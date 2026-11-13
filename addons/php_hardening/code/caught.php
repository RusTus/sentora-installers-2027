<?php

/**
 * Web Hardening: a page of "What it caught", its new lines, or its statistics, as JSON, for the
 * page (assets/ph.js asks every few seconds). Administrators only; it only reads the panel's own
 * tables (the guards' logs stay root's: the daemon copies from them).
 * GET after=<the newest line the page has> or before=<the oldest>, with q, kind, act, guard (the
 * filters); or stats=1&days=<1-400>. Every value is checked in module_controller::Feed and ph::Where.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

session_start();
if (method_exists('ctrl_auth', 'CheckSessionAccounts')) {
    ctrl_auth::CheckSessionAccounts();   // (a switched-off or deleted account's session ends here)
}
$phUid = isset($_SESSION['zpuid']) ? (int) $_SESSION['zpuid'] : 0;
session_write_close();
$phUser = $phUid > 0 ? ctrl_users::GetUserDetail($phUid) : false;
if (!$phUser || (int) $phUser['usergroupid'] !== 1 || $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(403);
    exit(json_encode(array('error' => 'administrators only')));
}
// (the module switched off: nothing)
$phQ = $zdbh->prepare("SELECT mo_enabled_en FROM x_modules WHERE mo_folder_vc = :f");
$phQ->execute(array(':f' => basename(dirname(dirname(__FILE__)))));
if ($phQ->fetchColumn() !== 'true') {
    http_response_code(403);
    exit(json_encode(array('error' => 'switched off')));
}
if (method_exists('ui_language', 'ForAccount')) {
    ui_language::ForAccount($phUser['userid']);
}
require_once dirname(__FILE__) . '/controller.ext.php';
echo json_encode(module_controller::Feed($_GET), JSON_INVALID_UTF8_SUBSTITUTE);
