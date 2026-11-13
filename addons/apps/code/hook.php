<?php

/**
 * Apps: a Git app's deploy hook. GitHub, GitLab, Bitbucket or any Git server calls it (POST) when
 * the app's repository changes; it queues a deploy of the app's branch, which the daemon runs as
 * the account's own user within 5 minutes (apps_jobs::StartDeploy). Nothing else can be asked:
 * the address carries the app's own secret key, a push to another branch is ignored, and one
 * deploy at a time (at most one every 30 seconds).
 *
 *   POST /modules/apps/code/hook.php?app=<id>&key=<64 hex characters>
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once dirname(__FILE__) . '/apps.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/**
 * Answer and stop.
 *
 * @param int $code HTTP status.
 * @param array $a
 * @return void
 */
function apps_hook_out($code, array $a)
{
    http_response_code($code);
    echo json_encode($a);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apps_hook_out(405, array('error' => 'Send a POST (the Git server\'s webhook).'));
}
$id = isset($_GET['app']) && is_string($_GET['app']) && ctype_digit($_GET['app']) ? (int) $_GET['app'] : 0;
$key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
apps::Ensure();
$q = $zdbh->prepare("SELECT ap_id_pk, ap_acc_fk, ap_token_vc, ap_branch_vc, ap_state_vc, ap_hook_in FROM x_apps WHERE ap_id_pk = :id AND ap_app_vc = 'git' AND ap_deleted_in IS NULL");
$q->execute(array(':id' => $id));
$a = $q->fetch(PDO::FETCH_ASSOC);
if (!$a || strlen((string) $a['ap_token_vc']) !== 64 || !preg_match('/^[0-9a-f]{64}$/', $key) || !hash_equals((string) $a['ap_token_vc'], $key)) {
    apps_hook_out(404, array('error' => 'No such app.'));
}
// GitHub's test when the webhook is added
if (($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '') === 'ping') {
    apps_hook_out(200, array('ok' => true, 'message' => 'The webhook works: a push to ' . $a['ap_branch_vc'] . ' deploys the app.'));
}
// a push to another branch (GitHub, GitLab and Gitea say which in "ref"): nothing to do
$body = json_decode((string) file_get_contents('php://input', false, null, 0, 1048576), true);
if (is_array($body) && isset($body['ref']) && is_string($body['ref']) && $body['ref'] !== 'refs/heads/' . $a['ap_branch_vc']) {
    apps_hook_out(200, array('ok' => true, 'queued' => false, 'message' => 'Not the app\'s branch (' . $a['ap_branch_vc'] . '): nothing to do.'));
}
// the account must be switched on
$on = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_id_pk = :u AND ac_enabled_in = 1 AND ac_deleted_ts IS NULL");
$on->execute(array(':u' => (int) $a['ap_acc_fk']));
if ((int) $on->fetchColumn() === 0) {
    apps_hook_out(403, array('error' => 'The account is switched off.'));
}
if ((int) $a['ap_hook_in'] > time() - 30) {
    apps_hook_out(429, array('ok' => false, 'message' => 'A deploy was asked for less than 30 seconds ago.'));
}
$u = $zdbh->prepare("UPDATE x_apps SET ap_state_vc = 'deploy', ap_hook_in = :t WHERE ap_id_pk = :id AND ap_state_vc = 'ready'");
$u->execute(array(':t' => time(), ':id' => (int) $a['ap_id_pk']));
if ($u->rowCount() === 1) {
    apps_hook_out(202, array('ok' => true, 'queued' => true, 'message' => 'A deploy is queued: the server runs it within 5 minutes.'));
}
apps_hook_out(200, array('ok' => true, 'queued' => false, 'message' => 'The app is busy (' . $a['ap_state_vc'] . '): a deploy is queued or running already.'));
