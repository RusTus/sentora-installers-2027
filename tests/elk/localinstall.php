<?php
// zppy install's steps, from a local .zpp (tests): php localinstall.php <name> <file.zpp>
chdir('/etc/sentora/panel/');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
$n = $argv[1];
@mkdir('modules/' . $n);
if (!sys_archive::Unzip($argv[2], 'modules/' . $n)) { echo "unzip failed\n"; exit(1); }
fs_director::SetFileSystemPermissions('modules/' . $n, 0777);
ui_module::ModuleInfoToDB($n);
include 'modules/' . $n . '/deploy/install.run';
echo "installed\n";
