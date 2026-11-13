<?php

/**
 * QuickFig worker: every read and change of a site's files, in a process of its own.
 *
 * Started by the panel (quickfig::Run()) as the command line, never from the web, with PHP confined
 * to the account's own folder (open_basedir), without the panel's database settings, and unable to
 * run commands. So a link the site's owner plants (wp-config.php pointing at another account's
 * wp-config.php, or at the panel's own settings) can not be followed: PHP refuses every file outside
 * the account's folder when it is opened.
 *
 * Asked in JSON on standard input ({"op": ..., "home": ..., "dir": ...}); answers in JSON.
 * Config files are read with PHP's own tokenizer, never run; a change replaces only the value of a
 * known setting, with a value written by var_export (it can not become code), is checked for PHP
 * syntax errors before it is written, and the file is kept first (QuickFig's backups, outside the
 * website). Files are written in place, so they keep their owner and permissions.
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

const QF_MAX_FILE = 1048576;
const QF_KEEP = 10;
const QF_SALTS = array('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT');

/**
 * Answer and stop.
 *
 * @param array $a
 * @return void
 */
function qf_out(array $a)
{
    echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Answer an error and stop.
 *
 * @param string $text
 * @return void
 */
function qf_fail($text)
{
    qf_out(array('error' => $text));
}

$req = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($req) || !isset($req['op'], $req['home'], $req['dir'])) {
    qf_fail('bad request');
}
$home = realpath((string) $req['home']);
$dir = realpath((string) $req['dir']);
if ($home === false || $dir === false || !is_dir($dir) || strpos($dir . '/', rtrim($home, '/') . '/') !== 0) {
    qf_fail('The site\'s folder is not in the account\'s own folder.');
}
$home = rtrim($home, '/');

/**
 * Is the path inside the account's folder (resolved), and not a link itself?
 *
 * @param string $path
 * @return bool
 */
function qf_inside($path)
{
    global $home;
    if (is_link($path)) {
        return false;
    }
    $real = realpath($path);
    return $real !== false && strpos($real . '/', $home . '/') === 0;
}

/**
 * Read a file of the site (a regular file, not a link, at most QF_MAX_FILE).
 *
 * @param string $path
 * @return string|false
 */
function qf_read($path)
{
    if (!qf_inside($path) || !is_file($path) || filesize($path) > QF_MAX_FILE) {
        return false;
    }
    return @file_get_contents($path);
}

/**
 * Does WordPress still show its "Briefly unavailable" page for this .maintenance file? WordPress
 * itself ignores the file once its $upgrading time is 10 minutes old (a normal update that stopped
 * half-way), so only a file that keeps itself fresh ($upgrading = time();), holds a time to come or
 * something else counts. Read as tokens, never run.
 *
 * @param string $path
 * @return bool
 */
function qf_maint_shown($path)
{
    $code = qf_read($path);
    if ($code === false) {
        return true;
    }
    $t = @token_get_all($code);
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_VARIABLE || $t[$i][1] !== '$upgrading') {
            continue;
        }
        $expr = '';
        for ($j = $i + 1; $j < $n && $t[$j] !== ';'; $j++) {
            if (!is_array($t[$j]) || !in_array($t[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $expr .= is_array($t[$j]) ? $t[$j][1] : $t[$j];
            }
        }
        if (preg_match('/^=([0-9]{1,12})$/', $expr, $m)) {
            return time() - (int) $m[1] < 600;
        }
        return true;
    }
    return false;
}

/**
 * Write a file in place (it keeps its owner and permissions), locked.
 *
 * @param string $path
 * @param string $data
 * @return bool
 */
function qf_write($path, $data)
{
    if (!qf_inside($path) || !is_file($path)) {
        return false;
    }
    $h = @fopen($path, 'r+');
    if (!$h) {
        return false;
    }
    flock($h, LOCK_EX);
    ftruncate($h, 0);
    rewind($h);
    $ok = fwrite($h, $data) === strlen($data);
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    return $ok;
}

/**
 * QuickFig's backups of a file: <home>/quickfig-backups/, outside the website.
 *
 * @param string $path The file.
 * @return array{0:string, 1:string} The folder and the start of this file's backup names.
 */
function qf_backup_names($path)
{
    global $home;
    return array($home . '/quickfig-backups', basename($path) . '.' . substr(sha1($path), 0, 10) . '.');
}

/**
 * Keep the file as it is now; the oldest beyond QF_KEEP go.
 *
 * @param string $path
 * @param string $data Its current content.
 * @return bool
 */
function qf_backup($path, $data)
{
    list($bdir, $start) = qf_backup_names($path);
    if (!is_dir($bdir)) {
        @mkdir($bdir, 0750);
    }
    if (!qf_inside($bdir) || !is_dir($bdir)) {
        return false;
    }
    $name = $bdir . '/' . $start . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.bak';
    if (@file_put_contents($name, $data, LOCK_EX) !== strlen($data)) {
        return false;
    }
    @chmod($name, 0640);
    $all = qf_backups($path);
    foreach (array_slice($all, QF_KEEP) as $old) {
        @unlink($bdir . '/' . $old['file']);
    }
    return true;
}

/**
 * The file's backups, newest first.
 *
 * @param string $path
 * @return array<int, array{file:string, time:int, size:int}>
 */
function qf_backups($path)
{
    list($bdir, $start) = qf_backup_names($path);
    $out = array();
    foreach ((array) @scandir($bdir) as $f) {
        if (strpos((string) $f, $start) === 0 && substr($f, -4) === '.bak' && is_file($bdir . '/' . $f) && !is_link($bdir . '/' . $f)) {
            $out[] = array('file' => $f, 'time' => (int) filemtime($bdir . '/' . $f), 'size' => (int) filesize($bdir . '/' . $f));
        }
    }
    usort($out, function ($a, $b) { return strcmp($b['file'], $a['file']); });
    return $out;
}

/**
 * The significant token after (or before) position $i.
 *
 * @param array $t Tokens.
 * @param int $i
 * @param int $dir 1 forwards, -1 backwards.
 * @return int The index, or -1.
 */
function qf_next(array $t, $i, $dir = 1)
{
    for ($j = $i + $dir; $j >= 0 && $j < count($t); $j += $dir) {
        if (is_array($t[$j]) && in_array($t[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return $j;
    }
    return -1;
}

/**
 * A literal's value: strings, numbers, true, false, null; anything else is an expression.
 *
 * @param array $t Tokens.
 * @param int $a First token of the value.
 * @param int $b One past the last.
 * @return array{kind:string, value:mixed}
 */
function qf_literal(array $t, $a, $b)
{
    $sig = array();
    for ($i = $a; $i < $b; $i++) {
        if (is_array($t[$i]) && in_array($t[$i][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        $sig[] = $t[$i];
    }
    if (count($sig) === 2 && $sig[0] === '-' && is_array($sig[1]) && $sig[1][0] === T_LNUMBER) {
        return array('kind' => 'int', 'value' => -(int) $sig[1][1]);
    }
    if (count($sig) !== 1 || !is_array($sig[0])) {
        return array('kind' => 'expr', 'value' => null);
    }
    list($id, $text) = $sig[0];
    if ($id === T_LNUMBER) {
        return array('kind' => 'int', 'value' => (int) $text);
    }
    if ($id === T_CONSTANT_ENCAPSED_STRING) {
        if ($text[0] === "'") {
            return array('kind' => 'string', 'value' => str_replace(array("\\\\", "\\'"), array("\\", "'"), substr($text, 1, -1)));
        }
        $inner = substr($text, 1, -1);
        // a double-quoted string only when it is plain (no $, no escapes)
        return strpbrk($inner, '$\\') === false ? array('kind' => 'string', 'value' => $inner) : array('kind' => 'expr', 'value' => null);
    }
    if ($id === T_STRING) {
        $l = strtolower($text);
        if ($l === 'true' || $l === 'false') {
            return array('kind' => 'bool', 'value' => $l === 'true');
        }
        if ($l === 'null') {
            return array('kind' => 'null', 'value' => null);
        }
    }
    return array('kind' => 'expr', 'value' => null);
}

/**
 * The settings of a wp-config.php: each define('NAME', value) (the first of each name) and
 * $table_prefix, with where their values are.
 *
 * @param string $code
 * @return array{tokens:array, defines:array, prefix:?array, insert:int}|string The parts, or what is wrong.
 */
function qf_wp_parse($code)
{
    try {
        $t = token_get_all($code, TOKEN_PARSE);
    } catch (ParseError $e) {
        return 'wp-config.php has a PHP error on line ' . $e->getLine() . ': ' . $e->getMessage();
    }
    $defines = array();
    $prefix = null;
    $insert = -1;
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        $tok = $t[$i];
        if (!is_array($tok)) {
            continue;
        }
        // where new settings go: before the "stop editing" line, else before wp-settings.php is loaded
        if ($insert < 0 && in_array($tok[0], array(T_COMMENT, T_DOC_COMMENT), true) && stripos($tok[1], 'stop editing') !== false) {
            $insert = $i;
        }
        if ($insert < 0 && in_array($tok[0], array(T_REQUIRE_ONCE, T_REQUIRE, T_INCLUDE, T_INCLUDE_ONCE), true)) {
            for ($j = $i + 1; $j < $n && $t[$j] !== ';'; $j++) {
                if (is_array($t[$j]) && $t[$j][0] === T_CONSTANT_ENCAPSED_STRING && stripos($t[$j][1], 'wp-settings.php') !== false) {
                    $insert = $i;
                    break;
                }
            }
        }
        if ($tok[0] === T_VARIABLE && $tok[1] === '$table_prefix' && $prefix === null) {
            $eq = qf_next($t, $i);
            if ($eq >= 0 && $t[$eq] === '=') {
                $end = $eq + 1;
                while ($end < $n && $t[$end] !== ';') {
                    $end++;
                }
                $prefix = array('start' => $eq + 1, 'end' => $end) + qf_literal($t, $eq + 1, $end);
            }
            continue;
        }
        if ($tok[0] !== T_STRING || strtolower($tok[1]) !== 'define') {
            continue;
        }
        $prev = qf_next($t, $i, -1);
        if ($prev >= 0 && (in_array($t[$prev], array('->', '::'), true) || (is_array($t[$prev]) && in_array($t[$prev][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NS_SEPARATOR), true)))) {
            continue;
        }
        $open = qf_next($t, $i);
        $nameAt = $open >= 0 && $t[$open] === '(' ? qf_next($t, $open) : -1;
        if ($nameAt < 0 || !is_array($t[$nameAt]) || $t[$nameAt][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $name = substr($t[$nameAt][1], 1, -1);
        $comma = qf_next($t, $nameAt);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name) || $comma < 0 || $t[$comma] !== ',') {
            continue;
        }
        $depth = 0;
        $end = $comma + 1;
        for (; $end < $n; $end++) {
            if ($t[$end] === '(' || $t[$end] === '[') {
                $depth++;
            } elseif ($t[$end] === ')' || $t[$end] === ']') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($t[$end] === ',' && $depth === 0) {
                break;
            }
        }
        $semi = qf_next($t, $end);
        if (!isset($defines[$name])) {
            // removable only when it stands on its own (not the body of an if without braces)
            $alone = $prev < 0 || in_array($t[$prev], array(';', '{', '}'), true) || (is_array($t[$prev]) && $t[$prev][0] === T_OPEN_TAG);
            $defines[$name] = array('start' => $comma + 1, 'end' => $end, 'stmt' => $i, 'semi' => ($semi >= 0 && $t[$semi] === ';') ? $semi : -1,
                'alone' => $alone && $t[$end] === ')') + qf_literal($t, $comma + 1, $end);
        }
    }
    return array('tokens' => $t, 'defines' => $defines, 'prefix' => $prefix, 'insert' => $insert);
}

/**
 * A value as PHP code: only literals (var_export for text: it can not become code).
 *
 * @param array $v {"t": "bool"|"int"|"string", "v": ...}
 * @return string|false
 */
function qf_code($v)
{
    if (!is_array($v) || !isset($v['t'])) {
        return false;
    }
    if ($v['t'] === 'bool') {
        return !empty($v['v']) ? 'true' : 'false';
    }
    if ($v['t'] === 'int' && is_numeric($v['v']) && (int) $v['v'] == $v['v']) {
        return (string) (int) $v['v'];
    }
    if ($v['t'] === 'string' && is_string($v['v']) && strlen($v['v']) <= 2048 && !preg_match('/[\x00-\x1F\x7F]/', $v['v'])) {
        return var_export($v['v'], true);
    }
    return false;
}

/**
 * The wp-config.php with settings changed: $set (name => value) and $unset (names back to WordPress's own default).
 *
 * @param string $code
 * @param array $set
 * @param array $unset
 * @return string The new code, or an array with 'error'.
 */
function qf_wp_change($code, array $set, array $unset)
{
    $p = qf_wp_parse($code);
    if (is_string($p)) {
        return array('error' => $p);
    }
    $t = $p['tokens'];
    $text = array();
    foreach ($t as $i => $tok) {
        $text[$i] = is_array($tok) ? $tok[1] : $tok;
    }
    $add = '';
    foreach ($set as $name => $v) {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $name)) {
            return array('error' => 'bad setting name');
        }
        $lit = qf_code($v);
        if ($lit === false) {
            return array('error' => 'bad value for ' . $name);
        }
        if (isset($p['defines'][$name])) {
            $d = $p['defines'][$name];
            if ($d['kind'] === 'expr') {
                return array('error' => $name . ' is set by code in wp-config.php (not a plain value): change it there');
            }
            // the whole value becomes the literal, keeping the spacing around it
            $lead = ($d['start'] < $d['end'] && is_array($t[$d['start']]) && $t[$d['start']][0] === T_WHITESPACE) ? $text[$d['start']] : ' ';
            for ($i = $d['start']; $i < $d['end']; $i++) {
                $text[$i] = '';
            }
            $last = $d['end'] - 1;
            $trail = ($last >= $d['start'] && is_array($t[$last]) && $t[$last][0] === T_WHITESPACE && $last !== $d['start']) ? $t[$last][1] : '';
            $text[$d['start']] = $lead . $lit . $trail;
        } else {
            $add .= "define( '" . $name . "', " . $lit . " );\n";
        }
    }
    foreach ($unset as $name) {
        if (!isset($p['defines'][$name])) {
            continue;
        }
        $d = $p['defines'][$name];
        if (!$d['alone'] || $d['semi'] < 0) {
            return array('error' => $name . ' is set inside other code in wp-config.php: change it there');
        }
        for ($i = $d['stmt']; $i <= $d['semi']; $i++) {
            $text[$i] = '';
        }
        // and the line break after it
        $nx = $d['semi'] + 1;
        if (isset($t[$nx]) && is_array($t[$nx]) && $t[$nx][0] === T_WHITESPACE) {
            $text[$nx] = preg_replace('/^[ \t]*\r?\n/', '', $text[$nx], 1);
        }
    }
    if ($add !== '') {
        if ($p['insert'] < 0) {
            return array('error' => 'wp-config.php has no line where WordPress loads its settings: new settings can not be added');
        }
        $text[$p['insert']] = $add . "\n" . $text[$p['insert']];
    }
    $new = implode('', $text);
    // checked before it is written: PHP must read it, and every setting must read back as set
    $check = qf_wp_parse($new);
    if (is_string($check)) {
        return array('error' => 'the change would break wp-config.php (' . $check . '): nothing was changed');
    }
    foreach ($set as $name => $v) {
        $got = isset($check['defines'][$name]) ? $check['defines'][$name] : null;
        $want = $v['t'] === 'bool' ? !empty($v['v']) : ($v['t'] === 'int' ? (int) $v['v'] : (string) $v['v']);
        if (!$got || $got['kind'] !== $v['t'] || $got['value'] !== $want) {
            return array('error' => 'the change did not read back as ' . $name . ': nothing was changed');
        }
    }
    return $new;
}

/**
 * Where WordPress is: the folder with wp-settings.php, and its wp-config.php (in that folder, or
 * one above it when that one has no WordPress of its own, as WordPress allows).
 *
 * @param string $dir
 * @return array{root:string, config:string}|null
 */
function qf_wp_find($dir)
{
    if (!is_file($dir . '/wp-settings.php')) {
        return null;
    }
    if (is_file($dir . '/wp-config.php') && qf_inside($dir . '/wp-config.php')) {
        return array('root' => $dir, 'config' => $dir . '/wp-config.php');
    }
    $up = dirname($dir);
    if (is_file($up . '/wp-config.php') && !is_file($up . '/wp-settings.php') && qf_inside($up . '/wp-config.php')) {
        return array('root' => $dir, 'config' => $up . '/wp-config.php');
    }
    return null;
}

/**
 * Delete what is in a folder (not the folder), without following links.
 *
 * @param string $dir
 * @return array{files:int, bytes:int}
 */
function qf_empty($dir)
{
    $n = 0;
    $bytes = 0;
    if (!is_dir($dir) || !qf_inside($dir)) {
        return array('files' => 0, 'bytes' => 0);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($n > 200000) {
            break;
        }
        $p = $f->getPathname();
        if (is_link($p) || $f->isFile()) {
            $bytes += is_link($p) ? 0 : (int) $f->getSize();
            @unlink($p);
            $n++;
        } elseif ($f->isDir()) {
            @rmdir($p);
        }
    }
    return array('files' => $n, 'bytes' => $bytes);
}

/**
 * The size of what is in a folder (bytes), without following links.
 *
 * @param string $dir
 * @return int
 */
function qf_size($dir)
{
    $b = 0;
    if (!is_dir($dir) || !qf_inside($dir)) {
        return 0;
    }
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (++$n > 200000) {
            break;
        }
        if (!is_link($f->getPathname()) && $f->isFile()) {
            $b += (int) $f->getSize();
        }
    }
    return $b;
}

$op = (string) $req['op'];
$sub = isset($req['sub']) ? trim((string) $req['sub'], '/') : '';
if ($sub !== '' && (!preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $sub) || preg_match('#(^|/)\.\.?(/|$)#', $sub))) {
    qf_fail('The folder may have letters, digits, dots, dashes and / only.');
}
$site = $sub === '' ? $dir : $dir . '/' . $sub;
if (!is_dir($site) || !qf_inside($site)) {
    qf_fail('No such folder in the site.');
}
$site = realpath($site);

// --- what is in the folder
if ($op === 'detect') {
    $wp = qf_wp_find($site);
    if ($wp) {
        qf_out(array('type' => 'wordpress', 'config' => substr($wp['config'], strlen($home) + 1), 'root' => substr($wp['root'], strlen($home) + 1)));
    }
    if (is_file($site . '/app/etc/env.php') && is_file($site . '/bin/magento')) {
        qf_out(array('type' => 'magento', 'config' => substr($site . '/app/etc/env.php', strlen($home) + 1), 'root' => substr($site, strlen($home) + 1)));
    }
    // another web app QuickFig knows (no settings page of its own: the crawler blocker), or a plain website
    $app = qf_app_in($site);
    $apps = qf_apps();
    qf_out(array('type' => 'none', 'app' => $app !== null ? $app : '', 'appname' => $app !== null ? $apps[$app][0] : ''));
}

/**
 * Magento's app/etc/env.php as a value, read as tokens, never run: `return [...]` (or array(...))
 * of plain values only: strings, numbers, true, false, null and arrays. Anything else (a call, a
 * constant, a variable) makes it unreadable here.
 *
 * @param string $code
 * @return array|null
 */
function qf_mage_env($code)
{
    $t = @token_get_all($code);
    $sig = array();
    foreach ($t as $tok) {
        if (is_array($tok) && in_array($tok[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG), true)) {
            continue;
        }
        $sig[] = $tok;
    }
    if (count($sig) < 3 || !is_array($sig[0]) || $sig[0][0] !== T_RETURN) {
        return null;
    }
    $i = 1;
    $value = function () use (&$value, &$i, $sig) {
        if (!isset($sig[$i])) {
            throw new Exception('end');
        }
        $tok = $sig[$i];
        $open = $tok === '[' ? ']' : (is_array($tok) && $tok[0] === T_ARRAY && ($sig[$i + 1] ?? null) === '(' ? ')' : null);
        if ($open !== null) {
            $i += $tok === '[' ? 1 : 2;
            $arr = array();
            while (($sig[$i] ?? null) !== $open) {
                $first = $value();
                if (is_array($sig[$i] ?? null) && $sig[$i][0] === T_DOUBLE_ARROW) {
                    $i++;
                    if (!is_string($first) && !is_int($first)) {
                        throw new Exception('key');
                    }
                    $arr[$first] = $value();
                } else {
                    $arr[] = $first;
                }
                if (($sig[$i] ?? null) === ',') {
                    $i++;
                } elseif (($sig[$i] ?? null) !== $open) {
                    throw new Exception('list');
                }
            }
            $i++;
            return $arr;
        }
        $neg = false;
        if ($tok === '-') {
            $neg = true;
            $tok = $sig[++$i] ?? null;
        }
        if (is_array($tok) && $tok[0] === T_DNUMBER && preg_match('/^\d+\.\d+$/', $tok[1])) {
            $i++;
            return $neg ? -(float) $tok[1] : (float) $tok[1];
        }
        if (is_array($tok) && in_array($tok[0], array(T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_STRING), true)) {
            $l = qf_literal(array($tok), 0, 1);
            if ($l['kind'] === 'expr' || ($neg && !in_array($l['kind'], array('int', 'float'), true))) {
                throw new Exception('not a plain value');
            }
            $i++;
            return $neg ? -$l['value'] : $l['value'];
        }
        throw new Exception('not a plain value');
    };
    try {
        $v = $value();
    } catch (Exception $e) {
        return null;
    }
    // the return, and nothing after it (a closing tag at most)
    $rest = array_slice($sig, $i + 1);
    $tail = count($rest) === 0 || (count($rest) === 1 && is_array($rest[0]) && $rest[0][0] === T_CLOSE_TAG);
    return ($sig[$i] ?? null) === ';' && $tail && is_array($v) ? $v : null;
}

/**
 * The shop's database (its default connection in env.php), and its tables' prefix.
 *
 * @param string $site
 * @return array{0:mysqli, 1:string}
 */
function qf_mage_db($site)
{
    $env = qf_mage_env((string) qf_read($site . '/app/etc/env.php'));
    $c = $env['db']['connection']['default'] ?? null;
    if (!is_array($c) || !isset($c['dbname'], $c['username']) || !is_string($c['dbname']) || !is_string($c['username'])) {
        qf_fail('The shop\'s database settings in app/etc/env.php could not be read (QuickFig reads plain values only).');
    }
    $prefix = (string) ($env['db']['table_prefix'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]{0,20}$/', $prefix)) {
        qf_fail('The shop\'s table prefix is not a plain name.');
    }
    if (!function_exists('mysqli_init')) {
        qf_fail('This server\'s PHP has no MySQL support.');
    }
    $host = (string) ($c['host'] ?? 'localhost');
    $port = null;
    $socket = null;
    if (preg_match('/^(.*):(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = (int) $m[2];
    } elseif (preg_match('/^(.*):(\/.+)$/', $host, $m)) {
        $host = $m[1];
        $socket = $m[2];
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    if (!@$db->real_connect($host, $c['username'], (string) ($c['password'] ?? ''), $c['dbname'], $port, $socket)) {
        qf_fail('The shop\'s database did not answer (MySQL said ' . mysqli_connect_errno() . ').');
    }
    return array($db, $prefix);
}

// --- Crawlers turned away (.htaccess) and asked to keep off search pages (robots.txt): QuickFig's
// own marked sections only; the rest of each file stays as it is

/** The marks around QuickFig's sections. */
const QF_BOTS_BEGIN = '# BEGIN QuickFig crawlers (Domain Management > QuickFig: change it there)';
const QF_BOTS_END = '# END QuickFig crawlers';

/** Search engines that call themselves an old Chrome on purpose: never caught by the old-Chrome rule. */
const QF_GOOD_BOTS = 'googlebot|google-inspectiontool|storebot-google|bingbot|adidxbot|bingpreview|duckduckbot|applebot|yandex|baiduspider|amazonbot|slurp|facebookexternalhit|linkedinbot|twitterbot|chrome-lighthouse';

/**
 * A text with QuickFig's section taken out, and the section (without its marks).
 *
 * @param string $text
 * @return array{0:string, 1:string}
 */
function qf_bots_split($text)
{
    $a = strpos($text, QF_BOTS_BEGIN);
    $b = $a === false ? false : strpos($text, QF_BOTS_END, $a);
    if ($a === false || $b === false) {
        return array($text, '');
    }
    $end = $b + strlen(QF_BOTS_END);
    $inner = substr($text, $a + strlen(QF_BOTS_BEGIN), $b - $a - strlen(QF_BOTS_BEGIN));
    $rest = substr($text, 0, $a) . ltrim(substr($text, $end), "\r\n");
    return array($rest, $inner);
}

/**
 * Apache's pattern for a Chrome major version below $t (100 to 199): 0-9, 10-99, 100 to $t - 1.
 *
 * @param int $t
 * @return string
 */
function qf_bots_below($t)
{
    $t = max(100, min(199, (int) $t));
    $parts = array('\d', '\d\d');
    $tens = intdiv($t - 100, 10);
    $ones = $t % 10;
    if ($tens > 0) {
        $parts[] = '1' . ($tens > 1 ? '[0-' . ($tens - 1) . ']' : '0') . '\d';
    }
    if ($ones > 0) {
        $parts[] = '1' . $tens . ($ones > 1 ? '[0-' . ($ones - 1) . ']' : '0');
    }
    return '(' . implode('|', $parts) . ')';
}

/**
 * Web apps QuickFig recognises in a site's folder besides WordPress and Magento (which have their
 * own settings pages), for the crawler blocker's robots.txt: kind => array(name, the files (or
 * folders, ending in /) that must all be there, the robots.txt rules, what they keep crawlers off).
 * The first that matches counts: a particular app before the framework it is built on. To teach
 * QuickFig another app, add a line here.
 *
 * @return array<string, array{0:string, 1:string[], 2:string, 3:string}>
 */
function qf_apps()
{
    return array(
        'opencart' => array('OpenCart', array('system/startup.php', 'catalog/controller/'),
            "Disallow: /*route=product/search\nDisallow: /*route=checkout/\nDisallow: /*route=account/\nDisallow: /*route=product/compare\nDisallow: /*&filter=\nDisallow: /*&sort=\nDisallow: /*&limit=\nDisallow: /*?sort=\n",
            'Search, sorting and filters, cart, checkout and account pages (route=product/search, &sort=, &filter=...), and a pause between pages. Search engines follow it; product and category pages stay open to them.'),
        'prestashop' => array('PrestaShop', array('classes/PrestaShopAutoload.php'),
            "Disallow: /*controller=search\nDisallow: /search\nDisallow: /*?q=\nDisallow: /*?order=\nDisallow: /*&order=\nDisallow: /cart\nDisallow: /order\nDisallow: /my-account\nDisallow: /*controller=cart\nDisallow: /*controller=order\n",
            'Search, sorting, cart, order and account pages, and a pause between pages. Search engines follow it; product and category pages stay open to them.'),
        'joomla' => array('Joomla', array('administrator/manifests/files/joomla.xml'),
            "Disallow: /administrator/\nDisallow: /component/search/\nDisallow: /component/finder/\nDisallow: /*?searchword=\nDisallow: /*&searchword=\nDisallow: /*?q=\n",
            'Search pages (com_search, com_finder) and the administrator pages, and a pause between pages. Search engines follow it; articles stay open to them.'),
        'drupal' => array('Drupal', array('core/lib/Drupal.php'),
            "Disallow: /search/\nDisallow: /*?search=\nDisallow: /*?keys=\nDisallow: /user/\nDisallow: /admin/\n",
            'Search, user and admin pages, and a pause between pages. Search engines follow it; content stays open to them.'),
        'nextcloud' => array('Nextcloud', array('occ', 'version.php', 'apps/'),
            "Disallow: /\n",
            'The whole site: a private cloud has nothing for search engines.'),
        'snipeit' => array('Snipe-IT', array('artisan', 'app/Models/Asset.php'),
            "Disallow: /\n",
            'The whole site: a private asset list has nothing for search engines.'),
        'osticket' => array('osTicket', array('include/class.osticket.php'),
            "Disallow: /scp/\nDisallow: /tickets.php\nDisallow: /login.php\nDisallow: /open.php\nDisallow: /*?id=\n",
            'The staff pages, tickets, login and new-ticket pages, and a pause between pages. Search engines follow it; the help pages stay open to them.'),
        'mediawiki' => array('MediaWiki', array('includes/Defines.php', 'skins/'),
            "Disallow: /index.php?\nDisallow: /*action=\nDisallow: /*oldid=\nDisallow: /*diff=\nDisallow: /*Special:\n",
            'Edit and history pages, old versions, differences and special pages, and a pause between pages. Search engines follow it; the articles stay open to them.'),
        'phpbb' => array('phpBB', array('viewtopic.php', 'phpbb/'),
            "Disallow: /search.php\nDisallow: /memberlist.php\nDisallow: /ucp.php\nDisallow: /posting.php\nDisallow: /*&sid=\nDisallow: /*?sid=\n",
            'Search, member list, user panel and posting pages and session links, and a pause between pages. Search engines follow it; the topics stay open to them.'),
        'laravel' => array('Laravel app', array('artisan', 'bootstrap/app.php'),
            "Disallow: /*?search=\nDisallow: /*?q=\nDisallow: /login\nDisallow: /admin\n",
            'Search, login and admin pages, and a pause between pages. Search engines follow it; the other pages stay open to them.'),
    );
}

/**
 * Which of qf_apps() is in a folder.
 *
 * @param string $dir
 * @return string|null The kind, or null.
 */
function qf_app_in($dir)
{
    foreach (qf_apps() as $kind => $a) {
        $all = true;
        foreach ($a[1] as $f) {
            if (substr($f, -1) === '/' ? !is_dir($dir . '/' . $f) : !is_file($dir . '/' . $f)) {
                $all = false;
                break;
            }
        }
        if ($all) {
            return $kind;
        }
    }
    return null;
}

/**
 * The robots.txt rules for what is in the site.
 *
 * @param string $kind magento, wordpress, an app of qf_apps() or site.
 * @param string $root The folder.
 * @param string $robText The robots.txt there now ('' when there is none).
 * @return string
 */
function qf_bots_robots($kind, $root, $robText)
{
    if ($kind === 'magento') {
        return "Disallow: /catalogsearch/\nDisallow: /*?*q=\nDisallow: /*?*price=\nDisallow: /*&price=\nDisallow: /checkout/\nDisallow: /customer/\nDisallow: /wishlist/\nDisallow: /*?*SID=\n";
    }
    if ($kind === 'wordpress') {
        return "Disallow: /?s=\nDisallow: /*?s=\nDisallow: /*&s=\nDisallow: /search/\n"
            // a WooCommerce shop: its cart, checkout and account pages, and the endless filter links
            . (is_dir($root . '/wp-content/plugins/woocommerce') ? "Disallow: /cart/\nDisallow: /checkout/\nDisallow: /my-account/\nDisallow: /*add-to-cart=\nDisallow: /*?orderby=\nDisallow: /*&orderby=\nDisallow: /*?filter_\nDisallow: /*&filter_\n" : '')
            . ($robText === '' ? "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" : '');
    }
    $apps = qf_apps();
    return isset($apps[$kind]) ? $apps[$kind][2] : "Disallow: /search\nDisallow: /*?q=\nDisallow: /*?s=\nDisallow: /*?search=\nDisallow: /cgi-bin/\n";
}

/**
 * The folder a site's crawler rules belong in and what is there: Magento, WordPress (its own
 * folder), an app of qf_apps(), or a website QuickFig does not recognise ('site').
 *
 * @param string $site
 * @return array{0:string, 1:string} The folder and the kind.
 */
function qf_bots_root($site)
{
    if (is_file($site . '/app/etc/env.php') && is_file($site . '/bin/magento')) {
        return array($site, 'magento');
    }
    $wp = qf_wp_find($site);
    if ($wp) {
        return array($wp['root'], 'wordpress');
    }
    $app = qf_app_in($site);
    return array($site, $app !== null ? $app : 'site');
}

/**
 * Write a file of the site (in place, keeping its owner; made when missing), its earlier version kept.
 *
 * @param string $path
 * @param string $data
 * @return bool
 */
function qf_bots_save($path, $data)
{
    if (is_link($path)) {
        return false;
    }
    if (!is_file($path)) {
        return $data === '' || (qf_inside(dirname($path)) && @file_put_contents($path, $data, LOCK_EX) === strlen($data) && @chmod($path, 0644));
    }
    $old = qf_read($path);
    if ($old === false) {
        return false;
    }
    if ($old === $data) {
        return true;
    }
    qf_backup($path, $old);
    // nothing left (QuickFig's part was all there was): the file goes, so that the site's own default
    // (WordPress's built-in robots.txt) shows again
    if (trim($data) === '') {
        return @unlink($path);
    }
    return qf_write($path, $data);
}

if ($op === 'bots_read' || $op === 'bots_write') {
    list($broot, $kind) = qf_bots_root($site);
    $hta = $broot . '/.htaccess';
    $rob = $broot . '/robots.txt';
    // a name in the folder that is no file QuickFig may use (a link, also one to outside the account,
    // which PHP will not even look at here, or a folder) stops it before either file is written
    $listed = array_flip((array) @scandir($broot));
    foreach (array($hta, $rob) as $f) {
        if (isset($listed[basename($f)]) && (@is_link($f) || !@is_file($f))) {
            qf_fail('.htaccess or robots.txt could not be read (it must be a file of its own in the site, not a link).');
        }
    }
    $htText = @is_file($hta) ? qf_read($hta) : '';
    $robText = @is_file($rob) ? qf_read($rob) : '';
    if ($htText === false || $robText === false) {
        qf_fail('.htaccess or robots.txt could not be read (it must be a file of its own in the site, not a link).');
    }
    if ($op === 'bots_read') {
        $inner = qf_bots_split($htText)[1];
        $names = preg_match('/^# names: (.*)$/m', $inner, $m) ? array_values(array_filter(explode('|', trim($m[1])), 'strlen')) : array();
        $old = preg_match('/^# chrome older than: (\d+)$/m', $inner, $m) ? (int) $m[1] : 0;
        $apps = qf_apps();
        qf_out(array('kind' => $kind, 'appname' => isset($apps[$kind]) ? $apps[$kind][0] : '', 'covers' => isset($apps[$kind]) ? $apps[$kind][3] : '',
            'woo' => $kind === 'wordpress' && is_dir($broot . '/wp-content/plugins/woocommerce'),
            'names' => $names, 'oldchrome' => $old, 'robots' => qf_bots_split($robText)[1] !== '',
            'htaccess' => is_file($hta), 'robotsfile' => is_file($rob), 'root' => substr($broot, strlen($home) + 1)));
    }
    // the names: letters, digits, dots, dashes, slashes and spaces (checked again here)
    $names = array();
    foreach ((array) ($req['names'] ?? array()) as $n) {
        $n = trim((string) $n);
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/ -]{2,59}$#', $n)) {
            qf_fail('A crawler\'s name has 3 to 60 letters, digits, dots, dashes, slashes or spaces.');
        }
        $names[strtolower($n)] = $n;
    }
    $names = array_slice(array_values($names), 0, 40);
    $oldChrome = (int) ($req['oldchrome'] ?? 0);
    if ($oldChrome !== 0 && ($oldChrome < 100 || $oldChrome > 199)) {
        qf_fail('Not a Chrome version.');
    }
    // .htaccess: turned away with 403
    $block = '';
    if ($names || $oldChrome) {
        $block = QF_BOTS_BEGIN . "\n# names: " . implode('|', $names) . "\n" . ($oldChrome ? '# chrome older than: ' . $oldChrome . "\n" : '')
            . "<IfModule mod_rewrite.c>\nRewriteEngine On\n";
        if ($names) {
            $pattern = implode('|', array_map(function ($n) {
                return str_replace(' ', '\s', preg_quote($n, '#'));
            }, $names));
            $block .= 'RewriteCond %{HTTP_USER_AGENT} (' . $pattern . ") [NC]\nRewriteRule .* - [F,L]\n";
        }
        if ($oldChrome) {
            $block .= 'RewriteCond %{HTTP_USER_AGENT} Chrome/' . qf_bots_below($oldChrome) . "\\. [NC]\n"
                . 'RewriteCond %{HTTP_USER_AGENT} !(' . QF_GOOD_BOTS . ") [NC]\nRewriteRule .* - [F,L]\n";
        }
        $block .= "</IfModule>\n" . QF_BOTS_END . "\n\n";
    }
    $newHt = $block . qf_bots_split($htText)[0];
    // robots.txt: polite crawlers asked to keep off search and filter pages
    $rBlock = '';
    if (!empty($req['robots'])) {
        $rBlock = QF_BOTS_BEGIN . "\n# Search and filter pages are endless and cost the database: crawlers, please stay on the real pages.\nUser-agent: *\n"
            . qf_bots_robots($kind, $broot, $robText)
            . "Crawl-delay: 5\n" . QF_BOTS_END . "\n";
    }
    $robRest = rtrim(qf_bots_split($robText)[0]);
    $newRob = $rBlock !== '' ? ($robRest !== '' ? $robRest . "\n\n" : '') . $rBlock : ($robRest !== '' ? $robRest . "\n" : '');
    if (trim($newHt) === '' && !is_file($hta)) {
        $newHt = '';
    }
    if (!qf_bots_save($hta, $newHt) || !qf_bots_save($rob, $newRob)) {
        qf_fail('.htaccess or robots.txt could not be written.');
    }
    qf_out(array('ok' => true, 'names' => count($names), 'oldchrome' => $oldChrome, 'robots' => $rBlock !== ''));
}

// --- hardening: switches written in QuickFig's own marked part of .htaccess (after the crawlers')
const QF_HARD_BEGIN = '# BEGIN QuickFig hardening (Domain Management > QuickFig: change it there)';
const QF_HARD_END = '# END QuickFig hardening';
const QF_NOPHP_BEGIN = '# BEGIN QuickFig: no PHP in this upload folder (Domain Management > QuickFig)';
const QF_NOPHP_END = '# END QuickFig: no PHP';

/**
 * A text with one of QuickFig's marked sections taken out, the section's inside, and the whole
 * section with its marks.
 *
 * @param string $text
 * @param string $begin
 * @param string $end
 * @return array{0:string, 1:string, 2:string}
 */
function qf_section($text, $begin, $end)
{
    $a = strpos($text, $begin);
    $b = $a === false ? false : strpos($text, $end, $a);
    if ($a === false || $b === false) {
        return array($text, '', '');
    }
    $stop = $b + strlen($end);
    $whole = substr($text, $a, $stop - $a);
    $rest = substr($text, 0, $a) . ltrim(substr($text, $stop), "\r\n");
    return array($rest, substr($text, $a + strlen($begin), $b - $a - strlen($begin)), $whole);
}

/**
 * The folders of a site where uploaded files land (pictures, attachments): no PHP should ever run
 * there. Only the ones that exist.
 *
 * @param string $root
 * @param string $kind
 * @return string[] Relative to $root.
 */
function qf_upload_dirs($root, $kind)
{
    $by = array(
        'wordpress' => array('wp-content/uploads'),
        'magento' => array('pub/media', 'media'),
        'opencart' => array('image'),
        'prestashop' => array('img', 'upload', 'download'),
        'joomla' => array('images'),
        'drupal' => array('sites/default/files'),
        'nextcloud' => array(),
        'snipeit' => array('public/uploads', 'storage/private_uploads'),
        'osticket' => array(),
        'mediawiki' => array('images'),
        'phpbb' => array('files', 'images/avatars/upload'),
        'laravel' => array('public/uploads', 'public/storage'),
        'site' => array('uploads', 'upload', 'images', 'files'),
    );
    $out = array();
    foreach (isset($by[$kind]) ? $by[$kind] : $by['site'] as $d) {
        if (is_dir($root . '/' . $d) && !is_link($root . '/' . $d) && qf_inside($root . '/' . $d)) {
            $out[] = $d;
        }
    }
    return $out;
}

/**
 * Is a name in a folder something QuickFig must not touch (a link, also one to outside the
 * account, which PHP will not even look at here, or a folder)?
 *
 * @param string $path
 * @return bool
 */
function qf_not_a_file($path)
{
    $listed = array_flip((array) @scandir(dirname($path)));
    return isset($listed[basename($path)]) && (@is_link($path) || !@is_file($path));
}

if ($op === 'harden_read' || $op === 'harden_write') {
    list($broot, $kind) = qf_bots_root($site);
    $hta = $broot . '/.htaccess';
    $dirs = qf_upload_dirs($broot, $kind);
    foreach (array_merge(array($hta), array_map(function ($d) use ($broot) { return $broot . '/' . $d . '/.htaccess'; }, $dirs)) as $f) {
        if (qf_not_a_file($f)) {
            qf_fail('A .htaccess of the site is a link or a folder: QuickFig leaves it alone.');
        }
    }
    $htText = @is_file($hta) ? qf_read($hta) : '';
    if ($htText === false) {
        qf_fail('.htaccess could not be read.');
    }
    $inner = qf_section($htText, QF_HARD_BEGIN, QF_HARD_END)[1];
    $now = preg_match('/^# options: (.*)$/m', $inner, $m) ? array_filter(explode(',', trim($m[1])), 'strlen') : array();
    $www = preg_match('/^# www: (www|bare)$/m', $inner, $m) ? $m[1] : '';
    $noPhp = array();
    foreach ($dirs as $d) {
        $t = @is_file($broot . '/' . $d . '/.htaccess') ? qf_read($broot . '/' . $d . '/.htaccess') : '';
        if (is_string($t) && strpos($t, QF_NOPHP_BEGIN) !== false) {
            $noPhp[] = $d;
        }
    }
    if ($op === 'harden_read') {
        qf_out(array('kind' => $kind, 'options' => array_values($now), 'www' => $www, 'uploads' => $dirs, 'nophp' => $noPhp,
            'root' => substr($broot, strlen($home) + 1)));
    }
    $known = array('xmlrpc', 'headers', 'hsts', 'https');
    $want = array_values(array_intersect($known, array_map('strval', (array) ($req['options'] ?? array()))));
    if ($kind !== 'wordpress') {
        $want = array_values(array_diff($want, array('xmlrpc')));
    }
    $www = in_array((string) ($req['www'] ?? ''), array('www', 'bare'), true) && !in_array($kind, array('wordpress', 'magento'), true) ? (string) $req['www'] : '';
    $https = in_array('https', $want, true);
    $block = '';
    if ($want || $www !== '') {
        $block = QF_HARD_BEGIN . "\n# options: " . implode(',', $want) . "\n" . ($www !== '' ? '# www: ' . $www . "\n" : '');
        if (in_array('xmlrpc', $want, true)) {
            $block .= "<Files \"xmlrpc.php\">\nRequire all denied\n</Files>\n";
        }
        if (in_array('headers', $want, true) || in_array('hsts', $want, true)) {
            $block .= "<IfModule mod_headers.c>\n";
            if (in_array('headers', $want, true)) {
                $block .= "Header set X-Content-Type-Options \"nosniff\"\nHeader set X-Frame-Options \"SAMEORIGIN\"\nHeader set Referrer-Policy \"strict-origin-when-cross-origin\"\n";
            }
            if (in_array('hsts', $want, true)) {
                $block .= "Header set Strict-Transport-Security \"max-age=31536000\" \"expr=%{HTTPS} == 'on'\"\n";
            }
            $block .= "</IfModule>\n";
        }
        if ($https || $www !== '') {
            $block .= "<IfModule mod_rewrite.c>\nRewriteEngine On\n";
            $scheme = $https ? 'https' : '%{REQUEST_SCHEME}';
            // (Let's Encrypt's checks reach the site as they are)
            if ($https) {
                $block .= "RewriteCond %{HTTPS} !=on\nRewriteCond %{REQUEST_URI} !^/\\.well-known/\n"
                    . ($www === 'www' ? "RewriteCond %{HTTP_HOST} ^(?:www\\.)?(.+)$ [NC]\nRewriteRule ^ https://www.%1%{REQUEST_URI} [R=301,L]\n"
                        : ($www === 'bare' ? "RewriteCond %{HTTP_HOST} ^(?:www\\.)?(.+)$ [NC]\nRewriteRule ^ https://%1%{REQUEST_URI} [R=301,L]\n"
                            : "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n"));
            }
            if ($www === 'www') {
                $block .= "RewriteCond %{HTTP_HOST} !^www\\. [NC]\nRewriteCond %{REQUEST_URI} !^/\\.well-known/\nRewriteRule ^ " . $scheme . "://www.%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n";
            } elseif ($www === 'bare') {
                $block .= "RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]\nRewriteCond %{REQUEST_URI} !^/\\.well-known/\nRewriteRule ^ " . $scheme . "://%1%{REQUEST_URI} [R=301,L]\n";
            }
            $block .= "</IfModule>\n";
        }
        $block .= QF_HARD_END . "\n\n";
    }
    // the crawlers' part stays first, then this one, then the site's own rules
    list($rest, , $crawl) = qf_section(qf_section($htText, QF_HARD_BEGIN, QF_HARD_END)[0], QF_BOTS_BEGIN, QF_BOTS_END);
    $newHt = ($crawl !== '' ? $crawl . "\n\n" : '') . $block . ltrim($rest, "\r\n");
    if (trim($newHt) === '' && !is_file($hta)) {
        $newHt = '';
    }
    // upload folders: a .htaccess there that refuses every PHP file (and its other names)
    $nophpOn = !empty($req['nophp']);
    $section = QF_NOPHP_BEGIN . "\n<FilesMatch \"\\.(?i:php[0-9]?|phtml|phar|pht|phps)$\">\nRequire all denied\n</FilesMatch>\n" . QF_NOPHP_END . "\n";
    $writes = array($hta => $newHt);
    foreach ($dirs as $d) {
        $f = $broot . '/' . $d . '/.htaccess';
        $t = @is_file($f) ? qf_read($f) : '';
        if ($t === false) {
            qf_fail('A .htaccess of the site could not be read.');
        }
        $rest = rtrim(qf_section($t, QF_NOPHP_BEGIN, QF_NOPHP_END)[0]);
        $writes[$f] = $nophpOn ? $section . ($rest !== '' ? "\n" . $rest . "\n" : '') : ($rest !== '' ? $rest . "\n" : '');
    }
    foreach ($writes as $f => $data) {
        if (!qf_bots_save($f, $data)) {
            qf_fail('.htaccess could not be written: ' . substr($f, strlen($home) + 1));
        }
    }
    qf_out(array('ok' => true, 'options' => $want, 'www' => $www, 'nophp' => $nophpOn ? $dirs : array()));
}

// --- permissions: files and folders anyone on the server could change, and config files with
// passwords that every user could read
/**
 * The config files with database passwords that QuickFig knows, relative to a site's folder.
 *
 * @return string[]
 */
function qf_secret_files()
{
    return array('wp-config.php', '../wp-config.php', 'app/etc/env.php', 'config.php', 'admin/config.php', '.env', 'configuration.php',
        'LocalSettings.php', 'include/ost-config.php', 'config/config.php', 'sites/default/settings.php', 'app/config/parameters.php',
        'config/settings.inc.php', 'config.inc.php', 'settings.php');
}

if ($op === 'perm_scan' || $op === 'perm_fix') {
    $fix = $op === 'perm_fix';
    $me = function_exists('posix_geteuid') ? posix_geteuid() : -1;
    $open = array();
    $notMine = 0;
    $fixed = 0;
    $n = 0;
    $more = false;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    try {
        foreach ($it as $f) {
            if (++$n > 200000) {
                $more = true;
                break;
            }
            if ($f->isLink()) {
                continue;
            }
            $mode = $f->getPerms() & 07777;
            if (!($mode & 0002)) {
                continue;
            }
            $path = $f->getPathname();
            if ($fix) {
                if ($f->getOwner() !== $me) {
                    $notMine++;
                } elseif (@chmod($path, $mode & ~0002)) {
                    $fixed++;
                }
                continue;
            }
            $open[] = substr($path, strlen($site) + 1) . ($f->isDir() ? '/' : '');
        }
    } catch (Exception $e) {
        // (a folder that can not be read: what was found so far counts)
    }
    $secrets = array();
    $closed = 0;
    foreach (qf_secret_files() as $rel) {
        $p = $site . '/' . $rel;
        if (!@is_file($p) || @is_link($p) || !qf_inside($p)) {
            continue;
        }
        $mode = fileperms($p) & 07777;
        if (!($mode & 0007)) {
            continue;
        }
        if ($fix) {
            if (fileowner($p) !== $me) {
                $notMine++;
            } elseif (@chmod($p, $mode & ~0007)) {
                $closed++;
            }
            continue;
        }
        $secrets[] = array('path' => $rel, 'mode' => sprintf('%04o', $mode));
    }
    if ($fix) {
        echo 'Files and folders anyone on the server could change: ' . $fixed . " closed (others may still read them, as before).\n"
            . 'Config files with passwords: ' . $closed . " closed to other users.\n"
            . ($notMine ? $notMine . " not changed: they belong to another user (the panel hands them back to you within the hour; then run this again).\n" : '')
            . ($more ? "Only the first 200,000 files were looked at.\n" : '');
        exit(0);
    }
    qf_out(array('open' => count($open), 'list' => array_slice($open, 0, 25), 'secrets' => $secrets, 'scanned' => min($n, 200000), 'more' => $more, 'time' => time()));
}

// --- WordPress's versions: the core, each plugin and theme (read from their headers, never run),
// for the panel to compare with wordpress.org
/**
 * A WordPress file header's fields (the first 8 KB, as WordPress reads them).
 *
 * @param string $file
 * @param string[] $fields
 * @return array<string, string>
 */
function qf_wp_header($file, array $fields)
{
    $h = @fopen($file, 'rb');
    if (!$h) {
        return array();
    }
    $text = (string) fread($h, 8192);
    fclose($h);
    $out = array();
    foreach ($fields as $f) {
        if (preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($f, '/') . ':(.*)$/mi', $text, $m)) {
            $out[$f] = substr(trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1])), 0, 100);
        }
    }
    return $out;
}

if ($op === 'wp_versions') {
    $wp = qf_wp_find($site);
    if (!$wp) {
        qf_fail('No WordPress in this folder.');
    }
    $root = $wp['root'];
    $core = '';
    $code = qf_read($root . '/wp-includes/version.php');
    if (is_string($code) && preg_match('/\$wp_version\s*=\s*\'([0-9][0-9A-Za-z.-]{0,20})\'/', $code, $m)) {
        $core = $m[1];
    }
    $plugins = array();
    $pdir = $root . '/wp-content/plugins';
    foreach ((array) @scandir($pdir) as $d) {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', (string) $d) || $d === '.' || $d === '..' || !is_dir($pdir . '/' . $d) || is_link($pdir . '/' . $d) || substr($d, -13) === '.quickfig-off') {
            continue;
        }
        $n = 0;
        foreach ((array) @scandir($pdir . '/' . $d) as $f) {
            if (substr((string) $f, -4) !== '.php' || ++$n > 40 || is_link($pdir . '/' . $d . '/' . $f)) {
                continue;
            }
            $hd = qf_wp_header($pdir . '/' . $d . '/' . $f, array('Plugin Name', 'Version'));
            if (isset($hd['Plugin Name']) && $hd['Plugin Name'] !== '') {
                $plugins[$d . '/' . $f] = array('Name' => $hd['Plugin Name'], 'Version' => isset($hd['Version']) ? $hd['Version'] : '');
                break;
            }
        }
    }
    $themes = array();
    $tdir = $root . '/wp-content/themes';
    foreach ((array) @scandir($tdir) as $d) {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', (string) $d) || $d === '.' || $d === '..' || is_link($tdir . '/' . $d) || !is_dir($tdir . '/' . $d) || !is_file($tdir . '/' . $d . '/style.css')) {
            continue;
        }
        $hd = qf_wp_header($tdir . '/' . $d . '/style.css', array('Theme Name', 'Version'));
        if (isset($hd['Theme Name'])) {
            $themes[$d] = array('Name' => $hd['Theme Name'], 'Version' => isset($hd['Version']) ? $hd['Version'] : '');
        }
    }
    qf_out(array('core' => $core, 'plugins' => $plugins, 'themes' => $themes));
}

// --- Magento
if (strpos($op, 'mage_') === 0) {
    if (!is_file($site . '/app/etc/env.php') || !is_file($site . '/bin/magento')) {
        qf_fail('No Magento in this folder.');
    }
    if ($op === 'mage_read') {
        $env = (string) qf_read($site . '/app/etc/env.php');
        $mode = preg_match("/'MAGE_MODE'\s*=>\s*'([a-z]+)'/", $env, $m) ? $m[1] : 'default';
        qf_out(array('mode' => $mode, 'maintenance' => is_file($site . '/var/.maintenance.flag'),
            'cache' => qf_size($site . '/var/cache'), 'pagecache' => qf_size($site . '/var/page_cache')));
    }
    if ($op === 'mage_cache') {
        $a = qf_empty($site . '/var/cache');
        $b = qf_empty($site . '/var/page_cache');
        qf_out(array('ok' => true, 'files' => $a['files'] + $b['files'], 'bytes' => $a['bytes'] + $b['bytes']));
    }
    if ($op === 'mage_maintenance') {
        $flag = $site . '/var/.maintenance.flag';
        if (!empty($req['on'])) {
            if (!is_dir($site . '/var') || !qf_inside($site . '/var') || @file_put_contents($flag, '') === false) {
                qf_fail('The maintenance flag could not be written.');
            }
        } elseif (is_file($flag) || is_link($flag)) {
            @unlink($flag);
        }
        qf_out(array('ok' => true, 'maintenance' => is_file($flag)));
    }
    // the search terms Magento keeps (search_query): every search typed in, also by bots
    if ($op === 'mage_search_read') {
        list($db, $prefix) = qf_mage_db($site);
        $table = $prefix . 'search_query';
        $r = $db->query("SELECT COUNT(*) AS total, COALESCE(SUM(num_results = 0), 0) AS empty FROM `" . $table . "`");
        if (!$r) {
            qf_fail('The shop has no search terms table (' . $table . ').');
        }
        $row = $r->fetch_assoc();
        $size = $db->query("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($table) . "'");
        qf_out(array('total' => (int) $row['total'], 'empty' => (int) $row['empty'], 'bytes' => $size ? (int) $size->fetch_row()[0] : 0));
    }
    if ($op === 'mage_search_clean') {
        // searches that found nothing (bots' junk, mostly), older than the days given; real ones stay
        $days = isset($req['days']) ? max(0, min(365, (int) $req['days'])) : 7;
        list($db, $prefix) = qf_mage_db($site);
        $table = $prefix . 'search_query';
        $until = time() + 20;
        $done = 0;
        $more = false;
        do {
            if (!$db->query("DELETE FROM `" . $table . "` WHERE num_results = 0 AND updated_at < NOW() - INTERVAL " . $days . " DAY LIMIT 20000")) {
                qf_fail('The search terms could not be cleaned (MySQL said ' . $db->errno . ').');
            }
            $n = $db->affected_rows;
            $done += $n;
            $more = $n === 20000;
        } while ($more && time() < $until);
        qf_out(array('ok' => true, 'deleted' => $done, 'more' => $more));
    }
    qf_fail('unknown request');
}

// --- WordPress
$wp = qf_wp_find($site);
if (!$wp) {
    qf_fail('No WordPress in this folder (no wp-settings.php with a wp-config.php).');
}
$config = $wp['config'];
$root = $wp['root'];

if ($op === 'wp_read') {
    $code = qf_read($config);
    if ($code === false) {
        qf_fail('wp-config.php could not be read (it must be a file of its own in the account, not a link).');
    }
    $p = qf_wp_parse($code);
    if (is_string($p)) {
        qf_fail($p);
    }
    $out = array();
    foreach ($p['defines'] as $name => $d) {
        if (in_array($name, QF_SALTS, true) || $name === 'DB_PASSWORD') {
            // never sent to the panel: only whether it is set
            $out[$name] = array('kind' => $d['kind'], 'value' => $d['kind'] === 'string' && $d['value'] !== '' && $d['value'] !== 'put your unique phrase here' ? '(set)' : '');
            continue;
        }
        $out[$name] = array('kind' => $d['kind'], 'value' => $d['value']);
    }
    $plugins = array();
    $pdir = $root . '/wp-content/plugins';
    foreach ((array) @scandir($pdir) as $f) {
        if ($f === '.' || $f === '..' || $f === 'index.php' || !preg_match('/^[A-Za-z0-9._-]+$/', (string) $f) || is_link($pdir . '/' . $f)) {
            continue;
        }
        if (is_dir($pdir . '/' . $f)) {
            $off = substr($f, -13) === '.quickfig-off';
            $plugins[] = array('name' => $off ? substr($f, 0, -13) : $f, 'off' => $off);
        }
    }
    $logFile = $root . '/wp-content/debug.log';
    if (isset($p['defines']['WP_DEBUG_LOG']) && $p['defines']['WP_DEBUG_LOG']['kind'] === 'string') {
        $logFile = $p['defines']['WP_DEBUG_LOG']['value'];
    }
    $maint = $root . '/.maintenance';
    $maintSince = is_file($maint) ? (int) filemtime($maint) : 0;
    qf_out(array('config' => substr($config, strlen($home) + 1), 'root' => substr($root, strlen($home) + 1), 'defines' => $out, 'prefix' => $p['prefix'] ? $p['prefix']['value'] : null,
        'canadd' => $p['insert'] >= 0, 'plugins' => $plugins,
        'maintenance' => $maintSince,
        'maintenance_shown' => $maintSince > 0 && qf_maint_shown($maint),
        'debuglog' => (is_file($logFile) && qf_inside($logFile)) ? (int) filesize($logFile) : -1,
        'cache' => qf_size($root . '/wp-content/cache'),
        'backups' => qf_backups($config)));
}

if ($op === 'wp_write' || $op === 'wp_salts') {
    $code = qf_read($config);
    if ($code === false) {
        qf_fail('wp-config.php could not be read (it must be a file of its own in the account, not a link).');
    }
    $set = isset($req['set']) && is_array($req['set']) ? $req['set'] : array();
    $unset = isset($req['unset']) && is_array($req['unset']) ? array_values(array_filter($req['unset'], 'is_string')) : array();
    if ($op === 'wp_salts') {
        // new secret keys: everyone is logged out of WordPress (64 characters each, without quotes or backslashes)
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_=+[]{};:,.<>/?|~';
        $set = array();
        foreach (QF_SALTS as $s) {
            $k = '';
            for ($i = 0; $i < 64; $i++) {
                $k .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $set[$s] = array('t' => 'string', 'v' => $k);
        }
        $unset = array();
    }
    $new = qf_wp_change($code, $set, $unset);
    if (is_array($new)) {
        qf_fail($new['error']);
    }
    if ($new === $code) {
        qf_out(array('ok' => true, 'changed' => false));
    }
    if (!qf_backup($config, $code)) {
        qf_fail('wp-config.php could not be kept first (QuickFig\'s backups folder): nothing was changed.');
    }
    if (!qf_write($config, $new)) {
        qf_fail('wp-config.php could not be written.');
    }
    qf_out(array('ok' => true, 'changed' => true));
}

if ($op === 'wp_undo') {
    $all = qf_backups($config);
    list($bdir) = qf_backup_names($config);
    if (!$all) {
        qf_fail('There is no earlier version kept.');
    }
    $want = isset($req['file']) ? (string) $req['file'] : $all[0]['file'];
    $found = null;
    foreach ($all as $b) {
        if ($b['file'] === $want) {
            $found = $b;
        }
    }
    if (!$found) {
        qf_fail('No such earlier version.');
    }
    $old = qf_read($bdir . '/' . $found['file']);
    $now = qf_read($config);
    if ($old === false || $now === false) {
        qf_fail('The earlier version could not be read.');
    }
    if (is_string(qf_wp_parse($old))) {
        qf_fail('That earlier version has a PHP error: it was not put back.');
    }
    // the version being replaced is kept too, so putting one back can be undone as well
    if (!qf_backup($config, $now) || !qf_write($config, $old)) {
        qf_fail('wp-config.php could not be written.');
    }
    qf_out(array('ok' => true));
}

if ($op === 'wp_maintenance_off') {
    $f = $root . '/.maintenance';
    if (is_file($f) || is_link($f)) {
        @unlink($f);
    }
    qf_out(array('ok' => !file_exists($f)));
}

if ($op === 'wp_plugin') {
    $name = isset($req['plugin']) ? (string) $req['plugin'] : '';
    if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $name) || $name === '.' || $name === '..') {
        qf_fail('bad plugin name');
    }
    $pdir = $root . '/wp-content/plugins';
    $on = $pdir . '/' . $name;
    $off = $on . '.quickfig-off';
    if (!empty($req['off'])) {
        if (!is_dir($on) || is_link($on) || file_exists($off) || !@rename($on, $off)) {
            qf_fail('The plugin could not be switched off.');
        }
    } else {
        if (!is_dir($off) || is_link($off) || file_exists($on) || !@rename($off, $on)) {
            qf_fail('The plugin could not be put back.');
        }
    }
    qf_out(array('ok' => true));
}

if ($op === 'wp_cache') {
    $r = qf_empty($root . '/wp-content/cache');
    qf_out(array('ok' => true) + $r);
}

if ($op === 'wp_debuglog') {
    $code = qf_read($config);
    $p = $code === false ? 'x' : qf_wp_parse($code);
    $logFile = $root . '/wp-content/debug.log';
    if (is_array($p) && isset($p['defines']['WP_DEBUG_LOG']) && $p['defines']['WP_DEBUG_LOG']['kind'] === 'string') {
        $logFile = $p['defines']['WP_DEBUG_LOG']['value'];
    }
    if (!empty($req['clear'])) {
        if (is_file($logFile) && qf_inside($logFile)) {
            $h = @fopen($logFile, 'r+');
            if ($h) {
                ftruncate($h, 0);
                fclose($h);
            }
        }
        qf_out(array('ok' => true));
    }
    if (!is_file($logFile) || !qf_inside($logFile)) {
        qf_out(array('lines' => array(), 'size' => -1));
    }
    $size = (int) filesize($logFile);
    $h = @fopen($logFile, 'r');
    $text = '';
    if ($h) {
        fseek($h, max(0, $size - 65536));
        $text = (string) stream_get_contents($h);
        fclose($h);
    }
    $lines = array_slice(explode("\n", trim($text)), -200);
    qf_out(array('lines' => $lines, 'size' => $size));
}

if ($op === 'wp_trimlog') {
    // a debug log left on can fill the disk: past 20 MB it keeps its newest 5 MB (from a line's start)
    $code = qf_read($config);
    $p = $code === false ? 'x' : qf_wp_parse($code);
    $logFile = $root . '/wp-content/debug.log';
    if (is_array($p) && isset($p['defines']['WP_DEBUG_LOG']) && $p['defines']['WP_DEBUG_LOG']['kind'] === 'string') {
        $logFile = $p['defines']['WP_DEBUG_LOG']['value'];
    }
    if (!is_file($logFile) || !qf_inside($logFile) || filesize($logFile) <= 20971520) {
        qf_out(array('ok' => true, 'trimmed' => false));
    }
    $h = @fopen($logFile, 'r+');
    if (!$h) {
        qf_fail('The debug log could not be opened.');
    }
    flock($h, LOCK_EX);
    $size = (int) fstat($h)['size'];
    fseek($h, max(0, $size - 5242880));
    $keep = (string) stream_get_contents($h);
    $nl = strpos($keep, "\n");
    $keep = "[QuickFig: older lines removed: the log was " . round($size / 1048576) . " MB]\n" . ($nl === false ? $keep : substr($keep, $nl + 1));
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, $keep);
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    qf_out(array('ok' => true, 'trimmed' => true, 'was' => $size));
}

if ($op === 'wp_protectlog') {
    // the debug log in wp-content must never be served to visitors
    $ht = $root . '/wp-content/.htaccess';
    $block = "\n# QuickFig: the debug log is never served\n<Files \"debug.log\">\n    Require all denied\n</Files>\n";
    if (is_file($ht)) {
        $cur = qf_read($ht);
        if ($cur === false) {
            qf_fail('wp-content/.htaccess could not be read.');
        }
        if (strpos($cur, '# QuickFig: the debug log') === false && !qf_write($ht, $cur . $block)) {
            qf_fail('wp-content/.htaccess could not be written.');
        }
    } elseif (!is_link($ht) && qf_inside($root . '/wp-content')) {
        if (@file_put_contents($ht, ltrim($block)) === false) {
            qf_fail('wp-content/.htaccess could not be written.');
        }
        @chmod($ht, 0644);
    }
    qf_out(array('ok' => true));
}

if ($op === 'wp_dbtest') {
    $code = qf_read($config);
    $p = $code === false ? 'x' : qf_wp_parse($code);
    if (!is_array($p)) {
        qf_fail('wp-config.php could not be read.');
    }
    $v = array();
    foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST') as $k) {
        if (!isset($p['defines'][$k]) || $p['defines'][$k]['kind'] !== 'string') {
            qf_fail($k . ' is not a plain value in wp-config.php.');
        }
        $v[$k] = $p['defines'][$k]['value'];
    }
    if (!function_exists('mysqli_init')) {
        qf_fail('This server\'s PHP has no MySQL support for the test.');
    }
    $host = $v['DB_HOST'];
    $port = null;
    $socket = null;
    if (preg_match('/^(.*):(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = (int) $m[2];
    } elseif (preg_match('/^(.*):(\/.+)$/', $host, $m)) {
        $host = $m[1];
        $socket = $m[2];
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 4);
    $ok = @$db->real_connect($host, $v['DB_USER'], $v['DB_PASSWORD'], $v['DB_NAME'], $port, $socket);
    qf_out($ok ? array('ok' => true) : array('ok' => false, 'why' => 'MySQL said ' . mysqli_connect_errno() . ': ' . mysqli_connect_error()));
}

qf_fail('unknown request');
