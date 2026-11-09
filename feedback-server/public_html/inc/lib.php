<?php
/**
 * Sentora-2027 feedback server: the database and the shared helpers.
 *
 * Written by RusTus, 2026-11-09
 */
function fb_config()
{
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        $c = (is_file($f) ? require $f : array()) + require __DIR__ . '/config.example.php';
    }
    return $c;
}

function fb_db()
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    $c = fb_config();
    $db = new PDO('sqlite:' . $c['database'], null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('CREATE TABLE IF NOT EXISTS installs (id TEXT PRIMARY KEY, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL, reports INTEGER NOT NULL DEFAULT 0,
        remote_ip TEXT, panel TEXT, os TEXT, arch TEXT, virt TEXT, cpu TEXT, cores INTEGER, memory INTEGER, disk INTEGER, public_ip TEXT, internal_ip TEXT, report TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS reports (n INTEGER PRIMARY KEY AUTOINCREMENT, install TEXT NOT NULL, at INTEGER NOT NULL, remote_ip TEXT, report TEXT)');
    $db->exec('CREATE INDEX IF NOT EXISTS reports_install ON reports (install, at)');
    $db->exec('CREATE INDEX IF NOT EXISTS reports_ip ON reports (remote_ip, at)');
    $db->exec('CREATE TABLE IF NOT EXISTS geo (ip TEXT PRIMARY KEY, country TEXT NOT NULL, at INTEGER NOT NULL)');
    // the add-on repository's downloads (public/repo): one row each, no address kept (only a hash, for repeats)
    $db->exec('CREATE TABLE IF NOT EXISTS downloads (n INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT NOT NULL, at INTEGER NOT NULL, kind TEXT NOT NULL, panel TEXT, who TEXT NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS downloads_who ON downloads (who, at)');
    $db->exec('CREATE INDEX IF NOT EXISTS downloads_module ON downloads (module, at)');
    // ratings of the add-ons: one per panel and module (a new one replaces it), with an optional comment;
    // hidden: taken off the panels' lists on the statistics page
    $db->exec('CREATE TABLE IF NOT EXISTS ratings (module TEXT NOT NULL, panel TEXT NOT NULL, stars INTEGER NOT NULL, comment TEXT NOT NULL DEFAULT \'\', at INTEGER NOT NULL,
        version TEXT NOT NULL DEFAULT \'\', hidden INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (module, panel))');
    $cols = array_column($db->query('PRAGMA table_info(installs)')->fetchAll(), 'name');
    foreach (array('country' => 'TEXT', 'load1' => 'REAL', 'accounts' => 'INTEGER', 'bandwidth' => 'INTEGER', 'machine' => 'TEXT') as $c => $t) {
        if (!in_array($c, $cols, true)) {
            $db->exec('ALTER TABLE installs ADD ' . $c . ' ' . $t);
        }
    }
    return $db;
}

function fb_remote_ip()
{
    $c = fb_config();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($c['ip_header'] !== '' && !empty($_SERVER[$c['ip_header']])) {
        $first = trim(explode(',', (string) $_SERVER[$c['ip_header']])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            $ip = $first;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function fb_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * A report as received, cleaned: known fields only, of the right type and size. Anything else
 * a sender adds is dropped.
 *
 * @param mixed $in
 * @return array|null
 */
function fb_clean($in)
{
    if (!is_array($in) || !isset($in['id']) || !is_string($in['id']) || !preg_match('/^[0-9a-f]{32}$/', $in['id'])) {
        return null;
    }
    $text = array('panel' => 32, 'installed' => 40, 'os' => 80, 'os_id' => 42, 'kernel' => 60, 'arch' => 20, 'virt' => 30, 'cpu' => 100,
        'php' => 40, 'db' => 60, 'apache' => 60, 'timezone' => 60);
    $num = array('cores' => 4096, 'memory' => PHP_INT_MAX, 'memory_free' => PHP_INT_MAX, 'disk' => PHP_INT_MAX, 'disk_free' => PHP_INT_MAX, 'uptime_days' => 100000,
        'bandwidth_month' => PHP_INT_MAX, 'disk_used_accounts' => PHP_INT_MAX);
    $out = array('id' => $in['id']);
    // the computer's key (a hash: the same through reinstalls on one computer)
    $out['machine'] = isset($in['machine']) && is_string($in['machine']) && preg_match('/^[0-9a-f]{32}$/', $in['machine']) ? $in['machine'] : '';
    foreach ($text as $k => $max) {
        $v = isset($in[$k]) && is_scalar($in[$k]) ? (string) $in[$k] : '';
        $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_check_encoding($v, 'UTF-8') ? $v : '');
        $out[$k] = mb_substr(trim($v), 0, $max);
    }
    foreach ($num as $k => $max) {
        $out[$k] = isset($in[$k]) && is_numeric($in[$k]) ? max(0, min($max, (int) $in[$k])) : 0;
    }
    $out['load'] = array();
    foreach (array(0, 1, 2) as $i) {
        $out['load'][$i] = isset($in['load'][$i]) && is_numeric($in['load'][$i]) ? max(0, min(100000, round((float) $in['load'][$i], 2))) : 0;
    }
    foreach (array('public_ip', 'internal_ip') as $k) {
        $out[$k] = isset($in[$k]) && is_string($in[$k]) && filter_var($in[$k], FILTER_VALIDATE_IP) ? $in[$k] : '';
    }
    $out['counts'] = array();
    foreach (array('accounts', 'resellers', 'domains', 'subdomains', 'parked', 'mailboxes', 'databases', 'ftp', 'cron') as $k) {
        $out['counts'][$k] = isset($in['counts'][$k]) && is_numeric($in['counts'][$k]) ? max(-1, min(10000000, (int) $in['counts'][$k])) : -1;
    }
    $out['modules'] = array();
    if (isset($in['modules']) && is_array($in['modules'])) {
        foreach (array_slice($in['modules'], 0, 150, true) as $m => $v) {
            if (is_string($m) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $m) && is_numeric($v)) {
                $out['modules'][$m] = (int) $v;
            }
        }
    }
    return $out;
}

/**
 * The country of a public address (two letters), from the lookup service set in config.php (none:
 * no lookups), each address asked once a month at most. Private and reserved addresses have none.
 *
 * @param string $ip
 * @return string '' when unknown.
 */
function fb_country($ip)
{
    $c = fb_config();
    if ($ip === '' || (string) $c['geo'] === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }
    $db = fb_db();
    $q = $db->prepare('SELECT country, at FROM geo WHERE ip = ?');
    $q->execute(array($ip));
    $row = $q->fetch();
    if ($row && (int) $row['at'] > time() - 30 * 86400) {
        return (string) $row['country'];
    }
    $ctx = stream_context_create(array('http' => array('timeout' => 3, 'ignore_errors' => true, 'user_agent' => 'Sentora-2027 feedback')));
    $j = json_decode((string) @file_get_contents(rtrim((string) $c['geo'], '/') . '/' . rawurlencode($ip), false, $ctx), true);
    $country = is_array($j) && isset($j['country']) && preg_match('/^[A-Z]{2}$/', (string) $j['country']) ? (string) $j['country'] : '';
    $db->prepare('INSERT OR REPLACE INTO geo (ip, country, at) VALUES (?, ?, ?)')->execute(array($ip, $country, time()));
    return $country;
}
