<?php

/**
 * Web Hardening: bans and flood protection, on fail2ban (which every Sentora installer sets up).
 *
 * The page asks (its requests wait in x_ph_settings); the daemon, as root, does: it writes fail2ban's
 * Sentora Web Hardening jail file (the never-ban list, the jail for bans by hand, the flood jail on the
 * websites' access logs), tests fail2ban's settings with it and puts the old file back when they fail,
 * bans and unbans, and writes down who is banned now for the page. Nothing from the page reaches a
 * command without being checked here again: addresses by PHP's own parser, jails by name and by
 * fail2ban's own list.
 *
 * Paths come from hidden settings (fail2ban_client, fail2ban_dir; added with their usual places when
 * missing, and the same on every supported system).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-23
 */
class ph_bans
{
    /** fail2ban's file for Web Hardening's jails (a .local: read after Sentora's own jail.d/sentora.conf). */
    const JAIL_FILE = 'sentora-web-hardening.local';
    /** The jail of bans made by hand (bans every port; until unbanned, or until the time asked). */
    const MANUAL = 'sentora-manual';
    /** The jail of too many requests to the websites. */
    const FLOOD = 'sentora-flood';
    /** At most this many requests waiting for the daemon. */
    const MAX_ASKED = 50;

    /**
     * A hidden setting of this server, added with its usual value when the panel does not have it yet.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $name fail2ban_client or fail2ban_dir
     * @return string
     */
    static function Path($name)
    {
        global $zdbh;
        $defaults = array('fail2ban_client' => array('/usr/bin/fail2ban-client', 'fail2ban\'s command (Web Hardening: bans and flood protection)'),
            'fail2ban_dir' => array('/etc/fail2ban', 'fail2ban\'s settings folder (Web Hardening: bans and flood protection)'));
        if (!isset($defaults[$name])) {
            return '';
        }
        $q = $zdbh->prepare("SELECT so_value_tx FROM x_settings WHERE so_name_vc = :n");
        $q->execute(array(':n' => $name));
        $v = $q->fetchColumn();
        if ($v === false) {
            $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                VALUES (:n, :c, :v, NULL, :d, 'Web Hardening', 'false')")
                ->execute(array(':n' => $name, ':c' => ucfirst(str_replace('_', ' ', $name)), ':v' => $defaults[$name][0], ':d' => $defaults[$name][1]));
            return $defaults[$name][0];
        }
        return rtrim((string) $v, '/');
    }

    // ----- checking what comes in -----

    /**
     * One address (IPv4 or IPv6) in its plain form, or '' when it is not one.
     *
     * @param string $s
     * @return string
     */
    static function Ip($s)
    {
        $s = trim((string) $s);
        if ($s === '' || strlen($s) > 45 || filter_var($s, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        return (string) inet_ntop(inet_pton($s));
    }

    /**
     * An address or a network (1.2.3.0/24, 2001:db8::/48) in its plain form, or ''.
     *
     * @param string $s
     * @return string
     */
    static function Net($s)
    {
        $s = trim((string) $s);
        if (strpos($s, '/') === false) {
            return self::Ip($s);
        }
        list($ip, $bits) = explode('/', $s, 2);
        $ip = self::Ip($ip);
        if ($ip === '' || !preg_match('/^\d{1,3}$/', $bits)) {
            return '';
        }
        $max = strpos($ip, ':') !== false ? 128 : 32;
        return (int) $bits <= $max ? $ip . '/' . (int) $bits : '';
    }

    /**
     * Whether an address is in a network (or is that address).
     *
     * @param string $ip
     * @param string $net
     * @return bool
     */
    static function InNet($ip, $net)
    {
        $a = @inet_pton($ip);
        list($n, $bits) = strpos($net, '/') !== false ? explode('/', $net, 2) : array($net, null);
        $b = @inet_pton($n);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        return $rest === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xff << (8 - $rest)) & 0xff) === 0;
    }

    /**
     * A jail's name as fail2ban gives it (letters, digits, dot, dash, underscore), or ''.
     *
     * @param string $s
     * @return string
     */
    static function Jail($s)
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', (string) $s) ? (string) $s : '';
    }

    // ----- the settings (x_ph_settings) -----

    /**
     * Never banned: one address or network per entry.
     *
     * @return string[]
     */
    static function Never()
    {
        $out = array();
        foreach (preg_split('/[\s,]+/', ph::Get('ban_never')) as $n) {
            $n = self::Net($n);
            if ($n !== '' && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * Flood protection: on or off, how many requests a minute an address may send, how long a ban lasts.
     *
     * @return array{on: bool, limit: int, minutes: int}
     */
    static function Flood()
    {
        $limit = (int) ph::Get('flood_limit');
        $minutes = (int) ph::Get('flood_minutes');
        return array('on' => ph::Get('flood_on') === '1', 'limit' => $limit >= 30 && $limit <= 100000 ? $limit : 300,
            'minutes' => $minutes >= 1 && $minutes <= 10080 ? $minutes : 60);
    }

    /**
     * What waits for the daemon.
     *
     * @return array<int, array{do: string, ip: string, hours: int, why: string, by: string, at: int}>
     */
    static function Asked()
    {
        $a = json_decode(ph::Get('ban_asked'), true);
        return is_array($a) ? array_values($a) : array();
    }

    /**
     * Ask the daemon to ban (for hours, 0: until unbanned) or unban an address.
     *
     * @param string $do ban or unban
     * @param string $ip Checked already.
     * @param int $hours
     * @param string $why
     * @param string $by
     * @return bool False when too much waits already.
     */
    static function Ask($do, $ip, $hours = 0, $why = '', $by = '')
    {
        $a = self::Asked();
        if (count($a) >= self::MAX_ASKED) {
            return false;
        }
        $a[] = array('do' => $do === 'unban' ? 'unban' : 'ban', 'ip' => $ip, 'hours' => max(0, min(8760, (int) $hours)),
            'why' => self::Line($why, 200), 'by' => self::Line($by, 64), 'at' => time());
        ph::Set('ban_asked', json_encode($a));
        return true;
    }

    /**
     * Bans made by hand (kept for the page: why, by whom, until when).
     *
     * @return array<string, array{why: string, by: string, at: int, until: int}>
     */
    static function Manual()
    {
        $m = json_decode(ph::Get('ban_manual'), true);
        return is_array($m) ? $m : array();
    }

    /**
     * Who is banned now, as the daemon last saw it.
     *
     * @return array{at: int, running: bool, problem: string, version: string, jails: array<string, array<int, array{ip: string, since: int, until: int}>>, flood_logs: int, reqtimeout: bool}
     */
    static function State()
    {
        $s = json_decode(ph::Get('ban_state'), true);
        $s = is_array($s) ? $s : array();
        return array('at' => (int) ($s['at'] ?? 0), 'running' => !empty($s['running']), 'problem' => (string) ($s['problem'] ?? ''),
            'version' => (string) ($s['version'] ?? ''), 'jails' => isset($s['jails']) && is_array($s['jails']) ? $s['jails'] : array(),
            'flood_logs' => (int) ($s['flood_logs'] ?? 0), 'reqtimeout' => !empty($s['reqtimeout']));
    }

    /**
     * One line of text, without characters that could start a new line in a file or a log.
     *
     * @param string $s
     * @param int $max
     * @return string
     */
    static function Line($s, $max)
    {
        $s = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $s));
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    /**
     * What each of the jails Sentora sets up is about, for the page (English).
     *
     * @return array<string, string>
     */
    static function Names()
    {
        return array('sshd' => 'SSH logins', 'postfix-sasl' => 'Mail sending (SMTP logins)', 'sentora-dovecot' => 'Mail logins (IMAP, POP3)',
            'roundcube-auth' => 'Webmail logins', 'sogo-auth' => 'SOGo logins', 'sentora-proftpd' => 'FTP logins', 'sentora-panel' => 'Panel logins',
            'sentora-phpmyadmin' => 'phpMyAdmin logins', self::FLOOD => 'Too many requests', self::MANUAL => 'Banned by hand', 'recidive' => 'Banned again and again');
    }

    // ----- the root side (the daemon) -----

    /**
     * Run fail2ban's command with checked words.
     *
     * @param string[] $words
     * @return array{0: int, 1: string}
     */
    static function Client(array $words)
    {
        return ph_daemon::Run(escapeshellarg(self::Path('fail2ban_client')) . ' ' . implode(' ', array_map('escapeshellarg', $words)));
    }

    /**
     * The server's own addresses that Sentora's jail file never bans (from jail.d/sentora.conf).
     *
     * @return string[]
     */
    static function OwnIgnored()
    {
        $out = array('127.0.0.1/8', '::1');
        $conf = (string) @file_get_contents(self::Path('fail2ban_dir') . '/jail.d/sentora.conf');
        if (preg_match('/^\s*ignoreip\s*=\s*(.+)$/m', $conf, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $n) {
                $n = self::Net($n);
                if ($n !== '' && !in_array($n, $out, true)) {
                    $out[] = $n;
                }
            }
        }
        return $out;
    }

    /**
     * The websites' access logs (the flood jail reads every one; a new website's log means a reload).
     *
     * @return string[]
     */
    static function AccessLogs()
    {
        $dir = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/');
        $out = array();
        foreach ((array) glob(($dir !== '' ? $dir : '/var/sentora/logs') . '/domains/*/*-access.log') as $f) {
            if (is_file($f) && !is_link($f) && strpos(basename(dirname($f)), '_') !== 0) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * fail2ban's jail file for Web Hardening.
     *
     * @param string[] $ignore Never banned.
     * @param array $flood
     * @param string[] $logs The websites' access logs (the flood jail is on only with at least one).
     * @return string
     */
    static function JailText(array $ignore, array $flood, array $logs)
    {
        $dir = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/');
        $t = "# Sentora Web Hardening: written by the panel's daemon (Server Admin > Web Hardening > Bans); changes here are replaced.\n\n"
            . "[DEFAULT]\n# never banned: this server, and Web Hardening's never-ban list\nignoreip = " . implode(' ', $ignore) . "\n\n"
            . "[" . self::MANUAL . "]\n# bans made by hand on the page: every port, until unbanned (or until the time asked: the daemon unbans)\n"
            . "enabled = true\nfilter = " . self::MANUAL . "\nbackend = systemd\njournalmatch = _SYSTEMD_UNIT=sentora-manual-bans.service\n"
            . "bantime = -1\naction = %(banaction_allports)s\n\n"
            . "[" . self::FLOOD . "]\n# too many requests to the websites from one address (pictures, styles and scripts not counted)\n";
        if ($flood['on'] && $logs) {
            $t .= "enabled = true\nfilter = " . self::FLOOD . "\nbackend = polling\nport = http,https\n"
                . "logpath = " . ($dir !== '' ? $dir : '/var/sentora/logs') . "/domains/*/*-access.log tail\n"
                . "maxretry = " . (int) $flood['limit'] . "\nfindtime = 60\nbantime = " . ((int) $flood['minutes'] * 60) . "\n";
        } else {
            $t .= "enabled = false\n";
        }
        return $t;
    }

    /**
     * fail2ban's filters for Web Hardening's jails: file => text.
     *
     * @return array<string, string>
     */
    static function Filters()
    {
        return array(
            self::FLOOD . '.conf' => "# Sentora Web Hardening: one line of a website's access log (combined or common) is one request;\n"
                . "# pictures, styles, scripts and fonts are not counted (a page brings many of them).\n[Definition]\n"
                . "failregex = ^<HOST> -[^\"]*\"[A-Z]+ \n"
                . "ignoreregex = ^<HOST> -[^\"]*\"(?:GET|HEAD) [^ \"?]+\\.(?:css|js|mjs|png|jpe?g|gif|svg|webp|avif|ico|bmp|woff2?|ttf|otf|eot|map)(?:\\?[^ \"]*)? HTTP\n",
            self::MANUAL . '.conf' => "# Sentora Web Hardening: bans made by hand only (it matches nothing in any log).\n[Definition]\n"
                . "failregex = ^sentora-web-hardening-never-matches <HOST>$\nignoreregex =\n",
        );
    }

    /**
     * Write a file only when it differs; the old text is returned for putting back (null: there was none,
     * false: unchanged).
     *
     * @param string $file
     * @param string $text
     * @return string|null|false
     */
    static function Put($file, $text)
    {
        $old = is_file($file) ? (string) file_get_contents($file) : null;
        if ($old === $text) {
            return false;
        }
        ph_daemon::Write($file, $text);
        return $old;
    }

    /**
     * Everything on a daemon run: fail2ban's files, what was asked, the bans that ran out, who is banned now.
     *
     * @param int|null $now
     * @return string[] Lines for the daemon's log.
     */
    static function DaemonRun($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $log = array();
        $dir = self::Path('fail2ban_dir');
        $client = self::Path('fail2ban_client');
        $state = array('at' => $now, 'running' => false, 'problem' => '', 'version' => '', 'jails' => array(), 'flood_logs' => 0,
            'reqtimeout' => strpos(ph_daemon::Run(sys_platform::Get('apache_modules_cmd'))[1], 'reqtimeout_module') !== false);
        if ($client === '' || !is_file($client) || !is_dir($dir . '/jail.d') || !is_dir($dir . '/filter.d')) {
            $state['problem'] = 'fail2ban is not installed on this server (' . $client . '): Sentora\'s installer sets it up.';
            ph::Set('ban_state', json_encode($state));
            return $log;
        }
        // 1. fail2ban's files: the filters, then the jail file; tested, the old ones back when the test fails
        $flood = self::Flood();
        $logs = self::AccessLogs();
        $state['flood_logs'] = count($logs);
        $changed = array();
        foreach (self::Filters() as $name => $text) {
            $old = self::Put($dir . '/filter.d/' . $name, $text);
            if ($old !== false) {
                $changed[$dir . '/filter.d/' . $name] = $old;
            }
        }
        $jail = $dir . '/jail.d/' . self::JAIL_FILE;
        $old = self::Put($jail, self::JailText(array_values(array_unique(array_merge(self::OwnIgnored(), self::Never()))), $flood, $logs));
        if ($old !== false) {
            $changed[$jail] = $old;
        }
        // (fail2ban reads the websites' logs that are there when it starts: a new website's log, a reload)
        $logsHash = sha1(implode("\n", $logs));
        $reload = $changed !== array() || ($flood['on'] && ph::Get('flood_logs_hash') !== $logsHash);
        if ($changed) {
            list($rc, $out) = self::Client(array('-t'));
            if ($rc !== 0) {
                foreach ($changed as $f => $was) {
                    if ($was === null) {
                        @unlink($f);
                    } else {
                        ph_daemon::Write($f, $was);
                    }
                }
                $reload = false;
                $why = '';
                foreach (explode("\n", $out) as $l) {
                    if (stripos($l, 'error') !== false) {
                        $why = trim($l);
                        break;
                    }
                }
                $state['problem'] = 'fail2ban refused the new settings, so nothing was changed' . ($why !== '' ? ' (' . substr($why, 0, 300) . ')' : '') . '.';
                $log[] = 'Web Hardening, bans: ' . $state['problem'];
            }
        }
        $ping = self::Client(array('ping'));
        $state['running'] = $ping[0] === 0;
        if (!$state['running']) {
            $state['problem'] = $state['problem'] !== '' ? $state['problem'] : 'fail2ban is not running (over SSH: systemctl start fail2ban).';
            ph::Set('ban_state', json_encode($state));
            return $log;
        }
        if ($reload) {
            if (self::Client(array('reload'))[0] === 0) {
                ph::Set('flood_logs_hash', $logsHash);
                $log[] = 'Web Hardening, bans: fail2ban has the new settings' . ($flood['on'] ? ' (flood protection on, ' . $flood['limit'] . ' requests a minute, ' . count($logs) . ' website logs)' : '');
            } else {
                $log[] = 'Web Hardening, bans: fail2ban did not reload';
            }
        }
        $v = self::Client(array('version'));
        $state['version'] = $v[0] === 0 ? self::Line($v[1], 20) : '';
        // 2. what the page asked, in order
        $manual = self::Manual();
        $never = array_merge(self::OwnIgnored(), self::Never());
        foreach (self::Asked() as $a) {
            $ip = self::Ip($a['ip'] ?? '');
            if ($ip === '') {
                continue;
            }
            if (($a['do'] ?? '') === 'unban') {
                self::Client(array('unban', $ip));
                unset($manual[$ip]);
                $log[] = 'Web Hardening, bans: unbanned ' . $ip . (($a['by'] ?? '') !== '' ? ' (by ' . $a['by'] . ')' : '');
                continue;
            }
            $kept = false;
            foreach ($never as $n) {
                $kept = $kept || self::InNet($ip, $n);
            }
            if ($kept) {
                $log[] = 'Web Hardening, bans: ' . $ip . ' is on the never-ban list, not banned';
                continue;
            }
            if (self::Client(array('set', self::MANUAL, 'banip', $ip))[0] === 0) {
                $hours = (int) ($a['hours'] ?? 0);
                $manual[$ip] = array('why' => (string) ($a['why'] ?? ''), 'by' => (string) ($a['by'] ?? ''), 'at' => $now, 'until' => $hours > 0 ? $now + $hours * 3600 : 0);
                $log[] = 'Web Hardening, bans: banned ' . $ip . ($hours > 0 ? ' for ' . $hours . ' hour(s)' : ' until unbanned') . (($a['by'] ?? '') !== '' ? ' (by ' . $a['by'] . ')' : '');
            } else {
                $log[] = 'Web Hardening, bans: fail2ban did not ban ' . $ip;
            }
        }
        ph::Set('ban_asked', '[]');
        // 3. bans by hand whose time is up
        foreach ($manual as $ip => $m) {
            if ((int) $m['until'] > 0 && (int) $m['until'] <= $now) {
                self::Client(array('set', self::MANUAL, 'unbanip', (string) $ip));
                unset($manual[$ip]);
                $log[] = 'Web Hardening, bans: ' . $ip . '\'s ban by hand ran out';
            }
        }
        // 4. who is banned now
        list($rc, $out) = self::Client(array('status'));
        $jails = array();
        if ($rc === 0 && preg_match('/Jail list:\s*(.*)$/m', $out, $m)) {
            foreach (preg_split('/[\s,]+/', trim($m[1])) as $j) {
                $j = self::Jail($j);
                if ($j !== '') {
                    $jails[$j] = self::Banned($j);
                }
            }
        }
        $state['jails'] = $jails;
        // (a ban by hand that fail2ban no longer has was unbanned some other way, over SSH)
        $nowManual = array_column(isset($jails[self::MANUAL]) ? $jails[self::MANUAL] : array(), 'ip');
        foreach (array_keys($manual) as $ip) {
            if (!in_array((string) $ip, $nowManual, true)) {
                unset($manual[$ip]);
            }
        }
        ph::Set('ban_manual', json_encode($manual));
        ph::Set('ban_state', json_encode($state));
        return $log;
    }

    /**
     * The addresses a jail has banned now, with since and until when fail2ban says (fail2ban 0.11 and later).
     *
     * @param string $jail
     * @return array<int, array{ip: string, since: int, until: int}>
     */
    static function Banned($jail)
    {
        $out = array();
        list($rc, $text) = self::Client(array('get', $jail, 'banip', '--with-time'));
        if ($rc !== 0) {
            list($rc, $text) = self::Client(array('get', $jail, 'banip'));
        }
        if ($rc !== 0) {
            return $out;
        }
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // "1.2.3.4 \t2026-11-23 10:00:00 + 3600 = 2026-11-23 11:00:00", or only the addresses
            if (preg_match('/^(\S+)\s+(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\s*\+\s*(-?\d+)\s*=\s*(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d|\S+)/', $line, $m)) {
                $ip = self::Ip($m[1]);
                if ($ip !== '') {
                    $since = (int) strtotime($m[2]);
                    $out[] = array('ip' => $ip, 'since' => $since, 'until' => (int) $m[3] < 0 ? 0 : $since + (int) $m[3]);
                }
                continue;
            }
            foreach (preg_split('/\s+/', $line) as $w) {
                $ip = self::Ip($w);
                if ($ip !== '') {
                    $out[] = array('ip' => $ip, 'since' => 0, 'until' => 0);
                }
            }
        }
        return array_slice($out, 0, 5000);
    }

    /**
     * The remove step: Web Hardening's jail file and filters out, fail2ban reloaded (its own jails stay).
     *
     * @return string[]
     */
    static function AllOff()
    {
        $dir = self::Path('fail2ban_dir');
        $any = false;
        foreach (array_merge(array($dir . '/jail.d/' . self::JAIL_FILE), array_map(function ($f) use ($dir) { return $dir . '/filter.d/' . $f; }, array_keys(self::Filters()))) as $f) {
            if (is_file($f)) {
                @unlink($f);
                $any = true;
            }
        }
        if ($any && self::Client(array('ping'))[0] === 0) {
            self::Client(array('reload'));
            return array('Web Hardening: its fail2ban jails are gone (bans by hand and flood protection); fail2ban\'s own jails stay');
        }
        return array();
    }
}
