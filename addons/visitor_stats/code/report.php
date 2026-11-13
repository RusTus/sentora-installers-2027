<?php

/**
 * Visitor Stats: shows one domain's report to that domain's owner, in the panel's
 * look (assets/report.css) and its Light or Dark (?theme=dark).
 * Log data (referrers, browsers, search terms) comes from visitors, so the
 * page runs sandboxed: no scripts, no outside requests. It keeps the panel's
 * origin (no scripts can use it) so the panel can size its frame to fit.
 *
 * Updated by RusTus, 2026-11-08
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'modules/visitor_stats/code/visitorstats.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$uid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
$user = ($uid > 0) ? ctrl_users::GetUserDetail($uid) : false;
session_write_close();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: sandbox allow-same-origin allow-popups; default-src 'none'; style-src 'unsafe-inline'; img-src data:");

if (!$user) {
    http_response_code(403);
    exit('Please log in to the control panel first.');
}
$domain = isset($_GET['domain']) ? (string) $_GET['domain'] : '';
if (!visitor_stats_helper::ValidDomain($domain) || !visitor_stats_helper::OwnsDomain($user['userid'], $domain)) {
    http_response_code(404);
    exit('Unknown domain.');
}
$file = visitor_stats_helper::ReportFile($user['username'], $domain);
if ($file === false || !is_file($file)) {
    echo '<p style="font-family: sans-serif">' . htmlspecialchars(ui_language::translate('No statistics yet for this domain. They are made every hour from the web server log, once the site has had visitors.'), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}
// the panel's look, in its Light or Dark: the colours, then the rules (a fixed file of this module)
$dark = isset($_GET['theme']) && $_GET['theme'] === 'dark';
$vars = $dark
    ? '--vz-scheme: dark; --vz-bg: #12171d; --vz-card: #1b222b; --vz-ink: #e6e9ee; --vz-muted: #9aa5b1; --vz-line: #2c3540; --vz-line2: #232c36; --vz-head: #1f2730; --vz-soft: #1f2730; --vz-accent: #5b97cc; --vz-bar: #3cc98d; --vz-bar2: #ff7a68;'
    : '--vz-scheme: light; --vz-bg: #f4f6f9; --vz-card: #ffffff; --vz-ink: #1f2933; --vz-muted: #6b7785; --vz-line: #e6e9ee; --vz-line2: #f0f2f5; --vz-head: #fafbfc; --vz-soft: #f4f6f9; --vz-accent: #447fb3; --vz-bar: #2db87d; --vz-bar2: #ff6854;';
$css = (string) @file_get_contents(dirname(__FILE__) . '/../assets/report.css');
$style = '<style>:root { ' . $vars . ' }' . "\n" . str_replace('</', '<\\/', $css) . '</style>';
$html = (string) file_get_contents($file);
$at = stripos($html, '</head>');
echo $at === false ? $style . $html : substr($html, 0, $at) . $style . substr($html, $at);
