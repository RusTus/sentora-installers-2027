<?php
/**
 * QuickFig, every hour: WP-CLI on the server (downloaded when missing or a month old), and a look
 * at every WordPress site as its account's own user (quickfig_watch: reminders, the debug log).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
require_once dirname(__FILE__) . '/../code/quickfig.php';
require_once dirname(__FILE__) . '/../code/jobs.php';
require_once dirname(__FILE__) . '/../code/watch.php';
if (ui_module::CheckModuleEnabled('QuickFig')) {
    try {
        $quickfigWpCli = quickfig_jobs::GetWpCli();
        if ($quickfigWpCli !== '') {
            echo "QuickFig: " . $quickfigWpCli . fs_filehandler::NewLine();
        }
        foreach (quickfig_watch::Hour() as $quickfigLine) {
            echo $quickfigLine . fs_filehandler::NewLine();
        }
    } catch (Exception $e) {
        echo "QuickFig: hourly look not done (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
    }
}
