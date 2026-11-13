<?php
/**
 * QuickFig, every daemon run: collect the background tasks that finished, start queued ones (as
 * each account's own user: quickfig_jobs).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
require_once dirname(__FILE__) . '/../code/quickfig.php';
require_once dirname(__FILE__) . '/../code/jobs.php';
if (ui_module::CheckModuleEnabled('QuickFig')) {
    try {
        foreach (quickfig_jobs::Run() as $quickfigLine) {
            echo $quickfigLine . fs_filehandler::NewLine();
        }
    } catch (Exception $e) {
        echo "QuickFig: tasks not run (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
    }
}
