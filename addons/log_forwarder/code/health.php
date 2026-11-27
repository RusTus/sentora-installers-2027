<?php

/**
 * Log Forwarder's Elk node: the panel's health, on every daemon run (every 5 minutes), while the Elk node is paired and
 * "Server health" is chosen. One line to the node over the same pinned connection as the logs: CPU, load, memory,
 * swap, disk, network, Apache's workers and requests, MySQL's connections and queries, the mail queue. Read from the
 * kernel (/proc) and the services themselves, the same on every system; the rates need the last run's figures, kept in
 * /var/lib/sentora/elk/health-last.json. A run the node does not answer is simply left out.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-20
 */
class elk_health
{

    /**
     * Root: gather the figures and send them (once a run; not more often than every 4 minutes).
     *
     * @return string '' or what it did, for the daemon log.
     */
    static function Run()
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !elk::Paired() || !in_array('health', elk::Chosen(), true)) {
            return '';
        }
        $h = self::Figures();
        if ($h === null) {
            return '';
        }
        return self::Send($h) ? 'server health sent.' : 'server health: the node did not answer.';
    }

    /**
     * The figures (null when the last were taken less than 4 minutes ago).
     *
     * @return array<string, float>|null
     */
    static function Figures()
    {
        global $zdbh;
        $now = microtime(true);
        $h = array();
        $lastFile = elk::STATE_DIR . '/health-last.json';
        $last = json_decode((string) @file_get_contents($lastFile), true);
        $last = is_array($last) ? $last : array();
        if (isset($last['t']) && $now - (float) $last['t'] < 240 && $now >= (float) $last['t']) {
            return null;
        }
        $keep = array('t' => $now);
        $rate = function ($key, $value) use (&$keep, $last, $now) {
            $keep[$key] = $value;
            if (!isset($last[$key], $last['t']) || $now - (float) $last['t'] < 5 || $value < $last[$key]) {
                return null;
            }
            return ($value - (float) $last[$key]) / ($now - (float) $last['t']);
        };

        // CPU: the share of time not idle since the last run (/proc/stat), and the load
        $stat = (string) @file_get_contents('/proc/stat');
        if (preg_match('/^cpu\s+(.+)$/m', $stat, $m)) {
            $v = array_map('floatval', preg_split('/\s+/', trim($m[1])));
            $idle = $v[3] + ($v[4] ?? 0);
            $total = array_sum(array_slice($v, 0, 8));
            if (isset($last['cpu_total'], $last['cpu_idle']) && $total > $last['cpu_total']) {
                $h['cpu_pct'] = round(100 * (1 - ($idle - $last['cpu_idle']) / ($total - $last['cpu_total'])), 1);
            }
            $keep['cpu_total'] = $total;
            $keep['cpu_idle'] = $idle;
        }
        $load = explode(' ', (string) @file_get_contents('/proc/loadavg'));
        if (count($load) >= 3) {
            $h['load1'] = (float) $load[0];
            $h['load5'] = (float) $load[1];
            $h['load15'] = (float) $load[2];
        }
        $h['cpus'] = (float) max(1, (int) preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo')));

        // memory and swap (/proc/meminfo, kB)
        $mi = array();
        if (preg_match_all('/^(\w+):\s+(\d+)/m', (string) @file_get_contents('/proc/meminfo'), $mm, PREG_SET_ORDER)) {
            foreach ($mm as $x) {
                $mi[$x[1]] = (float) $x[2] * 1024;
            }
        }
        if (!empty($mi['MemTotal'])) {
            $avail = $mi['MemAvailable'] ?? ($mi['MemFree'] ?? 0);
            $h['mem_total'] = $mi['MemTotal'];
            $h['mem_used_pct'] = round(100 * (1 - $avail / $mi['MemTotal']), 1);
        }
        if (!empty($mi['SwapTotal'])) {
            $h['swap_used_pct'] = round(100 * (1 - ($mi['SwapFree'] ?? 0) / $mi['SwapTotal']), 1);
        }

        // disk: the system and the websites' folder
        foreach (array('root' => '/', 'sites' => rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/')) as $k => $dir) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }
            $t = @disk_total_space($dir);
            $f = @disk_free_space($dir);
            if ($t > 0 && $f !== false) {
                $h['disk_' . $k . '_used_pct'] = round(100 * (1 - $f / $t), 1);
                $h['disk_' . $k . '_free'] = (float) $f;
            }
        }

        // network: bytes a second in and out, all interfaces but the loopback (/proc/net/dev)
        $rx = $tx = 0.0;
        foreach (array_slice(explode("\n", (string) @file_get_contents('/proc/net/dev')), 2) as $line) {
            if (preg_match('/^\s*([^:\s]+):\s*(\d+)(?:\s+\d+){7}\s+(\d+)/', $line, $m) && $m[1] !== 'lo') {
                $rx += (float) $m[2];
                $tx += (float) $m[3];
            }
        }
        if (($r = $rate('net_rx', $rx)) !== null) {
            $h['net_rx_bps'] = round($r);
        }
        if (($r = $rate('net_tx', $tx)) !== null) {
            $h['net_tx_bps'] = round($r);
        }

        // Apache: its own status page on this server (as Apache Status reads it)
        if (function_exists('curl_init')) {
            $c = curl_init('http://127.0.0.1/server-status?auto');
            // (panels from 2027.105 open Apache's status page only with its key, the hidden setting apache_status_secret)
            $stKey = class_exists('ctrl_options') ? (string) ctrl_options::GetSystemOption('apache_status_secret') : '';
            curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROXY => '',
                CURLOPT_HTTPHEADER => preg_match('/^[A-Za-z0-9]{16,128}$/', $stKey) ? array('X-Sentora-Status: ' . $stKey) : array()));
            $auto = (string) curl_exec($c);
            if ((int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200) {
                foreach (array('BusyWorkers' => 'apache_busy', 'IdleWorkers' => 'apache_idle') as $k => $f) {
                    if (preg_match('/^' . $k . ':\s*(\d+)/m', $auto, $m)) {
                        $h[$f] = (float) $m[1];
                    }
                }
                if (preg_match('/^Total Accesses:\s*(\d+)/m', $auto, $m) && ($r = $rate('apache_acc', (float) $m[1])) !== null) {
                    $h['apache_rps'] = round($r, 2);
                }
            }
        }

        // MySQL / MariaDB: connections and queries (any user may read these)
        try {
            $st = array();
            foreach ($zdbh->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Questions', 'Slow_queries')")->fetchAll(PDO::FETCH_NUM) as $r) {
                $st[$r[0]] = (float) $r[1];
            }
            if (isset($st['Threads_connected'])) {
                $h['mysql_threads'] = $st['Threads_connected'];
            }
            if (isset($st['Questions']) && ($r = $rate('mysql_q', $st['Questions'])) !== null) {
                $h['mysql_qps'] = round($r, 2);
            }
            if (isset($st['Slow_queries']) && ($r = $rate('mysql_slow', $st['Slow_queries'])) !== null) {
                $h['mysql_slow_per_min'] = round($r * 60, 2);
            }
        } catch (Exception $e) {
        }

        // the mail queue: Postfix's waiting mail (its spool is the same on every system)
        $q = 0;
        foreach (array('/var/spool/postfix/deferred', '/var/spool/postfix/active') as $dir) {
            if (is_dir($dir)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    $q += $f->isFile() ? 1 : 0;
                }
            }
        }
        $h['mail_queue'] = (float) $q;

        elk::WriteFile($lastFile, json_encode($keep), 0640, 'root');
        // (every figure a float, so Elasticsearch reads them all as numbers with decimals)
        return array_map('floatval', $h);
    }

    /**
     * Send the figures to the node (kind "health").
     *
     * @param array<string, float> $h
     * @return bool
     */
    static function Send(array $h)
    {
        $node = elk::Node();
        $ctx = stream_context_create(array('ssl' => array('local_cert' => elk::DIR . '/panel.crt', 'local_pk' => elk::DIR . '/panel.key',
            'cafile' => elk::DIR . '/node-ca.crt', 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => elk::NODE_NAME, 'allow_self_signed' => false)));
        $host = filter_var($node['addr'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $node['addr'] . ']' : $node['addr'];
        $conn = @stream_socket_client('tls://' . $host . ':' . elk::PORT, $no, $str, 10, STREAM_CLIENT_CONNECT, $ctx);
        if ($conn === false) {
            return false;
        }
        $panel = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ctrl_options::GetSystemOption('sentora_domain'));
        fwrite($conn, json_encode(array('@timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'sentora_host' => $panel, 'sentora_log' => 'health',
            'message' => json_encode($h, JSON_PRESERVE_ZERO_FRACTION)), JSON_UNESCAPED_SLASHES) . "\n");
        fclose($conn);
        return true;
    }
}
