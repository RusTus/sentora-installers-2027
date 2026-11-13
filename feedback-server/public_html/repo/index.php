<?php
/**
 * Sentora-2027 feedback server: the add-on repository's front door, which counts downloads.
 *
 * Panels use https://<this server>/repo as their Sentora-2027 add-on repository. The files stay where
 * they are published (repo_upstream: the repository on GitHub); this only stands in front of them:
 *
 *   <name>.zpp            a download: counted (a panel's, zppy's), then sent on to the file itself
 *   packages.txt, SHA256SUMS, <name>.xml
 *                         passed through from the upstream (kept repo_cache seconds), as Repo Browser
 *                         reads them without following a redirect
 *   repostats.php?json=true, repostatslist
 *                         the downloads of each module, as Repo Browser shows them (the JSON also its
 *                         average rating and how many rated it)
 *   rate.php (POST)       a panel's rating of a module, 1 to 5 stars and a short comment, sent by its
 *                         daemon; accepted only from a panel that reports here, from its own address
 *   reviews.php?module=   the newest comments on a module (not the hidden ones, no panel's ID)
 *
 * A download is counted only when a panel asks (zppy calls itself ZppyClient), only for a module the
 * repository lists, and once per panel (or address) and module in 10 minutes. zppy says whether it
 * installs or updates (?for=install|upgrade) and, when the panel sends customer feedback, its random
 * ID (&panel=), so the statistics page can tell installs, updates and panels apart. A visitor's
 * address is kept only as a hash, for those 10 minutes' check.
 *
 * Written by RusTus, 2026-11-09
 */
require __DIR__ . '/../inc/lib.php';

header('X-Content-Type-Options: nosniff');

/**
 * Answer and stop.
 *
 * @param int $code
 * @param string $type
 * @param string $body
 * @return void
 */
function fb_repo_out($code, $type, $body)
{
    http_response_code($code);
    header('Content-Type: ' . $type);
    header('Cache-Control: no-cache');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $body;
    }
    exit;
}

/**
 * A file of the upstream repository, kept for a few minutes (a stale copy when the upstream does
 * not answer).
 *
 * @param string $name A checked file name.
 * @return string|null Its content; null when the upstream has no such file.
 */
function fb_repo_get($name)
{
    $c = fb_config();
    $dir = dirname((string) $c['database']) . '/repo-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $f = $dir . '/' . $name;
    $missing = $f . '.missing';
    if (is_file($f) && filemtime($f) > time() - (int) $c['repo_cache']) {
        return (string) file_get_contents($f);
    }
    if (is_file($missing) && filemtime($missing) > time() - (int) $c['repo_cache']) {
        return null;
    }
    $h = curl_init(rtrim((string) $c['repo_upstream'], '/') . '/' . rawurlencode($name));
    curl_setopt_array($h, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Sentora-2027-feedback-server', CURLOPT_MAXFILESIZE => 2097152));
    $body = curl_exec($h);
    $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) {
        curl_close($h); // (no effect since PHP 8.0, deprecated in 8.5)
    }
    if ($code === 200 && is_string($body)) {
        @file_put_contents($f . '.new', $body);
        @rename($f . '.new', $f);
        @unlink($missing);
        return $body;
    }
    if ($code === 404) {
        @touch($missing);
        @unlink($f);
        return null;
    }
    // the upstream did not answer: the last copy, however old
    return is_file($f) ? (string) file_get_contents($f) : '';
}

/**
 * The modules the repository lists (its packages.txt).
 *
 * @return array<string, true>
 */
function fb_repo_modules()
{
    $out = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) fb_repo_get('packages.txt')) as $line) {
        $line = trim($line);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $line)) {
            $out[$line] = true;
        }
    }
    return $out;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$c = fb_config();
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$name = basename($path);

// a panel's rating of a module (its daemon sends it): POST JSON {panel, module, stars, comment, version}
if ($name === 'rate.php') {
    if ($method !== 'POST') {
        header('Allow: POST');
        fb_repo_out(405, 'application/json', '{"error":"POST a rating"}');
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    $r = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, true) : null;
    if (!is_array($r) || !isset($r['panel'], $r['module'], $r['stars']) || !is_string($r['panel']) || !preg_match('/^[0-9a-f]{32}$/', $r['panel'])
        || !is_string($r['module']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $r['module']) || !is_int($r['stars']) || $r['stars'] < 1 || $r['stars'] > 5) {
        fb_repo_out(400, 'application/json', '{"error":"not a rating"}');
    }
    $comment = isset($r['comment']) && is_string($r['comment']) ? $r['comment'] : '';
    // plain text: no control characters, one paragraph of at most 500 characters
    $comment = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', mb_convert_encoding($comment, 'UTF-8', 'UTF-8'))));
    $comment = mb_substr($comment, 0, 500);
    $version = isset($r['version']) && is_string($r['version']) && preg_match('/^[0-9][0-9.]{0,20}$/', $r['version']) ? $r['version'] : '';
    if (!isset(fb_repo_modules()[$r['module']])) {
        fb_repo_out(404, 'application/json', '{"error":"no such module"}');
    }
    // only a panel that reports to this server, from its own address: a made-up panel can not rate
    $db = fb_db();
    $q = $db->prepare('SELECT remote_ip FROM installs WHERE id = ?');
    $q->execute(array($r['panel']));
    $known = $q->fetchColumn();
    if ($known === false || (string) $known !== fb_remote_ip()) {
        fb_repo_out(403, 'application/json', '{"error":"not a panel that reports here"}');
    }
    $q = $db->prepare('SELECT COUNT(*) FROM ratings WHERE panel = ? AND at > ?');
    $q->execute(array($r['panel'], time() - 3600));
    if ((int) $q->fetchColumn() >= 30) {
        fb_repo_out(429, 'application/json', '{"error":"too many"}');
    }
    // a new rating of the same module replaces the panel's last (a comment taken off stays off)
    $db->prepare('INSERT INTO ratings (module, panel, stars, comment, at, version) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(module, panel) DO UPDATE SET stars = excluded.stars, comment = excluded.comment, at = excluded.at, version = excluded.version')
        ->execute(array($r['module'], $r['panel'], $r['stars'], $comment, time(), $version));
    fb_repo_out(201, 'application/json', '{"ok":true}');
}

// what panels say about a module: the newest comments (no panel's ID), for Repo Browser
if ($name === 'reviews.php') {
    $module = isset($_GET['module']) && is_string($_GET['module']) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $_GET['module']) ? $_GET['module'] : '';
    $out = array();
    if ($module !== '') {
        $q = fb_db()->prepare("SELECT stars, comment, at, version FROM ratings WHERE module = ? AND hidden = 0 AND comment <> '' ORDER BY at DESC LIMIT 20");
        $q->execute(array($module));
        foreach ($q->fetchAll() as $row) {
            $out[] = array('stars' => (int) $row['stars'], 'comment' => (string) $row['comment'], 'at' => (int) $row['at'], 'version' => (string) $row['version']);
        }
    }
    fb_repo_out(200, 'application/json', json_encode($out));
}

if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    fb_repo_out(405, 'text/plain; charset=utf-8', "GET only.\n");
}

// the downloads of each module (Repo Browser: repostats.php?json=true, else repostatslist)
if ($name === 'repostats.php' || $name === 'repostatslist') {
    $counts = array();
    foreach (fb_db()->query('SELECT module, COUNT(*) AS n FROM downloads GROUP BY module ORDER BY module')->fetchAll() as $r) {
        $counts[(string) $r['module']] = (int) $r['n'];
    }
    $mods = fb_repo_modules();
    $counts = array_intersect_key($counts, $mods);
    $stars = array();
    foreach (fb_db()->query('SELECT module, AVG(stars) AS a, COUNT(*) AS n FROM ratings GROUP BY module')->fetchAll() as $r) {
        if (isset($mods[(string) $r['module']])) {
            $stars[(string) $r['module']] = array(round((float) $r['a'], 1), (int) $r['n']);
            $counts += array((string) $r['module'] => 0);
        }
    }
    ksort($counts);
    if ($name === 'repostatslist') {
        $txt = '';
        foreach ($counts as $m => $n) {
            $txt .= $m . ' ' . $n . "\n";
        }
        fb_repo_out(200, 'text/plain; charset=utf-8', $txt);
    }
    $list = array();
    foreach ($counts as $m => $n) {
        $list[] = array('package' => $m, 'downloads' => $n) + (isset($stars[$m]) ? array('rating' => $stars[$m][0], 'ratings' => $stars[$m][1]) : array());
    }
    fb_repo_out(200, 'application/json', json_encode($list));
}

// the repository's lists and descriptions: passed through
if ($name === 'packages.txt' || $name === 'SHA256SUMS' || preg_match('/^[A-Za-z0-9_-]{1,64}\.xml$/', $name)) {
    $body = fb_repo_get($name);
    if ($body === null) {
        fb_repo_out(404, 'text/plain; charset=utf-8', "No such file.\n");
    }
    if ($body === '') {
        fb_repo_out(502, 'text/plain; charset=utf-8', "The repository can not be reached right now.\n");
    }
    fb_repo_out(200, substr($name, -4) === '.xml' ? 'application/xml' : 'text/plain; charset=utf-8', $body);
}

// a download: counted, then sent on to the file
if (preg_match('/^([A-Za-z0-9_-]{1,64})\.zpp$/', $name, $m)) {
    $module = $m[1];
    if (!isset(fb_repo_modules()[$module])) {
        fb_repo_out(404, 'text/plain; charset=utf-8', "The repository has no such module.\n");
    }
    // Repo Browser checks that a package is there with HEAD, and does not follow a redirect
    if ($method === 'HEAD') {
        fb_repo_out(200, 'application/zip', '');
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strpos($ua, 'ZppyClient') === 0) {
        $kind = isset($_GET['for']) && in_array($_GET['for'], array('install', 'upgrade'), true) ? $_GET['for'] : 'download';
        $panel = isset($_GET['panel']) && is_string($_GET['panel']) && preg_match('/^[0-9a-f]{32}$/', $_GET['panel']) ? $_GET['panel'] : null;
        $who = hash('sha256', ($panel !== null ? $panel : fb_remote_ip()) . '|' . $module . '|' . $kind);
        $db = fb_db();
        $q = $db->prepare('SELECT COUNT(*) FROM downloads WHERE who = ? AND at > ?');
        $q->execute(array($who, time() - 600));
        if ((int) $q->fetchColumn() === 0) {
            $db->prepare('INSERT INTO downloads (module, at, kind, panel, who) VALUES (?, ?, ?, ?, ?)')->execute(array($module, time(), $kind, $panel, $who));
        }
    }
    header('Location: ' . rtrim((string) $c['repo_upstream'], '/') . '/' . rawurlencode($module . '.zpp'), true, 302);
    header('Cache-Control: no-store');
    exit;
}

if ($name === 'repo' || $name === '' || $name === 'index.php') {
    $self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) . '/repo';
    fb_repo_out(200, 'text/plain; charset=utf-8', "The Sentora-2027 add-on repository.\nIn a panel: Repo Browser > Repositories > " . $self . "\n");
}
fb_repo_out(404, 'text/plain; charset=utf-8', "No such file.\n");
