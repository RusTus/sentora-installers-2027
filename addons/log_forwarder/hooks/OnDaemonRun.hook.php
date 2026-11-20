<?php
/**
 * Log Forwarder, every daemon run (root): rsyslog's settings follow the destinations set in the
 * panel, rsyslog may read exactly the forwarded logs, what clients sent counts towards their
 * bandwidth, and each destination's state is noted for the panel. A switched-off module stops
 * forwarding. The Elk node (administrators) gets the panel's own logs the same way (code/elk.php).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
require_once dirname(__FILE__) . '/../code/logforwarder.php';
require_once dirname(__FILE__) . '/../code/elk.php';

echo fs_filehandler::NewLine() . "START Log Forwarder" . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Log Forwarder')) {
    // the Elk node (administrators): paired, rsyslog there; then one rsyslog file for both; then its state
    foreach (elk::Prepare() as $logForwarderLine) {
        echo 'Elk node: ' . $logForwarderLine . fs_filehandler::NewLine();
    }
    foreach (log_forwarder::Apply() as $logForwarderLine) {
        echo $logForwarderLine . fs_filehandler::NewLine();
    }
    foreach (elk::After(log_forwarder::$installed) as $logForwarderLine) {
        echo 'Elk node: ' . $logForwarderLine . fs_filehandler::NewLine();
    }
    // older access logs asked for on the page ("Bring in older logs")
    foreach (elk::RunImports() as $logForwarderLine) {
        echo 'Elk node: ' . $logForwarderLine . fs_filehandler::NewLine();
    }
} elseif (is_file(log_forwarder::CONF) || is_file(elk::CONF)) {
    log_forwarder::Remove();
    elk::Remove();
    echo "Log Forwarder is switched off: rsyslog no longer forwards." . fs_filehandler::NewLine();
} else {
    echo "Log Forwarder module DISABLED." . fs_filehandler::NewLine();
}
echo "END Log Forwarder" . fs_filehandler::NewLine();
