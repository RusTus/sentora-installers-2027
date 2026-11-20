<?php
/**
 * Elk, every daemon run (root): the panel's certificate, the node paired when one was added on the page, rsyslog's
 * settings in line with the page, and the state noted for it. A switched-off module stops sending.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-20
 */
require_once dirname(__FILE__) . '/../code/elk.php';

echo fs_filehandler::NewLine() . "START Elk" . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Elk')) {
    foreach (elk::Apply() as $elkLine) {
        echo $elkLine . fs_filehandler::NewLine();
    }
} elseif (is_file(elk::CONF)) {
    elk::Remove();
    echo "Elk is switched off: rsyslog no longer sends to the Elk node." . fs_filehandler::NewLine();
} else {
    echo "Elk module DISABLED." . fs_filehandler::NewLine();
}
echo "END Elk" . fs_filehandler::NewLine();
