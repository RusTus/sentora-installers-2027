<?php
// One panel page request in its own PHP (as the panel does): env DH_REQ = {me, action, post, get}.
// Any PHP notice or warning fails the request.
set_error_handler(function ($no, $str, $file, $line) {
    fwrite(STDERR, "PHP problem: $str in $file:$line\n");
    exit(3);
});
$in = json_decode(getenv('DH_REQ'), true);
$root = __DIR__ . '/root';
chdir($root);
require $root . '/dryden/loader.inc.php';
$GLOBALS['DH_ME'] = (int) $in['me'];
$_POST = isset($in['post']) ? $in['post'] : array();
$_GET = isset($in['get']) ? $in['get'] : array();
$_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
require $root . '/modules/docker_hosting/code/controller.ext.php';
if (!empty($in['action'])) {
    call_user_func(array('module_controller', 'do' . $in['action']));
}
$html = module_controller::getPage();
echo json_encode(array('msg' => module_controller::$msg, 'command' => module_controller::$command, 'html' => $html));
