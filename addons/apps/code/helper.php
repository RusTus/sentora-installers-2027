<?php

/**
 * The Apps worker: looks into a site's folder for the panel and the daemon. Run only from the
 * command line, confined to the account's folder (open_basedir, no commands, no network: see
 * apps::Run()), it reads one request (JSON) on its input and answers JSON. It only looks: nothing
 * is written here.
 *
 *   check    is the folder free for an app (missing, empty, or only the panel's welcome page,
 *            error pages and .well-known)? and what is in it
 *   version  the version of the app in the folder
 *
 * A folder reached through a link is refused: an app goes only where the site's own folder is.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Answer and stop.
 *
 * @param array $a
 * @return void
 */
function ap_out(array $a)
{
    echo json_encode($a);
    exit(0);
}

/**
 * Answer with an error and stop.
 *
 * @param string $text
 * @return void
 */
function ap_fail($text)
{
    ap_out(array('error' => $text));
}

$req = json_decode((string) stream_get_contents(STDIN, 65536), true);
if (!is_array($req) || !isset($req['op'], $req['home'], $req['dir'])) {
    ap_fail('No request.');
}
$home = (string) $req['home'];
$op = (string) $req['op'];
$sub = isset($req['sub']) ? trim((string) $req['sub'], '/') : '';
if ($sub !== '' && (!preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $sub) || preg_match('#(^|/)\.\.?(/|$)#', $sub))) {
    ap_fail('A folder name has letters, digits, dots, dashes and underscores (and / between folders).');
}
// the site's own folder: a real folder in the account's public_html
$siteDir = rtrim((string) $req['dir'], '/');
$site = realpath($siteDir);
if ($site === false || !is_dir($site) || is_link($siteDir) || strpos($site . '/', $home . '/public_html/') !== 0) {
    ap_fail('The website\'s folder is missing (or is a link).');
}
$target = $site . ($sub !== '' ? '/' . $sub : '');

// every part of the way that is there must be a real folder, not a link
$path = $site;
foreach ($sub !== '' ? explode('/', $sub) : array() as $part) {
    $parent = $path;
    $path .= '/' . $part;
    if (is_link($path)) {
        ap_fail('That folder is a link: an app goes only in a real folder of the site.');
    }
    if (file_exists($path) && !is_dir($path)) {
        ap_fail('There is a file with that name, not a folder.');
    }
    if (!file_exists($path)) {
        // (a link to a place outside the account looks missing here: open_basedir hides it, but
        // its parent folder still lists it)
        if (in_array($part, (array) @scandir($parent), true)) {
            ap_fail('That folder is a link: an app goes only in a real folder of the site.');
        }
        break;
    }
}
$exists = is_dir($target);
$rel = substr($target, strlen($home) + 1);

if ($op === 'check') {
    $found = array();
    $welcome = false;
    if ($exists) {
        foreach ((array) @scandir($target) as $f) {
            if ($f === '.' || $f === '..' || $f === false) {
                continue;
            }
            // the panel's error pages, and certificates' checks (Let's Encrypt: .well-known)
            if (($f === '_errorpages' || $f === '.well-known') && is_dir($target . '/' . $f) && !is_link($target . '/' . $f)) {
                continue;
            }
            if ($f === 'index.html' && is_file($target . '/index.html') && !is_link($target . '/index.html') && (string) $req['welcome'] !== ''
                && filesize($target . '/index.html') < 1048576 && sha1_file($target . '/index.html') === (string) $req['welcome']) {
                $welcome = true;
                continue;
            }
            $found[] = (string) $f;
        }
    }
    sort($found);
    ap_out(array('rel' => $rel, 'exists' => $exists, 'free' => count($found) === 0, 'welcome' => $welcome,
        'found' => array_slice($found, 0, 8), 'more' => max(0, count($found) - 8),
        'wordpress' => $exists && is_file($target . '/wp-includes/version.php')));
}

if ($op === 'version') {
    $f = $target . '/wp-includes/version.php';
    if (!$exists || !is_file($f) || is_link($f) || filesize($f) > 65536) {
        ap_out(array('rel' => $rel, 'version' => ''));
    }
    // read as tokens, never run: $wp_version = '7.1.4';
    $t = @token_get_all((string) file_get_contents($f));
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (is_array($t[$i]) && $t[$i][0] === T_VARIABLE && $t[$i][1] === '$wp_version') {
            for ($j = $i + 1; $j < $n && $j < $i + 6; $j++) {
                if (is_array($t[$j]) && $t[$j][0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"]([0-9][0-9A-Za-z.-]{0,20})[\'"]$/', $t[$j][1], $m)) {
                    ap_out(array('rel' => $rel, 'version' => $m[1]));
                }
            }
        }
    }
    ap_out(array('rel' => $rel, 'version' => ''));
}

ap_fail('Not a request the worker knows.');
