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
ph_daemon::$run = function ($cmd) {
    if (strpos($cmd, 'systemctl ') === 0) {
        $GLOBALS['calls'][] = $cmd;
        return array(strpos($cmd, 'is-active') !== false ? $GLOBALS['active'] : 0, '');
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
for ($i = 0; $i < 1005; $i++) {
    $many[] = jline("[snuffleupagus][1.2.3.4][disabled_function][drop] n$i in $W/hosted/bob/a.php on line 1");
}
$GLOBALS['journal'] = implode("\n", $many);
run_daemon();
global $zdbh;
check((int) $zdbh->query("SELECT COUNT(*) FROM x_ph_caught")->fetchColumn() === 1000, 'at most 1000 kept (the oldest go)');
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
page(1, 'Clear', array());
check(count(ph::Caught()) === 0, 'the list can be emptied');
page(1, 'Rules', array('inRule' => array_fill_keys(ph::Chosen() ?: array('random', 'xxe', 'samesite', 'mail', 'putenv', 'basedir', 'curl', 'eval'), '1')));
run_daemon();
check(ph::Status($v83)['st_state_vc'] === 'watch' && ph::Status('8.4')['st_state_vc'] === 'on', 'the daemon applies what was saved');

// ---------------------------------------------------------------------------------------------
section('Install and remove');
$zdbh->exec("INSERT INTO x_modules (mo_id_pk, mo_folder_vc) VALUES (7, 'php_hardening')");
$zdbh->exec("INSERT INTO x_groups (ug_id_pk, ug_name_vc) VALUES (1, 'Administrators'), (2, 'Resellers'), (3, 'Users')");
$zdbh->exec("INSERT INTO x_permissions (pe_group_fk, pe_module_fk) VALUES (2, 7), (3, 7)");
list($rc, $o) = sh('cd ' . escapeshellarg(__DIR__ . '/root') . ' && ' . escapeshellarg($PHP) . ' ' . escapeshellarg("$MOD/deploy/install.run"));
$perm = $zdbh->query("SELECT pe_group_fk FROM x_permissions WHERE pe_module_fk = 7")->fetchAll(PDO::FETCH_COLUMN);
check($rc === 0 && array_map('intval', $perm) === array(1), 'install: administrators only', $o . ' ' . json_encode($perm));
sh('cd ' . escapeshellarg(__DIR__ . '/root') . ' && ' . escapeshellarg($PHP) . ' ' . escapeshellarg("$MOD/deploy/upgrade.run"));
check((int) $zdbh->query("SELECT COUNT(*) FROM x_permissions WHERE pe_module_fk = 7")->fetchColumn() === 1, 'upgrade: still once');
// remove (zppy includes it in its own run; here the commands really run, so systemctl is a stand-in on PATH)
file_put_contents("$W/sbin/systemctl", "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg("$W/systemctl.log") . "\n");
chmod("$W/sbin/systemctl", 0755);
list($rc, $o) = sh('cd ' . escapeshellarg(__DIR__ . '/root') . ' && ' . escapeshellarg($PHP) . ' ' . escapeshellarg("$MOD/deploy/remove.run"));
check($rc === 0 && !is_file("$sysScan/" . ph::INI) && !is_file("$W/opt/sentora-php/8.4/etc/conf.d/" . ph::INI) && !is_file("$sysScan/" . ph::RULES), 'remove: switched off in every PHP', $o);
check(!is_file("$sysExt/sentora-snuffleupagus.so") && is_file("$W/opt/sentora-php/8.4/lib/ext/snuffleupagus.so"), 'remove: the downloaded build deleted; a bundle\'s own stays');
check(strpos((string) @file_get_contents("$W/systemctl.log"), "reload php$v83-fpm") !== false, 'remove: PHP-FPM reloaded', (string) @file_get_contents("$W/systemctl.log"));
check(ph::Mode($v83) === 'off' && ph::Mode('8.4') === 'off', 'remove: every PHP noted off (so installing it again switches nothing on)');

// the hook runs only from the command line
list(, $o) = sh(escapeshellarg($PHP) . ' -r ' . escapeshellarg('echo strpos(file_get_contents(' . var_export("$MOD/hooks/OnDaemonRun.hook.php", true) . '), "PHP_SAPI !== \'cli\'") !== false ? "yes" : "no";'));
check($o === 'yes', 'the daemon hook does nothing when a browser asks for it');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
