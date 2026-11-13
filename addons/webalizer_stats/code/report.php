<?php

/**
 * Webalizer Stats: serves a domain's report pages and graphs to that domain's
 * owner only. Reports are in /var/sentora/stats/webalizer/<user>/<domain>/,
 * outside the web root. URL: report.php/<light|dark>/<domain>/<file>, so the
 * report's own relative links and images keep working and keep the theme
 * (report.php/<domain>/<file>, without one, is light). Each page gets the
 * panel's look (assets/report.css) in the panel's Light or Dark.
 *
 * Updated by RusTus, 2026-11-08
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
session_write_close();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function webalizer_report_deny($code, $text)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($text);
}

if (!$user || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $user['username'])) {
    webalizer_report_deny(403, 'Please log in to the control panel first.');
}
$parts = explode('/', trim(isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '', '/'));
$theme = 'light';
if (isset($parts[0]) && ($parts[0] === 'light' || $parts[0] === 'dark')) {
    $theme = array_shift($parts);
}
if (count($parts) == 1 && $parts[0] !== '') {
    $parts[] = 'index.html';
}
if (count($parts) != 2) {
    webalizer_report_deny(404, 'Not found.');
}
list($domain, $file) = $parts;
if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $domain) || strpos($domain, '..') !== false
    || !preg_match('/^[A-Za-z0-9_-]+\.(html|png)$/', $file)) {
    webalizer_report_deny(404, 'Not found.');
}

// The domain must be one of this user's own domains or subdomains
global $zdbh;
$sql = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk = :uid AND vh_name_vc = :domain AND vh_deleted_ts IS NULL");
$sql->bindParam(':uid', $user['userid']);
$sql->bindParam(':domain', $domain);
$sql->execute();
if ((int) $sql->fetchColumn() === 0 && !ctrl_statsdomains::IsPanelDomainOf($user['userid'], $domain)) {
    webalizer_report_deny(404, 'Unknown domain.');
}

$path = '/var/sentora/stats/webalizer/' . $user['username'] . '/' . $domain . '/' . $file;
if (!is_file($path)) {
    webalizer_report_deny(404, 'No statistics yet for this domain.');
}
if (substr($file, -4) === '.png') {
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=300');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
// Report text comes from the log (referrers, search terms, user agents):
// no scripts may run, it may only load its own graphs
header('Content-Type: text/html; charset=iso-8859-1');
header("Content-Security-Policy: sandbox allow-same-origin allow-popups; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
header('Cache-Control: private, max-age=300');
// the panel's look, in its Light or Dark: the colours, then the rules (a fixed file of this module)
$vars = $theme === 'dark'
    ? '--wz-scheme: dark; --wz-bg: #12171d; --wz-card: #1b222b; --wz-ink: #e6e9ee; --wz-muted: #9aa5b1; --wz-line: #2c3540; --wz-line2: #232c36; --wz-head: #232c36; --wz-soft: #1f2730; --wz-hover: #1f2730; --wz-accent: #5b97cc;'
    : '--wz-scheme: light; --wz-bg: #f4f6f9; --wz-card: #ffffff; --wz-ink: #1f2933; --wz-muted: #6b7785; --wz-line: #e6e9ee; --wz-line2: #f0f2f5; --wz-head: #eef2f6; --wz-soft: #f4f6f9; --wz-hover: #fafbfc; --wz-accent: #447fb3;';
$css = (string) @file_get_contents(dirname(__FILE__) . '/../assets/report.css');
$style = '<style>:root { ' . $vars . ' }' . "\n" . str_replace('</', '<\\/', $css) . '</style>';
$html = (string) file_get_contents($path);
$at = stripos($html, '</head>');
echo $at === false ? $style . $html : substr($html, 0, $at) . $style . substr($html, $at);
