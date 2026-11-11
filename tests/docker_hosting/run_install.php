<?php
// The module's install step, as zppy runs it (from the panel's folder).
set_error_handler(function ($no, $str, $file, $line) {
    fwrite(STDERR, "PHP problem: $str in $file:$line\n");
    exit(3);
});
chdir(__DIR__ . '/root');
include __DIR__ . '/root/modules/docker_hosting/deploy/' . (isset($argv[1]) ? $argv[1] : 'install.run');
