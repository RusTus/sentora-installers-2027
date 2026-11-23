<?php
// Web Hardening, bans and flood protection: the daemon's whole cycle against a fake fail2ban (its
// command answered here, its files really written), the page's actions with every refusal, and the
// flood filter's regular expressions on real access-log lines. Run from bans.sh (env PH_W, PH_DB, PH_MOD).
$W = getenv('PH_W');
$MOD = getenv('PH_MOD');
$PAGES = getenv('PH_PAGES');
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
        echo "  FAIL $what" . ($extra !== '' ? "\n       " . str_replace("\n", "\n       ", (string) $extra) : '') . "\n";
    }
}
function section($t)
{
    echo "\n== $t\n";
}

chdir(__DIR__ . '/root');
require __DIR__ . '/root/dryden/loader.inc.php';
require $MOD . '/code/ph.php';
require $MOD . '/code/daemon.php';
require $MOD . '/code/modsec.php';
require $MOD . '/code/bans.php';
require $MOD . '/code/controller.ext.php';

// a fake fail2ban: its folders, Sentora's own jail file (with the server's address), and its command
foreach (array('etc/fail2ban/jail.d', 'etc/fail2ban/filter.d', 'usr/bin', 'logs/domains/bob', 'logs/domains/_timing') as $d) {
    @mkdir("$W/$d", 0755, true);
}
file_put_contents("$W/etc/fail2ban/jail.d/sentora.conf", "[DEFAULT]\nbantime  = 1h\nignoreip = 127.0.0.1/8 ::1 198.51.100.9\n");
file_put_contents("$W/usr/bin/fail2ban-client", "#!/bin/sh\nexit 0\n");
$zdbh->exec("INSERT INTO x_settings (so_name_vc, so_value_tx, so_usereditable_en) VALUES ('fail2ban_dir', " . $zdbh->quote("$W/etc/fail2ban") . ", 'false')");
$GLOBALS['f2b'] = array('running' => true, 'refuse' => '', 'calls' => array(), 'jails' => array(
    'sshd' => array("203.0.113.5 \t2026-11-23 10:00:00 + 3600 = 2026-11-23 11:00:00"),
    'sentora-panel' => array(),
    'sentora-manual' => array()), 'reqtimeout' => true);
$client = "$W/usr/bin/fail2ban-client";
ph_daemon::$run = function ($cmd) use ($client) {
    $f = &$GLOBALS['f2b'];
    if (strpos($cmd, 'apache2ctl -M') === 0) {
        return array(0, " core_module (static)\n" . ($f['reqtimeout'] ? " reqtimeout_module (shared)\n" : ''));
    }
    $pre = escapeshellarg($client) . ' ';
    if (strpos($cmd, $pre) !== 0) {
        return array(127, 'not expected: ' . $cmd);
    }
    // the words, as the shell would hand them on (each one quoted by escapeshellarg)
    preg_match_all("/'((?:[^']|'\\\\'')*)'/", substr($cmd, strlen($pre)), $m);
    $w = array_map(function ($x) { return str_replace("'\\''", "'", $x); }, $m[1]);
    $f['calls'][] = implode(' ', $w);
    if ($w === array('-t')) {
        $jail = (string) @file_get_contents(getenv('PH_W') . '/etc/fail2ban/jail.d/' . ph_bans::JAIL_FILE);
        return $f['refuse'] !== '' && strpos($jail, $f['refuse']) !== false ? array(255, "ERROR  Failed during configuration: bad value '" . $f['refuse'] . "'") : array(0, 'OK: configuration test is successful');
    }
    if (!$f['running']) {
        return array(255, 'ERROR  Failed to access socket path: /var/run/fail2ban/fail2ban.sock. Is fail2ban running?');
    }
    if ($w === array('ping')) {
        return array(0, 'Server replied: pong');
    }
    if ($w === array('version')) {
        return array(0, '1.1.0');
    }
    if ($w === array('reload')) {
        return array(0, 'OK');
    }
    if ($w === array('status')) {
        return array(0, "Status\n|- Number of jail:\t" . count($f['jails']) . "\n`- Jail list:\t" . implode(', ', array_keys($f['jails'])));
    }
    if (count($w) === 4 && $w[0] === 'get' && $w[2] === 'banip' && $w[3] === '--with-time') {
        return isset($f['jails'][$w[1]]) ? array(0, implode("\n", $f['jails'][$w[1]])) : array(255, 'Sorry but the jail does not exist');
    }
    if (count($w) === 4 && $w[0] === 'set' && $w[2] === 'banip') {
        $f['jails'][$w[1]][] = $w[3] . " \t" . date('Y-m-d H:i:s') . ' + -1 = ' . date('Y-m-d H:i:s');
        return array(0, '1');
    }
    if (count($w) === 4 && $w[0] === 'set' && $w[2] === 'unbanip') {
        $f['jails'][$w[1]] = array_values(array_filter($f['jails'][$w[1]], function ($l) use ($w) { return strpos($l, $w[3] . ' ') !== 0; }));
        return array(0, '1');
    }
    if (count($w) === 2 && $w[0] === 'unban') {
        foreach ($f['jails'] as $j => $list) {
            $f['jails'][$j] = array_values(array_filter($list, function ($l) use ($w) { return strpos($l, $w[1] . ' ') !== 0; }));
        }
        return array(0, '1');
    }
    return array(255, 'unknown command');
};
function calls()
{
    $c = $GLOBALS['f2b']['calls'];
    $GLOBALS['f2b']['calls'] = array();
    return $c;
}
function page(array $get, array $post = array(), $action = '')
{
    module_controller::$msg = array();
    $_GET = $get;
    $_POST = $post;
    if ($action !== '') {
        call_user_func(array('module_controller', 'do' . $action));
    }
    return module_controller::PageHtml();
}
function save($name, $html)
{
    global $PAGES;
    if ($PAGES) {
        @mkdir($PAGES, 0755, true);
        file_put_contents("$PAGES/$name.html", $html);
    }
}
$jailFile = "$W/etc/fail2ban/jail.d/" . ph_bans::JAIL_FILE;
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_group_fk) VALUES (1, 'zadmin', 1), (3, 'bob', 3)");
$GLOBALS['PH_ME'] = 1;
$_SERVER['REMOTE_ADDR'] = '192.0.2.50';

// ---------------------------------------------------------------------------------------------
section('Addresses and networks are checked by PHP\'s own parser');
check(ph_bans::Ip('203.0.113.7') === '203.0.113.7' && ph_bans::Ip(' 2001:DB8:0:0::7 ') === '2001:db8::7', 'an address, IPv6 in its plain form');
foreach (array('1.2.3.4; rm -rf /', '1.2.3.4 5.6.7.8', '999.1.1.1', '-t', '$(id)', "1.2.3.4\n", 'localhost', '') as $bad) {
    check(ph_bans::Ip($bad) === '' || $bad === "1.2.3.4\n", 'not an address: ' . json_encode($bad));
}
check(ph_bans::Net('198.51.100.0/24') === '198.51.100.0/24' && ph_bans::Net('2001:db8::/48') === '2001:db8::/48' && ph_bans::Net('1.2.3.4/33') === '' && ph_bans::Net('1.2.3.4/x') === '', 'networks: a prefix within the address\'s size only');
check(ph_bans::InNet('198.51.100.77', '198.51.100.0/24') && !ph_bans::InNet('198.51.101.1', '198.51.100.0/24') && ph_bans::InNet('2001:db8::5', '2001:db8::/48')
    && ph_bans::InNet('10.1.2.3', '10.0.0.0/8') && !ph_bans::InNet('10.1.2.3', '2001:db8::/48'), 'an address in a network');
check(ph_bans::Jail('sentora-panel') === 'sentora-panel' && ph_bans::Jail('a b') === '' && ph_bans::Jail('-x') === '' && ph_bans::Jail('x;y') === '', 'a jail\'s name');

// ---------------------------------------------------------------------------------------------
section('The daemon: fail2ban\'s files, then who is banned');
$log = ph_bans::DaemonRun();
$c = calls();
check(ph_bans::Path('fail2ban_client') === '/usr/bin/fail2ban-client' && (string) $zdbh->query("SELECT so_usereditable_en FROM x_settings WHERE so_name_vc = 'fail2ban_client'")->fetchColumn() === 'false',
    'its command is a hidden setting, added with its usual place');
// (the test's fake fail2ban is in the test's folder)
$zdbh->exec("UPDATE x_settings SET so_value_tx = " . $zdbh->quote($client) . " WHERE so_name_vc = 'fail2ban_client'");
$log = ph_bans::DaemonRun();
$c = calls();
$jail = (string) @file_get_contents($jailFile);
check(is_file("$W/etc/fail2ban/filter.d/sentora-flood.conf") && is_file("$W/etc/fail2ban/filter.d/sentora-manual.conf"), 'its two filters written');
check(strpos($jail, "ignoreip = 127.0.0.1/8 ::1 198.51.100.9\n") !== false, 'never banned: this server (from Sentora\'s own jail file)', $jail);
check(preg_match('/\[sentora-manual\][^\[]*enabled = true/', $jail) === 1 && strpos($jail, 'bantime = -1') !== false && strpos($jail, 'banaction_allports') !== false, 'the jail for bans by hand: every port, until unbanned');
check(preg_match('/\[sentora-flood\][^\[]*enabled = false/', $jail) === 1, 'flood protection off until switched on');
check(in_array('-t', $c, true) && in_array('reload', $c, true), 'fail2ban\'s settings tested, then fail2ban reloaded', implode(' | ', $c));
$st = ph_bans::State();
check($st['running'] && $st['version'] === '1.1.0' && $st['reqtimeout'] && isset($st['jails']['sshd']) && count($st['jails']) === 3, 'who is banned: read from every jail', json_encode($st));
check($st['jails']['sshd'][0]['ip'] === '203.0.113.5' && $st['jails']['sshd'][0]['until'] - $st['jails']['sshd'][0]['since'] === 3600, 'with since and until');
ph_bans::DaemonRun();
check(!in_array('reload', calls(), true), 'nothing changed: no reload');

// ---------------------------------------------------------------------------------------------
section('The page: ban, unban, never ban (an administrator)');
$html = page(array('tab' => 'bans'));
save('bans', $html);
check(strpos($html, 'aria-current="page">Bans') !== false && strpos($html, '203.0.113.5') !== false && strpos($html, 'SSH logins') !== false && strpos($html, 'name="inUnban" value="203.0.113.5"') !== false,
    'Bans: the banned address, why (SSH logins) and Unban');
check(strpos($html, 'fail2ban running') !== false && strpos($html, 'Your address now: 192.0.2.50') !== false, 'fail2ban running; your own address shown for the never-ban list');
foreach (array(array('192.0.2.50', 'your own address'), array('198.51.100.9', 'this server'), array('127.0.0.1', 'this server'), array('1.2.3.4; reboot', 'not an address'), array('10.0.0.0/8', 'a network')) as $t) {
    page(array('tab' => 'bans'), array('inBanIp' => $t[0], 'inBanHours' => '24'), 'Ban');
    check(module_controller::$msg[0][0] === 'error' && ph_bans::Asked() === array(), 'refused: ' . $t[1] . ' (' . $t[0] . ')', json_encode(module_controller::$msg));
}
$html = page(array('tab' => 'bans'), array('inBanIp' => '203.0.113.66', 'inBanHours' => '1', 'inBanWhy' => "<script>x</script>\nInjected: line"), 'Ban');
$a = ph_bans::Asked();
check(count($a) === 1 && $a[0]['ip'] === '203.0.113.66' && $a[0]['hours'] === 1 && $a[0]['by'] === 'zadmin' && strpos($a[0]['why'], "\n") === false, 'a ban asked: for an hour, by whom, why on one line', json_encode($a));
check(strpos($html, 'waiting for the server') !== false && strpos($html, 'data-ph-wait') !== false, 'the page shows it waiting (and looks again by itself)');
page(array('tab' => 'bans'), array('inBanIp' => '203.0.113.67', 'inBanHours' => '99999'), 'Ban');
check(ph_bans::Asked()[1]['hours'] === 24, 'a time not offered becomes a day');
$t0 = time();
ph_bans::DaemonRun($t0);
$c = calls();
check(in_array('set sentora-manual banip 203.0.113.66', $c, true) && in_array('set sentora-manual banip 203.0.113.67', $c, true) && ph_bans::Asked() === array(), 'the daemon banned both (in the jail for bans by hand)', implode(' | ', $c));
$m = ph_bans::Manual();
check(isset($m['203.0.113.66']) && $m['203.0.113.66']['until'] === $t0 + 3600 && strpos($m['203.0.113.66']['why'], '<script>') !== false, 'kept: until when, why');
$html = page(array('tab' => 'bans'));
save('bans-more', $html);
check(strpos($html, 'Banned by hand') !== false && strpos($html, '&lt;script&gt;x&lt;/script&gt;') !== false && strpos($html, '<script>x</script>') === false, 'the page: banned by hand, why escaped');
ph_bans::DaemonRun($t0 + 3700);
$c = calls();
check(in_array('set sentora-manual unbanip 203.0.113.66', $c, true) && !in_array('set sentora-manual unbanip 203.0.113.67', $c, true) && !isset(ph_bans::Manual()['203.0.113.66']), 'an hour later: the hour\'s ban ran out, the day\'s stays', implode(' | ', $c));
page(array('tab' => 'bans'), array('inUnban' => '203.0.113.5'), 'Unban');
page(array('tab' => 'bans'), array('inUnban' => "203.0.113.5' ; reboot"), 'Unban');
check(count(ph_bans::Asked()) === 1 && module_controller::$msg[0][0] === 'error', 'unban: an address only');
ph_bans::DaemonRun();
check(in_array('unban 203.0.113.5', calls(), true) && ph_bans::State()['jails']['sshd'] === array(), 'unbanned from every jail (here: SSH)');
$GLOBALS['f2b']['jails']['sentora-manual'] = array();
ph_bans::DaemonRun();
calls();
check(ph_bans::Manual() === array(), 'a ban by hand unbanned over SSH is forgotten too');

page(array('tab' => 'bans'), array('inNever' => "203.0.113.0/24\nnot-an-address"), 'Never');
check(module_controller::$msg[0][0] === 'error' && ph_bans::Never() === array(), 'never ban: a list with something that is not an address is not saved');
page(array('tab' => 'bans'), array('inNever' => "203.0.113.0/24, 2001:DB8::1\n203.0.113.0/24"), 'Never');
check(ph_bans::Never() === array('203.0.113.0/24', '2001:db8::1'), 'saved, in plain form, once each', json_encode(ph_bans::Never()));
ph_bans::DaemonRun();
calls();
check(strpos((string) file_get_contents($jailFile), 'ignoreip = 127.0.0.1/8 ::1 198.51.100.9 203.0.113.0/24 2001:db8::1') !== false, 'fail2ban never bans them (every jail)');
page(array('tab' => 'bans'), array('inBanIp' => '203.0.113.200'), 'Ban');
check(module_controller::$msg[0][0] === 'error' && strpos(module_controller::$msg[0][1], 'never-ban list') !== false, 'an address in a never-ban network can not be banned by hand');

// ---------------------------------------------------------------------------------------------
section('Flood protection');
page(array('tab' => 'flood'), array('inFloodOn' => '1', 'inFloodLimit' => '10', 'inFloodMinutes' => '60'), 'Flood');
check(module_controller::$msg[0][0] === 'error' && !ph_bans::Flood()['on'], 'a limit under 30 a minute is refused');
page(array('tab' => 'flood'), array('inFloodOn' => '1', 'inFloodLimit' => '240', 'inFloodMinutes' => '1440'), 'Flood');
check(ph_bans::Flood() === array('on' => true, 'limit' => 240, 'minutes' => 1440), 'switched on: 240 a minute, banned for a day');
ph_bans::DaemonRun();
calls();
check(preg_match('/\[sentora-flood\][^\[]*enabled = false/', (string) file_get_contents($jailFile)) === 1 && ph_bans::State()['flood_logs'] === 0, 'no website has a log yet: the jail waits (fail2ban refuses a jail with no log)');
touch("$W/logs/domains/bob/bob.example-access.log");
touch("$W/logs/domains/_timing/sites-access.log");
ph_bans::DaemonRun();
$c = calls();
$jail = (string) file_get_contents($jailFile);
check(strpos($jail, "[sentora-flood]\n# too many") !== false && strpos($jail, "enabled = true\nfilter = sentora-flood") !== false && strpos($jail, "logpath = $W/logs/domains/*/*-access.log tail") !== false
    && strpos($jail, "maxretry = 240\nfindtime = 60\nbantime = 86400") !== false && strpos($jail, 'port = http,https') !== false, 'one website log: the flood jail on, every website\'s log, 240 a minute, a day', $jail);
check(in_array('reload', $c, true) && ph_bans::State()['flood_logs'] === 1, 'reloaded; the panel\'s own timing log is not a website\'s');
ph_bans::DaemonRun();
check(!in_array('reload', calls(), true), 'the same logs: no reload');
touch("$W/logs/domains/bob/shop.example-access.log");
ph_bans::DaemonRun();
check(in_array('reload', calls(), true), 'a new website\'s log: reloaded (fail2ban reads the list when it starts)');
$html = page(array('tab' => 'flood'));
save('flood', $html);
check(strpos($html, 'aria-current="page">Flood protection') !== false && strpos($html, 'value="240"') !== false && strpos($html, 'Watching 2 website log(s)') !== false && strpos($html, 'Slow attacks') !== false, 'the Flood protection tab: the settings, the logs watched, slow attacks');
check(strpos($html, 'Your own address (192.0.2.50) is not on the never-ban list') !== false, 'it says your own address is not on the never-ban list');

// the filter on real access-log lines (fail2ban's <HOST> becomes an address)
preg_match_all('/^(failregex|ignoreregex) = (.*)$/m', ph_bans::Filters()['sentora-flood.conf'], $fm);
$flt = array('Definition' => array_combine($fm[1], $fm[2]));
$re = function ($r) { return '/' . str_replace(array('<HOST>', '/'), array('(?P<host>[0-9a-fA-F:.]+)', '\\/'), $r) . '/'; };
$fr = $re($flt['Definition']['failregex']);
$ir = $re($flt['Definition']['ignoreregex']);
$lines = array(
    array('203.0.113.9 - - [23/Nov/2026:10:00:00 +0000] "GET /index.php?p=1 HTTP/1.1" 200 512 "-" "curl/8"', true),
    array('203.0.113.9 - - [23/Nov/2026:10:00:00 +0000] "POST /wp-login.php HTTP/1.1" 200 512 "-" "x"', true),
    array('2001:db8::9 - - [23/Nov/2026:10:00:00 +0000] "GET / HTTP/2.0" 200 5 "-" "x"', true),
    array('203.0.113.9 - - [23/Nov/2026:10:00:00 +0000] "GET /wp-content/style.css?ver=6 HTTP/1.1" 200 512 "-" "x"', false),
    array('203.0.113.9 - - [23/Nov/2026:10:00:00 +0000] "GET /img/logo.PNG HTTP/1.1" 304 0 "-" "x"', true),
    array('203.0.113.9 - - [23/Nov/2026:10:00:00 +0000] "GET /fonts/a.woff2 HTTP/1.1" 200 512 "-" "x"', false),
    array('203.0.113.9 - - [23/Nov/2026:10:00:00 +0000] "GET /script.js.php HTTP/1.1" 200 512 "-" "x"', true),
);
foreach ($lines as $l) {
    $counted = preg_match($fr, $l[0], $m) === 1 && preg_match($ir, $l[0]) !== 1;
    check($counted === $l[1], ($l[1] ? 'counted: ' : 'not counted: ') . substr($l[0], strpos($l[0], '"'), 45));
}

// with a real fail2ban (when this machine has one: the Add-ons workflow installs it): its own regex
// tool on access-log lines (it takes the date out before matching), and its own settings test
if (is_executable('/usr/bin/fail2ban-regex') && is_dir('/etc/fail2ban/filter.d')) {
    $real = "$W/real-f2b";
    exec('cp -r /etc/fail2ban ' . escapeshellarg($real));
    foreach (ph_bans::Filters() as $name => $text) {
        file_put_contents("$real/filter.d/$name", $text);
    }
    $d = gmdate('d/M/Y:H:i:s +0000');
    file_put_contents("$W/real-access.log", implode("\n", array_map(function ($l) use ($d) { return str_replace('23/Nov/2026:10:00:00 +0000', $d, $l[0]); }, $lines)) . "\n");
    exec('/usr/bin/fail2ban-regex ' . escapeshellarg("$W/real-access.log") . ' ' . escapeshellarg("$real/filter.d/sentora-flood.conf") . ' 2>&1', $o);
    $o = implode("\n", $o);
    $want = count(array_filter($lines, function ($l) { return $l[1]; }));
    check(preg_match('/Lines: 7 lines, 2 ignored, ' . $want . ' matched, 0 missed/', $o) === 1, "the real fail2ban-regex: $want requests counted, 2 not counted, none missed", $o);
    file_put_contents("$real/jail.d/" . ph_bans::JAIL_FILE, str_replace("$W/logs/", "$W/logs/", (string) file_get_contents($jailFile)));
    file_put_contents("$real/jail.d/00-sentora-ban.conf", "[DEFAULT]\nbanaction = nftables-multiport\nbanaction_allports = nftables-allports\n");
    $o = array();
    exec('/usr/bin/fail2ban-client -c ' . escapeshellarg($real) . ' -t 2>&1', $o, $rc);
    check($rc === 0, 'the real fail2ban accepts the jail file (fail2ban-client -t)', implode("\n", $o));
    $o = array();
    exec('/usr/bin/fail2ban-client -c ' . escapeshellarg($real) . ' -d 2>&1', $o);
    $o = implode("\n", $o);
    check(strpos($o, "'sentora-flood', 'addlogpath'") !== false && strpos($o, "'tail']") !== false && strpos($o, "['set', 'sentora-manual', 'bantime', '-1']") !== false, 'as fail2ban reads it: the websites\' logs from their end, bans by hand until unbanned', $o);
} else {
    echo "  (no fail2ban on this machine: the real fail2ban's checks are skipped)\n";
}

// ---------------------------------------------------------------------------------------------
section('When fail2ban says no');
$GLOBALS['f2b']['refuse'] = '198.51.100.0/24';
$before = (string) file_get_contents($jailFile);
page(array('tab' => 'bans'), array('inNever' => '198.51.100.0/24'), 'Never');
ph_bans::DaemonRun();
calls();
check((string) file_get_contents($jailFile) === $before && strpos(ph_bans::State()['problem'], 'fail2ban refused the new settings') !== false, 'settings fail2ban refuses: the old file put back, the reason on the page', ph_bans::State()['problem']);
$GLOBALS['f2b']['refuse'] = '';
$GLOBALS['f2b']['running'] = false;
ph_bans::DaemonRun();
calls();
$html = page(array('tab' => 'bans'));
check(strpos($html, 'fail2ban not running') !== false && strpos($html, 'systemctl start fail2ban') !== false, 'fail2ban not running: said, with how to start it');
$GLOBALS['f2b']['running'] = true;
$GLOBALS['f2b']['jails']['bad name;x'] = array('203.0.113.1');
$GLOBALS['f2b']['jails']['sshd'] = array("not-an-ip \t2026-11-23 10:00:00 + 60 = 2026-11-23 10:01:00", '203.0.113.8 203.0.113.9');
ph_bans::DaemonRun();
calls();
$st = ph_bans::State();
check(!isset($st['jails']['bad name;x']) && array_column($st['jails']['sshd'], 'ip') === array('203.0.113.8', '203.0.113.9'), 'only real jail names and addresses are taken from fail2ban\'s answers', json_encode($st['jails']));
$GLOBALS['f2b']['reqtimeout'] = false;
ph_bans::DaemonRun();
calls();
check(strpos(page(array('tab' => 'flood')), 'does not have mod_reqtimeout loaded') !== false, 'Apache without its request timeouts: said');

// ---------------------------------------------------------------------------------------------
section('Not an administrator');
$GLOBALS['PH_ME'] = 3;
$n = count(ph_bans::Asked());
$html = page(array('tab' => 'bans'), array('inBanIp' => '203.0.113.99'), 'Ban');
page(array('tab' => 'bans'), array('inNever' => '0.0.0.0/0'), 'Never');
page(array('tab' => 'flood'), array('inFloodOn' => '0', 'inFloodLimit' => '300', 'inFloodMinutes' => '60'), 'Flood');
check(count(ph_bans::Asked()) === $n && !in_array('0.0.0.0/0', ph_bans::Never(), true) && ph_bans::Flood()['on'] && strpos($html, 'Only administrators') !== false, 'a client can not ban, change the never-ban list or switch flood protection');
$GLOBALS['PH_ME'] = 1;

// ---------------------------------------------------------------------------------------------
section('Removing Web Hardening');
$log = ph_bans::AllOff();
check(!is_file($jailFile) && !is_file("$W/etc/fail2ban/filter.d/sentora-flood.conf") && is_file("$W/etc/fail2ban/jail.d/sentora.conf") && in_array('reload', calls(), true), 'its jail file and filters gone, fail2ban reloaded, Sentora\'s own jails stay');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
