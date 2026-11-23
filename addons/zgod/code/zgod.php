<?php

/**
 * zGod's data: the panel's own tables read across every account and reseller (what each reseller's
 * modules show only for their own clients), and the two things it changes (an account switched off
 * or on, a mailbox switched off or on) done the way the panel's own modules do them, hooks and all.
 * Every value reaches the database as a bound parameter.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-23 (zGod for ZPanel, 2011, written again)
 */
class zgod
{
    /** Administrators' group. */
    const ADMINS = 1;

    /**
     * The month as the panel keeps it in x_bandwidth (YYYYMM).
     *
     * @param int|null $t
     * @return string
     */
    static function Month($t = null)
    {
        return date('Ym', $t === null ? time() : $t);
    }

    /**
     * Rows of a query.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $sql
     * @param array $args
     * @return array<int, array<string, mixed>>
     */
    static function Rows($sql, array $args = array())
    {
        global $zdbh;
        $q = $zdbh->prepare($sql);
        $q->execute($args);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * One value of a query.
     *
     * @param string $sql
     * @param array $args
     * @return mixed
     */
    static function One($sql, array $args = array())
    {
        global $zdbh;
        $q = $zdbh->prepare($sql);
        $q->execute($args);
        return $q->fetchColumn();
    }

    /**
     * zGod's own settings (the daily report).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_zgod (zg_key_vc VARCHAR(40) NOT NULL PRIMARY KEY, zg_value_tx TEXT NOT NULL)");
            $done = true;
        }
    }

    /**
     * A setting.
     *
     * @param string $k
     * @param string $default
     * @return string
     */
    static function Get($k, $default = '')
    {
        self::Ensure();
        $v = self::One("SELECT zg_value_tx FROM x_zgod WHERE zg_key_vc = :k", array(':k' => $k));
        return $v === false ? $default : (string) $v;
    }

    /**
     * Store a setting.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $k
     * @param string $v
     * @return void
     */
    static function Set($k, $v)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("DELETE FROM x_zgod WHERE zg_key_vc = :k")->execute(array(':k' => $k));
        $zdbh->prepare("INSERT INTO x_zgod (zg_key_vc, zg_value_tx) VALUES (:k, :v)")->execute(array(':k' => $k, ':v' => (string) $v));
    }

    /**
     * Every account (not deleted), with its package, group, reseller, quotas and this month's use.
     *
     * @return array<int, array<string, mixed>> By account ID.
     */
    static function Accounts()
    {
        $rows = self::Rows("SELECT a.ac_id_pk AS id, a.ac_user_vc AS username, a.ac_email_vc AS email, a.ac_reseller_fk AS reseller,
                a.ac_group_fk AS grp, a.ac_enabled_in AS enabled, a.ac_lastlogon_ts AS lastlogon, a.ac_created_ts AS created,
                a.ac_package_fk AS package_id, p.pk_name_vc AS package, g.ug_name_vc AS groupname, r.ac_user_vc AS resellername,
                q.qt_diskspace_bi AS diskq, q.qt_bandwidth_bi AS bwq, b.bd_diskamount_bi AS disk, b.bd_transamount_bi AS bw
            FROM x_accounts a
            LEFT JOIN x_packages p ON p.pk_id_pk = a.ac_package_fk
            LEFT JOIN x_groups g ON g.ug_id_pk = a.ac_group_fk
            LEFT JOIN x_accounts r ON r.ac_id_pk = a.ac_reseller_fk
            LEFT JOIN x_quotas q ON q.qt_package_fk = a.ac_package_fk
            LEFT JOIN x_bandwidth b ON b.bd_acc_fk = a.ac_id_pk AND b.bd_month_in = :m
            WHERE a.ac_deleted_ts IS NULL ORDER BY a.ac_user_vc", array(':m' => self::Month()));
        $out = array();
        foreach ($rows as $r) {
            foreach (array('id', 'reseller', 'grp', 'enabled', 'lastlogon', 'created', 'package_id', 'diskq', 'bwq', 'disk', 'bw') as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['clients'] = 0;
            $out[$r['id']] = $r;
        }
        foreach ($out as $r) {
            if (isset($out[$r['reseller']]) && $r['reseller'] !== $r['id']) {
                $out[$r['reseller']]['clients']++;
            }
        }
        $counts = self::Counts();
        foreach ($out as $id => $r) {
            foreach ($counts as $what => $by) {
                $out[$id][$what] = isset($by[$id]) ? $by[$id] : 0;
            }
        }
        return $out;
    }

    /**
     * How many websites, mailboxes, databases, FTP accounts, forwarders and cron jobs each account has.
     *
     * @return array<string, array<int, int>>
     */
    static function Counts()
    {
        $tables = array(
            'domains' => array('x_vhosts', 'vh_acc_fk', 'vh_deleted_ts'),
            'mailboxes' => array('x_mailboxes', 'mb_acc_fk', 'mb_deleted_ts'),
            'databases' => array('x_mysql_databases', 'my_acc_fk', 'my_deleted_ts'),
            'ftp' => array('x_ftpaccounts', 'ft_acc_fk', 'ft_deleted_ts'),
            'forwarders' => array('x_forwarders', 'fw_acc_fk', 'fw_deleted_ts'),
            'cron' => array('x_cronjobs', 'ct_acc_fk', 'ct_deleted_ts'),
        );
        $out = array();
        foreach ($tables as $what => $t) {
            // (the table and column names are this list's own, never from a request)
            $out[$what] = array();
            foreach (self::Rows("SELECT {$t[1]} AS a, COUNT(*) AS n FROM {$t[0]} WHERE {$t[2]} IS NULL GROUP BY {$t[1]}") as $r) {
                $out[$what][(int) $r['a']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /**
     * The server's bandwidth: this month, this year, ever.
     *
     * @return array{month: int, year: int, total: int, disk: int}
     */
    static function Totals()
    {
        $y = date('Y');
        return array(
            'month' => (int) self::One("SELECT COALESCE(SUM(bd_transamount_bi), 0) FROM x_bandwidth WHERE bd_month_in = :m", array(':m' => self::Month())),
            'year' => (int) self::One("SELECT COALESCE(SUM(bd_transamount_bi), 0) FROM x_bandwidth WHERE bd_month_in >= :a AND bd_month_in <= :b", array(':a' => $y . '01', ':b' => $y . '12')),
            'total' => (int) self::One("SELECT COALESCE(SUM(bd_transamount_bi), 0) FROM x_bandwidth"),
            'disk' => (int) self::One("SELECT COALESCE(SUM(bd_diskamount_bi), 0) FROM x_bandwidth WHERE bd_month_in = :m", array(':m' => self::Month())),
        );
    }

    /**
     * The server's bandwidth by month (the last twelve), for the chart.
     *
     * @return array<string, int> YYYYMM => bytes.
     */
    static function ByMonth()
    {
        $out = array();
        for ($i = 11; $i >= 0; $i--) {
            $out[date('Ym', mktime(0, 0, 0, (int) date('n') - $i, 1))] = 0;
        }
        foreach (self::Rows("SELECT bd_month_in AS m, SUM(bd_transamount_bi) AS n FROM x_bandwidth WHERE bd_month_in >= :a GROUP BY bd_month_in", array(':a' => key($out))) as $r) {
            if (isset($out[(string) $r['m']])) {
                $out[(string) $r['m']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /**
     * Use of a quota as a share (null: no quota, so no limit to measure against).
     *
     * @param int $used
     * @param int $quota
     * @return float|null
     */
    static function Share($used, $quota)
    {
        return $quota > 0 ? $used / $quota : null;
    }

    /**
     * Everything about one account (null when there is none).
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    static function Account($id)
    {
        $all = self::Accounts();
        if (!isset($all[$id])) {
            return null;
        }
        $a = $all[$id];
        $p = self::Rows("SELECT ud_fullname_vc AS name, ud_address_tx AS address, ud_postcode_vc AS postcode, ud_phone_vc AS phone FROM x_profiles WHERE ud_user_fk = :u", array(':u' => $id));
        $a['profile'] = $p ? $p[0] : array('name' => '', 'address' => '', 'postcode' => '', 'phone' => '');
        $a['domain_list'] = self::Rows("SELECT vh_name_vc AS name, vh_directory_vc AS dir, vh_type_in AS type, vh_active_in AS active, vh_enabled_in AS enabled, vh_php_version_vc AS php, vh_created_ts AS created
            FROM x_vhosts WHERE vh_acc_fk = :u AND vh_deleted_ts IS NULL ORDER BY vh_type_in, vh_name_vc", array(':u' => $id));
        $a['database_list'] = self::Rows("SELECT my_name_vc AS name, my_usedspace_bi AS size, my_created_ts AS created FROM x_mysql_databases WHERE my_acc_fk = :u AND my_deleted_ts IS NULL ORDER BY my_name_vc", array(':u' => $id));
        // (the MySQL users: names, where from and which databases; never a password)
        $a['dbuser_list'] = self::Rows("SELECT mu_id_pk AS id, mu_name_vc AS name, mu_access_vc AS access, mu_created_ts AS created FROM x_mysql_users WHERE mu_acc_fk = :u AND mu_deleted_ts IS NULL ORDER BY mu_name_vc", array(':u' => $id));
        foreach ($a['dbuser_list'] as $k => $mu) {
            $a['dbuser_list'][$k]['databases'] = array_map(function ($r) { return (string) $r['name']; }, self::Rows("SELECT d.my_name_vc AS name FROM x_mysql_dbmap m JOIN x_mysql_databases d ON d.my_id_pk = m.mm_database_fk
                WHERE m.mm_user_fk = :m AND d.my_deleted_ts IS NULL ORDER BY d.my_name_vc", array(':m' => (int) $mu['id'])));
        }
        // (DNS records, by domain)
        $a['dns_list'] = array();
        foreach (self::Rows("SELECT dn_name_vc AS domain, dn_type_vc AS type, dn_host_vc AS host, dn_target_vc AS target, dn_ttl_in AS ttl, dn_priority_in AS priority
            FROM x_dns WHERE dn_acc_fk = :u AND dn_deleted_ts IS NULL ORDER BY dn_name_vc, dn_type_vc, dn_host_vc", array(':u' => $id)) as $r) {
            $a['dns_list'][(string) $r['domain']]['domain'] = (string) $r['domain'];
            $a['dns_list'][(string) $r['domain']]['records'][] = $r;
        }
        $a['dns_list'] = array_values($a['dns_list']);
        $a['mailbox_list'] = self::Rows("SELECT mb_id_pk AS id, mb_address_vc AS address, mb_enabled_in AS enabled, mb_created_ts AS created FROM x_mailboxes WHERE mb_acc_fk = :u AND mb_deleted_ts IS NULL ORDER BY mb_address_vc", array(':u' => $id));
        $a['forwarder_list'] = self::Rows("SELECT fw_address_vc AS address, fw_destination_vc AS dest, fw_keepmessage_in AS keep FROM x_forwarders WHERE fw_acc_fk = :u AND fw_deleted_ts IS NULL ORDER BY fw_address_vc", array(':u' => $id));
        $a['alias_list'] = self::Rows("SELECT al_address_vc AS address, al_destination_vc AS dest FROM x_aliases WHERE al_acc_fk = :u AND al_deleted_ts IS NULL ORDER BY al_address_vc", array(':u' => $id));
        $a['distlist_list'] = self::Rows("SELECT dl_address_vc AS address, dl_created_ts AS created FROM x_distlists WHERE dl_acc_fk = :u AND dl_deleted_ts IS NULL ORDER BY dl_address_vc", array(':u' => $id));
        $a['ftp_list'] = self::Rows("SELECT ft_user_vc AS ftpuser, ft_directory_vc AS dir, ft_access_vc AS access, ft_created_ts AS created FROM x_ftpaccounts WHERE ft_acc_fk = :u AND ft_deleted_ts IS NULL ORDER BY ft_user_vc", array(':u' => $id));
        $a['cron_list'] = self::Rows("SELECT ct_script_vc AS script, ct_timing_vc AS timing, ct_description_tx AS description FROM x_cronjobs WHERE ct_acc_fk = :u AND ct_deleted_ts IS NULL ORDER BY ct_id_pk", array(':u' => $id));
        $a['package_list'] = self::Rows("SELECT p.pk_id_pk AS id, p.pk_name_vc AS name, p.pk_enablephp_in AS php, p.pk_created_ts AS created, q.qt_diskspace_bi AS diskq, q.qt_bandwidth_bi AS bwq,
                (SELECT COUNT(*) FROM x_accounts c WHERE c.ac_package_fk = p.pk_id_pk AND c.ac_deleted_ts IS NULL) AS used
            FROM x_packages p LEFT JOIN x_quotas q ON q.qt_package_fk = p.pk_id_pk WHERE p.pk_reseller_fk = :u AND p.pk_deleted_ts IS NULL ORDER BY p.pk_name_vc", array(':u' => $id));
        $a['client_list'] = array();
        foreach ($all as $c) {
            if ($c['reseller'] === $id && $c['id'] !== $id) {
                $a['client_list'][] = $c;
            }
        }
        $a['log_list'] = self::Rows("SELECT lg_when_ts AS t, lg_module_vc AS module, lg_detail_tx AS detail FROM x_logs WHERE lg_user_fk = :u ORDER BY lg_when_ts DESC, lg_id_pk DESC LIMIT 25", array(':u' => $id));
        $a['docker_list'] = array_values(array_filter(self::HasDocker() ? self::All('docker') : array(), function ($c) use ($id) { return (int) $c['acc'] === $id; }));
        $a['reason'] = (!$a['enabled'] && class_exists('ws_suspend') && method_exists('ws_suspend', 'Reason')) ? (string) ws_suspend::Reason($id) : '';
        return $a;
    }

    /**
     * How active an account is, by its last login: recent (7 days), active (90 days), inactive (longer), never.
     *
     * @param int $lastlogon
     * @param int|null $now
     * @return string
     */
    static function Activity($lastlogon, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        if ((int) $lastlogon <= 0) {
            return 'never';
        }
        $days = ($now - (int) $lastlogon) / 86400;
        return $days <= 7 ? 'recent' : ($days <= 90 ? 'active' : 'inactive');
    }

    /**
     * Every domain, subdomain and parked domain on the panel, with its account and that account's reseller.
     *
     * @return array<int, array<string, mixed>>
     */
    static function Domains()
    {
        $out = array();
        foreach (self::Rows("SELECT v.vh_id_pk AS id, v.vh_name_vc AS name, v.vh_directory_vc AS dir, v.vh_type_in AS type, v.vh_active_in AS active, v.vh_php_version_vc AS php,
                v.vh_created_ts AS created, a.ac_id_pk AS acc, a.ac_user_vc AS username, a.ac_enabled_in AS acc_enabled, r.ac_id_pk AS reseller, r.ac_user_vc AS resellername
            FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk LEFT JOIN x_accounts r ON r.ac_id_pk = a.ac_reseller_fk
            WHERE v.vh_deleted_ts IS NULL AND a.ac_deleted_ts IS NULL ORDER BY v.vh_name_vc") as $r) {
            foreach (array('id', 'type', 'active', 'created', 'acc', 'acc_enabled', 'reseller') as $k) {
                $r[$k] = (int) $r[$k];
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Everything of one kind on the panel, each with its account (for zGod's lists): mailboxes, forwarders, aliases,
     * distlists, databases, dbusers, ftp, cron, packages. Never a password.
     *
     * @param string $what
     * @return array<int, array<string, mixed>>
     */
    static function All($what)
    {
        $acc = " JOIN x_accounts a ON a.ac_id_pk = %s AND a.ac_deleted_ts IS NULL";
        $sql = array(
            'mailboxes' => "SELECT t.mb_address_vc AS name, t.mb_enabled_in AS enabled, t.mb_created_ts AS created, a.ac_id_pk AS acc, a.ac_user_vc AS username
                FROM x_mailboxes t" . sprintf($acc, 't.mb_acc_fk') . " WHERE t.mb_deleted_ts IS NULL ORDER BY t.mb_address_vc",
            'forwarders' => "SELECT t.fw_address_vc AS name, t.fw_destination_vc AS dest, t.fw_keepmessage_in AS keep, a.ac_id_pk AS acc, a.ac_user_vc AS username
                FROM x_forwarders t" . sprintf($acc, 't.fw_acc_fk') . " WHERE t.fw_deleted_ts IS NULL ORDER BY t.fw_address_vc",
            'aliases' => "SELECT t.al_address_vc AS name, t.al_destination_vc AS dest, a.ac_id_pk AS acc, a.ac_user_vc AS username
                FROM x_aliases t" . sprintf($acc, 't.al_acc_fk') . " WHERE t.al_deleted_ts IS NULL ORDER BY t.al_address_vc",
            'distlists' => "SELECT t.dl_address_vc AS name, t.dl_created_ts AS created, a.ac_id_pk AS acc, a.ac_user_vc AS username
                FROM x_distlists t" . sprintf($acc, 't.dl_acc_fk') . " WHERE t.dl_deleted_ts IS NULL ORDER BY t.dl_address_vc",
            'databases' => "SELECT t.my_id_pk AS id, t.my_name_vc AS name, t.my_usedspace_bi AS size, t.my_created_ts AS created, a.ac_id_pk AS acc, a.ac_user_vc AS username,
                    (SELECT COUNT(*) FROM x_mysql_dbmap m JOIN x_mysql_users u ON u.mu_id_pk = m.mm_user_fk AND u.mu_deleted_ts IS NULL WHERE m.mm_database_fk = t.my_id_pk) AS users
                FROM x_mysql_databases t" . sprintf($acc, 't.my_acc_fk') . " WHERE t.my_deleted_ts IS NULL ORDER BY t.my_name_vc",
            'dbusers' => "SELECT t.mu_id_pk AS id, t.mu_name_vc AS name, t.mu_access_vc AS access, t.mu_created_ts AS created, a.ac_id_pk AS acc, a.ac_user_vc AS username
                FROM x_mysql_users t" . sprintf($acc, 't.mu_acc_fk') . " WHERE t.mu_deleted_ts IS NULL ORDER BY t.mu_name_vc",
            'ftp' => "SELECT t.ft_user_vc AS name, t.ft_directory_vc AS dir, t.ft_access_vc AS access, t.ft_created_ts AS created, a.ac_id_pk AS acc, a.ac_user_vc AS username
                FROM x_ftpaccounts t" . sprintf($acc, 't.ft_acc_fk') . " WHERE t.ft_deleted_ts IS NULL ORDER BY t.ft_user_vc",
            'cron' => "SELECT t.ct_timing_vc AS timing, t.ct_script_vc AS name, t.ct_description_tx AS description, t.ct_created_ts AS created, a.ac_id_pk AS acc, a.ac_user_vc AS username
                FROM x_cronjobs t" . sprintf($acc, 't.ct_acc_fk') . " WHERE t.ct_deleted_ts IS NULL ORDER BY a.ac_user_vc, t.ct_id_pk",
            'packages' => "SELECT t.pk_id_pk AS id, t.pk_name_vc AS name, t.pk_enablephp_in AS php, t.pk_created_ts AS created, a.ac_id_pk AS acc, a.ac_user_vc AS username,
                    q.qt_diskspace_bi AS diskq, q.qt_bandwidth_bi AS bwq, q.qt_domains_in AS domains, q.qt_subdomains_in AS subdomains, q.qt_mailboxes_in AS mailboxes, q.qt_mysql_in AS mysql, q.qt_ftpaccounts_in AS ftp,
                    (SELECT COUNT(*) FROM x_accounts c WHERE c.ac_package_fk = t.pk_id_pk AND c.ac_deleted_ts IS NULL) AS used
                FROM x_packages t" . sprintf($acc, 't.pk_reseller_fk') . " LEFT JOIN x_quotas q ON q.qt_package_fk = t.pk_id_pk WHERE t.pk_deleted_ts IS NULL ORDER BY a.ac_user_vc, t.pk_name_vc",
        );
        if (self::HasDocker()) {
            $sql['docker'] = "SELECT t.dc_id_pk AS id, t.dc_app_vc AS app, t.dc_label_vc AS name, t.dc_domain_vc AS domain, t.dc_state_vc AS state, t.dc_cpu_in AS cpu, t.dc_mem_in AS mem,
                    t.dc_disk_in AS disk, t.dc_used_in AS used, t.dc_created_in AS created, n.nd_name_vc AS node, a.ac_id_pk AS acc, a.ac_user_vc AS username
                FROM x_dh_containers t" . sprintf($acc, 't.dc_acc_fk') . " LEFT JOIN x_dh_nodes n ON n.nd_id_pk = t.dc_node_fk WHERE t.dc_deleted_in IS NULL ORDER BY a.ac_user_vc, t.dc_app_vc";
        }
        if (!isset($sql[$what])) {
            return array();
        }
        $rows = self::Rows($sql[$what]);
        if ($what === 'dbusers') {
            foreach ($rows as $k => $u) {
                $rows[$k]['databases'] = array_map(function ($r) { return (string) $r['name']; }, self::Rows("SELECT d.my_name_vc AS name FROM x_mysql_dbmap m JOIN x_mysql_databases d ON d.my_id_pk = m.mm_database_fk
                    WHERE m.mm_user_fk = :m AND d.my_deleted_ts IS NULL ORDER BY d.my_name_vc", array(':m' => (int) $u['id'])));
            }
        }
        return $rows;
    }

    /**
     * Whether Docker Hosting (the add-on) is on this panel: its table is there.
     *
     * @return bool
     */
    static function HasDocker()
    {
        try {
            self::One("SELECT COUNT(*) FROM x_dh_containers WHERE 1 = 0");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Whether the panel's Shadowing module is there and on (zGod's Shadow button uses it, with its own checks and log).
     *
     * @return bool
     */
    static function CanShadow()
    {
        return (string) self::One("SELECT mo_enabled_en FROM x_modules WHERE mo_folder_vc = 'shadowing'") === 'true';
    }

    /**
     * The resellers (and administrators) with clients, with their clients' totals.
     *
     * @return array<int, array<string, mixed>>
     */
    static function Resellers()
    {
        $all = self::Accounts();
        $out = array();
        foreach ($all as $a) {
            if ($a['clients'] === 0 && $a['grp'] !== 2) {
                continue;
            }
            $r = $a;
            $r['active'] = $r['off'] = $r['client_disk'] = $r['client_bw'] = $r['client_domains'] = $r['client_mailboxes'] = 0;
            foreach ($all as $c) {
                if ($c['reseller'] === $a['id'] && $c['id'] !== $a['id']) {
                    $r[$c['enabled'] ? 'active' : 'off']++;
                    $r['client_disk'] += $c['disk'];
                    $r['client_bw'] += $c['bw'];
                    $r['client_domains'] += $c['domains'];
                    $r['client_mailboxes'] += $c['mailboxes'];
                }
            }
            $r['packages'] = (int) self::One("SELECT COUNT(*) FROM x_packages WHERE pk_reseller_fk = :u AND pk_deleted_ts IS NULL", array(':u' => $a['id']));
            $out[] = $r;
        }
        return $out;
    }

    /**
     * A time from the panel's tables as a Unix time: x_logs keeps a TIMESTAMP ('2026-11-23 10:40:00'), others a number.
     *
     * @param mixed $v
     * @return int
     */
    static function When($v)
    {
        if (is_numeric($v)) {
            return (int) $v;
        }
        $t = strtotime((string) $v);
        return $t === false ? 0 : $t;
    }

    /**
     * Note what zGod did in the panel's log (System Log Viewer shows it).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $by The administrator.
     * @param string $text
     * @return void
     */
    static function Log($by, $text)
    {
        global $zdbh;
        // (the time is the database's own: lg_when_ts is a TIMESTAMP that fills itself, as the panel's logger leaves it)
        $zdbh->prepare("INSERT INTO x_logs (lg_user_fk, lg_code_vc, lg_module_vc, lg_detail_tx, lg_stack_tx) VALUES (:u, 'zgod', 'zgod', :d, '')")
            ->execute(array(':u' => (int) $by, ':d' => (string) $text));
    }

    /**
     * A hook of the panel's (as its own modules run them), when the panel has hooks.
     *
     * @param string $name
     * @return void
     */
    static function Hook($name)
    {
        if (class_exists('runtime_hook') && method_exists('runtime_hook', 'Execute')) {
            runtime_hook::Execute($name);
        }
    }

    /**
     * Switch an account off (suspend) or on, as Manage Clients does: never an administrator, never
     * the one doing it, never a deleted account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @param bool $on
     * @param int $by The administrator.
     * @param string $reason Why (kept by the panel's suspension record when it has one).
     * @return string '' when done, else why not (English).
     */
    static function SetAccount($id, $on, $by, $reason = '')
    {
        global $zdbh;
        $a = self::Rows("SELECT ac_id_pk, ac_user_vc, ac_group_fk, ac_enabled_in FROM x_accounts WHERE ac_id_pk = :u AND ac_deleted_ts IS NULL", array(':u' => (int) $id));
        if (!$a) {
            return 'There is no such account.';
        }
        $a = $a[0];
        if ((int) $a['ac_group_fk'] === self::ADMINS || (int) $a['ac_id_pk'] === (int) $by) {
            return 'An administrator account (and your own) can not be suspended here.';
        }
        if ((bool) (int) $a['ac_enabled_in'] === $on) {
            return '';
        }
        if (!$on && $reason !== '' && class_exists('ws_suspend') && method_exists('ws_suspend', 'Clean')) {
            $reason = ws_suspend::Clean($reason);
            if ($reason === false) {
                return 'The reason: one line of up to 200 characters, without < or >.';
            }
        }
        self::Hook($on ? 'OnBeforeEnableClient' : 'OnBeforeDisableClient');
        $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in = :e WHERE ac_id_pk = :u")->execute(array(':e' => $on ? 1 : 0, ':u' => (int) $id));
        if (class_exists('ws_suspend')) {
            if ($on && method_exists('ws_suspend', 'Clear')) {
                ws_suspend::Clear((int) $id);
            } elseif (!$on && $reason !== '' && method_exists('ws_suspend', 'Set')) {
                ws_suspend::Set((int) $id, $reason, (int) $by);
            }
        }
        self::Hook($on ? 'OnAfterEnableClient' : 'OnAfterDisableClient');
        self::Log($by, ($on ? 'Account switched on: ' : 'Account suspended: ') . $a['ac_user_vc'] . ($reason !== '' && !$on ? ' (' . $reason . ')' : ''));
        return '';
    }

    /**
     * Switch a mailbox off or on, as Mailboxes does.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id The mailbox.
     * @param bool $on
     * @param int $by The administrator.
     * @return string '' when done, else why not (English).
     */
    static function SetMailbox($id, $on, $by)
    {
        global $zdbh;
        $m = self::Rows("SELECT mb_address_vc, mb_enabled_in FROM x_mailboxes WHERE mb_id_pk = :m AND mb_deleted_ts IS NULL", array(':m' => (int) $id));
        if (!$m) {
            return 'There is no such mailbox.';
        }
        if ((bool) (int) $m[0]['mb_enabled_in'] === $on) {
            return '';
        }
        self::Hook($on ? 'OnBeforeEnableMailbox' : 'OnBeforeDisableMailbox');
        $zdbh->prepare("UPDATE x_mailboxes SET mb_enabled_in = :e WHERE mb_id_pk = :m")->execute(array(':e' => $on ? 1 : 0, ':m' => (int) $id));
        self::Hook($on ? 'OnAfterEnableMailbox' : 'OnAfterDisableMailbox');
        self::Log($by, ($on ? 'Mailbox switched on: ' : 'Mailbox switched off: ') . $m[0]['mb_address_vc']);
        return '';
    }

    /**
     * Bytes in words (1.5 GB).
     *
     * @param int|float $b
     * @return string
     */
    static function Size($b)
    {
        $b = (float) $b;
        foreach (array('bytes', 'KB', 'MB', 'GB', 'TB') as $i => $u) {
            if ($b < 1024 || $u === 'TB') {
                return $i === 0 ? (int) $b . ' ' . $u : number_format($b, $b < 10 ? 1 : 0) . ' ' . $u;
            }
            $b /= 1024;
        }
        return '';
    }

    /**
     * The daily report: what changed and what needs a look, as HTML (null when there is nothing at all
     * on the panel).
     *
     * @param int|null $now
     * @return array{subject: string, html: string}
     */
    static function Report($now = null)
    {
        $now = $now === null ? time() : $now;
        $all = self::Accounts();
        $t = self::Totals();
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $new = $near = $off = array();
        foreach ($all as $a) {
            if ($a['created'] >= $now - 86400) {
                $new[] = $a;
            }
            if (!$a['enabled']) {
                $off[] = $a;
            }
            foreach (array('disk' => 'diskq', 'bw' => 'bwq') as $u => $q) {
                $s = self::Share($a[$u], $a[$q]);
                if ($s !== null && $s >= 0.9) {
                    $near[] = array($a, $u === 'disk' ? 'disk' : 'bandwidth', $s);
                }
            }
        }
        $top = $all;
        usort($top, function ($x, $y) { return $y['bw'] - $x['bw']; });
        $top = array_slice(array_filter($top, function ($a) { return $a['bw'] > 0; }), 0, 5);
        $row = function (array $cells) use ($h) {
            return '<tr>' . implode('', array_map(function ($c) use ($h) { return '<td style="padding:4px 10px;border-bottom:1px solid #e6e9ee;color:#1f2933;background:#ffffff">' . $h($c) . '</td>'; }, $cells)) . '</tr>';
        };
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2933">'
            . '<h2 style="margin:0 0 6px">zGod daily report</h2><p style="margin:0 0 14px;color:#6b7785">' . $h(date('l, j F Y', $now)) . '</p>'
            . '<p>' . $h(count($all)) . ' accounts. Bandwidth this month: <b>' . $h(self::Size($t['month'])) . '</b> (this year ' . $h(self::Size($t['year'])) . '); disk used: <b>' . $h(self::Size($t['disk'])) . '</b>.</p>';
        $html .= '<h3>New in the last day</h3>' . ($new ? '<table>' . implode('', array_map(function ($a) use ($row) { return $row(array($a['username'], $a['package'], 'by ' . $a['resellername'])); }, $new)) . '</table>' : '<p>None.</p>');
        $html .= '<h3>At 90% of a quota or more</h3>' . ($near ? '<table>' . implode('', array_map(function ($n) use ($row) { return $row(array($n[0]['username'], $n[1], round($n[2] * 100) . '%')); }, $near)) . '</table>' : '<p>None.</p>');
        $html .= '<h3>Suspended</h3>' . ($off ? '<table>' . implode('', array_map(function ($a) use ($row) { return $row(array($a['username'], 'by ' . $a['resellername'])); }, $off)) . '</table>' : '<p>None.</p>');
        $html .= '<h3>Most bandwidth this month</h3>' . ($top ? '<table>' . implode('', array_map(function ($a) use ($row) { return $row(array($a['username'], zgod::Size($a['bw']))); }, $top)) . '</table>' : '<p>None yet.</p>');
        $html .= '<p style="color:#6b7785;font-size:12px;margin-top:18px">Sent by zGod on your Sentora panel (Server Admin, zGod, Daily report, to change or stop it).</p></div>';
        $nearAccounts = count(array_unique(array_map(function ($n) { return $n[0]['id']; }, $near)));
        $subject = 'zGod: ' . count($all) . ' accounts, ' . count($new) . ' new, ' . $nearAccounts . ' near a quota, ' . count($off) . ' suspended';
        return array('subject' => $subject, 'html' => $html);
    }

    /**
     * Where the report goes: the addresses chosen, else every administrator's.
     *
     * @return array<int, string>
     */
    static function ReportTo()
    {
        $to = array_values(array_filter(preg_split('/[\s,;]+/', self::Get('report_to')), function ($a) { return filter_var($a, FILTER_VALIDATE_EMAIL) !== false; }));
        if ($to) {
            return $to;
        }
        $out = array();
        foreach (self::Rows("SELECT ac_email_vc AS e FROM x_accounts WHERE ac_group_fk = :g AND ac_deleted_ts IS NULL AND ac_enabled_in = 1", array(':g' => self::ADMINS)) as $r) {
            if (filter_var((string) $r['e'], FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = (string) $r['e'];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Send the daily report, when it is switched on (the daemon, once a day).
     *
     * @param callable|null $send function(array $to, string $subject, string $html): bool (tests).
     * @return string What happened, for the daemon's log.
     */
    static function DailyReport($send = null)
    {
        if (self::Get('report', 'off') !== 'on') {
            return '';
        }
        $to = self::ReportTo();
        if (!$to) {
            return 'zGod: no address for the daily report (set one on its page)';
        }
        $r = self::Report();
        if ($send === null) {
            $send = function (array $to, $subject, $html) {
                $m = new sys_email();
                $m->Subject = $subject;
                $m->Body = $html;
                $m->IsHTML(true);
                foreach ($to as $a) {
                    $m->AddAddress($a);
                }
                return (bool) $m->SendEmail();
            };
        }
        $ok = $send($to, $r['subject'], $r['html']);
        self::Set('report_last', (string) time() . ($ok ? '' : ' failed'));
        return $ok ? 'zGod: daily report sent to ' . implode(', ', $to) : 'zGod: the daily report could not be sent (see the panel\'s mail settings)';
    }
    /**
     * zGod's lines for the Elk node (Log Forwarder sends the panel's zgod.log there, kind "zgod"; the node's zGod
     * dashboard reads them): each account's figures once an hour, the server's, and what was done since the last run
     * (from the panel's log). One JSON object per line; never an email address, a password or a mailbox's address.
     *
     * @param int $now
     * @param bool $hourly The figures too (once an hour), not only what was done.
     * @return string[]
     */
    static function ElkLines($now, $hourly)
    {
        $out = array();
        $ts = gmdate('Y-m-d\TH:i:s\Z', $now);
        $users = array();
        $accounts = self::Accounts();
        foreach ($accounts as $a) {
            $users[$a['id']] = $a['username'];
        }
        if ($hourly) {
            $n = $off = $disk = $bw = 0;
            foreach ($accounts as $a) {
                $pct = function ($u, $q) { return $q > 0 ? round(min(999, $u * 100 / $q), 1) : null; };
                $out[] = array('@timestamp' => $ts, 'type' => 'account', 'user' => $a['username'], 'reseller' => (string) $a['resellername'],
                    'package' => (string) $a['package'], 'group' => (string) $a['groupname'], 'enabled' => (bool) $a['enabled'],
                    'disk' => $a['disk'], 'disk_quota' => $a['diskq'], 'disk_pct' => $pct($a['disk'], $a['diskq']),
                    'bandwidth' => $a['bw'], 'bandwidth_quota' => $a['bwq'], 'bandwidth_pct' => $pct($a['bw'], $a['bwq']),
                    'domains' => $a['domains'], 'mailboxes' => $a['mailboxes'], 'databases' => $a['databases'], 'ftp' => $a['ftp'],
                    'clients' => $a['clients'], 'last_login' => $a['lastlogon'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $a['lastlogon']) : null);
                $n++;
                $off += $a['enabled'] ? 0 : 1;
                $disk += $a['disk'];
                $bw += $a['bw'];
            }
            $out[] = array('@timestamp' => $ts, 'type' => 'server', 'accounts' => $n, 'suspended' => $off, 'disk' => $disk, 'bandwidth' => $bw,
                'resellers' => count(array_filter($accounts, function ($a) { return $a['clients'] > 0; })));
        }
        $last = (int) self::Get('elk_log_id', '-1');
        if ($last < 0) {
            // (the first run: from now on, not the whole past)
            $last = (int) self::One("SELECT COALESCE(MAX(lg_id_pk), 0) FROM x_logs");
        }
        $what = array('Account suspended: ' => 'suspend', 'Account switched on: ' => 'unsuspend',
            'Mailbox switched off: ' => 'mailbox-off', 'Mailbox switched on: ' => 'mailbox-on');
        foreach (self::Rows("SELECT lg_id_pk, lg_user_fk, lg_detail_tx, lg_when_ts FROM x_logs WHERE lg_module_vc = 'zgod' AND lg_id_pk > :l ORDER BY lg_id_pk LIMIT 500", array(':l' => $last)) as $r) {
            $last = max($last, (int) $r['lg_id_pk']);
            foreach ($what as $pre => $act) {
                if (strpos((string) $r['lg_detail_tx'], $pre) !== 0) {
                    continue;
                }
                $rest = substr((string) $r['lg_detail_tx'], strlen($pre));
                $line = array('@timestamp' => gmdate('Y-m-d\TH:i:s\Z', self::When($r['lg_when_ts'])), 'type' => 'action', 'action' => $act,
                    'by' => isset($users[(int) $r['lg_user_fk']]) ? $users[(int) $r['lg_user_fk']] : '');
                if ($act === 'suspend' || $act === 'unsuspend') {
                    $m = array();
                    preg_match('/^(\S+)(?: \((.*)\))?$/s', $rest, $m);
                    $line['user'] = isset($m[1]) ? $m[1] : '';
                    if (!empty($m[2])) {
                        $line['reason'] = function_exists('mb_substr') ? mb_substr($m[2], 0, 200) : substr($m[2], 0, 200);
                    }
                } else {
                    // (the mailbox's account, not its address)
                    $line['user'] = (string) self::One("SELECT a.ac_user_vc FROM x_mailboxes m JOIN x_accounts a ON a.ac_id_pk = m.mb_acc_fk WHERE m.mb_address_vc = :m", array(':m' => $rest));
                }
                $out[] = $line;
            }
        }
        self::Set('elk_log_id', (string) $last);
        return array_map(function ($l) { return json_encode($l, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); }, $out);
    }

    /**
     * The daemon's run (every few minutes): zGod's lines into the panel's zgod.log (root's, 640; kept under 5 MB,
     * the older half in zgod.log.1), the figures once an hour.
     *
     * @param string $file The log file (the panel's log folder + zgod.log).
     * @param int|null $now
     * @return string What happened, for the daemon's log ('' when nothing).
     */
    static function ElkRun($file, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $hour = gmdate('YmdH', $now);
        $hourly = self::Get('elk_hour') !== $hour;
        $lines = self::ElkLines($now, $hourly);
        if ($hourly) {
            self::Set('elk_hour', $hour);
        }
        if (!$lines) {
            return '';
        }
        $dir = dirname($file);
        if (!is_dir($dir) || is_link($file)) {
            return 'zGod: no log folder for its lines (' . $dir . ')';
        }
        clearstatcache();
        if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
            @rename($file, $file . '.1');
        }
        $new = !is_file($file);
        $ok = @file_put_contents($file, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX) !== false;
        if ($new && $ok) {
            @chmod($file, 0640);
        }
        return $ok ? ($hourly ? 'zGod: ' . count($lines) . ' lines for the Elk node' : '') : 'zGod: could not write ' . $file;
    }
}
