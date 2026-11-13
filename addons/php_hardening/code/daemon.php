<?php

/**
 * Web Hardening (PHP part), the root side (the daemon's hook and the remove step): for every PHP on the
 * server it puts Snuffleupagus in place (the system PHP's from the release, checked by SHA-256),
 * writes the rules, checks that PHP starts with them, then switches it on and reloads that PHP-FPM;
 * a PHP that does not come back is put back as it was. It also reads what Snuffleupagus logged.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
class ph_daemon
{
    /** Sentora's own build for the system PHP, by this name (a distribution's snuffleupagus.so is never touched). */
    const SYSTEM_SO = 'sentora-snuffleupagus.so';

    /** @var array|null|false php-bundles.json of this run (false: could not be read). */
    static $list = null;

    /** @var callable|null For the tests: run a command (returns array(exit code, output)). */
    static $run = null;

    /** @var callable|null For the tests: fetch an address. */
    static $fetch = null;

    /**
     * Run a command.
     *
     * @param string $cmd
     * @return array{0: int, 1: string}
     */
    static function Run($cmd)
    {
        if (self::$run) {
            return call_user_func(self::$run, $cmd);
        }
        $out = array();
        exec($cmd . ' 2>&1', $out, $rc);
        return array($rc, implode("\n", $out));
    }

    /**
     * Everything to do on a daemon run.
     *
     * @return string[] Lines for the daemon's log.
     */
    static function DaemonRun()
    {
        $log = array();
        foreach (ph::Versions() as $v => $kind) {
            $line = self::Apply($v, $kind, ph::Mode($v));
            if ($line !== '') {
                $log[] = 'Web Hardening, PHP ' . $v . ': ' . $line;
            }
        }
        $line = ph_modsec::Apply();
        if ($line !== '') {
            $log[] = 'Web Hardening, ' . $line;
        }
        $n = self::ReadLog() + ph_modsec::ReadLog();
        // (the page's live list says when the server last looked)
        ph::Set('read_at', (string) time());
        if ($n > 0) {
            ph::Prune();
            $log[] = 'Web Hardening: ' . $n . ' new line(s) in what it caught';
        }
        return $log;
    }

    /**
     * Where a PHP's FPM program is ('' when it is not found).
     *
     * @param string $v
     * @param string $kind system or bundle
     * @return string
     */
    static function Fpm($v, $kind)
    {
        if ($kind === 'bundle') {
            $f = sys_phpversions::BASE_DIR . $v . '/sbin/php-fpm';
            return is_file($f) ? $f : '';
        }
        $name = basename((string) sys_platform::Get('php_fpm_process', array('v' => $v)));
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            return '';
        }
        $dirs = array_merge(explode(':', (string) getenv('PATH')), array('/usr/sbin', '/usr/bin', '/sbin', '/usr/local/sbin'));
        foreach ($dirs as $d) {
            if ($d !== '' && is_file(rtrim($d, '/') . '/' . $name) && is_executable(rtrim($d, '/') . '/' . $name)) {
                return rtrim($d, '/') . '/' . $name;
            }
        }
        return '';
    }

    /**
     * What a PHP-FPM says about itself: its settings folder (scan dir), its extension folder and
     * the extra .ini files it reads.
     *
     * @param string $fpm
     * @return array{scan: string, ext: string, inis: string[]}
     */
    static function Info($fpm)
    {
        list(, $out) = self::Run(escapeshellarg($fpm) . ' -i');
        $info = array('scan' => '', 'ext' => '', 'inis' => array());
        if (preg_match('/^Scan this dir for additional \.ini files => (.+)$/m', $out, $m) && trim($m[1]) !== '(none)') {
            $info['scan'] = rtrim(trim($m[1]), '/');
        }
        if (preg_match('/^extension_dir => (\S+)/m', $out, $m)) {
            $info['ext'] = rtrim(trim($m[1]), '/');
        }
        if (preg_match('/^Additional \.ini files parsed => (.+?)(?=^\S[^=\n]*=> |\z)/ms', $out, $m) && trim($m[1]) !== '(none)') {
            $info['inis'] = array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $m[1]))));
        }
        return $info;
    }

    /**
     * Another .ini that already loads Snuffleupagus (a distribution's package), or ''.
     *
     * @param string[] $inis
     * @return string
     */
    static function OtherLoader(array $inis)
    {
        foreach ($inis as $f) {
            if (basename($f) === ph::INI || !is_file($f)) {
                continue;
            }
            if (preg_match('/^\s*(zend_)?extension\s*=\s*["\']?[^;\n]*snuffleupagus/mi', (string) @file_get_contents($f))) {
                return $f;
            }
        }
        return '';
    }

    /**
     * Bring one PHP to what the administrator chose.
     *
     * @param string $v
     * @param string $kind system or bundle
     * @param string $mode off, watch or on
     * @return string A line for the daemon's log ('' when nothing changed).
     */
    static function Apply($v, $kind, $mode)
    {
        $fpm = self::Fpm($v, $kind);
        if ($fpm === '') {
            return $mode === 'off' ? '' : self::Problem($v, 'Its PHP-FPM program was not found.');
        }
        $info = self::Info($fpm);
        if ($info['scan'] === '' || !is_dir($info['scan'])) {
            return $mode === 'off' ? '' : self::Problem($v, 'This PHP reads no settings folder (scan dir), so the add-on can not switch anything on in it.');
        }
        $ini = $info['scan'] . '/' . ph::INI;
        $rules = $info['scan'] . '/' . ph::RULES;
        if ($mode === 'off') {
            $had = is_file($ini) || is_file($rules);
            if ($had) {
                @unlink($ini);
                @unlink($rules);
                self::Reload($v);
            }
            ph::SetStatus($v, 'off');
            return $had ? 'switched off' : '';
        }
        $other = self::OtherLoader($info['inis']);
        if ($other !== '') {
            return self::Problem($v, 'Snuffleupagus is already switched on by %s (a package of the system?). Remove that first; the add-on leaves it alone.', $other);
        }
        // the extension: built into Sentora's PHP bundles; for the system's PHP, Sentora's build from the release
        if ($kind === 'bundle') {
            $so = 'snuffleupagus.so';
            if ($info['ext'] === '' || !is_file($info['ext'] . '/' . $so)) {
                return self::Problem($v, 'This copy of PHP %s was installed before Sentora\'s PHP builds carried Snuffleupagus: update it in PHP Versions, or on the server as root: sentora-php-install %s (it keeps its settings).', $v);
            }
            $sp = self::BundleSp($v);
        } else {
            $so = self::SYSTEM_SO;
            $got = self::SystemSo($v, $info['ext']);
            if ($got['error'] !== '') {
                return self::Problem($v, $got['error']);
            }
            $sp = $got['version'];
        }
        $text = ph::RulesFile($mode, ph::Chosen(), ph::Allowed());
        $iniText = "; Written by Sentora's Web Hardening add-on: change it in the panel (Server Admin > Web Hardening).\n"
            . 'extension=' . $so . "\n" . 'sp.configuration_file=' . $rules . "\n";
        if (is_file($ini) && is_file($rules) && (string) @file_get_contents($ini) === $iniText && (string) @file_get_contents($rules) === $text) {
            $was = ph::Status($v);
            if ($was['st_state_vc'] !== $mode || $was['st_sp_vc'] !== $sp) {
                ph::SetStatus($v, $mode, '', $sp);
            }
            return '';
        }
        // the new rules are tried on their own first: PHP must start with them and Snuffleupagus may not complain
        $try = $info['scan'] . '/.' . ph::RULES . '.new';
        if (@file_put_contents($try, $text) === false) {
            return self::Problem($v, 'Its settings folder %s could not be written.', $info['scan']);
        }
        @chmod($try, 0644);
        list($rc, $out) = self::Run(escapeshellarg($fpm) . ' -n -d ' . escapeshellarg('extension_dir=' . $info['ext']) . ' -d ' . escapeshellarg('extension=' . $so)
            . ' -d ' . escapeshellarg('sp.configuration_file=' . $try) . ' -m');
        if ($rc !== 0 || !preg_match('/^snuffleupagus$/mi', $out) || strpos($out, '][config][') !== false) {
            @unlink($try);
            $why = '';
            foreach (explode("\n", $out) as $l) {
                if (stripos($l, 'snuffleupagus') !== false || stripos($l, 'error') !== false) {
                    $why = trim($l);
                    break;
                }
            }
            return self::Problem($v, 'PHP did not start with Snuffleupagus and these rules, so nothing was changed (%s).', $why !== '' ? $why : 'exit ' . $rc);
        }
        $oldIni = is_file($ini) ? (string) file_get_contents($ini) : null;
        $oldRules = is_file($rules) ? (string) file_get_contents($rules) : null;
        rename($try, $rules);
        self::Write($ini, $iniText);
        if (!self::Reload($v)) {
            // back as it was
            if ($oldIni === null) {
                @unlink($ini);
                @unlink($rules);
            } else {
                self::Write($ini, $oldIni);
                self::Write($rules, (string) $oldRules);
            }
            self::Reload($v);
            return self::Problem($v, 'Its PHP-FPM did not run again with the new settings, so they were taken back.');
        }
        ph::SetStatus($v, $mode, '', $sp);
        return ($mode === 'watch' ? 'watching (nothing blocked)' : 'on') . ', Snuffleupagus ' . ($sp !== '' ? $sp : '?');
    }

    /**
     * Write a file (0644) through a new file in the same folder (it keeps the folder's SELinux label).
     *
     * @param string $file
     * @param string $text
     * @return bool
     */
    static function Write($file, $text)
    {
        $tmp = dirname($file) . '/.' . basename($file) . '.new';
        if (@file_put_contents($tmp, $text) === false) {
            return false;
        }
        @chmod($tmp, 0644);
        return @rename($tmp, $file);
    }

    /**
     * Note a problem and return it for the log.
     *
     * @param string $v
     * @param string $text English, %s for $arg.
     * @param string $arg
     * @return string
     */
    static function Problem($v, $text, $arg = '')
    {
        $note = $arg !== '' ? sprintf(str_replace('%s', '%1$s', $text), $arg) : $text;
        $was = ph::Status($v);
        if ($was['st_state_vc'] !== 'problem' || $was['st_note_tx'] !== $note) {
            ph::SetStatus($v, 'problem', $note, $was['st_sp_vc']);
            return $note;
        }
        return '';
    }

    /**
     * Reload a PHP-FPM (restart when reload does not work), then check that it runs.
     *
     * @param string $v
     * @return bool
     */
    static function Reload($v)
    {
        $svc = escapeshellarg(sys_accountphp::ServiceName($v));
        if (self::Run('systemctl reload ' . $svc)[0] !== 0) {
            self::Run('systemctl restart ' . $svc);
        }
        for ($i = 0; $i < 10; $i++) {
            if (self::Run('systemctl is-active --quiet ' . $svc)[0] === 0) {
                return true;
            }
            if (!self::$run) {
                sleep(1);
            }
        }
        return false;
    }

    /**
     * The Snuffleupagus version a PHP bundle was built with (from its BUILD-INFO).
     *
     * @param string $v
     * @return string
     */
    static function BundleSp($v)
    {
        $t = (string) @file_get_contents(sys_phpversions::BASE_DIR . $v . '/BUILD-INFO');
        return preg_match('/^Snuffleupagus ([0-9][0-9.]*)/m', $t, $m) ? $m[1] : '';
    }

    /**
     * php-bundles.json from the release (once per run).
     *
     * @return array|false
     */
    static function BuildList()
    {
        if (self::$list === null) {
            $url = 'https://github.com/' . sys_phpbundles::REPO . '/releases/download/' . sys_phpbundles::TAG . '/php-bundles.json';
            $body = self::$fetch ? call_user_func(self::$fetch, $url) : sys_phpbundles::Fetch($url);
            $j = json_decode((string) $body, true);
            self::$list = is_array($j) ? $j : false;
        }
        return self::$list;
    }

    /**
     * Put Sentora's Snuffleupagus for the system PHP in its extension folder: downloaded from the
     * release when it is missing or a newer build is there (checked once a day), and only used when
     * its SHA-256 is the one php-bundles.json gives.
     *
     * @param string $v
     * @param string $ext The PHP's extension folder.
     * @return array{version: string, error: string}
     */
    static function SystemSo($v, $ext)
    {
        if ($ext === '' || !is_dir($ext)) {
            return array('version' => '', 'error' => 'This PHP\'s extension folder was not found.');
        }
        $file = $ext . '/' . self::SYSTEM_SO;
        $have = json_decode(ph::Get('so:' . $v), true);
        $have = is_array($have) ? $have : array();
        $present = is_file($file) && isset($have['sha256']) && hash_equals((string) $have['sha256'], (string) hash_file('sha256', $file));
        if ($present && (int) ($have['checked'] ?? 0) > time() - 86400) {
            return array('version' => (string) ($have['version'] ?? ''), 'error' => '');
        }
        $list = self::BuildList();
        $tag = sys_phpbundles::Tag();
        $want = is_array($list) && isset($list['snuffleupagus'][$tag][$v]) && is_array($list['snuffleupagus'][$tag][$v]) ? $list['snuffleupagus'][$tag][$v] : null;
        if ($want === null || !preg_match('/^[a-f0-9]{64}$/', (string) ($want['sha256'] ?? '')) || !preg_match('/^[A-Za-z0-9._-]+\.so$/', (string) ($want['file'] ?? ''))) {
            if ($present) {
                // the list could not be read (GitHub away): the file already checked stays
                return array('version' => (string) ($have['version'] ?? ''), 'error' => '');
            }
            return array('version' => '', 'error' => $list === false
                ? 'The list of Sentora\'s builds (php-bundles.json) could not be read from GitHub; tried again on the next run.'
                : sprintf('Sentora has no Snuffleupagus build for PHP %s on this system (%s) yet: run Actions > PHP bundles for it.', $v, $tag));
        }
        if ($present && hash_equals((string) $have['sha256'], (string) $want['sha256'])) {
            $have['checked'] = time();
            ph::Set('so:' . $v, json_encode($have));
            return array('version' => (string) ($have['version'] ?? ''), 'error' => '');
        }
        $url = 'https://github.com/' . sys_phpbundles::REPO . '/releases/download/' . sys_phpbundles::TAG . '/' . $want['file'];
        $body = self::$fetch ? call_user_func(self::$fetch, $url) : sys_phpbundles::Fetch($url);
        if (!is_string($body) || !hash_equals((string) $want['sha256'], hash('sha256', $body))) {
            if ($present) {
                return array('version' => (string) ($have['version'] ?? ''), 'error' => '');
            }
            return array('version' => '', 'error' => 'Snuffleupagus could not be downloaded, or it was not the file php-bundles.json names (SHA-256); tried again on the next run.');
        }
        if (!self::Write($file, $body)) {
            return array('version' => '', 'error' => sprintf('The extension folder %s could not be written.', $ext));
        }
        ph::Set('so:' . $v, json_encode(array('sha256' => $want['sha256'], 'version' => (string) ($want['version'] ?? ''), 'checked' => time())));
        return array('version' => (string) ($want['version'] ?? ''), 'error' => '');
    }

    /**
     * Read what Snuffleupagus wrote to the system log since the last run (journalctl).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return int New lines kept.
     */
    static function ReadLog()
    {
        global $zdbh;
        $cursor = ph::Get('cursor');
        $on = false;
        foreach (array_keys(ph::Versions()) as $v) {
            $on = $on || ph::Mode($v) !== 'off';
        }
        if (!$on && $cursor === '') {
            return 0;
        }
        $cmd = 'journalctl -t snuffleupagus -o json --no-pager -n 2000 ' . ($cursor !== '' ? escapeshellarg('--after-cursor=' . $cursor) : '--since=-1h');
        list($rc, $out) = self::Run($cmd);
        if ($rc !== 0) {
            return 0;
        }
        $n = 0;
        foreach (explode("\n", $out) as $line) {
            $j = json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            if (isset($j['__CURSOR']) && is_string($j['__CURSOR'])) {
                $cursor = $j['__CURSOR'];
            }
            $p = isset($j['MESSAGE']) && is_string($j['MESSAGE']) ? ph::Parse($j['MESSAGE']) : null;
            if (!$p || $p['feature'] === 'config') {
                continue;
            }
            $t = isset($j['__REALTIME_TIMESTAMP']) && ctype_digit((string) $j['__REALTIME_TIMESTAMP']) ? (int) floor($j['__REALTIME_TIMESTAMP'] / 1000000) : time();
            ph::Add(array('time' => $t, 'account' => ph::AccountOf($p['file']), 'feature' => $p['feature'], 'action' => $p['action'],
                'message' => $p['text'], 'file' => $p['file'], 'line' => $p['line'], 'ip' => $p['ip']));
            $n++;
        }
        ph::Set('cursor', $cursor);
        return $n;
    }

    /**
     * Switch it off everywhere (the remove step).
     *
     * @return string[]
     */
    static function AllOff()
    {
        $log = array();
        ph::Set('ms:mode', 'off');
        $line = ph_modsec::Apply();
        if ($line !== '') {
            $log[] = 'Web Hardening, ' . $line;
        }
        foreach (ph::Versions() as $v => $kind) {
            $line = self::Apply($v, $kind, 'off');
            if ($line !== '') {
                $log[] = 'Web Hardening, PHP ' . $v . ': ' . $line;
            }
            // the build it downloaded for the system PHP goes too (a bundle's own stays: it is part of the bundle)
            $fpm = $kind === 'system' ? self::Fpm($v, $kind) : '';
            if ($fpm !== '') {
                $info = self::Info($fpm);
                if ($info['ext'] !== '' && is_file($info['ext'] . '/' . self::SYSTEM_SO)) {
                    @unlink($info['ext'] . '/' . self::SYSTEM_SO);
                }
                ph::Set('so:' . $v, '');
            }
        }
        return $log;
    }
}
