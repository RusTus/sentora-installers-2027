<?php

/**
 * The Elk node's address on the panel (no login): a new Elk node's installer fetches this panel's certificate for its
 * logs here (?cert=1). It is a certificate (a public key), not a secret: the person installing checks the panel's code
 * the installer shows against the code on Log Forwarder's page (Elk node) before the node trusts it. Nothing else is answered.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-20
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once dirname(__FILE__) . '/elk.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=us-ascii');
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['cert'])) {
    http_response_code(400);
    exit;
}
$elkCert = elk::PanelCert();
if ($elkCert === '' || elk::Spki($elkCert) === '' || !ui_module::CheckModuleEnabled('Log Forwarder')) {
    // the daemon makes the certificate on its first run after Log Forwarder 203 is installed
    http_response_code(503);
    exit;
}
echo $elkCert;
