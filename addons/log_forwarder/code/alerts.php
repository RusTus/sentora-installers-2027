<?php

/**
 * Log Forwarder's Elk node: its alerts as notices to the administrators, and an email to them. On every daemon run
 * (while paired and "Alerts" is chosen) the daemon asks the node on port 6515, over the same pinned connection as the
 * logs (the panel's certificate; the node's authority pinned): a website down, a certificate running out, a full disk,
 * mail piling up, a burst of failed logins or of requests the web firewall stopped, many bounces, a busy CPU, the logs
 * gone quiet. Each alert is a notice while it lasts (taken away when it clears) and a mail when it starts (again
 * after a day while it lasts). What the node sends is checked and cut to size: names and words only, no links but
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
    const STATE = 'alerts.json';

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
        $sent = array();
        $mail = array();
        foreach ($got['alerts'] as $a) {
            $board = $a['board'] !== '' ? $a['board'] : 'sentora-overview';
            ctrl_notices::Send(array('scope' => 'groups', 'targets' => array(1), 'type' => $a['level'], 'from' => 0, 'source' => 'log_forwarder',
                'key' => self::KEY . md5($a['id']), 'closable' => 'login', 'title' => mb_substr('Elk node: ' . $a['title'], 0, 120, 'UTF-8'),
                'body' => $a['text'] . "\n\n[See it in Kibana](https://" . $host . '/app/dashboards#/view/' . $board . ')'));
            $last = isset($had[$a['id']]) ? (int) $had[$a['id']] : 0;
            if ($last < time() - 86400) {
                $mail[] = $a;
                $sent[$a['id']] = time();
            } else {
                $sent[$a['id']] = $last;
            }
        }
        $cleared = 0;
        foreach (array_keys($had) as $id) {
            if (!isset($sent[$id])) {
                ctrl_notices::Withdraw(self::KEY . md5($id), 0);
                $cleared++;
            }
        }
        $out['sent'] = $sent;
        $mailed = $mail ? self::Mail($mail, $host) : 0;
        elk::WriteFile($file, json_encode($out, JSON_UNESCAPED_SLASHES), 0640, 'sentora');
        if (!$got['alerts'] && !$cleared) {
            return '';
        }
        return 'alerts: ' . count($got['alerts']) . ' now' . ($cleared ? ', ' . $cleared . ' cleared' : '') . ($mailed ? ', mailed ' . $mailed . ' administrator(s)' : '') . '.';
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
        foreach (array('fortigate', 'veeam', 'm365') as $k) {
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
            . "Each one is also a notice in the panel until it clears; this mail comes again after a day while it lasts.\n";
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
