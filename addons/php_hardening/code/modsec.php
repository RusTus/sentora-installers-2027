<?php

/**
 * Web Hardening, ModSecurity: Apache's web application firewall with Sentora's copy of the OWASP
 * Core Rule Set (etc/modsecurity/coreruleset), off, watch only (DetectionOnly) or on. The installers
 * put ModSecurity on every system switched off; everything that differs between systems comes from
 * the panel's settings (modsec_*, apache_*). The daemon writes one file into the folder Sentora's
 * httpd.conf includes (modsec_conf_dir), tests Apache with it, reloads, and puts the old one back when
 * Apache refuses it or stops; it also reads ModSecurity's audit log into the list of what was caught.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
class ph_modsec
{
    /** The add-on's file in modsec_conf_dir. */
    const FILE = 'sentora-web-hardening.conf';

    /** Its audit log, in Apache's log folder. */
    const AUDIT = 'modsec_audit.log';

    /** The status row's key (x_ph_status). */
    const KEY = 'modsec';

    /** The rule IDs of the anomaly score checks: a transaction is "caught" when one of these matched. */
    const SCORE_RULES = array('949110', '959100');

    /** First ID of the add-on's own rules (local range). */
    const OWN_ID = 99100;

    // ----- choices -----

    /**
     * Off, watch or on.
     *
     * @return string
     */
    static function Mode()
    {
        $m = ph::Get('ms:mode');
        return in_array($m, array('watch', 'on'), true) ? $m : 'off';
    }

    /**
     * Paranoia level (1 to 3): how much the rules look for; higher catches more and stops more by mistake.
     *
     * @return int
     */
    static function Level()
    {
        $l = (int) ph::Get('ms:level');
        return $l >= 1 && $l <= 3 ? $l : 1;
    }

    /**
     * The score at which a request is stopped (5 is CRS's own; higher stops less).
     *
     * @return int
     */
    static function Threshold()
    {
        $t = (int) ph::Get('ms:threshold');
        return in_array($t, array(5, 10, 20), true) ? $t : 5;
    }

    /**
     * Domains left alone (their requests are not checked).
     *
     * @return string[]
     */
    static function SkipDomains()
    {
        $v = ph::Get('ms:skipdomains');
        return $v === '' ? array() : array_values(array_filter(explode(',', $v), array('ph_modsec', 'IsDomain')));
    }

    /**
     * Rule IDs switched off.
     *
     * @return string[]
     */
    static function SkipRules()
    {
        $v = ph::Get('ms:skiprules');
        return $v === '' ? array() : array_values(array_filter(explode(',', $v), function ($id) { return preg_match('/^[1-9][0-9]{2,6}$/', $id) === 1; }));
    }

    /**
     * The panel's own host names: its address and the resellers' panel addresses (Branding).
     *
     * @return string[]
     */
    static function PanelHosts()
    {
        $out = array();
        $panel = strtolower(trim((string) ctrl_options::GetSystemOption('sentora_domain')));
        if (self::IsDomain($panel)) {
            $out[] = $panel;
        }
        if (class_exists('ctrl_branding') && method_exists('ctrl_branding', 'Addresses')) {
            try {
                foreach (ctrl_branding::Addresses() as $a) {
                    $h = isset($a['host']) ? strtolower((string) $a['host']) : '';
                    if (self::IsDomain($h) && !in_array($h, $out, true)) {
                        $out[] = $h;
                    }
                }
            } catch (Exception $e) {
                // (no branding table yet: the main address only)
            }
        }
        return $out;
    }

    /**
     * Whether a text is a host name (lower case letters, digits, dots and dashes).
     *
     * @param string $d
     * @return bool
     */
    static function IsDomain($d)
    {
        return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', (string) $d) === 1;
    }

    // ----- the settings file -----

    /**
     * Sentora's copy of the OWASP rules.
     *
     * @return string
     */
    static function CrsDir()
    {
        return rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/etc/modsecurity/coreruleset';
    }

    /**
     * Its version line ('' when it is missing).
     *
     * @return string For example "OWASP CRS 4.25.2".
     */
    static function CrsVersion()
    {
        $t = (string) @file_get_contents(self::CrsDir() . '/VERSION');
        return preg_match('/^(OWASP CRS [0-9][0-9.]*)/', $t, $m) ? $m[1] : '';
    }

    /**
     * The audit log's path.
     *
     * @return string
     */
    static function AuditLog()
    {
        return rtrim(sys_platform::Get('apache_log_dir'), '/') . '/' . self::AUDIT;
    }

    /**
     * The settings file for a mode.
     *
     * @param string $mode watch or on
     * @param int $level
     * @param int $threshold
     * @param string[] $skipDomains Host names whose requests are not checked (the panel's own is added).
     * @param string[] $skipRules Rule IDs switched off.
     * @return string
     */
    static function Conf($mode, $level, $threshold, array $skipDomains, array $skipRules)
    {
        $q = function ($p) { return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $p) . '"'; };
        $crs = self::CrsDir();
        $data = rtrim(sys_platform::Get('modsec_data_dir'), '/');
        $rx = function (array $names) {
            $h = array();
            foreach ($names as $d) {
                $h[$d] = preg_quote(preg_replace('/^www\./', '', $d), '/');
            }
            return '^(?:www\.)?(?:' . implode('|', $h) . ')$';
        };
        $o = array(
            '# Written by Sentora\'s Web Hardening add-on (' . ($mode === 'watch' ? 'watch only: nothing is blocked, what would be is logged' : 'on') . '), ' . self::CrsVersion() . '.',
            '# Changes here are overwritten: change it in the panel (Server Admin > Web Hardening).',
            '<IfModule !unique_id_module>',
            '    LoadModule unique_id_module ' . $q(sys_platform::Get('modsec_unique_id_module')),
            '</IfModule>',
            '<IfModule !security2_module>',
            '    LoadModule security2_module ' . $q(sys_platform::Get('modsec_module')),
            '</IfModule>',
            '<IfModule security2_module>',
            '    SecRuleEngine ' . ($mode === 'watch' ? 'DetectionOnly' : 'On'),
            '    SecRequestBodyAccess On',
            '    # (bodies over 12.5 MB, such as large uploads, are checked in their first part only, not refused)',
            '    SecRequestBodyLimit 13107200',
            // ModSecurity 2.9.8 and newer refuse form data over this limit (413) whatever the
            // mode, so watching only gets the whole 12.5 MB: watching never refuses anything
            '    # form data without files: ' . ($mode === 'watch' ? 'watched up to 12.5 MB (newer ModSecurity refuses more than this limit even when only watching)' : 'refused over 128 KB'),
            '    SecRequestBodyNoFilesLimit ' . ($mode === 'watch' ? '13107200' : '131072'),
            '    SecRequestBodyLimitAction ProcessPartial',
            '    SecRequestBodyInMemoryLimit 131072',
            '    SecResponseBodyAccess Off',
            '    SecPcreMatchLimit 100000',
            '    SecPcreMatchLimitRecursion 100000',
            '    SecTmpDir ' . $q($data),
            '    SecDataDir ' . $q($data),
            '    SecArgumentSeparator &',
            '    SecCookieFormat 0',
            '    SecStatusEngine Off',
            '    # what was caught: the summary of each request a rule matched (no headers or bodies)',
            '    SecAuditEngine RelevantOnly',
            '    SecAuditLogRelevantStatus "^$"',
            '    SecAuditLogParts AHZ',
            '    SecAuditLogType Serial',
            '    SecAuditLog ' . $q(self::AuditLog()),
            '    # the rules: how much they look for, and the score at which a request is stopped',
            '    SecAction "id:900000,phase:1,pass,t:none,nolog,setvar:tx.blocking_paranoia_level=' . (int) $level . '"',
            '    SecAction "id:900110,phase:1,pass,t:none,nolog,setvar:tx.inbound_anomaly_score_threshold=' . (int) $threshold . ',setvar:tx.outbound_anomaly_score_threshold=4"',
            '    Include ' . $q($crs . '/crs-setup.conf.example'),
        );
        // (checked and logged, never blocked: a false alarm can not lock the administrators out)
        $panels = self::PanelHosts();
        if ($panels) {
            $o[] = '    # the panel itself (its address and the resellers\' panel addresses): watched only';
            $o[] = '    SecRule SERVER_NAME "@rx ' . $rx($panels) . '" "id:' . self::OWN_ID . ',phase:1,pass,t:none,t:lowercase,nolog,ctl:ruleEngine=DetectionOnly"';
            // (newer ModSecurity refuses form data over the limit even when only watching: a
            // larger one to the panel is not read by ModSecurity at all, so it is never refused)
            $o[] = '    SecRule SERVER_NAME "@rx ' . $rx($panels) . '" "id:' . (self::OWN_ID + 3) . ',phase:1,pass,t:none,t:lowercase,nolog,chain"';
            $o[] = '        SecRule REQUEST_HEADERS:Content-Length "@gt 131072" "t:none,ctl:requestBodyAccess=Off"';
        }
        // a request to a bare IP address (or with no name) reaches the panel or Sentora's "not found" site, never a customer's
        $o[] = '    # the panel by the server\'s IP address: watched only too';
        $o[] = '    SecRule SERVER_NAME "@rx ^(?:[0-9]{1,3}(?:\\.[0-9]{1,3}){3}|\\[?[0-9a-f]*:[0-9a-f:]+\\]?)?$" "id:' . (self::OWN_ID + 2) . ',phase:1,pass,t:none,t:lowercase,nolog,ctl:ruleEngine=DetectionOnly"';
        $o[] = '    SecRule SERVER_NAME "@rx ^(?:[0-9]{1,3}(?:\\.[0-9]{1,3}){3}|\\[?[0-9a-f]*:[0-9a-f:]+\\]?)?$" "id:' . (self::OWN_ID + 4) . ',phase:1,pass,t:none,t:lowercase,nolog,chain"';
        $o[] = '        SecRule REQUEST_HEADERS:Content-Length "@gt 131072" "t:none,ctl:requestBodyAccess=Off"';
        if ($skipDomains) {
            $o[] = '    # left alone: the domains chosen in the panel (with or without www.)';
            $o[] = '    SecRule SERVER_NAME "@rx ' . $rx($skipDomains) . '" "id:' . (self::OWN_ID + 1) . ',phase:1,pass,t:none,t:lowercase,nolog,ctl:ruleEngine=Off"';
        }
        $o[] = '    Include ' . $q($crs . '/rules/*.conf');
        if ($skipRules) {
            $o[] = '    # rules switched off in the panel';
            $o[] = '    SecRuleRemoveById ' . implode(' ', $skipRules);
        }
        $o[] = '</IfModule>';
        return implode("\n", $o) . "\n";
    }

    // ----- the daemon (root) -----

    /**
     * Bring ModSecurity to what the administrator chose.
     *
     * @return string A line for the daemon's log ('' when nothing changed).
     */
    static function Apply()
    {
        $mode = self::Mode();
        $dir = rtrim(sys_platform::Get('modsec_conf_dir'), '/');
        $file = $dir . '/' . self::FILE;
        if ($mode === 'off') {
            if (!is_file($file)) {
                self::SetStatus('off');
                return '';
            }
            $old = (string) file_get_contents($file);
            @unlink($file);
            if (!self::TestAndReload()) {
                ph_daemon::Write($file, $old);
                return self::Problem('Apache did not run without ModSecurity\'s settings, so they were put back.');
            }
            self::SetStatus('off');
            return 'switched off';
        }
        if (!is_file(sys_platform::Get('modsec_module'))) {
            return self::Problem('ModSecurity is not installed on this server (%s is missing): sentora-update installs it.', sys_platform::Get('modsec_module'));
        }
        if (self::CrsVersion() === '') {
            return self::Problem('The OWASP rules are missing from %s: sentora-update puts them back.', self::CrsDir());
        }
        $inc = dirname((string) ctrl_options::GetSystemOption('apache_vhost')) . '/httpd.conf';
        if ($dir === '' || !is_dir($dir) || strpos((string) @file_get_contents($inc), 'IncludeOptional ' . $dir . '/*.conf') === false) {
            return self::Problem('Sentora\'s Apache settings (%s) do not include the ModSecurity folder yet: sentora-update adds it.', $inc);
        }
        if (!is_file($file) && self::Loaded()) {
            return self::Problem('ModSecurity is already switched on by the system\'s own settings; switch those off first (over SSH: %s, then reload Apache).', sys_platform::Get('modsec_off_cmd'));
        }
        $text = self::Conf($mode, self::Level(), self::Threshold(), self::SkipDomains(), self::SkipRules());
        $old = is_file($file) ? (string) file_get_contents($file) : null;
        if ($old === $text) {
            $was = ph::Status(self::KEY);
            if ($was['st_state_vc'] !== $mode || $was['st_sp_vc'] !== self::CrsVersion()) {
                self::SetStatus($mode);
            }
            return '';
        }
        $data = rtrim(sys_platform::Get('modsec_data_dir'), '/');
        if ($data !== '' && !is_dir($data)) {
            @mkdir($data, 0750, true);
            @chown($data, sys_platform::Get('web_user'));
        }
        ph_daemon::Write($file, $text);
        list($ok, $why) = self::Test();
        if (!$ok) {
            self::PutBack($file, $old);
            return self::Problem('Apache refused the new ModSecurity settings, so nothing was changed (%s).', $why);
        }
        if (!self::Reload()) {
            self::PutBack($file, $old);
            self::Reload(true);
            return self::Problem('Apache did not run again with the new ModSecurity settings, so they were taken back.');
        }
        self::SetStatus($mode);
        return 'ModSecurity ' . ($mode === 'watch' ? 'watching (nothing blocked)' : 'on') . ', ' . self::CrsVersion() . ', level ' . self::Level();
    }

    /**
     * Put the previous file back (or none).
     *
     * @param string $file
     * @param string|null $old
     * @return void
     */
    static function PutBack($file, $old)
    {
        if ($old === null) {
            @unlink($file);
        } else {
            ph_daemon::Write($file, $old);
        }
    }

    /**
     * Apache's config test.
     *
     * @return array{0: bool, 1: string} Passed, and the first line it complained with.
     */
    static function Test()
    {
        list($rc, $out) = ph_daemon::Run(sys_platform::Get('apache_ctl') . ' configtest');
        // Apache's complaint: its first two lines (where, then what)
        $lines = array();
        foreach (explode("\n", $out) as $l) {
            if (trim($l) !== '' && stripos($l, 'Syntax OK') === false && count($lines) < 2) {
                $lines[] = trim($l);
            }
        }
        $why = implode(' ', $lines);
        return array($rc === 0, $why !== '' ? substr($why, 0, 400) : 'exit ' . $rc);
    }

    /**
     * Reload Apache (or restart), then check it runs.
     *
     * @param bool $restart
     * @return bool
     */
    static function Reload($restart = false)
    {
        $svc = escapeshellarg((string) ctrl_options::GetSystemOption('apache_sn'));
        if ($restart || ph_daemon::Run('systemctl reload ' . $svc)[0] !== 0) {
            ph_daemon::Run('systemctl restart ' . $svc);
        }
        for ($i = 0; $i < 10; $i++) {
            if (ph_daemon::Run('systemctl is-active --quiet ' . $svc)[0] === 0) {
                return true;
            }
            if (!ph_daemon::$run) {
                sleep(1);
            }
        }
        return false;
    }

    /**
     * Test, then reload (switching off).
     *
     * @return bool
     */
    static function TestAndReload()
    {
        list($ok) = self::Test();
        return $ok && self::Reload();
    }

    /**
     * Whether Apache has ModSecurity loaded.
     *
     * @return bool
     */
    static function Loaded()
    {
        list(, $out) = ph_daemon::Run(sys_platform::Get('apache_modules_cmd'));
        return strpos($out, 'security2_module') !== false;
    }

    /**
     * Note the state.
     *
     * @param string $state
     * @param string $note
     * @return void
     */
    static function SetStatus($state, $note = '')
    {
        ph::SetStatus(self::KEY, $state, $note, $state === 'off' ? '' : self::CrsVersion());
    }

    /**
     * Note a problem (once) and return it for the log.
     *
     * @param string $text English, %s for $arg.
     * @param string $arg
     * @return string
     */
    static function Problem($text, $arg = '')
    {
        return ph_daemon::Problem(self::KEY, $text, $arg);
    }

    // ----- what it caught -----

    /**
     * Read the audit log from where the last run stopped (a new file after rotation: from its start).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return int New lines kept.
     */
    static function ReadLog()
    {
        global $zdbh;
        $log = self::AuditLog();
        $pos = json_decode(ph::Get('ms:pos'), true);
        $pos = is_array($pos) ? $pos : array('ino' => 0, 'at' => 0);
        clearstatcache();
        if (!is_file($log)) {
            return 0;
        }
        $st = stat($log);
        $at = ((int) $pos['ino'] === (int) $st['ino'] && (int) $pos['at'] <= (int) $st['size']) ? (int) $pos['at'] : 0;
        if ($at >= $st['size']) {
            return 0;
        }
        $fh = fopen($log, 'rb');
        if (!$fh) {
            return 0;
        }
        fseek($fh, $at);
        $chunk = (string) fread($fh, 4 * 1048576);
        fclose($fh);
        // only whole entries (up to the last end marker); the rest is read next time
        if (!preg_match_all('/^--[0-9a-f]{8}-Z--\r?\n/m', $chunk, $mm, PREG_OFFSET_CAPTURE) || !$mm[0]) {
            if (strlen($chunk) >= 4 * 1048576) {
                ph::Set('ms:pos', json_encode(array('ino' => (int) $st['ino'], 'at' => $at + strlen($chunk))));
            }
            return 0;
        }
        $last = end($mm[0]);
        $used = $last[1] + strlen($last[0]);
        $n = 0;
        foreach (self::Entries(substr($chunk, 0, $used)) as $e) {
            ph::Add(array('time' => $e['time'], 'account' => self::AccountOfHost($e['host']), 'feature' => 'modsecurity', 'action' => $e['blocked'] ? 'drop' : 'simulation',
                'message' => $e['text'], 'file' => $e['host'] . $e['uri'], 'line' => 0, 'ip' => $e['ip']));
            $n++;
        }
        ph::Set('ms:pos', json_encode(array('ino' => (int) $st['ino'], 'at' => $at + $used)));
        return $n;
    }

    /**
     * The caught requests in a piece of a serial audit log (native format, parts A, H and Z): only
     * those that passed the score (they were, or in watch only would have been, stopped).
     *
     * @param string $text
     * @return array<int, array{time: int, ip: string, host: string, uri: string, blocked: bool, text: string}>
     */
    static function Entries($text)
    {
        $out = array();
        foreach (preg_split('/^--[0-9a-f]{8}-Z--\r?$/m', $text) as $entry) {
            if (!preg_match('/^--[0-9a-f]{8}-A--\r?\n\[([^\]]+)\] \S+ (\S+) /m', $entry, $a)) {
                continue;
            }
            $h = preg_match('/^--[0-9a-f]{8}-H--\r?\n(.*)\z/ms', $entry, $hm) ? $hm[1] : '';
            $ids = array();
            $msgs = array();
            $host = '';
            $uri = '';
            $scored = false;
            foreach (preg_split('/\r?\n/', $h) as $line) {
                if (strpos($line, 'Message: ') !== 0) {
                    continue;
                }
                $id = preg_match('/\[id "(\d+)"\]/', $line, $m) ? $m[1] : '';
                $msg = preg_match('/\[msg "((?:[^"\\\\]|\\\\.)*)"\]/', $line, $m) ? stripcslashes($m[1]) : '';
                if ($host === '' && preg_match('/\[hostname "([^"]*)"\]/', $line, $m)) {
                    $host = strtolower($m[1]);
                }
                if ($uri === '' && preg_match('/\[uri "((?:[^"\\\\]|\\\\.)*)"\]/', $line, $m)) {
                    $uri = stripcslashes($m[1]);
                }
                if (in_array($id, self::SCORE_RULES, true)) {
                    $scored = true;
                    continue;
                }
                // the rules that found something (attack rules 911000-944999 and 950000-956999, or the
                // administrator's own below 900000), not CRS's bookkeeping (901..., 905..., 980...)
                $i = (int) $id;
                if ($id !== '' && ($i < 900000 || ($i >= 911000 && $i < 945000) || ($i >= 950000 && $i < 957000))) {
                    $ids[$id] = $msg;
                }
            }
            if (!$scored) {
                continue;
            }
            // (ModSecurity 2 writes a zone west of Greenwich with two minus signs: "--0600")
            $ts = preg_replace('/ --(\d{4})$/', ' -$1', trim($a[1]));
            $t = DateTime::createFromFormat('d/M/Y:H:i:s.u O', $ts) ?: DateTime::createFromFormat('d/M/Y:H:i:s O', $ts);
            $parts = array();
            foreach ($ids as $id => $msg) {
                $parts[] = $id . ' ' . $msg;
            }
            $out[] = array('time' => $t ? $t->getTimestamp() : time(), 'ip' => $a[2], 'host' => $host, 'uri' => $uri,
                'blocked' => preg_match('/^Action: Intercepted/m', $h) === 1,
                'text' => $parts ? 'Rules ' . implode('; ', $parts) : 'Anomaly score exceeded');
        }
        return $out;
    }

    /**
     * Whose domain a host name is (the account's user name), or ''.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $host
     * @return string
     */
    static function AccountOfHost($host)
    {
        global $zdbh;
        $host = preg_replace('/:\d+$/', '', strtolower((string) $host));
        if (!self::IsDomain($host)) {
            return '';
        }
        $q = $zdbh->prepare("SELECT a.ac_user_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk WHERE v.vh_deleted_ts IS NULL AND v.vh_name_vc IN (:h, :b) LIMIT 1");
        $q->execute(array(':h' => $host, ':b' => preg_replace('/^www\./', '', $host)));
        $u = $q->fetchColumn();
        return $u === false ? '' : (string) $u;
    }
}
