<?php

/**
 * Log Forwarder's Elk node: its alerts as notices to the administrators, and an email to them. On every daemon run
 * (while paired and "Alerts" is chosen) the daemon asks the node on port 6515, over the same pinned connection as the
 * logs (the panel's certificate; the node's authority pinned): a website down, a certificate running out, a full disk,
 * mail piling up, a burst of failed logins or of requests the web firewall stopped, many bounces, a busy CPU, the logs
 * gone quiet. What needs a look is one notice (it comes back after a close only when something new starts) and a mail
 * when an alert starts (again after a day while it lasts); the administrators choose per kind (notice and mail, notice
 * only, off) and can mute one alert (Log Forwarder's Alerts card). What the node sends is checked and cut to size: names and words only, no links but
 * the one to its own Kibana board made here.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-20
 */
class elk_alerts
{
    const PORT = 6515;
    const KEY = 'elk-alert-';
    const NOTICE = 'elk-alerts';
    const STATE = 'alerts.json';
    /** The administrators' choices (x_settings, hidden): {"kinds": {kind: "both"|"notice"|"off"}, "mute": {id: {t, title}}}. */
    const S_RULES = 'elk_alert_rules';

    /**
     * Every kind of alert the node sends (the part of its id before ":"), by source, with a name.
     *
     * @return array<string, array<string, string>>
     */
    static function Kinds()
    {
        return array(
            'This panel' => array('site-down' => 'A website does not answer', 'cert' => 'A certificate runs out soon', 'disk-root' => 'The system disk fills up',
                'disk-sites' => 'The websites\' disk fills up', 'cpu' => 'The CPU stays busy', 'mailq' => 'Mail piles up', 'bounces' => 'Many mails bounce',
                'logins' => 'Many failed logins (SSH, panel, mail, FTP)', 'waf' => 'The web firewall stops many requests', 'quiet' => 'The panel\'s logs go quiet',
                'attacker' => 'An address attacks in several places'),
            'FortiGate' => array('fortigate' => 'Many failed VPN or admin logins'),
            'Veeam' => array('veeam' => 'A backup job failed', 'backup-stale' => 'A machine has no recent backup', 'repo-full' => 'A repository fills up'),
            'Microsoft 365' => array('office-new' => 'A sign-in from a new country', 'office-travel' => 'Sign-ins too far apart to travel'),
            'Active Directory' => array('ad-lockout' => 'An account is locked out', 'ad-guess' => 'Many failed logons for one account', 'ad-admin' => 'Someone added to an admin group',
                'ad-cleared' => 'A security log was cleared', 'ad-quiet' => 'A domain controller goes quiet'),
            'VMware vSphere' => array('vsphere-host' => 'A host is red', 'vsphere-alarm' => 'A red alarm in vCenter', 'vsphere-ds' => 'A datastore fills up',
                'vsphere-busy' => 'A host is busy', 'vsphere-snap' => 'An old snapshot', 'vsphere-quiet' => 'vCenter goes quiet'),
        );
    }

    /**
     * The administrators' choices, checked.
     *
     * @return array{kinds: array<string, string>, mute: array<string, array{t: int, title: string}>}
     */
    static function Rules()
    {
        $j = json_decode(elk::Setting(self::S_RULES), true);
        $r = array('kinds' => array(), 'mute' => array());
        if (is_array($j)) {
            foreach ((array) ($j['kinds'] ?? array()) as $k => $v) {
                if (is_string($k) && preg_match('/^[a-z-]{1,20}$/', $k) && in_array($v, array('notice', 'off'), true)) {
                    $r['kinds'][$k] = $v;
                }
            }
            foreach ((array) ($j['mute'] ?? array()) as $id => $m) {
                if (is_string($id) && preg_match('/^[a-z-]{1,20}:[a-z0-9.-]{1,253}$/', $id) && is_array($m)) {
                    $r['mute'][$id] = array('t' => (int) ($m['t'] ?? 0), 'title' => mb_substr((string) ($m['title'] ?? ''), 0, 120, 'UTF-8'));
                }
            }
        }
        return $r;
    }

    /**
     * Save the choices (the page; administrators only).
     *
     * @param array $r As Rules() gives.
     * @return void
     */
    static function SaveRules(array $r)
    {
        $mute = isset($r['mute']) && is_array($r['mute']) ? array_slice($r['mute'], -500, 500, true) : array();
        elk::Save(self::S_RULES, json_encode(array('kinds' => (object) ($r['kinds'] ?? array()), 'mute' => (object) $mute), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * What happens to an alert: 'both' (notice and mail), 'notice', 'off' (kind switched off) or 'muted'.
     *
     * @param string $id The alert's id.
     * @param array $rules From Rules().
     * @return string
     */
    static function Mode($id, array $rules)
    {
        if (isset($rules['mute'][$id])) {
            return 'muted';
        }
        $kind = strstr($id, ':', true);
        return isset($rules['kinds'][$kind]) ? $rules['kinds'][$kind] : 'both';
    }

    /**
     * Root: fetch the node's alerts and bring the notices and the mail up to date.
     *
     * @return string '' or what it did, for the daemon log.
     */
    static function Run()
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return '';
        }
        $file = elk::STATE_DIR . '/' . self::STATE;
        $st = json_decode((string) @file_get_contents($file), true);
        $st = is_array($st) ? $st : array();
        $had = isset($st['sent']) && is_array($st['sent']) ? $st['sent'] : array();
        if (!elk::Paired() || !in_array('alerts', elk::Chosen(), true)) {
            // switched off or no node: the notices go
            foreach (array_keys($had) as $id) {
                ctrl_notices::Withdraw(self::KEY . md5($id), 0);
            }
            ctrl_notices::Withdraw(self::NOTICE, 0);
            if ($had || $st) {
                @unlink($file);
            }
            return '';
        }
        $got = self::Fetch();
        $out = array('time' => time(), 'reach' => $got !== null, 'sent' => $had, 'alerts' => array(), 'ok' => false);
        if ($got === null) {
            // (the node away, or not updated yet: the notices stay as they were)
            $out['alerts'] = isset($st['alerts']) && is_array($st['alerts']) ? $st['alerts'] : array();
            elk::WriteFile($file, json_encode($out, JSON_UNESCAPED_SLASHES), 0640, 'sentora');
            return 'alerts: the node did not answer on port ' . self::PORT . '.';
        }
        $out['ok'] = $got['ok'];
        $out['alerts'] = $got['alerts'];
        $out['sources'] = $got['sources'];
        $node = elk::Node();
        $host = filter_var($node['addr'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $node['addr'] . ']' : $node['addr'];
        $rules = self::Rules();
        $sent = array();
        $mail = array();
        $show = array();
        foreach ($got['alerts'] as $a) {
            $mode = self::Mode($a['id'], $rules);
            if ($mode === 'off' || $mode === 'muted') {
                continue;
            }
            $show[] = $a;
            $last = isset($had[$a['id']]) ? (int) $had[$a['id']] : 0;
            if ($mode === 'both' && $last < time() - 86400) {
                $mail[] = $a;
                $sent[$a['id']] = time();
            } else {
                $sent[$a['id']] = $last;
            }
        }
        // (the one-notice-per-alert notices of Log Forwarder 211 and older go, once)
        if (empty($st['one'])) {
            foreach (array_keys($had) as $id) {
                ctrl_notices::Withdraw(self::KEY . md5($id), 0);
            }
        }
        $out['one'] = 1;
        $before = isset($st['shown']) && is_array($st['shown']) ? $st['shown'] : array();
        $out['shown'] = self::Notice($show, $host, $before);
        $cleared = count(array_diff(array_keys($had), array_keys($sent)));
        $out['sent'] = $sent;
        $mailed = $mail ? self::Mail($mail, $host) : 0;
        elk::WriteFile($file, json_encode($out, JSON_UNESCAPED_SLASHES), 0640, 'sentora');
        if (!$got['alerts'] && !$cleared) {
            return '';
        }
        return 'alerts: ' . count($got['alerts']) . ' now, ' . count($show) . ' shown' . ($cleared ? ', ' . $cleared . ' cleared' : '') . ($mailed ? ', mailed ' . $mailed . ' administrator(s)' : '') . '.';
    }

    /**
     * The one notice for the administrators: what needs a look now, outages first. It comes back after a close only when
     * a new alert starts; while the same ones go on (their figures change) or some clear, its text is brought up to date
     * quietly, so a closed notice stays closed. None left: it goes.
     *
     * @param array $show The alerts to show (from Fetch(), the choices applied).
     * @param string $host The node's address (for the Kibana link).
     * @param array $before The alert ids the notice showed when it last came back.
     * @return array<int, string> The ids it shows (kept for the next run).
     */
    static function Notice(array $show, $host, array $before)
    {
        global $zdbh;
        if (!$show) {
            ctrl_notices::Withdraw(self::NOTICE, 0);
            return array();
        }
        usort($show, function ($x, $y) { return array($x['level'] === 'outage' ? 0 : 1, $x['title']) <=> array($y['level'] === 'outage' ? 0 : 1, $y['title']); });
        $ids = array_map(function ($a) { return $a['id']; }, $show);
        $down = count(array_filter($show, function ($a) { return $a['level'] === 'outage'; }));
        $lines = array();
        foreach (array_slice($show, 0, 12) as $a) {
            $lines[] = '- ' . ($a['level'] === 'outage' ? '**' . $a['title'] . '**' : $a['title']);
        }
        if (count($show) > 12) {
            $lines[] = '- ' . 'and ' . (count($show) - 12) . ' more';
        }
        $title = count($show) === 1 ? 'Elk node: ' . $show[0]['title']
            : 'Elk node: ' . count($show) . ' things need a look';
        $body = implode("\n", $lines) . "\n\n[See them all, mute one or choose which alerts show](./?module=log_forwarder&elk=alerts)"
            . ' · [Kibana](https://' . $host . '/app/dashboards#/view/sentora-overview)';
        $type = $down ? 'outage' : 'warning';
        $title = mb_substr($title, 0, 120, 'UTF-8');
        $new = array_diff($ids, $before);
        $q = $zdbh->prepare("SELECT nt_id_pk, nt_type_vc FROM x_notices WHERE nt_key_vc = :k AND nt_acc_fk = 0 AND nt_deleted_ts IS NULL ORDER BY nt_id_pk DESC LIMIT 1");
        $q->execute(array(':k' => self::NOTICE));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $new || ($type === 'outage' && $row['nt_type_vc'] !== 'outage')) {
            ctrl_notices::Send(array('scope' => 'groups', 'targets' => array(1), 'type' => $type, 'from' => 0, 'source' => 'log_forwarder',
                'key' => self::NOTICE, 'closable' => true, 'title' => $title, 'body' => $body));
            return $ids;
        }
        // (nothing new: the same notice, its words up to date, its version kept)
        $zdbh->prepare("UPDATE x_notices SET nt_title_vc = :t, nt_body_tx = :b, nt_type_vc = :y WHERE nt_id_pk = :id")
            ->execute(array(':t' => $title, ':b' => $body, ':y' => $type, ':id' => (int) $row['nt_id_pk']));
        return array_values(array_intersect($before, $ids)) ?: $ids;
    }

    /**
     * The node's answer, checked (null when it did not answer or answered nonsense).
     *
     * @return array{ok: bool, alerts: array<int, array{id: string, level: string, title: string, text: string, board: string, since: int}>}|null
     */
    static function Fetch()
    {
        $node = elk::Node();
        $ctx = stream_context_create(array('ssl' => array('local_cert' => elk::DIR . '/panel.crt', 'local_pk' => elk::DIR . '/panel.key',
            'cafile' => elk::DIR . '/node-ca.crt', 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => elk::NODE_NAME, 'allow_self_signed' => false)));
        $host = filter_var($node['addr'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $node['addr'] . ']' : $node['addr'];
        $c = @stream_socket_client('tls://' . $host . ':' . self::PORT, $no, $str, 10, STREAM_CLIENT_CONNECT, $ctx);
        if ($c === false) {
            return null;
        }
        stream_set_timeout($c, 15);
        $data = '';
        while (!feof($c) && strlen($data) < 1048576) {
            $part = fread($c, 65536);
            if ($part === false || ($part === '' && stream_get_meta_data($c)['timed_out'])) {
                break;
            }
            $data .= $part;
        }
        fclose($c);
        $d = json_decode($data, true);
        if (!is_array($d) || !isset($d['alerts']) || !is_array($d['alerts'])) {
            return null;
        }
        $clean = function ($s, $n) {
            // plain words: no control characters, no [ ] (a notice reads [text](address) as a link), no markup
            $s = preg_replace('/[\x00-\x1F\x7F<>\[\]`*|\\\\]/u', ' ', (string) $s);
            // (and no web addresses: a notice makes http(s):// ones links; the only link is the Kibana one made here)
            $s = preg_replace('#https?:/+#i', '', $s);
            return trim(mb_substr(preg_replace('/\s+/u', ' ', (string) $s), 0, $n, 'UTF-8'));
        };
        $alerts = array();
        foreach (array_slice($d['alerts'], 0, 200) as $a) {
            if (!is_array($a) || !isset($a['id'], $a['title']) || !is_string($a['id']) || !preg_match('/^[a-z-]{1,20}:[a-z0-9.-]{1,253}$/', $a['id'])) {
                continue;
            }
            $board = isset($a['board']) && is_string($a['board']) && preg_match('/^sentora-[a-z]{1,30}$/', $a['board']) ? $a['board'] : '';
            $alerts[] = array('id' => $a['id'], 'level' => isset($a['level']) && $a['level'] === 'outage' ? 'outage' : 'warning',
                'title' => $clean($a['title'], 110), 'text' => $clean(isset($a['text']) ? $a['text'] : '', 600), 'board' => $board,
                'since' => isset($a['since']) ? (int) $a['since'] : time());
        }
        $src = array();
        foreach (array('fortigate', 'veeam', 'm365', 'windows', 'vsphere') as $k) {
            if (isset($d['sources'][$k]) && in_array($d['sources'][$k], array('on', 'off'), true)) {
                $src[$k] = $d['sources'][$k];
            }
        }
        return array('ok' => !empty($d['ok']), 'alerts' => $alerts, 'sources' => $src);
    }

    /**
     * Mail the administrators the new alerts (one mail).
     *
     * @param array $alerts From Fetch().
     * @param string $host The node's address (for the Kibana link).
     * @return int How many administrators were mailed.
     */
    static function Mail(array $alerts, $host)
    {
        global $zdbh;
        if (!class_exists('sys_email')) {
            return 0;
        }
        $to = $zdbh->query("SELECT ac_email_vc FROM x_accounts WHERE ac_group_fk = 1 AND ac_deleted_ts IS NULL AND ac_enabled_in = 1 AND ac_email_vc <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $to = array_values(array_filter($to, function ($a) { return filter_var($a, FILTER_VALIDATE_EMAIL) !== false; }));
        if (!$to) {
            return 0;
        }
        $lines = array();
        foreach ($alerts as $a) {
            $lines[] = '- ' . ($a['level'] === 'outage' ? '[DOWN] ' : '') . $a['title'] . "\n  " . $a['text'];
        }
        $m = new sys_email();
        $m->Subject = 'Elk node: ' . (count($alerts) === 1 ? $alerts[0]['title'] : count($alerts) . ' things need a look');
        $m->Body = "Hello,\n\nThe Elk node found:\n\n" . implode("\n", $lines) . "\n\nKibana: https://" . $host . "/\n"
            . "They are also in the notice at the top of the panel until they clear; this mail comes again after a day while one lasts.\n"
            . "Log Forwarder's Alerts card chooses which kinds come by mail, and mutes one.\n";
        foreach ($to as $a) {
            $m->AddAddress($a);
        }
        $m->SendEmail();
        return count($to);
    }

    /**
     * For the page: what the daemon last fetched.
     *
     * @return array
     */
    static function State()
    {
        $j = json_decode((string) @file_get_contents(elk::STATE_DIR . '/' . self::STATE), true);
        return is_array($j) ? $j : array();
    }
}
