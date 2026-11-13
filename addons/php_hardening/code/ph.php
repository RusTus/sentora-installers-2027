<?php

/**
 * Web Hardening, its PHP part (ModSecurity's is ph_modsec): Snuffleupagus (the PHP hardening
 * extension that took over from Suhosin) for every PHP on the server, switched on by version (off, watch only, on), with rules the administrator
 * chooses, accounts it leaves alone, and a list of what it caught. Every PHP bundle has
 * snuffleupagus.so (switched off); for the system's own PHP the daemon downloads Sentora's build
 * from the release, checked against php-bundles.json. The root work (files, PHP-FPM restarts, the
 * system log) is the daemon's (ph_daemon); this class keeps the settings and writes the rules.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
class ph
{
    /** The ini and rules file names in each PHP's settings folder. */
    const INI = '90-sentora-php-hardening.ini';
    const RULES = 'sentora-php-hardening.rules';

    /** How many caught lines are kept (the daily counts behind the statistics are kept longer: DAYS). */
    const KEEP = 5000;

    /** How many days of daily counts are kept. */
    const DAYS = 400;

    /** Lines per page of the list. */
    const PAGE = 50;

    /**
     * Create (or bring up to date) the module's own tables.
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
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_ph_settings (
            ph_name_vc VARCHAR(60) NOT NULL PRIMARY KEY, ph_value_tx TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_ph_status (
            st_version_vc VARCHAR(10) NOT NULL PRIMARY KEY, st_state_vc VARCHAR(10) NOT NULL DEFAULT 'off', st_note_tx TEXT NULL,
            st_sp_vc VARCHAR(20) NOT NULL DEFAULT '', st_checked_in INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_ph_caught (
            ca_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ca_time_in INT NOT NULL, ca_account_vc VARCHAR(60) NOT NULL DEFAULT '',
            ca_feature_vc VARCHAR(40) NOT NULL DEFAULT '', ca_action_vc VARCHAR(20) NOT NULL DEFAULT '', ca_message_tx TEXT NULL,
            ca_file_tx TEXT NULL, ca_line_in INT NOT NULL DEFAULT 0, ca_ip_vc VARCHAR(60) NOT NULL DEFAULT '',
            KEY ca_time (ca_time_in)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // (102) what kind of attack each line is, and the daily counts behind the statistics
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_ph_daily (
            dy_day_vc CHAR(10) NOT NULL, dy_kind_vc VARCHAR(40) NOT NULL, dy_action_vc VARCHAR(20) NOT NULL, dy_count_in INT NOT NULL DEFAULT 0,
            PRIMARY KEY (dy_day_vc, dy_kind_vc, dy_action_vc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $q = $zdbh->query("SELECT * FROM x_ph_caught LIMIT 1");
        $have = array();
        for ($i = 0; $i < $q->columnCount(); $i++) {
            $m = $q->getColumnMeta($i);
            $have[strtolower($m['name'])] = true;
        }
        $q->closeCursor();
        if (!isset($have['ca_kind_vc'])) {
            $zdbh->exec("ALTER TABLE x_ph_caught ADD COLUMN ca_kind_vc VARCHAR(40) NOT NULL DEFAULT ''");
        }
        $done = true;
        // lines from before 102: their kind, and their days counted (once)
        if (self::Get('kinds_done') === '') {
            self::Set('kinds_done', '1');
            $up = $zdbh->prepare("UPDATE x_ph_caught SET ca_kind_vc = :k WHERE ca_id_pk = :i");
            foreach ($zdbh->query("SELECT ca_id_pk, ca_time_in, ca_feature_vc, ca_action_vc, ca_message_tx FROM x_ph_caught WHERE ca_kind_vc = ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = self::KindOf((string) $r['ca_feature_vc'], (string) $r['ca_message_tx']);
                $up->execute(array(':k' => $k, ':i' => (int) $r['ca_id_pk']));
                self::Count((int) $r['ca_time_in'], $k, (string) $r['ca_action_vc']);
            }
        }
    }

    /**
     * A setting ('' when not set).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name
     * @return string
     */
    static function Get($name)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT ph_value_tx FROM x_ph_settings WHERE ph_name_vc = :n");
        $q->execute(array(':n' => (string) $name));
        $v = $q->fetchColumn();
        return $v === false || $v === null ? '' : (string) $v;
    }

    /**
     * Save a setting.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name
     * @param string $value
     * @return void
     */
    static function Set($name, $value)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("REPLACE INTO x_ph_settings (ph_name_vc, ph_value_tx) VALUES (:n, :v)")
            ->execute(array(':n' => (string) $name, ':v' => (string) $value));
    }

    // ----- the PHP versions -----

    /**
     * Every PHP on the server: the system's own, then Sentora's extra versions.
     *
     * @return array<string, string> Version => 'system' or 'bundle'.
     */
    static function Versions()
    {
        $out = array(sys_phpversions::DefaultVersion() => 'system');
        foreach (sys_phpversions::Installed() as $v) {
            $out[$v] = 'bundle';
        }
        return $out;
    }

    /**
     * What a version should be: off, watch (log what it would block) or on.
     *
     * @param string $version
     * @return string
     */
    static function Mode($version)
    {
        $m = self::Get('mode:' . $version);
        return in_array($m, array('watch', 'on'), true) ? $m : 'off';
    }

    /**
     * A version's state as the daemon last found it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $version
     * @return array{st_state_vc: string, st_note_tx: string, st_sp_vc: string, st_checked_in: int}
     */
    static function Status($version)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_ph_status WHERE st_version_vc = :v");
        $q->execute(array(':v' => (string) $version));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : array('st_state_vc' => 'off', 'st_note_tx' => '', 'st_sp_vc' => '', 'st_checked_in' => 0);
    }

    /**
     * Note a version's state (the daemon).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $version
     * @param string $state off, watch, on, problem
     * @param string $note
     * @param string $sp The Snuffleupagus version in use.
     * @return void
     */
    static function SetStatus($version, $state, $note = '', $sp = '')
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("REPLACE INTO x_ph_status (st_version_vc, st_state_vc, st_note_tx, st_sp_vc, st_checked_in) VALUES (:v, :s, :n, :sp, :t)")
            ->execute(array(':v' => (string) $version, ':s' => $state, ':n' => (string) $note, ':sp' => (string) $sp, ':t' => time()));
    }

    // ----- the rules -----

    /**
     * The rules the administrator chooses from. Each: title, what it does, on by default, whether
     * it can only log (watch only), and its lines: global lines, or blocks (a function, the filter
     * after it).
     *
     * @return array<string, array>
     */
    static function Catalog()
    {
        $shell = array('system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec');
        $inject = array();
        foreach (array('system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open') as $f) {
            $inject[] = array($f, '.param("command").value_r("[$|;&`\\\\n\\\\(\\\\)\\\\\\\\]")');
        }
        return array(
            'random' => array('title' => 'Safer random numbers',
                'desc' => 'rand(), mt_rand() and friends use the system\'s secure random numbers, so old code that makes tokens or passwords with them can not be predicted.',
                'default' => true, 'watch' => false, 'global' => array('sp.harden_random.enable();')),
            'xxe' => array('title' => 'Block XML external entities (XXE)',
                'desc' => 'XML a site reads can not pull in files from the server or call other addresses.',
                'default' => true, 'watch' => false, 'global' => array('@condition extension_loaded("xml");', 'sp.xxe_protection.enable();', '@end_condition;')),
            'samesite' => array('title' => 'SameSite on PHP session cookies',
                'desc' => 'The PHPSESSID cookie is not sent with requests other sites make (SameSite=Lax), against cross-site request forgery.',
                'default' => true, 'watch' => false, 'global' => array('sp.cookie.name("PHPSESSID").samesite("lax");')),
            'mail' => array('title' => 'Block mail() options attackers use',
                'desc' => 'mail() may not be given the sendmail options -X, -O or -C (how the PHPMailer exploits wrote PHP files); the usual -f sender still works.',
                'default' => true, 'watch' => true, 'block' => array(
                    array('mail', '.param("additional_params").value_r("(^|\\\\s)-[XOC]")'),
                    array('mb_send_mail', '.param("additional_params").value_r("(^|\\\\s)-[XOC]")'))),
            'putenv' => array('title' => 'Block putenv() tricks that load code',
                'desc' => 'putenv() may not set LD_ or GCONV_ variables, a known way to run a program and escape PHP\'s limits.',
                'default' => true, 'watch' => true, 'block' => array(array('putenv', '.param("assignment").value_r("^(LD_|GCONV_)")'))),
            'basedir' => array('title' => 'Block changes to open_basedir',
                'desc' => 'A script may not change open_basedir (the folder its PHP is kept in), part of known ways out of it.',
                'default' => true, 'watch' => true, 'block' => array(
                    array('ini_set', '.param("option").value_r("open_basedir")'),
                    array('ini_alter', '.param("option").value_r("open_basedir")'))),
            'curl' => array('title' => 'Block cURL\'s SSL engine option',
                'desc' => 'curl_setopt() may not set CURLOPT_SSLENGINE, which can load a program from a file (a 2026 exploit).',
                'default' => true, 'watch' => true, 'block' => array(array('curl_setopt', '.param("option").value("10089")'))),
            'eval' => array('title' => 'Code run with eval() may not run commands',
                'desc' => 'Inside eval() (how most web shells run what attackers send), system(), exec() and the like are refused.',
                'default' => true, 'watch' => true, 'eval' => 'system,exec,shell_exec,passthru,popen,proc_open,pcntl_exec,assert'),
            'chmod' => array('title' => 'Block chmod 0666 and 0777',
                'desc' => 'chmod() may not make files or folders writable by everyone. Some installers do it: try it in watch only first.',
                'default' => false, 'watch' => true, 'block' => array(
                    array('chmod', '.param("permissions").value("438")'),
                    array('chmod', '.param("permissions").value("511")'))),
            'inject' => array('title' => 'Block shell commands with injection characters',
                'desc' => 'system(), exec() and the like may not be given commands with $ ; | & ` ( ) or a new line, how injected commands are chained. Image tools and backups sometimes use them: try it in watch only first.',
                'default' => false, 'watch' => true, 'block' => $inject),
            'shell' => array('title' => 'Block shell commands altogether',
                'desc' => 'Sites may not run shell commands at all (system, exec, shell_exec, passthru, popen, proc_open, pcntl_exec). The strongest, and the most likely to break something (image tools, backups, some caches).',
                'default' => false, 'watch' => true, 'block' => array_map(function ($f) { return array($f, ''); }, $shell)),
            'wrappers' => array('title' => 'Only common stream wrappers',
                'desc' => 'Only file, php, phar, http(s), ftp(s), data, glob, compress.zlib and zip addresses can be opened; rarer wrappers (expect:// and others) are refused.',
                'default' => false, 'watch' => false, 'global' => array('sp.wrappers_whitelist.list("file,php,phar,http,https,ftp,ftps,data,glob,compress.zlib,zip");')),
        );
    }

    /**
     * The rules chosen (the defaults until the administrator saves a choice).
     *
     * @return string[] Rule IDs.
     */
    static function Chosen()
    {
        $saved = self::Get('rules');
        $catalog = self::Catalog();
        if ($saved === '') {
            return array_keys(array_filter($catalog, function ($r) { return $r['default']; }));
        }
        return array_values(array_intersect(array_keys($catalog), explode(',', $saved)));
    }

    /**
     * The accounts left alone by the blocking rules.
     *
     * @return string[] User names.
     */
    static function Exempt()
    {
        $v = self::Get('exempt');
        return $v === '' ? array() : array_values(array_filter(explode(',', $v), function ($u) { return preg_match('/^[a-z0-9][a-z0-9._-]{0,59}$/i', $u); }));
    }

    /**
     * A path as a regular expression for a Snuffleupagus filename_r() filter (written between
     * double quotes: backslashes doubled).
     *
     * @param string $path
     * @return string
     */
    static function PathRegex($path)
    {
        return '^' . str_replace('\\', '\\\\', preg_quote(rtrim((string) $path, '/') . '/', '#'));
    }

    /**
     * The rules file for a mode: every chosen rule (in watch only, the ones that can only log, as
     * simulation), the panel's own files and the exempt accounts' folders allowed first.
     *
     * @param string $mode watch or on
     * @param string[] $chosen Rule IDs.
     * @param string[] $allowed Folders whose scripts the blocking rules leave alone.
     * @return string
     */
    static function RulesFile($mode, array $chosen, array $allowed)
    {
        $catalog = self::Catalog();
        $sim = $mode === 'watch' ? '.simulation()' : '';
        $out = array('# Written by Sentora\'s Web Hardening add-on (' . ($mode === 'watch' ? 'watch only: nothing is blocked, what would be is logged' : 'on') . ').',
            '# Changes here are overwritten: change the rules in the panel (Server Admin > Web Hardening).',
            'sp.log_media("syslog");', '');
        foreach ($chosen as $id) {
            if (!isset($catalog[$id])) {
                continue;
            }
            $r = $catalog[$id];
            if ($mode === 'watch' && !$r['watch']) {
                continue;
            }
            $out[] = '# ' . $r['title'];
            if (isset($r['global'])) {
                foreach ($r['global'] as $line) {
                    $out[] = $line;
                }
            }
            if (isset($r['eval'])) {
                $out[] = 'sp.eval_blacklist.list("' . $r['eval'] . '")' . $sim . ';';
            }
            if (isset($r['block'])) {
                $seen = array();
                foreach ($r['block'] as $b) {
                    if (isset($seen[$b[0]])) {
                        continue;
                    }
                    $seen[$b[0]] = true;
                    foreach ($allowed as $path) {
                        $out[] = 'sp.disable_function.function("' . $b[0] . '").filename_r("' . self::PathRegex($path) . '").allow();';
                    }
                }
                foreach ($r['block'] as $b) {
                    $out[] = 'sp.disable_function.function("' . $b[0] . '")' . $b[1] . '.alias("' . str_replace('"', '\'', $r['title']) . '").drop()' . $sim . ';';
                }
            }
            $out[] = '';
        }
        return implode("\n", $out) . "\n";
    }

    /**
     * The folders the blocking rules leave alone: the panel's own, and the exempt accounts'.
     *
     * @return string[]
     */
    static function Allowed()
    {
        $out = array(rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/');
        $hosted = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/';
        foreach (self::Exempt() as $u) {
            $out[] = $hosted . $u . '/';
        }
        return $out;
    }

    // ----- what it caught -----

    /**
     * Read one Snuffleupagus log line.
     *
     * @param string $message "[snuffleupagus][ip][feature][action] text in /file on line N"
     * @return array|null ip, feature, action, text, file, line
     */
    static function Parse($message)
    {
        if (!preg_match('/^\[snuffleupagus\]\[([^\]]*)\]\[([^\]]+)\]\[([^\]]+)\] (.*)$/s', trim((string) $message), $m)) {
            return null;
        }
        $out = array('ip' => $m[1], 'feature' => $m[2], 'action' => $m[3], 'text' => $m[4], 'file' => '', 'line' => 0);
        // the file is at the end: " in /path on line N" (the last one: the message itself may say "in eval")
        if (preg_match('/^(.*) in (\/.*) on line (\d+)$/s', $m[4], $f)) {
            $out['text'] = $f[1];
            $out['file'] = $f[2];
            $out['line'] = (int) $f[3];
        }
        return $out;
    }

    /**
     * Whose site a file is (its account's user name), or ''.
     *
     * @param string $file
     * @return string
     */
    static function AccountOf($file)
    {
        $hosted = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/';
        if ($hosted !== '/' && strpos((string) $file, $hosted) === 0) {
            $rest = substr((string) $file, strlen($hosted));
            $u = strstr($rest, '/', true);
            return $u !== false && preg_match('/^[a-z0-9][a-z0-9._-]{0,59}$/i', $u) ? $u : '';
        }
        return '';
    }

    /**
     * Keep only the newest KEEP caught lines.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Prune()
    {
        global $zdbh;
        $keep = $zdbh->query("SELECT ca_id_pk FROM x_ph_caught ORDER BY ca_id_pk DESC LIMIT 1 OFFSET " . (self::KEEP - 1))->fetchColumn();
        if ($keep !== false) {
            $zdbh->prepare("DELETE FROM x_ph_caught WHERE ca_id_pk < :k")->execute(array(':k' => (int) $keep));
        }
        $zdbh->prepare("DELETE FROM x_ph_daily WHERE dy_day_vc < :d")->execute(array(':d' => date('Y-m-d', time() - self::DAYS * 86400)));
    }

    /**
     * The newest caught lines.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $limit
     * @return array<int, array>
     */
    static function Caught($limit = 100)
    {
        global $zdbh;
        self::Ensure();
        return $zdbh->query("SELECT * FROM x_ph_caught ORDER BY ca_time_in DESC, ca_id_pk DESC LIMIT " . max(1, (int) $limit))->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Caught lines added after a given one (for the page's live list), oldest first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $after The newest line the page has.
     * @param int $limit
     * @return array<int, array>
     */
    static function CaughtSince($after, $limit = 100)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_ph_caught WHERE ca_id_pk > :a ORDER BY ca_id_pk DESC LIMIT " . max(1, (int) $limit));
        $q->execute(array(':a' => max(0, (int) $after)));
        return array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The newest caught line's number (0 when there is none).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return int
     */
    static function NewestId()
    {
        global $zdbh;
        self::Ensure();
        return (int) $zdbh->query("SELECT MAX(ca_id_pk) FROM x_ph_caught")->fetchColumn();
    }

    // ----- kinds of attack, statistics, search -----

    /**
     * The kinds of attack, in the words shown (English; translated on the page).
     *
     * @return array<string, string> Kind => label.
     */
    static function Kinds()
    {
        return array(
            'sqli' => 'SQL injection', 'xss' => 'Cross-site scripting (XSS)', 'rce' => 'Command injection', 'php' => 'PHP code injection',
            'lfi' => 'Local file access', 'rfi' => 'Remote file inclusion', 'js' => 'JavaScript / Node injection', 'java' => 'Java attacks',
            'session' => 'Session fixation', 'protocol-attack' => 'HTTP smuggling and splitting', 'scanner' => 'Scanners and bots',
            'multipart' => 'Malformed uploads', 'protocol' => 'Odd or broken requests', 'leak' => 'Data leaks (in answers)', 'own' => 'Your own rules',
            'score' => 'Many small signs together',
            'php-function' => 'PHP: forbidden functions', 'php-eval' => 'PHP: eval of code', 'php-upload' => 'PHP: dangerous uploads',
            'php-xxe' => 'PHP: XML external entities', 'php-unserialize' => 'PHP: unsafe unserialize', 'php-other' => 'PHP: other guard rules',
        );
    }

    /**
     * What kind of attack a caught line is.
     *
     * @param string $feature modsecurity, or Snuffleupagus's feature
     * @param string $message
     * @return string A key of Kinds().
     */
    static function KindOf($feature, $message)
    {
        if ($feature !== 'modsecurity') {
            $map = array('disabled_function' => 'php-function', 'eval' => 'php-eval', 'upload_validation' => 'php-upload', 'xxe' => 'php-xxe', 'unserialize' => 'php-unserialize');
            return isset($map[$feature]) ? $map[$feature] : 'php-other';
        }
        // the rule families of the OWASP rules (their file numbers), the most telling first
        $families = array('942' => 'sqli', '941' => 'xss', '932' => 'rce', '933' => 'php', '930' => 'lfi', '931' => 'rfi', '934' => 'js', '944' => 'java',
            '943' => 'session', '921' => 'protocol-attack', '913' => 'scanner', '922' => 'multipart', '920' => 'protocol');
        preg_match_all('/(?:^Rules |; )(\d{6})\b/', (string) $message, $m);
        $ids = $m[1];
        foreach ($families as $f => $k) {
            foreach ($ids as $id) {
                if (strpos($id, $f) === 0) {
                    return $k;
                }
            }
        }
        foreach ($ids as $id) {
            if ((int) $id >= 950000 && (int) $id < 957000) {
                return 'leak';
            }
            if ((int) $id < 900000) {
                return 'own';
            }
        }
        return 'score';
    }

    /**
     * Count a caught line in its day.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $time
     * @param string $kind
     * @param string $action
     * @return void
     */
    static function Count($time, $kind, $action)
    {
        global $zdbh;
        $day = date('Y-m-d', (int) $time);
        $act = $action === 'drop' ? 'drop' : 'watch';
        $u = $zdbh->prepare("UPDATE x_ph_daily SET dy_count_in = dy_count_in + 1 WHERE dy_day_vc = :d AND dy_kind_vc = :k AND dy_action_vc = :a");
        $u->execute(array(':d' => $day, ':k' => $kind, ':a' => $act));
        if ($u->rowCount() === 0) {
            $zdbh->prepare("INSERT INTO x_ph_daily (dy_day_vc, dy_kind_vc, dy_action_vc, dy_count_in) VALUES (:d, :k, :a, 1)")->execute(array(':d' => $day, ':k' => $kind, ':a' => $act));
        }
    }

    /**
     * Keep a caught line (both guards' readers): with its kind, counted in its day.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array{time:int, account:string, feature:string, action:string, message:string, file:string, line:int, ip:string} $c
     * @return void
     */
    static function Add(array $c)
    {
        global $zdbh;
        self::Ensure();
        $kind = self::KindOf((string) $c['feature'], (string) $c['message']);
        $zdbh->prepare("INSERT INTO x_ph_caught (ca_time_in, ca_account_vc, ca_feature_vc, ca_action_vc, ca_message_tx, ca_file_tx, ca_line_in, ca_ip_vc, ca_kind_vc)
            VALUES (:t, :a, :f, :ac, :m, :fi, :l, :ip, :k)")->execute(array(':t' => (int) $c['time'], ':a' => substr((string) $c['account'], 0, 60),
            ':f' => substr((string) $c['feature'], 0, 40), ':ac' => substr((string) $c['action'], 0, 20), ':m' => substr((string) $c['message'], 0, 2000),
            ':fi' => substr((string) $c['file'], 0, 1000), ':l' => (int) $c['line'], ':ip' => substr((string) $c['ip'], 0, 60), ':k' => $kind));
        self::Count((int) $c['time'], $kind, (string) $c['action']);
    }

    /**
     * The WHERE part for the list's filters.
     *
     * @param array{q?:string, kind?:string, act?:string, guard?:string} $f
     * @param array $args (filled)
     * @return string
     */
    static function Where(array $f, array &$args)
    {
        $w = array('1 = 1');
        $q = isset($f['q']) && is_string($f['q']) ? trim($f['q']) : '';
        if ($q !== '') {
            // (! as the escape character: the same in MySQL, MariaDB and SQLite)
            $like = '%' . str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), substr($q, 0, 100)) . '%';
            $w[] = "(ca_message_tx LIKE :q1 ESCAPE '!' OR ca_file_tx LIKE :q2 ESCAPE '!' OR ca_ip_vc LIKE :q3 ESCAPE '!' OR ca_account_vc LIKE :q4 ESCAPE '!')";
            $args[':q1'] = $args[':q2'] = $args[':q3'] = $args[':q4'] = $like;
        }
        if (isset($f['kind']) && is_string($f['kind']) && isset(self::Kinds()[$f['kind']])) {
            $w[] = 'ca_kind_vc = :k';
            $args[':k'] = $f['kind'];
        }
        if (isset($f['act']) && $f['act'] === 'drop') {
            $w[] = "ca_action_vc = 'drop'";
        } elseif (isset($f['act']) && $f['act'] === 'watch') {
            $w[] = "ca_action_vc <> 'drop'";
        }
        if (isset($f['guard']) && $f['guard'] === 'modsecurity') {
            $w[] = "ca_feature_vc = 'modsecurity'";
        } elseif (isset($f['guard']) && $f['guard'] === 'php') {
            $w[] = "ca_feature_vc <> 'modsecurity'";
        }
        return implode(' AND ', $w);
    }

    /**
     * A page of the list, newest first: lines before $before (0: from the newest), or after $after.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $f Filters (see Where).
     * @param int $before
     * @param int $after
     * @return array{rows: array, more: bool, total: int}
     */
    static function Find(array $f, $before = 0, $after = 0)
    {
        global $zdbh;
        self::Ensure();
        $args = array();
        $where = self::Where($f, $args);
        $c = $zdbh->prepare("SELECT COUNT(*) FROM x_ph_caught WHERE " . $where);
        $c->execute($args);
        $total = (int) $c->fetchColumn();
        if ((int) $before > 0) {
            $where .= ' AND ca_id_pk < :b';
            $args[':b'] = (int) $before;
        }
        if ((int) $after > 0) {
            $where .= ' AND ca_id_pk > :af';
            $args[':af'] = (int) $after;
        }
        $q = $zdbh->prepare("SELECT * FROM x_ph_caught WHERE " . $where . " ORDER BY ca_id_pk DESC LIMIT " . (self::PAGE + 1));
        $q->execute($args);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > self::PAGE;
        return array('rows' => array_slice($rows, 0, self::PAGE), 'more' => $more, 'total' => $total);
    }

    /**
     * The statistics of the last $days days.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $days 1 to DAYS
     * @return array{days: array, kinds: array, drop: int, watch: int, ips: array, targets: array, hours: array, sources: int}
     */
    static function Stats($days)
    {
        global $zdbh;
        self::Ensure();
        $days = max(1, min(self::DAYS, (int) $days));
        $from = strtotime('today') - ($days - 1) * 86400;
        $series = array();
        for ($i = 0; $i < $days; $i++) {
            $series[date('Y-m-d', $from + $i * 86400 + 7200)] = array();
        }
        $kinds = array();
        $drop = 0;
        $watch = 0;
        $q = $zdbh->prepare("SELECT dy_day_vc, dy_kind_vc, dy_action_vc, dy_count_in FROM x_ph_daily WHERE dy_day_vc >= :d");
        $q->execute(array(':d' => date('Y-m-d', $from)));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int) $r['dy_count_in'];
            if (!isset($series[$r['dy_day_vc']])) {
                continue;
            }
            $series[$r['dy_day_vc']][$r['dy_kind_vc']] = (isset($series[$r['dy_day_vc']][$r['dy_kind_vc']]) ? $series[$r['dy_day_vc']][$r['dy_kind_vc']] : 0) + $n;
            $kinds[$r['dy_kind_vc']] = (isset($kinds[$r['dy_kind_vc']]) ? $kinds[$r['dy_kind_vc']] : 0) + $n;
            if ($r['dy_action_vc'] === 'drop') {
                $drop += $n;
            } else {
                $watch += $n;
            }
        }
        arsort($kinds);
        // sources, sites and the last 24 hours: from the lines still kept
        $ips = array();
        $targets = array();
        $hours = array();
        $now = time();
        for ($h = 23; $h >= 0; $h--) {
            $hours[date('H', $now - $h * 3600) . ':00'] = 0;
        }
        $q = $zdbh->prepare("SELECT ca_time_in, ca_ip_vc, ca_account_vc, ca_feature_vc, ca_file_tx FROM x_ph_caught WHERE ca_time_in >= :t");
        $q->execute(array(':t' => $from));
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $ip = (string) $r['ca_ip_vc'];
            if ($ip !== '') {
                $ips[$ip] = (isset($ips[$ip]) ? $ips[$ip] : 0) + 1;
            }
            $t = (string) $r['ca_account_vc'];
            if ($t === '' && $r['ca_feature_vc'] === 'modsecurity') {
                $t = (string) strtok((string) $r['ca_file_tx'], '/');
            }
            if ($t !== '') {
                $targets[$t] = (isset($targets[$t]) ? $targets[$t] : 0) + 1;
            }
            if ((int) $r['ca_time_in'] > $now - 86400) {
                $k = date('H', (int) $r['ca_time_in']) . ':00';
                if (isset($hours[$k])) {
                    $hours[$k]++;
                }
            }
        }
        arsort($ips);
        arsort($targets);
        return array('days' => $series, 'kinds' => $kinds, 'drop' => $drop, 'watch' => $watch, 'sources' => count($ips),
            'ips' => array_slice($ips, 0, 8, true), 'targets' => array_slice($targets, 0, 8, true), 'hours' => $hours);
    }
}
