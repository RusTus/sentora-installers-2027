<?php
/**
 * Apps, every hour: WP-CLI on the server (downloaded when missing or a month old) and the latest
 * version of each app (once a day) and the packages (WordPress, OpenCart).
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
        $appsLatest = apps::Latest();
        $appsLines = array(apps_jobs::GetWpCli(), apps_jobs::GetVersions());
        // the newest WordPress package, fetched ahead of the first install
        if (isset($appsLatest['wordpress']['version']) && apps_jobs::WordPressPackage((string) $appsLatest['wordpress']['version']) === '') {
            $appsLines[] = 'Apps: WordPress ' . $appsLatest['wordpress']['version'] . ' could not be downloaded (tried again in an hour).';
        }
        // and OpenCart's (the version Apps pins)
        $appsRecipes = apps::Recipes();
        if (apps_jobs::OpenCartPackage((string) $appsRecipes['opencart']['version'], (string) $appsRecipes['opencart']['sha256']) === '') {
            $appsLines[] = 'Apps: OpenCart ' . $appsRecipes['opencart']['version'] . ' could not be downloaded (tried again in an hour).';
        }
        foreach ($appsLines as $appsLine) {
            if ($appsLine !== '') {
                echo (strpos($appsLine, 'Apps:') === 0 ? '' : 'Apps: ') . $appsLine . fs_filehandler::NewLine();
            }
        }
    } catch (Exception $e) {
        echo "Apps: hourly step not done (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
    }
}
