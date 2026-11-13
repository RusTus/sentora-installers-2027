<?php

/**
 * QuickFig's longer tasks, run by the daemon in the background as the account's own Linux user
 * (web<account>): Magento's own tools (bin/magento: cache:flush, indexer:reindex, deploy:mode:set)
 * and WordPress's database links to https (WP-CLI search-replace). The page queues a task; the
 * daemon checks it again (a live account, its own site, the right kind of site), starts it as
 * the account's user, with the site's own PHP confined to the account's folder, at low priority
 * and for a limited time, and keeps its output for the page.
 *
 * The daemon is root: it never touches the account's files itself. Everything in the site is done
 * by the account's own user, so a link planted in the site leads nowhere that user could not
 * already reach.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class quickfig_jobs
{
    /** Root's own folder for QuickFig (WP-CLI, the tasks' output, the hourly state). */
    const DIR = '/var/lib/sentora/quickfig';

    /** WP-CLI, downloaded by the install step (and again when missing). */
    const WPCLI = '/var/lib/sentora/quickfig/wp-cli.phar';

    /** The tasks: name => array(label, the kind of site, seconds it may take). */
    const OPS = array(
        'mage_flush' => array('Flush Magento\'s caches (bin/magento cache:flush)', 'magento', 900),
        'mage_reindex' => array('Reindex (bin/magento indexer:reindex)', 'magento', 3600),
        'mage_mode' => array('Change the mode (bin/magento deploy:mode:set)', 'magento', 5400),
        'wp_https' => array('Links in the database to https (WP-CLI search-replace)', 'wordpress', 1800),
        'perm_fix' => array('Close files anyone could change, and config files with passwords (permissions)', '*', 900),
    );

    /** Tasks started at once, on the whole server. */
    const PARALLEL = 2;

    /**
     * Create the x_quickfig_jobs table if missing (once per request).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if ($done) {
            return;
        }
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_quickfig_jobs (
            qj_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, qj_acc_fk INT NOT NULL, qj_vh_fk INT NOT NULL,
            qj_sub_vc VARCHAR(200) NOT NULL DEFAULT '', qj_op_vc VARCHAR(20) NOT NULL, qj_arg_vc VARCHAR(255) NOT NULL DEFAULT '',
            qj_state_vc VARCHAR(10) NOT NULL DEFAULT 'queued', qj_rc_in INT NULL, qj_out_tx MEDIUMTEXT NULL,
            qj_created_in INT NOT NULL, qj_started_in INT NULL, qj_finished_in INT NULL,
            KEY qj_acc (qj_acc_fk, qj_vh_fk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    /**
     * Queue a task for one of the account's sites.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account.
     * @param int $vh The site (one of the account's own, checked by the caller).
     * @param string $sub The folder inside the site.
     * @param string $op One of OPS.
     * @param string $arg Its argument (mage_mode: developer or production; wp_https: the address).
     * @return string '' when queued, else why not.
     */
    static function Queue($uid, $vh, $sub, $op, $arg = '')
    {
        global $zdbh;
        self::Ensure();
        if (!isset(self::OPS[$op])) {
            return 'No such task.';
        }
        if ($op === 'mage_mode' && !in_array($arg, array('developer', 'production'), true)) {
            return 'Choose developer or production.';
        }
        if ($op === 'wp_https' && !is_file(self::WPCLI)) {
            return 'WP-CLI is not on this server yet: QuickFig\'s install step downloads it (it tries again every hour).';
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_quickfig_jobs WHERE qj_acc_fk = :u AND qj_vh_fk = :v AND qj_sub_vc = :s AND qj_op_vc = :o AND qj_state_vc IN ('queued', 'running')");
        $q->execute(array(':u' => (int) $uid, ':v' => (int) $vh, ':s' => (string) $sub, ':o' => $op));
        if ((int) $q->fetchColumn() > 0) {
            return 'That task is queued or running already.';
        }
        $zdbh->prepare("INSERT INTO x_quickfig_jobs (qj_acc_fk, qj_vh_fk, qj_sub_vc, qj_op_vc, qj_arg_vc, qj_created_in) VALUES (:u, :v, :s, :o, :a, :t)")
            ->execute(array(':u' => (int) $uid, ':v' => (int) $vh, ':s' => (string) $sub, ':o' => $op, ':a' => (string) $arg, ':t' => time()));
        // the oldest finished ones of the site go (the last 10 stay)
        $old = $zdbh->prepare("SELECT qj_id_pk FROM x_quickfig_jobs WHERE qj_acc_fk = :u AND qj_vh_fk = :v AND qj_state_vc IN ('done', 'failed') ORDER BY qj_id_pk DESC LIMIT 100 OFFSET 10");
        $old->execute(array(':u' => (int) $uid, ':v' => (int) $vh));
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $zdbh->prepare("DELETE FROM x_quickfig_jobs WHERE qj_id_pk = :id")->execute(array(':id' => (int) $id));
        }
        return '';
    }

    /**
     * A site's latest tasks, newest first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @param int $vh
     * @return array<int, array>
     */
    static function Recent($uid, $vh)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_quickfig_jobs WHERE qj_acc_fk = :u AND qj_vh_fk = :v ORDER BY qj_id_pk DESC LIMIT 10");
        $q->execute(array(':u' => (int) $uid, ':v' => (int) $vh));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The PHP a site runs (its version in PHP Versions, else the server's), as the cron tasks use it.
     *
     * @param string $version The site's vh_php_version_vc.
     * @return string
     */
    static function Php($version)
    {
        if ($version !== '' && $version !== sys_phpversions::DefaultVersion() && preg_match('/^\d+\.\d+$/', $version)
            && sys_phpversions::IsEnabled($version) && is_executable(sys_phpversions::BASE_DIR . $version . '/bin/php')) {
            return sys_phpversions::BASE_DIR . $version . '/bin/php';
        }
        // the system's own PHP: its command as this system's installer set it (php_cli: /usr/bin/php8.3 on
        // Debian/Ubuntu, /usr/bin/php on AlmaLinux/Rocky/RHEL/Fedora); panels from before that setting: the
        // Debian/Ubuntu name, else plain php
        $v = (string) sys_phpversions::DefaultVersion();
        if (class_exists('sys_platform') && method_exists('sys_platform', 'Get')) {
            $cli = (string) sys_platform::Get('php_cli', array('v' => $v));
            if ($cli !== '' && is_executable($cli)) {
                return $cli;
            }
        }
        return is_executable('/usr/bin/php' . $v) ? '/usr/bin/php' . $v : '/usr/bin/php';
    }

    /**
     * A command line run as the account's own user: no other groups, a clean environment, low
     * priority, at most $seconds.
     *
     * @param int $uid The account.
     * @param string $home The account's folder.
     * @param string $inner The command (escaped), run by sh as that user.
     * @param int $seconds
     * @return string
     */
    static function AsUser($uid, $home, $inner, $seconds)
    {
        $user = escapeshellarg(sys_accountphp::UserName((int) $uid));
        $tmp = rtrim($home, '/') . '/tmp';
        return 'setpriv --reuid=' . $user . ' --regid=' . $user . ' --clear-groups --reset-env env -i PATH=/usr/local/bin:/usr/bin:/bin LANG=C.UTF-8'
            . ' HOME=' . escapeshellarg($tmp) . ' WP_CLI_CACHE_DIR=' . escapeshellarg($tmp . '/.wp-cli-cache')
            . ' timeout -k 30 ' . (int) $seconds . ' nice -n 10 ionice -c 3 sh -c ' . escapeshellarg($inner);
    }

    /**
     * Daemon: collect the tasks that finished, start queued ones.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string[] What happened (for the daemon's log).
     */
    static function Run()
    {
        global $zdbh;
        self::Ensure();
        $log = array();
        $jobs = self::DIR . '/jobs';
        if (!is_dir($jobs)) {
            @mkdir($jobs, 0700, true);
        }
        if (!is_dir($jobs) || is_link($jobs) || is_link(self::DIR)) {
            return array('QuickFig: ' . $jobs . ' could not be made.');
        }
        // finished (or stuck) tasks
        foreach ($zdbh->query("SELECT * FROM x_quickfig_jobs WHERE qj_state_vc = 'running'")->fetchAll(PDO::FETCH_ASSOC) as $j) {
            $id = (int) $j['qj_id_pk'];
            $rc = $jobs . '/' . $id . '.rc';
            $out = $jobs . '/' . $id . '.out';
            $max = isset(self::OPS[$j['qj_op_vc']]) ? self::OPS[$j['qj_op_vc']][2] : 900;
            if (is_file($rc) || time() - (int) $j['qj_started_in'] > $max + 900) {
                $code = is_file($rc) ? (int) trim((string) file_get_contents($rc)) : 124;
                $text = is_file($out) ? (string) file_get_contents($out, false, null, max(0, (int) filesize($out) - 65536)) : '';
                if ($code === 124) {
                    $text .= "\n(stopped: it took longer than " . round($max / 60) . " minutes)";
                }
                $zdbh->prepare("UPDATE x_quickfig_jobs SET qj_state_vc = :s, qj_rc_in = :r, qj_out_tx = :o, qj_finished_in = :t WHERE qj_id_pk = :id")
                    ->execute(array(':s' => $code === 0 ? 'done' : 'failed', ':r' => $code, ':o' => mb_convert_encoding($text, 'UTF-8', 'UTF-8'), ':t' => time(), ':id' => $id));
                @unlink($rc);
                @unlink($out);
                $log[] = 'QuickFig: task ' . $id . ' (' . $j['qj_op_vc'] . ') ' . ($code === 0 ? 'done' : 'failed, exit code ' . $code);
            }
        }
        // queued tasks: a few at once on the server, one at a time per account
        $running = (int) $zdbh->query("SELECT COUNT(*) FROM x_quickfig_jobs WHERE qj_state_vc = 'running'")->fetchColumn();
        foreach ($zdbh->query("SELECT * FROM x_quickfig_jobs WHERE qj_state_vc = 'queued' ORDER BY qj_id_pk")->fetchAll(PDO::FETCH_ASSOC) as $j) {
            if ($running >= self::PARALLEL) {
                break;
            }
            $busy = $zdbh->prepare("SELECT COUNT(*) FROM x_quickfig_jobs WHERE qj_acc_fk = :u AND qj_state_vc = 'running'");
            $busy->execute(array(':u' => (int) $j['qj_acc_fk']));
            if ((int) $busy->fetchColumn() > 0) {
                continue;
            }
            $why = self::Start($j);
            if ($why !== '') {
                $zdbh->prepare("UPDATE x_quickfig_jobs SET qj_state_vc = 'failed', qj_out_tx = :o, qj_finished_in = :t WHERE qj_id_pk = :id")
                    ->execute(array(':o' => $why, ':t' => time(), ':id' => (int) $j['qj_id_pk']));
                $log[] = 'QuickFig: task ' . (int) $j['qj_id_pk'] . ' not started: ' . $why;
                continue;
            }
            $running++;
            $log[] = 'QuickFig: task ' . (int) $j['qj_id_pk'] . ' (' . $j['qj_op_vc'] . ') started';
        }
        return $log;
    }

    /**
     * Check a queued task again and start it in the background.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $j Its row.
     * @return string '' when started, else why not.
     */
    static function Start(array $j)
    {
        global $zdbh;
        $uid = (int) $j['qj_acc_fk'];
        $op = (string) $j['qj_op_vc'];
        if (!isset(self::OPS[$op])) {
            return 'No such task.';
        }
        $a = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_id_pk = :u AND ac_enabled_in = 1 AND ac_deleted_ts IS NULL");
        $a->execute(array(':u' => $uid));
        if (!$a->fetchColumn() || posix_getpwnam(sys_accountphp::UserName($uid)) === false) {
            return 'The account is switched off, or its Linux user is not made yet.';
        }
        $sites = quickfig::Sites($uid);
        $vh = (int) $j['qj_vh_fk'];
        if (!isset($sites[$vh])) {
            return 'The website is gone.';
        }
        $site = $sites[$vh];
        $sub = (string) $j['qj_sub_vc'];
        // what is in the folder, asked as the account's own user
        $d = quickfig::Run($uid, $site, 'detect', array('sub' => $sub), true);
        if (isset($d['error'])) {
            return (string) $d['error'];
        }
        if (self::OPS[$op][1] !== '*' && (!isset($d['type']) || $d['type'] !== self::OPS[$op][1])) {
            return 'There is no ' . (self::OPS[$op][1] === 'magento' ? 'Magento' : 'WordPress') . ' in that folder any more.';
        }
        $home = realpath(quickfig::Home($uid));
        // (a site with no app QuickFig knows: its own folder)
        $root = !empty($d['root']) ? $home . '/' . (string) $d['root'] : rtrim($site['dir'], '/') . ($sub !== '' ? '/' . $sub : '');
        $v = $zdbh->prepare("SELECT vh_php_version_vc FROM x_vhosts WHERE vh_id_pk = :v");
        $v->execute(array(':v' => $vh));
        $php = escapeshellarg(self::Php((string) $v->fetchColumn()));
        $limit = ' -d open_basedir=' . escapeshellarg($home . '/:' . self::DIR . '/') . ' -d sys_temp_dir=' . escapeshellarg($home . '/tmp');
        $arg = (string) $j['qj_arg_vc'];
        if ($op === 'perm_fix') {
            // QuickFig's own worker, as the account's user (only the owner of a file may change who can use it)
            $helper = dirname(__FILE__) . '/helper.php';
            $req = json_encode(array('op' => 'perm_fix', 'home' => $home, 'dir' => $site['dir'], 'sub' => $sub));
            $cmd = 'printf %s ' . escapeshellarg($req) . ' | ' . $php . ' -d open_basedir=' . escapeshellarg($home . '/:' . dirname($helper) . '/')
                . ' -d disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,putenv,mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,dl'
                . ' -d allow_url_fopen=0 -d display_errors=0 -d log_errors=0 ' . escapeshellarg($helper);
        } elseif ($op === 'mage_flush') {
            $cmd = $php . ' -d memory_limit=2G' . $limit . ' bin/magento cache:flush --no-ansi';
        } elseif ($op === 'mage_reindex') {
            $cmd = $php . ' -d memory_limit=2G' . $limit . ' bin/magento indexer:reindex --no-ansi';
        } elseif ($op === 'mage_mode') {
            if (!in_array($arg, array('developer', 'production'), true)) {
                return 'Choose developer or production.';
            }
            $cmd = $php . ' -d memory_limit=4G' . $limit . ' bin/magento deploy:mode:set ' . escapeshellarg($arg) . ' --no-ansi';
        } else {
            // http://<address> becomes https://<address> (and the www. name too) in the database, not in the guid column (WordPress's own advice)
            if (!preg_match('#^[a-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~%/+-]*)?$#', $arg)) {
                return 'Not an address.';
            }
            // PHP's own warnings off (WP-CLI looks for a wp-cli.yml above the account's folder, which open_basedir refuses: harmless)
            $wp = $php . ' -d memory_limit=512M -d error_reporting=' . escapeshellarg('E_ALL & ~E_WARNING & ~E_DEPRECATED') . $limit . ' ' . escapeshellarg(self::WPCLI) . ' --path=' . escapeshellarg($root) . ' --no-color';
            $cmd = $wp . ' search-replace ' . escapeshellarg('http://' . $arg) . ' ' . escapeshellarg('https://' . $arg) . ' --skip-columns=guid --precise --report-changed-only';
            if (strpos($arg, 'www.') !== 0) {
                $cmd .= ' && ' . $wp . ' search-replace ' . escapeshellarg('http://www.' . $arg) . ' ' . escapeshellarg('https://www.' . $arg) . ' --skip-columns=guid --precise --report-changed-only';
            }
            $cmd .= ' && ' . $wp . ' cache flush';
        }
        if (!is_dir($root)) {
            return 'The site\'s folder is gone.';
        }
        $id = (int) $j['qj_id_pk'];
        $jobs = self::DIR . '/jobs';
        $inner = 'cd ' . escapeshellarg($root) . ' && ' . $cmd;
        // the output goes to root's own folder (written by root's shell, never in the site)
        $full = '{ ' . self::AsUser($uid, $home, $inner, self::OPS[$op][2]) . '; } > ' . escapeshellarg($jobs . '/' . $id . '.out') . ' 2>&1; echo $? > '
            . escapeshellarg($jobs . '/' . $id . '.rc.new') . ' && mv ' . escapeshellarg($jobs . '/' . $id . '.rc.new') . ' ' . escapeshellarg($jobs . '/' . $id . '.rc');
        $zdbh->prepare("UPDATE x_quickfig_jobs SET qj_state_vc = 'running', qj_started_in = :t WHERE qj_id_pk = :id")->execute(array(':t' => time(), ':id' => $id));
        exec('setsid nohup sh -c ' . escapeshellarg($full) . ' > /dev/null 2>&1 < /dev/null &');
        return '';
    }

    /**
     * WP-CLI on the server: downloaded with its published SHA-512 checked, kept in root's folder.
     *
     * @return string What happened ('' when it is there already).
     */
    static function GetWpCli()
    {
        if (is_file(self::WPCLI) && filemtime(self::WPCLI) > time() - 30 * 86400) {
            return '';
        }
        if (!is_dir(self::DIR)) {
            @mkdir(self::DIR, 0755, true);
        }
        $base = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
        $ctx = stream_context_create(array('http' => array('timeout' => 60, 'user_agent' => 'Sentora-QuickFig')));
        $phar = @file_get_contents($base, false, $ctx);
        $sum = trim((string) @file_get_contents($base . '.sha512', false, $ctx));
        if (!is_string($phar) || strlen($phar) < 100000 || !preg_match('/^[0-9a-f]{128}/', $sum) || !hash_equals(substr($sum, 0, 128), hash('sha512', $phar))) {
            return 'WP-CLI could not be downloaded (or its checksum did not match): QuickFig tries again in an hour.';
        }
        file_put_contents(self::WPCLI . '.new', $phar);
        chmod(self::WPCLI . '.new', 0644);
        rename(self::WPCLI . '.new', self::WPCLI);
        return 'WP-CLI downloaded (checksum checked).';
    }
}
