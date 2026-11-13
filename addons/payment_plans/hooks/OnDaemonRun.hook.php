<?php
/**
 * Payment Plans, every daemon run: automatic plans, invoices, reminders, switching off and on,
 * payments not settled (pp_daemon).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
// (the daemon's only: never from a browser asking for this file)
if (PHP_SAPI !== 'cli') {
    return;
}
require_once dirname(__FILE__) . '/../code/pp.php';
require_once dirname(__FILE__) . '/../code/payments.php';
require_once dirname(__FILE__) . '/../code/daemon.php';
if (ui_module::CheckModuleEnabled('Payment Plans')) {
    try {
        foreach (pp_daemon::Run() as $ppLine) {
            echo $ppLine . fs_filehandler::NewLine();
        }
    } catch (Exception $e) {
        echo "Payment Plans: not run (" . $e->getMessage() . ")" . fs_filehandler::NewLine();
    }
}
