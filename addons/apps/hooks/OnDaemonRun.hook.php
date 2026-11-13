<?php
/**
 * Apps, every daemon run: collect the installs and removals that finished, start queued ones (as
 * each account's own user: apps_jobs).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
require_once dirname(__FILE__) . '/../code/apps.php';
require_once dirname(__FILE__) . '/../code/jobs.php';
if (ui_module::CheckModuleEnabled('Apps')) {
    try {
        foreach (apps_jobs::Run() as $appsLine) {
            echo $appsLine . fs_filehandler::NewLine();
        }
    } catch (Exception $e) {
        echo "Apps: tasks not run (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
    }
}
