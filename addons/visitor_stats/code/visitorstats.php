<?php

/**
 * Visitor Stats: shared helpers for the page, the report viewer, the hook and the install and upgrade steps.
 * Reports live outside the web root, so they are only reachable through
 * report.php, which checks that the domain belongs to the logged-in user.
 */
class visitor_stats_helper
{

    const BINARY = '/usr/local/bin/visitors';
    const STATS_DIR = '/var/sentora/stats/visitors/';

    static function ValidName($name)
    {
        return is_string($name) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1;
    }

    static function ValidDomain($domain)
    {
        return is_string($domain) && strlen($domain) <= 253
            && preg_match('/^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$/', $domain) === 1;
    }

    /**
     * Root only (install, upgrade and the hourly job): set up the reports' folder so that root writes
     * the reports and the panel (group sentora) may read them. The folder used to be made root:root
     * 0750, so the panel saw no report; this also repairs that. Links are left alone.
     *
     * Written by RusTus, 2026-11-08
     *
     * @return string[] What was changed (empty when everything was already right).
     */
    static function SecureFolders()
    {
        $did = array();
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return $did;
        }
        $base = rtrim(self::STATS_DIR, '/');
        $parent = dirname($base);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
            $did[] = 'made ' . $parent;
        } elseif (!is_link($parent) && (fileperms($parent) & 0011) !== 0011) {
            // the stats modules' shared folder: the panel must be able to pass through it
            @chmod($parent, (fileperms($parent) & 07777) | 0011);
            $did[] = $parent . ' now lets the panel through';
        }
        if (!is_dir($base)) {
            @mkdir($base, 0750);
            $did[] = 'made ' . $base;
        }
        if (is_dir($base) && !is_link($base)) {
            $st = stat($base);
            $grp = function_exists('posix_getgrnam') ? posix_getgrnam('sentora') : false;
            if ($grp !== false && ($st['uid'] !== 0 || $st['gid'] !== (int) $grp['gid'] || ($st['mode'] & 07777) !== 0750)) {
                @chown($base, 0);
                @chgrp($base, 'sentora');
                @chmod($base, 0750);
                $did[] = $base . ' is now root:sentora 0750';
            }
        }
        return $did;
    }

    static function ReportFile($username, $domain)
    {
        if (!self::ValidName($username) || !self::ValidDomain($domain)) {
            return false;
        }
        return self::STATS_DIR . $username . '/' . $domain . '.html';
    }

    /** domains and subdomains of one account */
    static function Domains($uid)
    {
        global $zdbh;
        $sql = $zdbh->prepare("SELECT vh_id_pk, vh_name_vc FROM x_vhosts WHERE vh_acc_fk = :uid AND vh_deleted_ts IS NULL ORDER BY vh_name_vc");
        $sql->bindParam(':uid', $uid);
        $sql->execute();
        $res = array();
        // The panel's own domain, for the main administrator account
        $panel = ctrl_statsdomains::PanelRowFor($uid);
        if ($panel) {
            $res[] = array('vh_id_pk' => 0, 'vh_name_vc' => $panel['vh_name_vc']);
        }
        while ($row = $sql->fetch()) {
            $res[] = array('vh_id_pk' => $row['vh_id_pk'], 'vh_name_vc' => $row['vh_name_vc']);
        }
        return $res;
    }

    static function OwnsDomain($uid, $domain)
    {
        foreach (self::Domains($uid) as $row) {
            if (strcasecmp($row['vh_name_vc'], $domain) === 0) {
                return true;
            }
        }
        return false;
    }

}

?>
