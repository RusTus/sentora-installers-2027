<?php
/**
 * zGod, once a day: the daily report by email, when it is switched on (Server Admin > zGod > Daily report).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-23
 */
// (the daemon's only: never from a browser asking for this file)
if (PHP_SAPI !== 'cli') {
    return;
}
require_once dirname(__FILE__) . '/../code/zgod.php';
$zgQ = $GLOBALS['zdbh']->prepare("SELECT mo_enabled_en FROM x_modules WHERE mo_folder_vc = :f");
$zgQ->execute(array(':f' => basename(dirname(dirname(__FILE__)))));
if ($zgQ->fetchColumn() === 'true') {
    try {
        $zgLine = zgod::DailyReport();
        if ($zgLine !== '') {
            echo $zgLine . fs_filehandler::NewLine();
        }
    } catch (Exception $e) {
        echo "zGod: daily report not sent (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
    }
}
