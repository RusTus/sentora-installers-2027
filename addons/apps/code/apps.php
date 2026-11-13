<?php

/**
 * Apps, the panel's side: the apps offered (recipes: WordPress, OpenCart, Snipe-IT), the account's sites, the worker that looks
 * into a site's folder (helper.php: confined to the account's folder), the database and MySQL user
 * an app gets (made as MySQL Databases and MySQL Users make them, so they show there and count
 * toward the package's limit) and the record of each app installed.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class apps
{
    /** Seconds the worker may take. */
    const TIMEOUT = 30;

    /** Root's own folder for Apps (WP-CLI, the tasks' input and output, the latest versions). */
    const DIR = '/var/lib/sentora/apps';

    /**
     * The apps offered: key => name, what it is, the least PHP it needs, the PHP the panel's
     * latest-version check found (versions.json, else this), where it comes from.
     *
     * @return array<string, array>
     */
    static function Recipes()
    {
        $v = self::Latest();
        return array(
            'wordpress' => array(
                'name' => 'WordPress',
                'desc' => 'Websites and blogs: the most used website software, with thousands of themes and plugins. It updates itself (security and small updates).',
                'php' => isset($v['wordpress']['php']) ? $v['wordpress']['php'] : '7.4',
                'version' => isset($v['wordpress']['version']) ? $v['wordpress']['version'] : '',
                'from' => 'wordpress.org (checked against its published checksums)',
                'admin' => 'wp-admin/',
                'short' => 'wp',
                'user' => 60,
                'pass' => 200,
                // disk it takes, measured (WordPress 7.1: 117 MB) with room for its database and first uploads
                'disk' => 130 * 1048576,
            ),
            'opencart' => array(
                'name' => 'OpenCart',
                'desc' => 'An online shop: products, a cart, checkout and payments (PayPal and others), light enough for any hosting package. Its updates are done by the shop\'s owner.',
                'php' => '8.1',
                // pinned: the release is checked against this SHA-256 (made from the release, which matches
                // OpenCart's source at its tag file for file); a newer OpenCart comes with a newer Apps
                'version' => '4.1.0.4',
                'sha256' => 'cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0',
                'from' => 'OpenCart\'s own release on GitHub (checked against its SHA-256)',
                'admin' => '',
                'short' => 'oc',
                'user' => 20,
                'pass' => 40,
                'disk' => 70 * 1048576, // OpenCart 4.1: 61 MB while it installs
            ),
            'snipeit' => array(
                'name' => 'Snipe-IT',
                'desc' => 'IT asset management: computers, licences, accessories and who has them, with check-out, audits and reports. Its updates are done by its owner.',
                'php' => '8.2',
                // pinned: its source at this tag must be this commit; its libraries come with Composer
                // (by the panel, once per server, as an unprivileged user and running none of their code)
                'version' => '8.8.3',
                'tag' => 'v8.8.3',
                'commit' => '621e30486a59304f2a5288fb62c4aa91b08e0a84',
                'from' => 'Snipe-IT\'s own source on GitHub (its release\'s commit checked) and its libraries with Composer',
                'admin' => 'login',
                'short' => 'si',
                'user' => 60,
                'pass' => 72,
                'disk' => 310 * 1048576, // Snipe-IT 8.8 with its libraries: 294 MB, its database 4 MB
            ),
            'git' => array(
                'name' => 'From Git',
                'desc' => 'Your own app from a Git repository (GitHub, GitLab, Bitbucket or any Git server): cloned into the website, its libraries installed with Composer, and updated with one click or automatically when you push.',
                'php' => '7.4',
                'version' => '',
                'from' => 'your Git repository',
                'admin' => '',
                'short' => 'app',
                'user' => 60,
                'pass' => 200,
                // room for a typical app with its libraries; a bigger one stops at the disk limit
                'disk' => 100 * 1048576,
            ),
        );
    }

    /**
     * The latest versions, as the daemon last found them (root writes, everyone reads).
     *
     * @return array
     */
    static function Latest()
    {
        static $v = null;
        if ($v === null) {
            $f = self::DIR . '/versions.json';
            $v = is_file($f) && !is_link($f) ? json_decode((string) @file_get_contents($f), true) : array();
            $v = is_array($v) ? $v : array();
        }
        return $v;
    }

    /**
     * Create the x_apps table if missing (once per request). One row per app installed: it is
     * also the install's (and the removal's) background task.
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
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_apps (
            ap_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ap_acc_fk INT NOT NULL, ap_vh_fk INT NOT NULL,
            ap_sub_vc VARCHAR(200) NOT NULL DEFAULT '', ap_app_vc VARCHAR(30) NOT NULL, ap_version_vc VARCHAR(30) NOT NULL DEFAULT '',
            ap_url_vc VARCHAR(255) NOT NULL DEFAULT '', ap_title_vc VARCHAR(200) NOT NULL DEFAULT '', ap_admin_vc VARCHAR(60) NOT NULL DEFAULT '',
            ap_email_vc VARCHAR(200) NOT NULL DEFAULT '', ap_hash_vc VARCHAR(255) NULL, ap_welcome_in TINYINT NOT NULL DEFAULT 0,
            ap_db_fk INT NULL, ap_dbuser_fk INT NULL,
            ap_state_vc VARCHAR(12) NOT NULL DEFAULT 'queued', ap_rc_in INT NULL, ap_out_tx MEDIUMTEXT NULL,
            ap_created_in INT NOT NULL, ap_started_in INT NULL, ap_finished_in INT NULL, ap_deleted_in INT NULL,
            ap_login_vc VARCHAR(100) NOT NULL DEFAULT '',
            KEY ap_acc (ap_acc_fk, ap_vh_fk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // (version 100 made the table without the admin's address)
        $has = $zdbh->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_apps' AND COLUMN_NAME = 'ap_login_vc'")->fetchColumn();
        if ((int) $has === 0) {
            $zdbh->prepare("ALTER TABLE x_apps ADD ap_login_vc VARCHAR(100) NOT NULL DEFAULT ''")->execute();
        }
        // (version 105: Git deploy, and the app's code inside its website: _app)
        $cols = array('ap_repo_vc' => "VARCHAR(255) NOT NULL DEFAULT ''", 'ap_branch_vc' => "VARCHAR(100) NOT NULL DEFAULT ''", 'ap_web_vc' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'ap_commit_vc' => "VARCHAR(250) NOT NULL DEFAULT ''", 'ap_prev_vc' => "VARCHAR(40) NOT NULL DEFAULT ''", 'ap_token_vc' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'ap_pubkey_tx' => 'TEXT NULL', 'ap_opts_vc' => "VARCHAR(100) NOT NULL DEFAULT ''", 'ap_layout_in' => 'TINYINT NOT NULL DEFAULT 0', 'ap_hook_in' => 'INT NULL',
            'ap_deployed_in' => 'INT NULL');
        $have = $zdbh->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_apps'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c => $type) {
            if (!in_array($c, $have, true)) {
                $zdbh->exec("ALTER TABLE x_apps ADD " . $c . " " . $type);
            }
        }
        $done = true;
    }

    /** The folder inside a website that holds an app's code: only its web folder is served. */
    const APP_DIR = '_app';

    /**
     * Is a Git repository's address one Apps may clone? https://host/path (no password in it) or
     * SSH (git@host:path, ssh://git@host[:port]/path, for a private repository with a deploy key);
     * the host must be on the internet, not this server or its network.
     *
     * @param string $url
     * @return string '' when fine, else why not.
     */
    static function GitUrlProblem($url)
    {
        $url = (string) $url;
        if (strlen($url) > 250) {
            return 'The address is too long.';
        }
        if (preg_match('#^https://([A-Za-z0-9.-]+)(:[0-9]{1,5})?/[A-Za-z0-9._~/-]+$#', $url, $m)) {
            $host = $m[1];
        } elseif (preg_match('#^git@([A-Za-z0-9.-]+):[A-Za-z0-9._~/-]+$#', $url, $m) || preg_match('#^ssh://git@([A-Za-z0-9.-]+)(:[0-9]{1,5})?/[A-Za-z0-9._~/-]+$#', $url, $m)) {
            $host = $m[1];
        } else {
            return 'Give the repository\'s address as https://host/owner/repo(.git), or git@host:owner/repo.git for a private one (without a password in it).';
        }
        if (strpos($url, '..') !== false || strpos($host, '.') === false) {
            return 'That is not a repository on the internet.';
        }
        $ips = @gethostbynamel($host);
        if (!$ips) {
            return 'The repository\'s server (' . $host . ') can not be found.';
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'The repository must be on the internet, not on this server or its own network.';
            }
        }
        return '';
    }

    /**
     * Is a Git address an SSH one (a private repository, cloned with the app's deploy key)?
     *
     * @param string $url
     * @return bool
     */
    static function GitSsh($url)
    {
        return strpos((string) $url, 'https://') !== 0;
    }

    /**
     * A branch or tag name Apps passes to Git.
     *
     * @param string $b
     * @return bool
     */
    static function ValidBranch($b)
    {
        return $b === '' || (strlen($b) <= 100 && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $b) && strpos($b, '..') === false && substr($b, -1) !== '/' && substr($b, -5) !== '.lock');
    }

    /**
     * A web folder inside the repository ('' for its top).
     *
     * @param string $w
     * @return bool
     */
    static function ValidWeb($w)
    {
        return $w === '' || $w === '*' || (strlen($w) <= 80 && preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#', $w));
    }

    /**
     * The account's apps (not removed), newest first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @return array<int, array>
     */
    static function Installed($uid)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_apps WHERE ap_acc_fk = :u AND ap_deleted_in IS NULL ORDER BY ap_id_pk DESC");
        $q->execute(array(':u' => (int) $uid));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * One of the account's apps.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @param int $id
     * @return array|null
     */
    static function Get($uid, $id)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_apps WHERE ap_id_pk = :id AND ap_acc_fk = :u AND ap_deleted_in IS NULL");
        $q->execute(array(':id' => (int) $id, ':u' => (int) $uid));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    /**
     * The account's sites: its domains and sub-domains with their folders.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account.
     * @return array<int, array{id:int, name:string, type:int, dir:string, php:string}> By vhost ID.
     */
    static function Sites($uid)
    {
        global $zdbh;
        $u = ctrl_users::GetUserDetail((int) $uid);
        $base = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $u['username'] . '/public_html';
        $q = $zdbh->prepare("SELECT vh_id_pk, vh_name_vc, vh_type_in, vh_directory_vc, vh_php_version_vc FROM x_vhosts WHERE vh_acc_fk = :u AND vh_type_in IN (1, 2) AND vh_deleted_ts IS NULL ORDER BY vh_name_vc");
        $q->execute(array(':u' => (int) $uid));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['vh_id_pk']] = array('id' => (int) $r['vh_id_pk'], 'name' => (string) $r['vh_name_vc'], 'type' => (int) $r['vh_type_in'],
                'dir' => $base . '/' . trim((string) $r['vh_directory_vc'], '/'), 'php' => (string) $r['vh_php_version_vc']);
        }
        return $out;
    }

    /**
     * The PHP version a site runs ('' in the vhost: the server's own).
     *
     * @param array $site From Sites().
     * @return string Such as 8.3.
     */
    static function SitePhp(array $site)
    {
        $v = (string) $site['php'];
        if ($v !== '' && preg_match('/^\d+\.\d+$/', $v) && sys_phpversions::IsEnabled($v)) {
            return $v;
        }
        return (string) sys_phpversions::DefaultVersion();
    }

    /**
     * Does the site have its certificate in use (Sencrypt SSL)?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $vh
     * @return bool
     */
    static function HasSsl($vh)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT vh_ssl_tx FROM x_vhosts WHERE vh_id_pk = :v AND vh_deleted_ts IS NULL");
        $q->execute(array(':v' => (int) $vh));
        return trim((string) $q->fetchColumn()) !== '';
    }

    /**
     * The account's folder.
     *
     * @param int $uid
     * @return string
     */
    static function Home($uid)
    {
        $u = ctrl_users::GetUserDetail((int) $uid);
        return rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $u['username'];
    }

    /**
     * The panel's welcome page, as a new domain's folder gets it (index.html): its SHA-1, so the
     * worker can tell it from a page of the owner's.
     *
     * @return string
     */
    static function WelcomeHash()
    {
        $f = rtrim((string) ctrl_options::GetSystemOption('static_dir'), '/') . '/pages/welcome.html';
        return is_file($f) ? (string) sha1_file($f) : '';
    }

    /**
     * Ask the worker (helper.php), confined to the account's folder.
     *
     * @param int $uid The account.
     * @param array $site From Sites().
     * @param string $op The request.
     * @param array $extra More for it (sub: the folder inside the site).
     * @param bool $asUser Run it as the account's own Linux user (the daemon, which is root).
     * @return array Its answer ('error' => text on failure).
     */
    static function Run($uid, array $site, $op, array $extra = array(), $asUser = false)
    {
        $home = realpath(self::Home($uid));
        if ($home === false || !preg_match('#^/[A-Za-z0-9_./-]+$#', $home)) {
            return array('error' => 'Your hosting folder does not exist yet.');
        }
        $helper = dirname(__FILE__) . '/helper.php';
        $php = PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (!is_executable($php)) {
            $php = PHP_BINDIR . '/php';
        }
        // confined to the account's folder (and its own code); no commands, no URLs, no panel settings
        $cmd = array($php, '-d', 'open_basedir=' . $home . '/:' . dirname($helper) . '/',
            '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,putenv,mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,dl,ini_set,set_include_path',
            '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'memory_limit=64M', '-d', 'max_execution_time=' . self::TIMEOUT,
            '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'auto_prepend_file=', $helper);
        if ($asUser) {
            if (posix_getpwnam(sys_accountphp::UserName($uid)) === false) {
                return array('error' => 'The account\'s Linux user is not made yet.');
            }
            $user = sys_accountphp::UserName($uid);
            $cmd = array_merge(array('setpriv', '--reuid=' . $user, '--regid=' . $user, '--clear-groups', '--reset-env', 'env', '-i', 'PATH=/usr/bin:/bin', 'LANG=C.UTF-8'), $cmd);
        }
        $p = @proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $home, array('PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8'));
        if (!is_resource($p)) {
            return array('error' => 'The Apps worker could not be started.');
        }
        fwrite($pipes[0], json_encode(array('op' => $op, 'home' => $home, 'dir' => $site['dir'], 'welcome' => self::WelcomeHash()) + $extra));
        fclose($pipes[0]);
        $out = '';
        $until = time() + self::TIMEOUT + 5;
        stream_set_blocking($pipes[1], false);
        while (!feof($pipes[1]) && time() < $until) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === '' || $chunk === false) {
                usleep(20000);
                continue;
            }
            $out .= $chunk;
            if (strlen($out) > 1048576) {
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($p);
        proc_close($p);
        $a = json_decode($out, true);
        return is_array($a) ? $a : array('error' => 'The Apps worker gave no answer.');
    }

    /**
     * The MySQL server as WordPress names it (DB_HOST), from the panel's own connection.
     *
     * @global string $host The panel's database host (cnf/db.php: host, or host;port=N).
     * @return string
     */
    static function DbHost()
    {
        global $host;
        $h = isset($host) ? (string) $host : 'localhost';
        if (preg_match('/^([A-Za-z0-9.:-]+);port=(\d+)$/', $h, $m)) {
            return $m[1] . ':' . $m[2];
        }
        return preg_match('/^[A-Za-z0-9.:-]+$/', $h) ? $h : 'localhost';
    }

    /**
     * Room for one more database in the account's package?
     *
     * @param int $uid
     * @return bool
     */
    static function DbRoom($uid)
    {
        $u = ctrl_users::GetUserDetail((int) $uid);
        return (int) $u['mysqlquota'] < 0 || (int) $u['mysqlquota'] > (int) ctrl_users::GetQuotaUsages('mysql', (int) $u['userid']);
    }

    /**
     * Room on the account's disk for an app? Over its package's disk space, the panel shows every
     * one of the account's websites as "disk space exceeded", so an install must not cross it. The
     * usage is the panel's own (the daemon counts the account's folder, mail and databases every
     * run); apps already waiting to be installed count as used.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @param int $need Bytes the app takes.
     * @param int $exclude An app (x_apps) not to count as waiting (the daemon's own).
     * @return array{0:bool, 1:int, 2:int} Room, bytes free (-1: no limit), the limit.
     */
    static function DiskRoom($uid, $need, $exclude = 0)
    {
        global $zdbh;
        $u = ctrl_users::GetUserDetail((int) $uid);
        $limit = (int) $u['diskquota'];
        if ($limit <= 0) {
            return array(true, -1, 0);
        }
        $used = (int) ctrl_users::GetQuotaUsages('diskspace', (int) $u['userid']);
        $recipes = self::Recipes();
        self::Ensure();
        $q = $zdbh->prepare("SELECT ap_app_vc FROM x_apps WHERE ap_acc_fk = :u AND ap_state_vc = 'queued' AND ap_deleted_in IS NULL AND ap_id_pk <> :x");
        $q->execute(array(':u' => (int) $uid, ':x' => (int) $exclude));
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $app) {
            $used += isset($recipes[$app]['disk']) ? (int) $recipes[$app]['disk'] : 0;
        }
        $free = max(0, $limit - $used);
        return array($free >= (int) $need, $free, $limit);
    }

    /**
     * Megabytes as the panel shows them (1 MB = 1,024,000 bytes, as the Packages page stores them).
     *
     * @param int $bytes
     * @return string
     */
    static function Mb($bytes)
    {
        return (string) round($bytes / 1024000);
    }

    /**
     * A free name for the app's database (and its MySQL user, the same name): <account>_<app><n>.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $username The account's user name.
     * @param string $short Such as wp.
     * @return string '' when the account's name is too long for one (32 characters).
     */
    static function FreeDbName($username, $short)
    {
        global $zdbh;
        $qd = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_databases WHERE my_name_vc = :n AND my_deleted_ts IS NULL");
        $qu = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_users WHERE mu_name_vc = :n AND mu_deleted_ts IS NULL");
        $qm = $zdbh->prepare("SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :n) + (SELECT COUNT(*) FROM mysql.user WHERE user = :n2)");
        for ($i = 1; $i < 100; $i++) {
            $n = $username . '_' . $short . ($i > 1 ? $i : '');
            if (strlen($n) > 32) {
                return '';
            }
            $qd->execute(array(':n' => $n));
            $qu->execute(array(':n' => $n));
            $qm->execute(array(':n' => $n, ':n2' => $n));
            if ((int) $qd->fetchColumn() === 0 && (int) $qu->fetchColumn() === 0 && (int) $qm->fetchColumn() === 0) {
                return $n;
            }
        }
        return '';
    }

    /**
     * Make the app's database and its MySQL user (this server only, all rights on that database),
     * the way MySQL Databases and MySQL Users make them: they show on those pages, count toward the
     * package and the password is kept encrypted (shown to the owner there on request).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @param string $name From FreeDbName().
     * @return array{0:int, 1:int}|string The database's and the user's IDs, or what went wrong.
     */
    static function MakeDb($uid, $name)
    {
        global $zdbh;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $name)) {
            return 'Not a database name.';
        }
        $access = 'localhost';
        // letters and digits only (about 140 bits): every app's installer takes it as it is (OpenCart's
        // reads a value starting with -- as an option, and decodes &...; in it)
        $abc = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ($i = 0; $i < 24; $i++) {
            $password .= $abc[random_int(0, 61)];
        }
        // the install reads the password back from the panel's encrypted copy: no key, no install
        $enc = ctrl_secret::Encrypt($password);
        if ($enc === '') {
            return 'This server has no key for stored passwords yet (cnf/security.php): run sentora-update, or ask the administrator.';
        }
        $made = array();
        try {
            runtime_hook::Execute('OnBeforeCreateDatabase');
            $zdbh->prepare("CREATE DATABASE `" . $name . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")->execute();
            $made[] = 'db';
            $zdbh->prepare("INSERT INTO x_mysql_databases (my_acc_fk, my_name_vc, my_created_ts) VALUES (:u, :n, :t)")->execute(array(':u' => (int) $uid, ':n' => $name, ':t' => time()));
            $db = (int) $zdbh->lastInsertId();
            runtime_hook::Execute('OnAfterCreateDatabase');
            runtime_hook::Execute('OnBeforeCreateDatabaseUser');
            $zdbh->prepare("CREATE USER :n@:a")->execute(array(':n' => $name, ':a' => $access));
            $made[] = 'user';
            if (version_compare(sys_versions::ShowMySQLVersion(), '5.7.6', '<')) {
                $zdbh->prepare("SET PASSWORD FOR :n@:a = PASSWORD(:p)")->execute(array(':n' => $name, ':a' => $access, ':p' => $password));
            } else {
                $zdbh->prepare("ALTER USER :n@:a IDENTIFIED " . (sys_oldlogins::NewUsersNeedNative((int) $uid) ? 'WITH mysql_native_password ' : '') . "BY :p")
                    ->execute(array(':n' => $name, ':a' => $access, ':p' => $password));
            }
            $zdbh->prepare("GRANT USAGE ON *.* TO :n@:a")->execute(array(':n' => $name, ':a' => $access));
            // _ and % are wildcards in a GRANT's database name: escaped, it means only itself
            $zdbh->prepare("GRANT ALL PRIVILEGES ON `" . str_replace(array('_', '%'), array('\\_', '\\%'), $name) . "`.* TO `" . $name . "`@`" . $access . "`")->execute();
            $zdbh->prepare("FLUSH PRIVILEGES")->execute();
            self::EnsurePassColumn();
            $zdbh->prepare("INSERT INTO x_mysql_users (mu_acc_fk, mu_name_vc, mu_database_fk, mu_pass_vc, mu_access_vc, mu_created_ts) VALUES (:u, :n, :d, :p, :a, :t)")
                ->execute(array(':u' => (int) $uid, ':n' => $name, ':d' => $db, ':p' => $enc, ':a' => $access, ':t' => time()));
            $user = (int) $zdbh->lastInsertId();
            $zdbh->prepare("INSERT INTO x_mysql_dbmap (mm_acc_fk, mm_user_fk, mm_database_fk) VALUES (:u, :m, :d)")->execute(array(':u' => (int) $uid, ':m' => $user, ':d' => $db));
            runtime_hook::Execute('OnAfterCreateDatabaseUser');
            return array($db, $user);
        } catch (Exception $e) {
            // half made: taken away again
            try {
                if (in_array('user', $made, true)) {
                    $zdbh->prepare("DROP USER IF EXISTS :n@:a")->execute(array(':n' => $name, ':a' => $access));
                }
                if (in_array('db', $made, true)) {
                    $zdbh->prepare("DROP DATABASE IF EXISTS `" . $name . "`")->execute();
                }
                $zdbh->prepare("UPDATE x_mysql_databases SET my_deleted_ts = :t WHERE my_name_vc = :n AND my_acc_fk = :u AND my_deleted_ts IS NULL")->execute(array(':t' => time(), ':n' => $name, ':u' => (int) $uid));
                $zdbh->prepare("UPDATE x_mysql_users SET mu_deleted_ts = :t WHERE mu_name_vc = :n AND mu_acc_fk = :u AND mu_deleted_ts IS NULL")->execute(array(':t' => time(), ':n' => $name, ':u' => (int) $uid));
            } catch (Exception $e2) {
            }
            return 'The database could not be made.';
        }
    }

    /**
     * Drop the app's database and MySQL user (only the account's own, still there).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @param int $db
     * @param int $user
     * @return void
     */
    static function DropDb($uid, $db, $user)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT mu_name_vc, mu_access_vc FROM x_mysql_users WHERE mu_id_pk = :id AND mu_acc_fk = :u AND mu_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $user, ':u' => (int) $uid));
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            runtime_hook::Execute('OnBeforeDeleteDatabaseUser');
            $zdbh->prepare("DROP USER IF EXISTS :n@:a")->execute(array(':n' => $r['mu_name_vc'], ':a' => $r['mu_access_vc']));
            $zdbh->prepare("UPDATE x_mysql_users SET mu_deleted_ts = :t WHERE mu_id_pk = :id")->execute(array(':t' => time(), ':id' => (int) $user));
            $zdbh->prepare("DELETE FROM x_mysql_dbmap WHERE mm_user_fk = :id")->execute(array(':id' => (int) $user));
            runtime_hook::Execute('OnAfterDeleteDatabaseUser');
        }
        $q = $zdbh->prepare("SELECT my_name_vc FROM x_mysql_databases WHERE my_id_pk = :id AND my_acc_fk = :u AND my_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $db, ':u' => (int) $uid));
        $name = (string) $q->fetchColumn();
        if ($name !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $name)) {
            runtime_hook::Execute('OnBeforeDeleteDatabase');
            $zdbh->prepare("DROP DATABASE IF EXISTS `" . $name . "`")->execute();
            $zdbh->prepare("UPDATE x_mysql_databases SET my_deleted_ts = :t WHERE my_id_pk = :id")->execute(array(':t' => time(), ':id' => (int) $db));
            $zdbh->prepare("DELETE FROM x_mysql_dbmap WHERE mm_database_fk = :id")->execute(array(':id' => (int) $db));
            runtime_hook::Execute('OnAfterDeleteDatabase');
        }
        $zdbh->prepare("FLUSH PRIVILEGES")->execute();
    }

    /**
     * The database name and MySQL user of an app, and the user's password (decrypted: the daemon
     * hands it to the install on its input, never on a command line).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $app Its x_apps row.
     * @return array{0:string, 1:string, 2:string}|null
     */
    static function DbLogin(array $app)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT my_name_vc FROM x_mysql_databases WHERE my_id_pk = :id AND my_acc_fk = :u AND my_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $app['ap_db_fk'], ':u' => (int) $app['ap_acc_fk']));
        $db = (string) $q->fetchColumn();
        $q = $zdbh->prepare("SELECT mu_name_vc, mu_pass_vc FROM x_mysql_users WHERE mu_id_pk = :id AND mu_acc_fk = :u AND mu_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $app['ap_dbuser_fk'], ':u' => (int) $app['ap_acc_fk']));
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if ($db === '' || !$u) {
            return null;
        }
        $pass = ctrl_secret::Decrypt((string) $u['mu_pass_vc']);
        return is_string($pass) && $pass !== '' ? array($db, (string) $u['mu_name_vc'], $pass) : null;
    }

    /**
     * Room for the encrypted password (older databases have 40 characters), as MySQL Users does.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function EnsurePassColumn()
    {
        global $zdbh;
        $w = $zdbh->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x_mysql_users' AND COLUMN_NAME = 'mu_pass_vc'")->fetchColumn();
        if ((int) $w < 255) {
            $zdbh->prepare("ALTER TABLE x_mysql_users MODIFY mu_pass_vc VARCHAR(255) DEFAULT NULL")->execute();
        }
    }

    /**
     * A password as WordPress (6.8 and later) stores it: bcrypt of its SHA-384 HMAC, with $wp in
     * front. The panel keeps only this, never the password.
     *
     * @param string $password
     * @return string
     */
    static function WpHash($password)
    {
        return '$wp' . password_hash(base64_encode(hash_hmac('sha384', (string) $password, 'wp-sha384', true)), PASSWORD_BCRYPT);
    }

    /**
     * The admin password as the app stores it: WordPress's own form (WpHash), or OpenCart's and
     * Snipe-IT's (PHP's password_hash, bcrypt).
     *
     * @param string $app
     * @param string $password
     * @return string
     */
    static function AppHash($app, $password)
    {
        // (OpenCart and Snipe-IT: bcrypt, as they make it themselves)
        return $app === 'wordpress' ? self::WpHash($password) : password_hash((string) $password, PASSWORD_BCRYPT);
    }

    /**
     * The MySQL server as host and port (OpenCart takes them apart).
     *
     * @return array{0:string, 1:string}
     */
    static function DbHostPort()
    {
        $h = self::DbHost();
        if (preg_match('/^(.+):(\d+)$/', $h, $m)) {
            return array($m[1], $m[2]);
        }
        return array($h, '3306');
    }
}
