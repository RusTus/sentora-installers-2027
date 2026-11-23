<?php
/**
 * zGod, on every daemon run: its lines for the Elk node into the panel's log folder (zgod.log): each account's
 * figures once an hour and what zGod did. Log Forwarder sends the file to the Elk node when one is added; the node's
 * zGod dashboard shows it. Without an Elk node the file is simply there (kept under 5 MB).
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
        $zgDir = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/');
        $zgLine = zgod::ElkRun(($zgDir !== '' ? $zgDir : '/var/sentora/logs') . '/zgod.log');
        if ($zgLine !== '') {
            echo $zgLine . fs_filehandler::NewLine();
        }
    } catch (Exception $e) {
        echo "zGod: lines for the Elk node not written (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
    }
}
