<?php

/**
 * Docker Hosting's page. For an account with a share: its containers (start one from the catalog,
 * start, stop, restart, logs, limits, open to the public, delete). For an account with clients: the
 * shares it gives them. For an administrator: the nodes (add one, with its install command), the
 * apps offered, and the panel's address for the nodes. Everything an account does is checked
 * against its own share (dh), never against what a browser sends.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
require_once dirname(__FILE__) . '/dh.php';
require_once dirname(__FILE__) . '/daemon.php';

class module_controller extends ctrl_module
{
    /** @var array<int, array{0:string, 1:string}> Messages: [type ('ok'|'error'), text]. */
    static $msg = array();

    /** @var string A node's install command, shown once after it was made. */
    static $command = '';

    /**
     * Escape for HTML.
     *
     * @param mixed $s
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate, put words in for %s, escape.
     *
     * @param string $s
     * @param array $args
     * @return string
     */
    static function t($s, array $args = array())
    {
        $text = ui_language::translate($s);
        foreach ($args as $a) {
            $text = preg_replace('/%s/', str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $a), $text, 1);
        }
        return self::h($text);
    }

    /**
     * A message for the page.
     *
     * @param string $type ok or error
     * @param string $text English.
     * @param array $args
     * @return void
     */
    static function Say($type, $text, array $args = array())
    {
        $t = ui_language::translate($text);
        foreach ($args as $a) {
            $t = preg_replace('/%s/', str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $a), $t, 1);
        }
        self::$msg[] = array($type, $t);
    }

    /**
     * Say how it went: ok, or dh::$why.
     *
     * @param bool $ok
     * @param string $text
     * @param array $args
     * @return void
     */
    static function Done($ok, $text, array $args = array())
    {
        if ($ok) {
            self::Say('ok', $text, $args);
        } else {
            self::Say('error', dh::$why);
        }
    }

    /**
     * The logged-in account's ID.
     *
     * @return int
     */
    static function Me()
    {
        $u = ctrl_users::GetUserDetail();
        return (int) $u['userid'];
    }

    /**
     * The node box of Start an app, for administrators only (others always get Automatic): each online
     * node with what it has free; offline ones can not be chosen.
     *
     * @return string
     */
    static function NodeChoice()
    {
        if (!self::Admin()) {
            return '';
        }
        $opts = '<option value="0">' . self::t('Automatic') . '</option>';
        foreach (dh::Nodes() as $id => $n) {
            if ((string) $n['nd_signpk_vc'] === '') {
                continue;
            }
            $on = dh::Online($n);
            $r = dh::Room($n);
            $opts .= '<option value="' . (int) $id . '"' . ($on ? '' : ' disabled') . '>' . self::h($n['nd_name_vc']) . ' ('
                . ($on ? self::h(sprintf(self::t('free: %d MB memory, %s GB disk'), max(0, $r['mem']), round(max(0, $r['disk']) / 1024, 1)))
                    . ((int) $n['nd_accepting_in'] === 1 ? '' : ', ' . self::t('takes no new apps by itself'))
                    : self::t('offline')) . ')</option>';
        }
        return '<label class="dh-field"><span>' . self::t('Node (administrators only)') . '</span><select class="form-control" name="inNode">' . $opts . '</select>'
            . '<small>' . self::t('The server the app runs on. Automatic picks the one with the most memory free. Your customers never see this: their apps always go where Automatic puts them.') . '</small></label>';
    }

    /**
     * Whether the logged-in account is an administrator.
     *
     * @return bool
     */
    static function Admin()
    {
        return dh::IsAdmin(dh::Account(self::Me()));
    }

    /**
     * A posted field.
     *
     * @param string $n
     * @return string
     */
    static function Post($n)
    {
        return isset($_POST[$n]) && is_string($_POST[$n]) ? trim($_POST[$n]) : '';
    }

    /**
     * A posted whole number, or false.
     *
     * @param string $n
     * @return int|false
     */
    static function Int($n)
    {
        $v = self::Post($n);
        return preg_match('/^\d{1,9}$/', $v) ? (int) $v : false;
    }

    /**
     * Posted GB (whole or with decimals) as MB, or false.
     *
     * @param string $n
     * @return int|false
     */
    static function Gb($n)
    {
        $v = str_replace(',', '.', self::Post($n));
        return preg_match('/^\d{1,5}(\.\d{1,2})?$/', $v) ? (int) round((float) $v * 1024) : false;
    }

    // ----- containers -----

    /**
     * Start a new container from the catalog.
     *
     * @return void
     */
    static function doDeploy()
    {
        runtime_csfr::Protect();
        $cpu = dh::ParseCpu(self::Post('inCpu'));
        $mem = self::Int('inMem');
        $disk = self::Gb('inDisk');
        if ($cpu === false || $mem === false || $disk === false) {
            self::Say('error', 'Give the CPU (like 0.5), memory (MB) and disk (GB) as numbers.');
            return;
        }
        $id = dh::Deploy(self::Me(), self::Me(), array('app' => self::Post('inApp'), 'label' => self::Post('inLabel'), 'cpu' => $cpu, 'mem' => $mem, 'disk' => $disk,
            'vhost' => (int) self::Int('inDomain'), 'name' => self::Post('inName'), 'dns' => self::Post('inDns') === '1', 'node' => (int) self::Int('inNode')));
        if ($id) {
            $c = dh::Container($id);
            self::Say('ok', 'Starting %s at %s: the node downloads the app first, which can take a few minutes.', array(dh_catalog::App((string) $c['dc_app_vc'])['name'], $c['dc_domain_vc']));
        } else {
            self::Say('error', dh::$why);
        }
    }

    /**
     * Back an app up now (its owner or an administrator).
     *
     * @return void
     */
    static function doBackup()
    {
        runtime_csfr::Protect();
        self::Done(dh_backup::Start((int) self::Int('inId'), self::Me()), 'Backing it up: the app stops for a moment while its data is copied, then runs again. The copy goes to the panel, and from there with the off-server backup to the backup node.');
    }

    /**
     * Put an app's newest backup back.
     *
     * @return void
     */
    static function doRestore()
    {
        runtime_csfr::Protect();
        self::Done(dh_backup::Restore((int) self::Int('inId'), self::Me()), 'Putting its backup back: the app stops while its data is replaced. Its data from before is kept aside until the restore went through, and put back if it fails.');
    }

    /**
     * Nightly backups of an app on or off.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doBackupAuto()
    {
        global $zdbh;
        runtime_csfr::Protect();
        $id = (int) self::Int('inId');
        $c = dh::Container($id);
        if (!dh_backup::Mine($c, self::Me())) {
            self::Say('error', 'No such app.');
            return;
        }
        $on = self::Post('inOn') === '1' ? 1 : 0;
        $zdbh->prepare("UPDATE x_dh_containers SET dc_backup_in = :o WHERE dc_id_pk = :c")->execute(array(':o' => $on, ':c' => $id));
        self::Say('ok', $on ? 'It is backed up every night now.' : 'It is no longer backed up at night.');
    }

    /**
     * Move an app to another node, with its data (administrators).
     *
     * @return void
     */
    static function doMoveApp()
    {
        runtime_csfr::Protect();
        self::Done(dh_backup::Move((int) self::Int('inId'), (int) self::Int('inTo'), self::Me()), 'Moving it: the app stops, its data is carried over through the panel, and it starts on the other node with the same address. The old copy is removed only once the new one runs; if anything fails, it runs again where it was.');
    }

    /**
     * Start, stop, restart, logs, open to the public, close again, delete.
     *
     * @return void
     */
    static function doAct()
    {
        runtime_csfr::Protect();
        $what = self::Post('inWhat');
        $id = (int) self::Int('inId');
        $ok = dh::Act(self::Me(), self::Me(), $id, $what);
        $texts = array('start' => 'Starting it.', 'stop' => 'Stopping it.', 'restart' => 'Restarting it.', 'logs' => 'Asking the node for its logs: they show below in a moment.',
            'public' => 'Opening it to the public: the setup password is no longer asked.', 'private' => 'Closing it again: it asks for a new setup password.',
            'delete' => 'Deleting it, with its data.', 'update' => 'Updating it: the node downloads the newest version and swaps it in (a few minutes). If it does not start, the old one comes back by itself.',
            'auto-on' => 'It now updates by itself at night (02:00-05:00 on the panel\'s clock) when a newer version is out. The node looks once a day.', 'auto-off' => 'It no longer updates by itself.',
            'remake' => 'Making it again on its node (a fresh app: its old data is gone with the node\'s old setup).');
        self::Done($ok, isset($texts[$what]) ? $texts[$what] : 'Done.');
        if ($ok && $what === 'logs') {
            $_GET['logs'] = (string) $id;
        }
    }

    /**
     * A container's settings: its size, night updates, open to everyone (each changed only when it differs).
     *
     * @return void
     */
    static function doCtSettings()
    {
        runtime_csfr::Protect();
        $id = (int) self::Int('inId');
        $c = dh::Container($id);
        if (!$c || (int) $c['dc_acc_fk'] !== self::Me()) {
            self::Say('error', 'No such container.');
            return;
        }
        $cpu = dh::ParseCpu(self::Post('inCpu'));
        $mem = self::Int('inMem');
        $disk = self::Gb('inDisk');
        if ($cpu === false || $mem === false || $disk === false) {
            self::Say('error', 'Give the CPU (like 0.5), memory (MB) and disk (GB) as numbers.');
            $_GET['settings'] = (string) $id;
            return;
        }
        $done = array();
        if ($cpu !== (int) $c['dc_cpu_in'] || $mem !== (int) $c['dc_mem_in'] || $disk !== (int) $c['dc_disk_in']) {
            if (!dh::Limits(self::Me(), self::Me(), $id, $cpu, $mem, $disk)) {
                self::Say('error', dh::$why);
                $_GET['settings'] = (string) $id;
                return;
            }
            $done[] = 'the new size is on its way to the node';
        }
        $public = self::Post('inPublic') === '1';
        if (isset($_POST['inPublic']) && $public !== ((int) $c['dc_public_in'] === 1)) {
            if (dh::Act(self::Me(), self::Me(), $id, $public ? 'public' : 'private')) {
                $done[] = $public ? 'it is open to everyone' : 'it asks for the setup password again (a new one, shown with the address)';
            }
        }
        self::Say('ok', $done ? 'Saved: ' . implode('; ', $done) . '.' : 'Nothing changed.');
    }

    /**
     * A container's own settings (environment variables), from its Advanced section.
     *
     * @return void
     */
    static function doCtEnv()
    {
        runtime_csfr::Protect();
        $text = isset($_POST['inEnv']) && is_string($_POST['inEnv']) ? $_POST['inEnv'] : '';
        if (dh::SetOwnerEnv(self::Me(), (int) self::Int('inId'), $text)) {
            unset($_POST['inEnv']);
            self::Say('ok', 'Saved: the app restarts with its new settings (a few seconds). If it does not start with them, it comes back with the ones before.');
        } else {
            self::Say(dh::$why === 'Nothing changed.' ? 'ok' : 'error', dh::$why);
        }
    }

    /**
     * Change a container's limits.
     *
     * @return void
     */
    static function doLimits()
    {
        runtime_csfr::Protect();
        $cpu = dh::ParseCpu(self::Post('inCpu'));
        $mem = self::Int('inMem');
        $disk = self::Gb('inDisk');
        if ($cpu === false || $mem === false || $disk === false) {
            self::Say('error', 'Give the CPU (like 0.5), memory (MB) and disk (GB) as numbers.');
            return;
        }
        self::Done(dh::Limits(self::Me(), self::Me(), (int) self::Int('inId'), $cpu, $mem, $disk), 'The new limits are on their way to the node.');
    }

    // ----- shares -----

    /**
     * Give a client a share, change it, or take it back.
     *
     * @return void
     */
    static function doGrant()
    {
        runtime_csfr::Protect();
        $child = (int) self::Int('inAcc');
        if (self::Post('inOff') === '1') {
            $v = array('count' => 0, 'cpu' => 0, 'mem' => 0, 'disk' => 0, 'apps' => array());
        } elseif ((int) self::Int('inPlan') > 0) {
            $v = array('plan' => (int) self::Int('inPlan'));
        } else {
            $cpu = dh::ParseCpu(self::Post('inCpu'));
            $v = array('count' => self::Int('inCount'), 'cpu' => $cpu, 'mem' => self::Int('inMem'), 'disk' => self::Gb('inDisk'),
                'apps' => isset($_POST['inApps']) && is_array($_POST['inApps']) ? array_values(array_filter($_POST['inApps'], 'is_string')) : array());
            if (in_array(false, array($v['count'], $v['cpu'], $v['mem'], $v['disk']), true)) {
                self::Say('error', 'Give the containers, CPU (like 2.5), memory (MB) and disk (GB) as numbers.');
                return;
            }
        }
        self::Done(dh::SetGrant(self::Me(), $child, $v), isset($v['plan']) ? 'The client is on that plan now.' : ($v['count'] === 0 ? 'The share was taken back.' : 'The share was saved.'));
    }

    /**
     * Make or change a Docker plan (its clients follow).
     *
     * @return void
     */
    static function doPlan()
    {
        runtime_csfr::Protect();
        $cpu = dh::ParseCpu(self::Post('inCpu'));
        $v = array('name' => self::Post('inName'), 'count' => self::Int('inCount'), 'cpu' => $cpu, 'mem' => self::Int('inMem'), 'disk' => self::Gb('inDisk'),
            'apps' => isset($_POST['inApps']) && is_array($_POST['inApps']) ? array_values(array_filter($_POST['inApps'], 'is_string')) : array());
        if (in_array(false, array($v['count'], $v['cpu'], $v['mem'], $v['disk']), true)) {
            self::Say('error', 'Give the containers, CPU (like 2.5), memory (MB) and disk (GB) as numbers.');
            return;
        }
        $id = dh::SavePlan(self::Me(), (int) self::Int('inPlan'), $v);
        if ($id === false) {
            self::Say('error', dh::$why);
            return;
        }
        $n = count(dh::PlanClients($id));
        self::Say('ok', 'Plan "%s" saved.' . ($n > 0 ? ' Its clients follow it.' : ''), array($v['name']));
        if (dh::$why !== '') {
            self::Say('error', dh::$why);
        }
    }

    /**
     * Remove a Docker plan (its clients keep their amounts).
     *
     * @return void
     */
    static function doPlanRemove()
    {
        runtime_csfr::Protect();
        self::Done(dh::RemovePlan(self::Me(), (int) self::Int('inPlan')), 'The plan was removed; its clients keep their amounts as their own.');
    }

    // ----- nodes (administrators) -----

    /**
     * Add a node: its install command, shown once.
     *
     * @return void
     */
    static function doAddNode()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $name = self::Post('inName');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,59}$/', $name)) {
            self::Say('error', 'Give the node a name: letters, digits, spaces, dots, hyphens (up to 60).');
            return;
        }
        if (self::Post('inKey') !== '') {
            $nid = dh::AddNodeKey($name, self::Post('inKey'), self::Me());
            if ($nid === false) {
                self::Say('error', dh::$why);
                return;
            }
            self::Say('ok', 'Node "%s" added. It is linked the next time it calls (within a few seconds, if its installer has finished): reload this page.', array($name));
            return;
        }
        if (isset($_POST['inUrl'])) {
            $url = rtrim(self::Post('inUrl'), '/');
            if ($url !== '' && !preg_match('#^https://[A-Za-z0-9.-]{1,253}(:\d{1,5})?$#', $url)) {
                self::Say('error', 'The panel\'s address must look like https://172.16.1.250 or https://panel.example.com (with :port if needed), nothing after it.');
                return;
            }
            if ($url !== dh::Get('panel_url')) {
                dh::Set('panel_url', $url);
                dh::Log('settings', 'Panel address for nodes: ' . dh::PanelUrl(), 0, self::Me());
            }
        }
        $code = dh::AddNode($name, self::Me());
        if ($code === false) {
            self::Say('error', dh::$why);
            return;
        }
        self::$command = dh::InstallCommand($code);
        self::Say('ok', 'Node "%s" added. Its install command is below: run it on the new server within the hour.', array($name));
    }

    /**
     * A new install code for a node not linked yet.
     *
     * @return void
     */
    static function doNodeCode()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $code = dh::NewCode((int) self::Int('inId'), self::Me());
        if ($code === false) {
            self::Say('error', dh::$why);
            return;
        }
        self::$command = dh::InstallCommand($code);
        self::Say('ok', 'A new install command (the old one no longer works): run it as root on the node within the hour.');
    }

    /**
     * Change a node: its public address, whether it takes new containers; or remove it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doNode()
    {
        global $zdbh;
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $id = (int) self::Int('inId');
        $n = dh::Node($id);
        if (!$n) {
            return;
        }
        if (self::Post('inRemove') === '1') {
            $ok = dh::RemoveNode($id, self::Me(), isset($_POST['inConfirm']) && is_string($_POST['inConfirm']) ? $_POST['inConfirm'] : null);
            $with = dh::$removedApps > 0;
            self::Done($ok, dh::$why === 'online' ? ($with ? 'The node was asked to delete its apps and then remove its agent and keys; it disappears from this list when it has (within a minute or two).'
                : 'The node was asked to remove its agent and keys; it disappears from this list when it has (within a minute).')
                : 'The node was removed' . ($with ? ', its apps too' : '') . ': its keys no longer work. It was not online: if it calls again, it is told to uninstall itself with everything on it. Or remove it there yourself: sentora-docker-node-uninstall --purge (as root on the node).');
            return;
        }
        $ip = self::Post('inIp');
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            self::Say('error', 'The public address must be a public IPv4 address (or empty: the address the node calls from).');
            return;
        }
        $acc = self::Post('inAccepting') === '1' ? 1 : 0;
        $zdbh->prepare("UPDATE x_dh_nodes SET nd_publicip_vc = :i, nd_accepting_in = :a WHERE nd_id_pk = :n")->execute(array(':i' => $ip, ':a' => $acc, ':n' => $id));
        dh::Log('node', sprintf('Node "%s": public address %s, %s new containers', $n['nd_name_vc'], $ip !== '' ? $ip : '(as seen)', $acc ? 'takes' : 'takes no'), 0, self::Me(), 0, $id);
        self::Say('ok', 'The node was saved.');
    }

    /**
     * Something asked of a node: update it, its system's updates, restart, automatic security updates.
     *
     * @return void
     */
    static function doNodeAct()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $what = self::Post('inWhat');
        if ($what === 'autosec') {
            // (the checkbox in Manage)
            $what = self::Post('inAutosec') === '1' ? 'autosec-on' : 'autosec-off';
        } elseif ($what === 'autoagent') {
            $what = self::Post('inAutoagent') === '1' ? 'autoagent-on' : 'autoagent-off';
        }
        $texts = array('node-update' => 'The node updates itself the next time it calls (a few seconds); it is back within a few minutes, its apps keep running.',
            'sys-security' => 'The node installs its security updates now; the result shows here when it is done.',
            'sys-all' => 'The node installs all its updates now; the result shows here when it is done.',
            'reboot' => 'The node restarts in about a minute; its apps start again by themselves.',
            'check-updates' => 'The node looks for updates now (its apps and its system); reload this page in a minute.',
            'autosec-on' => 'Security updates now install by themselves at night.', 'autosec-off' => 'Security updates now wait for you.',
            'autoagent-on' => 'Node updates now install by themselves at night.', 'autoagent-off' => 'Node updates now wait for you (Update now).');
        self::Done(dh::NodeAct((int) self::Int('inId'), $what, self::Me()), isset($texts[$what]) ? $texts[$what] : 'Done.');
    }

    /**
     * Update every node whose agent is behind (and is not updating already).
     *
     * @return void
     */
    static function doNodeUpdateAll()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $done = array();
        foreach (self::Behind() as $b) {
            if (!$b['queued'] && dh::NodeAct($b['id'], 'node-update', self::Me())) {
                $done[] = $b['name'];
            }
        }
        if (!$done) {
            self::Say('error', 'No node needs an update (or each is updating already).');
            return;
        }
        self::Say('ok', 'These nodes update themselves the next time they call (a few seconds): %s. Each is back within a few minutes; their apps keep running.', array(implode(', ', $done)));
    }

    /**
     * Give a found app (back) to an owner (administrators).
     *
     * @return void
     */
    static function doAdopt()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        self::Done(dh::Adopt(self::Me(), (int) self::Int('inFound'), (int) self::Int('inAcc'), self::Post('inApp')),
            'The node is asked for the app\'s settings; it is given to its owner within a minute (moved under the owner\'s user on the node if needed, with its data).');
    }

    /**
     * Delete a found app on its node (administrators).
     *
     * @return void
     */
    static function doFoundDelete()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        self::Done(dh::DeleteFound(self::Me(), (int) self::Int('inFound')), 'The node deletes it, with its data, the next time it calls.');
    }

    /**
     * Start the apps of a node that is gone for good on the other nodes (administrators).
     *
     * @return void
     */
    static function doRehome()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $n = dh::Rehome((int) self::Int('inId'), self::Me());
        self::Done($n !== false, '%s app(s) are being started on other nodes, without their old data. %s', array($n === false ? 0 : $n, dh::$why));
    }

    /**
     * New panel keys when the old ones can not be read (administrators).
     *
     * @return void
     */
    static function doNewKeys()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        self::Done(dh::NewKeys(self::Me()), 'The panel has new keys. Now link every node again: on each, as root, run sentora-docker-node-relink --panel <this panel\'s address>, then add it here with the key it shows. Its apps keep running and show under "Found on the nodes" to give back.');
    }

    /**
     * Let a customer's apps reach the local network, or not (administrators).
     *
     * @return void
     */
    static function doLan()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $on = self::Post('inLan') === '1';
        $a = dh::Account((int) self::Int('inAcc'));
        self::Done(dh::SetLan(self::Me(), (int) self::Int('inAcc'), $on), $on ? '%s\'s apps may reach the local network now (the nodes take it within a few seconds).'
            : '%s\'s apps can no longer reach the local network (the nodes take it within a few seconds).', array($a ? $a['ac_user_vc'] : ''));
    }

    /**
     * A node's certificate settings.
     *
     * @return void
     */
    static function doNodeSettings()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        self::Done(dh::SetNodeSettings((int) self::Int('inId'), self::Post('inEmail'), self::Post('inLocal') === '1', self::Me()), 'Saved; the node takes the new settings the next time it calls.');
    }

    /**
     * The apps offered, and the panel's address for its nodes.
     *
     * @return void
     */
    static function doSettings()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $apps = isset($_POST['inApps']) && is_array($_POST['inApps']) ? array_values(array_intersect(array_keys(dh_catalog::Apps()), $_POST['inApps'])) : array();
        dh::Set('apps', count($apps) === count(dh_catalog::Apps()) ? '*' : implode(',', $apps));
        dh::Log('settings', 'Apps offered: ' . (count($apps) ? implode(', ', $apps) : 'none'), 0, self::Me());
        self::Say('ok', 'Saved.');
    }

    // ----- the administrators' own apps -----

    /** @var string|null The app form that was sent and refused ('' = the new one), shown again filled in. */
    static $appForm = null;

    /**
     * Add an app of one's own, or change one (inId).
     *
     * @return void
     */
    static function doApp()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $id = self::Post('inId');
        $p = array('name' => self::Post('inName'), 'desc' => self::Post('inDesc'), 'image' => self::Post('inImage'), 'port' => self::Post('inPort'),
            'volumes' => self::Post('inVolumes'), 'env' => isset($_POST['inEnv']) && is_string($_POST['inEnv']) ? $_POST['inEnv'] : '', 'web' => self::Post('inWeb'),
            'minmem' => self::Int('inMinMem'), 'mem' => self::Int('inMem'), 'cpu' => dh::ParseCpu(self::Post('inCpu')), 'disk' => self::Gb('inDisk'));
        $r = dh::SaveApp(self::Me(), $p, $id);
        if ($r === false) {
            self::$appForm = $id;
            self::Say('error', dh::$why);
            return;
        }
        $a = dh_catalog::App($r);
        self::Say('ok', $id === '' ? '%s was added to the apps. It is offered to you at once; resellers and clients get it when you tick it in their share (Shares for my clients).' : '%s was saved. Apps already running get the change when their owner presses Update.', array($a['name']));
    }

    /**
     * Add or change a registry login (administrators).
     *
     * @return void
     */
    static function doRegistry()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $host = dh::RegistryHost(self::Post('inHost'));
        self::Done(dh::SaveRegistry(self::Me(), self::Post('inHost'), self::Post('inUser'), isset($_POST['inSecret']) && is_string($_POST['inSecret']) ? trim($_POST['inSecret']) : ''),
            'The login for %s was saved. Images from it are downloaded with it from now on (Update an app to use it for an app already running).', array($host));
    }

    /**
     * Remove a registry login (administrators).
     *
     * @return void
     */
    static function doRegistryRemove()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        self::Done(dh::RemoveRegistry(self::Me(), self::Post('inHost')), 'The login was removed. Its images are downloaded without a login again (a private one then fails).');
    }

    /**
     * Fill the Add an app form from a docker run line (only read, never run; nothing is saved).
     *
     * @return void
     */
    static function doAppFromRun()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $r = dh::FromDockerRun(isset($_POST['inRun']) && is_string($_POST['inRun']) ? $_POST['inRun'] : '');
        if ($r === false) {
            self::Say('error', dh::$why);
            return;
        }
        $f = $r['fields'];
        $mem = $f['mem'] >= 64 ? $f['mem'] : 256;
        $_POST = array('inName' => $f['name'], 'inDesc' => '', 'inImage' => $f['image'], 'inPort' => $f['port'], 'inVolumes' => $f['volumes'], 'inEnv' => $f['env'], 'inWeb' => '',
            'inMinMem' => (string) min(128, $mem), 'inMem' => (string) $mem, 'inCpu' => dh::Cpu($f['cpu'] >= 10 ? $f['cpu'] : 50), 'inDisk' => '1');
        self::$appForm = '';
        self::Say('ok', 'Filled in from the docker run line: check each field (the port above all), add a line saying what it does, then press Add the app. Nothing is saved yet.');
        if ($r['left']) {
            self::Say('warn', 'Left out: %s', array(implode('; ', $r['left'])));
        }
    }

    /**
     * Remove an app of one's own.
     *
     * @return void
     */
    static function doAppRemove()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        self::Done(dh::RemoveApp(self::Me(), self::Post('inId')), 'The app was removed.');
    }

    // ----- the page -----

    /**
     * Nothing to set up before the page (module.zpm's <@ Init @> asks for it).
     *
     * @return void
     */
    static function getInit()
    {
    }

    /**
     * The page.
     *
     * @return string
     */
    static function getPage()
    {
        static $page = null;
        if ($page !== null) {
            return $page;
        }
        dh::Ensure();
        return $page = self::PageHtml();
    }

    /**
     * The page's HTML.
     *
     * @return string
     */
    static function PageHtml()
    {
        $me = self::Me();
        $admin = self::Admin();
        $out = '';
        foreach (self::$msg as $m) {
            $out .= '<div class="dh-msg ' . ($m[0] === 'ok' ? 'is-ok' : ($m[0] === 'warn' ? 'is-warn' : 'is-bad')) . '" role="' . ($m[0] === 'error' ? 'alert' : 'status') . '">' . self::h($m[1]) . '</div>';
        }
        if (!dh::Keys()) {
            $out .= '<div class="dh-msg is-bad">' . self::t('This server has no secret key (cnf/security.php), or it changed since the nodes were linked: Docker Hosting can not work. Run sentora-post-install-check.') . '</div>';
            if ($admin && dh::Get('sign_sk') !== '') {
                // the keys are there but can not be read: the old secret is lost
                $out .= '<div class="dh-card"><h3>' . self::t('The panel\'s keys can not be read') . '</h3><p class="dh-small">'
                    . self::t('First try to put the old cnf/security.php back (from a backup): then everything works again as it was. Only when it is lost for good, start over with new keys: the apps on the nodes keep running with their data, but every node must be linked again (on each, as root: sentora-docker-node-relink --panel <this panel\'s address>, then add it under Nodes and apps with the key it shows), and you give each app back to its owner from "Found on the nodes". Setup passwords and the apps\' own settings are read again from the nodes.')
                    . '</p>' . self::Button('NewKeys', array(), 'Start over with new keys', 'btn-danger', 'Make new keys? Every node must be linked again with sentora-docker-node-relink. Do this only when the old cnf/security.php is lost.') . '</div>';
            }
            return $out;
        }
        $grant = dh::Grant($me);
        $kids = $grant ? dh::Children($me) : array();
        $tabs = array();
        $tabs['containers'] = 'My apps';
        if ($grant && count($kids) > 0) {
            $tabs['shares'] = 'Shares for my clients';
        }
        if ($admin) {
            $tabs['nodes'] = 'Nodes and apps';
        }
        $tabs['log'] = 'Log';
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : ($admin && self::$command !== '' ? 'nodes' : 'containers');
        if ($admin) {
            $out .= self::WarningsHtml();
        }
        $out .= '<div class="dh-tabs">';
        foreach ($tabs as $k => $label) {
            $out .= '<a class="' . ($k === $tab ? 'on' : '') . '" href="./?module=docker_hosting&amp;tab=' . $k . '">' . self::t($label) . '</a>';
        }
        $out .= '</div>';
        switch ($tab) {
            case 'shares':
                return $out . self::SharesHtml($me, $grant, $kids);
            case 'nodes':
                return $out . self::NodesHtml();
            case 'log':
                return $out . self::LogHtml($me, $admin);
            default:
                return $out . self::ContainersHtml($me, $grant);
        }
    }

    /**
     * What needs a look on the nodes (administrators: shown above the tabs on every tab).
     *
     * @return string
     */
    static function WarningsHtml()
    {
        // what needs a look: one line per node (its name, then what is wrong in a few words), with the
        // buttons that fix it under the line (the same as in its Manage, so they say Queued too)
        $items = array();
        $behind = 0;
        foreach (dh::Nodes() as $n) {
            $id = (int) $n['nd_id_pk'];
            $what = array();
            $btn = '';
            foreach (dh::Words($n) as $k => $w) {
                $what[] = self::h(ui_language::translate($w));
                if ($k === 'security') {
                    $btn .= self::NodeButton($id, 'sys-security', 'Install security updates', 'btn-primary');
                } elseif ($k === 'reboot') {
                    $btn .= self::NodeButton($id, 'reboot', 'Restart node', 'btn-warning', 'Restart this node? Its apps are down for a minute or two and start again by themselves.');
                } elseif ($k === 'agent') {
                    $btn .= self::NodeButton($id, 'node-update', 'Update now', 'btn-primary', '', 'update');
                    $behind++;
                }
            }
            if ($what) {
                $items[] = '<li><b>' . self::h((string) $n['nd_name_vc']) . '</b>: ' . implode('; ', $what) . '.'
                    . ($btn !== '' ? '<div class="dh-msg-act">' . $btn . '</div>' : '') . '</li>';
            }
        }
        if (!$items) {
            return '';
        }
        return '<div class="dh-msg is-bad" role="alert"><div class="dh-cardhead"><b>' . self::t('Needs a look') . '</b>' . ($behind > 1 ? self::UpdateAllButton() : '') . '</div><ul class="dh-small">' . implode('', $items) . '</ul>'
            . '<span class="dh-small">' . self::t('More in each node\'s Manage, under') . ' <a href="./?module=docker_hosting&amp;tab=nodes">' . self::t('Nodes and apps') . '</a>. '
            . self::t('The administrators also get this as a panel notice, and by email (again each day while it lasts).') . '</span></div>';
    }

    /**
     * A form with one button.
     *
     * @param string $action
     * @param array $fields
     * @param string $label
     * @param string $class
     * @param string $ask A question to confirm first.
     * @return string
     */
    static function Button($action, array $fields, $label, $class = 'btn-default', $ask = '', $icon = '')
    {
        $f = '<form class="dh-inline" method="post" action="./?module=docker_hosting' . self::Here() . '&amp;action=' . $action . '"' . ($ask !== '' ? ' onsubmit="return confirm(this.dataset.ask)" data-ask="' . self::t($ask) . '"' : '') . '>' . runtime_csfr::Token();
        foreach ($fields as $k => $v) {
            $f .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        }
        if ($icon === 'trash') {
            // (as the panel's own lists: delete is a trash can, red when pointed at)
            return $f . '<button type="submit" class="btn btn-sm btn-default dh-del" title="' . self::t($label) . '" aria-label="' . self::t($label) . '">' . self::Icon('trash') . '</button></form>';
        }
        return $f . '<button type="submit" class="btn btn-sm ' . $class . '">' . ($icon !== '' ? self::Icon($icon) : '') . self::t($label) . '</button></form>';
    }

    /**
     * Where the page is now (its tab, and the row opened under its list), so a button
     * brings the person back to the same place.
     *
     * @return string query string part, already escaped for HTML
     */
    static function Here()
    {
        $q = '';
        if (isset($_GET['tab']) && is_string($_GET['tab']) && in_array($_GET['tab'], array('containers', 'shares', 'nodes', 'log'), true)) {
            $q .= '&amp;tab=' . $_GET['tab'];
        }
        foreach (array('manage', 'settings', 'plan', 'share') as $k) {
            if (isset($_GET[$k]) && is_string($_GET[$k]) && ctype_digit($_GET[$k])) {
                $q .= '&amp;' . $k . '=' . (int) $_GET[$k];
            }
        }
        if (isset($_GET['app']) && is_string($_GET['app']) && preg_match('/^[a-z0-9-]{1,64}$/', $_GET['app'])) {
            $q .= '&amp;app=' . $_GET['app'];
        }
        return $q;
    }

    /**
     * The linked nodes whose agent is older than the newest one out.
     *
     * @return array of array(id, name, agent, queued: an update is already asked for)
     */
    static function Behind()
    {
        $latest = dh::Get('latest_agent');
        $out = array();
        if ($latest === '') {
            return $out;
        }
        foreach (dh::Nodes() as $n) {
            $i = dh::Info($n);
            if ((string) $n['nd_signpk_vc'] === '' || (string) $i['agent'] === '' || !version_compare((string) $i['agent'], $latest, '<')) {
                continue;
            }
            $jobs = dh::NodeJobs((int) $n['nd_id_pk']);
            $out[] = array('id' => (int) $n['nd_id_pk'], 'name' => (string) $n['nd_name_vc'], 'agent' => (string) $i['agent'], 'queued' => isset($jobs['node-update']));
        }
        return $out;
    }

    /**
     * "Update all nodes": one button for every node that is behind and not already updating.
     *
     * @return string
     */
    static function UpdateAllButton()
    {
        // (as Repo Browser's Update all: the count, asked first, then Queued while the nodes have not taken it)
        $todo = 0;
        $behind = self::Behind();
        foreach ($behind as $b) {
            $todo += $b['queued'] ? 0 : 1;
        }
        if ($todo === 0) {
            return $behind ? '<button class="btn btn-sm btn-default" type="button" disabled="disabled" title="'
                . self::t('Waiting for the nodes: each picks this up the next time it calls (within a few seconds).') . '">' . self::t('%s (queued)', array(ui_language::translate('Update all'))) . '</button>' : '';
        }
        if ($todo === 1) {
            return self::Button('NodeUpdateAll', array(), 'Update now', 'btn-primary', '', 'update');
        }
        return str_replace('</button></form>', ' (' . $todo . ')</button></form>',
            self::Button('NodeUpdateAll', array(), 'Update all', 'btn-primary', 'Update every node in this list? Their apps keep running.', 'update'));
    }

    /**
     * A node's Remove (trash) button. With apps on it, it names them and asks for the node's name typed
     * in (they are deleted with it, with their data); without, a plain yes/no.
     *
     * @return string
     */
    static function RemoveNodeButton($id, array $n)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT dc_domain_vc FROM x_dh_containers WHERE dc_node_fk = :n AND dc_deleted_in IS NULL ORDER BY dc_domain_vc");
        $q->execute(array(':n' => (int) $id));
        $domains = $q->fetchAll(PDO::FETCH_COLUMN);
        $f = $zdbh->prepare("SELECT fd_domain_vc, fd_cid_in FROM x_dh_found WHERE fd_node_fk = :n ORDER BY fd_cid_in");
        $f->execute(array(':n' => (int) $id));
        foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $fd) {
            $domains[] = ($fd['fd_domain_vc'] !== '' ? $fd['fd_domain_vc'] : 'app ' . (int) $fd['fd_cid_in']) . ' (' . ui_language::translate('found on it') . ')';
        }
        if (!$domains) {
            return self::Button('Node', array('inId' => $id, 'inRemove' => '1'), 'Remove', 'btn-default',
                'Remove this node? When it is online it removes its agent and keys itself; its keys stop working in the panel either way.', 'trash');
        }
        $list = implode(', ', array_slice($domains, 0, 12)) . (count($domains) > 12 ? ', ...' : '');
        $ask = ui_language::translate('Remove node "%s" and everything on it?') . "\n\n"
            . ui_language::translate('These apps are deleted with it, with their data (their owners get a notice):') . "\n" . $list . "\n\n"
            . ui_language::translate('Type the node\'s name to go ahead:');
        $ask = str_replace('%s', (string) $n['nd_name_vc'], $ask);
        return '<form class="dh-inline" method="post" action="./?module=docker_hosting' . self::Here() . '&amp;action=Node" data-ask="' . self::h($ask) . '"'
            . ' onsubmit="var v = prompt(this.dataset.ask); if (v === null) { return false; } this.inConfirm.value = v; return true;">' . runtime_csfr::Token()
            . '<input type="hidden" name="inId" value="' . (int) $id . '"><input type="hidden" name="inRemove" value="1"><input type="hidden" name="inConfirm" value="">'
            . '<button type="submit" class="btn btn-sm btn-default dh-del" title="' . self::t('Remove the node and its apps') . '" aria-label="' . self::t('Remove the node and its apps') . '">' . self::Icon('trash') . '</button></form>';
    }

    /**
     * A small line icon, as the panel's own lists use.
     *
     * @param string $name
     * @return string
     */
    static function Icon($name)
    {
        $paths = array(
            'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
            'stop' => '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
            'play' => '<path d="M7 5v14l11-7z"/>',
            'restart' => '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
            'logs' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
            'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
            'update' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
            'server' => '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        );
        return '<svg class="dh-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (isset($paths[$name]) ? $paths[$name] : '') . '</svg>';
    }

    /**
     * A share's numbers as tiles.
     *
     * @param array $g
     * @param int $acc
     * @return string
     */
    static function ShareTiles(array $g, $acc)
    {
        if ($g['unlimited']) {
            return '<p class="dh-muted">' . self::t('As an administrator you have no limit: only the room on the nodes counts.') . '</p>';
        }
        $u = dh::Used($acc);
        $gv = dh::Given($acc);
        $f = dh::Free($acc);
        $row = function ($label, $k, $fmt) use ($g, $u, $gv, $f) {
            return '<div class="dh-limit"><span>' . self::t($label) . '</span><b>' . self::h($fmt($f[$k])) . ' ' . self::t('free') . '</b><span>'
                . self::t('of %s; you use %s, gave %s', array($fmt($g[$k]), $fmt($u[$k]), $fmt($gv[$k]))) . '</span></div>';
        };
        $n = function ($v) { return (string) $v; };
        return '<div class="dh-limits">' . $row('Containers', 'count', $n) . $row('CPU', 'cpu', function ($v) { return dh::Cpu($v); })
            . $row('Memory (MB)', 'mem', $n) . $row('Disk (GB)', 'disk', function ($v) { return rtrim(rtrim(sprintf('%.2f', $v / 1024), '0'), '.'); }) . '</div>';
    }

    /**
     * The containers tab.
     *
     * @param int $me
     * @param array|null $g
     * @return string
     */
    static function ContainersHtml($me, $g)
    {
        if (!$g) {
            return '<div class="dh-card dh-intro"><p>' . self::t('Docker Hosting is not part of your account: ask your provider if you would like to run apps (like Gitea, Nextcloud or Uptime Kuma) here.') . '</p></div>';
        }
        $out = '<div class="dh-card"><h3>' . self::t('Your share') . '</h3>' . self::ShareTiles($g, $me) . '</div>';
        $list = dh::Containers($me);
        $out .= '<div class="dh-card"><h3>' . self::t('Your apps') . '</h3>';
        if (!$list) {
            $canStart = dh::Domains($me) && array_intersect($g['apps'], dh::EnabledApps());
            $out .= '<p class="dh-muted">' . ($canStart ? self::t('None yet: start one below.') : self::t('None yet.')) . '</p>';
        } else {
            $open = isset($_GET['settings']) && ctype_digit((string) $_GET['settings']) ? (int) $_GET['settings'] : 0;
            $words = array('running' => 'Running', 'stopped' => 'Stopped', 'exited' => 'Stopped', 'creating' => 'Being set up', 'starting' => 'Starting',
                'restarting' => 'Restarting', 'stopping' => 'Stopping', 'updating' => 'Being updated', 'applying' => 'Taking its new settings', 'deleting' => 'Being deleted', 'error' => 'Problem',
                'missing' => 'Missing on its node', 'moving' => 'Being moved', 'saving' => 'Being backed up', 'restoring' => 'Its backup being put back');
            $out .= '<div class="dh-list">';
            foreach ($list as $id => $c) {
                $app = dh_catalog::App((string) $c['dc_app_vc']);
                $state = (string) $c['dc_state_vc'];
                $cls = $state === 'running' ? 'is-ok' : (in_array($state, array('error', 'missing', 'stopped', 'exited'), true) ? 'is-bad' : 'is-warn');
                $busy = in_array($state, array('creating', 'deleting', 'updating', 'applying', 'moving', 'saving', 'restoring'), true);
                $missing = $state === 'missing';
                $public = (int) $c['dc_public_in'] === 1;
                $gb = rtrim(rtrim(sprintf('%.2f', $c['dc_disk_in'] / 1024), '0'), '.');
                // the row's controls, always in the same order: nightly updates, update, stop/start, restart, logs, settings, delete
                $actions = '';
                if ($missing) {
                    // the node lost it: made again (fresh) or deleted
                    $actions .= '<div class="dh-switches"></div><div class="dh-act">' . self::Button('Act', array('inId' => $id, 'inWhat' => 'remake'), 'Make it again', 'btn-primary',
                        'Make this app again on its node? It starts fresh: its old data was lost with the node\'s old setup. Its address and settings stay.', 'restart');
                } elseif (!$busy) {
                    $actions .= '<div class="dh-switches">';
                    $actions .= '<form class="dh-inline dh-night" method="post" action="./?module=docker_hosting&amp;action=Act" title="' . self::t('When a newer version of the app is out, it is installed by itself between 02:00 and 05:00. If it does not start, the old version comes back.') . '">'
                        . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . $id . '"><input type="hidden" name="inWhat" value="' . ((int) $c['dc_autoupd_in'] === 1 ? 'auto-off' : 'auto-on') . '">'
                        . '<label class="sf-switch"><input type="checkbox" onchange="this.form.submit()"' . ((int) $c['dc_autoupd_in'] === 1 ? ' checked' : '') . ' aria-label="' . self::t('Nightly updates') . '"><span class="sf-toggle"></span></label>'
                        . '<span class="dh-night-label">' . self::t('Nightly updates') . '</span>'
                        . '<noscript><button class="btn btn-sm btn-default" type="submit">' . self::t((int) $c['dc_autoupd_in'] === 1 ? 'Turn off' : 'Turn on') . '</button></noscript></form>';
                    $actions .= '<form class="dh-inline dh-night" method="post" action="./?module=docker_hosting&amp;action=Act" title="' . self::t('On: anyone can open the app\'s address, no password. Off: the address asks for the setup password.') . '">'
                        . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . $id . '"><input type="hidden" name="inWhat" value="' . ($public ? 'private' : 'public') . '">'
                        . '<label class="sf-switch"><input type="checkbox" onchange="if(this.checked&&!confirm(this.dataset.ask)){this.checked=false;return;}this.form.submit()"' . ($public ? ' checked' : '')
                        . ' data-ask="' . self::t('Open it to everyone? Do this once the app is set up (it has its own administrator account): after this, anyone can reach it without a password.') . '" aria-label="' . self::t('Open to everyone') . '"><span class="sf-toggle"></span></label>'
                        . '<span class="dh-night-label">' . self::t('Open to everyone') . '</span>'
                        . '<noscript><button class="btn btn-sm btn-default" type="submit">' . self::t($public ? 'Turn off' : 'Turn on') . '</button></noscript></form>';
                    $actions .= '</div><div class="dh-act">';
                    if ((int) $c['dc_update_in'] === 1) {
                        $actions .= self::Button('Act', array('inId' => $id, 'inWhat' => 'update'), 'Update', 'btn-primary', 'Update this app to its newest version? It is down for a moment; its data stays. If the new version does not start, the old one comes back by itself.', 'update');
                    }
                    $running = in_array($state, array('running', 'starting', 'restarting'), true);
                    $actions .= $running ? self::Button('Act', array('inId' => $id, 'inWhat' => 'stop'), 'Stop', 'btn-default', '', 'stop') . self::Button('Act', array('inId' => $id, 'inWhat' => 'restart'), 'Restart', 'btn-default', '', 'restart')
                        : self::Button('Act', array('inId' => $id, 'inWhat' => 'start'), 'Start', 'btn-primary', '', 'play');
                    $actions .= self::Button('Act', array('inId' => $id, 'inWhat' => 'logs'), 'Logs', 'btn-default', '', 'logs');
                    $actions .= '<a class="btn btn-sm btn-default' . ($open === $id ? ' active' : '') . '" href="./?module=docker_hosting' . ($open === $id ? '' : '&amp;settings=' . $id . '#dh-set-' . $id) . '">' . self::Icon('gear') . self::t('Settings') . '</a>';
                }
                $actions .= ($busy ? '<div class="dh-act">' : '') . self::Button('Act', array('inId' => $id, 'inWhat' => 'delete'), 'Delete', 'btn-default', 'Delete this app and all its data? This can not be undone.', 'trash') . '</div>';
                if ($busy) {
                    $actions = '<div class="dh-switches"></div>' . $actions;
                }
                // what is going on, in words
                $status = '<span class="dh-pill ' . $cls . '">' . self::t(isset($words[$state]) ? $words[$state] : ucfirst($state)) . '</span>'
                    . ((int) $c['dc_update_in'] === 1 ? '<span class="dh-pill is-warn">' . self::t('A newer version is out') . '</span>' : '');
                $setup = '';
                if (!$public && !$busy && !$missing) {
                    $pw = dh::SetupPassword($c);
                    $setup = '<div class="dh-setup">' . self::t('Password protected: the address asks for user %s and password %s. Set the app up (make its own administrator account), then switch on "Open to everyone".', array('setup', $pw !== '' ? $pw : '?'))
                        . '</div>';
                    $setup = str_replace(array('user setup', self::h($pw)), array('user <code>setup</code>', '<code>' . self::h($pw) . '</code>'), $setup);
                }
                $cn = dh::Node((int) $c['dc_node_fk']);
                $dns = (int) $c['dc_dns_fk'] > 0 ? '' : '<div class="dh-muted">' . self::t('The panel did not add a DNS record for this address: add an A record for %s pointing to %s (the server this app runs on), in DNS Manager or wherever your domain\'s DNS is kept.', array($c['dc_domain_vc'], $cn && dh::PublicIp($cn) !== '' ? dh::PublicIp($cn) : '?')) . '</div>';
                $name = $app ? $app['name'] : (string) $c['dc_app_vc'];
                $sub = '<a href="https://' . self::h($c['dc_domain_vc']) . '/" target="_blank" rel="noopener noreferrer">' . self::h($c['dc_domain_vc']) . '</a>'
                    . '<span>' . self::h(dh::Cpu((int) $c['dc_cpu_in'])) . ' CPU, ' . (int) $c['dc_mem_in'] . ' MB, ' . self::h($gb) . ' GB</span>'
                    . '<span>' . self::t('%s MB of disk used', array((int) $c['dc_used_in'])) . '</span>'
                    . '<span>' . ($public ? self::t('Open to everyone: no password') : self::t('Password protected')) . '</span>'
                    . (self::Admin() ? '<span class="dh-onnode">' . self::t('On node %s', array($cn ? (string) $cn['nd_name_vc'] : '?')) . '</span>' : '')
                    . self::BackupLine($c);
                $out .= '<div class="dh-item ' . $cls . '" id="dh-set-' . $id . '"><span class="dh-avatar" aria-hidden="true">' . self::h(strtoupper(substr($name, 0, 1))) . '</span>'
                    . '<div class="dh-main"><div class="dh-name"><b>' . self::h($name) . '</b>' . ((string) $c['dc_label_vc'] !== '' ? '<span class="dh-muted">' . self::h($c['dc_label_vc']) . '</span>' : '') . $status . '</div>'
                    . '<div class="dh-sub">' . $sub . '</div>'
                    . ((string) $c['dc_note_vc'] !== '' ? '<div class="dh-note">' . self::h($c['dc_note_vc']) . '</div>' : '') . $dns . $setup . '</div>'
                    . $actions;
                // its settings, opened under its row
                if ($open === $id && !$busy && !$missing) {
                    $out .= '<div class="dh-more dh-settings"><form method="post" action="./?module=docker_hosting&amp;action=CtSettings" data-public="' . ($public ? '1' : '0') . '"'
                        . '>'
                        . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . $id . '">'
                        . '<h4>' . self::t('Settings of %s', array($c['dc_domain_vc'])) . '</h4><div class="dh-grid">'
                        . '<fieldset><legend>' . self::t('Size') . '</legend>'
                        . '<label class="dh-field"><span>' . self::t('CPU') . '</span><input type="text" class="form-control" name="inCpu" value="' . self::h(dh::Cpu((int) $c['dc_cpu_in'])) . '"><small>' . self::t('1 = one full CPU core, 0.5 = half of one.') . '</small></label>'
                        . '<label class="dh-field"><span>' . self::t('Memory (MB)') . '</span><input type="text" class="form-control" name="inMem" value="' . (int) $c['dc_mem_in'] . '"><small>' . self::t('1024 MB = 1 GB.') . '</small></label>'
                        . '<label class="dh-field"><span>' . self::t('Disk (GB)') . '</span><input type="text" class="form-control" name="inDisk" value="' . self::h($gb) . '"><small>' . self::t('Room for the app\'s data. If it uses more, the app is stopped until you give it more room.') . '</small></label>'
                        . '</fieldset></div><div class="dh-foot"><a class="btn btn-default" href="./?module=docker_hosting">' . self::t('Cancel') . '</a> <button class="btn btn-primary" type="submit">' . self::t('Save') . '</button></div></form>'
                        . self::AdvancedHtml($id, $c, $app) . self::BackupHtml($id, $c) . '</div>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
        if (isset($_GET['logs']) && ctype_digit((string) $_GET['logs']) && isset($list[(int) $_GET['logs']])) {
            $l = dh::Logs((int) $_GET['logs']);
            $out .= '<div class="dh-card"><h3>' . self::t('Logs of %s', array($list[(int) $_GET['logs']]['dc_domain_vc'])) . '</h3>'
                . ($l[2] ? '<p class="dh-muted">' . self::t('Waiting for the node (it calls every few seconds): reload the page in a moment.') . ' <a href="./?module=docker_hosting&amp;logs=' . (int) $_GET['logs'] . '">' . self::t('Reload') . '</a></p>' : '')
                . ($l[0] !== '' ? '<p class="dh-muted">' . self::h(date('Y-m-d H:i:s', $l[1])) . '</p><div class="dh-logs">' . self::h($l[0]) . '</div>' : '') . '</div>';
        }
        $out .= self::DeployHtml($me, $g);
        return $out;
    }

    /**
     * The form that starts a container.
     *
     * @param int $me
     * @param array $g
     * @return string
     */
    static function DeployHtml($me, array $g)
    {
        $doms = dh::Domains($me);
        $apps = array_intersect_key(dh_catalog::Apps(), array_flip($g['apps']));
        if (!$apps) {
            return '<div class="dh-card"><p class="dh-muted">' . self::t('No apps are offered to you right now.') . '</p></div>';
        }
        if (!$doms) {
            return '<div class="dh-card"><h3>' . self::t('Start an app') . '</h3><p>' . self::t('Each app gets its own address under one of your domains (like app.yourdomain.com), and this account has no domain yet. To start one:') . '</p><ol class="dh-small">'
                . '<li><a href="./?module=domains">' . self::t('Domains') . '</a>: ' . self::t('add a domain.') . '</li>'
                . '<li><a href="./?module=dns_manager">' . self::t('DNS Manager') . '</a>: ' . self::t('create its DNS records (then the app\'s record can be added for you).') . '</li>'
                . '<li>' . self::t('Come back here: the form to start an app shows below your apps.') . '</li></ol></div>';
        }
        $appOpts = '';
        $desc = '';
        foreach ($apps as $id => $a) {
            $appOpts .= '<option value="' . self::h($id) . '" data-mem="' . (int) $a['mem'] . '" data-cpu="' . self::h(dh::Cpu($a['cpu'])) . '" data-disk="' . self::h($a['disk'] / 1024) . '">' . self::h($a['name']) . '</option>';
            $desc .= '<li><b>' . self::h($a['name']) . '</b>: ' . self::t($a['desc']) . ' <span class="dh-muted">' . self::t('at least %s MB of memory', array($a['minmem'])) . '</span></li>';
        }
        $domOpts = '';
        foreach ($doms as $id => $d) {
            $domOpts .= '<option value="' . (int) $id . '">' . self::h($d['name']) . ($d['dns'] ? '' : ' ' . self::t('(no DNS yet)')) . '</option>';
        }
        $first = reset($apps);
        return '<div class="dh-card"><h3>' . self::t('Start an app') . '</h3><p>' . self::t('Pick an app, give it a web address under one of your domains, and press Start it. The apps you may start:') . '</p><ul class="dh-small">' . $desc . '</ul>'
            . '<form method="post" action="./?module=docker_hosting&amp;action=Deploy" autocomplete="off">' . runtime_csfr::Token() . '<div class="dh-grid">'
            . '<fieldset><legend>' . self::t('App and address') . '</legend>'
            . '<label class="dh-field"><span>' . self::t('App') . '</span><select class="form-control" name="inApp" id="dh-app">' . $appOpts . '</select></label>'
            . '<div class="dh-field"><span>' . self::t('Web address of the app') . '</span><div class="dh-addr"><input type="text" class="form-control" name="inName" id="dh-name" placeholder="kuma" maxlength="63" required aria-label="name"> . <select class="form-control" name="inDomain" id="dh-dom" aria-label="domain">' . $domOpts . '</select></div>'
            . '<small>' . self::t('Type a short name of your choice in the first box (letters, digits and hyphens, for example kuma, status or files) and pick one of your domains. Together they make the address people type in their browser to open the app. The app gets its own HTTPS certificate.') . '</small>'
            . '<div class="dh-preview">' . self::t('The app will be at:') . ' <b id="dh-url">https://kuma.' . self::h(reset($doms)['name']) . '</b></div></div>'
            . '<label class="dh-check"><input type="checkbox" name="inDns" value="1" checked> <span>' . self::t('Add the DNS record for me (recommended). For the address to find the app, your domain needs a DNS record (type A) for this name, pointing to the server the app runs on. Ticked: the panel adds it to your domain, as DNS Manager would. Not ticked: you add it yourself, in DNS Manager or wherever your domain\'s DNS is kept.') . '</span></label>'
            . self::NodeChoice()
            . '<label class="dh-field"><span>' . self::t('A note for yourself (optional)') . '</span><input type="text" class="form-control" name="inLabel" maxlength="60" placeholder="' . self::t('Status page for the shop') . '"><small>' . self::t('Only shown to you, in your list of apps.') . '</small></label>'
            . '</fieldset><fieldset><legend>' . self::t('Limits') . '</legend>'
            . '<p class="dh-small">' . self::t('How much of the server this app may use. The values filled in suit the app you picked; you can change them later. They count against your share (at the top of this page).') . '</p>'
            . '<label class="dh-field"><span>' . self::t('CPU') . '</span><input type="text" class="form-control" name="inCpu" id="dh-cpu" value="' . self::h(dh::Cpu($first['cpu'])) . '"><small>' . self::t('Processor power: 1 = one full CPU core, 0.5 = half of one.') . '</small></label>'
            . '<label class="dh-field"><span>' . self::t('Memory (MB)') . '</span><input type="text" class="form-control" name="inMem" id="dh-mem" value="' . (int) $first['mem'] . '"><small>' . self::t('Working memory (RAM) in megabytes: 1024 MB = 1 GB. With too little the app stops or keeps restarting.') . '</small></label>'
            . '<label class="dh-field"><span>' . self::t('Disk (GB)') . '</span><input type="text" class="form-control" name="inDisk" id="dh-disk" value="' . self::h($first['disk'] / 1024) . '"><small>' . self::t('Room for the app\'s own files and data, in gigabytes. If it uses more, the app is stopped until you give it more room.') . '</small></label>'
            . '</fieldset></div><div class="dh-foot"><span class="dh-muted">' . self::t('After you press Start it, the server downloads the app (a few minutes the first time). The app then runs behind a setup password, shown on this page, so only you can do its first setup (like making its administrator account). When that is done, press "Open to the public".') . '</span>'
            . '<button class="btn btn-primary" type="submit">' . self::t('Start it') . '</button></div></form>'
            . '<script>(function () { var s = document.getElementById("dh-app"); if (!s) { return; } s.addEventListener("change", function () { var o = s.options[s.selectedIndex];'
            . ' document.getElementById("dh-cpu").value = o.dataset.cpu; document.getElementById("dh-mem").value = o.dataset.mem; document.getElementById("dh-disk").value = o.dataset.disk; });'
            . ' var n = document.getElementById("dh-name"), d = document.getElementById("dh-dom"), u = document.getElementById("dh-url");'
            . ' function show() { var dom = d.options[d.selectedIndex].text.split(" ")[0]; u.textContent = "https://" + ((n.value || "kuma").toLowerCase()) + "." + dom; }'
            . ' n.addEventListener("input", show); d.addEventListener("change", show); show(); })();</script></div>';
    }

    /**
     * When the app was last backed up, for its row ('' when backups are not offered).
     *
     * @param array $c
     * @return string
     */
    static function BackupLine(array $c)
    {
        if (dh_backup::Unavailable() !== '') {
            return '';
        }
        $b = dh_backup::Newest((int) $c['dc_id_pk']);
        if (!$b) {
            return '<span>' . self::t((int) $c['dc_backup_in'] === 1 ? 'Not backed up yet' : 'Not backed up') . '</span>';
        }
        return '<span>' . self::t('Backed up %s', array(date('Y-m-d H:i', (int) $b['bk_done_in']))) . '</span>';
    }

    /**
     * An app's backups, in its settings: nightly on or off, back up now, put the newest back. Offered
     * only while the off-server backup goes to a Sentora backup node.
     *
     * @param int $id
     * @param array $c
     * @return string
     */
    static function BackupHtml($id, array $c)
    {
        if (dh_backup::Unavailable() !== '') {
            return '';
        }
        $on = (int) $c['dc_backup_in'] === 1;
        $b = dh_backup::Newest($id);
        $last = '';
        foreach (dh_backup::Of($id) as $x) {
            if ($x['bk_state_vc'] === 'failed' && $x['bk_kind_vc'] === 'backup') {
                $last = '<p class="dh-note">' . self::t('The last try failed (%s): %s', array(date('Y-m-d H:i', (int) $x['bk_created_in']), $x['bk_note_vc'])) . '</p>';
            }
            break;
        }
        $size = function ($bytes) {
            return $bytes < 1048576 ? max(1, (int) round($bytes / 1024)) . ' KB' : round($bytes / 1048576, 1) . ' MB';
        };
        return '<div class="dh-backups"><h4>' . self::t('Backups') . '</h4>'
            . '<p class="dh-small">' . self::t('A backup copies the app\'s data (its data folders) to the panel: the app stops for a moment while it is read, then runs again. The panel keeps the newest; the off-server backup carries it to the backup node every night, which keeps the nights before.') . '</p>'
            . '<form class="dh-inline dh-night" method="post" action="./?module=docker_hosting&amp;action=BackupAuto">' . runtime_csfr::Token()
            . '<input type="hidden" name="inId" value="' . (int) $id . '"><input type="hidden" name="inOn" value="' . ($on ? '0' : '1') . '">'
            . '<label class="sf-switch"><input type="checkbox" onchange="this.form.submit()"' . ($on ? ' checked' : '') . ' aria-label="' . self::t('Nightly backup') . '"><span class="sf-toggle"></span></label>'
            . '<span class="dh-night-label">' . self::t('Nightly backup') . '</span> <span class="dh-muted">' . self::t('(two hours before the off-server backup)') . '</span>'
            . '<noscript><button class="btn btn-sm btn-default" type="submit">' . self::t($on ? 'Turn off' : 'Turn on') . '</button></noscript></form>'
            . '<p class="dh-small">' . ($b ? self::t('Newest backup: %s, %s.', array(date('Y-m-d H:i', (int) $b['bk_done_in']), $size((int) $b['bk_size_bi']))) : self::t('No backup yet.')) . '</p>' . $last
            . '<div class="dh-backups-act">' . self::Button('Backup', array('inId' => $id), 'Back up now', 'btn-default', '', 'update')
            . ($b ? ' ' . self::Button('Restore', array('inId' => $id), 'Put this backup back', 'btn-warning',
                sprintf(self::t('Put the backup of %s back? The app\'s data is replaced with it: what changed since then is gone.'), date('Y-m-d H:i', (int) $b['bk_done_in'])), 'restart') : '')
            . '</div></div>';
    }

    /**
     * The apps on a node, for an administrator, each with "Move to" another node.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @return string
     */
    static function NodeApps($node)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT c.*, a.ac_user_vc FROM x_dh_containers c LEFT JOIN x_accounts a ON a.ac_id_pk = c.dc_acc_fk WHERE c.dc_node_fk = :n AND c.dc_deleted_in IS NULL ORDER BY c.dc_domain_vc");
        $q->execute(array(':n' => (int) $node));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $out = '<h4>' . self::t('Apps on this node') . '</h4>';
        if (!$rows) {
            return $out . '<p class="dh-muted">' . self::t('None.') . '</p>';
        }
        $targets = '';
        foreach (dh::Nodes() as $tid => $t) {
            if ((int) $tid !== (int) $node && (string) $t['nd_signpk_vc'] !== '' && dh::Online($t)) {
                $r = dh::Room($t);
                $targets .= '<option value="' . (int) $tid . '">' . self::h(sprintf(self::t('%s (free: %d MB memory, %s GB disk)'), $t['nd_name_vc'], max(0, $r['mem']), round(max(0, $r['disk']) / 1024, 1))) . '</option>';
            }
        }
        $out .= '<p>' . self::t('Move to: the app stops, its data is carried over through the panel, and it starts on the other node at the same address (the DNS record the panel added follows it). The old copy is removed only once the new one runs; if anything fails, it runs again here. Bigger apps take longer: the panel needs room for their data meanwhile.') . '</p><div class="dh-list">';
        foreach ($rows as $c) {
            $app = dh_catalog::App((string) $c['dc_app_vc']);
            $name = $app ? $app['name'] : (string) $c['dc_app_vc'];
            $state = (string) $c['dc_state_vc'];
            $steady = in_array($state, array('running', 'stopped', 'exited', 'created'), true);
            $cls = $state === 'running' ? 'is-ok' : ($steady ? 'is-bad' : 'is-warn');
            $form = $targets !== '' && $steady
                ? '<form class="dh-found-form" method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;manage=' . (int) $node . '&amp;action=MoveApp" onsubmit="return confirm(this.dataset.ask)" data-ask="'
                    . self::t('Move this app to the other node? It is down while its data is carried over.') . '">' . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . (int) $c['dc_id_pk'] . '">'
                    . '<select class="form-control input-sm" name="inTo" aria-label="' . self::t('Move to') . '">' . $targets . '</select>'
                    . '<button class="btn btn-sm btn-default" type="submit">' . self::t('Move') . '</button></form>'
                : '<div class="dh-muted">' . self::t($targets === '' ? 'No other node is online to move it to.' : 'It can be moved once it is running or stopped.') . '</div>';
            $out .= '<div class="dh-item ' . $cls . '"><span class="dh-avatar" aria-hidden="true">' . self::h(strtoupper(substr($name, 0, 1))) . '</span>'
                . '<div class="dh-main"><div class="dh-name"><b>' . self::h($name) . '</b><span class="dh-pill ' . $cls . '">' . self::h(ucfirst($state)) . '</span></div>'
                . '<div class="dh-sub"><span>' . self::h($c['dc_domain_vc']) . '</span><span>' . self::t('owner %s', array((string) $c['ac_user_vc'])) . '</span><span>' . self::t('%s MB of disk used', array((int) $c['dc_used_in'])) . '</span></div>'
                . $form . '</div><div class="dh-switches"></div><div class="dh-act"></div></div>';
        }
        return $out . '</div>';
    }

    /**
     * A container's advanced settings (its environment variables): the app's own, shown, and its
     * owner's lines, which add to them or change them. Saving makes the app again with them.
     *
     * @param int $id
     * @param array $c
     * @param array|null $app
     * @return string
     */
    static function AdvancedHtml($id, array $c, $app)
    {
        $mine = dh::OwnerEnv($c);
        $posted = isset($_POST['inEnv']) && is_string($_POST['inEnv']) && (int) self::Int('inId') === (int) $id;
        $text = '';
        foreach ($mine as $k => $v) {
            $text .= $k . '=' . $v . "\n";
        }
        if ($posted) {
            $text = $_POST['inEnv'];
        }
        $rows = '';
        foreach ($app ? $app['env'] : array() as $k => $v) {
            $shown = strpos($v, '{secret}') !== false ? '<span class="dh-muted">' . self::t('(a secret of its own, made by the panel)') . '</span>'
                : '<code>' . self::h(str_replace('{domain}', (string) $c['dc_domain_vc'], $v)) . '</code>';
            $rows .= '<tr><td><code>' . self::h($k) . '</code></td><td>' . $shown . (isset($mine[$k]) ? ' <span class="dh-pill is-warn">' . self::t('changed by you below') . '</span>' : '') . '</td></tr>';
        }
        return '<details class="dh-adv"' . ($posted || $mine ? ' open' : '') . '><summary>' . self::t('Advanced: the app\'s own settings') . '</summary>'
            . '<p class="dh-small">' . self::t('Many apps are set up with settings (environment variables), as their maker\'s instructions describe: a time zone, a mail server for its messages, a language. Add your own here, one per line as NAME=value; a name the app already has gets your value instead.') . '</p>'
            . ($rows !== '' ? '<table class="dh-table dh-env"><tr><th>' . self::t('Set for this app') . '</th><th></th></tr>' . $rows . '</table>' : '<p class="dh-muted">' . self::t('This app has no settings of its own.') . '</p>')
            . '<form method="post" action="./?module=docker_hosting&amp;action=CtEnv&amp;settings=' . (int) $id . '#dh-set-' . (int) $id . '">' . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . (int) $id . '">'
            . '<label class="dh-field"><span>' . self::t('Your settings') . '</span><textarea class="form-control dh-envbox" name="inEnv" rows="5" spellcheck="false" placeholder="TZ=America/Chicago">' . self::h($text) . '</textarea>'
            . '<small>' . self::t('{domain} becomes the app\'s address. Saving restarts the app with them (a few seconds); if it does not start with them, it comes back with the ones before.') . '</small></label>'
            . '<p class="dh-small dh-note-soft">' . self::t('Good to know: some apps read a setting only the first time they start, and keep it in their own files after that. Changing such a setting here then does nothing; change it in the app itself.') . '</p>'
            . '<div class="dh-foot"><span></span><button class="btn btn-primary" type="submit">' . self::t('Save and restart the app') . '</button></div></form></details>';
    }

    /**
     * The shares tab.
     *
     * @param int $me
     * @param array $g
     * @param array $kids
     * @return string
     */
    static function SharesHtml($me, array $g, array $kids)
    {
        global $zdbh;
        $gb = function ($mb) { return rtrim(rtrim(sprintf('%.2f', $mb / 1024), '0'), '.'); };
        $amounts = function ($n, $cpu, $mem, $disk) use ($gb) {
            return self::t('%s containers, %s CPU, %s MB memory, %s GB disk', array($n, dh::Cpu($cpu), $mem, $gb($disk)));
        };
        $appNames = function (array $ids) {
            $n = array();
            foreach ($ids as $a) {
                $app = dh_catalog::App($a);
                $n[] = $app ? $app['name'] : $a;
            }
            return implode(', ', $n);
        };
        $checks = function (array $on) use ($g) {
            $c = '';
            foreach ($g['apps'] as $a) {
                $c .= '<label><input type="checkbox" name="inApps[]" value="' . self::h($a) . '"' . (in_array($a, $on, true) ? ' checked' : '') . '> ' . self::h(dh_catalog::App($a)['name']) . '</label>';
            }
            return '<div class="dh-apps">' . $c . '</div>';
        };
        $fields = function ($n, $cpu, $mem, $disk) use ($gb) {
            return '<label class="dh-field"><span>' . self::t('Containers') . '</span><input type="text" class="form-control" name="inCount" value="' . (int) $n . '"><small>' . self::t('How many apps at once.') . '</small></label>'
                . '<label class="dh-field"><span>' . self::t('CPU') . '</span><input type="text" class="form-control" name="inCpu" value="' . self::h(dh::Cpu($cpu)) . '"><small>' . self::t('For all its apps together; 1 = one full core.') . '</small></label>'
                . '<label class="dh-field"><span>' . self::t('Memory (MB)') . '</span><input type="text" class="form-control" name="inMem" value="' . (int) $mem . '"><small>' . self::t('For all its apps together; 1024 MB = 1 GB.') . '</small></label>'
                . '<label class="dh-field"><span>' . self::t('Disk (GB)') . '</span><input type="text" class="form-control" name="inDisk" value="' . self::h($gb($disk)) . '"><small>' . self::t('The apps\' data, all together.') . '</small></label>';
        };
        $out = '<div class="dh-card"><h3>' . self::t('What you have') . '</h3>' . self::ShareTiles($g, $me)
            . '<p class="dh-small">' . self::t('A share is how much Docker Hosting an account may use: how many apps (containers), how much CPU, memory and disk all its apps together, and which apps. These are Docker Hosting\'s own limits, apart from the panel\'s packages (the apps run on other servers). You give each of your clients part of what you have free, with a plan or its own amounts; a client can never go over its share, and a reseller can only pass on part of its own.') . '</p></div>';
        // plans
        $plans = dh::Plans($me);
        $openPlan = isset($_GET['plan']) && ctype_digit((string) $_GET['plan']) ? (int) $_GET['plan'] : 0;
        $out .= '<div class="dh-card" id="dh-plans"><h3>' . self::t('Docker plans') . '</h3><p class="dh-small">'
            . self::t('Your own packages for Docker Hosting: put a client on a plan below. When you change a plan, every client on it follows (never below what a client uses, and only as far as you have free: a client that can not follow keeps its amounts and is named). Removing a plan leaves its clients their amounts.') . '</p>';
        if ($plans) {
            $out .= '<div class="dh-list">';
            foreach ($plans as $pid => $p) {
                $papps = dh::AppList((string) $p['pl_apps_tx']);
                $n = count(dh::PlanClients($pid));
                $out .= '<div class="dh-item is-ok" id="dh-plan-' . $pid . '"><span class="dh-avatar" aria-hidden="true">' . self::h(mb_strtoupper(mb_substr($p['pl_name_vc'], 0, 1))) . '</span>'
                    . '<div class="dh-main"><div class="dh-name"><b>' . self::h($p['pl_name_vc']) . '</b><span class="dh-pill">' . self::t($n === 1 ? '1 client' : '%s clients', array($n)) . '</span></div>'
                    . '<div class="dh-sub"><span>' . $amounts((int) $p['pl_count_in'], (int) $p['pl_cpu_in'], (int) $p['pl_mem_in'], (int) $p['pl_disk_in']) . '</span><span>' . self::h($appNames($papps)) . '</span></div></div>'
                    . '<div class="dh-switches"></div><div class="dh-act">'
                    . '<a class="btn btn-sm btn-default' . ($openPlan === $pid ? ' active' : '') . '" href="./?module=docker_hosting&amp;tab=shares' . ($openPlan === $pid ? '' : '&amp;plan=' . $pid . '#dh-plan-' . $pid) . '">' . self::Icon('gear') . self::t('Change') . '</a>'
                    . self::Button('PlanRemove', array('inPlan' => $pid), 'Remove', 'btn-default', 'Remove this plan? Its clients keep their amounts as their own.', 'trash') . '</div>';
                if ($openPlan === $pid) {
                    $out .= '<div class="dh-more"><form method="post" action="./?module=docker_hosting&amp;tab=shares&amp;action=Plan#dh-plans">' . runtime_csfr::Token()
                        . '<input type="hidden" name="inPlan" value="' . $pid . '"><div class="dh-grid">'
                        . '<label class="dh-field"><span>' . self::t('Name') . '</span><input type="text" class="form-control" name="inName" maxlength="40" value="' . self::h($p['pl_name_vc']) . '"></label>'
                        . $fields((int) $p['pl_count_in'], (int) $p['pl_cpu_in'], (int) $p['pl_mem_in'], (int) $p['pl_disk_in']) . '</div>' . $checks($papps)
                        . '<div class="dh-foot"><a class="btn btn-default" href="./?module=docker_hosting&amp;tab=shares">' . self::t('Cancel') . '</a> <button class="btn btn-primary" type="submit">' . self::t('Save; its clients follow') . '</button></div></form></div>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';
        } else {
            $out .= '<p class="dh-muted">' . self::t('No plans yet: make one below, or give each client its own amounts.') . '</p>';
        }
        $out .= '<details class="dh-small"' . ($plans ? '' : ' open') . '><summary>' . self::t('Make a plan') . '</summary>'
            . '<form method="post" action="./?module=docker_hosting&amp;tab=shares&amp;action=Plan#dh-plans">' . runtime_csfr::Token() . '<input type="hidden" name="inPlan" value="0"><div class="dh-grid">'
            . '<label class="dh-field"><span>' . self::t('Name') . '</span><input type="text" class="form-control" name="inName" maxlength="40" placeholder="Small"></label>'
            . $fields(2, 100, 2048, 20480) . '</div>' . $checks($g['apps'])
            . '<div class="dh-foot"><span></span><button class="btn btn-primary" type="submit">' . self::t('Make this plan') . '</button></div></form></details></div>';
        // clients
        $openShare = isset($_GET['share']) && ctype_digit((string) $_GET['share']) ? (int) $_GET['share'] : 0;
        $out .= '<div class="dh-card" id="dh-clients"><h3>' . self::t('Your clients') . '</h3><div class="dh-list">';
        $q = $zdbh->prepare("SELECT * FROM x_dh_grants WHERE gr_acc_fk = :a AND gr_by_fk = :b");
        foreach ($kids as $id => $k) {
            $q->execute(array(':a' => $id, ':b' => $me));
            $cur = $q->fetch(PDO::FETCH_ASSOC);
            $cur = $cur && (int) $cur['gr_count_in'] > 0 ? $cur : null;
            $u = dh::Used($id);
            $gv = dh::Given($id);
            $plan = $cur && (int) $cur['gr_plan_fk'] > 0 && isset($plans[(int) $cur['gr_plan_fk']]) ? $plans[(int) $cur['gr_plan_fk']] : null;
            $opt = '<option value="">' . self::t($cur ? ($plan ? 'Plan...' : 'Put on a plan...') : 'Give a plan...') . '</option>';
            foreach ($plans as $pid => $p) {
                $opt .= '<option value="' . $pid . '"' . ($plan && (int) $plan['pl_id_pk'] === $pid ? ' selected' : '') . '>' . self::h($p['pl_name_vc']) . '</option>';
            }
            $act = ($plans ? '<form class="dh-inline" method="post" action="./?module=docker_hosting&amp;tab=shares&amp;action=Grant#dh-clients">' . runtime_csfr::Token()
                    . '<input type="hidden" name="inAcc" value="' . (int) $id . '"><select class="form-control input-sm" name="inPlan" aria-label="' . self::t('Plan') . '" onchange="if(this.value)this.form.submit()">' . $opt . '</select>'
                    . '<noscript><button class="btn btn-sm btn-default" type="submit">' . self::t('Save') . '</button></noscript></form>' : '')
                . '<a class="btn btn-sm btn-default' . ($openShare === $id ? ' active' : '') . '" href="./?module=docker_hosting&amp;tab=shares' . ($openShare === $id ? '' : '&amp;share=' . $id . '#dh-share-' . $id) . '">' . self::Icon('gear') . self::t('Own amounts') . '</a>'
                . ($cur ? self::Button('Grant', array('inAcc' => $id, 'inOff' => '1'), 'Take it back', 'btn-default', 'Take this client\'s whole share back? Only possible while it runs no containers.', 'trash') : '');
            $pill = $cur ? ($plan ? '<span class="dh-pill is-ok">' . self::h($plan['pl_name_vc']) . '</span>' : '<span class="dh-pill">' . self::t('own amounts') . '</span>') : '<span class="dh-pill is-warn">' . self::t('no share') . '</span>';
            $out .= '<div class="dh-item ' . ($cur ? 'is-ok' : 'is-warn') . '" id="dh-share-' . (int) $id . '"><span class="dh-avatar" aria-hidden="true">' . self::h(strtoupper(substr($k['ac_user_vc'], 0, 1))) . '</span>'
                . '<div class="dh-main"><div class="dh-name"><b>' . self::h($k['ac_user_vc']) . '</b>' . ((int) $k['ac_group_fk'] === 2 ? '<span class="dh-pill">' . self::t('reseller') . '</span>' : '') . $pill . '</div>'
                . '<div class="dh-sub">' . ($cur ? '<span>' . self::t('Share: %s', array($amounts((int) $cur['gr_count_in'], (int) $cur['gr_cpu_in'], (int) $cur['gr_mem_in'], (int) $cur['gr_disk_in']))) . '</span>' : '')
                . '<span>' . self::t('In use: %s', array($amounts($u['count'], $u['cpu'], $u['mem'], $u['disk']))) . '</span>'
                . ($gv['count'] > 0 ? '<span>' . self::t('gave its clients %s containers', array($gv['count'])) . '</span>' : '') . '</div></div>'
                . '<div class="dh-switches"></div><div class="dh-act">' . $act . '</div>';
            if ($openShare === $id) {
                $out .= '<div class="dh-more"><form method="post" action="./?module=docker_hosting&amp;tab=shares&amp;action=Grant#dh-clients">' . runtime_csfr::Token()
                    . '<input type="hidden" name="inAcc" value="' . (int) $id . '"><h4>' . self::t('Own amounts for %s', array($k['ac_user_vc'])) . '</h4><div class="dh-grid">'
                    . $fields($cur ? (int) $cur['gr_count_in'] : 0, $cur ? (int) $cur['gr_cpu_in'] : 0, $cur ? (int) $cur['gr_mem_in'] : 0, $cur ? (int) $cur['gr_disk_in'] : 0) . '</div>'
                    . $checks($cur ? dh::AppList((string) $cur['gr_apps_tx']) : $g['apps'])
                    . '<p class="dh-muted">' . self::t('Saving these takes the client off its plan. Containers 0 takes the share back.') . '</p>'
                    . '<div class="dh-foot"><a class="btn btn-default" href="./?module=docker_hosting&amp;tab=shares">' . self::t('Cancel') . '</a> <button class="btn btn-primary" type="submit">' . self::t('Save') . '</button></div></form></div>';
            }
            $out .= '</div>';
        }
        return $out . '</div></div>';
    }

    /**
     * Which customers' apps may reach the local network (administrators).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string
     */
    static function LanHtml()
    {
        global $zdbh;
        $q = $zdbh->query("SELECT ac_id_pk, ac_user_vc, ac_group_fk FROM x_accounts WHERE ac_deleted_ts IS NULL AND (ac_group_fk = 1
            OR ac_id_pk IN (SELECT gr_acc_fk FROM x_dh_grants WHERE gr_count_in > 0) OR ac_id_pk IN (SELECT dc_acc_fk FROM x_dh_containers WHERE dc_deleted_in IS NULL)) ORDER BY ac_user_vc");
        $on = dh::LanAccounts();
        $rows = '';
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $id = (int) $a['ac_id_pk'];
            $yes = in_array($id, $on, true);
            $rows .= '<div class="dh-lan-row"><form class="dh-inline dh-night" method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=Lan">' . runtime_csfr::Token()
                . '<input type="hidden" name="inAcc" value="' . $id . '"><input type="hidden" name="inLan" value="' . ($yes ? '0' : '1') . '">'
                . '<label class="sf-switch"><input type="checkbox" onchange="this.form.submit()"' . ($yes ? ' checked' : '') . ' aria-label="' . self::h($a['ac_user_vc']) . '"><span class="sf-toggle"></span></label>'
                . '<span class="dh-night-label"><b>' . self::h($a['ac_user_vc']) . '</b></span> <span class="dh-muted">'
                . self::t($yes ? 'its apps may reach the local network' : 'its apps reach the internet only') . '</span>'
                . '<noscript><button class="btn btn-sm btn-default" type="submit">' . self::t($yes ? 'Turn off' : 'Turn on') . '</button></noscript></form></div>';
        }
        return '<div class="dh-card"><h3>' . self::t('Local network') . '</h3><p class="dh-small">'
            . self::t('The apps reach the internet, but not private addresses (10.x, 172.16-31.x, 192.168.x): not your other servers, routers, or this panel by its local address. Turn it on for an account whose apps need your own network, for example your own monitoring app. It counts for all that account\'s apps, on every node (nodes with agent 108 or newer). The cloud\'s metadata address (169.254.169.254) stays closed for every app.')
            . '</p>' . ($rows !== '' ? '<div class="dh-lan">' . $rows . '</div>' : '<p class="dh-muted">' . self::t('No accounts with Docker Hosting yet.') . '</p>') . '</div>';
    }

    /**
     * Apps the nodes have that the panel does not know (administrators): who they likely belong to,
     * give each back or delete it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string
     */
    static function FoundHtml()
    {
        global $zdbh;
        $found = dh::FoundApps();
        if (!$found) {
            return '';
        }
        $accs = $zdbh->query("SELECT ac_id_pk, ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL ORDER BY ac_user_vc")->fetchAll(PDO::FETCH_ASSOC);
        $apps = dh_catalog::Apps();
        $out = '<div class="dh-card" id="dh-found"><h3>' . self::t('Found on the nodes') . '</h3><p class="dh-small">'
            . self::t('Apps a node has that this panel does not know: the node was linked again (sentora-docker-node-relink), or the panel was set up again or restored from an older copy. They keep running and nothing deletes them by itself. Give each back to its owner (it keeps its data; the node moves it under the owner\'s user when needed), or delete it.')
            . '</p><div class="dh-list">';
        foreach ($found as $fid => $f) {
            $sg = dh::Suggest($f);
            $n = dh::NodeAny((int) $f['fd_node_fk']);
            $app = $sg['app'] !== '' && isset($apps[$sg['app']]) ? $apps[$sg['app']] : null;
            $name = $app ? $app['name'] : ((string) $f['fd_image_vc'] !== '' ? (string) $f['fd_image_vc'] : self::t('An app'));
            $busy = (string) $f['fd_step_vc'] !== '';
            $steps = array('reading' => 'Reading its settings', 'moving' => 'Being moved to its owner', 'deleting' => 'Being deleted');
            $sub = ((string) $f['fd_domain_vc'] !== '' ? '<span>' . self::h($f['fd_domain_vc']) . '</span>' : '<span>' . self::t('address not known') . '</span>')
                . '<span>' . self::t('node "%s"', array($n ? $n['nd_name_vc'] : '?')) . '</span>'
                . '<span>' . self::h(sprintf('sdh-%d, user %s', $f['fd_cid_in'], $f['fd_user_vc'])) . '</span>'
                . '<span>' . self::t('%s MB of disk used', array((int) $f['fd_used_in'])) . '</span>'
                . '<span>' . self::t('found %s', array(date('Y-m-d H:i', (int) $f['fd_first_in']))) . '</span>';
            $act = '';
            $form = '';
            if (!$busy) {
                $aopt = '<option value="">' . self::t('Owner...') . '</option>';
                foreach ($accs as $a) {
                    $aopt .= '<option value="' . (int) $a['ac_id_pk'] . '"' . ((int) $a['ac_id_pk'] === $sg['acc'] ? ' selected' : '') . '>' . self::h($a['ac_user_vc']) . '</option>';
                }
                $popt = '<option value="">' . self::t('Which app...') . '</option>';
                foreach ($apps as $aid => $a) {
                    $popt .= '<option value="' . self::h($aid) . '"' . ($aid === $sg['app'] ? ' selected' : '') . '>' . self::h($a['name']) . '</option>';
                }
                $form = '<form class="dh-found-form" method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=Adopt#dh-found">' . runtime_csfr::Token()
                    . '<input type="hidden" name="inFound" value="' . $fid . '">'
                    . '<select class="form-control input-sm" name="inAcc" aria-label="' . self::t('Owner') . '">' . $aopt . '</select>'
                    . '<select class="form-control input-sm" name="inApp" aria-label="' . self::t('App') . '">' . $popt . '</select>'
                    . '<button class="btn btn-sm btn-primary" type="submit">' . self::Icon('play') . self::t($sg['back'] ? 'Give it back' : 'Give to this owner') . '</button></form>';
                $act = self::Button('FoundDelete', array('inFound' => $fid), 'Delete', 'btn-default', 'Delete this app on its node, with all its data? This can not be undone.', 'trash');
            }
            $out .= '<div class="dh-item ' . ($busy ? 'is-warn' : 'is-bad') . '"><span class="dh-avatar" aria-hidden="true">' . self::h(strtoupper(substr($name, 0, 1))) . '</span>'
                . '<div class="dh-main"><div class="dh-name"><b>' . self::h($name) . '</b><span class="dh-pill ' . ($busy ? 'is-warn' : 'is-bad') . '">'
                . self::t($busy && isset($steps[$f['fd_step_vc']]) ? $steps[$f['fd_step_vc']] : 'Not known to the panel') . '</span>'
                . '<span class="dh-pill">' . self::h(ucfirst((string) $f['fd_state_vc'])) . '</span></div>'
                . '<div class="dh-sub">' . $sub . '</div>'
                . ($sg['why'] !== '' ? '<div class="dh-muted">' . self::t($sg['why']) . '</div>' : '')
                . ((string) $f['fd_note_vc'] !== '' ? '<div class="dh-note">' . self::h($f['fd_note_vc']) . '</div>' : '')
                . $form . '</div><div class="dh-switches"></div><div class="dh-act">' . $act . '</div></div>';
        }
        return $out . '</div></div>';
    }

    /**
     * The nodes tab (administrators).
     *
     * @return string
     */
    static function NodesHtml()
    {
        $out = '';
        $nodes = dh::Nodes();
        if (self::$command !== '') {
            $out .= '<div class="dh-card"><h3>' . self::t('Install command') . '</h3><ol class="dh-small">'
                . '<li>' . self::t('On the new server (Ubuntu 26.04, Debian 13, AlmaLinux, Rocky Linux or Red Hat Enterprise Linux 10, or Fedora 45; fresh, nothing else on it, not this panel\'s server), log in and become root (su, or put sudo in front).') . '</li>'
                . '<li>' . self::t('Copy the whole command below (it is one line), paste it there and press Enter.') . '</li>'
                . '<li>' . self::t('It takes a few minutes and ends with "Done". The node then shows here as online (reload this page).') . '</li></ol>'
                . '<code class="dh-cmd">' . self::h(self::$command) . '</code><p class="dh-muted">' . self::t('The command works once, within the hour, and is not shown again: if it runs out, press "New install command" at the node.')
                . (strpos(self::$command, '--no-tls-check') !== false ? ' ' . self::t('It ends with --no-tls-check because this panel\'s certificate is self-signed; that is safe here, the node checks every message with the panel\'s own key, which is in the command.') : '') . '</p></div>';
        }
        $out .= '<div class="dh-card"><div class="dh-cardhead"><h3>' . self::t('Docker nodes') . '</h3>' . (count(self::Behind()) > 1 ? self::UpdateAllButton() : '') . '</div><p class="dh-small">' . self::t('A node is a separate server that runs the apps, never this panel\'s own. A small program on it (the agent) calls this panel every few seconds for its work, so nothing on the node has to be opened for the panel; updates, restarts and settings below reach it that way.') . '</p>';
        if ($nodes) {
            $latest = dh::Get('latest_agent');
            $openNode = isset($_GET['manage']) && ctype_digit((string) $_GET['manage']) ? (int) $_GET['manage'] : 0;
            $out .= '<div class="dh-list">';
            foreach ($nodes as $id => $n) {
                $i = dh::Info($n);
                $linked = (string) $n['nd_signpk_vc'] !== '';
                $online = dh::Online($n);
                $state = (int) $n['nd_removing_in'] === 1 ? self::t('removing itself')
                    : (!$linked ? ((string) $n['nd_claim_vc'] !== '' ? self::t('added with its key: waiting for the node to call') : ((int) $n['nd_codeexp_in'] >= time() ? self::t('waiting for its installer') : self::t('install code expired')))
                    : ($online ? self::t('online') : self::t('offline since %s', array(date('Y-m-d H:i', (int) $n['nd_seen_in'])))));
                $p = dh::Placed($id);
                $r = dh::Room($n);
                $behind = $linked && $latest !== '' && (string) $i['agent'] !== '' && version_compare((string) $i['agent'], $latest, '<');
                $sub = $linked ? '<span>' . self::t('public address: %s', array(dh::PublicIp($n) !== '' ? dh::PublicIp($n) : '?')) . '</span>'
                    . '<span>' . self::h($i['os']) . '</span><span>Podman ' . self::h($i['podman']) . '</span>'
                    . '<span>' . self::t('agent %s', array((string) $i['agent'] !== '' ? $i['agent'] : '?')) . '</span>'
                    . '<span>' . self::h(sprintf('%d CPU, %d MB, %d GB disk (%d GB free)', $i['cpus'], $i['mem_mb'], $i['disk_total_mb'] / 1024, $i['disk_free_mb'] / 1024)) . '</span>'
                    . ($i['selinux'] !== '' ? '<span>SELinux ' . self::h($i['selinux']) . '</span>' : '')
                    . ($i['selinux'] === '' && isset($i['apparmor']) && $i['apparmor'] !== '' ? '<span>AppArmor ' . self::h($i['apparmor']) . '</span>' : '')
                    . '<span>' . self::t('%s apps; room for %s CPU, %s MB, %s GB', array($p['count'], dh::Cpu(max(0, $r['cpu'])), max(0, $r['mem']), round(max(0, $r['disk']) / 1024, 1))) . '</span>' : '';
                $act = '';
                $more = '';
                if ($linked && (int) $n['nd_removing_in'] === 0) {
                    if ($behind) {
                        $act .= self::NodeButton($id, 'node-update', 'Update now', 'btn-primary', '', 'update');
                    }
                    $act .= '<a class="btn btn-sm btn-default' . ($openNode === $id ? ' active' : '') . '" href="./?module=docker_hosting&amp;tab=nodes' . ($openNode === $id ? '' : '&amp;manage=' . $id . '#dh-node-' . $id) . '">' . self::Icon('gear') . self::t('Manage') . '</a>';
                    if ($openNode === $id) {
                        $more = '<div class="dh-more">' . self::NodeManage($id, $n, $i, $latest) . '</div>';
                    }
                } elseif (!$linked && (string) $n['nd_claim_vc'] === '') {
                    $act .= self::Button('NodeCode', array('inId' => $id), 'New install command', 'btn-primary');
                }
                $act .= self::RemoveNodeButton($id, $n);
                $pill = $online ? 'is-ok' : ($linked ? 'is-bad' : 'is-warn');
                $out .= '<div class="dh-item ' . $pill . '" id="dh-node-' . $id . '"><span class="dh-avatar" aria-hidden="true">' . self::Icon('server') . '</span>'
                    . '<div class="dh-main"><div class="dh-name"><b>' . self::h($n['nd_name_vc']) . '</b><span class="dh-pill ' . $pill . '">' . $state . '</span>'
                    . ($behind ? '<span class="dh-pill is-warn">' . self::t('Update available: %s', array(self::h($latest))) . '</span>' : '') . '</div>'
                    . ($sub !== '' ? '<div class="dh-sub">' . $sub . '</div>' : '') . '</div>'
                    . '<div class="dh-switches"></div><div class="dh-act">' . $act . '</div>' . $more . '</div>';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
        $out .= self::FoundHtml();
        $out .= self::LanHtml();
        // adding a node: the node makes its key, the administrator pastes it
        $fp = dh::Fingerprint();
        $out .= '<div class="dh-card"><h3>' . self::t('Add a Docker node') . '</h3><ol class="dh-small">'
            . '<li>' . self::t('On the new server (Ubuntu 26.04, Debian 13, AlmaLinux, Rocky Linux or Red Hat Enterprise Linux 10, or Fedora 45; fresh, nothing else on it, not this panel\'s server), log in, become root and run:') . '<code class="dh-cmd">curl -fsSL ' . self::h(dh::NODE_INSTALLER) . ' -o sentora2027-docker-node &amp;&amp; sudo bash sentora2027-docker-node</code></li>'
            . '<li>' . self::t('It asks where your Sentora panel is: type this panel\'s address as that server reaches it (on a home or office network usually its IP address, like 172.16.1.250).') . '</li>'
            . '<li>' . self::t('It shows a code the panel answered with. This panel\'s code is %s: answer y only when the two are the same (otherwise something is pretending to be your panel).', array($fp)) . '</li>'
            . '<li>' . self::t('It ends with the node\'s key (SN1-...). Give the node a name below and paste that key. The node shows online a few seconds later.') . '</li></ol>'
            . '<div class="dh-msg is-ok">' . self::t('This panel\'s code:') . ' <b class="dh-fp">' . self::h($fp) . '</b></div>'
            . '<form method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=AddNode">' . runtime_csfr::Token() . '<div class="dh-grid">'
            . '<label class="dh-field"><span>' . self::t('A name for the node') . '</span><input type="text" class="form-control" name="inName" placeholder="node1" maxlength="60" required>'
            . '<small>' . self::t('Only a label for this list, for example node1 or the server\'s host name.') . '</small></label>'
            . '<label class="dh-field"><span>' . self::t('The node\'s key') . '</span><input type="text" class="form-control" name="inKey" placeholder="SN1-ABCD-EFGH-JKLM-NPQR-STUV-WXYZ" maxlength="60" autocomplete="off" required>'
            . '<small>' . self::t('Shown at the end of the node\'s installer, and kept on the node in /root/sentora-docker-node-key.txt until it is linked.') . '</small></label>'
            . '</div><div class="dh-foot"><span></span><button class="btn btn-primary" type="submit">' . self::t('Add this node') . '</button></div></form>'
            . '<details class="dh-small"><summary>' . self::t('The other way: a one-time install command (for scripted installs)') . '</summary>'
            . '<form method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=AddNode">' . runtime_csfr::Token() . '<div class="dh-grid">'
            . '<label class="dh-field"><span>' . self::t('A name for the new node') . '</span><input type="text" class="form-control" name="inName" placeholder="node1" maxlength="60" required></label>'
            . '<label class="dh-field"><span>' . self::t('This panel\'s address, as the node reaches it') . '</span><input type="text" class="form-control" name="inUrl" value="' . self::h(dh::Get('panel_url')) . '" placeholder="' . self::h('https://' . ctrl_options::GetSystemOption('sentora_domain')) . '">'
            . '<small>' . self::t('The node calls the panel at this address. Empty: %s. If the node can not look that name up, type this panel\'s IP address instead, for example https://172.16.1.250.', array('https://' . ctrl_options::GetSystemOption('sentora_domain'))) . '</small></label>'
            . '</div><div class="dh-foot"><span class="dh-muted">' . self::t('You get a command to run on the new server, within the hour.') . '</span><button class="btn btn-default" type="submit">' . self::t('Add and show its install command') . '</button></div></form></details></div>';
        $enabled = dh::EnabledApps();
        $checks = '';
        foreach (dh_catalog::Apps() as $id => $a) {
            $checks .= '<label><input type="checkbox" name="inApps[]" value="' . self::h($id) . '"' . (in_array($id, $enabled, true) ? ' checked' : '') . '> ' . self::h($a['name']) . ' <span class="dh-muted">' . self::h($a['image']) . '</span>' . (!empty($a['custom']) ? ' <span class="dh-pill">' . self::t('your own') . '</span>' : '') . '</label>';
        }
        $out .= self::OwnAppsHtml();
        $out .= self::RegistriesHtml();
        $out .= '<div class="dh-card"><h3>' . self::t('Settings') . '</h3><form method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=Settings">' . runtime_csfr::Token()
            . '<div class="dh-field"><span>' . self::t('Apps offered') . '</span><div class="dh-apps">' . $checks . '</div><small>' . self::t('The apps resellers and clients may choose from (they only see the ones their share allows). Unticking an app stops new ones being started; apps already running keep running.') . '</small></div>'
            . '<div class="dh-foot"><span></span><button class="btn btn-primary" type="submit">' . self::t('Save') . '</button></div></form></div>';
        return $out;
    }

    /**
     * The administrators' own apps: the list, change or remove one, add one.
     *
     * @return string
     */
    static function OwnAppsHtml()
    {
        $own = dh_catalog::Custom();
        $out = '<div class="dh-card" id="dh-own"><h3>' . self::t('Your own apps') . '</h3>'
            . '<p>' . self::t('Besides the apps that come with Docker Hosting, you can add any app that is published as a container image (on Docker Hub, GitHub\'s ghcr.io, Quay and so on). It is then offered like the others: to you at once, to resellers and clients when you tick it in their share.') . '</p>'
            . '<p class="dh-small"><b>' . self::t('Only add images you trust.') . '</b> ' . self::t('The image is a program the node downloads and runs for your customers, with their data in it. Take it from its maker\'s own page, and prefer a version tag (like :1 or :2.4) over :latest, so an update does not bring a big change by surprise. Whatever the image is, it runs with the same limits as every app: under the customer\'s own Linux user (never root), with no extra privileges, within its CPU, memory and disk, and reachable only through its web address (the node\'s proxy, with the setup password until the customer opens it to the public).') . '</p>';
        if ($own) {
            // card rows as the other lists (nodes, plans, shares); Change opens the form under its row
            $openApp = isset($_GET['app']) && is_string($_GET['app']) ? $_GET['app'] : '';
            $out .= '<div class="dh-list">';
            foreach ($own as $id => $a) {
                $users = dh::AppUsers($id);
                $open = $openApp === $id || self::$appForm === $id;
                $anchor = 'dh-app-' . preg_replace('/[^a-z0-9-]/', '', strtolower($id));
                $out .= '<div class="dh-item is-ok" id="' . $anchor . '"><span class="dh-avatar" aria-hidden="true">' . self::h(mb_strtoupper(mb_substr($a['name'], 0, 1))) . '</span>'
                    . '<div class="dh-main"><div class="dh-name"><b>' . self::h($a['name']) . '</b><span class="dh-pill' . ($users > 0 ? ' is-ok' : '') . '">' . ($users === 0 ? self::t('Not in use') : self::t($users === 1 ? 'In use by 1 container' : 'In use by %s containers', array($users))) . '</span></div>'
                    . '<div class="dh-sub"><code>' . self::h($a['image']) . '</code><span>' . self::t('port %s', array((int) $a['port'])) . '</span>'
                    . '<span>' . ($a['volumes'] ? self::t('data in %s', array(self::h(implode(', ', $a['volumes'])))) : self::t('keeps no data')) . '</span></div>'
                    . ($a['desc'] !== '' ? '<div class="dh-muted">' . self::h($a['desc']) . '</div>' : '') . '</div>'
                    . '<div class="dh-switches"></div><div class="dh-act">'
                    . '<a class="btn btn-sm btn-default' . ($open ? ' active' : '') . '" href="./?module=docker_hosting&amp;tab=nodes' . ($open ? '#dh-own' : '&amp;app=' . rawurlencode($id) . '#' . $anchor) . '">' . self::Icon('gear') . self::t('Change') . '</a>'
                    . ($users === 0 ? '<form class="dh-inline" method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=AppRemove" onsubmit="return confirm(this.dataset.ask)" data-ask="' . self::t('Remove this app from the list? (No one runs it.)') . '">' . runtime_csfr::Token()
                        . '<input type="hidden" name="inId" value="' . self::h($id) . '"><button type="submit" class="btn btn-sm btn-default dh-del" title="' . self::t('Remove') . '" aria-label="' . self::t('Remove') . '">' . self::Icon('trash') . '</button></form>' : '')
                    . '</div>' . ($open ? '<div class="dh-more">' . self::AppForm($id, $a, $users) . '</div>' : '') . '</div>';
            }
            $out .= '</div>';
        }
        $blank = array('name' => '', 'desc' => '', 'image' => '', 'port' => '', 'volumes' => array(), 'env' => array(), 'minmem' => 128, 'mem' => 256, 'cpu' => 50, 'disk' => 1024, 'web' => '');
        $run = '<form class="dh-fromrun" method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=AppFromRun#dh-own">' . runtime_csfr::Token()
            . '<label class="dh-field"><span>' . self::t('Have a docker run line from the image\'s page? (optional)') . '</span>'
            . '<textarea class="form-control dh-envbox" name="inRun" rows="3" spellcheck="false" placeholder="docker run -d -p 9090:9090 -v ./data:/etc/linkding/data ghcr.io/sissbruecker/linkding:1"></textarea>'
            . '<small>' . self::t('Paste it and press Fill in: the form below is filled from it (image, port, data folders, settings). It is only read, never run, and nothing is saved until you press Add the app. What a node never allows (privileged, the host\'s network or folders, devices) is left out and named.') . '</small></label>'
            . '<div class="dh-foot"><button class="btn btn-default" type="submit">' . self::t('Fill in') . '</button></div></form>';
        return $out . '<details class="dh-add"' . (!$own || self::$appForm === '' ? ' open' : '') . '><summary class="btn btn-default">' . self::t('Add an app') . '</summary>' . $run . self::AppForm('', $blank, 0) . '</details></div>';
    }

    /**
     * Registry logins: for private images, and for Docker Hub's higher download limit (administrators).
     *
     * @return string
     */
    static function RegistriesHtml()
    {
        $out = '<div class="dh-card" id="dh-registries"><h3>' . self::t('Registry logins') . '</h3>'
            . '<p class="dh-small">' . self::t('For images that need a login: a private image on Docker Hub, GitHub (ghcr.io), Quay or your own registry. A Docker Hub login also raises how often the nodes may download from Docker Hub. Use a token that may only read (pull) images, not your account\'s password.') . '</p>'
            . '<p class="dh-small">' . self::t('Kept encrypted on this panel and never shown again. It goes to a node, sealed, only to download an image from that registry: there it is written to a file only that customer\'s user can read, used for that one download and deleted. The nightly check for newer versions can not see private images; Update still works.') . '</p>'
            . '<p class="dh-small">' . self::t('A registry on your own network (a private address such as 192.168.x or 10.x) is reached only for customers whose Local network is on (above); one with a public address works for everyone.') . '</p>';
        $all = dh::Registries();
        if ($all) {
            $out .= '<div class="dh-list">';
            foreach ($all as $host => $r) {
                $out .= '<div class="dh-item is-ok"><span class="dh-avatar" aria-hidden="true">' . self::Icon('server') . '</span>'
                    . '<div class="dh-main"><div class="dh-name"><b>' . self::h($host) . '</b></div>'
                    . '<div class="dh-sub"><span>' . self::t('user %s', array($r['user'])) . '</span><span>' . self::t('added %s', array(date('Y-m-d', (int) $r['at']))) . '</span></div></div>'
                    . '<div class="dh-switches"></div><div class="dh-act">'
                    . self::Button('RegistryRemove', array('inHost' => $host), 'Remove', 'btn-default', 'Remove this login? Images from this registry are then downloaded without it.', 'trash') . '</div></div>';
            }
            $out .= '</div>';
        }
        return $out . '<details class="dh-add"' . (!$all ? ' open' : '') . '><summary class="btn btn-default">' . self::t('Add a login') . '</summary>'
            . '<form method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=Registry#dh-registries" autocomplete="off">' . runtime_csfr::Token()
            . '<div class="dh-grid">'
            . '<label class="dh-field"><span>' . self::t('Registry') . '</span><input type="text" class="form-control" name="inHost" maxlength="255" required placeholder="docker.io"><small>' . self::t('As images name it: docker.io (Docker Hub), ghcr.io, quay.io, or your own.') . '</small></label>'
            . '<label class="dh-field"><span>' . self::t('User name') . '</span><input type="text" class="form-control" name="inUser" maxlength="255" required autocomplete="off"></label>'
            . '<label class="dh-field"><span>' . self::t('Token') . '</span><input type="password" class="form-control" name="inSecret" maxlength="4096" required autocomplete="new-password"><small>' . self::t('A read-only (pull) token is best. Saving again for the same registry replaces it.') . '</small></label>'
            . '</div><div class="dh-foot"><button class="btn btn-primary" type="submit">' . self::t('Save the login') . '</button></div></form></details></div>';
    }

    /**
     * The form for one app of one's own ($id '' = a new one).
     *
     * @param string $id
     * @param array $a
     * @param int $users Containers using it (its port and folders are then fixed).
     * @return string
     */
    static function AppForm($id, array $a, $users)
    {
        $v = array('name' => $a['name'], 'desc' => $a['desc'], 'image' => $a['image'], 'port' => (string) $a['port'], 'volumes' => implode("\n", $a['volumes']), 'web' => $a['web'],
            'minmem' => (string) $a['minmem'], 'mem' => (string) $a['mem'], 'cpu' => dh::Cpu($a['cpu']), 'disk' => (string) ($a['disk'] / 1024), 'env' => '');
        foreach ($a['env'] as $k => $val) {
            $v['env'] .= $k . '=' . $val . "\n";
        }
        if (self::$appForm === $id) {
            // refused: show what was typed
            foreach (array('name' => 'inName', 'desc' => 'inDesc', 'image' => 'inImage', 'port' => 'inPort', 'volumes' => 'inVolumes', 'web' => 'inWeb', 'minmem' => 'inMinMem', 'mem' => 'inMem', 'cpu' => 'inCpu', 'disk' => 'inDisk', 'env' => 'inEnv') as $k => $f) {
                $v[$k] = isset($_POST[$f]) && is_string($_POST[$f]) ? $_POST[$f] : '';
            }
        }
        $fixed = $users > 0 ? ' readonly' : '';
        $in = function ($name, $key, $label, $help, $extra = '') use ($v) {
            return '<label class="dh-field"><span>' . self::t($label) . '</span><input type="text" class="form-control" name="' . $name . '" value="' . self::h($v[$key]) . '"' . $extra . '><small>' . self::t($help) . '</small></label>';
        };
        return '<form class="dh-appform" method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=App#dh-own" autocomplete="off">' . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . self::h($id) . '"><div class="dh-grid">'
            . '<fieldset><legend>' . self::t('What it is') . '</legend>'
            . $in('inName', 'name', 'Name', 'What your customers see in the list of apps, for example Plausible or Linkding.', ' maxlength="60" required')
            . $in('inDesc', 'desc', 'What it does (one line)', 'Shown under "Start an app", so a customer knows what they are picking.', ' maxlength="255"')
            . $in('inImage', 'image', 'Image', 'Its full name with the registry, as its maker gives it, for example docker.io/library/nginx:1 or ghcr.io/linkding/linkding:1. (On Docker Hub, "nginx" alone is docker.io/library/nginx, and "maker/app" is docker.io/maker/app.)', ' maxlength="300" required placeholder="docker.io/maker/app:1"')
            . $in('inPort', 'port', 'Port inside the container', 'The port the app\'s web page listens on inside its container; the image\'s page says which (often 80, 3000 or 8080). Customers never see it: the node puts the app\'s web address in front of it.', ' maxlength="5" required placeholder="8080"' . $fixed)
            . $in('inWeb', 'web', 'The maker\'s web page (optional)', 'For you, to find its instructions again.', ' maxlength="200" placeholder="https://"')
            . '</fieldset><fieldset><legend>' . self::t('Data and settings') . '</legend>'
            . '<label class="dh-field"><span>' . self::t('Data folders') . '</span><textarea class="form-control" name="inVolumes" rows="3" placeholder="/data"' . $fixed . '>' . self::h($v['volumes']) . '</textarea>'
            . '<small>' . self::t('The folders inside the container where the app keeps what must survive a restart or an update, one per line (the image\'s page names them, often as "volumes", for example /data or /var/lib/app). Each gets a store of its own on the node, counted against the disk limit. Leave empty if the app keeps nothing.') . '</small></label>'
            . '<label class="dh-field"><span>' . self::t('Settings (environment variables)') . '</span><textarea class="form-control" name="inEnv" rows="4" placeholder="APP_URL=https://{domain}" spellcheck="false">' . self::h($v['env']) . '</textarea>'
            . '<small>' . self::t('One per line, NAME=value, as the image\'s page lists them. {domain} becomes the app\'s own address (like app.example.com). {secret} becomes a long random value of that container\'s own, the same after every update: use it where the app wants a secret key. Do not type real passwords here: every customer\'s app would get the same one.') . '</small></label>'
            . '</fieldset><fieldset><legend>' . self::t('Limits') . '</legend>'
            . $in('inMinMem', 'minmem', 'Least memory (MB)', 'Below this the app does not work; a customer can not give it less.', ' maxlength="6" required')
            . $in('inMem', 'mem', 'Suggested memory (MB)', 'Filled in when a customer picks the app (they can change it).', ' maxlength="6" required')
            . $in('inCpu', 'cpu', 'Suggested CPU', '1 = one full CPU core, 0.5 = half of one.', ' maxlength="6" required')
            . $in('inDisk', 'disk', 'Suggested disk (GB)', 'Room for its data folders.', ' maxlength="8" required')
            . '</fieldset></div>'
            . ($users > 0 ? '<p class="dh-small">' . self::t('%s containers use this app, so its port and data folders stay as they are. A new image or settings reach them when their owner presses Update (or by itself at night, where automatic updates are on).', array($users)) . '</p>' : '')
            . '<div class="dh-foot"><span class="dh-muted">' . self::t('The panel checks what you typed; the node checks it again before it runs anything.') . '</span><button class="btn btn-primary" type="submit">' . self::t($id === '' ? 'Add the app' : 'Save') . '</button></div></form>';
    }

    /**
     * A node's job button (Update now, Install security updates...). While that job waits for the node it
     * shows "Queued" (as Repo Browser does), and "In progress" once the node has picked it up.
     *
     * @param int $id the node
     * @param string $what node-update, sys-security, sys-all, reboot or check-updates
     * @return string
     */
    static function NodeButton($id, $what, $label, $class = 'btn-default', $ask = '', $icon = '')
    {
        static $waiting = array();
        if (!isset($waiting[$id])) {
            $waiting[$id] = dh::NodeJobs((int) $id);
        }
        $action = $what === 'sys-security' || $what === 'sys-all' ? 'sys-update' : $what;
        if (isset($waiting[$id][$action])) {
            $sent = $waiting[$id][$action] === 'sent';
            return '<button class="btn btn-sm btn-default" type="button" disabled="disabled" title="'
                . self::t($sent ? 'The node is doing this now; the page shows the result when it is done.' : 'Waiting for the node: it picks this up the next time it calls (within a few seconds).') . '">'
                . self::t($sent ? '%s (in progress)' : '%s (queued)', array(ui_language::translate($label))) . '</button>';
        }
        return self::Button('NodeAct', array('inId' => $id, 'inWhat' => $what), $label, $class, $ask, $icon);
    }

    /**
     * A switch in a node's Manage (Nightly security updates, Nightly node updates).
     *
     * @param int $id the node
     * @param string $what autosec or autoagent
     * @param bool $on
     * @param string $label
     * @param string $note
     * @return string
     */
    static function Switch($id, $what, $on, $label, $note)
    {
        $field = 'in' . ucfirst($what);
        return '<form class="dh-inline dh-night" method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=NodeAct">' . runtime_csfr::Token()
            . '<input type="hidden" name="inId" value="' . (int) $id . '"><input type="hidden" name="inWhat" value="' . self::h($what) . '">'
            . '<input type="hidden" name="' . $field . '" value="' . ($on ? '0' : '1') . '">'
            . '<label class="sf-switch"><input type="checkbox" onchange="this.form.submit()"' . ($on ? ' checked' : '') . ' aria-label="' . self::t($label) . '"><span class="sf-toggle"></span></label>'
            . '<span class="dh-night-label">' . self::t($label) . '</span> <span class="dh-muted">' . self::t($note) . '</span>'
            . '<noscript><button class="btn btn-sm btn-default" type="submit">' . self::t($on ? 'Turn off' : 'Turn on') . '</button></noscript></form>';
    }

    /**
     * A linked node's management: updates, restart, settings, address.
     *
     * @param int $id
     * @param array $n
     * @param array $i Its report.
     * @param string $latest The newest agent's version ('' when not known).
     * @return string
     */
    static function NodeManage($id, array $n, array $i, $latest)
    {
        $st = dh::NodeSettings($n);
        $agentOld = $latest !== '' && (string) $i['agent'] !== '' && version_compare((string) $i['agent'], $latest, '<');
        $sysLine = (int) $i['sys_checked_at'] > 0
            ? self::t('%s updates waiting, %s of them security fixes (looked %s).', array((int) $i['sys_updates'], (int) $i['sys_security'], date('Y-m-d H:i', (int) $i['sys_checked_at'])))
            : self::t('Not looked at yet (the node looks every 6 hours, or press "Look for updates now").');
        if ((string) $i['sys_running'] !== '') {
            $sysLine .= ' ' . self::t('Installing updates right now.');
        } elseif ((int) $i['sys_last_at'] > 0) {
            $sysLine .= ' ' . self::t('Last update run: %s, %s.', array(date('Y-m-d H:i', (int) $i['sys_last_at']), (int) $i['sys_last_ok'] === 1 ? self::t('it went well') : self::t('it failed (see /var/log/sentora-docker-node-sysupdate.log on the node)')));
        }
        if ((int) $i['sys_reboot'] === 1) {
            $sysLine .= ' ' . self::t('A restart is needed to finish them.');
        }
        return '<div class="dh-small">'
            . '<h4>' . self::t('The node\'s agent') . '</h4><p>' . self::t('Version %s', array($i['agent'])) . ($latest !== '' ? ' (' . ($agentOld ? self::t('%s is out', array($latest)) : self::t('the newest')) . ')' : '') . '. '
            . self::t('Updating the node updates only Sentora\'s own software on it (its agent and setup); Podman, Caddy, PHP and the rest of the system come with the system\'s updates below. The apps keep running.') . '</p>'
            . ($agentOld ? self::NodeButton($id, 'node-update', 'Update now', 'btn-primary', '', 'update')
                : self::NodeButton($id, 'node-update', 'Update node', 'btn-default'))
            . self::Switch($id, 'autoagent', (int) $n['nd_autoagent_in'] === 1, 'Nightly node updates', '(installed by themselves between 02:00 and 03:00 when a new version is out)')
            . '<h4>' . self::t('The node\'s system') . '</h4><p>' . $sysLine . '</p>'
            . self::NodeButton($id, 'check-updates', 'Look for updates now')
            . self::NodeButton($id, 'sys-security', 'Install security updates', (int) $i['sys_security'] > 0 ? 'btn-primary' : 'btn-default')
            . self::NodeButton($id, 'sys-all', 'Install all updates')
            . self::NodeButton($id, 'reboot', 'Restart node', (int) $i['sys_reboot'] === 1 ? 'btn-warning' : 'btn-default',
                'Restart this node? Its apps are down for a minute or two and start again by themselves.')
            . self::Switch($id, 'autosec', (int) $n['nd_autosec_in'] === 1, 'Nightly security updates', '(installed by themselves between 03:00 and 05:00)')
            . self::NodeApps($id)
            . '<h4>' . self::t('Certificates') . '</h4>'
            . '<form method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=NodeSettings">' . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . $id . '">'
            . '<label class="dh-check"><input type="radio" name="inLocal" value="0"' . ($st['local_certs'] ? '' : ' checked') . '> <span>' . self::t('Let\'s Encrypt (for apps on real domains: the app\'s name must point to this node from the internet, and ports 80 and 443 must reach it)') . '</span></label>'
            . '<label class="dh-check"><input type="radio" name="inLocal" value="1"' . ($st['local_certs'] ? ' checked' : '') . '> <span>' . self::t('The node\'s own certificates (for a test or home network; browsers warn once, as with a self-signed panel)') . '</span></label>'
            . '<label class="dh-field"><span>' . self::t('Email for Let\'s Encrypt (optional)') . '</span><input type="text" class="form-control" name="inEmail" value="' . self::h($st['email']) . '" placeholder="you@example.com"><small>' . self::t('Let\'s Encrypt writes here when a certificate is about to run out and could not be renewed.') . '</small></label>'
            . '<button class="btn btn-sm btn-default" type="submit">' . self::t('Save and send to the node') . '</button></form>'
            . '<h4>' . self::t('Address and new apps') . '</h4>'
            . '<form method="post" action="./?module=docker_hosting&amp;tab=nodes&amp;action=Node">' . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . $id . '">'
            . '<label class="dh-field"><span>' . self::t('Public address') . '</span><input type="text" class="form-control" name="inIp" value="' . self::h($n['nd_publicip_vc']) . '" placeholder="' . self::h($n['nd_ip_vc']) . '">'
            . '<small>' . self::t('The IP address the apps\' DNS records point to, the one visitors reach. Empty: the address the node calls from. Fill it in when the node is behind a router (NAT) with another public address.') . '</small></label>'
            . '<label class="dh-check"><input type="checkbox" name="inAccepting" value="1"' . ((int) $n['nd_accepting_in'] === 1 ? ' checked' : '') . '> <span>' . self::t('Takes new apps (untick to send no new apps here; the ones on it keep running)') . '</span></label>'
            . '<button class="btn btn-sm btn-default" type="submit">' . self::t('Save') . '</button></form>'
            . '<h4>' . self::t('Linking it again') . '</h4><p>' . self::t('When this panel moves to another address, or the node no longer reaches it after the panel was set up again: on the node, as root, run sentora-docker-node-relink --panel <this panel\'s address> (add --new-key when the panel no longer knows the node, then add it here with the key it shows). The apps on the node keep running with their data; ones the panel does not know show under "Found on the nodes".') . '</p>'
            . (!dh::Online($n) && (int) $n['nd_seen_in'] < time() - 600
                ? '<h4>' . self::t('Gone for good?') . '</h4><p>' . self::t('If this server is lost (broken, deleted) and will not come back, its apps can be started on the other nodes: fresh, without their old data (that was on this server), with the same addresses and settings; DNS records the panel added point to the new node. If the server does come back later, its old copies show under "Found on the nodes".') . '</p>'
                    . self::Button('Rehome', array('inId' => $id), 'Start its apps on other nodes', 'btn-warning', 'Start this node\'s apps on the other nodes, without their old data? Do this only when this server will not come back.')
                : '')
            . '</div>';
    }

    /**
     * The log tab: an administrator sees everything, others what concerns their account.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $me
     * @param bool $admin
     * @return string
     */
    static function LogHtml($me, $admin)
    {
        global $zdbh;
        if ($admin) {
            $q = $zdbh->query("SELECT l.*, a.ac_user_vc FROM x_dh_log l LEFT JOIN x_accounts a ON a.ac_id_pk = l.lg_acc_fk ORDER BY l.lg_id_pk DESC LIMIT 200");
        } else {
            $q = $zdbh->prepare("SELECT l.*, a.ac_user_vc FROM x_dh_log l LEFT JOIN x_accounts a ON a.ac_id_pk = l.lg_acc_fk WHERE l.lg_acc_fk = :m OR (l.lg_by_fk = :m AND l.lg_node_fk = 0) ORDER BY l.lg_id_pk DESC LIMIT 200");
            $q->execute(array(':m' => $me));
        }
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $out = '<div class="dh-card"><h3>' . self::t('Log') . '</h3>';
        if (!$rows) {
            return $out . '<p class="dh-muted">' . self::t('Nothing yet.') . '</p></div>';
        }
        $out .= '<table class="dh-table"><tr><th>' . self::t('When') . '</th><th>' . self::t('Account') . '</th><th>' . self::t('What') . '</th></tr>';
        foreach ($rows as $r) {
            $out .= '<tr><td class="dh-nowrap">' . self::h(date('Y-m-d H:i', (int) $r['lg_time_in'])) . '</td><td>' . self::h((string) $r['ac_user_vc']) . '</td><td>' . self::h($r['lg_note_vc']) . '</td></tr>';
        }
        return $out . '</table></div>';
    }
}
