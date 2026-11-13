<?php

/**
 * Docker Hosting, the panel's side. Containers run on separate servers ("nodes", each with the
 * Sentora Node agent), never on the panel's own server. A node calls the panel (no port of the node
 * is opened for the panel), proves who it is with its own keys (dh_proto), takes the jobs waiting for
 * it and reports back. A node only does fixed jobs: create, start, stop, restart, limits, logs,
 * public, delete.
 *
 * Who may run containers, and how much, passes down the account tree: an administrator has no
 * limit and gives resellers a share ("grant": containers, CPU, memory, disk, apps); a reseller gives
 * its clients parts of its own share. What an account runs itself plus what it has given its
 * clients never goes over what it was given.
 *
 * Nothing of the core or its tables is changed: the account tree, packages and domains are only
 * read. The one exception is the DNS record a customer asks for, which is added the way DNS Manager
 * adds one (a row in x_dns for the customer's own domain, with DNS Manager's checks, and the domain
 * on the dns_hasupdates list so the daemon rewrites its zone).
 *
 * Units: CPU in hundredths of a CPU (100 = one CPU), memory and disk in MB.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
require_once dirname(__FILE__) . '/proto.php';
require_once dirname(__FILE__) . '/catalog.php';
require_once dirname(__FILE__) . '/backup.php';

class dh
{
    /** A node counts as online while it called within this many seconds. */
    const ONLINE = 120;

    /** A pairing code is good for this long (seconds). */
    const CODE_LIFE = 3600;

    /** Ports on a node's 127.0.0.1 for the containers (its proxy forwards to them). */
    const PORT_FIRST = 20000;
    const PORT_LAST = 29999;

    /** Part of a node's memory and disk that may be given to containers (percent); CPU may be shared 4 times over. */
    const MEM_SHARE = 90;
    const DISK_SHARE = 90;
    const CPU_OVER = 4;

    /** A job sent and not answered within this many seconds is sent again (jobs can be done twice safely). */
    const RESEND = 900;

    /** Linking messages (pair, hello) one address may send in 10 minutes (a waiting node sends 60). */
    const LINK_MAX = 300;

    /** Most containers one account may hold, whatever its grant (a safety cap). */
    const MAX_COUNT = 200;

    /** Where the node's installer is. */
    const NODE_INSTALLER = 'https://raw.githubusercontent.com/RusTus/sentora-installers-2027/master2027/docker-node/sentora2027-docker-node';

    /** @var string The reason for the last refusal (English). */
    static $why = '';

    // ----- tables and settings -----

    /**
     * Create the tables if missing (once per request).
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
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_settings (st_name_vc VARCHAR(60) NOT NULL PRIMARY KEY, st_value_tx TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_nodes (
            nd_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, nd_name_vc VARCHAR(80) NOT NULL, nd_signpk_vc VARCHAR(100) NOT NULL DEFAULT '', nd_boxpk_vc VARCHAR(100) NOT NULL DEFAULT '',
            nd_code_vc VARCHAR(64) NOT NULL DEFAULT '', nd_codeexp_in INT NOT NULL DEFAULT 0, nd_seen_in INT NOT NULL DEFAULT 0, nd_info_tx TEXT NULL,
            nd_ip_vc VARCHAR(45) NOT NULL DEFAULT '', nd_publicip_vc VARCHAR(45) NOT NULL DEFAULT '', nd_accepting_in TINYINT NOT NULL DEFAULT 1,
            nd_created_in INT NOT NULL, nd_deleted_in INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_grants (
            gr_acc_fk INT NOT NULL PRIMARY KEY, gr_by_fk INT NOT NULL, gr_count_in INT NOT NULL DEFAULT 0, gr_cpu_in INT NOT NULL DEFAULT 0,
            gr_mem_in INT NOT NULL DEFAULT 0, gr_disk_in INT NOT NULL DEFAULT 0, gr_apps_tx TEXT NULL, gr_updated_in INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_containers (
            dc_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, dc_acc_fk INT NOT NULL, dc_node_fk INT NOT NULL, dc_app_vc VARCHAR(40) NOT NULL, dc_label_vc VARCHAR(60) NOT NULL DEFAULT '',
            dc_cpu_in INT NOT NULL, dc_mem_in INT NOT NULL, dc_disk_in INT NOT NULL, dc_port_in INT NOT NULL, dc_domain_vc VARCHAR(253) NOT NULL DEFAULT '',
            dc_vhost_fk INT NOT NULL DEFAULT 0, dc_dns_fk INT NOT NULL DEFAULT 0, dc_state_vc VARCHAR(20) NOT NULL DEFAULT 'creating', dc_want_vc VARCHAR(10) NOT NULL DEFAULT 'running',
            dc_held_vc VARCHAR(10) NOT NULL DEFAULT '', dc_public_in TINYINT NOT NULL DEFAULT 0, dc_setup_tx TEXT NULL, dc_used_in INT NOT NULL DEFAULT 0,
            dc_note_vc VARCHAR(255) NOT NULL DEFAULT '', dc_created_in INT NOT NULL, dc_deleted_in INT NULL,
            KEY dc_acc (dc_acc_fk), KEY dc_node (dc_node_fk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_jobs (
            jb_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, jb_node_fk INT NOT NULL, jb_ct_fk INT NOT NULL DEFAULT 0, jb_action_vc VARCHAR(20) NOT NULL,
            jb_data_tx TEXT NULL, jb_state_vc VARCHAR(10) NOT NULL DEFAULT 'queued', jb_sent_in INT NOT NULL DEFAULT 0, jb_tries_in INT NOT NULL DEFAULT 0,
            jb_result_tx MEDIUMTEXT NULL, jb_created_in INT NOT NULL, jb_done_in INT NULL, KEY jb_node (jb_node_fk, jb_state_vc), KEY jb_ct (jb_ct_fk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_log (
            lg_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, lg_time_in INT NOT NULL, lg_acc_fk INT NOT NULL DEFAULT 0, lg_by_fk INT NOT NULL DEFAULT 0,
            lg_ct_fk INT NOT NULL DEFAULT 0, lg_node_fk INT NOT NULL DEFAULT 0, lg_what_vc VARCHAR(20) NOT NULL, lg_note_vc VARCHAR(255) NOT NULL DEFAULT '',
            KEY lg_acc (lg_acc_fk, lg_time_in)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_apps (
            ap_id_vc VARCHAR(40) NOT NULL PRIMARY KEY, ap_name_vc VARCHAR(60) NOT NULL, ap_desc_vc VARCHAR(255) NOT NULL DEFAULT '', ap_image_vc VARCHAR(300) NOT NULL,
            ap_port_in INT NOT NULL, ap_volumes_tx TEXT NULL, ap_env_tx TEXT NULL, ap_minmem_in INT NOT NULL, ap_mem_in INT NOT NULL, ap_cpu_in INT NOT NULL, ap_disk_in INT NOT NULL,
            ap_web_vc VARCHAR(200) NOT NULL DEFAULT '', ap_by_fk INT NOT NULL DEFAULT 0, ap_created_in INT NOT NULL, ap_updated_in INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_nonces (nn_nonce_vc CHAR(32) NOT NULL PRIMARY KEY, nn_time_in INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // (105) app updates, problems noticed, node keys, node removal, node settings, warnings sent
        // (112) the owner's own settings for the app (environment variables), encrypted
        self::AddColumns('x_dh_containers', array('dc_env_tx' => "TEXT NULL", 'dc_envprev_tx' => "TEXT NULL"));
        // (120) where a linking message came from: at most LINK_MAX of them per address in 10 minutes
        self::AddColumns('x_dh_nonces', array('nn_ip_vc' => "VARCHAR(45) NOT NULL DEFAULT ''", 'nn_link_in' => "TINYINT NOT NULL DEFAULT 0"));
        self::AddColumns('x_dh_containers', array('dc_update_in' => "TINYINT NOT NULL DEFAULT 0", 'dc_autoupd_in' => "TINYINT NOT NULL DEFAULT 0",
            'dc_badsince_in' => "INT NOT NULL DEFAULT 0"));
        self::AddColumns('x_dh_nodes', array('nd_claim_vc' => "VARCHAR(64) NOT NULL DEFAULT ''", 'nd_removing_in' => "TINYINT NOT NULL DEFAULT 0",
            'nd_settings_tx' => "TEXT NULL", 'nd_autosec_in' => "TINYINT NOT NULL DEFAULT 0", 'nd_autoagent_in' => "TINYINT NOT NULL DEFAULT 1", 'nd_warned_tx' => "TEXT NULL",
            'nd_gonesign_vc' => "VARCHAR(100) NOT NULL DEFAULT ''", 'nd_gonebox_vc' => "VARCHAR(100) NOT NULL DEFAULT ''"));
        // (122) apps a node has that the panel does not know (a node linked again, a panel set up again or
        // restored): kept, listed, and given back to an owner by an administrator; settings kept when taken back
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_found (
            fd_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, fd_node_fk INT NOT NULL, fd_cid_in INT NOT NULL, fd_user_vc VARCHAR(20) NOT NULL DEFAULT '',
            fd_image_vc VARCHAR(300) NOT NULL DEFAULT '', fd_domain_vc VARCHAR(253) NOT NULL DEFAULT '', fd_hostport_in INT NOT NULL DEFAULT 0, fd_volumes_in INT NOT NULL DEFAULT 0,
            fd_public_in TINYINT NOT NULL DEFAULT 0, fd_state_vc VARCHAR(20) NOT NULL DEFAULT '', fd_used_in INT NOT NULL DEFAULT 0, fd_first_in INT NOT NULL DEFAULT 0,
            fd_seen_in INT NOT NULL DEFAULT 0, fd_acc_fk INT NOT NULL DEFAULT 0, fd_app_vc VARCHAR(40) NOT NULL DEFAULT '', fd_step_vc VARCHAR(10) NOT NULL DEFAULT '',
            fd_target_in INT NOT NULL DEFAULT 0, fd_note_vc VARCHAR(255) NOT NULL DEFAULT '', KEY fd_node (fd_node_fk, fd_cid_in)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::AddColumns('x_dh_containers', array('dc_keepenv_tx' => "TEXT NULL"));
        // (123) a seller's Docker plans (its own packages for Docker Hosting, apart from the panel's: the
        // apps run on other servers); a client's share follows its plan (gr_plan_fk, 0 = its own amounts)
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_plans (
            pl_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, pl_by_fk INT NOT NULL, pl_name_vc VARCHAR(40) NOT NULL, pl_count_in INT NOT NULL DEFAULT 0,
            pl_cpu_in INT NOT NULL DEFAULT 0, pl_mem_in INT NOT NULL DEFAULT 0, pl_disk_in INT NOT NULL DEFAULT 0, pl_apps_tx TEXT NULL,
            pl_created_in INT NOT NULL, pl_deleted_in INT NULL, KEY pl_by (pl_by_fk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::AddColumns('x_dh_grants', array('gr_plan_fk' => "INT NOT NULL DEFAULT 0"));
        // (125) app backups, restores and moves between nodes
        dh_backup::Ensure();
        $done = true;
    }

    /**
     * Add the columns a table is missing (a server that had an older version of the add-on).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $table
     * @param array<string, string> $cols name => definition
     * @return void
     */
    static function AddColumns($table, array $cols)
    {
        global $zdbh;
        $q = $zdbh->query("SELECT * FROM " . $table . " LIMIT 1");
        $have = array();
        for ($i = 0; $i < $q->columnCount(); $i++) {
            $m = $q->getColumnMeta($i);
            $have[strtolower($m['name'])] = true;
        }
        $q->closeCursor();
        foreach ($cols as $c => $def) {
            if (!isset($have[$c])) {
                $zdbh->exec("ALTER TABLE " . $table . " ADD COLUMN " . $c . " " . $def);
            }
        }
    }

    /**
     * The panel's code, from its public keys (the node installer shows the same one, to be checked).
     *
     * @return string like K7Q2-9MXD
     */
    static function Fingerprint()
    {
        $k = self::Keys();
        if (!$k) {
            return '';
        }
        $b = self::Base32(hash('sha256', 'sentora-panel|' . $k['sign_pk'] . $k['box_pk'], true));
        return substr($b, 0, 4) . '-' . substr($b, 4, 4);
    }

    /**
     * Base32 without letters people mix up (no I, O, 0, 1).
     *
     * @param string $bin
     * @return string
     */
    static function Base32($bin)
    {
        $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $a[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    /**
     * A node key as typed (SN1-XXXX-...) back to its secret, or false.
     *
     * @param string $key
     * @return string|false
     */
    static function KeySecret($key)
    {
        $k = strtoupper(preg_replace('/\s+/', '', (string) $key));
        if (!preg_match('/^SN1((-[A-HJ-NP-Z2-9]{4}){6})$/', $k, $m)) {
            return false;
        }
        $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $bits = '';
        foreach (str_split(str_replace('-', '', $m[1])) as $c) {
            $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split(substr($bits, 0, 120), 8) as $b) {
            $out .= chr(bindec($b));
        }
        return $out;
    }

    /**
     * A setting.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name
     * @param string $default
     * @return string
     */
    static function Get($name, $default = '')
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT st_value_tx FROM x_dh_settings WHERE st_name_vc = :n");
        $q->execute(array(':n' => $name));
        $v = $q->fetchColumn();
        return $v === false || $v === null ? $default : (string) $v;
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
        $zdbh->prepare("DELETE FROM x_dh_settings WHERE st_name_vc = :n")->execute(array(':n' => $name));
        $zdbh->prepare("INSERT INTO x_dh_settings (st_name_vc, st_value_tx) VALUES (:n, :v)")->execute(array(':n' => $name, ':v' => (string) $value));
    }

    /**
     * The panel's own keys (made the first time; the secret halves are kept encrypted with the
     * server's secret, cnf/security.php), or null when this server can not keep secrets.
     *
     * @return array{sign_sk:string, sign_pk:string, box_kp:string, box_pk:string}|null
     */
    static function Keys()
    {
        static $keys = null;
        if ($keys !== null) {
            return $keys;
        }
        $sk = ctrl_secret::Decrypt(self::Get('sign_sk'));
        $kp = ctrl_secret::Decrypt(self::Get('box_kp'));
        if ($sk === false || $kp === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || strlen($kp) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
            if (self::Get('sign_sk') !== '') {
                // keys there but not readable: the server's secret changed (never made anew silently: nodes trust these)
                return null;
            }
            $s = sodium_crypto_sign_keypair();
            $b = sodium_crypto_box_keypair();
            $es = ctrl_secret::Encrypt(sodium_crypto_sign_secretkey($s));
            $eb = ctrl_secret::Encrypt($b);
            if ($es === '' || $eb === '') {
                return null;
            }
            self::Set('sign_sk', $es);
            self::Set('box_kp', $eb);
            $sk = sodium_crypto_sign_secretkey($s);
            $kp = $b;
        }
        return $keys = array('sign_sk' => $sk, 'sign_pk' => sodium_crypto_sign_publickey_from_secretkey($sk), 'box_kp' => $kp, 'box_pk' => sodium_crypto_box_publickey($kp));
    }

    /**
     * The panel's public keys as one line for the node's installer.
     *
     * @return string
     */
    static function PanelKey()
    {
        $k = self::Keys();
        return $k ? 'v1.' . dh_proto::B64($k['sign_pk']) . '.' . dh_proto::B64($k['box_pk']) : '';
    }

    /**
     * The panel's address as nodes reach it.
     *
     * @return string
     */
    static function PanelUrl()
    {
        $u = self::Get('panel_url');
        return $u !== '' ? $u : 'https://' . ctrl_options::GetSystemOption('sentora_domain');
    }

    /**
     * Note something in the log.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $what
     * @param string $note
     * @param int $acc
     * @param int $by
     * @param int $ct
     * @param int $node
     * @return void
     */
    static function Log($what, $note, $acc = 0, $by = 0, $ct = 0, $node = 0)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("INSERT INTO x_dh_log (lg_time_in, lg_acc_fk, lg_by_fk, lg_ct_fk, lg_node_fk, lg_what_vc, lg_note_vc) VALUES (:t, :a, :b, :c, :n, :w, :o)")
            ->execute(array(':t' => time(), ':a' => (int) $acc, ':b' => (int) $by, ':c' => (int) $ct, ':n' => (int) $node, ':w' => substr($what, 0, 20), ':o' => mb_substr($note, 0, 255)));
    }

    /**
     * Refuse with a reason.
     *
     * @param string $why English.
     * @return false
     */
    static function No($why)
    {
        self::$why = $why;
        return false;
    }

    // ----- accounts and grants -----

    /**
     * An account (not deleted), or null.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return array|null ac_id_pk, ac_user_vc, ac_reseller_fk, ac_group_fk, ac_enabled_in
     */
    static function Account($id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_id_pk, ac_user_vc, ac_reseller_fk, ac_group_fk, ac_enabled_in FROM x_accounts WHERE ac_id_pk = :a AND ac_deleted_ts IS NULL");
        $q->execute(array(':a' => (int) $id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    /**
     * Whether an account is an administrator (no limits).
     *
     * @param array|null $acc
     * @return bool
     */
    static function IsAdmin($acc)
    {
        return is_array($acc) && (int) $acc['ac_group_fk'] === 1;
    }

    /**
     * An account's direct clients (not deleted, not itself).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @return array<int, array>
     */
    static function Children($acc)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_id_pk, ac_user_vc, ac_group_fk, ac_enabled_in FROM x_accounts WHERE ac_reseller_fk = :a AND ac_id_pk <> :a AND ac_deleted_ts IS NULL ORDER BY ac_user_vc");
        $q->execute(array(':a' => (int) $acc));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['ac_id_pk']] = $r;
        }
        return $out;
    }

    /**
     * What an account was given: count, cpu, mem, disk, apps (app IDs), unlimited; null when nothing.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @return array|null
     */
    static function Grant($acc)
    {
        global $zdbh;
        self::Ensure();
        $a = self::Account($acc);
        if (!$a) {
            return null;
        }
        if (self::IsAdmin($a)) {
            return array('count' => PHP_INT_MAX, 'cpu' => PHP_INT_MAX, 'mem' => PHP_INT_MAX, 'disk' => PHP_INT_MAX, 'apps' => self::EnabledApps(), 'unlimited' => true, 'by' => 0);
        }
        $q = $zdbh->prepare("SELECT * FROM x_dh_grants WHERE gr_acc_fk = :a");
        $q->execute(array(':a' => (int) $acc));
        $g = $q->fetch(PDO::FETCH_ASSOC);
        // a grant counts only while it comes from the account above (an account moved to another reseller starts again)
        if (!$g || (int) $g['gr_by_fk'] !== (int) $a['ac_reseller_fk'] || (int) $g['gr_count_in'] <= 0) {
            return null;
        }
        // apps: what it was given, and still has above it
        $parent = self::Grant((int) $a['ac_reseller_fk']);
        $apps = $parent ? array_values(array_intersect(self::AppList((string) $g['gr_apps_tx']), $parent['apps'])) : array();
        return array('count' => (int) $g['gr_count_in'], 'cpu' => (int) $g['gr_cpu_in'], 'mem' => (int) $g['gr_mem_in'], 'disk' => (int) $g['gr_disk_in'],
            'apps' => $apps, 'unlimited' => false, 'by' => (int) $g['gr_by_fk']);
    }

    /**
     * App IDs from their stored list.
     *
     * @param string $text
     * @return array<int, string>
     */
    static function AppList($text)
    {
        return array_values(array_filter(explode(',', $text), function ($a) {
            return dh_catalog::App($a) !== null;
        }));
    }

    /**
     * The apps the administrator offers (all, until it chooses).
     *
     * @return array<int, string>
     */
    static function EnabledApps()
    {
        $v = self::Get('apps', '*');
        return $v === '*' ? array_keys(dh_catalog::Apps()) : self::AppList($v);
    }

    // ----- the administrators' own apps -----

    /**
     * Read a `docker run` (or `podman run`) line from an image's page into the fields of an app of
     * one's own. It is only read, never run. What the node never allows (privileged, the host's
     * network, devices, capabilities, the host's folders...) and what an app here can not use is
     * left out and named, so the administrator sees it.
     *
     * @param string $line
     * @return array|false array('fields' => name, image, port, volumes (text), env (text), mem, cpu; 'left' => list of what was left out)
     */
    static function FromDockerRun($line)
    {
        $line = (string) $line;
        if (strlen($line) > 8000) {
            return self::No('That is too long for one docker run line.');
        }
        // the words, as a shell splits them (quotes, a backslash at the end of a line)
        $line = preg_replace('/\\\\\r?\n/', ' ', $line);
        $w = array();
        $cur = null;
        $q = '';
        $len = strlen($line);
        for ($k = 0; $k < $len; $k++) {
            $c = $line[$k];
            if ($q !== '') {
                if ($c === $q) {
                    $q = '';
                } elseif ($c === '\\' && $q === '"' && $k + 1 < $len) {
                    $cur .= $line[++$k];
                } else {
                    $cur .= $c;
                }
            } elseif ($c === '"' || $c === "'") {
                $q = $c;
                $cur = (string) $cur;
            } elseif ($c === '\\' && $k + 1 < $len) {
                $cur .= $line[++$k];
            } elseif (ctype_space($c)) {
                if ($cur !== null) {
                    $w[] = $cur;
                    $cur = null;
                }
            } else {
                $cur .= $c;
            }
        }
        if ($cur !== null) {
            $w[] = $cur;
        }
        while ($w && in_array($w[0], array('sudo', '$', '#'), true)) {
            array_shift($w);
        }
        if (count($w) < 3 || !in_array($w[0], array('docker', 'podman'), true) || !in_array($w[1], array('run', 'create'), true)) {
            return self::No('Paste a line that starts with docker run (as the image\'s page shows it).');
        }
        $w = array_slice($w, 2);
        $f = array('name' => '', 'image' => '', 'port' => '', 'volumes' => array(), 'env' => array(), 'mem' => 0, 'cpu' => 0);
        $left = array();
        $ports = array();
        // options without a value
        $flags = array('-d', '--detach', '-i', '--interactive', '-t', '--tty', '-it', '-ti', '-dit', '-itd', '--rm', '--init', '-P', '--publish-all', '--read-only', '--privileged', '--no-healthcheck', '--sig-proxy');
        // handled by the node itself: dropped without a word
        $quiet = array('-d', '--detach', '-i', '--interactive', '-t', '--tty', '-it', '-ti', '-dit', '-itd', '--rm', '--init', '--restart', '--pull', '--log-driver', '--log-opt', '--sig-proxy');
        // never allowed on a node
        $never = array('--privileged', '--cap-add', '--device', '--security-opt', '--pid', '--ipc', '--uts', '--userns', '--cgroupns', '--cgroup-parent', '--sysctl', '--group-add', '-P', '--publish-all');
        $mb = function ($v) {
            if (!preg_match('/^(\d+(?:\.\d+)?)\s*([bkmg]?)b?$/i', trim($v), $m)) {
                return 0;
            }
            $x = (float) $m[1];
            $u = strtolower($m[2]);
            return (int) round($u === 'g' ? $x * 1024 : ($u === 'k' ? $x / 1024 : ($u === 'b' ? $x / 1048576 : $x)));
        };
        $k = 0;
        $n = count($w);
        while ($k < $n) {
            $t = $w[$k];
            if ($t === '' || $t[0] !== '-') {
                break;
            }
            $opt = $t;
            $val = null;
            if (strpos($t, '=') !== false && substr($t, 0, 2) === '--') {
                list($opt, $val) = explode('=', $t, 2);
            } elseif (preg_match('/^-([pvemuwlh])(.+)$/', $t, $m)) {
                // -p8080:80, -eNAME=value
                $opt = '-' . $m[1];
                $val = $m[2];
            }
            $k++;
            if ($val === null && !in_array($opt, $flags, true)) {
                if ($k >= $n) {
                    break;
                }
                $val = $w[$k++];
            }
            if (in_array($opt, $never, true)) {
                $left[] = $t . ($val !== null && strpos($t, '=') === false && !in_array($opt, $flags, true) ? ' ' . $val : '') . ' (never allowed on a node)';
            } elseif (in_array($opt, $quiet, true)) {
                continue;
            } elseif ($opt === '--name') {
                $f['name'] = $val;
            } elseif ($opt === '-p' || $opt === '--publish') {
                $parts = explode('/', $val);
                $proto = isset($parts[1]) ? strtolower($parts[1]) : 'tcp';
                $bits = explode(':', $parts[0]);
                $cport = end($bits);
                if ($proto !== 'tcp' || !ctype_digit($cport)) {
                    $left[] = '-p ' . $val . ' (only one web port, over TCP)';
                } else {
                    $ports[] = (int) $cport;
                }
            } elseif ($opt === '-v' || $opt === '--volume' || $opt === '--mount') {
                $target = '';
                $source = '';
                if ($opt === '--mount') {
                    foreach (explode(',', $val) as $kv) {
                        $kv = explode('=', $kv, 2);
                        if (in_array($kv[0], array('target', 'destination', 'dst'), true) && isset($kv[1])) {
                            $target = $kv[1];
                        } elseif (in_array($kv[0], array('source', 'src'), true) && isset($kv[1])) {
                            $source = $kv[1];
                        }
                    }
                } else {
                    $bits = explode(':', $val);
                    if (count($bits) >= 2) {
                        $source = $bits[0];
                        $target = $bits[1];
                    } else {
                        $target = $bits[0];
                    }
                }
                $target = rtrim($target, '/');
                if (strpos($source, '.sock') !== false || strpos($target, '.sock') !== false || in_array($target, array('', '/etc', '/proc', '/sys', '/dev', '/var/run', '/run'), true)) {
                    $left[] = $opt . ' ' . $val . ' (never allowed on a node)';
                } else {
                    $f['volumes'][] = $target;
                    if ($source !== '' && ($source[0] === '/' || $source[0] === '.' || $source[0] === '~' || $source[0] === '$')) {
                        $left[] = $opt . ' ' . $val . ': its folder on the host is not used; ' . $target . ' gets a store of its own on the node';
                    }
                }
            } elseif ($opt === '-e' || $opt === '--env') {
                if (strpos($val, '=') === false) {
                    $left[] = '-e ' . $val . ' (its value comes from the computer it was typed on: give it a value under Settings)';
                } else {
                    $f['env'][] = $val;
                }
            } elseif ($opt === '-m' || $opt === '--memory') {
                $f['mem'] = $mb($val);
            } elseif ($opt === '--cpus') {
                $f['cpu'] = (int) round((float) $val * 100);
            } elseif ($opt === '--network' || $opt === '--net') {
                $left[] = $opt . ' ' . $val . ($val === 'host' ? ' (never allowed on a node)' : ' (each app has its own network on the node)');
            } else {
                $left[] = $t . ($val !== null && strpos($t, '=') === false && !in_array($opt, $flags, true) ? ' ' . $val : '') . ' (not something an app here can use)';
            }
        }
        if ($k >= $n) {
            return self::No('No image name found in that line.');
        }
        $img = strtolower($w[$k]);
        $rest = array_slice($w, $k + 1);
        if ($rest) {
            $left[] = 'what follows the image (' . implode(' ', $rest) . '): an app here runs its image as it is';
        }
        // the full name, with the registry (as Docker reads a short one)
        $first = explode('/', $img)[0];
        if (strpos($img, '/') === false) {
            $img = 'docker.io/library/' . $img;
        } elseif (!preg_match('/[.:]/', $first) && $first !== 'localhost') {
            $img = 'docker.io/' . $img;
        }
        $f['image'] = $img;
        $ports = array_values(array_unique($ports));
        if ($ports) {
            $f['port'] = (string) $ports[0];
            foreach (array_slice($ports, 1) as $o) {
                $left[] = 'port ' . $o . ' (an app has one web address: the first port, ' . $ports[0] . ', is used)';
            }
        }
        if ($f['name'] === '') {
            $base = preg_replace('/[:@].*$/', '', basename($img));
            $f['name'] = ucwords(str_replace(array('-', '_'), ' ', $base));
        }
        $f['name'] = mb_substr(preg_replace('/[^\p{L}\p{N} ._+()&-]/u', ' ', $f['name']), 0, 60, 'UTF-8');
        $f['volumes'] = implode("\n", array_unique($f['volumes']));
        $f['env'] = implode("\n", $f['env']);
        return array('fields' => $f, 'left' => $left);
    }

    /**
     * Check an app an administrator typed in (the same rules the node checks again).
     *
     * @param array $p name, desc, image, port, volumes (one per line), env (KEY=value lines), minmem, mem (MB), cpu (hundredths), disk (MB), web
     * @return array|false The app's fields, or false (why in dh::$why).
     */
    static function CheckApp(array $p)
    {
        $s = function ($k) use ($p) {
            return isset($p[$k]) && is_string($p[$k]) ? trim($p[$k]) : '';
        };
        $name = $s('name');
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._+()&-]{0,59}$/u', $name)) {
            return self::No('Give the app a name: up to 60 letters, digits, spaces and . _ + ( ) & -');
        }
        $desc = $s('desc');
        if (!preg_match('/^.{0,255}$/u', $desc) || preg_match('/[\x00-\x1f\x7f]/', $desc)) {
            return self::No('The description is one line, up to 255 characters.');
        }
        $image = $s('image');
        if (strlen($image) > 300 || !preg_match('#^[a-z0-9.-]+(:\d{1,5})?/[a-z0-9._/-]+(:[A-Za-z0-9._-]{1,128})?(@sha256:[a-f0-9]{64})?$#', $image)
            || !preg_match('/[.:]/', explode('/', $image)[0])) {
            return self::No('The image needs its full name, with the registry it comes from, in small letters, for example docker.io/library/nginx:1 or ghcr.io/maker/app:latest.');
        }
        $port = $s('port');
        if (!preg_match('/^\d{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535) {
            return self::No('The port is the one the app listens on inside its container: a number from 1 to 65535 (the image\'s page says which, often 80, 3000 or 8080).');
        }
        $vols = array();
        foreach (preg_split('/\R/', $s('volumes')) as $v) {
            $v = rtrim(trim($v), '/');
            if ($v === '') {
                continue;
            }
            if (!preg_match('#^/[A-Za-z0-9._/-]{1,200}$#', $v) || strpos($v, '..') !== false || strpos($v, '//') !== false) {
                return self::No(sprintf('"%s" is not a folder inside the container: it starts with / and has only letters, digits and . _ - /', $v));
            }
            if (!in_array($v, $vols, true)) {
                $vols[] = $v;
            }
        }
        if (count($vols) > 10) {
            return self::No('Up to 10 data folders.');
        }
        $env = self::ParseEnv($s('env'));
        if ($env === false) {
            return false;
        }
        $n = array();
        foreach (array('minmem', 'mem', 'cpu', 'disk') as $k) {
            if (!isset($p[$k]) || !is_int($p[$k])) {
                return self::No('Give memory, CPU and disk as numbers.');
            }
            $n[$k] = $p[$k];
        }
        if ($n['minmem'] < 64 || $n['mem'] < $n['minmem'] || $n['mem'] > 262144 || $n['cpu'] < 10 || $n['cpu'] > 6400 || $n['disk'] < 256 || $n['disk'] > 10485760) {
            return self::No('Memory: at least 64 MB, and what is suggested not less than the least. CPU: 0.1 to 64. Disk: at least 0.25 GB.');
        }
        $web = $s('web');
        if ($web !== '' && (strlen($web) > 200 || !preg_match('#^https?://[^\s<>"\']+$#', $web) || filter_var($web, FILTER_VALIDATE_URL) === false)) {
            return self::No('The web page is an address starting with https:// (or leave it empty).');
        }
        return array('name' => $name, 'desc' => $desc, 'image' => $image, 'port' => (int) $port, 'volumes' => $vols, 'env' => $env,
            'minmem' => $n['minmem'], 'mem' => $n['mem'], 'cpu' => $n['cpu'], 'disk' => $n['disk'], 'web' => $web);
    }

    /**
     * How many containers (not yet deleted) use an app.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $id
     * @return int
     */
    static function AppUsers($id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_containers WHERE dc_app_vc = :a AND dc_deleted_in IS NULL");
        $q->execute(array(':a' => (string) $id));
        return (int) $q->fetchColumn();
    }

    /**
     * Add an app of the administrator's own, or change one ($id).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by The administrator.
     * @param array $p See CheckApp.
     * @param string $id '' for a new app.
     * @return string|false The app's id, or false (why in dh::$why).
     */
    static function SaveApp($by, array $p, $id = '')
    {
        global $zdbh;
        self::Ensure();
        if (!self::IsAdmin(self::Account($by))) {
            return self::No('Only the panel\'s administrators add apps.');
        }
        $a = self::CheckApp($p);
        if ($a === false) {
            return false;
        }
        $custom = dh_catalog::Custom();
        $row = array(':n' => $a['name'], ':d' => $a['desc'], ':i' => $a['image'], ':p' => $a['port'], ':v' => json_encode($a['volumes']),
            ':e' => ctrl_secret::Encrypt(json_encode((object) $a['env'])), ':mm' => $a['minmem'], ':m' => $a['mem'], ':c' => $a['cpu'], ':k' => $a['disk'], ':w' => $a['web'], ':t' => time());
        if ($row[':e'] === '') {
            return self::No('This server can not keep secrets (cnf/security.php): run sentora-post-install-check.');
        }
        if ($id !== '') {
            if (!isset($custom[$id])) {
                return self::No('No such app of your own.');
            }
            $old = $custom[$id];
            $users = self::AppUsers($id);
            if ($users > 0 && ($old['port'] !== $a['port'] || $old['volumes'] !== $a['volumes'])) {
                return self::No(sprintf('%d containers use this app: its port and data folders stay as they are (their data is kept in those folders). Everything else can change; for a different port or folders, add it as a new app.', $users));
            }
            $zdbh->prepare("UPDATE x_dh_apps SET ap_name_vc = :n, ap_desc_vc = :d, ap_image_vc = :i, ap_port_in = :p, ap_volumes_tx = :v, ap_env_tx = :e, ap_minmem_in = :mm,
                ap_mem_in = :m, ap_cpu_in = :c, ap_disk_in = :k, ap_web_vc = :w, ap_updated_in = :t WHERE ap_id_vc = :id")->execute($row + array(':id' => $id));
            self::Log('app', sprintf('Own app %s (%s) changed: %s', $a['name'], $id, $a['image']), 0, $by);
        } else {
            if (count($custom) >= 100) {
                return self::No('Up to 100 apps of your own.');
            }
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($a['name'])), '-');
            $slug = substr($slug !== '' ? $slug : 'app', 0, 30);
            $id = 'my-' . $slug;
            for ($i = 2; isset($custom[$id]) || dh_catalog::App($id) !== null; $i++) {
                $id = 'my-' . $slug . '-' . $i;
            }
            $zdbh->prepare("INSERT INTO x_dh_apps (ap_id_vc, ap_name_vc, ap_desc_vc, ap_image_vc, ap_port_in, ap_volumes_tx, ap_env_tx, ap_minmem_in, ap_mem_in, ap_cpu_in, ap_disk_in, ap_web_vc, ap_by_fk, ap_created_in, ap_updated_in)
                VALUES (:id, :n, :d, :i, :p, :v, :e, :mm, :m, :c, :k, :w, :b, :t, :t)")->execute($row + array(':id' => $id, ':b' => (int) $by));
            // offered at once (when the administrator chose a list instead of all of them)
            $on = self::Get('apps', '*');
            if ($on !== '*') {
                self::Set('apps', implode(',', array_merge(self::AppList($on), array($id))));
            }
            self::Log('app', sprintf('Own app %s (%s) added: %s', $a['name'], $id, $a['image']), 0, $by);
        }
        dh_catalog::$custom = null;
        return $id;
    }

    /**
     * Settings typed as lines of NAME=value (an app's, or its owner's), checked as the node checks them.
     *
     * @param string $text
     * @return array<string, string>|false (why in dh::$why)
     */
    static function ParseEnv($text)
    {
        $env = array();
        foreach (preg_split('/\R/', (string) $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $kv = explode('=', $line, 2);
            $k = trim($kv[0]);
            if (count($kv) !== 2 || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k)) {
                return self::No(sprintf('Each setting is one line NAME=value, the name in letters, digits and _ (this one is not: "%s").', preg_replace('/^(.{0,40}).*$/us', '$1', trim($line))));
            }
            if (isset($env[$k])) {
                return self::No(sprintf('The setting %s is there twice.', $k));
            }
            if (strlen($kv[1]) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $kv[1])) {
                return self::No(sprintf('The value of %s is too long or has control characters.', $k));
            }
            $env[$k] = $kv[1];
        }
        if (count($env) > 50) {
            return self::No('Up to 50 settings.');
        }
        return $env;
    }

    /**
     * The owner's own settings of a container.
     *
     * @param array $c
     * @return array<string, string>
     */
    static function OwnerEnv(array $c)
    {
        if (!isset($c['dc_env_tx']) || (string) $c['dc_env_tx'] === '') {
            return array();
        }
        $e = json_decode((string) ctrl_secret::Decrypt((string) $c['dc_env_tx']), true);
        return is_array($e) ? array_filter($e, 'is_string') : array();
    }

    /**
     * The settings an app taken back from a node ran with, where they differ from the panel's own
     * (dh::AdoptRead): kept encrypted, used as they are.
     *
     * @param array $c
     * @return array<string, string>
     */
    static function KeepEnv(array $c)
    {
        if (!isset($c['dc_keepenv_tx']) || (string) $c['dc_keepenv_tx'] === '') {
            return array();
        }
        $j = json_decode((string) ctrl_secret::Decrypt((string) $c['dc_keepenv_tx']), true);
        $out = array();
        foreach (is_array($j) ? $j : array() as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k) && is_string($v) && strlen($v) <= 4096 && !preg_match('/[\x00\r\n]/', $v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Change the owner's own settings of a container: kept (encrypted), and the node makes the app
     * again from the same version with them (if it does not start with them, the old one runs again).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @param int $id
     * @param string $text NAME=value lines
     * @return bool
     */
    static function SetOwnerEnv($acc, $id, $text)
    {
        global $zdbh;
        $c = self::Container($id);
        if (!$c || (int) $c['dc_acc_fk'] !== (int) $acc || in_array((string) $c['dc_state_vc'], array('deleting', 'creating', 'updating', 'applying'), true)) {
            return self::No('No such app, or it is busy right now (wait until it runs).');
        }
        $env = self::ParseEnv($text);
        if ($env === false) {
            return false;
        }
        $app = dh_catalog::App((string) $c['dc_app_vc']);
        if (count(array_merge($app ? $app['env'] : array(), $env)) > 50) {
            return self::No('Up to 50 settings, the app\'s own included.');
        }
        if ($env === self::OwnerEnv($c)) {
            return self::No('Nothing changed.');
        }
        $n = self::Node((int) $c['dc_node_fk']);
        $info = $n ? self::Info($n) : array('agent' => '');
        if ((string) $info['agent'] === '' || version_compare((string) $info['agent'], '106', '<')) {
            return self::No('The server this app runs on has an older agent that can not change an app\'s settings yet: the administrator updates it first (Nodes and apps, Manage, Update node).');
        }
        $enc = $env ? ctrl_secret::Encrypt(json_encode((object) $env)) : '';
        // (the settings before, put back if the app does not start with the new ones)
        $zdbh->prepare("UPDATE x_dh_containers SET dc_envprev_tx = dc_env_tx, dc_env_tx = :e, dc_state_vc = 'applying', dc_note_vc = '' WHERE dc_id_pk = :c")->execute(array(':e' => $enc, ':c' => (int) $id));
        $c['dc_env_tx'] = $enc;
        self::Queue((int) $c['dc_node_fk'], (int) $id, 'recreate', self::CreateData($c, ''));
        self::Log('settings', $c['dc_domain_vc'] . ': its own settings changed (' . count($env) . ')', (int) $acc, (int) $acc, (int) $id, (int) $c['dc_node_fk']);
        return true;
    }

    /**
     * Remove an app of the administrator's own (not while a container uses it).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by
     * @param string $id
     * @return bool
     */
    static function RemoveApp($by, $id)
    {
        global $zdbh;
        self::Ensure();
        if (!self::IsAdmin(self::Account($by))) {
            return self::No('Only the panel\'s administrators remove apps.');
        }
        $custom = dh_catalog::Custom();
        if (!is_string($id) || !isset($custom[$id])) {
            return self::No('No such app of your own.');
        }
        $users = self::AppUsers($id);
        if ($users > 0) {
            return self::No(sprintf('%d containers still use %s: they have to be deleted first.', $users, $custom[$id]['name']));
        }
        $zdbh->prepare("DELETE FROM x_dh_apps WHERE ap_id_vc = :id")->execute(array(':id' => $id));
        dh_catalog::$custom = null;
        self::Log('app', sprintf('Own app %s (%s) removed', $custom[$id]['name'], $id), 0, $by);
        return true;
    }

    /**
     * A container's own secret for {secret} in an app's settings: the same every time (so it
     * survives updates), different for every container and setting, known to nobody else.
     *
     * @param int $cid
     * @param string $key
     * @return string 40 characters.
     */
    static function AppSecret($cid, $key)
    {
        $k = self::Keys();
        return substr(hash_hmac('sha256', 'dh-app-secret|' . (int) $cid . '|' . $key, $k ? $k['sign_sk'] : ''), 0, 40);
    }

    /**
     * What an account's own containers take.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @return array{count:int, cpu:int, mem:int, disk:int}
     */
    static function Used($acc)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) c, COALESCE(SUM(dc_cpu_in),0) cpu, COALESCE(SUM(dc_mem_in),0) mem, COALESCE(SUM(dc_disk_in),0) disk FROM x_dh_containers WHERE dc_acc_fk = :a AND dc_deleted_in IS NULL");
        $q->execute(array(':a' => (int) $acc));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return array('count' => (int) $r['c'], 'cpu' => (int) $r['cpu'], 'mem' => (int) $r['mem'], 'disk' => (int) $r['disk']);
    }

    /**
     * What an account has given its direct clients (grants that still count).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @param int $except A client left out (its grant is being changed).
     * @return array{count:int, cpu:int, mem:int, disk:int}
     */
    static function Given($acc, $except = 0)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COALESCE(SUM(g.gr_count_in),0) c, COALESCE(SUM(g.gr_cpu_in),0) cpu, COALESCE(SUM(g.gr_mem_in),0) mem, COALESCE(SUM(g.gr_disk_in),0) disk
            FROM x_dh_grants g JOIN x_accounts a ON a.ac_id_pk = g.gr_acc_fk
            WHERE g.gr_by_fk = :a AND a.ac_reseller_fk = :a AND a.ac_deleted_ts IS NULL AND g.gr_acc_fk <> :x");
        $q->execute(array(':a' => (int) $acc, ':x' => (int) $except));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return array('count' => (int) $r['c'], 'cpu' => (int) $r['cpu'], 'mem' => (int) $r['mem'], 'disk' => (int) $r['disk']);
    }

    /**
     * What an account still has free (its grant less what it runs and what it gave), or null.
     *
     * @param int $acc
     * @return array|null count, cpu, mem, disk (PHP_INT_MAX for an administrator)
     */
    static function Free($acc)
    {
        $g = self::Grant($acc);
        if (!$g) {
            return null;
        }
        if ($g['unlimited']) {
            return array('count' => PHP_INT_MAX, 'cpu' => PHP_INT_MAX, 'mem' => PHP_INT_MAX, 'disk' => PHP_INT_MAX);
        }
        $u = self::Used($acc);
        $gv = self::Given($acc);
        $out = array();
        foreach (array('count', 'cpu', 'mem', 'disk') as $k) {
            $out[$k] = $g[$k] - $u[$k] - $gv[$k];
        }
        return $out;
    }

    /**
     * A seller's Docker plans (not deleted), by ID.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by
     * @return array<int, array>
     */
    static function Plans($by)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_dh_plans WHERE pl_by_fk = :b AND pl_deleted_in IS NULL ORDER BY pl_name_vc");
        $q->execute(array(':b' => (int) $by));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['pl_id_pk']] = $r;
        }
        return $out;
    }

    /**
     * One plan (not deleted), or null.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return array|null
     */
    static function Plan($id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_dh_plans WHERE pl_id_pk = :p AND pl_deleted_in IS NULL");
        $q->execute(array(':p' => (int) $id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    /**
     * The clients on a plan.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return array<int, int> account IDs
     */
    static function PlanClients($id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT g.gr_acc_fk FROM x_dh_grants g JOIN x_accounts a ON a.ac_id_pk = g.gr_acc_fk WHERE g.gr_plan_fk = :p AND a.ac_deleted_ts IS NULL ORDER BY g.gr_acc_fk");
        $q->execute(array(':p' => (int) $id));
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Make or change a Docker plan. When it changes, every client on it follows, as far as each can
     * (never below what a client uses, never past what the seller has free): the others keep their
     * amounts and are named in dh::$why.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by The seller.
     * @param int $id 0 for a new plan.
     * @param array $v name, count, cpu, mem, disk, apps
     * @return int|false The plan's ID.
     */
    static function SavePlan($by, $id, array $v)
    {
        global $zdbh;
        $mine = self::Grant($by);
        if (!$mine) {
            return self::No('You have no Docker Hosting share to make plans from.');
        }
        $name = isset($v['name']) ? trim((string) $v['name']) : '';
        if ($name === '' || mb_strlen($name) > 40 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F<>\p{Cf}]/u', $name)) {
            return self::No('Give the plan a name of up to 40 plain characters.');
        }
        foreach (array('count', 'cpu', 'mem', 'disk') as $k) {
            if (!isset($v[$k]) || !is_int($v[$k]) || $v[$k] <= 0) {
                return self::No('A plan needs containers, CPU, memory and disk above 0.');
            }
        }
        if ($v['count'] > self::MAX_COUNT) {
            return self::No(sprintf('At most %d containers per account.', self::MAX_COUNT));
        }
        if ($v['cpu'] < 10 || $v['mem'] < 128 || $v['disk'] < 256) {
            return self::No('At least 0.1 CPU, 128 MB of memory and 0.25 GB of disk.');
        }
        $apps = array_values(array_intersect(isset($v['apps']) && is_array($v['apps']) ? $v['apps'] : array(), $mine['apps']));
        if (!$apps) {
            return self::No('Choose at least one app the plan offers.');
        }
        foreach (self::Plans($by) as $pid => $p) {
            if ($pid !== (int) $id && mb_strtolower($p['pl_name_vc']) === mb_strtolower($name)) {
                return self::No('You have a plan with that name already.');
            }
        }
        $vals = array(':n' => $name, ':c' => $v['count'], ':u' => $v['cpu'], ':m' => $v['mem'], ':d' => $v['disk'], ':a' => implode(',', $apps));
        if ((int) $id > 0) {
            $p = self::Plan($id);
            if (!$p || (int) $p['pl_by_fk'] !== (int) $by) {
                return self::No('No such plan.');
            }
            $zdbh->prepare("UPDATE x_dh_plans SET pl_name_vc = :n, pl_count_in = :c, pl_cpu_in = :u, pl_mem_in = :m, pl_disk_in = :d, pl_apps_tx = :a WHERE pl_id_pk = :p")
                ->execute($vals + array(':p' => (int) $id));
        } else {
            $zdbh->prepare("INSERT INTO x_dh_plans (pl_by_fk, pl_name_vc, pl_count_in, pl_cpu_in, pl_mem_in, pl_disk_in, pl_apps_tx, pl_created_in) VALUES (:b, :n, :c, :u, :m, :d, :a, :t)")
                ->execute($vals + array(':b' => (int) $by, ':t' => time()));
            $id = (int) $zdbh->lastInsertId();
        }
        self::Log('plan', mb_substr(sprintf('Plan "%s": %d containers, %s CPU, %d MB memory, %d MB disk; apps %s', $name, $v['count'], self::Cpu($v['cpu']), $v['mem'], $v['disk'], implode(', ', $apps)), 0, 255), 0, (int) $by);
        // the clients on it follow
        $kept = array();
        foreach (self::PlanClients($id) as $child) {
            if (!self::SetGrant($by, $child, array('plan' => (int) $id))) {
                $a = self::Account($child);
                $kept[] = ($a ? $a['ac_user_vc'] : '#' . $child) . ' (' . self::$why . ')';
            }
        }
        self::$why = $kept ? 'These clients keep their amounts for now: ' . implode('; ', $kept) : '';
        return (int) $id;
    }

    /**
     * Remove a plan: its clients keep their amounts as their own.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by
     * @param int $id
     * @return bool
     */
    static function RemovePlan($by, $id)
    {
        global $zdbh;
        $p = self::Plan($id);
        if (!$p || (int) $p['pl_by_fk'] !== (int) $by) {
            return self::No('No such plan.');
        }
        $zdbh->prepare("UPDATE x_dh_plans SET pl_deleted_in = :t WHERE pl_id_pk = :p")->execute(array(':t' => time(), ':p' => (int) $id));
        $zdbh->prepare("UPDATE x_dh_grants SET gr_plan_fk = 0 WHERE gr_plan_fk = :p")->execute(array(':p' => (int) $id));
        self::Log('plan', mb_substr('Plan "' . $p['pl_name_vc'] . '" removed (its clients keep their amounts)', 0, 255), 0, (int) $by);
        return true;
    }

    /**
     * Give a direct client a share (or change it; all zero takes it back).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by The giver.
     * @param int $child
     * @param array $v count, cpu, mem, disk, apps (array of IDs)
     * @return bool False with dh::$why.
     */
    static function SetGrant($by, $child, array $v)
    {
        global $zdbh;
        $c = self::Account($child);
        if (!$c || (int) $c['ac_reseller_fk'] !== (int) $by || (int) $child === (int) $by) {
            return self::No('That account is not one of your clients.');
        }
        $mine = self::Grant($by);
        if (!$mine) {
            return self::No('You have no Docker Hosting share to give.');
        }
        // a plan: its amounts and apps (the client follows it when it changes)
        $plan = isset($v['plan']) ? (int) $v['plan'] : 0;
        if ($plan > 0) {
            $p = self::Plan($plan);
            if (!$p || (int) $p['pl_by_fk'] !== (int) $by) {
                return self::No('No such plan.');
            }
            $v = array('count' => (int) $p['pl_count_in'], 'cpu' => (int) $p['pl_cpu_in'], 'mem' => (int) $p['pl_mem_in'], 'disk' => (int) $p['pl_disk_in'],
                'apps' => self::AppList((string) $p['pl_apps_tx']));
        }
        foreach (array('count', 'cpu', 'mem', 'disk') as $k) {
            if (!isset($v[$k]) || !is_int($v[$k]) || $v[$k] < 0) {
                return self::No('The amounts must be whole numbers, 0 or more.');
            }
        }
        if ($v['count'] > self::MAX_COUNT) {
            return self::No(sprintf('At most %d containers per account.', self::MAX_COUNT));
        }
        $off = $v['count'] === 0;
        if ($off) {
            $v = array('count' => 0, 'cpu' => 0, 'mem' => 0, 'disk' => 0, 'apps' => array());
        }
        // not below what the client runs and has given on
        $u = self::Used($child);
        $gv = self::Given($child);
        foreach (array('count' => 'containers', 'cpu' => 'CPU', 'mem' => 'memory', 'disk' => 'disk') as $k => $n) {
            if ($v[$k] < $u[$k] + $gv[$k]) {
                return self::No($off ? 'The client still runs containers or has given a share to its own clients: those go first.'
                    : 'Not less than the client uses now (its containers and what it gave its own clients): check the ' . $n . '.');
            }
        }
        // not more than the giver has free (its grant to this client counts as free again)
        if (!$mine['unlimited']) {
            $mu = self::Used($by);
            $mg = self::Given($by, $child);
            foreach (array('count' => 'containers', 'cpu' => 'CPU', 'mem' => 'memory', 'disk' => 'disk') as $k => $n) {
                if ($v[$k] > $mine[$k] - $mu[$k] - $mg[$k]) {
                    return self::No('That is more than you have free: check the ' . $n . '.');
                }
            }
        }
        $apps = array_values(array_intersect(isset($v['apps']) && is_array($v['apps']) ? $v['apps'] : array(), $mine['apps']));
        if (!$off && !$apps) {
            return self::No('Choose at least one app the client may start.');
        }
        $zdbh->prepare("DELETE FROM x_dh_grants WHERE gr_acc_fk = :a")->execute(array(':a' => (int) $child));
        if (!$off) {
            $zdbh->prepare("INSERT INTO x_dh_grants (gr_acc_fk, gr_by_fk, gr_count_in, gr_cpu_in, gr_mem_in, gr_disk_in, gr_apps_tx, gr_updated_in, gr_plan_fk) VALUES (:a, :b, :n, :c, :m, :d, :p, :t, :l)")
                ->execute(array(':a' => (int) $child, ':b' => (int) $by, ':n' => $v['count'], ':c' => $v['cpu'], ':m' => $v['mem'], ':d' => $v['disk'], ':p' => implode(',', $apps), ':t' => time(), ':l' => $plan));
        }
        self::Log($off ? 'grant-off' : 'grant', mb_substr($off ? 'Share taken back from ' . $c['ac_user_vc']
            : sprintf('%s: %s%d containers, %s CPU, %d MB memory, %d MB disk; apps %s', $c['ac_user_vc'], $plan > 0 ? 'plan "' . $p['pl_name_vc'] . '", ' : '', $v['count'], self::Cpu($v['cpu']), $v['mem'], $v['disk'], implode(', ', $apps)), 0, 255), (int) $child, (int) $by);
        return true;
    }

    /**
     * CPU in hundredths as text (1.5).
     *
     * @param int $c
     * @return string
     */
    static function Cpu($c)
    {
        return rtrim(rtrim(sprintf('%d.%02d', intdiv((int) $c, 100), (int) $c % 100), '0'), '.');
    }

    /**
     * CPU typed (1.5, 0,25) in hundredths, or false.
     *
     * @param string $t
     * @return int|false
     */
    static function ParseCpu($t)
    {
        $t = str_replace(',', '.', trim((string) $t));
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $t)) {
            return false;
        }
        $p = explode('.', $t . '.');
        return (int) $p[0] * 100 + (int) str_pad($p[1], 2, '0');
    }

    // ----- nodes -----

    /**
     * The nodes (not deleted).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, array>
     */
    static function Nodes()
    {
        global $zdbh;
        self::Ensure();
        $out = array();
        foreach ($zdbh->query("SELECT * FROM x_dh_nodes WHERE nd_deleted_in IS NULL ORDER BY nd_name_vc")->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $out[(int) $n['nd_id_pk']] = $n;
        }
        return $out;
    }

    /**
     * One node (not deleted), or null.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return array|null
     */
    static function Node($id)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_dh_nodes WHERE nd_id_pk = :n AND nd_deleted_in IS NULL");
        $q->execute(array(':n' => (int) $id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    /**
     * Whether a node called lately.
     *
     * @param array $n
     * @return bool
     */
    static function Online(array $n)
    {
        return (string) $n['nd_signpk_vc'] !== '' && (int) $n['nd_seen_in'] >= time() - self::ONLINE;
    }

    /**
     * A node's own report (cpus, mem_mb, disk_total_mb, disk_free_mb, podman, os, agent...).
     *
     * @param array $n
     * @return array
     */
    static function Info(array $n)
    {
        $i = json_decode((string) $n['nd_info_tx'], true);
        return self::CleanInfo(is_array($i) ? $i : array());
    }

    /**
     * The IPv4 address customers' DNS records point to ('' when unknown).
     *
     * @param array $n
     * @return string
     */
    static function PublicIp(array $n)
    {
        $ip = (string) $n['nd_publicip_vc'] !== '' ? (string) $n['nd_publicip_vc'] : (string) $n['nd_ip_vc'];
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : '';
    }

    /**
     * Add a node: a one-time code for its installer (shown once, kept only as a hash).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name
     * @param int $by
     * @return string|false The code ("<node ID>.<secret>"), or false with dh::$why.
     */
    static function AddNode($name, $by)
    {
        global $zdbh;
        if (!self::Keys()) {
            return self::No('This server has no secret key to keep the panel\'s node keys with (cnf/security.php): Docker Hosting can not link nodes.');
        }
        $secret = bin2hex(random_bytes(16));
        $zdbh->prepare("INSERT INTO x_dh_nodes (nd_name_vc, nd_code_vc, nd_codeexp_in, nd_created_in) VALUES (:n, :c, :e, :t)")
            ->execute(array(':n' => $name, ':c' => hash('sha256', $secret), ':e' => time() + self::CODE_LIFE, ':t' => time()));
        $id = (int) $zdbh->lastInsertId();
        self::Log('node-add', 'Node "' . $name . '" added (waiting for its installer)', 0, $by, 0, $id);
        return $id . '.' . $secret;
    }

    /**
     * Add a node with the node key its installer showed: the node is linked the next time it calls.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name
     * @param string $key
     * @param int $by
     * @return int|false The node's ID, or false with dh::$why.
     */
    static function AddNodeKey($name, $key, $by)
    {
        global $zdbh;
        if (!self::Keys()) {
            return self::No('This server has no secret key to keep the panel\'s node keys with (cnf/security.php): Docker Hosting can not link nodes.');
        }
        $secret = self::KeySecret($key);
        if ($secret === false) {
            return self::No('That is not a node key: it looks like SN1-ABCD-EFGH-JKLM-NPQR-STUV-WXYZ (the installer showed it at the end; it is also in /root/sentora-docker-node-key.txt on the node).');
        }
        $claim = hash('sha256', $secret);
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_nodes WHERE nd_claim_vc = :c AND nd_deleted_in IS NULL");
        $q->execute(array(':c' => $claim));
        if ((int) $q->fetchColumn() > 0) {
            return self::No('That node key was added already.');
        }
        $zdbh->prepare("INSERT INTO x_dh_nodes (nd_name_vc, nd_claim_vc, nd_created_in) VALUES (:n, :c, :t)")->execute(array(':n' => $name, ':c' => $claim, ':t' => time()));
        $id = (int) $zdbh->lastInsertId();
        self::Log('node-add', 'Node "' . $name . '" added with its node key (it links the next time it calls)', 0, $by, 0, $id);
        return $id;
    }

    /**
     * A new code for a node not linked yet (the old one stops working).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @param int $by
     * @return string|false
     */
    static function NewCode($id, $by)
    {
        global $zdbh;
        $n = self::Node($id);
        if (!$n || (string) $n['nd_signpk_vc'] !== '') {
            return self::No('That node is linked already.');
        }
        $secret = bin2hex(random_bytes(16));
        $zdbh->prepare("UPDATE x_dh_nodes SET nd_code_vc = :c, nd_codeexp_in = :e WHERE nd_id_pk = :n")
            ->execute(array(':c' => hash('sha256', $secret), ':e' => time() + self::CODE_LIFE, ':n' => (int) $id));
        self::Log('node-code', 'New install code for node "' . $n['nd_name_vc'] . '"', 0, $by, 0, (int) $id);
        return (int) $id . '.' . $secret;
    }

    /**
     * The command that installs the node (run as root on the new server).
     *
     * @param string $code
     * @return string
     */
    static function InstallCommand($code)
    {
        return 'curl -fsSL ' . self::NODE_INSTALLER . ' -o sentora2027-docker-node && sudo bash sentora2027-docker-node --panel ' . escapeshellarg(self::PanelUrl())
            . ' --key ' . self::PanelKey() . ' --code ' . $code . (self::SelfSigned(self::PanelUrl()) ? ' --no-tls-check' : '');
    }

    /**
     * Whether the panel's address answers with a certificate nobody vouches for (self-signed, or
     * not for that name, as with an IP address). Then the node can not check it, and is told to
     * rely on the panel's key alone (which is in its install command): safe, every message is signed.
     *
     * @param string $url
     * @return bool
     */
    static function SelfSigned($url)
    {
        if (!function_exists('curl_init') || strpos($url, 'https://') !== 0) {
            return false;
        }
        $c = curl_init($url . '/');
        curl_setopt_array($c, array(CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false));
        curl_exec($c);
        $errno = curl_errno($c);
        // 51 the name does not match, 60 nobody vouches for it (self-signed), 58/77/83 certificate problems
        return in_array($errno, array(51, 58, 60, 77, 83), true);
    }

    /**
     * How many apps the last RemoveNode() deleted with the node.
     *
     * @var int
     */
    static $removedApps = 0;

    /**
     * Remove a node (only without containers; its keys stop working at once).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @param int $by
     * @return bool
     */
    static function RemoveNode($id, $by, $confirm = null)
    {
        global $zdbh;
        $n = self::Node($id);
        if (!$n) {
            return self::No('No such node.');
        }
        $q = $zdbh->prepare("SELECT * FROM x_dh_containers WHERE dc_node_fk = :n AND dc_deleted_in IS NULL");
        $q->execute(array(':n' => (int) $id));
        $apps = $q->fetchAll(PDO::FETCH_ASSOC);
        // (133) apps found on it that the panel does not know go too (the node is purged)
        $f = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_found WHERE fd_node_fk = :n");
        $f->execute(array(':n' => (int) $id));
        $found = (int) $f->fetchColumn();
        if (!$apps && $found > 0 && ($confirm === null || trim((string) $confirm) !== (string) $n['nd_name_vc'])) {
            self::$why = sprintf('Node "%s" has %d app(s) found on it that the panel does not know; they are deleted with it. To go ahead, type the node\'s name exactly when Remove asks for it.', $n['nd_name_vc'], $found);
            return false;
        }
        if ($apps) {
            // (132, owner: removing a node removes everything on it, once its name is typed)
            if ($confirm === null || trim((string) $confirm) !== (string) $n['nd_name_vc']) {
                self::$why = sprintf('Node "%s" still has %d app(s); they are deleted with it, with their data. To go ahead, type the node\'s name exactly when Remove asks for it.', $n['nd_name_vc'], count($apps));
                return false;
            }
            $online = self::Online($n);
            self::$removedApps = count($apps);
            foreach ($apps as $c) {
                $cid = (int) $c['dc_id_pk'];
                $zdbh->prepare("DELETE FROM x_dh_jobs WHERE jb_ct_fk = :c AND jb_state_vc = 'queued'")->execute(array(':c' => $cid));
                if ($online) {
                    // the node deletes it (container, data, its site), as Delete on the app's row does
                    $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'deleting', dc_want_vc = 'deleted' WHERE dc_id_pk = :c")->execute(array(':c' => $cid));
                    self::Queue((int) $id, $cid, 'delete', self::Who($c) + array('domain' => (string) $c['dc_domain_vc']));
                } else {
                    // nothing can reach the node: gone from the panel now (should the node come back, it deletes them: they are this node's apps the panel deleted)
                    self::DnsRemove($c);
                    $zdbh->prepare("UPDATE x_dh_containers SET dc_deleted_in = :t, dc_state_vc = 'deleted', dc_setup_tx = NULL WHERE dc_id_pk = :c")->execute(array(':t' => time(), ':c' => $cid));
                }
                self::Log('deleted', sprintf('Deleted %s with node "%s"', $c['dc_domain_vc'], $n['nd_name_vc']), (int) $c['dc_acc_fk'], (int) $by, $cid, (int) $id);
                if (class_exists('ctrl_notices') && method_exists('ctrl_notices', 'ToUser')) {
                    ctrl_notices::ToUser((int) $c['dc_acc_fk'], sprintf("Your app at **%s** was removed: the server it ran on was taken out of service by the administrator, and its data went with it.\n\nYou can start it again under [Docker Hosting](./?module=docker_hosting).", $c['dc_domain_vc']),
                        array('type' => 'warning', 'title' => 'Docker Hosting: an app was removed', 'key' => 'docker-hosting-removed-' . $cid, 'source' => 'docker_hosting', 'end' => time() + 14 * 86400));
                }
            }
        }
        if (self::Online($n) && (int) $n['nd_removing_in'] === 0) {
            // the node removes its agent and keys itself; the panel forgets it once it says so
            $zdbh->prepare("UPDATE x_dh_nodes SET nd_removing_in = 1, nd_accepting_in = 0 WHERE nd_id_pk = :n")->execute(array(':n' => (int) $id));
            self::Queue((int) $id, 0, 'uninstall', array());
            self::Log('node-remove', 'Node "' . $n['nd_name_vc'] . '" asked to remove its agent', 0, $by, 0, (int) $id);
            self::$why = 'online';
            return true;
        }
        self::Forget((int) $id);
        self::Log('node-remove', 'Node "' . $n['nd_name_vc'] . '" removed', 0, $by, 0, (int) $id);
        self::$why = 'offline';
        return true;
    }

    /**
     * Forget a node: its keys stop working at once.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return void
     */
    static function Forget($id)
    {
        global $zdbh;
        // (133) its public keys are kept aside: should the removed node ever call again, it is told one thing
        // only, to uninstall itself with everything on it (see Receive); it can do nothing else here
        $zdbh->prepare("UPDATE x_dh_nodes SET nd_gonesign_vc = nd_signpk_vc, nd_gonebox_vc = nd_boxpk_vc WHERE nd_id_pk = :n AND nd_signpk_vc <> ''")->execute(array(':n' => (int) $id));
        $zdbh->prepare("UPDATE x_dh_nodes SET nd_deleted_in = :t, nd_signpk_vc = '', nd_boxpk_vc = '', nd_code_vc = '', nd_claim_vc = '' WHERE nd_id_pk = :n")->execute(array(':t' => time(), ':n' => (int) $id));
        $zdbh->prepare("DELETE FROM x_dh_jobs WHERE jb_node_fk = :n AND jb_state_vc IN ('queued','sent')")->execute(array(':n' => (int) $id));
        self::Queue((int) $id, 0, 'uninstall', array('purge' => true));
    }

    /**
     * The node's own jobs (updates, restart...) that are still waiting or running: action => queued or sent.
     *
     * @param int $id the node
     * @return array
     */
    static function NodeJobs($id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT jb_action_vc, jb_state_vc FROM x_dh_jobs WHERE jb_node_fk = :n AND jb_ct_fk = 0 AND jb_state_vc IN ('queued','sent') ORDER BY jb_id_pk");
        $q->execute(array(':n' => (int) $id));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['jb_action_vc']] = (string) $r['jb_state_vc'];
        }
        return $out;
    }

    /**
     * The accounts whose apps may reach the local network (private addresses), as the panel keeps them.
     *
     * @return array<int, int>
     */
    static function LanAccounts()
    {
        $out = array();
        foreach (explode(',', (string) self::Get('lan_accounts', '')) as $a) {
            if (ctype_digit($a) && (int) $a > 0) {
                $out[] = (int) $a;
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /**
     * The same as the nodes' Linux users (sdh<account>), sorted.
     *
     * @return array<int, string>
     */
    static function LanUsers()
    {
        $u = array();
        foreach (self::LanAccounts() as $a) {
            $u[] = 'sdh' . $a;
        }
        sort($u, SORT_STRING);
        return $u;
    }

    /**
     * Let an account's apps reach the local network, or not (administrators only; off is the safer way).
     * Every linked node gets the new list.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by
     * @param int $acc
     * @param bool $on
     * @return bool
     */
    static function SetLan($by, $acc, $on)
    {
        global $zdbh;
        if (!self::IsAdmin(self::Account($by))) {
            return self::No('Only the panel\'s administrators decide this.');
        }
        $a = self::Account($acc);
        if (!$a) {
            return self::No('No such account.');
        }
        $list = self::LanAccounts();
        $list = $on ? array_values(array_unique(array_merge($list, array((int) $acc)))) : array_values(array_diff($list, array((int) $acc)));
        sort($list);
        self::Set('lan_accounts', implode(',', $list));
        $users = self::LanUsers();
        foreach (self::Nodes() as $nid => $n) {
            $agent = (string) self::Info($n)['agent'];
            // (an older agent can not do this: it gets the list when it is updated, from its first report)
            if ((string) $n['nd_signpk_vc'] === '' || $agent === '' || version_compare($agent, '108', '<')) {
                continue;
            }
            $zdbh->prepare("DELETE FROM x_dh_jobs WHERE jb_node_fk = :n AND jb_action_vc = 'network' AND jb_state_vc = 'queued'")->execute(array(':n' => (int) $nid));
            self::Queue((int) $nid, 0, 'network', array('lan_users' => $users));
        }
        self::Log('network', sprintf('%s\'s apps %s reach the local network', $a['ac_user_vc'], $on ? 'may' : 'may not'), (int) $acc, (int) $by);
        return true;
    }

    /**
     * Something the administrator asks of a node: node-update, sys-security, sys-all, reboot,
     * check-updates, auto-security on/off.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @param string $what
     * @param int $by
     * @return bool
     */
    static function NodeAct($id, $what, $by)
    {
        global $zdbh;
        $n = self::Node($id);
        if (!$n || (string) $n['nd_signpk_vc'] === '') {
            return self::No('No such node (or it is not linked yet).');
        }
        $jobs = array('node-update' => array('node-update', array()), 'sys-security' => array('sys-update', array('security' => true)),
            'sys-all' => array('sys-update', array('security' => false)), 'reboot' => array('reboot', array()), 'check-updates' => array('check-updates', array()));
        if ($what === 'autosec-on' || $what === 'autosec-off') {
            $zdbh->prepare("UPDATE x_dh_nodes SET nd_autosec_in = :a WHERE nd_id_pk = :n")->execute(array(':a' => $what === 'autosec-on' ? 1 : 0, ':n' => (int) $id));
        } elseif ($what === 'autoagent-on' || $what === 'autoagent-off') {
            $zdbh->prepare("UPDATE x_dh_nodes SET nd_autoagent_in = :a WHERE nd_id_pk = :n")->execute(array(':a' => $what === 'autoagent-on' ? 1 : 0, ':n' => (int) $id));
        } elseif (isset($jobs[$what])) {
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_node_fk = :n AND jb_ct_fk = 0 AND jb_action_vc = :a AND jb_state_vc IN ('queued','sent')");
            $q->execute(array(':n' => (int) $id, ':a' => $jobs[$what][0]));
            if ((int) $q->fetchColumn() > 0) {
                return self::No('That was asked already: the node does it the next time it calls.');
            }
            self::Queue((int) $id, 0, $jobs[$what][0], $jobs[$what][1]);
        } else {
            return self::No('Unknown action.');
        }
        self::Log('node-' . substr($what, 0, 15), 'Node "' . $n['nd_name_vc'] . '": ' . $what, 0, $by, 0, (int) $id);
        return true;
    }

    /**
     * A node's settings: Let's Encrypt's email, or Caddy's own certificates (for a test network).
     *
     * @param array $n
     * @return array{email:string, local_certs:int}
     */
    static function NodeSettings(array $n)
    {
        // what the node reports it uses (set when it was installed, or from here)
        $i = self::Info($n);
        if ((int) $n['nd_seen_in'] > 0 && (string) $n['nd_info_tx'] !== '' && strpos((string) $n['nd_info_tx'], 'cfg_local_certs') !== false) {
            return array('email' => $i['cfg_email'], 'local_certs' => $i['cfg_local_certs']);
        }
        $j = json_decode((string) $n['nd_settings_tx'], true);
        return array('email' => is_array($j) && isset($j['email']) ? (string) $j['email'] : '', 'local_certs' => is_array($j) && !empty($j['local_certs']) ? 1 : 0);
    }

    /**
     * Change a node's settings and send them to it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @param string $email
     * @param bool $local
     * @param int $by
     * @return bool
     */
    static function SetNodeSettings($id, $email, $local, $by)
    {
        global $zdbh;
        $n = self::Node($id);
        if (!$n || (string) $n['nd_signpk_vc'] === '') {
            return self::No('No such node (or it is not linked yet).');
        }
        if ($email !== '' && !preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $email)) {
            return self::No('That is not an email address.');
        }
        $v = array('email' => $email, 'local_certs' => $local ? 1 : 0);
        $zdbh->prepare("UPDATE x_dh_nodes SET nd_settings_tx = :s WHERE nd_id_pk = :n")->execute(array(':s' => json_encode($v), ':n' => (int) $id));
        self::Queue((int) $id, 0, 'settings', $v);
        self::Log('node-settings', 'Node "' . $n['nd_name_vc'] . '": ' . ($local ? 'Caddy\'s own certificates' : 'Let\'s Encrypt') . ($email !== '' ? ', ' . $email : ''), 0, $by, 0, (int) $id);
        return true;
    }

    /**
     * What needs a look on a node, in a few words each (for the page's Needs a look and the panel
     * notice). Without figures (disk free, how many updates) the words stay the same while the
     * problem lasts, so a notice does not come back each time a number moves.
     *
     * @param array $n The node's row.
     * @param bool $figures With the figures.
     * @return array<string, string> key (as Warnings()) => English words
     */
    static function Words(array $n, $figures = true)
    {
        $i = self::Info($n);
        $out = array();
        foreach (self::Warnings($n) as $k => $w) {
            if ($k === 'disk') {
                $out[$k] = $figures ? sprintf('almost out of disk (%s GB free)', round((int) $i['disk_free_mb'] / 1024, 1)) : 'almost out of disk';
            } elseif ($k === 'security') {
                $s = (int) $i['sys_security'];
                $out[$k] = !$figures ? 'security updates waiting' : ($s === 1 ? '1 security update waiting' : sprintf('%d security updates waiting', $s));
            } elseif ($k === 'reboot') {
                $out[$k] = 'needs a restart to finish its updates';
            } elseif ($k === 'agent') {
                $out[$k] = sprintf('runs agent %s (%s is out)', $i['agent'], self::Get('latest_agent'));
            } elseif ($k === 'found') {
                $out[$k] = 'apps the panel does not know are still running on it: give each back to its owner or delete it, under Found on the nodes';
            } else {
                // (the rest says more than a few words can: as it is, without the node's name in front)
                $out[$k] = rtrim(preg_replace('/^Node "[^"]*" /', '', $w), '.');
            }
        }
        return $out;
    }

    /**
     * What looks wrong on a node (shown on the page, mailed to the administrators).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $n
     * @return array<string, string> key => English text
     */
    static function Warnings(array $n)
    {
        global $zdbh;
        $w = array();
        if ((string) $n['nd_signpk_vc'] === '') {
            return $w;
        }
        $name = (string) $n['nd_name_vc'];
        $i = self::Info($n);
        if ((int) $n['nd_seen_in'] < time() - 600) {
            $w['offline'] = sprintf('Node "%s" has not called the panel since %s: it may be off, or cut off from the panel.', $name, date('Y-m-d H:i', (int) $n['nd_seen_in']));
        }
        if ((int) $i['disk_total_mb'] > 0 && ((int) $i['disk_free_mb'] < 2048 || (int) $i['disk_free_mb'] * 10 < (int) $i['disk_total_mb'])) {
            $w['disk'] = sprintf('Node "%s" is almost out of disk: %d MB free.', $name, (int) $i['disk_free_mb']);
        }
        if ((int) $i['sys_security'] > 0) {
            $w['security'] = sprintf('Node "%s" has %d security updates waiting.', $name, (int) $i['sys_security']);
        }
        if ((int) $i['sys_reboot'] === 1) {
            $w['reboot'] = sprintf('Node "%s" needs a restart to finish its updates.', $name);
        }
        $latest = self::Get('latest_agent');
        if ($latest !== '' && (string) $i['agent'] !== '' && version_compare((string) $i['agent'], $latest, '<')) {
            $w['agent'] = sprintf('Node "%s" runs agent %s; %s is out (Update now, in the node\'s Manage).', $name, $i['agent'], $latest);
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_found WHERE fd_node_fk = :n");
        $q->execute(array(':n' => (int) $n['nd_id_pk']));
        if (($k = (int) $q->fetchColumn()) > 0) {
            $w['found'] = sprintf('Node "%s" has %d app(s) the panel does not know (kept, still running): give each back to its owner or delete it, under Found on the nodes.', $name, $k);
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_containers WHERE dc_node_fk = :n AND dc_deleted_in IS NULL AND dc_state_vc = 'missing'");
        $q->execute(array(':n' => (int) $n['nd_id_pk']));
        if (($k = (int) $q->fetchColumn()) > 0) {
            $w['missing'] = sprintf('Node "%s" no longer has %d of its apps (it was set up again, or restored): their owners can make them again (without the old data) or delete them.', $name, $k);
        }
        if ((int) $n['nd_seen_in'] < time() - 86400) {
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_containers WHERE dc_node_fk = :n AND dc_deleted_in IS NULL");
            $q->execute(array(':n' => (int) $n['nd_id_pk']));
            if (($k = (int) $q->fetchColumn()) > 0) {
                $w['gone'] = sprintf('Node "%s" has been away for over a day with %d app(s): if it is gone for good, start them on the other nodes (Manage, next to the node).', $name, $k);
            }
        }
        $q = $zdbh->prepare("SELECT dc_domain_vc, dc_state_vc FROM x_dh_containers WHERE dc_node_fk = :n AND dc_deleted_in IS NULL AND dc_badsince_in > 0 AND dc_badsince_in < :t");
        $q->execute(array(':n' => (int) $n['nd_id_pk'], ':t' => time() - 600));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $w['app-' . $c['dc_domain_vc']] = sprintf('The app at %s should run but has been "%s" for over 10 minutes.', $c['dc_domain_vc'], $c['dc_state_vc']);
        }
        return $w;
    }

    /**
     * What is placed on a node: cpu, mem, disk, and its ports in use.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @return array{cpu:int, mem:int, disk:int, count:int, ports:array<int,int>}
     */
    static function Placed($node)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT dc_cpu_in, dc_mem_in, dc_disk_in, dc_port_in FROM x_dh_containers WHERE dc_node_fk = :n AND dc_deleted_in IS NULL");
        $q->execute(array(':n' => (int) $node));
        $o = array('cpu' => 0, 'mem' => 0, 'disk' => 0, 'count' => 0, 'ports' => array());
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o['cpu'] += (int) $r['dc_cpu_in'];
            $o['mem'] += (int) $r['dc_mem_in'];
            $o['disk'] += (int) $r['dc_disk_in'];
            $o['count']++;
            $o['ports'][] = (int) $r['dc_port_in'];
        }
        return $o;
    }

    /**
     * Room left on a node (what may still be placed there).
     *
     * @param array $n
     * @return array{cpu:int, mem:int, disk:int}
     */
    static function Room(array $n)
    {
        $i = self::Info($n);
        $p = self::Placed((int) $n['nd_id_pk']);
        return array(
            'cpu' => (int) (isset($i['cpus']) ? $i['cpus'] : 0) * 100 * self::CPU_OVER - $p['cpu'],
            'mem' => intdiv((int) (isset($i['mem_mb']) ? $i['mem_mb'] : 0) * self::MEM_SHARE, 100) - $p['mem'],
            'disk' => intdiv((int) (isset($i['disk_total_mb']) ? $i['disk_total_mb'] : 0) * self::DISK_SHARE, 100) - $p['disk'],
        );
    }

    /**
     * The node a new container goes on: online, taking containers, with room; the one with the most memory free.
     *
     * @param int $cpu
     * @param int $mem
     * @param int $disk
     * @return int 0 when none.
     */
    static function Pick($cpu, $mem, $disk)
    {
        $best = 0;
        $bestMem = -1;
        foreach (self::Nodes() as $id => $n) {
            if (!self::Online($n) || (int) $n['nd_accepting_in'] !== 1) {
                continue;
            }
            $r = self::Room($n);
            if ($r['cpu'] >= $cpu && $r['mem'] >= $mem && $r['disk'] >= $disk && $r['mem'] > $bestMem) {
                $best = $id;
                $bestMem = $r['mem'];
            }
        }
        return $best;
    }

    /**
     * A free port on a node, or 0.
     *
     * @param int $node
     * @return int
     */
    static function FreePort($node)
    {
        $used = array_flip(array_merge(self::Placed($node)['ports'], dh_backup::Reserved($node)));
        for ($p = self::PORT_FIRST; $p <= self::PORT_LAST; $p++) {
            if (!isset($used[$p])) {
                return $p;
            }
        }
        return 0;
    }

    // ----- containers -----

    /**
     * An account's containers (not deleted), or one container of any account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @return array<int, array>
     */
    static function Containers($acc)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_dh_containers WHERE dc_acc_fk = :a AND dc_deleted_in IS NULL ORDER BY dc_id_pk");
        $q->execute(array(':a' => (int) $acc));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['dc_id_pk']] = $r;
        }
        return $out;
    }

    /**
     * One container (not deleted), or null.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return array|null
     */
    static function Container($id)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c AND dc_deleted_in IS NULL");
        $q->execute(array(':c' => (int) $id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    /**
     * An account's own domains a container address can go under (with DNS records already, so the zone exists).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @return array<int, array{name:string, dns:bool}> By x_vhosts ID.
     */
    static function Domains($acc)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT v.vh_id_pk, v.vh_name_vc, (SELECT COUNT(*) FROM x_dns d WHERE d.dn_vhost_fk = v.vh_id_pk AND d.dn_acc_fk = v.vh_acc_fk AND d.dn_deleted_ts IS NULL) AS records
            FROM x_vhosts v WHERE v.vh_acc_fk = :a AND v.vh_deleted_ts IS NULL AND v.vh_type_in != 2 ORDER BY v.vh_name_vc");
        $q->execute(array(':a' => (int) $acc));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['vh_id_pk']] = array('name' => strtolower((string) $r['vh_name_vc']), 'dns' => (int) $r['records'] > 0);
        }
        return $out;
    }

    /**
     * Check a container's address: a name under one of the account's own domains, free in DNS,
     * not a website on this panel, not another container's (DNS Manager's checks and more).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @param int $vhost
     * @param string $label
     * @return array|false [full name, domain row, label] or false with dh::$why.
     */
    static function CheckAddress($acc, $vhost, $label)
    {
        global $zdbh;
        $doms = self::Domains($acc);
        if (!isset($doms[(int) $vhost])) {
            return self::No('Choose one of your own domains.');
        }
        $label = strtolower(trim((string) $label));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
            return self::No('The name before the domain may have letters, digits and hyphens (not at its start or end), up to 63 of them.');
        }
        $full = $label . '.' . $doms[(int) $vhost]['name'];
        if (strlen($full) > 253) {
            return self::No('That address is too long.');
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk = :v AND LOWER(dn_host_vc) = :h AND dn_type_vc IN ('A','AAAA','CNAME') AND dn_deleted_ts IS NULL");
        $q->execute(array(':v' => (int) $vhost, ':h' => $label));
        if ((int) $q->fetchColumn() > 0) {
            return self::No('That name already has a DNS record (in DNS Manager): choose another, or delete that record first.');
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE LOWER(vh_name_vc) = :n AND vh_deleted_ts IS NULL");
        $q->execute(array(':n' => $full));
        if ((int) $q->fetchColumn() > 0) {
            return self::No('That address is a website on this panel already.');
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_containers WHERE dc_domain_vc = :n AND dc_deleted_in IS NULL");
        $q->execute(array(':n' => $full));
        if ((int) $q->fetchColumn() > 0) {
            return self::No('Another container has that address.');
        }
        return array($full, $doms[(int) $vhost], $label);
    }

    /**
     * Start a new container for an account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @param int $by Who asked (the account itself).
     * @param array $v app, label, cpu, mem, disk, vhost, name (the part before the domain), dns (bool: add the record)
     * @return int|false Its ID, or false with dh::$why.
     */
    static function Deploy($acc, $by, array $v)
    {
        global $zdbh;
        if (!self::Keys()) {
            return self::No('This server has no secret key (cnf/security.php): Docker Hosting can not work.');
        }
        $g = self::Grant($acc);
        if (!$g) {
            return self::No('Your account has no Docker Hosting share: ask your provider.');
        }
        $app = dh_catalog::App(isset($v['app']) ? $v['app'] : '');
        if (!$app || !in_array($v['app'], $g['apps'], true)) {
            return self::No('That app is not one you may start.');
        }
        foreach (array('cpu', 'mem', 'disk') as $k) {
            if (!isset($v[$k]) || !is_int($v[$k]) || $v[$k] <= 0) {
                return self::No('Give the CPU, memory and disk as numbers above 0.');
            }
        }
        if ($v['mem'] < $app['minmem']) {
            return self::No(sprintf('%s needs at least %d MB of memory.', $app['name'], $app['minmem']));
        }
        if ($v['cpu'] < 10 || $v['disk'] < 256) {
            return self::No('At least 0.1 CPU and 256 MB of disk.');
        }
        $f = self::Free($acc);
        foreach (array('count' => 1, 'cpu' => $v['cpu'], 'mem' => $v['mem'], 'disk' => $v['disk']) as $k => $need) {
            if ($f[$k] < $need) {
                return self::No($k === 'count' ? 'You have no containers left in your share.' : 'That is more than your share has free: check the ' . array('cpu' => 'CPU', 'mem' => 'memory', 'disk' => 'disk')[$k] . '.');
            }
        }
        if (count(self::Containers($acc)) >= self::MAX_COUNT) {
            return self::No('Too many containers on this account.');
        }
        $label = isset($v['label']) ? trim((string) $v['label']) : '';
        if ($label !== '' && (mb_strlen($label) > 60 || preg_match('/[\x00-\x1F\x7F<>\p{Cf}]/u', $label) || !mb_check_encoding($label, 'UTF-8'))) {
            return self::No('The name may be up to 60 plain characters.');
        }
        $addr = self::CheckAddress($acc, isset($v['vhost']) ? (int) $v['vhost'] : 0, isset($v['name']) ? $v['name'] : '');
        if (!$addr) {
            return false;
        }
        $want = isset($v['node']) ? (int) $v['node'] : 0;
        if ($want > 0) {
            // (an administrator's own choice of node; everyone else's app goes where Pick puts it)
            if (!self::IsAdmin(self::Account($by))) {
                return self::No('Only an administrator chooses the node.');
            }
            $wn = self::Node($want);
            if (!$wn || !self::Online($wn)) {
                return self::No('That node is not online: pick another, or Automatic.');
            }
            $r = self::Room($wn);
            if ($r['cpu'] < $v['cpu'] || $r['mem'] < $v['mem'] || $r['disk'] < $v['disk']) {
                return self::No(sprintf('Node %s has not got room for that (free: %s CPU, %d MB memory, %s GB disk).', $wn['nd_name_vc'], self::Cpu(max(0, $r['cpu'])), max(0, $r['mem']), round(max(0, $r['disk']) / 1024, 1)));
            }
            $node = $want;
        } else {
            $node = self::Pick($v['cpu'], $v['mem'], $v['disk']);
        }
        if (!$node) {
            return self::No('No node has room for that right now (or none is online): try smaller limits, or ask your provider.');
        }
        $port = self::FreePort($node);
        if (!$port) {
            return self::No('The node has no port free.');
        }
        $n = self::Node($node);
        $ip = self::PublicIp($n);
        $dnsOn = !empty($v['dns']);
        if ($dnsOn && ($ip === '' || !$addr[1]['dns'])) {
            return self::No($ip === '' ? 'The node\'s public address is not known yet: add the DNS record yourself later.' : 'That domain has no DNS records yet: set its DNS up in DNS Manager first, or add the record yourself.');
        }
        $setup = self::Password();
        $zdbh->prepare("INSERT INTO x_dh_containers (dc_acc_fk, dc_node_fk, dc_app_vc, dc_label_vc, dc_cpu_in, dc_mem_in, dc_disk_in, dc_port_in, dc_domain_vc, dc_vhost_fk,
            dc_state_vc, dc_want_vc, dc_public_in, dc_setup_tx, dc_created_in) VALUES (:a, :n, :p, :l, :c, :m, :d, :o, :h, :v, 'creating', 'running', 0, :s, :t)")
            ->execute(array(':a' => (int) $acc, ':n' => $node, ':p' => $v['app'], ':l' => $label, ':c' => $v['cpu'], ':m' => $v['mem'], ':d' => $v['disk'], ':o' => $port,
                ':h' => $addr[0], ':v' => (int) $v['vhost'], ':s' => ctrl_secret::Encrypt($setup), ':t' => time()));
        $id = (int) $zdbh->lastInsertId();
        if ($dnsOn) {
            $dn = self::DnsAdd($acc, (int) $v['vhost'], $addr[1]['name'], $addr[2], $ip);
            $zdbh->prepare("UPDATE x_dh_containers SET dc_dns_fk = :d WHERE dc_id_pk = :c")->execute(array(':d' => $dn, ':c' => $id));
        }
        self::Queue($node, $id, 'create', self::CreateData(self::Container($id), $setup));
        self::Log('create', sprintf('%s at %s on node %s (%s CPU, %d MB memory, %d MB disk)%s', $app['name'], $addr[0], $n['nd_name_vc'], self::Cpu($v['cpu']), $v['mem'], $v['disk'], $dnsOn ? '; DNS record added' : ''), (int) $acc, (int) $by, $id, $node);
        return $id;
    }

    /**
     * A setup password (letters and digits people can read out).
     *
     * @return string
     */
    static function Password()
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < 20; $i++) {
            $p .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $p;
    }

    /**
     * The registry logins the administrators added (private images, or Docker Hub's higher download
     * limit): host => array(user, secret, at). Kept encrypted with the panel's secret key; the secret
     * is never shown again, only sent (sealed) to a node for a download from that registry.
     *
     * @return array<string, array>
     */
    static function Registries()
    {
        $raw = (string) self::Get('registries');
        if ($raw === '') {
            return array();
        }
        $a = json_decode((string) ctrl_secret::Decrypt($raw), true);
        return is_array($a) ? $a : array();
    }

    /**
     * A registry's name as images use it ('docker.io' for Docker Hub, whatever it was typed as).
     *
     * @param string $h
     * @return string '' when it is not one
     */
    static function RegistryHost($h)
    {
        $h = strtolower(trim((string) $h));
        $h = preg_replace('#^https?://#', '', $h);
        $h = rtrim(explode('/', $h)[0], '.');
        if (in_array($h, array('docker.com', 'hub.docker.com', 'index.docker.io', 'registry-1.docker.io', 'registry.hub.docker.com', 'docker hub'), true)) {
            $h = 'docker.io';
        }
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+(:\d{1,5})?$/', $h) ? $h : '';
    }

    /**
     * Add or change a registry login (administrators).
     *
     * @return bool
     */
    static function SaveRegistry($by, $host, $user, $secret)
    {
        $host = self::RegistryHost($host);
        if ($host === '') {
            return self::No('Type the registry as images name it, for example docker.io, ghcr.io or quay.io.');
        }
        if (!preg_match('/^[\x21-\x7e]{1,255}$/', (string) $user)) {
            return self::No('The user name has no spaces (up to 255 characters).');
        }
        if (!preg_match('/^[\x21-\x7e]{1,4096}$/', (string) $secret)) {
            return self::No('Paste the token (or password): no spaces.');
        }
        $all = self::Registries();
        if (!isset($all[$host]) && count($all) >= 20) {
            return self::No('Up to 20 registry logins.');
        }
        $all[$host] = array('user' => (string) $user, 'secret' => (string) $secret, 'at' => time());
        self::Set('registries', ctrl_secret::Encrypt(json_encode($all)));
        self::Log('registry', sprintf('Registry login for %s (user %s) saved', $host, $user), 0, $by);
        return true;
    }

    /**
     * Remove a registry login (administrators).
     *
     * @return bool
     */
    static function RemoveRegistry($by, $host)
    {
        $all = self::Registries();
        $host = self::RegistryHost($host);
        if ($host === '' || !isset($all[$host])) {
            return self::No('No such registry login.');
        }
        unset($all[$host]);
        self::Set('registries', $all ? ctrl_secret::Encrypt(json_encode($all)) : '');
        self::Log('registry', sprintf('Registry login for %s removed', $host), 0, $by);
        return true;
    }

    /**
     * What the node gets to create a container.
     *
     * @param array $c
     * @param string $setup The setup password (only its hash goes to the node).
     * @return array
     */
    static function CreateData(array $c, $setup)
    {
        $app = dh_catalog::App((string) $c['dc_app_vc']);
        $env = array();
        // the app's settings, then its owner's (which may change them)
        $fill = function ($k, $val) use ($c) {
            return str_replace(array('{domain}', '{secret}'), array((string) $c['dc_domain_vc'], self::AppSecret((int) $c['dc_id_pk'], (string) $k)), $val);
        };
        // the app's settings; an app taken back keeps the ones it ran with where they differ from those
        // (KeepEnv: as they were, no placeholders filled in); then its owner's (which may change them)
        foreach ($app['env'] as $k => $val) {
            $env[$k] = $fill($k, $val);
        }
        foreach (self::KeepEnv($c) as $k => $val) {
            $env[$k] = $val;
        }
        foreach (self::OwnerEnv($c) as $k => $val) {
            $env[$k] = $fill($k, $val);
        }
        return array_merge(self::Who($c), array(
            'image' => $app['image'], 'port' => (int) $app['port'], 'hostport' => (int) $c['dc_port_in'], 'env' => $env, 'volumes' => $app['volumes'],
            'cpu' => (int) $c['dc_cpu_in'], 'mem' => (int) $c['dc_mem_in'], 'disk' => (int) $c['dc_disk_in'], 'domain' => (string) $c['dc_domain_vc'],
            'public' => (int) $c['dc_public_in'], 'auth' => $setup !== '' ? password_hash($setup, PASSWORD_BCRYPT) : '',
        ), self::RegistryFor((string) $app['image']));
    }

    /**
     * The login for an image's registry, as a job's 'registry' field (none when there is no login).
     *
     * @param string $image
     * @return array
     */
    static function RegistryFor($image)
    {
        $host = explode('/', (string) $image)[0];
        $all = self::Registries();
        if (!isset($all[$host])) {
            return array();
        }
        return array('registry' => array('host' => $host, 'user' => (string) $all[$host]['user'], 'secret' => (string) $all[$host]['secret']));
    }

    /**
     * Which container and whose (every job carries this).
     *
     * @param array $c
     * @return array{cid:int, user:string}
     */
    static function Who(array $c)
    {
        return array('cid' => (int) $c['dc_id_pk'], 'user' => 'sdh' . (int) $c['dc_acc_fk']);
    }

    /**
     * The setup password of a container ('' once it is public or unreadable).
     *
     * @param array $c
     * @return string
     */
    static function SetupPassword(array $c)
    {
        $p = ctrl_secret::Decrypt((string) $c['dc_setup_tx']);
        return $p === false ? '' : $p;
    }

    /**
     * Do something with one of an account's own containers.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @param int $by
     * @param int $id
     * @param string $what start, stop, restart, logs, public, private, delete
     * @return bool
     */
    static function Act($acc, $by, $id, $what)
    {
        global $zdbh;
        $c = self::Container($id);
        if (!$c || (int) $c['dc_acc_fk'] !== (int) $acc) {
            return self::No('No such container.');
        }
        $node = (int) $c['dc_node_fk'];
        $who = self::Who($c);
        if ((string) $c['dc_state_vc'] === 'deleting') {
            return self::No('That container is being deleted.');
        }
        switch ($what) {
            case 'start':
            case 'restart':
                if ((string) $c['dc_held_vc'] === 'account') {
                    return self::No('The account is switched off: its containers stay stopped.');
                }
                if ((int) $c['dc_used_in'] > (int) $c['dc_disk_in']) {
                    return self::No('It uses more disk than its limit: raise its disk limit first.');
                }
                $zdbh->prepare("UPDATE x_dh_containers SET dc_want_vc = 'running', dc_held_vc = '', dc_state_vc = :s WHERE dc_id_pk = :c")
                    ->execute(array(':s' => $what === 'start' ? 'starting' : 'restarting', ':c' => (int) $id));
                self::Queue($node, (int) $id, $what, $who);
                break;
            case 'stop':
                $zdbh->prepare("UPDATE x_dh_containers SET dc_want_vc = 'stopped', dc_state_vc = 'stopping' WHERE dc_id_pk = :c")->execute(array(':c' => (int) $id));
                self::Queue($node, (int) $id, 'stop', $who);
                break;
            case 'logs':
                self::Queue($node, (int) $id, 'logs', $who + array('lines' => 200));
                break;
            case 'update':
                $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_ct_fk = :c AND jb_action_vc IN ('update','recreate') AND jb_state_vc IN ('queued','sent')");
                $q->execute(array(':c' => (int) $id));
                if ((int) $q->fetchColumn() > 0) {
                    return self::No('It is being updated already.');
                }
                $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'updating' WHERE dc_id_pk = :c")->execute(array(':c' => (int) $id));
                self::Queue($node, (int) $id, 'update', self::CreateData($c, ''));
                break;
            case 'remake':
                // the node lost it (set up again, restored): made again, without its old data
                if ((string) $c['dc_state_vc'] !== 'missing') {
                    return self::No('Only an app its node no longer has is made again.');
                }
                $setup = (int) $c['dc_public_in'] === 1 ? '' : self::SetupPassword($c);
                if ((int) $c['dc_public_in'] !== 1 && $setup === '') {
                    $setup = self::Password();
                    $zdbh->prepare("UPDATE x_dh_containers SET dc_setup_tx = :s WHERE dc_id_pk = :c")->execute(array(':s' => ctrl_secret::Encrypt($setup), ':c' => (int) $id));
                }
                $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'creating', dc_want_vc = 'running', dc_note_vc = '', dc_used_in = 0 WHERE dc_id_pk = :c")->execute(array(':c' => (int) $id));
                self::Queue($node, (int) $id, 'create', self::CreateData(self::Container((int) $id), $setup));
                break;
            case 'auto-on':
            case 'auto-off':
                $zdbh->prepare("UPDATE x_dh_containers SET dc_autoupd_in = :a WHERE dc_id_pk = :c")->execute(array(':a' => $what === 'auto-on' ? 1 : 0, ':c' => (int) $id));
                break;
            case 'public':
            case 'private':
                $pub = $what === 'public' ? 1 : 0;
                $setup = '';
                if (!$pub) {
                    // a new setup password each time it is closed again
                    $setup = self::Password();
                    $zdbh->prepare("UPDATE x_dh_containers SET dc_setup_tx = :s WHERE dc_id_pk = :c")->execute(array(':s' => ctrl_secret::Encrypt($setup), ':c' => (int) $id));
                }
                $zdbh->prepare("UPDATE x_dh_containers SET dc_public_in = :p WHERE dc_id_pk = :c")->execute(array(':p' => $pub, ':c' => (int) $id));
                self::Queue($node, (int) $id, 'proxy', $who + array('domain' => (string) $c['dc_domain_vc'], 'hostport' => (int) $c['dc_port_in'], 'public' => $pub,
                    'auth' => $pub ? '' : password_hash($setup, PASSWORD_BCRYPT)));
                break;
            case 'delete':
                $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'deleting', dc_want_vc = 'deleted' WHERE dc_id_pk = :c")->execute(array(':c' => (int) $id));
                // no other job of it is still worth doing
                $zdbh->prepare("DELETE FROM x_dh_jobs WHERE jb_ct_fk = :c AND jb_state_vc = 'queued'")->execute(array(':c' => (int) $id));
                self::Queue($node, (int) $id, 'delete', $who + array('domain' => (string) $c['dc_domain_vc']));
                break;
            default:
                return self::No('Unknown action.');
        }
        self::Log($what, ucfirst($what) . ' ' . $c['dc_domain_vc'], (int) $acc, (int) $by, (int) $id, $node);
        return true;
    }

    /**
     * Change a container's limits (within the account's share).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @param int $by
     * @param int $id
     * @param int $cpu
     * @param int $mem
     * @param int $disk
     * @return bool
     */
    static function Limits($acc, $by, $id, $cpu, $mem, $disk)
    {
        global $zdbh;
        $c = self::Container($id);
        if (!$c || (int) $c['dc_acc_fk'] !== (int) $acc || (string) $c['dc_state_vc'] === 'deleting') {
            return self::No('No such container.');
        }
        $app = dh_catalog::App((string) $c['dc_app_vc']);
        if (!is_int($cpu) || !is_int($mem) || !is_int($disk) || $cpu < 10 || $disk < 256 || $mem < ($app ? $app['minmem'] : 128)) {
            return self::No(sprintf('At least 0.1 CPU, %d MB of memory and 256 MB of disk.', $app ? $app['minmem'] : 128));
        }
        $f = self::Free($acc);
        if (!$f) {
            return self::No('Your account has no Docker Hosting share: ask your provider.');
        }
        $more = array('cpu' => $cpu - (int) $c['dc_cpu_in'], 'mem' => $mem - (int) $c['dc_mem_in'], 'disk' => $disk - (int) $c['dc_disk_in']);
        foreach ($more as $k => $d) {
            if ($d > 0 && $f[$k] < $d) {
                return self::No('That is more than your share has free.');
            }
        }
        $n = self::Node((int) $c['dc_node_fk']);
        $room = $n ? self::Room($n) : array('cpu' => 0, 'mem' => 0, 'disk' => 0);
        foreach ($more as $k => $d) {
            if ($d > 0 && $room[$k] < $d) {
                return self::No('Its node has no room for that much more.');
            }
        }
        $zdbh->prepare("UPDATE x_dh_containers SET dc_cpu_in = :c, dc_mem_in = :m, dc_disk_in = :d WHERE dc_id_pk = :i")
            ->execute(array(':c' => $cpu, ':m' => $mem, ':d' => $disk, ':i' => (int) $id));
        self::Queue((int) $c['dc_node_fk'], (int) $id, 'limits', self::Who($c) + array('cpu' => $cpu, 'mem' => $mem));
        self::Log('limits', sprintf('%s: %s CPU, %d MB memory, %d MB disk', $c['dc_domain_vc'], self::Cpu($cpu), $mem, $disk), (int) $acc, (int) $by, (int) $id, (int) $c['dc_node_fk']);
        return true;
    }

    /**
     * The last logs a node sent for a container ('' when none yet), and when.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return array{0:string, 1:int, 2:bool} text, time, still waiting
     */
    static function Logs($id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT jb_state_vc, jb_result_tx, jb_done_in FROM x_dh_jobs WHERE jb_ct_fk = :c AND jb_action_vc = 'logs' ORDER BY jb_id_pk DESC LIMIT 1");
        $q->execute(array(':c' => (int) $id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return array('', 0, false);
        }
        return array((string) $r['jb_result_tx'], (int) $r['jb_done_in'], in_array((string) $r['jb_state_vc'], array('queued', 'sent'), true));
    }

    // ----- DNS (the way DNS Manager does it) -----

    /**
     * Add the A record of a container's address to the account's own domain.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $acc
     * @param int $vhost
     * @param string $domain
     * @param string $label
     * @param string $ip
     * @return int The record's ID.
     */
    static function DnsAdd($acc, $vhost, $domain, $label, $ip)
    {
        global $zdbh;
        $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk, dn_name_vc, dn_vhost_fk, dn_type_vc, dn_host_vc, dn_ttl_in, dn_target_vc, dn_priority_in, dn_weight_in, dn_port_in, dn_created_ts)
            VALUES (:a, :n, :v, 'A', :h, 3600, :t, 0, 0, 0, :c)")
            ->execute(array(':a' => (int) $acc, ':n' => $domain, ':v' => (int) $vhost, ':h' => $label, ':t' => $ip, ':c' => time()));
        $id = (int) $zdbh->lastInsertId();
        self::DnsUpdate($vhost);
        return $id;
    }

    /**
     * Delete the record a container's address had (only the one Docker Hosting added, as it added it).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $c
     * @return void
     */
    static function DnsRemove(array $c)
    {
        global $zdbh;
        if ((int) $c['dc_dns_fk'] <= 0) {
            return;
        }
        $label = substr((string) $c['dc_domain_vc'], 0, (int) strpos((string) $c['dc_domain_vc'], '.'));
        $q = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts = :t WHERE dn_id_pk = :d AND dn_acc_fk = :a AND dn_vhost_fk = :v AND dn_type_vc = 'A' AND LOWER(dn_host_vc) = :h AND dn_deleted_ts IS NULL");
        $q->execute(array(':t' => time(), ':d' => (int) $c['dc_dns_fk'], ':a' => (int) $c['dc_acc_fk'], ':v' => (int) $c['dc_vhost_fk'], ':h' => $label));
        if ($q->rowCount() > 0) {
            self::DnsUpdate((int) $c['dc_vhost_fk']);
        }
    }

    /**
     * Put a domain on the dns_hasupdates list, so the daemon rewrites its zone (as DNS Manager's TriggerDNSUpdate).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $vhost
     * @return void
     */
    static function DnsUpdate($vhost)
    {
        global $zdbh;
        $list = (string) ctrl_options::GetSystemOption('dns_hasupdates');
        $ids = $list === '' ? array() : explode(',', $list);
        if (!in_array((string) (int) $vhost, $ids, true)) {
            $ids[] = (string) (int) $vhost;
            $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = 'dns_hasupdates'")->execute(array(':v' => implode(',', $ids)));
        }
    }

    // ----- apps a node has that the panel does not know, apps a node lost, a node that is gone -----

    /**
     * Note an app a node reports that the panel does not know: kept (never deleted by itself) and
     * listed for an administrator, who gives it back to an owner or deletes it. Its number is kept
     * from being handed out to a new app meanwhile (a row marked 'reserved').
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @param int $cid
     * @param array $s What the node says about it.
     * @return void
     */
    static function Found($node, $cid, array $s)
    {
        global $zdbh;
        $user = isset($s['user']) && is_string($s['user']) && preg_match('/^sdh\d{1,9}$/', $s['user']) ? $s['user'] : '';
        if ($cid <= 0 || $cid > 2000000000 || $user === '') {
            return;
        }
        $v = array(
            ':u' => $user,
            ':i' => isset($s['image']) && is_string($s['image']) && preg_match('#^[A-Za-z0-9.:/@_-]{1,300}$#', $s['image']) ? $s['image'] : '',
            ':d' => isset($s['domain']) && is_string($s['domain']) && strlen($s['domain']) <= 253 && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/', $s['domain']) ? $s['domain'] : '',
            ':p' => isset($s['hostport']) && is_int($s['hostport']) && $s['hostport'] >= self::PORT_FIRST && $s['hostport'] <= self::PORT_LAST ? $s['hostport'] : 0,
            // (-1: not said; agents before 109 say only the user and state)
            ':v' => isset($s['volumes']) && is_int($s['volumes']) && $s['volumes'] >= 0 && $s['volumes'] <= 10 ? $s['volumes'] : -1,
            ':b' => !empty($s['public']) ? 1 : 0,
            ':s' => isset($s['state']) && is_string($s['state']) && preg_match('/^[a-z]{1,20}$/', $s['state']) ? $s['state'] : 'unknown',
            ':m' => isset($s['used_mb']) && is_numeric($s['used_mb']) ? max(0, min(2000000000, (int) $s['used_mb'])) : 0,
            ':t' => time(), ':n' => (int) $node, ':c' => (int) $cid,
        );
        $q = $zdbh->prepare("SELECT fd_id_pk FROM x_dh_found WHERE fd_node_fk = :n AND fd_cid_in = :c");
        $q->execute(array(':n' => (int) $node, ':c' => (int) $cid));
        $id = (int) $q->fetchColumn();
        if ($id > 0) {
            unset($v[':n'], $v[':c']);
            $v[':f'] = $id;
            $zdbh->prepare("UPDATE x_dh_found SET fd_user_vc = :u, fd_image_vc = :i, fd_domain_vc = :d, fd_hostport_in = :p, fd_volumes_in = :v, fd_public_in = :b,
                fd_state_vc = :s, fd_used_in = :m, fd_seen_in = :t WHERE fd_id_pk = :f")->execute($v);
        } else {
            // (a node broken into could report any number of made-up apps: at most MAX_COUNT kept per node)
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_found WHERE fd_node_fk = :n");
            $q->execute(array(':n' => (int) $node));
            if ((int) $q->fetchColumn() >= self::MAX_COUNT) {
                return;
            }
            $zdbh->prepare("INSERT INTO x_dh_found (fd_node_fk, fd_cid_in, fd_user_vc, fd_image_vc, fd_domain_vc, fd_hostport_in, fd_volumes_in, fd_public_in, fd_state_vc, fd_used_in, fd_first_in, fd_seen_in)
                VALUES (:n, :c, :u, :i, :d, :p, :v, :b, :s, :m, :t, :t)")->execute($v);
            $n = self::NodeAny($node);
            self::Log('found', mb_substr(sprintf('Node "%s" has an app the panel does not know (sdh-%d of %s%s): kept, see Found on this node', $n ? $n['nd_name_vc'] : $node, $cid, $user,
                $v[':d'] !== '' ? ', ' . $v[':d'] : ''), 0, 255), 0, 0, 0, (int) $node);
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_containers WHERE dc_id_pk = :c");
        $q->execute(array(':c' => (int) $cid));
        if ((int) $q->fetchColumn() === 0 && self::NearNumber($cid)) {
            $zdbh->prepare("INSERT INTO x_dh_containers (dc_id_pk, dc_acc_fk, dc_node_fk, dc_app_vc, dc_cpu_in, dc_mem_in, dc_disk_in, dc_port_in, dc_state_vc, dc_want_vc, dc_created_in, dc_deleted_in)
                VALUES (:c, 0, 0, '', 0, 0, 0, 0, 'reserved', 'deleted', :t, :t)")->execute(array(':c' => (int) $cid, ':t' => time()));
        }
    }

    /**
     * Whether an app number a node reports is near the panel's own (at most 10000 past the highest):
     * only such numbers are kept for found apps, so a node can not push the panel's numbering to
     * its end with made-up ones (a panel restored from an older copy is behind by a few at most).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $cid
     * @return bool
     */
    static function NearNumber($cid)
    {
        global $zdbh;
        return (int) $cid <= (int) $zdbh->query("SELECT COALESCE(MAX(dc_id_pk), 0) FROM x_dh_containers")->fetchColumn() + 10000;
    }

    /**
     * A node, also one removed from the panel (for names in messages).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return array|null
     */
    static function NodeAny($id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_dh_nodes WHERE nd_id_pk = :n");
        $q->execute(array(':n' => (int) $id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    /**
     * The apps nodes have that the panel does not know, by node.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node 0: every node's
     * @return array<int, array>
     */
    static function FoundApps($node = 0)
    {
        global $zdbh;
        self::Ensure();
        if ((int) $node > 0) {
            $q = $zdbh->prepare("SELECT * FROM x_dh_found WHERE fd_node_fk = :n ORDER BY fd_cid_in");
            $q->execute(array(':n' => (int) $node));
        } else {
            $q = $zdbh->query("SELECT * FROM x_dh_found ORDER BY fd_node_fk, fd_cid_in");
        }
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['fd_id_pk']] = $r;
        }
        return $out;
    }

    /**
     * The catalog app an image is (by its name, without version), or ''.
     *
     * @param string $image
     * @return string
     */
    static function AppByImage($image)
    {
        $base = function ($i) {
            $i = preg_replace('/@sha256:[a-f0-9]{64}$/', '', strtolower((string) $i));
            return (string) preg_replace('#:[a-z0-9._-]{1,128}$#', '', (string) $i);
        };
        $want = $base($image);
        if ($want === '') {
            return '';
        }
        foreach (dh_catalog::Apps() as $id => $a) {
            if ($base($a['image']) === $want) {
                return (string) $id;
            }
        }
        return '';
    }

    /**
     * The account whose domain an address is under (the longest match), or 0.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $domain
     * @return array{0:int, 1:int, 2:string} [account, vhost, domain name]
     */
    static function DomainOwner($domain)
    {
        global $zdbh;
        $domain = strtolower((string) $domain);
        $best = array(0, 0, '');
        if ($domain === '') {
            return $best;
        }
        $q = $zdbh->query("SELECT vh_id_pk, vh_acc_fk, vh_name_vc FROM x_vhosts WHERE vh_deleted_ts IS NULL");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $name = strtolower((string) $v['vh_name_vc']);
            if ($name !== '' && ($domain === $name || substr($domain, -strlen($name) - 1) === '.' . $name) && strlen($name) > strlen($best[2])) {
                $best = array((int) $v['vh_acc_fk'], (int) $v['vh_id_pk'], $name);
            }
        }
        return $best;
    }

    /**
     * Who a found app most likely belongs to, and which app it is.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $f
     * @return array{acc:int, app:string, why:string, back:int}
     */
    static function Suggest(array $f)
    {
        global $zdbh;
        $node = (int) $f['fd_node_fk'];
        $cid = (int) $f['fd_cid_in'];
        $num = (int) substr((string) $f['fd_user_vc'], 3);
        $out = array('acc' => 0, 'app' => self::AppByImage($f['fd_image_vc']), 'why' => '', 'back' => 0);
        $q = $zdbh->prepare("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c");
        $q->execute(array(':c' => $cid));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r && (string) $r['dc_state_vc'] !== 'reserved' && (int) $r['dc_acc_fk'] === $num && (int) $r['dc_node_fk'] !== $node && ($a = self::Account($num))) {
            $on = self::NodeAny((int) $r['dc_node_fk']);
            $app = dh_catalog::App((string) $r['dc_app_vc']);
            $live = $r['dc_deleted_in'] === null && $on && $on['nd_deleted_in'] === null && self::Online($on);
            if ($live) {
                // the same app runs on another node now (it was started there again): this is an old copy
                $out['why'] = sprintf('A copy of %s\'s app at %s, which runs on node "%s" now: delete this copy, or give it back as a second app.', $a['ac_user_vc'], $r['dc_domain_vc'], $on['nd_name_vc']);
                $out['acc'] = $num;
                $out['app'] = (string) $r['dc_app_vc'];
                return $out;
            }
            $out = array('acc' => $num, 'app' => (string) $r['dc_app_vc'], 'back' => 1,
                'why' => sprintf('It is %s\'s %s at %s%s: give it back as it was.', $a['ac_user_vc'], $app ? $app['name'] : $r['dc_app_vc'], $r['dc_domain_vc'],
                    $on ? sprintf(', last on node "%s"', $on['nd_name_vc']) : ''));
            return $out;
        }
        $o = self::DomainOwner($f['fd_domain_vc']);
        if ($o[0] > 0 && ($a = self::Account($o[0]))) {
            $out['acc'] = $o[0];
            $out['why'] = sprintf('Its address %s is under %s, %s\'s domain here.', $f['fd_domain_vc'], $o[2], $a['ac_user_vc']);
        } elseif ($num > 0 && ($a = self::Account($num))) {
            $out['acc'] = $num;
            $out['why'] = sprintf('It ran under account number %d, which is %s here: check that it is the same customer (a panel set up again numbers its accounts anew).', $num, $a['ac_user_vc']);
        } else {
            $out['why'] = 'Its owner is not known here: choose one.';
        }
        return $out;
    }

    /**
     * Give a found app (back) to an owner: the node first says the settings the app runs with
     * (job 'inspect'), then AdoptRead finishes it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by
     * @param int $fid The found app (x_dh_found).
     * @param int $acc Its owner.
     * @param string $app The catalog app it is.
     * @return bool
     */
    static function Adopt($by, $fid, $acc, $app)
    {
        global $zdbh;
        if (!self::IsAdmin(self::Account($by))) {
            return self::No('Only the panel\'s administrators do this.');
        }
        $q = $zdbh->prepare("SELECT * FROM x_dh_found WHERE fd_id_pk = :f");
        $q->execute(array(':f' => (int) $fid));
        $f = $q->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return self::No('That app is not on the list any more.');
        }
        if ((string) $f['fd_step_vc'] !== '') {
            return self::No('That app is being taken care of already.');
        }
        $n = self::Node((int) $f['fd_node_fk']);
        if (!$n || !self::Online($n)) {
            return self::No('Its node is not online.');
        }
        $i = self::Info($n);
        if ((string) $i['agent'] === '' || version_compare((string) $i['agent'], '109', '<')) {
            return self::No(sprintf('Its node runs agent %s: update the node first (agent 109 or newer gives apps back).', (string) $i['agent'] !== '' ? $i['agent'] : '?'));
        }
        $a = self::Account($acc);
        if (!$a) {
            return self::No('Choose its owner.');
        }
        $ap = dh_catalog::App($app);
        if (!$ap) {
            return self::No('Choose which app it is (add it under Your own apps first if it is not in the list).');
        }
        if ((int) $f['fd_volumes_in'] !== count($ap['volumes'])) {
            return self::No(sprintf('It has %d data folders and %s has %d: it is not that app.', (int) $f['fd_volumes_in'], $ap['name'], count($ap['volumes'])));
        }
        if ((string) $f['fd_domain_vc'] === '') {
            return self::No('Its address could not be read on the node: it can only be deleted.');
        }
        $o = self::DomainOwner($f['fd_domain_vc']);
        if ($o[0] > 0 && $o[0] !== (int) $acc) {
            $oa = self::Account($o[0]);
            return self::No(sprintf('Its address %s is under %s, which belongs to %s, not %s.', $f['fd_domain_vc'], $o[2], $oa ? $oa['ac_user_vc'] : '?', $a['ac_user_vc']));
        }
        $g = self::Grant($acc);
        if (!$g) {
            return self::No(sprintf('%s has no Docker Hosting share (it would not see the app): give it one first.', $a['ac_user_vc']));
        }
        if (!in_array((string) $app, $g['apps'], true)) {
            return self::No(sprintf('%s may not run %s (its share does not offer it): change the share first.', $a['ac_user_vc'], $ap['name']));
        }
        // (its size is known once the node says it: AdoptRead checks the rest of the share)
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_containers WHERE dc_id_pk = :c AND dc_acc_fk = :a AND dc_deleted_in IS NULL");
        $q->execute(array(':c' => (int) $f['fd_cid_in'], ':a' => (int) $acc));
        if ((int) $q->fetchColumn() === 0 && self::Free($acc)['count'] < 1) {
            return self::No(sprintf('%s\'s share has no container free: raise it first (or delete one of its apps).', $a['ac_user_vc']));
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_containers WHERE dc_domain_vc = :d AND dc_deleted_in IS NULL AND dc_id_pk != :c");
        $q->execute(array(':d' => $f['fd_domain_vc'], ':c' => (int) $f['fd_cid_in']));
        if ((int) $q->fetchColumn() > 0) {
            return self::No(sprintf('Another app uses %s already: delete one of the two.', $f['fd_domain_vc']));
        }
        $zdbh->prepare("UPDATE x_dh_found SET fd_acc_fk = :a, fd_app_vc = :p, fd_step_vc = 'reading', fd_note_vc = '' WHERE fd_id_pk = :f")
            ->execute(array(':a' => (int) $acc, ':p' => (string) $app, ':f' => (int) $fid));
        self::Queue((int) $f['fd_node_fk'], 0, 'inspect', array('cid' => (int) $f['fd_cid_in'], 'user' => (string) $f['fd_user_vc']));
        self::Log('adopt', mb_substr(sprintf('Giving %s on node "%s" to %s (%s)', $f['fd_domain_vc'], $n['nd_name_vc'], $a['ac_user_vc'], $ap['name']), 0, 255), (int) $acc, (int) $by, 0, (int) $f['fd_node_fk']);
        return true;
    }

    /**
     * The node said the settings a found app runs with: it becomes the owner's app. When its number,
     * owner (Linux user) and port can stay, nothing on the node changes; otherwise the node moves it
     * (job 'move': the same version and data under the owner's user, the old one removed once the
     * new one runs, put back when anything fails).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @param array $data The inspect job's data.
     * @param bool $ok
     * @param string $out
     * @return void
     */
    static function AdoptRead($node, array $data, $ok, $out)
    {
        global $zdbh;
        $cid = isset($data['cid']) ? (int) $data['cid'] : 0;
        $q = $zdbh->prepare("SELECT * FROM x_dh_found WHERE fd_node_fk = :n AND fd_cid_in = :c AND fd_step_vc = 'reading'");
        $q->execute(array(':n' => (int) $node, ':c' => $cid));
        $f = $q->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return;
        }
        $stop = function ($why) use ($zdbh, $f) {
            $zdbh->prepare("UPDATE x_dh_found SET fd_step_vc = '', fd_note_vc = :m WHERE fd_id_pk = :f")->execute(array(':m' => mb_substr($why, 0, 255), ':f' => (int) $f['fd_id_pk']));
            self::Log('failed', mb_substr('Giving back ' . $f['fd_domain_vc'] . ': ' . $why, 0, 255), (int) $f['fd_acc_fk'], 0, 0, (int) $f['fd_node_fk']);
        };
        $j = $ok ? json_decode($out, true) : null;
        if (!is_array($j) || !isset($j['env']) || !is_array($j['env'])) {
            $stop('its settings could not be read on the node (' . trim((string) preg_replace('/\s+/', ' ', mb_substr($out, 0, 150))) . ')');
            return;
        }
        $acc = (int) $f['fd_acc_fk'];
        $app = dh_catalog::App((string) $f['fd_app_vc']);
        if (!$app || !self::Account($acc)) {
            $stop('the owner or the app is gone from the panel');
            return;
        }
        $user = 'sdh' . $acc;
        $q = $zdbh->prepare("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c");
        $q->execute(array(':c' => $cid));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        // its number stays when it is free (or kept for it), or it was this owner's app on another node
        $reserved = ($r && (string) $r['dc_state_vc'] === 'reserved') || (!$r && self::NearNumber($cid));
        $old = $r && !$reserved && (int) $r['dc_acc_fk'] === $acc && (int) $r['dc_node_fk'] !== (int) $node ? $r : null;
        if ($old) {
            $on = self::NodeAny((int) $old['dc_node_fk']);
            if ($old['dc_deleted_in'] === null && $on && $on['nd_deleted_in'] === null && self::Online($on)) {
                // (that app runs on another node now: this copy becomes a second app)
                $old = null;
            }
        }
        $reuse = $reserved || $old !== null;
        $port = (int) $f['fd_hostport_in'];
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_containers WHERE dc_node_fk = :n AND dc_deleted_in IS NULL AND dc_port_in = :p AND dc_id_pk != :c");
        $q->execute(array(':n' => (int) $node, ':p' => $port, ':c' => $reuse ? $cid : 0));
        if ($port < self::PORT_FIRST || (int) $q->fetchColumn() > 0) {
            $port = self::FreePort((int) $node);
            if (!$port) {
                $stop('the node has no port free');
                return;
            }
        }
        if ($reuse && $user === (string) $f['fd_user_vc'] && $port !== (int) $f['fd_hostport_in']) {
            // (the same number and owner on another port can not be made beside itself: a new number)
            $reuse = false;
        }
        $move = !$reuse || $user !== (string) $f['fd_user_vc'] || $port !== (int) $f['fd_hostport_in'];
        // never past the owner's share (an old copy of its own app counts only by what it grows)
        $size = array('count' => 1,
            'cpu' => isset($j['cpu']) && is_int($j['cpu']) && $j['cpu'] >= 1 && $j['cpu'] <= 100000 ? $j['cpu'] : (int) $app['cpu'],
            'mem' => isset($j['mem']) && is_int($j['mem']) && $j['mem'] >= 16 && $j['mem'] <= 4194304 ? $j['mem'] : (int) $app['mem'],
            'disk' => $old ? (int) $old['dc_disk_in'] : max((int) $app['disk'], (int) $f['fd_used_in'] + 1024));
        $free = self::Free($acc);
        $names = array('count' => 'containers', 'cpu' => 'CPU', 'mem' => 'memory', 'disk' => 'disk');
        foreach ($size as $k => $need) {
            $had = $old && $old['dc_deleted_in'] === null ? ($k === 'count' ? 1 : (int) $old[array('cpu' => 'dc_cpu_in', 'mem' => 'dc_mem_in', 'disk' => 'dc_disk_in')[$k]]) : 0;
            if (!$free || $need - $had > $free[$k]) {
                $stop(sprintf('the owner\'s share has not enough %s free for it (it needs %s): raise the share first', $names[$k],
                    $k === 'cpu' ? self::Cpu($need) . ' CPU' : ($k === 'count' ? '1 container' : $need . ' MB')));
                return;
            }
        }
        $setup = (int) $f['fd_public_in'] === 1 ? '' : self::Password();
        $o = self::DomainOwner($f['fd_domain_vc']);
        $v = array(':a' => $acc, ':n' => (int) $node, ':p' => (string) $f['fd_app_vc'], ':l' => $old ? (string) $old['dc_label_vc'] : '',
            ':c' => $size['cpu'], ':m' => $size['mem'], ':d' => $size['disk'], ':o' => $port, ':h' => (string) $f['fd_domain_vc'],
            ':v' => $o[0] === $acc ? $o[1] : 0, ':s' => $move ? 'moving' : 'running', ':b' => (int) $f['fd_public_in'],
            ':x' => $setup !== '' ? ctrl_secret::Encrypt($setup) : null, ':t' => time());
        if ($reuse) {
            $target = $cid;
            if ($r) {
                $v[':i'] = $cid;
                $zdbh->prepare("UPDATE x_dh_containers SET dc_acc_fk = :a, dc_node_fk = :n, dc_app_vc = :p, dc_label_vc = :l, dc_cpu_in = :c, dc_mem_in = :m, dc_disk_in = :d,
                    dc_port_in = :o, dc_domain_vc = :h, dc_vhost_fk = :v, dc_state_vc = :s, dc_want_vc = 'running', dc_held_vc = '', dc_public_in = :b, dc_setup_tx = :x,
                    dc_note_vc = '', dc_update_in = 0, dc_badsince_in = 0, dc_created_in = :t, dc_deleted_in = NULL" . ($old ? '' : ", dc_dns_fk = 0, dc_env_tx = NULL, dc_envprev_tx = NULL") . " WHERE dc_id_pk = :i")
                    ->execute($v);
            } else {
                $v[':i'] = $cid;
                $zdbh->prepare("INSERT INTO x_dh_containers (dc_id_pk, dc_acc_fk, dc_node_fk, dc_app_vc, dc_label_vc, dc_cpu_in, dc_mem_in, dc_disk_in, dc_port_in, dc_domain_vc, dc_vhost_fk,
                    dc_state_vc, dc_want_vc, dc_public_in, dc_setup_tx, dc_created_in) VALUES (:i, :a, :n, :p, :l, :c, :m, :d, :o, :h, :v, :s, 'running', :b, :x, :t)")->execute($v);
            }
        } else {
            $zdbh->prepare("INSERT INTO x_dh_containers (dc_acc_fk, dc_node_fk, dc_app_vc, dc_label_vc, dc_cpu_in, dc_mem_in, dc_disk_in, dc_port_in, dc_domain_vc, dc_vhost_fk,
                dc_state_vc, dc_want_vc, dc_public_in, dc_setup_tx, dc_created_in) VALUES (:a, :n, :p, :l, :c, :m, :d, :o, :h, :v, :s, 'running', :b, :x, :t)")->execute($v);
            $target = (int) $zdbh->lastInsertId();
        }
        // the settings it runs with, where they differ from what the panel would give it now (secrets
        // made by another panel, or before its keys changed; the owner's own ones)
        $c = self::Container($target);
        $gen = array();
        foreach ($app['env'] as $k => $val) {
            $gen[$k] = str_replace(array('{domain}', '{secret}'), array((string) $c['dc_domain_vc'], self::AppSecret($target, (string) $k)), $val);
        }
        $keep = array();
        foreach ($j['env'] as $k => $val) {
            if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k) && is_string($val) && strlen($val) <= 4096 && !preg_match('/[\x00\r\n]/', $val)
                && (!isset($gen[$k]) || $gen[$k] !== $val) && count($keep) < 50) {
                $keep[$k] = $val;
            }
        }
        $zdbh->prepare("UPDATE x_dh_containers SET dc_keepenv_tx = :e WHERE dc_id_pk = :c")->execute(array(':e' => $keep ? ctrl_secret::Encrypt(json_encode($keep)) : null, ':c' => $target));
        $c = self::Container($target);
        if ($old && (int) $old['dc_dns_fk'] > 0) {
            self::DnsRepoint($c, (int) $node);
        }
        $a = self::Account($acc);
        if ($move) {
            $zdbh->prepare("UPDATE x_dh_found SET fd_step_vc = 'moving', fd_target_in = :t WHERE fd_id_pk = :f")->execute(array(':t' => $target, ':f' => (int) $f['fd_id_pk']));
            self::Queue((int) $node, $target, 'move', self::CreateData($c, $setup) + array('from_cid' => $cid, 'from_user' => (string) $f['fd_user_vc']));
            self::Log('adopt', mb_substr(sprintf('%s: moving it to %s on the node (the same version and data)', $f['fd_domain_vc'], $a['ac_user_vc']), 0, 255), $acc, 0, $target, (int) $node);
            return;
        }
        $zdbh->prepare("DELETE FROM x_dh_found WHERE fd_id_pk = :f")->execute(array(':f' => (int) $f['fd_id_pk']));
        if ($setup !== '') {
            // (the old setup password is not known here: a new one)
            self::Queue((int) $node, $target, 'proxy', self::Who($c) + array('domain' => (string) $c['dc_domain_vc'], 'hostport' => (int) $c['dc_port_in'], 'public' => 0,
                'auth' => password_hash($setup, PASSWORD_BCRYPT)));
        }
        self::Log('adopted', mb_substr(sprintf('%s is %s\'s app again (it kept running, with its data)', $f['fd_domain_vc'], $a['ac_user_vc']), 0, 255), $acc, 0, $target, (int) $node);
    }

    /**
     * Point an app's DNS record (the one Docker Hosting added) at the node it runs on now.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $c
     * @param int $node
     * @return void
     */
    static function DnsRepoint(array $c, $node)
    {
        global $zdbh;
        $n = self::Node($node);
        $ip = $n ? self::PublicIp($n) : '';
        if ((int) $c['dc_dns_fk'] <= 0 || $ip === '') {
            return;
        }
        $q = $zdbh->prepare("UPDATE x_dns SET dn_target_vc = :ip WHERE dn_id_pk = :d AND dn_acc_fk = :a AND dn_type_vc = 'A' AND dn_deleted_ts IS NULL AND dn_target_vc != :ip2");
        $q->execute(array(':ip' => $ip, ':ip2' => $ip, ':d' => (int) $c['dc_dns_fk'], ':a' => (int) $c['dc_acc_fk']));
        if ($q->rowCount() > 0) {
            self::DnsUpdate((int) $c['dc_vhost_fk']);
        }
    }

    /**
     * Delete a found app on its node (with its data).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by
     * @param int $fid
     * @return bool
     */
    static function DeleteFound($by, $fid)
    {
        global $zdbh;
        if (!self::IsAdmin(self::Account($by))) {
            return self::No('Only the panel\'s administrators do this.');
        }
        $q = $zdbh->prepare("SELECT * FROM x_dh_found WHERE fd_id_pk = :f");
        $q->execute(array(':f' => (int) $fid));
        $f = $q->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return self::No('That app is not on the list any more.');
        }
        if ((string) $f['fd_step_vc'] !== '') {
            return self::No('That app is being taken care of already.');
        }
        $zdbh->prepare("UPDATE x_dh_found SET fd_step_vc = 'deleting', fd_note_vc = '' WHERE fd_id_pk = :f")->execute(array(':f' => (int) $fid));
        self::Queue((int) $f['fd_node_fk'], 0, 'delete', array('cid' => (int) $f['fd_cid_in'], 'user' => (string) $f['fd_user_vc'], 'domain' => ''));
        self::Log('delete', mb_substr(sprintf('Deleting the found app sdh-%d of %s%s (with its data)', $f['fd_cid_in'], $f['fd_user_vc'], (string) $f['fd_domain_vc'] !== '' ? ' at ' . $f['fd_domain_vc'] : ''), 0, 255), 0, (int) $by, 0, (int) $f['fd_node_fk']);
        return true;
    }

    /**
     * A node that is gone for good (the server broke, was deleted): its apps are started again on
     * the other nodes, without their data (it was on that server). Their addresses, sizes and
     * settings stay; DNS records Docker Hosting added point to the new node.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @param int $by
     * @return int|false How many were started elsewhere.
     */
    static function Rehome($id, $by)
    {
        global $zdbh;
        if (!self::IsAdmin(self::Account($by))) {
            return self::No('Only the panel\'s administrators do this.');
        }
        $n = self::Node($id);
        if (!$n) {
            return self::No('No such node.');
        }
        if (self::Online($n) || (int) $n['nd_seen_in'] > time() - 600) {
            return self::No('The node is online (or was within the last 10 minutes): its apps stay where they are.');
        }
        $q = $zdbh->prepare("SELECT * FROM x_dh_containers WHERE dc_node_fk = :n AND dc_deleted_in IS NULL AND dc_state_vc != 'deleting' ORDER BY dc_id_pk");
        $q->execute(array(':n' => (int) $id));
        $done = 0;
        $left = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $to = self::Pick((int) $c['dc_cpu_in'], (int) $c['dc_mem_in'], (int) $c['dc_disk_in']);
            $port = $to ? self::FreePort($to) : 0;
            if (!$to || !$port) {
                $left++;
                continue;
            }
            $setup = (int) $c['dc_public_in'] === 1 ? '' : self::SetupPassword($c);
            if ((int) $c['dc_public_in'] !== 1 && $setup === '') {
                $setup = self::Password();
            }
            $zdbh->prepare("UPDATE x_dh_containers SET dc_node_fk = :n, dc_port_in = :p, dc_state_vc = 'creating', dc_used_in = 0, dc_update_in = 0, dc_badsince_in = 0, dc_setup_tx = :s,
                dc_note_vc = :m WHERE dc_id_pk = :c")->execute(array(':n' => $to, ':p' => $port, ':s' => $setup !== '' ? ctrl_secret::Encrypt($setup) : null,
                ':m' => mb_substr(sprintf('Started again on another node after node "%s" was gone (its old data stayed there).', $n['nd_name_vc']), 0, 255), ':c' => (int) $c['dc_id_pk']));
            $zdbh->prepare("DELETE FROM x_dh_jobs WHERE jb_ct_fk = :c AND jb_state_vc IN ('queued','sent')")->execute(array(':c' => (int) $c['dc_id_pk']));
            $c = self::Container((int) $c['dc_id_pk']);
            self::DnsRepoint($c, $to);
            self::Queue($to, (int) $c['dc_id_pk'], 'create', self::CreateData($c, $setup));
            $tn = self::Node($to);
            self::Log('rehome', mb_substr(sprintf('%s started again on node "%s" (node "%s" is gone; without its old data)', $c['dc_domain_vc'], $tn['nd_name_vc'], $n['nd_name_vc']), 0, 255), (int) $c['dc_acc_fk'], (int) $by, (int) $c['dc_id_pk'], $to);
            $done++;
        }
        self::$why = $left > 0 ? sprintf('%d could not be placed (no node with room is online): try again when there is room.', $left) : '';
        if ($done === 0 && $left > 0) {
            return self::No(self::$why);
        }
        return $done;
    }

    /**
     * Start over with new panel keys, when the old ones can not be read any more (the server's
     * secret, cnf/security.php, changed and the old one is lost). Every node must then be linked
     * again (sentora-docker-node-relink on it); its apps are kept and given back from "Found on this node".
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by
     * @return bool
     */
    static function NewKeys($by)
    {
        global $zdbh;
        if (!self::IsAdmin(self::Account($by))) {
            return self::No('Only the panel\'s administrators do this.');
        }
        if (self::Keys() || self::Get('sign_sk') === '') {
            return self::No('The panel\'s keys are fine: nothing to do.');
        }
        if (ctrl_secret::Encrypt('test') === '') {
            return self::No('This server has no secret key at all (cnf/security.php): put it back first (sentora-post-install-check says how).');
        }
        self::Set('sign_sk', '');
        self::Set('box_kp', '');
        // (what was waiting for the nodes can not be read any more)
        $zdbh->exec("DELETE FROM x_dh_jobs WHERE jb_state_vc IN ('queued','sent')");
        self::Log('new-keys', 'The panel made new keys (the old ones could not be read): every node must be linked again with sentora-docker-node-relink', 0, (int) $by);
        return true;
    }

    // ----- jobs and what nodes say -----

    /**
     * Queue a job for a node (its data kept encrypted: it holds settings and the setup password's hash).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @param int $ct
     * @param string $action
     * @param array $data
     * @return int
     */
    static function Queue($node, $ct, $action, array $data)
    {
        global $zdbh;
        $zdbh->prepare("INSERT INTO x_dh_jobs (jb_node_fk, jb_ct_fk, jb_action_vc, jb_data_tx, jb_created_in) VALUES (:n, :c, :a, :d, :t)")
            ->execute(array(':n' => (int) $node, ':c' => (int) $ct, ':a' => $action, ':d' => ctrl_secret::Encrypt(json_encode($data)), ':t' => time()));
        return (int) $zdbh->lastInsertId();
    }

    /**
     * Whether a nonce was used before (and remember it).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $nonce
     * @return bool
     */
    static function Replayed($nonce, $ip = '', $link = false)
    {
        global $zdbh;
        try {
            $zdbh->prepare("INSERT INTO x_dh_nonces (nn_nonce_vc, nn_time_in, nn_ip_vc, nn_link_in) VALUES (:n, :t, :i, :l)")
                ->execute(array(':n' => $nonce, ':t' => time(), ':i' => self::Ip($ip), ':l' => $link ? 1 : 0));
            return false;
        } catch (Exception $e) {
            return true;
        }
    }

    /**
     * Whether an address sent too many linking messages (pair, hello) lately: they need no key the
     * panel knows, so anyone can send them; this keeps them from filling the tables and the log.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $ip
     * @return bool
     */
    static function TooMany($ip)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_nonces WHERE nn_link_in = 1 AND nn_ip_vc = :i AND nn_time_in > :t");
        $q->execute(array(':i' => self::Ip($ip), ':t' => time() - 600));
        return (int) $q->fetchColumn() >= self::LINK_MAX;
    }

    /**
     * One message from a node: opened, checked, answered. Returns the reply (on the wire), or ''
     * when the message is not good (nothing is told why).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $wire
     * @param string $ip Where it came from.
     * @return string
     */
    static function Receive($wire, $ip)
    {
        global $zdbh;
        $k = self::Keys();
        if (!$k) {
            return '';
        }
        $o = dh_proto::Open($wire, $k['box_kp']);
        if (!$o) {
            return '';
        }
        $msg = $o[0];
        if (($msg['type'] === 'pair' || $msg['type'] === 'hello') && self::TooMany($ip)) {
            return '';
        }
        if ($msg['type'] === 'pair') {
            $d = $msg['data'];
            $spk = dh_proto::Bin(isset($d['sign_pk']) ? $d['sign_pk'] : '', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
            $bpk = dh_proto::Bin(isset($d['box_pk']) ? $d['box_pk'] : '', SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
            $code = isset($d['code']) && is_string($d['code']) && preg_match('/^(\d{1,9})\.([0-9a-f]{32})$/', $d['code'], $m) ? $m : null;
            if (!$spk || !$bpk || !$code || !dh_proto::Verify($o, $spk) || self::Replayed($msg['nonce'], $ip, true)) {
                return '';
            }
            $n = self::Node((int) $code[1]);
            if (!$n || (string) $n['nd_signpk_vc'] !== '' || (int) $n['nd_codeexp_in'] < time() || !hash_equals((string) $n['nd_code_vc'], hash('sha256', $code[2]))) {
                // (noted for the first few from an address in 10 minutes, so it can not fill the log)
                $seen = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_nonces WHERE nn_link_in = 1 AND nn_ip_vc = :i AND nn_time_in > :t");
                $seen->execute(array(':i' => self::Ip($ip), ':t' => time() - 600));
                if ((int) $seen->fetchColumn() <= 3) {
                    self::Log('pair-refused', 'A node tried to link with a code that is not good (any more), from ' . self::Ip($ip), 0, 0, 0, $n ? (int) $n['nd_id_pk'] : 0);
                }
                return '';
            }
            $q = $zdbh->prepare("UPDATE x_dh_nodes SET nd_signpk_vc = :s, nd_boxpk_vc = :b, nd_code_vc = '', nd_codeexp_in = 0, nd_seen_in = :t, nd_ip_vc = :i, nd_info_tx = :f
                WHERE nd_id_pk = :n AND nd_signpk_vc = '' AND nd_code_vc = :c");
            $q->execute(array(':s' => dh_proto::B64($spk), ':b' => dh_proto::B64($bpk), ':t' => time(), ':i' => self::Ip($ip), ':f' => json_encode(self::CleanInfo(isset($d['info']) ? $d['info'] : array())),
                ':n' => (int) $n['nd_id_pk'], ':c' => (string) $n['nd_code_vc']));
            if ($q->rowCount() !== 1) {
                return '';
            }
            self::Log('pair', 'Node "' . $n['nd_name_vc'] . '" linked from ' . self::Ip($ip), 0, 0, 0, (int) $n['nd_id_pk']);
            return dh_proto::Pack(dh_proto::Msg('paired', (int) $n['nd_id_pk'], array('node' => (int) $n['nd_id_pk'], 'name' => (string) $n['nd_name_vc']), $msg['nonce']), $k['sign_sk'], $bpk);
        }
        if ($msg['type'] === 'hello') {
            $d = $msg['data'];
            $spk = dh_proto::Bin(isset($d['sign_pk']) ? $d['sign_pk'] : '', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
            $bpk = dh_proto::Bin(isset($d['box_pk']) ? $d['box_pk'] : '', SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
            // the node sends its key itself (sealed: only this panel reads it); the panel kept only the key's hash
            $claim = isset($d['claim']) && is_string($d['claim']) && preg_match('/^[0-9a-f]{30}$/', $d['claim']) ? hash('sha256', hex2bin($d['claim'])) : '';
            if (!$spk || !$bpk || $claim === '' || !dh_proto::Verify($o, $spk) || self::Replayed($msg['nonce'], $ip, true)) {
                return '';
            }
            $q = $zdbh->prepare("SELECT * FROM x_dh_nodes WHERE nd_claim_vc = :c AND nd_signpk_vc = '' AND nd_deleted_in IS NULL");
            $q->execute(array(':c' => $claim));
            $n = $q->fetch(PDO::FETCH_ASSOC);
            if (!$n) {
                // not added in the panel (yet): it asks again in a few seconds
                return dh_proto::Pack(dh_proto::Msg('pending', 0, array(), $msg['nonce']), $k['sign_sk'], $bpk);
            }
            $u = $zdbh->prepare("UPDATE x_dh_nodes SET nd_signpk_vc = :s, nd_boxpk_vc = :b, nd_claim_vc = '', nd_seen_in = :t, nd_ip_vc = :i, nd_info_tx = :f
                WHERE nd_id_pk = :n AND nd_signpk_vc = '' AND nd_claim_vc = :c");
            $u->execute(array(':s' => dh_proto::B64($spk), ':b' => dh_proto::B64($bpk), ':t' => time(), ':i' => self::Ip($ip),
                ':f' => json_encode(self::CleanInfo(isset($d['info']) ? $d['info'] : array())), ':n' => (int) $n['nd_id_pk'], ':c' => $claim));
            if ($u->rowCount() !== 1) {
                return '';
            }
            self::Log('pair', 'Node "' . $n['nd_name_vc'] . '" linked with its node key from ' . self::Ip($ip), 0, 0, 0, (int) $n['nd_id_pk']);
            return dh_proto::Pack(dh_proto::Msg('linked', (int) $n['nd_id_pk'], array('node' => (int) $n['nd_id_pk'], 'name' => (string) $n['nd_name_vc']), $msg['nonce']), $k['sign_sk'], $bpk);
        }
        $n = isset($msg['node']) ? self::Node((int) $msg['node']) : null;
        if (!$n && isset($msg['node'])) {
            return self::GoneNode((int) $msg['node'], $o, $msg, $k);
        }
        if (!$n || (string) $n['nd_signpk_vc'] === '') {
            return '';
        }
        $spk = dh_proto::Bin((string) $n['nd_signpk_vc'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $bpk = dh_proto::Bin((string) $n['nd_boxpk_vc'], SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
        if (!$spk || !$bpk || !dh_proto::Verify($o, $spk) || self::Replayed($msg['nonce']) || !in_array($msg['type'], array('poll', 'put', 'get'), true)) {
            return '';
        }
        if ($msg['type'] !== 'poll') {
            // a piece of an app's backup, to or from the node (a node busy with one does not poll: it is online)
            $zdbh->prepare("UPDATE x_dh_nodes SET nd_seen_in = :t WHERE nd_id_pk = :n")->execute(array(':t' => time(), ':n' => (int) $n['nd_id_pk']));
            $data = is_array($msg['data']) ? $msg['data'] : array();
            $reply = $msg['type'] === 'put' ? dh_backup::Put((int) $n['nd_id_pk'], $data) : dh_backup::Get((int) $n['nd_id_pk'], $data);
            return dh_proto::Pack(dh_proto::Msg($msg['type'] === 'put' ? 'taken' : 'piece', (int) $n['nd_id_pk'], $reply, $msg['nonce']), $k['sign_sk'], $bpk);
        }
        $reply = self::Poll($n, $msg['data'], $ip);
        return dh_proto::Pack(dh_proto::Msg('jobs', (int) $n['nd_id_pk'], $reply, $msg['nonce']), $k['sign_sk'], $bpk);
    }

    /**
     * A node that was removed calls again (it was off when it was removed, or its uninstall never ran): when
     * it really is that node (signed with its own key), its one answer is to uninstall itself with
     * everything on it, its customers' containers and data too (as they were deleted with it here). It is
     * given nothing else. A node this panel never knew, or one restored from an older copy of the panel
     * (its row is not removed), never gets this.
     *
     * @return string the sealed answer, or '' (nothing)
     */
    static function GoneNode($id, $o, array $msg, array $k)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_dh_nodes WHERE nd_id_pk = :n AND nd_deleted_in IS NOT NULL AND nd_gonesign_vc <> ''");
        $q->execute(array(':n' => (int) $id));
        $g = $q->fetch(PDO::FETCH_ASSOC);
        if (!$g) {
            return '';
        }
        $spk = dh_proto::Bin((string) $g['nd_gonesign_vc'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $bpk = dh_proto::Bin((string) $g['nd_gonebox_vc'], SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
        if (!$spk || !$bpk || !dh_proto::Verify($o, $spk) || ($msg['type'] ?? '') !== 'poll' || self::Replayed($msg['nonce'])) {
            return '';
        }
        $j = $zdbh->prepare("SELECT jb_id_pk, jb_state_vc FROM x_dh_jobs WHERE jb_node_fk = :n AND jb_action_vc = 'uninstall' ORDER BY jb_id_pk DESC LIMIT 1");
        $j->execute(array(':n' => (int) $id));
        $job = $j->fetch(PDO::FETCH_ASSOC);
        $data = is_array($msg['data'] ?? null) ? $msg['data'] : array();
        // its answer to the job (it is uninstalling): nothing more to send, ever
        foreach ((array) ($data['results'] ?? array()) as $r) {
            if ($job && is_array($r) && (int) ($r['id'] ?? 0) === (int) $job['jb_id_pk']) {
                $zdbh->prepare("UPDATE x_dh_jobs SET jb_state_vc = :s, jb_result_tx = :r, jb_done_in = :t WHERE jb_id_pk = :j")
                    ->execute(array(':s' => !empty($r['ok']) ? 'done' : 'failed', ':r' => mb_substr((string) ($r['out'] ?? ''), 0, 1000), ':t' => time(), ':j' => (int) $job['jb_id_pk']));
                $zdbh->prepare("UPDATE x_dh_nodes SET nd_gonesign_vc = '', nd_gonebox_vc = '' WHERE nd_id_pk = :n")->execute(array(':n' => (int) $id));
                self::Log('node-remove', sprintf('Removed node "%s" called again and was told to uninstall itself with everything on it: %s', $g['nd_name_vc'], mb_substr((string) ($r['out'] ?? ''), 0, 200)), 0, 0, 0, (int) $id);
                return dh_proto::Pack(dh_proto::Msg('jobs', (int) $id, array('jobs' => array(), 'interval' => 600), $msg['nonce']), $k['sign_sk'], $bpk);
            }
        }
        if (!$job) {
            $job = array('jb_id_pk' => self::Queue((int) $id, 0, 'uninstall', array('purge' => true)));
        }
        $zdbh->prepare("UPDATE x_dh_jobs SET jb_state_vc = 'sent', jb_sent_in = :t, jb_tries_in = jb_tries_in + 1 WHERE jb_id_pk = :j")->execute(array(':t' => time(), ':j' => (int) $job['jb_id_pk']));
        return dh_proto::Pack(dh_proto::Msg('jobs', (int) $id, array('jobs' => array(array('id' => (int) $job['jb_id_pk'], 'action' => 'uninstall', 'data' => array('purge' => true))), 'interval' => 60), $msg['nonce']), $k['sign_sk'], $bpk);
    }

    /**
     * An address as text, or ''.
     *
     * @param string $ip
     * @return string
     */
    static function Ip($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? (string) $ip : '';
    }

    /**
     * A node's report, only the expected fields.
     *
     * @param mixed $i
     * @return array
     */
    static function CleanInfo($i)
    {
        $i = is_array($i) ? $i : array();
        $out = array();
        foreach (array('cpus', 'mem_mb', 'disk_total_mb', 'disk_free_mb', 'sys_updates', 'sys_security', 'sys_reboot', 'sys_checked_at', 'sys_last_at', 'sys_last_ok') as $k) {
            $out[$k] = isset($i[$k]) && is_numeric($i[$k]) ? max(0, (int) $i[$k]) : 0;
        }
        $out['cfg_local_certs'] = !empty($i['cfg_local_certs']) ? 1 : 0;
        // (agent 108 and later: the customers whose apps may reach the local network; null = not said)
        $out['cfg_lan_users'] = isset($i['cfg_lan_users']) && is_array($i['cfg_lan_users'])
            ? array_values(array_filter($i['cfg_lan_users'], function ($u) { return is_string($u) && preg_match('/^sdh\d{1,9}$/', $u); })) : null;
        $out['cfg_email'] = isset($i['cfg_email']) && is_string($i['cfg_email']) && preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $i['cfg_email']) ? $i['cfg_email'] : '';
        foreach (array('sys_last_kind', 'sys_running') as $k) {
            $out[$k] = isset($i[$k]) && is_string($i[$k]) && preg_match('/^[a-z]{0,10}$/', $i[$k]) ? $i[$k] : '';
        }
        foreach (array('os', 'kernel', 'podman', 'caddy', 'agent', 'selinux', 'apparmor') as $k) {
            $out[$k] = isset($i[$k]) && is_string($i[$k]) ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $i[$k]), 0, 100) : '';
        }
        return $out;
    }

    /**
     * A node's poll: its report, the results of the jobs it did and its containers' states; the
     * reply holds the jobs waiting for it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $n
     * @param array $d info, results [{id, ok, out}], states {cid: {state, used_mb}}
     * @param string $ip
     * @return array{jobs:array, interval:int}
     */
    static function Poll(array $n, array $d, $ip)
    {
        global $zdbh;
        $node = (int) $n['nd_id_pk'];
        $now = time();
        $info = isset($d['info']) ? self::CleanInfo($d['info']) : self::Info($n);
        $zdbh->prepare("UPDATE x_dh_nodes SET nd_seen_in = :t, nd_ip_vc = :i, nd_info_tx = :f WHERE nd_id_pk = :n")
            ->execute(array(':t' => time(), ':i' => self::Ip($ip), ':f' => json_encode($info), ':n' => $node));
        // the node lets other customers reach the local network than the panel says (a node set up
        // again, or a change it missed): the list is sent again
        if (is_array($info['cfg_lan_users']) && $info['cfg_lan_users'] !== self::LanUsers()) {
            $w = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_node_fk = :n AND jb_action_vc = 'network' AND jb_state_vc IN ('queued','sent')");
            $w->execute(array(':n' => $node));
            if ((int) $w->fetchColumn() === 0) {
                self::Queue($node, 0, 'network', array('lan_users' => self::LanUsers()));
            }
        }
        // results
        foreach ((isset($d['results']) && is_array($d['results']) ? array_slice($d['results'], 0, 50) : array()) as $r) {
            if (is_array($r) && isset($r['id'])) {
                self::Result($node, (int) $r['id'], !empty($r['ok']), isset($r['out']) && is_string($r['out']) ? $r['out'] : '');
            }
        }
        // states
        $mine = array();
        $q = $zdbh->prepare("SELECT * FROM x_dh_containers WHERE dc_node_fk = :n");
        $q->execute(array(':n' => $node));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $mine[(int) $c['dc_id_pk']] = $c;
        }
        $states = isset($d['states']) && is_array($d['states']) ? $d['states'] : array();
        $set = $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = :s, dc_used_in = :u WHERE dc_id_pk = :c AND dc_deleted_in IS NULL AND dc_state_vc NOT IN ('deleting')");
        foreach ($states as $cid => $s) {
            $cid = (int) $cid;
            if (!is_array($s)) {
                continue;
            }
            if (!isset($mine[$cid]) || $mine[$cid]['dc_deleted_in'] !== null) {
                $p = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_node_fk = :n AND jb_ct_fk = :c AND jb_action_vc = 'delete' AND jb_state_vc IN ('queued','sent')");
                $p->execute(array(':n' => $node, ':c' => $cid));
                if ($cid > 0 && !isset($mine[$cid]) && (int) $p->fetchColumn() > 0) {
                    // (an app moved to another node: its old copy here is on its way out)
                    continue;
                }
                if (!isset($mine[$cid]) || (string) $mine[$cid]['dc_state_vc'] === 'reserved') {
                    // an app the panel does not know (the node was linked again, the panel set up again or
                    // restored): never deleted by itself; kept and listed for an administrator to give back
                    self::Found($node, $cid, $s);
                    continue;
                }
                // an app of this node the panel deleted, still there (the delete did not get through): it goes
                $p = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_node_fk = :n AND jb_ct_fk = :c AND jb_action_vc = 'delete' AND jb_state_vc IN ('queued','sent')");
                $p->execute(array(':n' => $node, ':c' => $cid));
                if ($cid > 0 && (int) $p->fetchColumn() === 0 && isset($s['user']) && is_string($s['user']) && preg_match('/^sdh\d+$/', $s['user'])) {
                    self::Queue($node, $cid, 'delete', array('cid' => $cid, 'user' => $s['user'], 'domain' => ''));
                    self::Log('orphan', 'Node had container ' . $cid . ' the panel does not: deleting it', 0, 0, $cid, $node);
                }
                continue;
            }
            $state = isset($s['state']) && is_string($s['state']) && preg_match('/^[a-z]{1,20}$/', $s['state']) ? $s['state'] : 'unknown';
            // a state the panel asked to change is shown as asked until the node's job is done
            $busy = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_ct_fk = :c AND jb_state_vc IN ('queued','sent') AND jb_action_vc IN ('create','start','stop','restart','update','recreate','move','backup','restore')");
            $busy->execute(array(':c' => $cid));
            if ((int) $busy->fetchColumn() > 0) {
                $state = (string) $mine[$cid]['dc_state_vc'];
            }
            $set->execute(array(':s' => $state, ':u' => isset($s['used_mb']) && is_numeric($s['used_mb']) ? max(0, (int) $s['used_mb']) : 0, ':c' => $cid));
            $bad = (string) $mine[$cid]['dc_want_vc'] === 'running' && !in_array($state, array('running', 'creating', 'starting', 'restarting', 'updating', 'applying', 'saving', 'restoring', 'moving'), true);
            $zdbh->prepare("UPDATE x_dh_containers SET dc_update_in = :u, dc_badsince_in = :b WHERE dc_id_pk = :c")->execute(array(':u' => !empty($s['update']) ? 1 : 0,
                ':b' => $bad ? ((int) $mine[$cid]['dc_badsince_in'] > 0 ? (int) $mine[$cid]['dc_badsince_in'] : time()) : 0, ':c' => $cid));
        }
        if (isset($d['states']) && is_array($d['states'])) {
            // found apps the node no longer has
            // (one being moved to its owner is the owner's app by number meanwhile: its result clears it)
            $zdbh->prepare("DELETE FROM x_dh_found WHERE fd_node_fk = :n AND fd_seen_in < :t AND fd_step_vc != 'moving'")->execute(array(':n' => $node, ':t' => $now));
            // the panel's apps the node does not have (the node was set up again, or restored from an older copy)
            foreach ($mine as $cid => $c) {
                if ($c['dc_deleted_in'] !== null || isset($states[$cid]) || isset($states[(string) $cid])
                    || in_array((string) $c['dc_state_vc'], array('creating', 'deleting', 'moving', 'missing', 'error', 'saving', 'restoring'), true)) {
                    continue;
                }
                $p = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_ct_fk = :c AND jb_action_vc IN ('create','move') AND jb_state_vc IN ('queued','sent')");
                $p->execute(array(':c' => $cid));
                if ((int) $p->fetchColumn() > 0) {
                    continue;
                }
                $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'missing', dc_badsince_in = 0, dc_note_vc = :m WHERE dc_id_pk = :c")
                    ->execute(array(':m' => 'The node no longer has this app (it was set up again, or restored from an older copy). Make it again (without its old data) or delete it.', ':c' => $cid));
                self::Log('missing', 'Node no longer has ' . $c['dc_domain_vc'] . ': shown as missing', (int) $c['dc_acc_fk'], 0, $cid, $node);
            }
        }
        // jobs waiting: new ones, and ones sent long ago and not answered
        $q = $zdbh->prepare("SELECT * FROM x_dh_jobs WHERE jb_node_fk = :n AND (jb_state_vc = 'queued' OR (jb_state_vc = 'sent' AND jb_sent_in < :old)) ORDER BY jb_id_pk LIMIT 10");
        $q->execute(array(':n' => $node, ':old' => time() - self::RESEND));
        $jobs = array();
        $sent = $zdbh->prepare("UPDATE x_dh_jobs SET jb_state_vc = 'sent', jb_sent_in = :t, jb_tries_in = jb_tries_in + 1 WHERE jb_id_pk = :j");
        $fail = $zdbh->prepare("UPDATE x_dh_jobs SET jb_state_vc = 'failed', jb_result_tx = :r, jb_done_in = :t WHERE jb_id_pk = :j");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $j) {
            $data = json_decode((string) ctrl_secret::Decrypt((string) $j['jb_data_tx']), true);
            if (!is_array($data) || (int) $j['jb_tries_in'] >= 5) {
                $fail->execute(array(':r' => is_array($data) ? 'The node did not answer this job.' : 'The job could not be read (the server\'s secret changed?).', ':t' => time(), ':j' => (int) $j['jb_id_pk']));
                if (is_array($data) && in_array((string) $j['jb_action_vc'], array('backup', 'restore'), true)) {
                    dh_backup::Result((int) $j['jb_node_fk'], $j, false, 'The node did not answer.');
                } elseif ((string) $j['jb_action_vc'] !== 'delete' || ($fc = self::Container((int) $j['jb_ct_fk'])) === null || (int) $fc['dc_node_fk'] === (int) $j['jb_node_fk']) {
                    self::Failed((int) $j['jb_ct_fk'], (string) $j['jb_action_vc'], 'The node did not answer.');
                }
                continue;
            }
            $sent->execute(array(':t' => time(), ':j' => (int) $j['jb_id_pk']));
            $jobs[] = array('id' => (int) $j['jb_id_pk'], 'action' => (string) $j['jb_action_vc'], 'data' => $data);
        }
        return array('jobs' => $jobs, 'interval' => 10);
    }

    /**
     * A job's result from its node.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @param int $job
     * @param bool $ok
     * @param string $out
     * @return void
     */
    static function Result($node, $job, $ok, $out)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_dh_jobs WHERE jb_id_pk = :j AND jb_node_fk = :n AND jb_state_vc IN ('queued','sent')");
        $q->execute(array(':j' => (int) $job, ':n' => (int) $node));
        $j = $q->fetch(PDO::FETCH_ASSOC);
        if (!$j) {
            return;
        }
        $out = mb_substr(mb_convert_encoding($out, 'UTF-8', 'UTF-8'), 0, 65000);
        $cid = (int) $j['jb_ct_fk'];
        $inspect = $cid === 0 && (string) $j['jb_action_vc'] === 'inspect';
        // (an app's settings, as the node read them, are not kept in the jobs: only encrypted, in the app)
        $zdbh->prepare("UPDATE x_dh_jobs SET jb_state_vc = :s, jb_result_tx = :r, jb_done_in = :t WHERE jb_id_pk = :j")
            ->execute(array(':s' => $ok ? 'done' : 'failed', ':r' => $inspect && $ok ? 'settings read' : $out, ':t' => time(), ':j' => (int) $job));
        if ($cid === 0) {
            $n = self::Node($node);
            $data = json_decode((string) ctrl_secret::Decrypt((string) $j['jb_data_tx']), true);
            $data = is_array($data) ? $data : array();
            if ($inspect) {
                self::AdoptRead((int) $node, $data, $ok, $out);
                return;
            }
            if ((string) $j['jb_action_vc'] === 'delete' && isset($data['cid'])) {
                // a found app deleted on the node
                if ($ok) {
                    $zdbh->prepare("DELETE FROM x_dh_found WHERE fd_node_fk = :n AND fd_cid_in = :c")->execute(array(':n' => (int) $node, ':c' => (int) $data['cid']));
                } else {
                    $zdbh->prepare("UPDATE x_dh_found SET fd_step_vc = '', fd_note_vc = :m WHERE fd_node_fk = :n AND fd_cid_in = :c")
                        ->execute(array(':m' => mb_substr('Could not be deleted: ' . trim((string) preg_replace('/\s+/', ' ', $out)), 0, 255), ':n' => (int) $node, ':c' => (int) $data['cid']));
                }
                self::Log($ok ? 'deleted' : 'failed', mb_substr(($ok ? 'Deleted the found app sdh-' : 'Could not delete the found app sdh-') . (int) $data['cid'] . ($ok ? '' : ': ' . $out), 0, 255), 0, 0, 0, (int) $node);
                return;
            }
            if ((string) $j['jb_action_vc'] === 'uninstall' && $ok && $n) {
                self::Forget((int) $node);
                self::Log('node-removed', 'Node "' . $n['nd_name_vc'] . '" removed its agent and keys; it is gone from the panel', 0, 0, 0, (int) $node);
            } elseif ($n) {
                self::Log($ok ? 'node-job' : 'node-failed', 'Node "' . $n['nd_name_vc'] . '": ' . mb_substr(trim(preg_replace('/\s+/', ' ', $out)), 0, 200), 0, 0, 0, (int) $node);
            }
            return;
        }
        if (in_array((string) $j['jb_action_vc'], array('backup', 'restore'), true)) {
            // (checked by its transfer: a move's restore runs on the node the app goes to)
            dh_backup::Result((int) $node, $j, (bool) $ok, (string) $out);
            return;
        }
        $c = self::Container($cid);
        // (only a container of this node: a node never changes another node's apps)
        if (!$c || (int) $c['dc_node_fk'] !== (int) $node) {
            return;
        }
        $action = (string) $j['jb_action_vc'];
        if (!$ok) {
            self::Failed($cid, $action, $out);
            return;
        }
        $set = $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = :s, dc_note_vc = '' WHERE dc_id_pk = :c");
        switch ($action) {
            case 'create':
            case 'start':
            case 'restart':
                $set->execute(array(':s' => 'running', ':c' => $cid));
                break;
            case 'recreate':
                $set->execute(array(':s' => 'running', ':c' => $cid));
                $zdbh->prepare("UPDATE x_dh_containers SET dc_envprev_tx = NULL WHERE dc_id_pk = :c")->execute(array(':c' => $cid));
                self::Log('settings', $c['dc_domain_vc'] . ': running with its new settings', (int) $c['dc_acc_fk'], 0, $cid, (int) $node);
                break;
            case 'update':
                $set->execute(array(':s' => 'running', ':c' => $cid));
                $zdbh->prepare("UPDATE x_dh_containers SET dc_update_in = 0 WHERE dc_id_pk = :c")->execute(array(':c' => $cid));
                self::Log('updated', $c['dc_domain_vc'] . ': ' . mb_substr($out, 0, 200), (int) $c['dc_acc_fk'], 0, $cid, (int) $node);
                break;
            case 'stop':
                $set->execute(array(':s' => 'stopped', ':c' => $cid));
                break;
            case 'move':
                // a found app given to its owner: it runs under the owner's user now
                $set->execute(array(':s' => 'running', ':c' => $cid));
                $zdbh->prepare("DELETE FROM x_dh_found WHERE fd_node_fk = :n AND fd_target_in = :c AND fd_step_vc = 'moving'")->execute(array(':n' => (int) $node, ':c' => $cid));
                self::Log('adopted', mb_substr(sprintf('%s is %s\'s app now (moved on the node, with its data)', $c['dc_domain_vc'], 'sdh' . (int) $c['dc_acc_fk']), 0, 255), (int) $c['dc_acc_fk'], 0, $cid, (int) $node);
                break;
            case 'delete':
                self::DnsRemove($c);
                $zdbh->prepare("UPDATE x_dh_containers SET dc_deleted_in = :t, dc_state_vc = 'deleted', dc_setup_tx = NULL WHERE dc_id_pk = :c")->execute(array(':t' => time(), ':c' => $cid));
                self::Log('deleted', 'Deleted ' . $c['dc_domain_vc'] . ' (with its data)', (int) $c['dc_acc_fk'], 0, $cid, (int) $node);
                break;
        }
    }

    /**
     * Note that a job of a container failed.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $cid
     * @param string $action
     * @param string $out
     * @return void
     */
    static function Failed($cid, $action, $out)
    {
        global $zdbh;
        if ($cid <= 0) {
            return;
        }
        $line = trim((string) preg_replace('/\s+/', ' ', $out));
        if ($action === 'move') {
            // the node put the app back as it was: it stays on the found list; the row made for it goes
            // (a number kept for it stays kept)
            $q = $zdbh->prepare("SELECT * FROM x_dh_found WHERE fd_target_in = :c AND fd_step_vc = 'moving'");
            $q->execute(array(':c' => (int) $cid));
            $f = $q->fetch(PDO::FETCH_ASSOC);
            if ($f) {
                $zdbh->prepare("UPDATE x_dh_found SET fd_step_vc = '', fd_target_in = 0, fd_note_vc = :m WHERE fd_id_pk = :f")
                    ->execute(array(':m' => mb_substr('Could not be moved to its owner: ' . $line, 0, 255), ':f' => (int) $f['fd_id_pk']));
            }
            if ($f && (int) $f['fd_cid_in'] === (int) $cid) {
                $zdbh->prepare("UPDATE x_dh_containers SET dc_acc_fk = 0, dc_node_fk = 0, dc_state_vc = 'reserved', dc_want_vc = 'deleted', dc_setup_tx = NULL, dc_keepenv_tx = NULL,
                    dc_deleted_in = :t WHERE dc_id_pk = :c")->execute(array(':t' => time(), ':c' => (int) $cid));
            } else {
                $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'deleted', dc_setup_tx = NULL, dc_keepenv_tx = NULL, dc_deleted_in = :t WHERE dc_id_pk = :c")
                    ->execute(array(':t' => time(), ':c' => (int) $cid));
            }
            self::Log('failed', mb_substr('Moving a found app to its owner failed: ' . $line, 0, 255), 0, 0, (int) $cid);
            return;
        }
        if ($action === 'recreate') {
            // the node put the app back as it was: its settings go back too
            $zdbh->prepare("UPDATE x_dh_containers SET dc_env_tx = dc_envprev_tx, dc_envprev_tx = NULL, dc_state_vc = 'running', dc_note_vc = :n WHERE dc_id_pk = :c AND dc_deleted_in IS NULL")
                ->execute(array(':n' => mb_substr('The new settings did not work, so it runs with the ones before: ' . $line, 0, 255), ':c' => (int) $cid));
            self::Log('failed', mb_substr('New settings did not work: ' . $line, 0, 255), 0, 0, (int) $cid);
            return;
        }
        $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = CASE WHEN dc_state_vc = 'deleting' THEN 'deleting' ELSE 'error' END, dc_note_vc = :n WHERE dc_id_pk = :c AND dc_deleted_in IS NULL")
            ->execute(array(':n' => mb_substr(ucfirst($action) . ' failed: ' . $line, 0, 255), ':c' => (int) $cid));
        self::Log('failed', mb_substr(ucfirst($action) . ' failed: ' . $line, 0, 255), 0, 0, (int) $cid);
    }
}
