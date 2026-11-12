<?php
// PHP Hardening: the rules against the real Snuffleupagus, and the daemon's whole cycle against fake
// PHP-FPMs (this machine's PHP with their own settings folders), a fake systemctl, journalctl and
// GitHub. Run from run.sh (env PH_W, PH_DB, PH_SO, PH_MOD).
$W = getenv('PH_W');
$SO = getenv('PH_SO');
$MOD = getenv('PH_MOD');
$PHP = PHP_BINARY;
$pass = 0;
$fail = 0;
function check($ok, $what, $extra = '')
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $what\n";
    } else {
        $fail++;
        echo "  FAIL $what" . ($extra !== '' ? "\n       " . str_replace("\n", "\n       ", $extra) : '') . "\n";
    }
}
function section($t)
{
    echo "\n== $t\n";
}
function sh($cmd)
{
    $o = array();
    exec($cmd . ' 2>&1', $o, $rc);
    return array($rc, implode("\n", $o));
}

chdir(__DIR__ . '/root');
require __DIR__ . '/root/dryden/loader.inc.php';
require $MOD . '/code/ph.php';
require $MOD . '/code/daemon.php';
require $MOD . '/code/modsec.php';
require $MOD . '/code/controller.ext.php';
$v83 = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

// the folders: panel, two accounts' sites, the system PHP's settings and extension folders
foreach (array('panel', 'hosted/bob/public_html', 'hosted/alice/public_html', 'etc/php/fpm/conf.d', 'usr/lib/php/ext', 'sbin', 'opt/sentora-php', 'run') as $d) {
    @mkdir("$W/$d", 0755, true);
}
// a fake PHP-FPM: this PHP, its settings folder and extension folder given; with -n as asked
function fakeFpm($file, $scan, $ext)
{
    global $PHP;
    file_put_contents($file, "#!/bin/bash\nfor a; do [ \"\$a\" = -n ] && exec " . escapeshellarg($PHP) . " \"\$@\"; done\n"
        . "exec env PHP_INI_SCAN_DIR=" . escapeshellarg($scan) . " " . escapeshellarg($PHP) . " -d extension_dir=" . escapeshellarg($ext) . " \"\$@\"\n");
    chmod($file, 0755);
}
fakeFpm("$W/sbin/fake-php-fpm$v83", "$W/etc/php/fpm/conf.d", "$W/usr/lib/php/ext");
putenv('PATH=' . "$W/sbin:" . getenv('PATH'));
$sysFpm = "$W/sbin/fake-php-fpm$v83";
$sysScan = "$W/etc/php/fpm/conf.d";
$sysExt = "$W/usr/lib/php/ext";

// fake systemctl / journalctl; everything else really runs
$GLOBALS['calls'] = array();
$GLOBALS['active'] = 0;
$GLOBALS['journal'] = '';
$GLOBALS['refuse'] = '';
$GLOBALS['sysmodsec'] = false;
ph_daemon::$run = function ($cmd) {
    if (strpos($cmd, 'systemctl ') === 0) {
        $GLOBALS['calls'][] = $cmd;
        return array(strpos($cmd, 'is-active') !== false ? $GLOBALS['active'] : 0, '');
    }
    if (strpos($cmd, 'apache2ctl ') === 0) {
        $GLOBALS['calls'][] = $cmd;
        if ($cmd === 'apache2ctl configtest') {
            // what Apache would say: the test can make it refuse a file containing a word
            $f = getenv('PH_W') . '/apache/modsecurity.d/' . ph_modsec::FILE;
            if ($GLOBALS['refuse'] !== '' && is_file($f) && strpos(file_get_contents($f), $GLOBALS['refuse']) !== false) {
                return array(1, "AH00526: Syntax error on line 12 of $f:\nInvalid command '" . $GLOBALS['refuse'] . "'");
            }
            return array(0, 'Syntax OK');
        }
        return array(0, " core_module (static)\n" . (is_file(getenv('PH_W') . '/apache/modsecurity.d/' . ph_modsec::FILE) || $GLOBALS['sysmodsec'] ? " security2_module (shared)\n" : ''));
    }
    if (strpos($cmd, 'journalctl ') === 0) {
        $GLOBALS['calls'][] = $cmd;
        return array(0, $GLOBALS['journal']);
    }
    $o = array();
    exec($cmd . ' 2>&1', $o, $rc);
    return array($rc, implode("\n", $o));
};
// fake GitHub: php-bundles.json and Sentora's build for the system PHP
$soBody = file_get_contents($SO);
$soFile = "sentora-snuffleupagus-0.14.0-php$v83-ubuntu26.04-amd64.so";
$GLOBALS['json'] = array('bundles' => array(), 'snuffleupagus' => array('ubuntu26.04' => array($v83 => array('version' => '0.14.0', 'file' => $soFile, 'sha256' => hash('sha256', $soBody)))));
$GLOBALS['fetched'] = array();
$GLOBALS['serve'] = $soBody;
ph_daemon::$fetch = function ($url) use ($soFile) {
    $GLOBALS['fetched'][] = $url;
    if (substr($url, -strlen('/current/php-bundles.json')) === '/current/php-bundles.json') {
        return $GLOBALS['json'] === false ? false : json_encode($GLOBALS['json']);
    }
    return substr($url, -strlen('/current/' . $soFile)) === '/current/' . $soFile ? $GLOBALS['serve'] : false;
};
function run_daemon()
{
    ph_daemon::$list = null;
    $GLOBALS['calls'] = array();
    return ph_daemon::DaemonRun();
}
function reloads()
{
    return array_values(array_filter($GLOBALS['calls'], function ($c) { return strpos($c, 'reload') !== false || strpos($c, 'restart') !== false; }));
}

// ---------------------------------------------------------------------------------------------
section('Rules: every choice is a rules file Snuffleupagus accepts');
ph::Ensure();
$all = array_keys(ph::Catalog());
check(count($all) === 12, '12 rules to choose from');
check(ph::Chosen() === array('random', 'xxe', 'samesite', 'mail', 'putenv', 'basedir', 'curl', 'eval'), 'the defaults (8) until a choice is saved', implode(',', ph::Chosen()));
check(ph::Mode($v83) === 'off', 'every PHP starts off');
function tryRules($text, &$out = null)
{
    global $W, $SO, $PHP;
    file_put_contents("$W/try.rules", $text);
    list($rc, $out) = sh(escapeshellarg($PHP) . ' -n -d extension=' . escapeshellarg($SO) . ' -d sp.configuration_file=' . escapeshellarg("$W/try.rules") . ' -m');
    return $rc === 0 && preg_match('/^snuffleupagus$/m', $out) && strpos($out, '][config][') === false;
}
$allowed = array("$W/panel/", "$W/hosted/alice/");
foreach (array('on', 'watch') as $m) {
    check(tryRules(ph::RulesFile($m, ph::Chosen(), array()), $o), "defaults, $m: accepted", (string) $o);
    check(tryRules(ph::RulesFile($m, $all, $allowed), $o), "every rule with allowed folders, $m: accepted", (string) $o);
    foreach ($all as $id) {
        check(tryRules(ph::RulesFile($m, array($id), $allowed), $o), "rule '$id' alone, $m: accepted", (string) $o);
    }
}
$watch = ph::RulesFile('watch', $all, $allowed);
check(strpos($watch, 'harden_random') === false && strpos($watch, 'wrappers_whitelist') === false && strpos($watch, 'xxe') === false, 'watch only leaves out the rules that can not just log');
check(substr_count($watch, '.drop().simulation();') > 10 && strpos($watch, 'eval_blacklist.list("system') !== false && strpos($watch, '").simulation();') !== false, 'watch only: every block is a simulation');
check(preg_match('/\.drop\(\);/', $watch) === 0, 'watch only: nothing blocks');
check(ph::PathRegex('/var/sentora/hostdata/a.b/') === '^/var/sentora/hostdata/a\\\\.b/', 'a folder becomes an anchored regex (dots escaped, doubled for the rules file)', ph::PathRegex('/var/sentora/hostdata/a.b/'));

// ---------------------------------------------------------------------------------------------
section('Rules at work (the real extension): blocked, allowed, watched');
// runs a script in a folder with the given rules, logging to PHP's own error output so the line can be read
function runIn($dir, $code, $rules)
{
    global $W, $SO, $PHP;
    file_put_contents("$W/run.rules", str_replace('sp.log_media("syslog");', '', $rules));
    file_put_contents("$dir/t.php", "<?php\n" . $code . "\necho \"|END\";\n");
    list(, $out) = sh(escapeshellarg($PHP) . ' -n -d display_errors=stderr -d log_errors=0 -d extension=' . escapeshellarg($SO) . ' -d sp.configuration_file=' . escapeshellarg("$W/run.rules") . ' ' . escapeshellarg("$dir/t.php"));
    return $out;
}
$bob = "$W/hosted/bob/public_html";
$alice = "$W/hosted/alice/public_html";
$panel = "$W/panel";
$def = ph::RulesFile('on', ph::Chosen(), array("$W/panel/"));
$o = runIn($bob, 'echo "A"; mail("x@example.com", "s", "b", "", "-X/tmp/log.php"); echo "@@B";', $def);
check(strpos($o, 'A') !== false && strpos($o, '@@B') === false && strpos($o, '|END') === false, 'mail() with -X: blocked (the script stops)', $o);
$line = '';
foreach (explode("\n", $o) as $l) {
    if (strpos($l, '[snuffleupagus]') !== false) {
        $line = preg_replace('/^.*?(\[snuffleupagus\])/', '$1', $l);
    }
}
$p = ph::Parse($line);
check($p && $p['action'] === 'drop' && $p['feature'] === 'disabled_function' && $p['file'] === "$bob/t.php" && $p['line'] === 2, 'its log line is read: blocked, which file and line', $line . ' => ' . json_encode($p));
check($p && ph::AccountOf($p['file']) === 'bob', 'the file is bob\'s site');
check(ph::AccountOf("$W/panel/x.php") === '' && ph::AccountOf("$W/hosted/../x.php") === '', 'a file outside the hosting folders is nobody\'s');
$o = runIn($bob, 'echo "A"; echo @mail("x@example.com", "s", "b", "", "-fme@example.com") ? "" : ""; echo "@@B";', $def);
check(strpos($o, '|END') !== false, 'mail() with the usual -f sender: allowed', $o);
$o = runIn($bob, 'putenv("FOO=1"); echo getenv("FOO"); putenv("LD_PRELOAD=/tmp/x.so"); echo "@@B";', $def);
check(strpos($o, '1') !== false && strpos($o, '@@B') === false, 'putenv(): FOO allowed, LD_PRELOAD blocked', $o);
$o = runIn($bob, 'ini_set("memory_limit", "256M"); echo ini_get("memory_limit"); ini_set("open_basedir", "/"); echo "@@B";', $def);
check(strpos($o, '256M') !== false && strpos($o, '@@B') === false, 'ini_set(): memory_limit allowed, open_basedir blocked', $o);
$o = runIn($bob, 'echo "A"; eval(\'echo "E"; system("echo SHELL");\'); echo "@@B";', $def);
check(strpos($o, 'E') !== false && strpos($o, 'SHELL') === false && strpos($o, '@@B') === false, 'eval(): code runs, system() inside it is blocked', $o);
$o = runIn($bob, 'system("echo SHELL"); echo "@@B";', $def);
check(strpos($o, 'SHELL') !== false && strpos($o, '|END') !== false, 'the defaults leave ordinary shell commands alone', $o);
$o = runIn($bob, 'echo mt_rand(1, 6) > 0 ? "R" : "";', $def);
check(strpos($o, 'R|END') !== false, 'random numbers still work (safer random)', $o);
$strict = ph::RulesFile('on', $all, array("$W/panel/", "$W/hosted/alice/"));
$o = runIn($bob, 'system("echo SHELL"); echo "@@B";', $strict);
check(strpos($o, 'SHELL') === false && strpos($o, '@@B') === false, 'shell commands blocked: bob\'s system() stops', $o);
$o = runIn($alice, 'system("echo SHELL"); echo "@@B";', $strict);
check(strpos($o, 'SHELL') !== false && strpos($o, '|END') !== false, 'an account left alone (alice): its system() runs', $o);
$o = runIn($panel, 'system("echo SHELL"); echo "@@B";', $strict);
check(strpos($o, 'SHELL') !== false && strpos($o, '|END') !== false, 'the panel\'s own scripts: always left alone', $o);
@mkdir("$W/hosted/alice2/public_html", 0755, true);
$o = runIn("$W/hosted/alice2/public_html", 'system("echo SHELL"); echo "@@B";', $strict);
check(strpos($o, 'SHELL') === false, 'alice\'s exception does not cover "alice2" (the folder ends with /)', $o);
$o = runIn($bob, 'touch(__DIR__ . "/f"); chmod(__DIR__ . "/f", 0644); echo "C"; chmod(__DIR__ . "/f", 0777); echo "@@B";', ph::RulesFile('on', array('chmod'), array()));
check(strpos($o, 'C') !== false && strpos($o, '@@B') === false, 'chmod(): 0644 allowed, 0777 blocked', $o);
$inj = ph::RulesFile('on', array('inject'), array());
$o = runIn($bob, 'echo shell_exec("echo PLAIN"); echo shell_exec("echo X; echo QQ9"); echo "@@B";', $inj);
check(strpos($o, 'PLAIN') !== false && strpos($o, "\nQQ9") === false && strpos($o, '@@B') === false, 'injection characters: a plain command runs, one with ; is blocked', $o);
$o = runIn($bob, 'echo shell_exec("convert in.png -resize 50% out.png 2>/dev/null; true") === null ? "" : ""; echo "@@B";', $inj);
check(strpos($o, '|END') === false, '(and a ; anywhere in it counts)', $o);
$o = runIn($bob, 'echo @file_get_contents("data://text/plain,DATA"); echo @file_get_contents("expect://id") === false ? "X" : "?"; echo "@@B";', ph::RulesFile('on', array('wrappers'), array()));
check(strpos($o, 'DATA') !== false && strpos($o, 'X@@B') !== false, 'stream wrappers: data:// works, expect:// refused', $o);
$wa = ph::RulesFile('watch', $all, array("$W/panel/"));
$o = runIn($bob, 'system("echo SHELL"); mail("x@example.com", "s", "b", "", "-X/tmp/l"); echo "@@B";', $wa);
check(strpos($o, 'SHELL') !== false && strpos($o, '|END') !== false, 'watch only: nothing is stopped', $o);
$sims = array_filter(array_map('ph::Parse', array_map(function ($l) { return preg_replace('/^.*?(\[snuffleupagus\])/', '$1', $l); }, preg_grep('/\[snuffleupagus\]/', explode("\n", $o)))));
check(count($sims) >= 2 && count(array_filter($sims, function ($s) { return $s['action'] === 'simulation'; })) === count($sims), 'watch only: what would have been blocked is logged (as simulation)', $o);

// ---------------------------------------------------------------------------------------------
section('Daemon: the system PHP (download, check, switch on)');
check(run_daemon() === array() && !$GLOBALS['fetched'] && !reloads(), 'all off: nothing is done, nothing downloaded');
check(!in_array('journalctl', array_map(function ($c) { return strtok($c, ' '); }, $GLOBALS['calls']), true), 'all off and never on: the log is not read');
ph::Set('mode:' . $v83, 'watch');
$log = run_daemon();
check(is_file("$sysExt/sentora-snuffleupagus.so") && hash_file('sha256', "$sysExt/sentora-snuffleupagus.so") === hash('sha256', $soBody), 'Sentora\'s build downloaded into the PHP\'s extension folder', implode("\n", $log));
check(is_file("$sysScan/" . ph::INI) && is_file("$sysScan/" . ph::RULES), 'its .ini and rules written in the PHP\'s settings folder');
check(substr(sprintf('%o', fileperms("$sysScan/" . ph::RULES)), -4) === '0644' && substr(sprintf('%o', fileperms("$sysExt/sentora-snuffleupagus.so")), -4) === '0644', 'readable files (0644)');
check(count(glob("$sysScan/.*.new")) === 0 && count(glob("$sysExt/.*.new")) === 0, 'no files left half-written');
check(reloads() === array("systemctl reload 'php$v83-fpm'"), 'PHP-FPM reloaded once', implode("\n", $GLOBALS['calls']));
$st = ph::Status($v83);
check($st['st_state_vc'] === 'watch' && $st['st_sp_vc'] === '0.14.0', 'state: watching, Snuffleupagus 0.14.0', json_encode($st));
check(count($log) === 1 && strpos($log[0], 'watching') !== false, 'one line in the daemon\'s log', implode("\n", $log));
list(, $m) = sh(escapeshellarg($sysFpm) . ' -m');
check(preg_match('/^snuffleupagus$/m', $m) === 1 && strpos($m, '][config][') === false, 'that PHP now starts with Snuffleupagus', $m);
file_put_contents("$bob/w.php", '<?php system("echo SHELL");');
ph::Set('rules', implode(',', $all));
run_daemon();
list(, $m) = sh(escapeshellarg($sysFpm) . ' ' . escapeshellarg("$bob/w.php"));
check(strpos($m, 'SHELL') !== false, 'watch only in that PHP: bob\'s system() still runs', $m);
check(count(reloads()) === 1 && strpos(file_get_contents("$sysScan/" . ph::RULES), 'pcntl_exec') !== false, 'new rules: written, PHP-FPM reloaded');
ph::Set('mode:' . $v83, 'on');
$log = run_daemon();
list(, $m) = sh(escapeshellarg($sysFpm) . ' ' . escapeshellarg("$bob/w.php"));
check(strpos($m, 'SHELL') === false, 'on: bob\'s system() is blocked in that PHP', $m . "\n" . implode("\n", $log));
check(ph::Status($v83)['st_state_vc'] === 'on', 'state: on');
$GLOBALS['fetched'] = array();
check(run_daemon() === array() && !reloads() && !$GLOBALS['fetched'], 'the next run: nothing changed, nothing reloaded, nothing downloaded (checked once a day)');
// the daily check finds a newer build
$have = json_decode(ph::Get('so:' . $v83), true);
$have['checked'] = time() - 90000;
ph::Set('so:' . $v83, json_encode($have));
$newer = $soBody . "\0";
$GLOBALS['json']['snuffleupagus']['ubuntu26.04'][$v83] = array('version' => '0.14.1', 'file' => $soFile, 'sha256' => hash('sha256', $newer));
$GLOBALS['serve'] = $newer;
run_daemon();
check(hash_file('sha256', "$sysExt/sentora-snuffleupagus.so") === hash('sha256', $newer) && ph::Status($v83)['st_sp_vc'] === '0.14.1', 'a day later a newer build is fetched and used');
// put the real one back
$have = json_decode(ph::Get('so:' . $v83), true);
$have['checked'] = 0;
ph::Set('so:' . $v83, json_encode($have));
$GLOBALS['json']['snuffleupagus']['ubuntu26.04'][$v83] = array('version' => '0.14.0', 'file' => $soFile, 'sha256' => hash('sha256', $soBody));
$GLOBALS['serve'] = $soBody;
run_daemon();
check(hash_file('sha256', "$sysExt/sentora-snuffleupagus.so") === hash('sha256', $soBody), '(back to the real build)');
// GitHub away: the file already there stays in use
$have = json_decode(ph::Get('so:' . $v83), true);
$have['checked'] = 0;
ph::Set('so:' . $v83, json_encode($have));
$GLOBALS['json'] = false;
check(run_daemon() === array() && ph::Status($v83)['st_state_vc'] === 'on', 'GitHub not reachable: the checked file stays in use, nothing changes');
$GLOBALS['json'] = array('bundles' => array(), 'snuffleupagus' => array('ubuntu26.04' => array($v83 => array('version' => '0.14.0', 'file' => $soFile, 'sha256' => hash('sha256', $soBody)))));

section('Daemon: refusals and putting things back');
// someone changed the downloaded file: it no longer matches, so it is fetched again
file_put_contents("$sysExt/sentora-snuffleupagus.so", 'tampered');
run_daemon();
check(hash_file('sha256', "$sysExt/sentora-snuffleupagus.so") === hash('sha256', $soBody), 'a changed extension file is replaced with the checked build');
// a download that is not the file the list names
unlink("$sysExt/sentora-snuffleupagus.so");
ph::Set('so:' . $v83, '');
$GLOBALS['serve'] = 'not the real file';
$log = run_daemon();
$st = ph::Status($v83);
check(!is_file("$sysExt/sentora-snuffleupagus.so") && $st['st_state_vc'] === 'problem' && strpos($st['st_note_tx'], 'SHA-256') !== false, 'a download with the wrong SHA-256: not used, the problem shown', json_encode($st) . "\n" . implode("\n", $log));
check(count($log) === 1 && run_daemon() === array(), 'the same problem is logged once, not every run');
$GLOBALS['serve'] = $soBody;
run_daemon();
check(ph::Status($v83)['st_state_vc'] === 'on', 'once the right file comes, it is on again');
// no build for this system
$GLOBALS['json']['snuffleupagus'] = array();
unlink("$sysExt/sentora-snuffleupagus.so");
ph::Set('so:' . $v83, '');
run_daemon();
check(strpos(ph::Status($v83)['st_note_tx'], 'Actions > PHP bundles') !== false, 'no build for this system yet: says to run Actions > PHP bundles', ph::Status($v83)['st_note_tx']);
$GLOBALS['json']['snuffleupagus'] = array('ubuntu26.04' => array($v83 => array('version' => '0.14.0', 'file' => $soFile, 'sha256' => hash('sha256', $soBody))));
run_daemon();
// PHP-FPM does not come back: everything is put back as it was
$before = file_get_contents("$sysScan/" . ph::RULES);
ph::Set('exempt', 'alice');
$GLOBALS['active'] = 3;
$log = run_daemon();
$GLOBALS['active'] = 0;
check(file_get_contents("$sysScan/" . ph::RULES) === $before && ph::Status($v83)['st_state_vc'] === 'problem', 'PHP-FPM did not come back: the old rules are put back, the problem shown', implode("\n", $log));
check(count(reloads()) >= 2, 'and PHP-FPM reloaded again with the old ones', implode("\n", $GLOBALS['calls']));
run_daemon();
check(strpos(file_get_contents("$sysScan/" . ph::RULES), "hosted/alice/") !== false && ph::Status($v83)['st_state_vc'] === 'on', 'tried again next run: alice\'s exception is in, on');
// the first switch-on fails: nothing of ours stays
ph::Set('mode:' . $v83, 'off');
run_daemon();
check(!is_file("$sysScan/" . ph::INI) && !is_file("$sysScan/" . ph::RULES) && ph::Status($v83)['st_state_vc'] === 'off' && count(reloads()) === 1, 'off: its files taken out, PHP-FPM reloaded');
check(run_daemon() === array() && !reloads(), 'off again: nothing to do');
ph::Set('mode:' . $v83, 'on');
$GLOBALS['active'] = 3;
run_daemon();
$GLOBALS['active'] = 0;
check(!is_file("$sysScan/" . ph::INI) && !is_file("$sysScan/" . ph::RULES), 'the first switch-on fails: none of its files stay');
// a package of the system already loads Snuffleupagus
run_daemon();
file_put_contents("$sysScan/20-snuffleupagus.ini", "extension=snuffleupagus.so\n");
$log = run_daemon();
check(ph::Status($v83)['st_state_vc'] === 'problem' && strpos(ph::Status($v83)['st_note_tx'], '20-snuffleupagus.ini') !== false, 'another .ini already loads Snuffleupagus: left alone, said which', implode("\n", $log));
file_put_contents("$sysScan/20-snuffleupagus.ini", ";extension=snuffleupagus.so\n");
run_daemon();
check(ph::Status($v83)['st_state_vc'] === 'on', 'one that is commented out does not count');
unlink("$sysScan/20-snuffleupagus.ini");
// no FPM program
rename($sysFpm, "$sysFpm.away");
run_daemon();
check(ph::Status($v83)['st_state_vc'] === 'problem' && strpos(ph::Status($v83)['st_note_tx'], 'not found') !== false, 'its PHP-FPM program missing: the problem shown');
rename("$sysFpm.away", $sysFpm);
run_daemon();
check(ph::Status($v83)['st_state_vc'] === 'on', '(back)');

section('Daemon: Sentora\'s PHP bundles');
// 8.4 with Snuffleupagus built in, 8.2 an older build without it
foreach (array('8.4' => true, '8.2' => false) as $bv => $has) {
    $b = "$W/opt/sentora-php/$bv";
    @mkdir("$b/sbin", 0755, true);
    @mkdir("$b/etc/conf.d", 0755, true);
    @mkdir("$b/lib/ext", 0755, true);
    fakeFpm("$b/sbin/php-fpm", "$b/etc/conf.d", "$b/lib/ext");
    if ($has) {
        copy($SO, "$b/lib/ext/snuffleupagus.so");
        file_put_contents("$b/BUILD-INFO", "PHP 8.4.30\nSnuffleupagus 0.14.0: lib/php/extensions, off (the PHP Hardening add-on switches it on).\n");
    }
}
check(array_keys(ph::Versions()) === array($v83, '8.2', '8.4'), 'every PHP is listed: the system\'s, then the extra ones', implode(',', array_keys(ph::Versions())));
ph::Set('mode:8.4', 'on');
ph::Set('mode:8.2', 'watch');
$GLOBALS['fetched'] = array();
$log = run_daemon();
check(ph::Status('8.4')['st_state_vc'] === 'on' && ph::Status('8.4')['st_sp_vc'] === '0.14.0' && strpos(file_get_contents("$W/opt/sentora-php/8.4/etc/conf.d/" . ph::INI), "extension=snuffleupagus.so\n") !== false, '8.4: its own snuffleupagus.so switched on (version from BUILD-INFO)', implode("\n", $log));
check(in_array("systemctl reload 'sentora-php8.4-fpm'", $GLOBALS['calls'], true), 'its own service reloaded');
check(!array_filter($GLOBALS['fetched'], function ($u) { return strpos($u, '.so') !== false; }), 'nothing downloaded for a bundle');
list(, $m) = sh(escapeshellarg("$W/opt/sentora-php/8.4/sbin/php-fpm") . ' ' . escapeshellarg("$bob/w.php"));
check(strpos($m, 'SHELL') === false, '8.4 blocks bob\'s system()', $m);
check(ph::Status('8.2')['st_state_vc'] === 'problem' && strpos(ph::Status('8.2')['st_note_tx'], 'PHP Versions') !== false && !is_file("$W/opt/sentora-php/8.2/etc/conf.d/" . ph::INI), '8.2 (older build): says to update it in PHP Versions, nothing written');
// the extension of 8.4 broken: PHP is tried first, so nothing changes
ph::Set('exempt', 'alice,bob');
copy("$W/opt/sentora-php/8.4/lib/ext/snuffleupagus.so", "$W/so.keep");
file_put_contents("$W/opt/sentora-php/8.4/lib/ext/snuffleupagus.so", 'broken');
$before = file_get_contents("$W/opt/sentora-php/8.4/etc/conf.d/" . ph::RULES);
$log = run_daemon();
check(file_get_contents("$W/opt/sentora-php/8.4/etc/conf.d/" . ph::RULES) === $before && ph::Status('8.4')['st_state_vc'] === 'problem' && strpos(ph::Status('8.4')['st_note_tx'], 'did not start') !== false, 'PHP does not start with the new rules: nothing changed, the problem shown', implode("\n", $log));
check(!in_array("systemctl reload 'sentora-php8.4-fpm'", $GLOBALS['calls'], true) && !glob("$W/opt/sentora-php/8.4/etc/conf.d/.*.new"), 'not reloaded, no test file left');
copy("$W/so.keep", "$W/opt/sentora-php/8.4/lib/ext/snuffleupagus.so");
// a bundle updated (its conf.d replaced): put back on the next run
unlink("$W/opt/sentora-php/8.4/etc/conf.d/" . ph::INI);
run_daemon();
check(is_file("$W/opt/sentora-php/8.4/etc/conf.d/" . ph::INI) && ph::Status('8.4')['st_state_vc'] === 'on', 'its file gone (a bundle update): put back on the next run');
ph::Set('exempt', 'alice');
ph::Set('mode:8.2', 'off');
run_daemon();

// ---------------------------------------------------------------------------------------------
section('What it caught: read from the system log');
$cur = 0;
function jline($msg, $t = null)
{
    global $cur;
    $cur++;
    return json_encode(array('__CURSOR' => 's=abc;i=' . $cur, '__REALTIME_TIMESTAMP' => (string) (($t ?: time()) * 1000000), 'MESSAGE' => $msg, 'SYSLOG_IDENTIFIER' => 'snuffleupagus'));
}
$GLOBALS['journal'] = implode("\n", array(
    jline("[snuffleupagus][203.0.113.9][disabled_function][drop] Aborted execution on call of the function 'system' in $W/hosted/bob/public_html/shell.php on line 4", 1700000000),
    jline("[snuffleupagus][0.0.0.0][config][log] Unexpected keyword 'x' on line 3"),
    jline("[snuffleupagus][198.51.100.2][eval][simulation] A call to 'system' was tried in eval. dropping it. in $W/hosted/carol/x.php on line 9"),
    json_encode(array('__CURSOR' => 's=abc;i=99', 'MESSAGE' => array(91, 98))),
    'not json',
));
$GLOBALS['journal'] = str_replace('s=abc;i=99', 's=abc;i=last', $GLOBALS['journal']);
$log = run_daemon();
$c = ph::Caught();
check(count($c) === 2, 'two lines kept (the settings complaint and the unreadable ones skipped)', json_encode($c));
check($c && $c[1]['ca_account_vc'] === 'bob' && $c[1]['ca_action_vc'] === 'drop' && $c[1]['ca_ip_vc'] === '203.0.113.9' && (int) $c[1]['ca_line_in'] === 4 && (int) $c[1]['ca_time_in'] === 1700000000, 'bob\'s: blocked, address, line, time');
check($c && $c[0]['ca_account_vc'] === 'carol' && $c[0]['ca_action_vc'] === 'simulation', 'carol\'s: watched');
check(ph::Get('cursor') === 's=abc;i=last', 'where it stopped is kept');
$GLOBALS['journal'] = '';
run_daemon();
$jc = array_values(preg_grep('/^journalctl /', $GLOBALS['calls']));
check($jc && strpos($jc[0], "'--after-cursor=s=abc;i=last'") !== false && strpos($jc[0], '-t snuffleupagus') !== false, 'the next run reads on from there', implode("\n", $jc));
$many = array();
for ($i = 0; $i < ph::KEEP + 5; $i++) {
    $many[] = jline("[snuffleupagus][1.2.3.4][disabled_function][drop] n$i in $W/hosted/bob/a.php on line 1");
}
$GLOBALS['journal'] = implode("\n", $many);
run_daemon();
global $zdbh;
check((int) $zdbh->query("SELECT COUNT(*) FROM x_ph_caught")->fetchColumn() === ph::KEEP, 'at most ' . ph::KEEP . ' kept (the oldest go)');
$GLOBALS['journal'] = jline('[snuffleupagus][1.2.3.4][disabled_function][drop] <script>alert(1)</script> in ' . $W . '/hosted/bob/<b>.php on line 1') . "\n"
    . jline("[snuffleupagus][1.2.3.4][disabled_function][simulation] watched in $W/hosted/bob/a.php on line 1");
run_daemon();


// ---------------------------------------------------------------------------------------------
section('ModSecurity: the settings file');
foreach (array('modsec', 'modsec/data', 'apache/modsecurity.d', 'log', 'panel/etc/modsecurity/coreruleset/rules') as $d) {
    @mkdir("$W/$d", 0755, true);
}
touch("$W/modsec/mod_security2.so");
touch("$W/modsec/mod_unique_id.so");
file_put_contents("$W/panel/etc/modsecurity/coreruleset/VERSION", "OWASP CRS 4.25.2 (git commit b9818c3718bc75b99403aed6c4b23773004c5412), from https://github.com/coreruleset/coreruleset\n");
file_put_contents("$W/apache/httpd.conf", "ServerName localhost\nIncludeOptional $W/apache/modsecurity.d/*.conf\nInclude $W/apache/httpd-vhosts.conf\n");
$msFile = "$W/apache/modsecurity.d/" . ph_modsec::FILE;
check(ph_modsec::Mode() === 'off' && ph_modsec::Level() === 1 && ph_modsec::Threshold() === 5, 'starts off, level 1, score 5');
check(ph_modsec::CrsVersion() === 'OWASP CRS 4.25.2', 'the rules\' version is read');
$c = ph_modsec::Conf('watch', 2, 10, array('bob.example'), array('942100', '920350'));
check(strpos($c, 'SecRuleEngine DetectionOnly') !== false && strpos($c, 'SecRuleEngine On') === false, 'watch only: DetectionOnly');
check(strpos(ph_modsec::Conf('on', 1, 5, array(), array()), "SecRuleEngine On\n") !== false, 'on: On');
check(strpos($c, "LoadModule security2_module \"$W/modsec/mod_security2.so\"") !== false && strpos($c, "LoadModule unique_id_module \"$W/modsec/mod_unique_id.so\"") !== false, 'the modules come from the settings (modsec_module, modsec_unique_id_module)');
check(strpos($c, "<IfModule !security2_module>") !== false, '(loaded only when nothing loaded it already)');
check(strpos($c, "SecDataDir \"$W/modsec/data\"") !== false && strpos($c, "SecAuditLog \"$W/log/modsec_audit.log\"") !== false, 'data folder and audit log from the settings (modsec_data_dir, apache_log_dir)');
check(strpos($c, 'SecAuditLogParts AHZ') !== false, 'the audit log keeps no headers or bodies (parts A, H, Z)');
check(strpos($c, 'tx.blocking_paranoia_level=2') !== false && strpos($c, 'tx.inbound_anomaly_score_threshold=10') !== false, 'level and score');
check(strpos($c, 'SecRule SERVER_NAME "@rx ^(?:www\\.)?(?:panel\\.example)$" "id:99100,phase:1,pass,t:none,t:lowercase,nolog,ctl:ruleEngine=DetectionOnly"') !== false, 'the panel itself: watched only (never blocked: no lock-out)', $c);
check(preg_match('/SecRule SERVER_NAME "@rx (\\^\\(\\?:\\[0-9\\][^"]+)" "id:99102,phase:1,pass,t:none,t:lowercase,nolog,ctl:ruleEngine=DetectionOnly"/', $c, $ipm) === 1, 'the panel by the server\'s IP address: watched only too', $c);
$iprx = '/' . str_replace('/', '\\/', stripcslashes($ipm[1])) . '/';
check(preg_match($iprx, '172.16.1.250') && preg_match($iprx, '[fe80::1]') && preg_match($iprx, '') && !preg_match($iprx, 'bob.example') && !preg_match($iprx, 'deadbeef') && !preg_match($iprx, '1.2.3.4.evil.example'),
    '(the IP rule matches addresses only, never a site\'s name)', $iprx);
eval('class ctrl_branding { static function Addresses() { return array(array(\'host\' => \'panel.reseller.example\'), array(\'host\' => \'bad name\')); } }');
check(ph_modsec::PanelHosts() === array('panel.example', 'panel.reseller.example'), 'resellers\' panel addresses (Branding) are the panel too; nonsense left out', json_encode(ph_modsec::PanelHosts()));
check(strpos($c, 'SecRule SERVER_NAME "@rx ^(?:www\\.)?(?:bob\\.example)$" "id:99101,phase:1,pass,t:none,t:lowercase,nolog,ctl:ruleEngine=Off"') !== false, 'the chosen domains: left alone (with or without www.)', $c);
check(strpos(ph_modsec::Conf('on', 1, 5, array(), array()), 'ruleEngine=Off') === false, 'no domains chosen: nothing is left alone');
check(strpos($c, 'SecRuleRemoveById 942100 920350') !== false, 'rules switched off');
$pIdx = strpos($c, 'crs-setup.conf.example'); $sIdx = strpos($c, 'SecRule SERVER_NAME'); $rIdx = strpos($c, '/rules/*.conf'); $xIdx = strpos($c, 'SecRuleRemoveById');
check($pIdx < $sIdx && $sIdx < $rIdx && $rIdx < $xIdx, 'in order: setup, domains left alone, the rules, rules switched off');
check(strpos(ph_modsec::Conf('on', 1, 5, array(), array()), 'SecRuleRemoveById') === false, 'nothing switched off: no SecRuleRemoveById line');

section('ModSecurity: the daemon');
$GLOBALS['calls'] = array();
check(ph_modsec::Apply() === '' && !is_file($msFile) && !$GLOBALS['calls'], 'off: nothing written, Apache not touched');
ph::Set('ms:mode', 'watch');
$line = ph_modsec::Apply();
check(is_file($msFile) && strpos(file_get_contents($msFile), 'DetectionOnly') !== false, 'watch only: its file written', $line);
check(in_array('apache2ctl configtest', $GLOBALS['calls'], true) && in_array("systemctl reload 'apache2'", $GLOBALS['calls'], true), 'Apache tested, then reloaded', implode("\n", $GLOBALS['calls']));
$st = ph::Status('modsec');
check($st['st_state_vc'] === 'watch' && $st['st_sp_vc'] === 'OWASP CRS 4.25.2', 'state: watching, with the rules\' version', json_encode($st));
check(substr(sprintf('%o', fileperms($msFile)), -4) === '0644' && !glob("$W/apache/modsecurity.d/.*.new"), 'readable, nothing half-written');
$GLOBALS['calls'] = array();
check(ph_modsec::Apply() === '' && !$GLOBALS['calls'], 'the next run: nothing to do (Apache not even tested)');
ph::Set('ms:mode', 'on');
$GLOBALS['calls'] = array();
ph_modsec::Apply();
check(strpos(file_get_contents($msFile), "SecRuleEngine On\n") !== false && ph::Status('modsec')['st_state_vc'] === 'on', 'on: written, state on');
// Apache refuses: the old file stays
$before = file_get_contents($msFile);
ph::Set('ms:skiprules', '942100');
$GLOBALS['calls'] = array();
$GLOBALS['refuse'] = 'SecRuleRemoveById';
$line = ph_modsec::Apply();
$GLOBALS['refuse'] = '';
check(file_get_contents($msFile) === $before && ph::Status('modsec')['st_state_vc'] === 'problem' && strpos(ph::Status('modsec')['st_note_tx'], "Invalid command 'SecRuleRemoveById'") !== false, 'Apache refuses the new file: the old one stays, Apache\'s complaint shown', $line);
check(!in_array("systemctl reload 'apache2'", $GLOBALS['calls'], true), '(and Apache is not reloaded)');
ph_modsec::Apply();
check(strpos(file_get_contents($msFile), 'SecRuleRemoveById 942100') !== false && ph::Status('modsec')['st_state_vc'] === 'on', 'accepted next run: in');
// Apache does not come back after the reload
$before = file_get_contents($msFile);
ph::Set('ms:level', '2');
$GLOBALS['active'] = 3;
$GLOBALS['calls'] = array();
ph_modsec::Apply();
$GLOBALS['active'] = 0;
check(file_get_contents($msFile) === $before && ph::Status('modsec')['st_state_vc'] === 'problem' && in_array("systemctl restart 'apache2'", $GLOBALS['calls'], true), 'Apache did not run after the reload: the old file back, Apache restarted');
ph::Set('ms:level', '1');
ph_modsec::Apply();
// the system's own ModSecurity already on
ph::Set('ms:mode', 'off');
ph_modsec::Apply();
check(!is_file($msFile) && ph::Status('modsec')['st_state_vc'] === 'off', 'off: its file taken out, Apache reloaded');
$GLOBALS['sysmodsec'] = true;
ph::Set('ms:mode', 'watch');
ph_modsec::Apply();
$GLOBALS['sysmodsec'] = false;
check(!is_file($msFile) && strpos(ph::Status('modsec')['st_note_tx'], 'a2dismod -q -f security2') !== false, 'the system\'s own ModSecurity already loaded: left alone, says how to switch it off (modsec_off_cmd)', ph::Status('modsec')['st_note_tx']);
// not installed, no rules, no Include line
rename("$W/modsec/mod_security2.so", "$W/modsec/away.so");
ph_modsec::Apply();
check(strpos(ph::Status('modsec')['st_note_tx'], 'sentora-update installs it') !== false && !is_file($msFile), 'ModSecurity not installed: says sentora-update installs it');
rename("$W/modsec/away.so", "$W/modsec/mod_security2.so");
file_put_contents("$W/apache/httpd.conf", "ServerName localhost\n");
ph_modsec::Apply();
check(strpos(ph::Status('modsec')['st_note_tx'], 'sentora-update adds it') !== false && !is_file($msFile), 'httpd.conf without the Include line: says sentora-update adds it');
file_put_contents("$W/apache/httpd.conf", "IncludeOptional $W/apache/modsecurity.d/*.conf\n");
rename("$W/panel/etc/modsecurity/coreruleset/VERSION", "$W/v.away");
ph_modsec::Apply();
check(strpos(ph::Status('modsec')['st_note_tx'], 'rules are missing') !== false, 'the rules missing: says so');
rename("$W/v.away", "$W/panel/etc/modsecurity/coreruleset/VERSION");
ph_modsec::Apply();
check(ph::Status('modsec')['st_state_vc'] === 'watch' && is_file($msFile), 'all there again: watching');
// new rules (sentora-update brought a new CRS): the file changes, Apache is tested again
file_put_contents("$W/panel/etc/modsecurity/coreruleset/VERSION", "OWASP CRS 4.25.3 (git commit x)\n");
$GLOBALS['calls'] = array();
ph_modsec::Apply();
check(strpos(file_get_contents($msFile), 'OWASP CRS 4.25.3') !== false && in_array('apache2ctl configtest', $GLOBALS['calls'], true) && ph::Status('modsec')['st_sp_vc'] === 'OWASP CRS 4.25.3', 'a newer rule set (sentora-update): tested and noted');

section('ModSecurity: what it caught (audit log)');
$zdbh->exec("DELETE FROM x_ph_caught");
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_group_fk) VALUES (30, 'bobsite', 3)");
$zdbh->exec("INSERT INTO x_vhosts (vh_name_vc, vh_acc_fk) VALUES ('bob.example', 30)");
$zdbh->exec("INSERT INTO x_vhosts (vh_name_vc, vh_acc_fk, vh_deleted_ts) VALUES ('gone.example', 30, 1)");
function audit($id, $ts, $ip, $host, $uri, array $msgs, $blocked)
{
    $o = "--$id-A--\n[$ts] ZXk1aBcDeF\@AAAAAAAAAAAA $ip 51234 10.0.0.5 443\n--$id-H--\n";
    foreach ($msgs as $m) {
        $o .= 'Message: ' . ($blocked && $m[0] === '949110' ? 'Access denied with code 403 (phase 2). ' : 'Warning. ') . 'Pattern match at ARGS:q. [file "/etc/x/rules/R.conf"] [line "1"] [id "' . $m[0] . '"] [msg "' . $m[1] . '"] [severity "CRITICAL"] [ver "OWASP_CRS/4.25.2"] [hostname "' . $host . '"] [uri "' . $uri . '"] [unique_id "ZXk1aBcDeF@AAAAAAAAAAAA"]' . "\n";
    }
    if ($blocked) {
        $o .= "Action: Intercepted (phase 2)\n";
    }
    return $o . "Apache-Handler: proxy:unix:/run/x.sock|fcgi://localhost\nStopwatch: 1700000000000000 1234 (- - -)\nProducer: ModSecurity for Apache/2.9.12 (http://www.modsecurity.org/); OWASP_CRS/4.25.2.\nServer: Apache\nEngine-Mode: \"" . ($blocked ? 'ENABLED' : 'DETECTION_ONLY') . "\"\n\n--$id-Z--\n\n";
}
$audit = "$W/log/modsec_audit.log";
file_put_contents($audit,
    audit('3c6a5b1f', '10/Nov/2026:13:20:01.123456 -0600', '203.0.113.9', 'www.bob.example', '/index.php', array(array('941100', 'XSS Attack Detected via libinjection'), array('941110', 'XSS Filter - Category 1: Script Tag Vector'), array('949110', 'Inbound Anomaly Score Exceeded (Total Score: 10)'), array('980170', 'Anomaly Scores: (Inbound Scores: blocking=10)')), true)
    . audit('4d7b6c2a', '10/Nov/2026:13:21:00 -0600', '198.51.100.7', 'nobody.example', '/wp-login.php', array(array('942100', 'SQL Injection Attack Detected via libinjection'), array('949110', 'Inbound Anomaly Score Exceeded (Total Score: 5)')), false)
    . audit('5e8c7d3b', '10/Nov/2026:13:22:00.5 --0600', '198.51.100.8', 'bob.example', '/ok.php', array(array('920350', 'Host header is a numeric IP address')), false)
    . "--6f9d8e4c-A--\n[10/Nov/2026:13:23:00 -0600] half 1.2.3.4 1 2 3\n--6f9d8e4c-H--\nMessage: Warning. [id \"949110\"]\n");
$n = ph_modsec::ReadLog();
$c = ph::Caught();
check($n === 2 && count($c) === 2, 'two requests caught (one below the score is not; the half-written last entry waits)', json_encode($c));
$b = $c[1];
check($b['ca_feature_vc'] === 'modsecurity' && $b['ca_action_vc'] === 'drop' && $b['ca_ip_vc'] === '203.0.113.9' && $b['ca_account_vc'] === 'bobsite' && $b['ca_file_tx'] === 'www.bob.example/index.php', 'blocked: address, the account whose domain it is (www. too), host and address', json_encode($b));
check($b['ca_message_tx'] === 'Rules 941100 XSS Attack Detected via libinjection; 941110 XSS Filter - Category 1: Script Tag Vector', 'its rules with their numbers (CRS\'s bookkeeping left out)', $b['ca_message_tx']);
check((int) $b['ca_time_in'] === (new DateTime('2026-11-10 13:20:01', new DateTimeZone('-0600')))->getTimestamp(), 'its time');
check($c[0]['ca_action_vc'] === 'simulation' && $c[0]['ca_account_vc'] === '' && strpos($c[0]['ca_message_tx'], '942100') !== false, 'watch only: would block; a domain not on this server is nobody\'s');
$ent = ph_modsec::Entries(audit('1a2b3c4d', '10/Nov/2026:13:22:00.483725 --0600', '1.2.3.4', 'bob.example', '/', array(array('942100', 'x'), array('949110', 'y')), false));
check(count($ent) === 1 && $ent[0]['time'] === (new DateTime('2026-11-10 13:22:00', new DateTimeZone('-0600')))->getTimestamp(), 'ModSecurity\'s own way of writing a zone west of Greenwich ("--0600"): its real time, not the time it was read', json_encode($ent));
check(ph::KindOf('modsecurity', $c[1]['ca_message_tx']) === 'xss' && $c[1]['ca_kind_vc'] === 'xss' && $c[0]['ca_kind_vc'] === 'sqli', 'each line knows its kind of attack');
check(ph_modsec::ReadLog() === 0, 'read again: nothing new');
file_put_contents($audit, "Message: Warning. [id \"941100\"] [msg \"x\"] [hostname \"bob.example\"] [uri \"/a\"]\nAction: Intercepted (phase 2)\n\n--6f9d8e4c-Z--\n\n", FILE_APPEND);
check(ph_modsec::ReadLog() === 1, 'the rest of the half entry, once written: read');
// rotated: a new file is read from its start
rename($audit, "$audit.1");
file_put_contents($audit, audit('7a0e9f5d', '10/Nov/2026:14:00:00 -0600', '192.0.2.1', 'bob.example', '/x', array(array('932100', 'Remote Command Execution: Unix Command Injection'), array('949110', 'Inbound Anomaly Score Exceeded (Total Score: 5)')), true));
check(ph_modsec::ReadLog() === 1 && strpos(ph::Caught(1)[0]['ca_message_tx'], '932100') !== false, 'after log rotation: the new file is read from its start');
check(ph_modsec::AccountOfHost('BOB.example:443') === 'bobsite' && ph_modsec::AccountOfHost('gone.example') === '' && ph_modsec::AccountOfHost("x' OR 1=1 --") === '', 'host to account: case and port ignored, deleted domains and nonsense are nobody\'s');

$GLOBALS['journal'] = jline('[snuffleupagus][1.2.3.4][disabled_function][drop] <script>alert(1)</script> in ' . $W . '/hosted/bob/<b>.php on line 1') . "\n"
    . jline("[snuffleupagus][1.2.3.4][disabled_function][simulation] watched in $W/hosted/bob/a.php on line 1");
run_daemon();
// ---------------------------------------------------------------------------------------------
section('The page');
$zdbh->exec("DELETE FROM x_accounts");
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_group_fk) VALUES (1, 'zadmin', 1), (2, 'reseller1', 2), (3, 'bob', 3), (4, 'alice', 3)");
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_group_fk, ac_deleted_ts) VALUES (5, 'gone', 3, 1)");
function page($me, $action = '', $post = array())
{
    $GLOBALS['PH_ME'] = $me;
    module_controller::$msg = array();
    $_POST = $post;
    if ($action !== '') {
        call_user_func(array('module_controller', 'do' . $action));
    }
    return module_controller::PageHtml();
}
$h = page(2);
check(strpos($h, 'Only administrators') !== false && strpos($h, 'inMode') === false, 'a reseller sees no settings');
$before = ph::Mode($v83);
page(2, 'Modes', array('inMode' => array($v83 => 'off')));
page(3, 'Rules', array('inRule' => array()));
page(3, 'Exempt', array('inExempt' => 'bob'));
check(ph::Mode($v83) === $before && ph::Get('rules') === implode(',', $all) && ph::Get('exempt') === 'alice', 'a reseller or client can not change anything');
$h = page(1);
check(strpos($h, 'PHP ' . $v83) !== false && strpos($h, 'PHP 8.4') !== false && strpos($h, 'PHP 8.2') !== false, 'the administrator sees every PHP');
check(strpos($h, '&lt;script&gt;alert(1)') !== false && strpos($h, '<script>alert') === false && strpos($h, '&lt;b&gt;.php') !== false, 'caught lines are shown escaped');
check(strpos($h, 'Would block') !== false && strpos($h, 'Blocked') !== false, 'blocked and watched lines told apart');
check(strpos($h, 'did not start') === false, '(no old problem shown)');
$h = page(1, 'Modes', array('inMode' => array($v83 => 'watch', '8.4' => 'bogus', '9.9' => 'on')));
check(ph::Mode($v83) === 'watch' && ph::Mode('8.4') === 'on' && ph::Get('mode:9.9') === '', 'modes saved; an unknown mode or version ignored');
check(strpos($h, 'Waiting for the daemon') !== false && strpos($h, 'next run') !== false, 'until the daemon runs: "waiting"');
page(1, 'Rules', array('inRule' => array('mail' => '1', 'shell' => '1', 'nope' => '1')));
check(ph::Chosen() === array('mail', 'shell'), 'rules saved (unknown ones ignored)');
page(1, 'Rules', array());
check(ph::Chosen() === array() && ph::Get('rules') === '-', 'no rules at all is saved as that (not the defaults again)');
check(tryRules(ph::RulesFile('on', array(), ph::Allowed()), $o), '(a rules file with no rules is accepted too)', (string) $o);
$h = page(1, 'Exempt', array('inExempt' => 'bob, nobody gone'));
check(ph::Get('exempt') === 'alice' && strpos($h, 'nobody, gone') !== false, 'an unknown or deleted account: not saved, named');
page(1, 'Exempt', array('inExempt' => "bob alice,bob"));
check(ph::Exempt() === array('bob', 'alice'), 'accounts saved (once each)');
page(1, 'Exempt', array('inExempt' => "bob/../x"));
check(ph::Exempt() === array('bob', 'alice'), 'a path is not an account');
$zdbh->exec("INSERT INTO x_ph_settings (ph_name_vc, ph_value_tx) VALUES ('exempt', 'x/../../etc,bob') ON CONFLICT(ph_name_vc) DO UPDATE SET ph_value_tx = excluded.ph_value_tx");
check(ph::Exempt() === array('bob'), 'and one written straight into the table is ignored too');
$h = page(1);
check(strpos($h, 'ModSecurity (in Apache)') !== false && strpos($h, 'name="inMsMode"') !== false, 'the ModSecurity part is on the page');
page(3, 'ModSec', array('inMsMode' => 'off', 'inMsLevel' => '1', 'inMsThreshold' => '5'));
check(ph_modsec::Mode() === 'watch', 'a client can not change ModSecurity');
$h = page(1, 'ModSec', array('inMsMode' => 'on', 'inMsLevel' => '4', 'inMsThreshold' => '5'));
check(ph_modsec::Mode() === 'watch' && strpos($h, 'choose from the lists') !== false, 'a level not offered: not saved');
page(1, 'ModSec', array('inMsMode' => 'on', 'inMsLevel' => '2', 'inMsThreshold' => '10'));
check(ph_modsec::Mode() === 'on' && ph_modsec::Level() === 2 && ph_modsec::Threshold() === 10, 'ModSecurity: mode, level and score saved');
$h = page(1, 'ModSecSkip', array('inMsDomains' => 'www.BOB.example, other.example', 'inMsRules' => '942100'));
check(strpos($h, 'other.example is not a domain on this server') !== false, 'a domain not on this server: not saved, named');
$h = page(1, 'ModSecSkip', array('inMsDomains' => 'bob.example', 'inMsRules' => '942100 1;rm'));
check(strpos($h, 'is not a rule number') !== false, 'something that is not a rule number: not saved');
page(1, 'ModSecSkip', array('inMsDomains' => 'www.BOB.example', 'inMsRules' => '942100, 920350 942100'));
check(ph_modsec::SkipDomains() === array('bob.example') && ph_modsec::SkipRules() === array('942100', '920350'), 'domains (www. and case dropped) and rules saved once each');
$zdbh->exec("UPDATE x_ph_settings SET ph_value_tx = 'bob.example,x\") ctl:ruleEngine=Off\"' WHERE ph_name_vc = 'ms:skipdomains'");
check(ph_modsec::SkipDomains() === array('bob.example'), 'one written straight into the table that is no domain is ignored (nothing reaches Apache\'s settings)');
page(1, 'Clear', array());
check(count(ph::Caught()) === 0, 'the list can be emptied');

section('The live list of what was caught');
check(ph::Get('read_at') !== '' && (int) ph::Get('read_at') > time() - 3600, 'a daemon run notes when it last read the logs');
$h = page(1);
$top = ph::NewestId();
check(strpos($h, 'id="ph-caught"') !== false && strpos($h, 'data-last="' . $top . '"') !== false && strpos($h, 'data-feed="modules/php_hardening/code/caught.php"') !== false, 'the list knows its newest line and where to ask for more');
check(strpos($h, 'The server last looked at') !== false && strpos($h, 'Look now') !== false && strpos($h, 'Pause') !== false && strpos($h, 'Nothing yet.') !== false, 'says when the server last looked; Look now and Pause; "Nothing yet" while empty');
check(strpos($h, 'data-tab="stats"') !== false && strpos($h, 'class="form-control ph-q"') !== false && strpos($h, '<option value="sqli">SQL injection</option>') !== false, 'tabs (list, statistics), search, and the kinds of attack to pick');
check(strpos(file_get_contents("$MOD/module.zpm"), 'modules/php_hardening/assets/ph.js') !== false && is_file("$MOD/assets/ph.js"), 'the page loads its script');
$f = module_controller::Feed(array('after' => (string) $top));
check($f['count'] === 0 && $f['rows'] === '' && $f['last'] === $top, 'nothing new: nothing sent');
$zdbh->exec("DELETE FROM x_ph_daily");
$add = function ($t, $acc, $feat, $act, $msg, $file, $ip) { ph::Add(array('time' => $t, 'account' => $acc, 'feature' => $feat, 'action' => $act, 'message' => $msg, 'file' => $file, 'line' => 0, 'ip' => $ip)); };
$add(time(), 'bob', 'modsecurity', 'drop', 'Rules 942100 <script>alert(2)</script>', 'bob.example/x<b>.php', '203.0.113.5');
$add(time(), '', 'disabled_function', 'simulation', 'second 100%_done', '/hosted/bob/a.php', '');
$f = module_controller::Feed(array('after' => (string) $top));
check($f['count'] === 2 && $f['last'] === ph::NewestId() && strpos($f['rows'], 'second') < strpos($f['rows'], 'alert'), 'new lines come, newest first, with the newest number', json_encode($f));
check(strpos($f['rows'], '&lt;script&gt;alert(2)') !== false && strpos($f['rows'], '<script>') === false && strpos($f['rows'], '&lt;b&gt;.php') !== false, 'they come escaped by the server');
check(strpos($f['rows'], 'SQL injection') !== false && strpos($f['rows'], 'PHP: forbidden functions') !== false, 'with their kind of attack');
check(module_controller::Feed(array('after' => (string) $f['last']))['count'] === 0, 'asked again: nothing twice');
check(strpos($f['looked'], 'The server last looked at') !== false && strpos($f['looked'], '&') === false, 'the "last looked" words come as plain text');
// many lines: pages, search, filters
for ($i = 0; $i < 120; $i++) {
    $add(time() - $i * 600, 'alice', 'modsecurity', $i % 3 ? 'simulation' : 'drop', 'Rules 920350 Host header is a numeric IP address; 931130 Possible Remote File Inclusion', '172.16.1.250/x?u=http://a', '172.16.6.' . ($i % 4));
}
$p1 = module_controller::Feed(array('before' => '0'));
check($p1['count'] === ph::PAGE && $p1['more'] === true && $p1['total'] === 122, 'a page at a time (50), with how many there are', json_encode(array($p1['count'], $p1['more'], $p1['total'])));
$p2 = module_controller::Feed(array('before' => (string) $p1['oldest']));
$p3 = module_controller::Feed(array('before' => (string) $p2['oldest']));
check($p2['count'] === 50 && $p3['count'] === 22 && $p3['more'] === false && $p2['oldest'] < $p1['oldest'], 'older pages until the end, no line twice');
check(module_controller::Feed(array('before' => '0', 'q' => 'alert'))['total'] === 1 && module_controller::Feed(array('before' => '0', 'q' => '172.16.6.3'))['total'] === 30, 'search: words, addresses');
check(module_controller::Feed(array('before' => '0', 'q' => '100%_'))['total'] === 1 && module_controller::Feed(array('before' => '0', 'q' => '%'))['total'] === 1 && module_controller::Feed(array('before' => '0', 'q' => '_'))['total'] === 1, 'search: % and _ are only characters (not wildcards)');
check(module_controller::Feed(array('before' => '0', 'q' => "' OR 1=1 --"))['total'] === 0, '(a search can not change the query)');
check(module_controller::Feed(array('before' => '0', 'kind' => 'rfi'))['total'] === 120 && module_controller::Feed(array('before' => '0', 'kind' => 'nope'))['total'] === 122, 'by kind (an unknown kind: ignored)');
check(module_controller::Feed(array('before' => '0', 'act' => 'drop'))['total'] === 41 && module_controller::Feed(array('before' => '0', 'act' => 'watch'))['total'] === 81, 'blocked, or would block');
check(module_controller::Feed(array('before' => '0', 'guard' => 'php'))['total'] === 1 && module_controller::Feed(array('before' => '0', 'guard' => 'modsecurity', 'kind' => 'sqli'))['total'] === 1, 'by guard, and filters together');
$lv = module_controller::Feed(array('after' => (string) $p1['last'], 'q' => 'alert'));
check($lv['count'] === 0, 'new lines follow the filters too');
// statistics
$st = module_controller::Feed(array('stats' => '1', 'days' => '30'))['stats'];
check($st['drop'] === 41 && $st['watch'] === 81 && $st['sources'] === 5, 'statistics: blocked, would block, different sources', json_encode(array($st['drop'], $st['watch'], $st['sources'])));
check($st['kinds'][0]['key'] === 'rfi' && $st['kinds'][0]['n'] === 120 && $st['kinds'][0]['label'] === 'Remote file inclusion', 'kinds of attack, most first, with their words');
check(count($st['days']) === 30 && end($st['days'])['day'] === date('Y-m-d'), 'each of the 30 days (today last)');
check($st['ips'][0]['n'] === 30 && count($st['hours']) === 24 && $st['targets'][0]['label'] === 'alice', 'where from, what they went after, the last 24 hours');
check(count(module_controller::Feed(array('stats' => '1', 'days' => '99999'))['stats']['days']) === ph::DAYS && count(module_controller::Feed(array('stats' => '1', 'days' => 'x'))['stats']['days']) === 30, 'days: at most a year and a bit; nonsense: 30');
check(json_encode($st) !== false && strpos(json_encode($st), '<') === false, '(the statistics are numbers and plain words)');
// the counts outlive the list
page(1, 'Clear', array());
check(count(ph::Caught()) === 0 && module_controller::Feed(array('stats' => '1', 'days' => '30'))['stats']['drop'] === 41, 'emptying the list keeps the statistics');
$zdbh->exec("INSERT INTO x_ph_daily (dy_day_vc, dy_kind_vc, dy_action_vc, dy_count_in) VALUES ('2020-01-01', 'xss', 'drop', 5)");
ph::Prune();
check((int) $zdbh->query("SELECT COUNT(*) FROM x_ph_daily WHERE dy_day_vc = '2020-01-01'")->fetchColumn() === 0, 'daily counts older than ' . ph::DAYS . ' days go');
$src = file_get_contents("$MOD/code/caught.php");
check(strpos($src, "(int) \$phUser['usergroupid'] !== 1") !== false && strpos($src, 'http_response_code(403)') !== false && strpos($src, "mo_enabled_en") !== false
    && strpos($src, 'session_write_close') !== false && strpos($src, "REQUEST_METHOD'] !== 'GET'") !== false, 'the feed: administrators only, the module switched on, GET only');
$js = file_get_contents("$MOD/assets/ph.js");
check(substr_count($js, 'innerHTML') === 2 && substr_count($js, 'innerHTML = j.rows') === 1 && strpos($js, 't.innerHTML = html') !== false && strpos($js, 'textContent = j.looked') !== false, 'the script puts in only the rows the server escaped; everything else as text');
// Look now
page(3, 'LookNow', array());
check(ph::Get('looknow_at') === '', 'a client can not make the server look');
$h = page(1, 'LookNow', array());
check(strpos($h, 'could not be asked to look now') !== false && ph::Get('looknow_at') === '', 'without zsudo: told so, nothing noted');
ph::Set('looknow_at', (string) time());
$h = page(1, 'LookNow', array());
check(strpos($h, 'at most once a minute') !== false, 'at most once a minute');
ph::Set('looknow_at', '');
page(1, 'Rules', array('inRule' => array_fill_keys(ph::Chosen() ?: array('random', 'xxe', 'samesite', 'mail', 'putenv', 'basedir', 'curl', 'eval'), '1')));
run_daemon();
check(ph::Status($v83)['st_state_vc'] === 'watch' && ph::Status('8.4')['st_state_vc'] === 'on', 'the daemon applies what was saved');

// ---------------------------------------------------------------------------------------------
section('Install and remove');
$zdbh->exec("ALTER TABLE x_modules ADD COLUMN mo_name_vc TEXT");
$zdbh->exec("ALTER TABLE x_modules ADD COLUMN mo_desc_tx TEXT");
$zdbh->exec("INSERT INTO x_modules (mo_id_pk, mo_folder_vc, mo_name_vc) VALUES (7, 'php_hardening', 'PHP Hardening')");
$zdbh->exec("INSERT INTO x_groups (ug_id_pk, ug_name_vc) VALUES (1, 'Administrators'), (2, 'Resellers'), (3, 'Users')");
$zdbh->exec("INSERT INTO x_permissions (pe_group_fk, pe_module_fk) VALUES (2, 7), (3, 7)");
list($rc, $o) = sh('cd ' . escapeshellarg(__DIR__ . '/root') . ' && ' . escapeshellarg($PHP) . ' ' . escapeshellarg("$MOD/deploy/install.run"));
$perm = $zdbh->query("SELECT pe_group_fk FROM x_permissions WHERE pe_module_fk = 7")->fetchAll(PDO::FETCH_COLUMN);
check($rc === 0 && array_map('intval', $perm) === array(1), 'install: administrators only', $o . ' ' . json_encode($perm));
check($zdbh->query("SELECT mo_name_vc FROM x_modules WHERE mo_id_pk = 7")->fetchColumn() === 'Web Hardening', 'its name in the panel: Web Hardening (an upgrade from PHP Hardening changes it too)');
sh('cd ' . escapeshellarg(__DIR__ . '/root') . ' && ' . escapeshellarg($PHP) . ' ' . escapeshellarg("$MOD/deploy/upgrade.run"));
check((int) $zdbh->query("SELECT COUNT(*) FROM x_permissions WHERE pe_module_fk = 7")->fetchColumn() === 1, 'upgrade: still once');
// remove (zppy includes it in its own run; here the commands really run, so systemctl is a stand-in on PATH)
file_put_contents("$W/sbin/systemctl", "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg("$W/systemctl.log") . "\n");
chmod("$W/sbin/systemctl", 0755);
file_put_contents("$W/sbin/apache2ctl", "#!/bin/sh\necho \"apache2ctl \$@\" >> " . escapeshellarg("$W/systemctl.log") . "\n[ \"\$1\" = configtest ] && echo 'Syntax OK'\nexit 0\n");
chmod("$W/sbin/apache2ctl", 0755);
ph::Set('ms:mode', 'on');
ph_modsec::Apply();
check(is_file($msFile), '(ModSecurity on before removing)');
list($rc, $o) = sh('cd ' . escapeshellarg(__DIR__ . '/root') . ' && ' . escapeshellarg($PHP) . ' ' . escapeshellarg("$MOD/deploy/remove.run"));
check($rc === 0 && !is_file("$sysScan/" . ph::INI) && !is_file("$W/opt/sentora-php/8.4/etc/conf.d/" . ph::INI) && !is_file("$sysScan/" . ph::RULES), 'remove: switched off in every PHP', $o);
check(!is_file("$sysExt/sentora-snuffleupagus.so") && is_file("$W/opt/sentora-php/8.4/lib/ext/snuffleupagus.so"), 'remove: the downloaded build deleted; a bundle\'s own stays');
check(strpos((string) @file_get_contents("$W/systemctl.log"), "reload php$v83-fpm") !== false, 'remove: PHP-FPM reloaded', (string) @file_get_contents("$W/systemctl.log"));
check(ph::Mode($v83) === 'off' && ph::Mode('8.4') === 'off', 'remove: every PHP noted off (so installing it again switches nothing on)');
check(!is_file($msFile) && ph_modsec::Mode() === 'off' && strpos((string) @file_get_contents("$W/systemctl.log"), 'reload apache2') !== false, 'remove: ModSecurity switched off too (its file out, Apache reloaded)');

// the hook runs only from the command line
list(, $o) = sh(escapeshellarg($PHP) . ' -r ' . escapeshellarg('echo strpos(file_get_contents(' . var_export("$MOD/hooks/OnDaemonRun.hook.php", true) . '), "PHP_SAPI !== \'cli\'") !== false ? "yes" : "no";'));
check($o === 'yes', 'the daemon hook does nothing when a browser asks for it');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
