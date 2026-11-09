<?php
/**
 * Sentora-2027 feedback server: the statistics page (behind a password: it shows IP addresses).
 * Every value shown came from a panel's report, so every value is escaped.
 *
 * Written by RusTus, 2026-11-09
 */
require __DIR__ . '/../lib.php';
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(array('lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $https));
session_name('fbstats');
session_start();
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$c = fb_config();
$error = '';
if (isset($_POST['logout'])) {
    $_SESSION = array();
    session_destroy();
    header('Location: ./');
    exit;
}
if (isset($_POST['password']) && is_string($_POST['password'])) {
    if ($c['password_hash'] !== '' && password_verify($_POST['password'], $c['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['in'] = true;
        header('Location: ./');
        exit;
    }
    sleep(2);
    $error = 'Wrong password.';
}
$page = function ($title, $body) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . fb_h($title) . '</title><style>
:root { color-scheme: light dark; --ink: #1f2933; --muted: #6b7785; --line: #e6e9ee; --card: #fff; --bg: #f4f6f9; --accent: #447fb3; }
@media (prefers-color-scheme: dark) { :root { --ink: #e6e9ee; --muted: #9aa5b1; --line: #2f3a46; --card: #1c242d; --bg: #11171d; --accent: #6aa3d8; } }
* { box-sizing: border-box; } body { margin: 0; background: var(--bg); color: var(--ink); font: 14px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
header { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; background: var(--card); border-bottom: 1px solid var(--line); }
header b { font-size: 16px; } main { max-width: 1200px; margin: 0 auto; padding: 20px 16px; }
.tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
.tile { background: var(--card); border-radius: 8px; padding: 14px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.tile b { display: block; font-size: 24px; } .tile span { color: var(--muted); font-size: 12px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 12px; margin-bottom: 16px; }
.card { background: var(--card); border-radius: 8px; padding: 14px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); overflow-x: auto; }
h2 { font-size: 14px; margin: 0 0 10px; } table { width: 100%; border-collapse: collapse; font-size: 13px; }
td, th { padding: 5px 6px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; white-space: nowrap; } th { color: var(--muted); font-weight: 600; font-size: 12px; }
td.n { text-align: right; } .bar { height: 6px; background: var(--accent); border-radius: 3px; min-width: 2px; }
.muted { color: var(--muted); } form.login { max-width: 320px; margin: 80px auto; background: var(--card); padding: 24px; border-radius: 8px; }
input, button { font: inherit; padding: 8px 10px; border-radius: 6px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); width: 100%; } button { background: var(--accent); color: #fff; border: 0; margin-top: 10px; cursor: pointer; }
header button { width: auto; margin: 0; padding: 6px 12px; } .err { color: #c0392b; }
</style></head><body>' . $body . '</body></html>';
};
if (empty($_SESSION['in'])) {
    $page('Sentora-2027 feedback', '<form class="login" method="post"><h2>Sentora-2027 feedback</h2>'
        . ($c['password_hash'] === '' ? '<p class="err">Set a password first: password_hash in config.php (see README.md).</p>' : '')
        . ($error !== '' ? '<p class="err">' . fb_h($error) . '</p>' : '')
        . '<input type="password" name="password" placeholder="Password" autofocus required><button type="submit">Log in</button></form>');
    exit;
}
$db = fb_db();
$now = time();
$one = function ($sql, array $a = array()) use ($db) {
    $q = $db->prepare($sql);
    $q->execute($a);
    return $q->fetchColumn();
};
$all = function ($sql, array $a = array()) use ($db) {
    $q = $db->prepare($sql);
    $q->execute($a);
    return $q->fetchAll();
};
$total = (int) $one('SELECT COUNT(*) FROM installs');
$tiles = array(
    'servers seen' => $total,
    'active (7 days)' => (int) $one('SELECT COUNT(*) FROM installs WHERE last_seen > ?', array($now - 7 * 86400)),
    'active (30 days)' => (int) $one('SELECT COUNT(*) FROM installs WHERE last_seen > ?', array($now - 30 * 86400)),
    'new (30 days)' => (int) $one('SELECT COUNT(*) FROM installs WHERE first_seen > ?', array($now - 30 * 86400)),
    'reports kept' => (int) $one('SELECT COUNT(*) FROM reports'),
);
// sums of the counts of the servers active in the last 30 days
$sums = array();
foreach ($all('SELECT report FROM installs WHERE last_seen > ?', array($now - 30 * 86400)) as $row) {
    $r = json_decode((string) $row['report'], true);
    foreach ((array) ($r['counts'] ?? array()) as $k => $v) {
        $sums[$k] = ($sums[$k] ?? 0) + max(0, (int) $v);
    }
    foreach ((array) ($r['modules'] ?? array()) as $m => $v) {
        $sums['module:' . $m] = ($sums['module:' . $m] ?? 0) + 1;
    }
}
$out = '<header><b>Sentora-2027 feedback</b><form method="post"><button name="logout" value="1">Log out</button></form></header><main><div class="tiles">';
foreach ($tiles as $k => $v) {
    $out .= '<div class="tile"><b>' . number_format($v) . '</b><span>' . fb_h($k) . '</span></div>';
}
foreach (array('accounts', 'domains', 'mailboxes', 'databases') as $k) {
    $out .= '<div class="tile"><b>' . number_format($sums[$k] ?? 0) . '</b><span>' . fb_h($k) . ' (active servers)</span></div>';
}
$traffic = (int) $one('SELECT COALESCE(SUM(bandwidth), 0) FROM installs WHERE last_seen > ?', array($now - 30 * 86400));
$out .= '<div class="tile"><b>' . fb_h(round($traffic / 1073741824, 1)) . ' GB</b><span>website traffic this month (active servers)</span></div>'
    . '<div class="tile"><b>' . (int) $one("SELECT COUNT(DISTINCT country) FROM installs WHERE country <> ''") . '</b><span>countries</span></div>';
$out .= '</div><div class="grid">';
$breakdown = function ($title, $col, $label = null) use ($all, $total) {
    $rows = $all('SELECT ' . $col . ' AS k, COUNT(*) AS n FROM installs GROUP BY ' . $col . ' ORDER BY n DESC, k LIMIT 15');
    $h = '<div class="card"><h2>' . fb_h($title) . '</h2><table>';
    foreach ($rows as $r) {
        $h .= '<tr><td>' . fb_h($label ? $label($r['k']) : ($r['k'] !== '' && $r['k'] !== null ? $r['k'] : '(unknown)')) . '</td><td class="n">' . (int) $r['n'] . '</td><td style="width:40%"><div class="bar" style="width:' . ($total ? round(100 * $r['n'] / $total) : 0) . '%"></div></td></tr>';
    }
    return $h . '</table></div>';
};
$country = function ($cc) {
    $cc = (string) $cc;
    if ($cc === '') {
        return '(unknown)';
    }
    $name = class_exists('Locale') ? Locale::getDisplayRegion('-' . $cc, 'en') : '';
    return ($name !== '' && $name !== $cc ? $name . ' ' : '') . '(' . $cc . ')';
};
$gb = function ($b) {
    return $b > 0 ? round($b / 1073741824) . ' GB' : '(unknown)';
};
$out .= $breakdown('Countries', 'country', $country) . $breakdown('Panel version', 'panel') . $breakdown('Operating system', 'os') . $breakdown('Virtualisation', 'virt') . $breakdown('Architecture', 'arch')
    . $breakdown('CPU cores', 'cores', function ($v) { return (int) $v . ' cores'; })
    . $breakdown('Memory', 'CAST(ROUND(memory / 1073741824.0) AS INTEGER) * 1073741824', $gb);
$mods = array();
foreach ($sums as $k => $v) {
    if (strpos($k, 'module:') === 0) {
        $mods[substr($k, 7)] = $v;
    }
}
arsort($mods);
$h = '<div class="card"><h2>Modules switched on (active servers)</h2><table>';
foreach (array_slice($mods, 0, 25, true) as $m => $n) {
    $h .= '<tr><td>' . fb_h($m) . '</td><td class="n">' . (int) $n . '</td></tr>';
}
$out .= $h . '</table></div>';
// the busiest servers: most accounts, with their load and traffic
$h = '<div class="card"><h2>Heaviest servers (most accounts)</h2><table><tr><th>ID</th><th>Country</th><th class="n">Accounts</th><th class="n">Load</th><th class="n">Cores</th><th class="n">Traffic this month</th></tr>';
foreach ($all('SELECT id, country, accounts, load1, cores, bandwidth FROM installs WHERE last_seen > ? ORDER BY accounts DESC, bandwidth DESC LIMIT 15', array($now - 30 * 86400)) as $r) {
    $h .= '<tr><td class="muted">' . fb_h(substr($r['id'], 0, 8)) . '</td><td>' . fb_h($country($r['country'])) . '</td><td class="n">' . (int) $r['accounts'] . '</td><td class="n">' . fb_h(number_format((float) $r['load1'], 2)) . '</td>'
        . '<td class="n">' . (int) $r['cores'] . '</td><td class="n">' . fb_h(round((int) $r['bandwidth'] / 1073741824, 1)) . ' GB</td></tr>';
}
$out .= $h . '</table></div></div>';
$out .= '<div class="card"><h2>Latest servers</h2><table><tr><th>Last report</th><th>ID</th><th>Panel</th><th>OS</th><th>CPU</th><th>Cores</th><th>Memory</th><th>Disk</th><th>Country</th><th>Load</th><th>Sent from</th><th>Panel IP</th><th>Internal IP</th><th>Accounts</th><th>Domains</th><th>Reports</th></tr>';
foreach ($all('SELECT * FROM installs ORDER BY last_seen DESC LIMIT 100') as $r) {
    $rep = json_decode((string) $r['report'], true);
    $out .= '<tr><td>' . fb_h(gmdate('Y-m-d H:i', (int) $r['last_seen'])) . '</td><td class="muted">' . fb_h(substr($r['id'], 0, 8)) . '</td><td>' . fb_h($r['panel']) . '</td><td>' . fb_h($r['os']) . '</td>'
        . '<td>' . fb_h(mb_substr((string) $r['cpu'], 0, 40)) . '</td><td class="n">' . (int) $r['cores'] . '</td><td class="n">' . fb_h($gb((int) $r['memory'])) . '</td><td class="n">' . fb_h($gb((int) $r['disk'])) . '</td>'
        . '<td>' . fb_h($country($r['country'])) . '</td><td class="n">' . fb_h(number_format((float) $r['load1'], 2)) . '</td>'
        . '<td>' . fb_h($r['remote_ip']) . '</td><td>' . fb_h($r['public_ip']) . '</td><td>' . fb_h($r['internal_ip']) . '</td>'
        . '<td class="n">' . (int) ($rep['counts']['accounts'] ?? 0) . '</td><td class="n">' . (int) ($rep['counts']['domains'] ?? 0) . '</td><td class="n">' . (int) $r['reports'] . '</td></tr>';
}
$out .= '</table><p class="muted">Times in UTC. "Sent from" is the address the report came from; "Panel IP" is the address the panel is set to (Sentora Config).</p></div></main>';
$page('Sentora-2027 feedback', $out);
