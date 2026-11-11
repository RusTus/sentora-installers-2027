<?php
// One daemon run of the module's hook (env DH_DB). Any PHP notice or warning fails it.
set_error_handler(function ($no, $str, $file, $line) {
    fwrite(STDERR, "PHP problem: $str in $file:$line\n");
    exit(3);
});
chdir(__DIR__ . '/root');
require __DIR__ . '/root/dryden/loader.inc.php';
include __DIR__ . '/root/modules/docker_hosting/hooks/OnDaemonRun.hook.php';
