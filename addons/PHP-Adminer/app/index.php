<?php

/**
 * PHP-Adminer for Sentora: the only way in to Adminer (the folder's .htaccess
 * refuses every other file). It requires a panel login, offers only that
 * account's own MySQL users, connects only to this server's MySQL, and keeps
 * Adminer's session tied to the panel account. A switched-off or deleted account
 * is turned away on its next click, as everywhere else in the panel.
 *
 * Updated by RusTus, 2026-11-08
 */
$app = __DIR__;
// the one MySQL server Adminer may use, by the name its login form shows (adminer-plugins.php)
const SENTORA_ADMINER_SERVER = 'MySQL on this server';
chdir(dirname(__DIR__, 3)); // the panel's folder
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

session_start();
ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
$panelUid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
session_write_close();
if ($panelUid <= 0 || !($panelUser = ctrl_users::GetUserDetail($panelUid))) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    exit('<p style="font-family: sans-serif">Please log in to the control panel first, then open PHP-Adminer from Database Management.</p>');
}

// The account's own MySQL users (made in MySQL Users)
$users = array();
$sql = $zdbh->prepare("SELECT mu_name_vc FROM x_mysql_users WHERE mu_acc_fk = :uid AND mu_deleted_ts IS NULL ORDER BY mu_name_vc");
$sql->execute(array(':uid' => $panelUid));
while ($row = $sql->fetch()) {
    $users[] = (string) $row['mu_name_vc'];
}
$users = array_values(array_unique($users));

// The account's databases, and which of its MySQL users may use each (MySQL Users)
$userDbs = array_fill_keys($users, array());
$sql = $zdbh->prepare("SELECT u.mu_name_vc, d.my_name_vc FROM x_mysql_dbmap m
    JOIN x_mysql_users u ON u.mu_id_pk = m.mm_user_fk AND u.mu_deleted_ts IS NULL
    JOIN x_mysql_databases d ON d.my_id_pk = m.mm_database_fk AND d.my_deleted_ts IS NULL
    WHERE m.mm_acc_fk = :uid AND u.mu_acc_fk = :uid2 AND d.my_acc_fk = :uid3 ORDER BY d.my_name_vc");
$sql->execute(array(':uid' => $panelUid, ':uid2' => $panelUid, ':uid3' => $panelUid));
while ($row = $sql->fetch()) {
    if (isset($userDbs[$row['mu_name_vc']])) {
        $userDbs[$row['mu_name_vc']][] = (string) $row['my_name_vc'];
    }
}
if (!count($users)) {
    header('Content-Type: text/html; charset=utf-8');
    exit('<p style="font-family: sans-serif">You have no MySQL users yet. Create one in Database Management &gt; MySQL Users, then open PHP-Adminer again.</p>');
}

// Adminer's own session (its own cookie and ID, never the panel's), opened here
// first so it can be tied to the panel account: a different panel login in the same
// browser starts with an empty Adminer session. It is closed again before Adminer
// runs, which then opens it in its usual way.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$cookiePath = preg_replace('~[^/]*$~', '', strtok($_SERVER['REQUEST_URI'], '?'));
session_cache_limiter('');
session_name('adminer_sid');
$sid = (isset($_COOKIE['adminer_sid']) && preg_match('/^[A-Za-z0-9,-]{22,256}$/', $_COOKIE['adminer_sid'])) ? $_COOKIE['adminer_sid'] : session_create_id();
session_id($sid);
session_set_cookie_params(array('lifetime' => 0, 'path' => $cookiePath, 'domain' => '', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'));
session_start();
if (!isset($_SESSION['sentora_uid']) || (int) $_SESSION['sentora_uid'] !== $panelUid) {
    $_SESSION = array('sentora_uid' => $panelUid);
    session_regenerate_id(true);
}
// Opened again from the panel (no address of its own) while logged in to Adminer as exactly one
// of the account's MySQL users: straight back in, instead of Adminer's login page with a list of
// the open logins. Two or more: Adminer's list, to choose from.
$resume = sentora_adminer_resume($_SESSION, $users, $_SERVER['REQUEST_METHOD'], $_GET);
session_write_close();
if ($resume !== '') {
    header('Location: ' . $resume, true, 302);
    exit;
}

$GLOBALS['sentora_adminer_users'] = $users;
$GLOBALS['sentora_adminer_dbs'] = $userDbs;
unset($zdbh, $sql, $row); // Adminer has no use for the panel's database connection
chdir($app);
include $app . '/adminer.php';

/**
 * Where to send a request that opens PHP-Adminer without an address of its own: back to the one
 * open Adminer login of the panel account's MySQL users, or nowhere ('').
 *
 * @param array $session Adminer's session ($_SESSION['pwds'][driver][server][user] = password).
 * @param string[] $users The panel account's MySQL users.
 * @param string $method The request method.
 * @param array $get The query parameters.
 * @return string The relative address to go to, or ''.
 */
function sentora_adminer_resume(array $session, array $users, $method, array $get)
{
    if ($method !== 'GET' || count($get) > 0) {
        return '';
    }
    $open = array();
    $logins = isset($session['pwds']['server'][SENTORA_ADMINER_SERVER]) ? (array) $session['pwds']['server'][SENTORA_ADMINER_SERVER] : array();
    foreach ($logins as $user => $password) {
        if ($password !== null && in_array((string) $user, $users, true)) {
            $open[] = (string) $user;
        }
    }
    return count($open) === 1 ? '?server=' . urlencode(SENTORA_ADMINER_SERVER) . '&username=' . urlencode($open[0]) : '';
}
