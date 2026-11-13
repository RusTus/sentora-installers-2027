<?php

/**
 * QuickFig's hourly look at every WordPress site (the daemon, OnDaemonHour), as each account's own
 * user: a reminder (a panel notice to the site's owner, gone by itself when fixed) when debug mode
 * has been on for more than a day, or the site has shown "Briefly unavailable for scheduled
 * maintenance" for more than 10 minutes; and a debug log past 20 MB keeps its newest 5 MB. For a
 * Magento shop: its searches that found nothing are deleted once a week, with a reminder past 300,000.
 * Domains that point at the same files are looked at once (under the first name). And for every
 * website: who visited in the last 24 hours (its access log, counted per browser name).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class quickfig_watch
{
    /** Hours debug mode may stay on before the reminder. */
    const DEBUG_HOURS = 24;

    /** Minutes a maintenance notice may stay before the reminder. */
    const MAINT_MINUTES = 10;

    /**
     * Look at every site.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string[] What happened (for the daemon's log).
     */
    static function Hour()
    {
        global $zdbh;
        $log = array();
        $stateFile = quickfig_jobs::DIR . '/state.json';
        if (!is_dir(quickfig_jobs::DIR)) {
            @mkdir(quickfig_jobs::DIR, 0755, true);
        }
        $state = is_file($stateFile) && !is_link($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : array();
        $state = is_array($state) ? $state : array();
        $seen = array();
        $allSites = array();
        // a website under several names (domains pointing at the same files) is looked at once,
        // under its first name: the others' reminders go
        $done = array();
        $accounts = $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_enabled_in = 1 AND ac_deleted_ts IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($accounts as $uid) {
            $uid = (int) $uid;
            if (posix_getpwnam(sys_accountphp::UserName($uid)) === false) {
                continue;
            }
            $panelUser = (string) ctrl_users::GetUserDetail($uid)['username'];
            foreach (quickfig::Sites($uid) as $site) {
                // who visited (every site, also those sharing files: each has its own log)
                $allSites[(int) $site['id']] = true;
                self::Agents($panelUser, $site);
                $d = quickfig::Run($uid, $site, 'detect', array(), true);
                if (!empty($d['root'])) {
                    $where = $uid . ':' . $d['root'];
                    if (isset($done[$where])) {
                        self::Clear($site['id']);
                        continue;
                    }
                    $done[$where] = true;
                }
                if (isset($d['type']) && $d['type'] === 'magento') {
                    $seen[(string) $site['id']] = true;
                    self::Magento($uid, $site, $state, $log);
                    continue;
                }
                if (!isset($d['type']) || $d['type'] !== 'wordpress') {
                    self::Clear($site['id']);
                    continue;
                }
                $r = quickfig::Run($uid, $site, 'wp_read', array(), true);
                if (isset($r['error'])) {
                    continue;
                }
                $key = (string) $site['id'];
                $seen[$key] = true;
                // debug mode: since when it is on
                $debug = isset($r['defines']['WP_DEBUG']) && $r['defines']['WP_DEBUG']['value'] === true;
                if (!$debug) {
                    unset($state['debug'][$key]);
                    ctrl_notices::Withdraw('quickfig-debug-' . $key, 0);
                } else {
                    if (empty($state['debug'][$key])) {
                        $state['debug'][$key] = time();
                    }
                    if (time() - (int) $state['debug'][$key] >= self::DEBUG_HOURS * 3600) {
                        ctrl_notices::Send(array('scope' => 'user', 'targets' => array($uid), 'type' => 'warning', 'from' => 0, 'source' => 'quickfig',
                            'key' => 'quickfig-debug-' . $key, 'closable' => 'login', 'title' => 'Debug mode is still on',
                            'body' => 'WordPress\'s debug mode on ' . $site['name'] . ' has been on since ' . date('j M Y', (int) $state['debug'][$key])
                                . ': it slows the site down and its log keeps growing. Turn it off in Domain Management > QuickFig when you are done.'));
                    }
                }
                // stuck in maintenance
                // (WordPress itself ignores a .maintenance file 10 minutes after an update began: only
                // one it still shows counts, wp_read's maintenance_shown)
                $since = (int) $r['maintenance'];
                if ($since > 0 && !empty($r['maintenance_shown']) && time() - $since >= self::MAINT_MINUTES * 60) {
                    ctrl_notices::Send(array('scope' => 'user', 'targets' => array($uid), 'type' => 'outage', 'from' => 0, 'source' => 'quickfig',
                        'key' => 'quickfig-maint-' . $key, 'closable' => 'login', 'title' => $site['name'] . ' shows a maintenance notice',
                        'body' => $site['name'] . ' has shown "Briefly unavailable for scheduled maintenance" since ' . date('j M Y, H:i', $since)
                            . ': the maintenance file in its folder keeps it there. Domain Management > QuickFig removes the notice.'));
                } else {
                    ctrl_notices::Withdraw('quickfig-maint-' . $key, 0);
                }
                // a debug log past 20 MB
                if ((int) $r['debuglog'] > 20971520) {
                    $t = quickfig::Run($uid, $site, 'wp_trimlog', array(), true);
                    if (!empty($t['trimmed'])) {
                        $log[] = 'QuickFig: the debug log of ' . $site['name'] . ' was ' . round((int) $t['was'] / 1048576) . ' MB: its newest 5 MB were kept';
                    }
                }
            }
        }
        foreach (array('debug', 'mage') as $part) {
            foreach (array_keys(isset($state[$part]) ? $state[$part] : array()) as $k) {
                if (!isset($seen[$k])) {
                    unset($state[$part][$k]);
                }
            }
        }
        // the counts of websites that are gone
        foreach ((array) glob(self::AGENT_DIR . '/*.json') as $f) {
            if (!isset($allSites[(int) basename($f, '.json')])) {
                @unlink($f);
            }
        }
        @file_put_contents($stateFile . '.new', json_encode($state));
        @chmod($stateFile . '.new', 0600);
        @rename($stateFile . '.new', $stateFile);
        return $log;
    }

    /** Where each website's visitor counts are kept (root's; the panel reads them). */
    const AGENT_DIR = '/var/lib/sentora/quickfig/agents';

    /** At most this much of a log is read in one hour (a busy site's newest part). */
    const AGENT_MAX_READ = 134217728;

    /**
     * Who visited a website: the new lines of its access log, counted per browser name
     * (quickfig::Agent()) and hour, the last 24 hours kept in AGENT_DIR/<site>.json for the
     * Crawlers card. Only what is new since the last hour is read.
     *
     * @param string $panelUser The account's panel name (its logs folder).
     * @param array $site
     * @return void
     */
    static function Agents($panelUser, array $site)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $panelUser) || !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', (string) $site['name'])) {
            return;
        }
        $dir = self::AGENT_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (!is_dir($dir) || is_link($dir) || is_link(dirname($dir))) {
            return;
        }
        @chgrp($dir, 'sentora');
        @chmod($dir, 0750);
        $log = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/domains/' . $panelUser . '/' . $site['name'] . '-access.log';
        $out = $dir . '/' . (int) $site['id'] . '.json';
        $d = is_file($out) && !is_link($out) ? json_decode((string) file_get_contents($out), true) : null;
        $d = is_array($d) ? $d : array('hours' => array(), 'kinds' => array(), 'pos' => array(0, 0));
        $cut = date('YmdH', time() - 23 * 3600);
        foreach (array_keys($d['hours']) as $h) {
            if ((string) $h < $cut) {
                unset($d['hours'][$h]);
            }
        }
        $st = is_file($log) && !is_link($log) ? @stat($log) : false;
        if ($st !== false) {
            $first = (int) $d['pos'][0] !== (int) $st['ino'] || (int) $d['pos'][1] > (int) $st['size'];
            $pos = $first ? max(0, (int) $st['size'] - 16777216) : (int) $d['pos'][1];
            $pos = max($pos, (int) $st['size'] - self::AGENT_MAX_READ);
            $fh = @fopen($log, 'rb');
            if ($fh !== false) {
                fseek($fh, $pos);
                if ($pos > 0 && ($first || (int) $st['size'] - (int) $d['pos'][1] > self::AGENT_MAX_READ)) {
                    fgets($fh);  // (started inside a line)
                }
                $mon = array('Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12');
                $seen = array();
                $done = $pos;
                while (($line = fgets($fh, 16384)) !== false) {
                    if (substr($line, -1) !== "\n") {
                        break;  // (the line being written now: next hour)
                    }
                    $done += strlen($line);
                    if (!preg_match('~\[(\d{2})/(\w{3})/(\d{4}):(\d{2}):~', $line, $t) || !isset($mon[$t[2]])
                        || !preg_match('/"([^"]*)"\s*$/', $line, $u)) {
                        continue;
                    }
                    $hour = $t[3] . $mon[$t[2]] . $t[1] . $t[4];
                    if ($hour < $cut) {
                        continue;
                    }
                    if (!isset($seen[$u[1]])) {
                        if (count($seen) > 20000) {
                            $seen = array();
                        }
                        $seen[$u[1]] = quickfig::Agent($u[1]);
                    }
                    list($label, $kind, $token) = $seen[$u[1]];
                    $d['hours'][$hour][$label] = (isset($d['hours'][$hour][$label]) ? $d['hours'][$hour][$label] : 0) + 1;
                    $d['kinds'][$label] = array($kind, $token);
                }
                fclose($fh);
                $d['pos'] = array((int) $st['ino'], $done);
            }
        }
        // each hour: its 300 busiest names (the rest of a flood of random names together)
        $used = array();
        foreach ($d['hours'] as $h => $names) {
            arsort($names);
            if (count($names) > 300) {
                $rest = array_sum(array_slice($names, 300));
                $names = array_slice($names, 0, 300, true);
                $names['(others)'] = (isset($names['(others)']) ? $names['(others)'] : 0) + $rest;
                $d['kinds']['(others)'] = array('other', '');
            }
            $d['hours'][$h] = $names;
            $used += $names;
        }
        $d['kinds'] = array_intersect_key($d['kinds'], $used);
        $d['time'] = time();
        if (@file_put_contents($out . '.new', json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @chgrp($out . '.new', 'sentora');
            @chmod($out . '.new', 0640);
            @rename($out . '.new', $out);
        }
    }

    /**
     * The last 24 hours of a website's visitors (for the page): label => array(requests, kind, name to block).
     *
     * @param int $vh
     * @return array|null Null when nothing was counted yet.
     */
    static function Visitors($vh)
    {
        $f = self::AGENT_DIR . '/' . (int) $vh . '.json';
        $d = is_file($f) && !is_link($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (!is_array($d) || !isset($d['hours'])) {
            return null;
        }
        $cut = date('YmdH', time() - 23 * 3600);
        $sum = array();
        foreach ($d['hours'] as $h => $names) {
            if ((string) $h < $cut) {
                continue;
            }
            foreach ((array) $names as $label => $n) {
                $sum[$label] = (isset($sum[$label]) ? $sum[$label] : 0) + (int) $n;
            }
        }
        arsort($sum);
        $out = array();
        foreach ($sum as $label => $n) {
            $k = isset($d['kinds'][$label]) ? $d['kinds'][$label] : array('other', '');
            $out[(string) $label] = array($n, (string) $k[0], (string) $k[1]);
        }
        return array('time' => (int) $d['time'], 'agents' => $out);
    }

    /** Searches that found nothing, past which the shop's owner is reminded. */
    const SEARCH_JUNK = 300000;

    /**
     * A Magento shop: once a week, its searches that found nothing and are more than a week old are
     * deleted (bots fill the table by the million and the shop counts it on its pages); again the
     * next hour while more are left. Past SEARCH_JUNK, its owner is reminded.
     *
     * @param int $uid
     * @param array $site
     * @param array $state
     * @param string[] $log
     * @return void
     */
    static function Magento($uid, array $site, array &$state, array &$log)
    {
        $key = (string) $site['id'];
        $m = isset($state['mage'][$key]) ? $state['mage'][$key] : array();
        if (empty($m['clean']) || time() - (int) $m['clean'] >= 7 * 86400 - 3600 || !empty($m['more'])) {
            $r = quickfig::Run($uid, $site, 'mage_search_clean', array('days' => 7), true);
            if (isset($r['deleted'])) {
                $m['clean'] = time();
                $m['more'] = !empty($r['more']);
                if ((int) $r['deleted'] > 0) {
                    $log[] = 'QuickFig: ' . $site['name'] . ': ' . (int) $r['deleted'] . ' searches that found nothing deleted' . ($m['more'] ? ' (more next hour)' : '');
                }
            }
        }
        $state['mage'][$key] = $m;
        $q = quickfig::Run($uid, $site, 'mage_search_read', array(), true);
        if (isset($q['empty']) && (int) $q['empty'] > self::SEARCH_JUNK) {
            ctrl_notices::Send(array('scope' => 'user', 'targets' => array($uid), 'type' => 'warning', 'from' => 0, 'source' => 'quickfig',
                'key' => 'quickfig-search-' . $key, 'closable' => 'login', 'title' => $site['name'] . ': bots are filling the shop\'s search terms',
                'body' => $site['name'] . ' has ' . number_format((int) $q['empty']) . ' saved searches that found nothing, mostly bots searching the shop; the shop counts them on its pages, which makes its database slow. Domain Management > QuickFig deletes them (it also does so once a week); a robots.txt that keeps crawlers out of /catalogsearch/ stops the well-behaved ones.'));
        } elseif (isset($q['empty'])) {
            ctrl_notices::Withdraw('quickfig-search-' . $key, 0);
        }
    }

    /**
     * A site that is no WordPress (any more): its reminders go.
     *
     * @param int $vh
     * @return void
     */
    static function Clear($vh)
    {
        ctrl_notices::Withdraw('quickfig-debug-' . (int) $vh, 0);
        ctrl_notices::Withdraw('quickfig-maint-' . (int) $vh, 0);
        ctrl_notices::Withdraw('quickfig-search-' . (int) $vh, 0);
    }
}
