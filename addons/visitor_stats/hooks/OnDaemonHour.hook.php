<?php
/**
 * Visitor Stats, every hour: a Visitors report for each domain that has had traffic, in
 * /var/sentora/stats/visitors/<user>/<domain>.html (root's; the panel's group may read them).
 *
 * Updated by RusTus, 2026-11-08
 */

require_once dirname(__FILE__) . '/../code/visitorstats.php';

echo fs_filehandler::NewLine() . "BEGIN Visitor Stats" . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Visitor Stats')) {
    GenerateVisitorStats();
} else {
    echo "Visitor Stats module DISABLED." . fs_filehandler::NewLine();
}
echo "END Visitor Stats" . fs_filehandler::NewLine();

function GenerateVisitorStats()
{
    global $zdbh;
    if (!is_executable(visitor_stats_helper::BINARY)) {
        echo "visitors is not installed (" . visitor_stats_helper::BINARY . "), skipping." . fs_filehandler::NewLine();
        return;
    }
    $sql = $zdbh->prepare("SELECT vh_name_vc, ac_user_vc FROM x_vhosts LEFT JOIN x_accounts ON x_vhosts.vh_acc_fk = x_accounts.ac_id_pk WHERE vh_deleted_ts IS NULL AND vh_type_in IN (1, 2)");
    $sql->execute();
    // The reports' folder: root's, the panel (group sentora) may read it (also repairs the old setup)
    foreach (visitor_stats_helper::SecureFolders() as $line) {
        echo "Reports folder: " . $line . fs_filehandler::NewLine();
    }
    $logdir = rtrim(ctrl_options::GetSystemOption('log_dir'), '/') . '/domains/';
    $rows = $sql->fetchAll();
    // The panel's own traffic, under the main administrator account
    $panelDomain = ctrl_statsdomains::PanelDomain();
    $panelOwner = ctrl_statsdomains::OwnerName();
    if ($panelDomain !== false && $panelOwner !== false) {
        $rows[] = array('ac_user_vc' => $panelOwner, 'vh_name_vc' => $panelDomain, 'sentora_logfile' => ctrl_statsdomains::PanelLog());
    }
    foreach ($rows as $row) {
        $user = (string) $row['ac_user_vc'];
        $domain = (string) $row['vh_name_vc'];
        $report = visitor_stats_helper::ReportFile($user, $domain);
        if ($report === false) {
            continue;
        }
        $log = isset($row['sentora_logfile']) ? $row['sentora_logfile'] : $logdir . $user . '/' . $domain . '-access.log';
        if (!is_file($log) || filesize($log) == 0) {
            echo "No traffic yet, skipping: " . $user . "/" . $domain . fs_filehandler::NewLine();
            continue;
        }
        if (!is_dir(dirname($report))) {
            @mkdir(dirname($report), 0750, true);
        }
        // Reports: root's, readable by the panel only (not by other accounts' sites)
        @chgrp(dirname($report), 'sentora');
        @chmod(dirname($report), 0750);
        // Write next to the report, then swap it in, so a viewer never sees half a page
        $tmp = $report . '.new';
        // visitors runs as the stats user and reads the log on standard input (root
        // opens it); its report comes back on standard output
        if (!ctrl_statsdomains::EnsureStatsUser()) {
            echo "Could not create the " . ctrl_statsdomains::STATS_USER . " user, skipping." . fs_filehandler::NewLine();
            return;
        }
        $cmd = ctrl_statsdomains::AsStatsUser(escapeshellarg(visitor_stats_helper::BINARY) . ' -A -m 30 -o html -')
            . ' < ' . escapeshellarg($log) . ' > ' . escapeshellarg($tmp) . ' 2> /dev/null';
        exec($cmd, $out, $rc);
        if ($rc === 0 && is_file($tmp) && filesize($tmp) > 0) {
            @chgrp($tmp, 'sentora');
            @chmod($tmp, 0640);
            rename($tmp, $report);
            echo "Generated stats for: " . $user . "/" . $domain . fs_filehandler::NewLine();
        } else {
            @unlink($tmp);
            echo "Failed: " . $user . "/" . $domain . fs_filehandler::NewLine();
        }
    }
}

?>
