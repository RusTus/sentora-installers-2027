<?php

/**
 * Docker Hosting's address for its nodes: a node posts a sealed, signed message here (to link
 * itself with its one-time code, or to take its jobs and report), and gets a sealed, signed reply.
 * Anything that is not such a message gets an empty 400: nothing is told why.
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
require_once dirname(__FILE__) . '/dh.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=us-ascii');
// the panel's public keys (not secret): a new node's installer fetches them, and the person
// installing checks the panel's code against this panel's page before the node trusts them
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['key'])) {
    if (class_exists('ui_module') && !ui_module::CheckModuleEnabled('Docker Hosting')) {
        http_response_code(503);
        exit;
    }
    dh::Ensure();
    $dhKey = dh::PanelKey();
    if ($dhKey === '') {
        http_response_code(503);
        exit;
    }
    echo $dhKey;
    exit;
}
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$dhLen = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
// (agents from 105 send the message as a form field, m=..., which web filters such as ModSecurity
// expect; older ones send it bare. URL encoding makes it at most three times as long.)
if ($dhLen <= 0 || $dhLen > 3 * dh_proto::MAX) {
    http_response_code(400);
    exit;
}
// (the module must be switched on in the panel)
if (class_exists('ui_module') && !ui_module::CheckModuleEnabled('Docker Hosting')) {
    http_response_code(503);
    exit;
}
dh::Ensure();
$dhBody = (string) file_get_contents('php://input', false, null, 0, 3 * dh_proto::MAX);
if (strncmp($dhBody, 'm=', 2) === 0) {
    $dhBody = rawurldecode(substr($dhBody, 2));
}
if (strlen($dhBody) > dh_proto::MAX) {
    http_response_code(400);
    exit;
}
$dhReply = dh::Receive($dhBody, isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
if ($dhReply === '') {
    http_response_code(400);
    exit;
}
echo $dhReply;
