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
        $a['reason'] = (!$a['enabled'] && class_exists('ws_suspend') && method_exists('ws_suspend', 'Reason')) ? (string) ws_suspend::Reason($id) : '';
        return $a;
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
        $zdbh->prepare("INSERT INTO x_logs (lg_user_fk, lg_code_vc, lg_module_vc, lg_detail_tx, lg_stack_tx, lg_when_ts) VALUES (:u, 'zgod', 'zgod', :d, '', :t)")
            ->execute(array(':u' => (int) $by, ':d' => (string) $text, ':t' => time()));
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
}
