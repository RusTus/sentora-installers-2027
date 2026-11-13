<?php
/**
 * Web Hardening, every daemon run: ModSecurity and each PHP brought to what the administrator chose
 * (ph_modsec, ph_daemon), and what they caught read from their logs.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
// (the daemon's only: never from a browser asking for this file)
if (PHP_SAPI !== 'cli') {
    return;
}
require_once dirname(__FILE__) . '/../code/ph.php';
require_once dirname(__FILE__) . '/../code/daemon.php';
require_once dirname(__FILE__) . '/../code/modsec.php';
// (found by its folder: its name in the panel was "PHP Hardening" before version 101)
$phQ = $GLOBALS['zdbh']->prepare("SELECT mo_enabled_en FROM x_modules WHERE mo_folder_vc = :f");
$phQ->execute(array(':f' => basename(dirname(dirname(__FILE__)))));
if ($phQ->fetchColumn() === 'true') {
    try {
        foreach (ph_daemon::DaemonRun() as $phLine) {
            echo $phLine . fs_filehandler::NewLine();
        }
    } catch (Exception $e) {
        echo "Web Hardening: not run (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
    }
}
