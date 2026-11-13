<?php

/**
 * Docker Hosting's app backups (125): an app's data folders, copied from its node to the panel, and back.
 *
 * Nodes never talk to each other and nothing on a node is opened for the panel, so a copy travels in
 * pieces inside the nodes' own sealed messages (dh_proto, at most 4 MB each): the node sends 'put'
 * pieces of a backup and fetches 'get' pieces of a restore. Every piece belongs to one transfer, known
 * by a random token the panel gave that node's job; another node, or another app, gets nothing.
 *
 *  - Backup: the app stops while its data is read (so the copy is whole) and runs again. The panel
 *    keeps the newest backup of each app in DIR, which the off-server backup carries to the backup
 *    server every night (it keeps the older nights). Offered only while the off-server backup goes to
 *    a Sentora backup node (owner, 2026-11-12): the panel alone is no place to keep them.
 *  - Restore: the newest backup over the app's data; the node keeps today's data aside and puts it
 *    back when anything fails.
 *  - Move (administrators): a backup that stays on the panel only while it is put on another node;
 *    the app is made there with the same version and data, its address and DNS record follow, and
 *    the old copy goes only once the new one runs. Needs no backup node.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-12
 */
class dh_backup
{
    /** One piece (the node sends and takes no bigger ones). */
    const PIECE = 2097152;

    /** Free room the panel keeps on its disk: no piece is taken below it. */
    const RESERVE_MB = 2048;

    /** A transfer that has not moved on for this long is given up. */
    const STALE = 21600;

    /** The off-server backup's own state (written by the core's sentora-offsite-backup). */
    const OFFSITE_STATE = '/var/lib/sentora/offsite-backup.json';

    /**
     * How many data folders an app has.
     *
     * @param array $c
     * @return int
     */
    static function Vols(array $c)
    {
        $a = dh_catalog::App((string) $c['dc_app_vc']);
        return $a ? count($a['volumes']) : 0;
    }

    /**
     * Where the backups are kept: the panel's backup folder, which the off-server backup copies.
     *
     * @return string
     */
    static function Dir()
    {
        $d = getenv('DH_BACKUP_DIR');
        return $d !== false && $d !== '' ? rtrim($d, '/') : '/var/sentora/backups/docker-apps';
    }

    /**
     * The table, and the switch for each app.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dh_backups (
            bk_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, bk_ct_fk INT NOT NULL, bk_node_fk INT NOT NULL, bk_kind_vc VARCHAR(10) NOT NULL DEFAULT 'backup',
            bk_state_vc VARCHAR(12) NOT NULL DEFAULT 'queued', bk_token_vc CHAR(32) NOT NULL, bk_files_tx TEXT NULL, bk_size_bi BIGINT NOT NULL DEFAULT 0,
            bk_image_vc VARCHAR(255) NOT NULL DEFAULT '', bk_was_vc VARCHAR(20) NOT NULL DEFAULT '', bk_to_fk INT NOT NULL DEFAULT 0, bk_port_in INT NOT NULL DEFAULT 0,
            bk_by_fk INT NOT NULL DEFAULT 0, bk_note_vc VARCHAR(255) NOT NULL DEFAULT '', bk_created_in INT NOT NULL, bk_moved_in INT NOT NULL DEFAULT 0, bk_done_in INT NULL,
            KEY bk_ct (bk_ct_fk), KEY bk_token (bk_token_vc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        dh::AddColumns('x_dh_containers', array('dc_backup_in' => "TINYINT NOT NULL DEFAULT 1"));
    }

    /**
     * Whether app backups can be offered: '' when they can, else why not (in words).
     *
     * @return string
     */
    static function Unavailable()
    {
        if (ctrl_options::GetSystemOption('offsite_enabled') !== 'true') {
            return 'App backups need the off-server backup switched on, to a Sentora backup node (Admin > Backup Config > Off-server backup).';
        }
        $f = getenv('DH_OFFSITE_STATE') ?: self::OFFSITE_STATE;
        $st = json_decode((string) @file_get_contents($f), true);
        if (!is_array($st) || !isset($st['gate']) || (int) $st['gate'] !== 1) {
            return 'App backups need the off-server backup to go to a Sentora backup node (Admin > Backup Config > Off-server backup: press Test connection once it is set up; panel 2027.85 or newer).';
        }
        return '';
    }

    /**
     * The backups and transfers of one app, newest first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $cid
     * @return array
     */
    static function Of($cid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_dh_backups WHERE bk_ct_fk = :c ORDER BY bk_id_pk DESC");
        $q->execute(array(':c' => (int) $cid));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The newest backup kept of an app, or null.
     *
     * @param int $cid
     * @return array|null
     */
    static function Newest($cid)
    {
        foreach (self::Of($cid) as $b) {
            if ($b['bk_kind_vc'] === 'backup' && in_array($b['bk_state_vc'], array('stored', 'restoring'), true)) {
                return $b;
            }
        }
        return null;
    }

    /**
     * A transfer of this app still under way, or null.
     *
     * @param int $cid
     * @return array|null
     */
    static function Busy($cid)
    {
        foreach (self::Of($cid) as $b) {
            if (in_array($b['bk_state_vc'], array('queued', 'sending', 'restoring'), true)) {
                return $b;
            }
        }
        return null;
    }

    /**
     * Whether the app may be changed by this account (its owner, or an administrator).
     *
     * @param array|null $c
     * @param int $by
     * @return bool
     */
    static function Mine($c, $by)
    {
        return $c && $c['dc_deleted_in'] === null && ((int) $c['dc_acc_fk'] === (int) $by || dh::IsAdmin(dh::Account($by)));
    }

    /**
     * Back an app up now (its owner or an administrator; also the nightly run, $by 0).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $cid
     * @param int $by
     * @return bool
     */
    static function Start($cid, $by)
    {
        global $zdbh;
        $c = dh::Container($cid);
        if ($by > 0 && !self::Mine($c, $by)) {
            return dh::No('No such app.');
        }
        if (!$c || $c['dc_deleted_in'] !== null) {
            return dh::No('No such app.');
        }
        $why = self::Unavailable();
        if ($why !== '') {
            return dh::No($why);
        }
        if (!self::Steady($c)) {
            return false;
        }
        $token = bin2hex(random_bytes(16));
        $zdbh->prepare("INSERT INTO x_dh_backups (bk_ct_fk, bk_node_fk, bk_kind_vc, bk_state_vc, bk_token_vc, bk_by_fk, bk_created_in, bk_moved_in) VALUES (:c, :n, 'backup', 'queued', :t, :b, :w, :w)")
            ->execute(array(':c' => (int) $cid, ':n' => (int) $c['dc_node_fk'], ':t' => $token, ':b' => (int) $by, ':w' => time()));
        $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'saving' WHERE dc_id_pk = :c")->execute(array(':c' => (int) $cid));
        dh::Queue((int) $c['dc_node_fk'], (int) $cid, 'backup', array_merge(dh::Who($c), array('token' => $token)));
        dh::Log('backup', $c['dc_domain_vc'] . ': backing up its data', (int) $c['dc_acc_fk'], (int) $by, (int) $cid, (int) $c['dc_node_fk']);
        return true;
    }

    /**
     * Whether an app is in a state to be copied (not being made, changed, moved or copied already).
     *
     * @param array $c
     * @return bool
     */
    static function Steady(array $c)
    {
        if (!in_array((string) $c['dc_state_vc'], array('running', 'stopped', 'exited', 'created'), true)) {
            return dh::No('The app is busy (' . $c['dc_state_vc'] . '): try again when it is running or stopped.');
        }
        if (self::Busy((int) $c['dc_id_pk'])) {
            return dh::No('A backup, restore or move of this app is already under way.');
        }
        $n = dh::Node((int) $c['dc_node_fk']);
        if (!$n || !dh::Online($n)) {
            return dh::No('The app\'s node is not online.');
        }
        $i = dh::Info($n);
        if (!isset($i['agent']) || (int) $i['agent'] < 111) {
            return dh::No('The app\'s node runs an older agent (backups need 111): update the node first (Nodes and apps > Manage > Update now).');
        }
        return true;
    }

    /**
     * Put the newest backup back over the app's data (its owner or an administrator).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $cid
     * @param int $by
     * @return bool
     */
    static function Restore($cid, $by)
    {
        global $zdbh;
        $c = dh::Container($cid);
        if (!self::Mine($c, $by)) {
            return dh::No('No such app.');
        }
        $b = self::Newest($cid);
        if (!$b || !self::FilesThere($b)) {
            return dh::No('This app has no backup on the panel.');
        }
        if (!self::Steady($c)) {
            return false;
        }
        $zdbh->prepare("UPDATE x_dh_backups SET bk_state_vc = 'restoring', bk_to_fk = :n, bk_moved_in = :t, bk_note_vc = '' WHERE bk_id_pk = :b")
            ->execute(array(':n' => (int) $c['dc_node_fk'], ':t' => time(), ':b' => (int) $b['bk_id_pk']));
        $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'restoring' WHERE dc_id_pk = :c")->execute(array(':c' => (int) $cid));
        $data = array_merge(dh::CreateData($c, dh::SetupPassword($c)), array('token' => (string) $b['bk_token_vc'], 'files' => self::Files($b)));
        dh::Queue((int) $c['dc_node_fk'], (int) $cid, 'restore', $data);
        dh::Log('restore', $c['dc_domain_vc'] . ': putting back its backup of ' . date('Y-m-d H:i', (int) $b['bk_done_in']), (int) $c['dc_acc_fk'], (int) $by, (int) $cid, (int) $c['dc_node_fk']);
        return true;
    }

    /**
     * Move an app to another node, with its data (administrators only).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $cid
     * @param int $to
     * @param int $by
     * @return bool
     */
    static function Move($cid, $to, $by)
    {
        global $zdbh;
        if (!dh::IsAdmin(dh::Account($by))) {
            return dh::No('Only an administrator moves apps between nodes.');
        }
        $c = dh::Container($cid);
        if (!$c || $c['dc_deleted_in'] !== null) {
            return dh::No('No such app.');
        }
        if ((int) $to === (int) $c['dc_node_fk']) {
            return dh::No('The app is on that node already.');
        }
        $n = dh::Node($to);
        if (!$n || !dh::Online($n)) {
            return dh::No('That node is not online.');
        }
        $i = dh::Info($n);
        if (!isset($i['agent']) || (int) $i['agent'] < 111) {
            return dh::No('That node runs an older agent (moves need 111): update it first.');
        }
        if (!self::Steady($c)) {
            return false;
        }
        $r = dh::Room($n);
        if ($r['cpu'] < (int) $c['dc_cpu_in'] || $r['mem'] < (int) $c['dc_mem_in'] || $r['disk'] < (int) $c['dc_disk_in']) {
            return dh::No(sprintf('Node %s has not got room for it (free: %s CPU, %d MB memory, %s GB disk).', $n['nd_name_vc'], dh::Cpu(max(0, $r['cpu'])), max(0, $r['mem']), round(max(0, $r['disk']) / 1024, 1)));
        }
        $port = dh::FreePort((int) $to);
        if (!$port) {
            return dh::No('That node has no port free.');
        }
        $free = @disk_free_space(self::MakeDir() ?: '/');
        if ($free !== false && $free < ((int) $c['dc_used_in'] + self::RESERVE_MB) * 1048576) {
            return dh::No('The panel has not got room to carry its data over (it needs about ' . ((int) $c['dc_used_in'] + self::RESERVE_MB) . ' MB free).');
        }
        $token = bin2hex(random_bytes(16));
        $zdbh->prepare("INSERT INTO x_dh_backups (bk_ct_fk, bk_node_fk, bk_kind_vc, bk_state_vc, bk_token_vc, bk_to_fk, bk_port_in, bk_by_fk, bk_created_in, bk_moved_in)
            VALUES (:c, :n, 'move', 'queued', :t, :to, :p, :b, :w, :w)")
            ->execute(array(':c' => (int) $cid, ':n' => (int) $c['dc_node_fk'], ':t' => $token, ':to' => (int) $to, ':p' => $port, ':b' => (int) $by, ':w' => time()));
        $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'moving' WHERE dc_id_pk = :c")->execute(array(':c' => (int) $cid));
        dh::Queue((int) $c['dc_node_fk'], (int) $cid, 'backup', array_merge(dh::Who($c), array('token' => $token, 'move' => true)));
        $from = dh::Node((int) $c['dc_node_fk']);
        dh::Log('move', sprintf('%s: moving from node %s to node %s, with its data', $c['dc_domain_vc'], $from ? $from['nd_name_vc'] : '?', $n['nd_name_vc']), (int) $c['dc_acc_fk'], (int) $by, (int) $cid, (int) $to);
        return true;
    }

    /**
     * Ports kept for apps on their way to a node (FreePort leaves them).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @return int[]
     */
    static function Reserved($node)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT bk_port_in FROM x_dh_backups WHERE bk_to_fk = :n AND bk_kind_vc = 'move' AND bk_state_vc IN ('queued','sending','restoring')");
        $q->execute(array(':n' => (int) $node));
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The folder, made for the panel's web server (only it reads and writes there).
     *
     * @return string '' when it can not be made
     */
    static function MakeDir()
    {
        $d = self::Dir();
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
        return is_dir($d) && is_writable($d) ? $d : '';
    }

    /**
     * As root (the daemon): the folder made for the panel's web server, which takes the pieces (only it
     * and root reach it; under SELinux the panel's backup folder is the web server's to write already).
     *
     * @return void
     */
    static function RootDir()
    {
        if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
            return;
        }
        $d = self::Dir();
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
        $web = class_exists('sys_platform') && method_exists('sys_platform', 'Get') ? (string) sys_platform::Get('web_user') : '';
        if ($web === '' || !function_exists('posix_getpwnam') || posix_getpwnam($web) === false) {
            foreach (array('www-data', 'apache') as $w) {
                if (function_exists('posix_getpwnam') && posix_getpwnam($w) !== false) {
                    $web = $w;
                    break;
                }
            }
        }
        if ($web !== '' && is_dir($d) && !is_link($d)) {
            @chown($d, $web);
            @chmod($d, 0700);
        }
    }

    /**
     * A transfer's own folder.
     *
     * @param array $b
     * @return string
     */
    static function Path(array $b)
    {
        return self::Dir() . '/' . (int) $b['bk_ct_fk'] . '/' . $b['bk_token_vc'];
    }

    /**
     * A transfer's files as the node gets them.
     *
     * @param array $b
     * @return array
     */
    static function Files(array $b)
    {
        $f = json_decode((string) $b['bk_files_tx'], true);
        return is_array($f) ? $f : array();
    }

    /**
     * Whether every file of a backup is still there, whole in size.
     *
     * @param array $b
     * @return bool
     */
    static function FilesThere(array $b)
    {
        foreach (self::Files($b) as $f) {
            if (@filesize(self::Path($b) . '/' . $f['name']) !== $f['size']) {
                return false;
            }
        }
        return true;
    }

    /**
     * The transfer a node's piece belongs to: that node's own, still open; or null.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @param mixed $token
     * @param array $states
     * @param string $nodeCol
     * @return array|null
     */
    static function Open($node, $token, array $states, $nodeCol)
    {
        global $zdbh;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $q = $zdbh->prepare("SELECT * FROM x_dh_backups WHERE bk_token_vc = :t");
        $q->execute(array(':t' => $token));
        $b = $q->fetch(PDO::FETCH_ASSOC);
        if (!$b || (int) $b[$nodeCol] !== (int) $node || !in_array($b['bk_state_vc'], $states, true)) {
            return null;
        }
        return $b;
    }

    /**
     * A piece of a backup from a node. Taken only in order (the node is told where to go on), only for
     * its own open transfer, only up to the app's size, and never below RESERVE_MB free on the panel.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @param array $d
     * @return array the reply
     */
    static function Put($node, array $d)
    {
        global $zdbh;
        $b = self::Open($node, isset($d['token']) ? $d['token'] : '', array('queued', 'sending'), 'bk_node_fk');
        if (!$b) {
            return array('stop' => 'no such backup under way');
        }
        $c = dh::Container((int) $b['bk_ct_fk']);
        $file = isset($d['file']) ? $d['file'] : '';
        $vols = $c ? self::Vols($c) : 0;
        if (!$c || !is_string($file) || !preg_match('/^v(\d{1,2})\.tar\.gz$/', $file, $m) || (int) $m[1] >= $vols
            || !isset($d['offset'], $d['size'], $d['sha'], $d['data']) || !is_int($d['offset']) || !is_int($d['size']) || !is_string($d['sha']) || !preg_match('/^[a-f0-9]{64}$/', $d['sha']) || !is_string($d['data'])) {
            return array('stop' => 'a piece that does not fit');
        }
        // (packed, a copy is not bigger than the app's whole disk; a little room for the packing)
        $max = ((int) $c['dc_disk_in'] + 64) * 1048576;
        if ($d['size'] < 1 || $d['size'] > $max) {
            self::Give($b, 'The copy is bigger than the app\'s disk allows.');
            return array('stop' => 'bigger than the app\'s disk');
        }
        $dir = self::MakeDir();
        if ($dir === '') {
            self::Give($b, 'The panel could not make its folder for app backups (' . self::Dir() . ').');
            return array('stop' => 'no folder on the panel');
        }
        $p = self::Path($b);
        if (!is_dir($p) && !@mkdir($p, 0700, true)) {
            self::Give($b, 'The panel could not make the backup\'s folder.');
            return array('stop' => 'no folder on the panel');
        }
        $part = $p . '/' . $file . '.part';
        $done = $p . '/' . $file;
        $zdbh->prepare("UPDATE x_dh_backups SET bk_state_vc = 'sending', bk_moved_in = :t WHERE bk_id_pk = :b")->execute(array(':t' => time(), ':b' => (int) $b['bk_id_pk']));
        clearstatcache();
        if (is_file($done) && !is_file($part)) {
            // (the last piece's answer was lost: the file is whole already)
            return @filesize($done) === $d['size'] && hash_file('sha256', $done) === $d['sha'] ? array('done' => true) : array('stop' => 'the copy differs');
        }
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($d['offset'] !== $have) {
            return array('have' => $have);
        }
        if ($have >= $d['size']) {
            // the whole file: checked, then kept
            if ($have !== $d['size'] || hash_file('sha256', $part) !== $d['sha']) {
                @unlink($part);
                return array('have' => 0);
            }
            @rename($part, $done);
            return array('done' => true);
        }
        $bin = base64_decode($d['data'], true);
        if ($bin === false || $bin === '' || strlen($bin) > self::PIECE || $have + strlen($bin) > $d['size']) {
            return array('have' => $have);
        }
        $free = @disk_free_space($dir);
        if ($free !== false && $free - strlen($bin) < self::RESERVE_MB * 1048576) {
            dh::Log('failed', 'App backups: the panel\'s disk is nearly full; a node waits with its backup', 0, 0, (int) $b['bk_ct_fk'], (int) $node);
            return array('busy' => 120);
        }
        $fh = @fopen($part, 'ab');
        if (!$fh) {
            return array('busy' => 60);
        }
        fwrite($fh, $bin);
        fclose($fh);
        clearstatcache();
        return array('have' => (int) filesize($part));
    }

    /**
     * A piece of a backup for the node putting it back (an app's own node, or the one it moves to).
     *
     * @param int $node
     * @param array $d
     * @return array the reply
     */
    static function Get($node, array $d)
    {
        global $zdbh;
        $b = self::Open($node, isset($d['token']) ? $d['token'] : '', array('restoring'), 'bk_to_fk');
        if (!$b) {
            return array('stop' => 'no such restore under way');
        }
        $file = isset($d['file']) ? $d['file'] : '';
        $known = false;
        foreach (self::Files($b) as $f) {
            $known = $known || $f['name'] === $file;
        }
        if (!$known || !isset($d['offset']) || !is_int($d['offset']) || $d['offset'] < 0) {
            return array('stop' => 'no such file');
        }
        $p = self::Path($b) . '/' . $file;
        $fh = @fopen($p, 'rb');
        if (!$fh) {
            return array('stop' => 'the backup is no longer on the panel');
        }
        fseek($fh, $d['offset']);
        $bin = (string) fread($fh, self::PIECE);
        fclose($fh);
        $zdbh->prepare("UPDATE x_dh_backups SET bk_moved_in = :t WHERE bk_id_pk = :b")->execute(array(':t' => time(), ':b' => (int) $b['bk_id_pk']));
        return array('data' => base64_encode($bin));
    }

    /**
     * A transfer given up: its pieces go, the app is as it was (a move: it runs again where it was).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $b
     * @param string $why
     * @return void
     */
    static function Give(array $b, $why)
    {
        global $zdbh;
        $why = mb_substr(trim((string) preg_replace('/\s+/', ' ', $why)), 0, 230);
        $restoring = $b['bk_state_vc'] === 'restoring' && $b['bk_kind_vc'] === 'backup';
        // (a backup that was being put back stays: it is still the newest)
        $zdbh->prepare("UPDATE x_dh_backups SET bk_state_vc = :s, bk_note_vc = :m, bk_done_in = :t WHERE bk_id_pk = :b")
            ->execute(array(':s' => $restoring ? 'stored' : 'failed', ':m' => $why, ':t' => $restoring ? (int) $b['bk_done_in'] : time(), ':b' => (int) $b['bk_id_pk']));
        if (!$restoring) {
            self::Remove($b);
        }
        $c = dh::Container((int) $b['bk_ct_fk']);
        if (!$c) {
            return;
        }
        $what = $b['bk_kind_vc'] === 'move' ? 'Moving it' : ($restoring ? 'Putting its backup back' : 'Its backup');
        if ($b['bk_kind_vc'] === 'move') {
            // the old copy was left stopped for the move: it runs again where it was
            if ((string) $b['bk_was_vc'] !== 'exited' && (string) $c['dc_want_vc'] === 'running') {
                dh::Queue((int) $c['dc_node_fk'], (int) $c['dc_id_pk'], 'start', dh::Who($c));
            }
        }
        $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = CASE WHEN dc_state_vc IN ('saving','restoring','moving') THEN :s ELSE dc_state_vc END, dc_note_vc = :m WHERE dc_id_pk = :c")
            ->execute(array(':s' => (string) $c['dc_want_vc'] === 'running' ? 'running' : 'stopped', ':m' => mb_substr($what . ' failed: ' . $why, 0, 255), ':c' => (int) $c['dc_id_pk']));
        dh::Log('failed', mb_substr($c['dc_domain_vc'] . ': ' . strtolower($what) . ' failed: ' . $why, 0, 255), (int) $c['dc_acc_fk'], 0, (int) $c['dc_id_pk'], (int) $b['bk_node_fk']);
    }

    /**
     * A transfer's files go from the panel.
     *
     * @param array $b
     * @return void
     */
    static function Remove(array $b)
    {
        $p = self::Path($b);
        foreach ((array) glob($p . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($p);
        @rmdir(dirname($p));
    }

    /**
     * A node's answer to a backup or restore job.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $node
     * @param array $j the job
     * @param bool $ok
     * @param string $out
     * @return void
     */
    static function Result($node, array $j, $ok, $out)
    {
        global $zdbh;
        $data = json_decode((string) ctrl_secret::Decrypt((string) $j['jb_data_tx']), true);
        $token = is_array($data) && isset($data['token']) ? (string) $data['token'] : '';
        $col = (string) $j['jb_action_vc'] === 'backup' ? 'bk_node_fk' : 'bk_to_fk';
        $b = self::Open($node, $token, (string) $j['jb_action_vc'] === 'backup' ? array('queued', 'sending') : array('restoring'), $col);
        if (!$b) {
            return;
        }
        if (!$ok) {
            self::Give($b, $out);
            return;
        }
        $c = dh::Container((int) $b['bk_ct_fk']);
        if (!$c || $c['dc_deleted_in'] !== null) {
            self::Give($b, 'The app was deleted meanwhile.');
            return;
        }
        if ((string) $j['jb_action_vc'] === 'backup') {
            $r = json_decode($out, true);
            $vols = self::Vols($c);
            $files = is_array($r) && isset($r['files']) && is_array($r['files']) ? array_values($r['files']) : null;
            if ($files === null || count($files) !== $vols) {
                self::Give($b, 'The node\'s answer did not list the files.');
                return;
            }
            $size = 0;
            foreach ($files as $i => $f) {
                $p = self::Path($b) . '/v' . $i . '.tar.gz';
                clearstatcache();
                if (!is_array($f) || !isset($f['name'], $f['size'], $f['sha']) || $f['name'] !== 'v' . $i . '.tar.gz' || !is_int($f['size']) || @filesize($p) !== $f['size'] || hash_file('sha256', $p) !== $f['sha']) {
                    self::Give($b, 'A file of the backup did not arrive whole.');
                    return;
                }
                $files[$i] = array('name' => $f['name'], 'size' => $f['size'], 'sha' => $f['sha']);
                $size += $f['size'];
            }
            $image = isset($r['image']) && is_string($r['image']) && preg_match('#^[a-z0-9.-]+(:\d{1,5})?/[a-z0-9._/-]+@sha256:[a-f0-9]{64}$#', $r['image']) ? $r['image'] : '';
            $was = isset($r['was']) && is_string($r['was']) && preg_match('/^[a-z]{1,20}$/', $r['was']) ? $r['was'] : '';
            $zdbh->prepare("UPDATE x_dh_backups SET bk_files_tx = :f, bk_size_bi = :s, bk_image_vc = :i, bk_was_vc = :w, bk_moved_in = :t WHERE bk_id_pk = :b")
                ->execute(array(':f' => json_encode($files), ':s' => $size, ':i' => $image, ':w' => $was, ':t' => time(), ':b' => (int) $b['bk_id_pk']));
            $b = array_merge($b, array('bk_files_tx' => json_encode($files), 'bk_size_bi' => $size, 'bk_image_vc' => $image, 'bk_was_vc' => $was));
            if ($b['bk_kind_vc'] === 'move') {
                self::MoveOn($b, $c);
                return;
            }
            $zdbh->prepare("UPDATE x_dh_backups SET bk_state_vc = 'stored', bk_done_in = :t WHERE bk_id_pk = :b")->execute(array(':t' => time(), ':b' => (int) $b['bk_id_pk']));
            // only the newest stays on the panel (the backup node keeps the nights before)
            foreach (self::Of((int) $c['dc_id_pk']) as $old) {
                if ((int) $old['bk_id_pk'] !== (int) $b['bk_id_pk'] && $old['bk_state_vc'] === 'stored') {
                    self::Remove($old);
                    $zdbh->prepare("UPDATE x_dh_backups SET bk_state_vc = 'replaced' WHERE bk_id_pk = :b")->execute(array(':b' => (int) $old['bk_id_pk']));
                }
            }
            $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = CASE WHEN dc_state_vc = 'saving' THEN :s ELSE dc_state_vc END WHERE dc_id_pk = :c")
                ->execute(array(':s' => $was === 'running' ? 'running' : 'stopped', ':c' => (int) $c['dc_id_pk']));
            dh::Log('backup', sprintf('%s: backed up (%s MB)', $c['dc_domain_vc'], round($size / 1048576, 1)), (int) $c['dc_acc_fk'], 0, (int) $c['dc_id_pk'], (int) $node);
            return;
        }
        // a restore (or the last step of a move) went through
        if ($b['bk_kind_vc'] === 'move') {
            self::MoveDone($b, $c);
            return;
        }
        $zdbh->prepare("UPDATE x_dh_backups SET bk_state_vc = 'stored', bk_note_vc = '' WHERE bk_id_pk = :b")->execute(array(':b' => (int) $b['bk_id_pk']));
        $zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = :s, dc_note_vc = '' WHERE dc_id_pk = :c")
            ->execute(array(':s' => (string) $c['dc_want_vc'] === 'running' ? 'running' : 'stopped', ':c' => (int) $c['dc_id_pk']));
        dh::Log('restored', $c['dc_domain_vc'] . ': its backup of ' . date('Y-m-d H:i', (int) $b['bk_done_in']) . ' is back', (int) $c['dc_acc_fk'], 0, (int) $c['dc_id_pk'], (int) $node);
    }

    /**
     * A move's data is on the panel: the new node makes the app from it.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $b
     * @param array $c
     * @return void
     */
    static function MoveOn(array $b, array $c)
    {
        global $zdbh;
        $n = dh::Node((int) $b['bk_to_fk']);
        if (!$n) {
            self::Give($b, 'The node it was moving to is gone.');
            return;
        }
        $zdbh->prepare("UPDATE x_dh_backups SET bk_state_vc = 'restoring', bk_moved_in = :t WHERE bk_id_pk = :b")->execute(array(':t' => time(), ':b' => (int) $b['bk_id_pk']));
        $there = $c;
        $there['dc_port_in'] = (int) $b['bk_port_in'];
        $data = array_merge(dh::CreateData($there, dh::SetupPassword($c)), array('token' => (string) $b['bk_token_vc'], 'files' => self::Files($b), 'image_ref' => (string) $b['bk_image_vc']));
        dh::Queue((int) $b['bk_to_fk'], (int) $c['dc_id_pk'], 'restore', $data);
    }

    /**
     * A move went through: the app is the new node's, its DNS record follows, the old copy goes.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $b
     * @param array $c
     * @return void
     */
    static function MoveDone(array $b, array $c)
    {
        global $zdbh;
        $from = (int) $c['dc_node_fk'];
        $to = (int) $b['bk_to_fk'];
        // (queued first: until it is done, the old node's copy is not taken for a found app)
        dh::Queue($from, (int) $c['dc_id_pk'], 'delete', dh::Who($c) + array('domain' => ''));
        $zdbh->prepare("UPDATE x_dh_containers SET dc_node_fk = :n, dc_port_in = :p, dc_state_vc = :s, dc_note_vc = '' WHERE dc_id_pk = :c")
            ->execute(array(':n' => $to, ':p' => (int) $b['bk_port_in'], ':s' => (string) $b['bk_was_vc'] === 'exited' ? 'stopped' : 'running', ':c' => (int) $c['dc_id_pk']));
        $zdbh->prepare("UPDATE x_dh_backups SET bk_state_vc = 'moved', bk_done_in = :t WHERE bk_id_pk = :b")->execute(array(':t' => time(), ':b' => (int) $b['bk_id_pk']));
        self::Remove($b);
        dh::DnsRepoint(dh::Container((int) $c['dc_id_pk']), $to);
        $fn = dh::Node($from);
        $tn = dh::Node($to);
        dh::Log('moved', sprintf('%s: moved from node %s to node %s, with its data', $c['dc_domain_vc'], $fn ? $fn['nd_name_vc'] : '?', $tn ? $tn['nd_name_vc'] : '?'), (int) $c['dc_acc_fk'], (int) $b['bk_by_fk'], (int) $c['dc_id_pk'], $to);
    }

    /**
     * The daemon's part: transfers that stopped moving are given up, backups of deleted apps go, and
     * each night (two hours before the off-server backup) every app with backups on is backed up, one
     * at a time on each node.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $hour the panel's hour now (tests set it)
     * @return void
     */
    static function Daemon($hour)
    {
        global $zdbh;
        self::Ensure();
        self::RootDir();
        foreach ($zdbh->query("SELECT * FROM x_dh_backups WHERE bk_state_vc IN ('queued','sending','restoring')")->fetchAll(PDO::FETCH_ASSOC) as $b) {
            if ((int) $b['bk_moved_in'] < time() - self::STALE) {
                self::Give($b, 'It stopped moving on for ' . intdiv(self::STALE, 3600) . ' hours.');
            }
        }
        foreach ($zdbh->query("SELECT b.* FROM x_dh_backups b JOIN x_dh_containers c ON c.dc_id_pk = b.bk_ct_fk WHERE b.bk_state_vc = 'stored' AND c.dc_deleted_in IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $b) {
            self::Remove($b);
            $zdbh->prepare("UPDATE x_dh_backups SET bk_state_vc = 'removed' WHERE bk_id_pk = :b")->execute(array(':b' => (int) $b['bk_id_pk']));
        }
        if (self::Unavailable() !== '') {
            return;
        }
        $t = (string) ctrl_options::GetSystemOption('offsite_time');
        $at = preg_match('/^(\d{1,2}):\d\d$/', $t, $m) ? ((int) $m[1] + 22) % 24 : 0;
        if ((int) $hour !== $at && (int) $hour !== ($at + 1) % 24) {
            return;
        }
        $since = time() - 20 * 3600;
        $busyNodes = array();
        foreach ($zdbh->query("SELECT b.bk_node_fk, b.bk_to_fk FROM x_dh_backups b WHERE b.bk_state_vc IN ('queued','sending','restoring')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $busyNodes[(int) $r['bk_node_fk']] = true;
            $busyNodes[(int) $r['bk_to_fk']] = true;
        }
        $q = $zdbh->query("SELECT * FROM x_dh_containers WHERE dc_deleted_in IS NULL AND dc_backup_in = 1 ORDER BY dc_id_pk");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $node = (int) $c['dc_node_fk'];
            if (isset($busyNodes[$node])) {
                continue;
            }
            $last = $zdbh->prepare("SELECT COUNT(*) FROM x_dh_backups WHERE bk_ct_fk = :c AND bk_kind_vc = 'backup' AND bk_created_in > :s");
            $last->execute(array(':c' => (int) $c['dc_id_pk'], ':s' => $since));
            if ((int) $last->fetchColumn() > 0) {
                continue;
            }
            if (self::Start((int) $c['dc_id_pk'], 0)) {
                $busyNodes[$node] = true;
            }
        }
    }
}
