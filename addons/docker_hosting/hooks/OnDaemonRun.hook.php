<?php
/**
 * Docker Hosting, every daemon run: accounts switched off, deleted accounts, disk limits (dh_daemon).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
// (the daemon's only: never from a browser asking for this file)
if (PHP_SAPI !== 'cli') {
    return;
}
require_once dirname(__FILE__) . '/../code/dh.php';
require_once dirname(__FILE__) . '/../code/daemon.php';
if (ui_module::CheckModuleEnabled('Docker Hosting')) {
    try {
        foreach (dh_daemon::Run() as $dhLine) {
            echo $dhLine . fs_filehandler::NewLine();
        }
    } catch (Exception $e) {
        echo "Docker Hosting: not run (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
    }
}
