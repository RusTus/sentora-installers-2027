<?php

/**
 * Docker Hosting, every daemon run: containers of accounts switched off are stopped (and started
 * again when the account is switched on), containers of deleted accounts are deleted, a container
 * using more disk than its limit is stopped, and old nonces and jobs are cleared.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
class dh_daemon
{
    /**
     * One run.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, string> Lines for the daemon's log.
     */
    static function Run()
    {
        global $zdbh;
        dh::Ensure();
        $lines = array();
        $zdbh->prepare("DELETE FROM x_dh_nonces WHERE nn_time_in < :t")->execute(array(':t' => time() - 2 * dh_proto::SKEW - 60));
        $zdbh->prepare("DELETE FROM x_dh_jobs WHERE jb_state_vc IN ('done','failed') AND jb_done_in < :t")->execute(array(':t' => time() - 30 * 86400));

        $rows = $zdbh->query("SELECT c.*, a.ac_enabled_in, a.ac_deleted_ts, a.ac_id_pk FROM x_dh_containers c LEFT JOIN x_accounts a ON a.ac_id_pk = c.dc_acc_fk
            WHERE c.dc_deleted_in IS NULL AND c.dc_state_vc <> 'deleting'")->fetchAll(PDO::FETCH_ASSOC);
        $hold = $zdbh->prepare("UPDATE x_dh_containers SET dc_held_vc = :h, dc_want_vc = 'stopped', dc_state_vc = 'stopping', dc_note_vc = :n WHERE dc_id_pk = :c");
        foreach ($rows as $c) {
            $cid = (int) $c['dc_id_pk'];
            $acc = (int) $c['dc_acc_fk'];
            if ($c['ac_id_pk'] === null || $c['ac_deleted_ts'] !== null) {
                dh::Act($acc, 0, $cid, 'delete');
                $lines[] = 'Docker Hosting: container ' . $cid . ' of a deleted account is being deleted';
                continue;
            }
            $running = (string) $c['dc_want_vc'] === 'running';
            if ((int) $c['ac_enabled_in'] !== 1 && $running) {
                $hold->execute(array(':h' => 'account', ':n' => 'Stopped: the account is switched off.', ':c' => $cid));
                dh::Queue((int) $c['dc_node_fk'], $cid, 'stop', dh::Who($c));
                dh::Log('held', 'Stopped ' . $c['dc_domain_vc'] . ': the account is switched off', $acc, 0, $cid, (int) $c['dc_node_fk']);
                $lines[] = 'Docker Hosting: container ' . $cid . ' stopped (account switched off)';
            } elseif ((int) $c['ac_enabled_in'] === 1 && (string) $c['dc_held_vc'] === 'account') {
                $zdbh->prepare("UPDATE x_dh_containers SET dc_held_vc = '', dc_want_vc = 'running', dc_state_vc = 'starting', dc_note_vc = '' WHERE dc_id_pk = :c")->execute(array(':c' => $cid));
                dh::Queue((int) $c['dc_node_fk'], $cid, 'start', dh::Who($c));
                dh::Log('unheld', 'Started ' . $c['dc_domain_vc'] . ' again: the account is switched on', $acc, 0, $cid, (int) $c['dc_node_fk']);
                $lines[] = 'Docker Hosting: container ' . $cid . ' started again (account switched on)';
            } elseif ($running && (int) $c['dc_used_in'] > (int) $c['dc_disk_in']) {
                $hold->execute(array(':h' => 'disk', ':n' => sprintf('Stopped: it uses %d MB of disk, its limit is %d MB.', (int) $c['dc_used_in'], (int) $c['dc_disk_in']), ':c' => $cid));
                dh::Queue((int) $c['dc_node_fk'], $cid, 'stop', dh::Who($c));
                dh::Log('held', sprintf('Stopped %s: %d MB of disk used, limit %d MB', $c['dc_domain_vc'], (int) $c['dc_used_in'], (int) $c['dc_disk_in']), $acc, 0, $cid, (int) $c['dc_node_fk']);
                $lines[] = 'Docker Hosting: container ' . $cid . ' stopped (over its disk limit)';
            }
        }
        // grants of deleted accounts
        $zdbh->exec("DELETE FROM x_dh_grants WHERE gr_acc_fk NOT IN (SELECT ac_id_pk FROM x_accounts WHERE ac_deleted_ts IS NULL)");
        $hour = getenv('DH_TEST_HOUR') !== false ? (int) getenv('DH_TEST_HOUR') : (int) date('G');
        // apps that update by themselves: at night, when a newer version is out
        if ($hour >= 2 && $hour < 5) {
            foreach ($zdbh->query("SELECT dc_id_pk, dc_acc_fk, dc_domain_vc FROM x_dh_containers WHERE dc_deleted_in IS NULL AND dc_autoupd_in = 1 AND dc_update_in = 1
                AND dc_state_vc = 'running'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (dh::Act((int) $c['dc_acc_fk'], 0, (int) $c['dc_id_pk'], 'update')) {
                    $lines[] = 'Docker Hosting: updating ' . $c['dc_domain_vc'] . ' (automatic updates)';
                }
            }
        }
        self::LatestAgent($hour);
        // (125) app backups: given-up transfers, backups of deleted apps, and the nightly round
        dh_backup::Daemon($hour);
        $latest = (string) dh::Get('latest_agent', '');
        foreach (dh::Nodes() as $id => $n) {
            if ((string) $n['nd_signpk_vc'] === '') {
                continue;
            }
            $i = dh::Info($n);
            // node updates by themselves, at night, only when switched on and a newer agent is out
            if ((int) $n['nd_autoagent_in'] === 1 && $latest !== '' && (string) $i['agent'] !== '' && version_compare((string) $i['agent'], $latest, '<')
                && $hour === 2 && dh::Online($n) && (int) $n['nd_removing_in'] === 0 && (int) dh::Get('autoagent_' . $id, '0') < time() - 20 * 3600) {
                dh::Set('autoagent_' . $id, (string) time());
                if (dh::NodeAct($id, 'node-update', 0)) {
                    dh::Log('node-update', 'Node "' . $n['nd_name_vc'] . '": updating to agent ' . $latest . ' (automatic)', 0, 0, 0, $id);
                    $lines[] = 'Docker Hosting: node ' . $n['nd_name_vc'] . ' updates to agent ' . $latest;
                }
            }
            // security updates by themselves, at night
            if ((int) $n['nd_autosec_in'] === 1 && (int) $i['sys_security'] > 0 && $hour >= 3 && $hour < 5 && dh::Online($n)
                && (int) dh::Get('autosec_' . $id, '0') < time() - 20 * 3600) {
                dh::Set('autosec_' . $id, (string) time());
                dh::Queue($id, 0, 'sys-update', array('security' => true));
                dh::Log('node-sys-security', 'Node "' . $n['nd_name_vc'] . '": installing security updates (automatic)', 0, 0, 0, $id);
                $lines[] = 'Docker Hosting: node ' . $n['nd_name_vc'] . ' installs its security updates';
            }
            // a node asked to remove itself that went quiet: forgotten after 10 minutes
            if ((int) $n['nd_removing_in'] === 1 && (int) $n['nd_seen_in'] < time() - 600) {
                dh::Forget($id);
                dh::Log('node-removed', 'Node "' . $n['nd_name_vc'] . '" forgotten (it went quiet while removing itself)', 0, 0, 0, $id);
            }
        }
        $lines = array_merge($lines, self::Warn());
        $lines = array_merge($lines, self::Notices());
        $lines = array_merge($lines, self::Widget());
        return $lines;
    }

    /**
     * The newest node agent's version, looked up once a night (and on the first run after installing),
     * so the panel can say which nodes are behind. GitHub is asked whether the file changed (ETag).
     *
     * @param int $hour the hour of the day now
     * @return void
     */
    static function LatestAgent($hour)
    {
        if (!function_exists('curl_init')) {
            return;
        }
        $last = (int) dh::Get('latest_agent_at', '0');
        $known = dh::Get('latest_agent', '') !== '';
        // once more right after the module itself was updated (a new module usually comes with a new agent)
        $sig = (string) @sha1_file(__FILE__);
        if ($sig !== '' && dh::Get('latest_agent_sig', '') !== $sig) {
            dh::Set('latest_agent_sig', $sig);
            $known = false;
            $last = 0;
        }
        if ($known && ($last > time() - 20 * 3600 || $hour < 1 || $hour >= 5)) {
            return;
        }
        if (!$known && $last > time() - 3600) {
            return;
        }
        dh::Set('latest_agent_at', (string) time());
        $etag = (string) dh::Get('latest_agent_etag', '');
        $got = '';
        $c = curl_init(dirname(dh::NODE_INSTALLER) . '/sentora-docker-node-agent.php');
        curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ($etag !== '' && dh::Get('latest_agent', '') !== '') ? array('If-None-Match: ' . $etag) : array(),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$got) {
                if (preg_match('/^etag:\s*((?:W\/)?"?[A-Za-z0-9\/+=_.:-]{1,200}"?)\s*$/i', $line, $m)) {
                    $got = $m[1];
                }
                return strlen($line);
            }));
        $body = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if ($code === 200 && is_string($body) && preg_match("/^    const VERSION = '(\\d{1,6})';/m", $body, $m)) {
            dh::Set('latest_agent', $m[1]);
            dh::Set('latest_agent_etag', $got);
        }
    }

    /**
     * Put the add-on's dashboard widget (assets/widget) where the panel finds uploaded widgets, and
     * replace it when the add-on brings a new one. Only on a panel that has dashboard widgets.
     *
     * @return array lines for the daemon's log
     */
    static function Widget()
    {
        $root = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/';
        $src = dirname(__FILE__) . '/../assets/widget/';
        if (!is_file($root . 'modules/dashboard/code/widgets.php') || !is_file($src . 'widget.xml')) {
            return array();
        }
        $base = $root . 'etc/dashboard-widgets/';
        $dst = $base . 'docker_hosting';
        $files = array('widget.xml', 'widget.php', 'widget.css');
        $same = is_dir($dst);
        foreach ($files as $f) {
            $same = $same && is_file($dst . '/' . $f) && sha1_file($dst . '/' . $f) === sha1_file($src . $f);
        }
        if ($same) {
            return array();
        }
        if (!is_dir($base) && !@mkdir($base, 0755, true)) {
            return array('Docker Hosting: the dashboard widget could not be put in ' . $base);
        }
        // written into a new folder, then swapped in (as Widget Admin does)
        $new = $base . '.docker_hosting.new';
        if (is_dir($new)) {
            foreach ((array) glob($new . '/*') as $f) {
                @unlink($f);
            }
            rmdir($new);
        }
        if (!@mkdir($new, 0755)) {
            return array('Docker Hosting: the dashboard widget could not be written');
        }
        foreach ($files as $f) {
            copy($src . $f, $new . '/' . $f);
            chmod($new . '/' . $f, 0644);
        }
        if (is_dir($dst)) {
            foreach ((array) glob($dst . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($dst);
        }
        rename($new, $dst);
        return array('Docker Hosting: the dashboard widget was put in place (' . $dst . ')');
    }

    /**
     * What needs a look on each node, as a panel notice to the administrators (one per node; it goes
     * away by itself once everything on that node is fine, or the node is removed). Only on a panel
     * that has notices (ctrl_notices, 2027.60 and newer).
     *
     * @return array lines for the daemon's log
     */
    static function Notices()
    {
        if (!class_exists('ctrl_notices') || !method_exists('ctrl_notices', 'Send') || !method_exists('ctrl_notices', 'Withdraw')) {
            return array();
        }
        $had = array_filter(array_map('intval', explode(',', (string) dh::Get('notice_nodes'))));
        $now = array();
        $out = array();
        foreach (dh::Nodes() as $id => $n) {
            $key = 'docker-hosting-node-' . (int) $id;
            $words = dh::Words($n, false);
            // (134) only what is urgent becomes a notice on every page: a node offline or gone, almost out of
            // disk, apps lost or down; the rest (updates, a restart, found apps) is in the Docker Hosting widget
            // and on the page
            foreach (array_keys($words) as $k) {
                if (!in_array($k, array('offline', 'gone', 'disk', 'missing'), true) && strpos($k, 'app-') !== 0) {
                    unset($words[$k]);
                }
            }
            if (!$words) {
                if (in_array((int) $id, $had, true) && ctrl_notices::Withdraw($key, 0) > 0) {
                    $out[] = 'Docker Hosting: node "' . $n['nd_name_vc'] . '" is fine again, its notice was taken away';
                }
                continue;
            }
            $ok = ctrl_notices::Send(array('scope' => 'groups', 'targets' => array(1), 'type' => 'warning', 'key' => $key, 'source' => 'docker_hosting', 'closable' => 'login',
                'title' => mb_substr('Docker Hosting: node "' . $n['nd_name_vc'] . '" needs a look', 0, 120, 'UTF-8'),
                'body' => "- " . implode("\n- ", array_map('ucfirst', $words)) . "\n\n[Fix it in Docker Hosting > Nodes and apps](./?module=docker_hosting&tab=nodes)"));
            if ($ok) {
                $now[] = (int) $id;
            }
        }
        // a node that was removed takes its notice with it
        foreach (array_diff($had, $now) as $gone) {
            if (!isset(dh::Nodes()[$gone])) {
                ctrl_notices::Withdraw('docker-hosting-node-' . $gone, 0);
            }
        }
        if ($now !== $had) {
            dh::Set('notice_nodes', implode(',', $now));
        }
        return $out;
    }

    /**
     * Mail the administrators what is new among the nodes' warnings (each again after a day, while it lasts).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array<int, string>
     */
    static function Warn()
    {
        global $zdbh;
        $new = array();
        foreach (dh::Nodes() as $id => $n) {
            $w = dh::Warnings($n);
            $sent = json_decode((string) $n['nd_warned_tx'], true);
            $sent = is_array($sent) ? $sent : array();
            $keep = array();
            foreach ($w as $k => $text) {
                if (!isset($sent[$k]) || (int) $sent[$k] < time() - 86400) {
                    $new[] = $text;
                    $keep[$k] = time();
                } else {
                    $keep[$k] = (int) $sent[$k];
                }
            }
            if ($keep !== $sent) {
                $zdbh->prepare("UPDATE x_dh_nodes SET nd_warned_tx = :w WHERE nd_id_pk = :n")->execute(array(':w' => json_encode($keep), ':n' => $id));
            }
        }
        if (!$new || !class_exists('sys_email')) {
            return array();
        }
        $to = $zdbh->query("SELECT ac_email_vc FROM x_accounts WHERE ac_group_fk = 1 AND ac_deleted_ts IS NULL AND ac_enabled_in = 1 AND ac_email_vc <> ''")->fetchAll(PDO::FETCH_COLUMN);
        if (!$to) {
            return array();
        }
        $m = new sys_email();
        $m->Subject = 'Docker Hosting: ' . (count($new) === 1 ? 'a node needs a look' : count($new) . ' things on the nodes need a look');
        $m->Body = "Hello,\n\nDocker Hosting noticed:\n\n- " . implode("\n- ", $new) . "\n\nSee the panel: Advanced > Docker Hosting > Nodes and apps.\n";
        foreach ($to as $a) {
            $m->AddAddress($a);
        }
        $m->SendEmail();
        return array('Docker Hosting: mailed the administrators ' . count($new) . ' warning(s)');
    }
}
