<?php

/**
 * Sentora Node agent: runs on a Docker Hosting node (a separate server, never the panel's) as the
 * root service sentora-node. It calls the panel every few seconds (nothing on the node is opened for
 * the panel), takes the jobs waiting for it, does them, and reports back.
 *
 * Every message is signed and sealed (the same as the add-on's code/proto.php): the agent knows the
 * panel's public keys from its install command, so it only does jobs the panel signed, and only the
 * panel can read what it reports. It does fixed jobs only: create, start, stop, restart, limits,
 * logs, proxy (public or behind the setup password), delete; everything in a job is checked here
 * again before it is used, and commands are run without a shell.
 *
 * Each customer account has its own Linux user (sdh<account ID>) running its containers with
 * rootless Podman (its own user namespace, cgroup limits for CPU, memory and processes). Containers
 * listen on 127.0.0.1 only; Caddy (the node's web server) gives each its address with HTTPS.
 *
 *   sentora-node-agent enroll --panel https://panel.example [--panel-code XXXX-XXXX] [--no-tls-check]
 *                                (the installer's usual way: the node makes its own key, the panel
 *                                 administrator pastes it in "Add a node"; the panel's code is checked
 *                                 by the person installing, so the node never trusts a fake panel)
 *   sentora-node-agent pair --panel https://panel.example --key v1.<sign>.<box> --code <id>.<secret> [--no-tls-check]
 *                                (the older way: the command the panel shows)
 *   sentora-node-agent run       (the service)
 *   sentora-node-agent once      (one call to the panel)
 *   sentora-node-agent status
 *
 * Paths for tests: SNODE_ETC (/etc/sentora-node), SNODE_STATE (/var/lib/sentora-node),
 * SNODE_CADDY (/etc/caddy), SNODE_RUN (/run/user), SNODE_HOME (/home), SNODE_SUBID (/etc: subuid,
 * subgid), SNODE_USERPATH (PATH of the customers' commands), SNODE_KEYFILE (/root/sentora-node-key.txt).
 * What differs between systems (Caddy's folder, service and group, the no-login shell) comes from
 * /etc/sentora-node/platform.conf, written by the installer for its system (P()).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

class snode
{
    const VERSION = '110';

    /** @var bool The panel understands only bare messages (Docker Hosting before 108). */
    static $bare = false;
    const V = 1;
    const SKEW = 300;
    const PORT_FIRST = 20000;
    const PORT_LAST = 29999;

    static $etc;
    static $state;
    static $caddy;
    static $run;
    static $home;
    /** @var array */
    static $cfg = array();
    /** @var array Disk use per container (MB) and when measured. */
    static $disk = array();
    static $diskAt = 0;
    static $sysCheckAt = 0;
    static $fence = null;
    static $fenceAt = 0;
    static $platform = array();

    /**
     * A name that differs between systems, from platform.conf (KEY=value lines, written by the
     * installer for its system), or the given default.
     */
    static function P($key, $default)
    {
        return isset(self::$platform[$key]) && self::$platform[$key] !== '' ? self::$platform[$key] : $default;
    }

    static function Init()
    {
        self::$etc = getenv('SNODE_ETC') ?: '/etc/sentora-node';
        // (a service has no HOME; Caddy before 2.8 will not even check its settings without one)
        if ((string) getenv('HOME') === '') {
            putenv('HOME=/root');
        }
        foreach ((array) @file(self::$etc . '/platform.conf', FILE_IGNORE_NEW_LINES) as $l) {
            if (preg_match('/^([A-Z_]{1,32})=([A-Za-z0-9_.\/@:+-]{0,200})$/', (string) $l, $m)) {
                self::$platform[$m[1]] = $m[2];
            }
        }
        self::$state = getenv('SNODE_STATE') ?: '/var/lib/sentora-node';
        self::$caddy = getenv('SNODE_CADDY') ?: self::P('CADDY_DIR', '/etc/caddy');
        self::$run = getenv('SNODE_RUN') ?: '/run/user';
        self::$home = getenv('SNODE_HOME') ?: '/home';
        umask(077);
        foreach (array('sodium_crypto_box_seal', 'curl_init') as $f) {
            if (!function_exists($f)) {
                self::Out("PHP is missing " . $f . " (php-sodium, php-curl)");
                exit(1);
            }
        }
    }

    static function Out($line)
    {
        fwrite(STDOUT, $line . "\n");
    }

    // ----- messages (as the add-on's dh_proto) -----

    static function B64($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    static function Bin($text, $len)
    {
        if (!is_string($text) || !preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
            return false;
        }
        $b = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        return $b !== false && strlen($b) === $len ? $b : false;
    }

    static function Pack(array $msg, $signSk, $toBoxPk)
    {
        $json = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return base64_encode(sodium_crypto_box_seal(sodium_crypto_sign_detached($json, $signSk) . $json, $toBoxPk));
    }

    /**
     * Open the panel's reply: sealed to us, signed by the panel, answering our nonce, fresh.
     */
    static function OpenReply($wire, $boxKp, $panelSignPk, $nonce)
    {
        $raw = base64_decode(trim((string) $wire), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_BOX_SEALBYTES + SODIUM_CRYPTO_SIGN_BYTES + 2) {
            return null;
        }
        $plain = sodium_crypto_box_seal_open($raw, $boxKp);
        if ($plain === false) {
            return null;
        }
        $sig = substr($plain, 0, SODIUM_CRYPTO_SIGN_BYTES);
        $json = substr($plain, SODIUM_CRYPTO_SIGN_BYTES);
        if (!sodium_crypto_sign_verify_detached($sig, $json, $panelSignPk)) {
            return null;
        }
        $m = json_decode($json, true);
        if (!is_array($m) || (int) (isset($m['v']) ? $m['v'] : 0) !== self::V || !isset($m['nonce'], $m['ts']) || !hash_equals($nonce, (string) $m['nonce'])
            || abs(time() - (int) $m['ts']) > self::SKEW) {
            return null;
        }
        return $m;
    }

    static function Msg($type, $node, array $data)
    {
        return array('v' => self::V, 'type' => $type, 'node' => (int) $node, 'ts' => time(), 'nonce' => bin2hex(random_bytes(16)), 'data' => $data);
    }

    /**
     * The short code of a panel's public keys (the panel shows the same one), like K7Q2-9MXD.
     */
    static function Fingerprint($signPk, $boxPk)
    {
        $b = self::Base32(hash('sha256', 'sentora-panel|' . $signPk . $boxPk, true));
        return substr($b, 0, 4) . '-' . substr($b, 4, 4);
    }

    static function Base32($bin)
    {
        $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $a[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    /**
     * Fetch the panel's public keys (they are not secret) and whether its certificate can be checked.
     * Returns [key line, tls_verify] or null.
     */
    static function PanelKey($panel, $verify)
    {
        foreach ($verify ? array(true, false) : array(false) as $v) {
            $c = curl_init(rtrim($panel, '/') . '/modules/docker_hosting/code/node.php?key=1');
            curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_HTTPHEADER => array('User-Agent: sentora-node/' . self::VERSION)));
            if (!$v) {
                curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $body = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            $errno = curl_errno($c);
            $err = curl_error($c);
            curl_close($c);
            if ($body !== false && $code === 200 && preg_match('/^v1\.[A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}$/', trim($body))) {
                return array(trim($body), $v);
            }
            if ($v && in_array($errno, array(51, 58, 60, 77, 83), true)) {
                self::Out('The panel\'s certificate is self-signed: the node will rely on the panel\'s key (checked with you below) instead.');
                continue;
            }
            self::Out('Could not get the panel\'s key from ' . $panel . ': ' . ($body === false ? $err : 'HTTP ' . $code . ($code === 404 ? ' (is the Docker Hosting add-on installed and up to date in the panel?)' : '')));
            return null;
        }
        return null;
    }

    /**
     * Send a message to the panel; its opened reply, or null (with the reason on the output).
     */
    static function Call(array $msg, $signSk, $boxKp, array $cfg)
    {
        $panelSign = self::Bin($cfg['panel_sign_pk'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $panelBox = self::Bin($cfg['panel_box_pk'], SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
        $wire = self::Pack($msg, $signSk, $panelBox);
        // as a form field (web filters such as ModSecurity flag every other content type); a panel
        // with Docker Hosting before 108 understands only the bare message: then that, from then on
        foreach (self::$bare ? array(true) : array(false, true) as $bare) {
            $c = curl_init(rtrim($cfg['panel'], '/') . '/modules/docker_hosting/code/node.php');
            curl_setopt_array($c, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $bare ? $wire : 'm=' . rawurlencode($wire), CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array('Content-Type: ' . ($bare ? 'text/plain' : 'application/x-www-form-urlencoded'), 'User-Agent: sentora-node/' . self::VERSION),
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP));
            if (empty($cfg['tls_verify'])) {
                // (safe: every reply is signed with the panel's key, which the node already knows)
                curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $body = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            $err = curl_error($c);
            curl_close($c);
            if ($code !== 400) {
                break;
            }
            if ($bare && $body !== false) {
                break;
            }
        }
        if (!self::$bare && $bare && $code === 200) {
            self::$bare = true;
        }
        if ($body === false || $code !== 200) {
            if ($body === false && stripos($err, 'certificate') !== false && !empty($cfg['tls_verify'])) {
                $err .= ' (the panel has a self-signed certificate: add --no-tls-check to the install command; safe, every reply is signed with the panel\'s key)';
            }
            self::Out('panel: ' . ($body === false ? $err : 'HTTP ' . $code . ($code === 400 ? ' (the panel refused the message: wrong keys, expired code, or the clocks differ by more than 5 minutes)' : '')));
            return null;
        }
        $r = self::OpenReply($body, $boxKp, $panelSign, $msg['nonce']);
        if (!$r) {
            self::Out('panel: the reply is not signed by the panel this node was linked to: ignored');
        }
        return $r;
    }

    // ----- config and state -----

    static function LoadCfg()
    {
        $f = self::$etc . '/node.json';
        $c = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        if (!is_array($c) || !isset($c['node'], $c['panel'], $c['sign_sk'], $c['box_kp'], $c['panel_sign_pk'], $c['panel_box_pk']) || ((int) $c['node'] === 0 && !isset($c['claim']))) {
            return null;
        }
        return self::$cfg = $c;
    }

    static function WriteFile($f, $data, $mode = 0600)
    {
        $tmp = $f . '.new';
        if (file_put_contents($tmp, $data) === false) {
            return false;
        }
        chmod($tmp, $mode);
        return rename($tmp, $f);
    }

    static function State()
    {
        $f = self::$state . '/state.json';
        $s = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        $s = is_array($s) ? $s : array();
        foreach (array('containers', 'results', 'done') as $k) {
            if (!isset($s[$k]) || !is_array($s[$k])) {
                $s[$k] = array();
            }
        }
        return $s;
    }

    static function SaveState(array $s)
    {
        if (!is_dir(self::$state)) {
            mkdir(self::$state, 0700, true);
        }
        // the answers of the last 300 jobs are kept, to answer a job sent twice the same way
        if (count($s['done']) > 300) {
            $s['done'] = array_slice($s['done'], -300, null, true);
        }
        self::WriteFile(self::$state . '/state.json', json_encode($s, JSON_UNESCAPED_SLASHES));
    }

    // ----- running things -----

    /**
     * Whether Caddy is older than 2.8 (the version some systems ship: Debian's and Ubuntu's 2.6),
     * which spells a password block basicauth and wants its hash base64-encoded.
     */
    static function CaddyOld()
    {
        static $old = null;
        if ($old === null) {
            $v = self::Run(array(self::P('CADDY_BIN', 'caddy'), 'version'), null, 20);
            $old = $v[0] === 0 && preg_match('/^v?2\.[0-7]\./', trim($v[1])) === 1;
        }
        return $old;
    }

    /**
     * Where a command is on the PATH, or ''.
     */
    static function Which($cmd)
    {
        static $seen = array();
        if (!isset($seen[$cmd])) {
            $seen[$cmd] = '';
            foreach (explode(':', (string) getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin') as $d) {
                if ($d !== '' && is_file($d . '/' . $cmd) && is_executable($d . '/' . $cmd)) {
                    $seen[$cmd] = $d . '/' . $cmd;
                    break;
                }
            }
        }
        return $seen[$cmd];
    }

    /**
     * Run a command (no shell). Returns [exit code, output (stdout and stderr together)].
     */
    static function Run(array $argv, $cwd = null, $timeout = 600)
    {
        // (a command this system does not have, such as getenforce without SELinux: said, not warned about)
        if (strpos((string) $argv[0], '/') === false && self::Which($argv[0]) === '') {
            return array(127, $argv[0] . ': not on this system');
        }
        $p = proc_open($argv, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes, $cwd);
        if (!is_resource($p)) {
            return array(127, 'could not run ' . $argv[0]);
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $end = time() + $timeout;
        while (true) {
            $r = array($pipes[1]);
            $w = null;
            $e = null;
            if (@stream_select($r, $w, $e, 1) > 0) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk !== false && $chunk !== '') {
                    $out .= strlen($out) < 1048576 ? $chunk : '';
                }
            }
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                proc_close($p);
                return array((int) $st['exitcode'], $out);
            }
            if (time() > $end) {
                proc_terminate($p, 9);
                fclose($pipes[1]);
                proc_close($p);
                return array(124, $out . "\n(stopped after " . $timeout . " seconds)");
            }
        }
    }

    static function Uid($user)
    {
        $r = self::Run(array('id', '-u', $user));
        return $r[0] === 0 && ctype_digit(trim($r[1])) ? (int) trim($r[1]) : -1;
    }

    /**
     * The customer's own Linux user, with its subordinate IDs, lingering (its services run without
     * a login) and podman-restart on (its containers start again after a restart).
     */
    static function EnsureUser($user)
    {
        $uid = self::Uid($user);
        if ($uid < 0) {
            $r = self::Run(array('useradd', '--create-home', '--home-dir', self::$home . '/' . $user, '--shell', self::P('NOLOGIN', '/sbin/nologin'), '--comment', 'Sentora Docker Hosting', $user));
            if ($r[0] !== 0) {
                return 'useradd: ' . trim($r[1]);
            }
            $uid = self::Uid($user);
            if ($uid < 0) {
                return 'the user ' . $user . ' was not made';
            }
            @chmod(self::$home . '/' . $user, 0700);
        }
        $sub = getenv('SNODE_SUBID') ?: '/etc';
        foreach (array($sub . '/subuid' => '--add-subuids', $sub . '/subgid' => '--add-subgids') as $file => $opt) {
            $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : array();
            $has = false;
            $top = 100000;
            foreach ($lines as $l) {
                $p = explode(':', $l);
                if (count($p) === 3) {
                    $has = $has || $p[0] === $user || $p[0] === (string) $uid;
                    $top = max($top, (int) $p[1] + (int) $p[2]);
                }
            }
            if (!$has) {
                $r = self::Run(array('usermod', $opt, $top . '-' . ($top + 65535), $user));
                if ($r[0] !== 0) {
                    return 'usermod: ' . trim($r[1]);
                }
            }
        }
        $e = self::Session($user);
        if ($e !== '') {
            return $e;
        }
        // (its containers with --restart start again after a restart of the server)
        if (!is_file(self::$home . '/' . $user . '/.config/systemd/user/default.target.wants/podman-restart.service')) {
            $r = self::AsUser($user, array('systemctl', '--user', 'enable', 'podman-restart.service'));
            if ($r[0] !== 0) {
                return 'podman-restart: ' . self::Tail($r[1]);
            }
        }
        return '';
    }

    /**
     * The user's own systemd session (it lingers: it runs without a login, and starts with the server).
     */
    static function Session($user)
    {
        $uid = self::Uid($user);
        if ($uid < 0) {
            return 'no user ' . $user;
        }
        if (is_dir(self::$run . '/' . $uid)) {
            return '';
        }
        $l = self::Run(array('loginctl', 'enable-linger', $user));
        if ($l[0] !== 0) {
            return 'loginctl enable-linger: ' . self::Tail($l[1]);
        }
        self::Run(array('systemctl', 'start', 'user@' . $uid . '.service'), null, 60);
        for ($i = 0; $i < 30 && !is_dir(self::$run . '/' . $uid); $i++) {
            sleep(1);
        }
        if (!is_dir(self::$run . '/' . $uid)) {
            $st = self::Run(array('systemctl', 'status', '--no-pager', '--lines', '5', 'user@' . $uid . '.service'), null, 30);
            return 'the user\'s session did not start (' . self::$run . '/' . $uid . '): ' . self::Tail($st[1]);
        }
        return '';
    }

    /**
     * Run a command as the customer's user (in its own session's environment).
     */
    static function AsUser($user, array $argv, $timeout = 600)
    {
        $uid = self::Uid($user);
        $env = array('env', 'HOME=' . self::$home . '/' . $user, 'XDG_RUNTIME_DIR=' . self::$run . '/' . $uid, 'DBUS_SESSION_BUS_ADDRESS=unix:path=' . self::$run . '/' . $uid . '/bus',
            'PATH=' . (getenv('SNODE_USERPATH') ?: '/usr/local/bin:/usr/bin:/bin'));
        return self::Run(array_merge(array('runuser', '-u', $user, '--'), $env, $argv), self::$home . '/' . $user, $timeout);
    }

    static function Podman($user, array $args, $timeout = 600)
    {
        $e = self::Session($user);
        if ($e !== '') {
            return array(125, $e);
        }
        return self::AsUser($user, array_merge(array('podman'), $args), $timeout);
    }

    // ----- checking a job -----

    static function Check(array $d, $action)
    {
        if (!isset($d['cid']) || !is_int($d['cid']) || $d['cid'] <= 0 || !isset($d['user']) || !is_string($d['user']) || !preg_match('/^sdh\d{1,9}$/', $d['user'])) {
            return 'bad container or user';
        }
        if (isset($d['domain']) && $d['domain'] !== '' && (!is_string($d['domain']) || strlen($d['domain']) > 253
            || !preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/', $d['domain']))) {
            return 'bad domain';
        }
        if (isset($d['hostport']) && (!is_int($d['hostport']) || $d['hostport'] < self::PORT_FIRST || $d['hostport'] > self::PORT_LAST)) {
            return 'bad port';
        }
        if (isset($d['auth']) && $d['auth'] !== '' && (!is_string($d['auth']) || !preg_match('#^\$2[aby]\$\d\d\$[./A-Za-z0-9]{53}$#', $d['auth']))) {
            return 'bad password hash';
        }
        if (isset($d['cpu']) && (!is_int($d['cpu']) || $d['cpu'] < 1 || $d['cpu'] > 100000)) {
            return 'bad CPU';
        }
        if (isset($d['mem']) && (!is_int($d['mem']) || $d['mem'] < 16 || $d['mem'] > 4194304)) {
            return 'bad memory';
        }
        if ($action === 'create' || $action === 'update' || $action === 'recreate' || $action === 'move') {
            if (!isset($d['image']) || !is_string($d['image']) || strlen($d['image']) > 255
                || !preg_match('#^[a-z0-9.-]+(:\d{1,5})?/[a-z0-9._/-]+(:[A-Za-z0-9._-]{1,128})?(@sha256:[a-f0-9]{64})?$#', $d['image'])) {
                return 'bad image';
            }
            if (!isset($d['port']) || !is_int($d['port']) || $d['port'] < 1 || $d['port'] > 65535 || !isset($d['hostport'], $d['cpu'], $d['mem'], $d['domain'])) {
                return 'bad settings';
            }
            if (!isset($d['env']) || !is_array($d['env']) || count($d['env']) > 50) {
                return 'bad environment';
            }
            foreach ($d['env'] as $k => $v) {
                if (!is_string($k) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k) || !is_string($v) || strlen($v) > 4096 || preg_match('/[\x00\r\n]/', $v)) {
                    return 'bad environment';
                }
            }
            if (!isset($d['volumes']) || !is_array($d['volumes']) || count($d['volumes']) > 10) {
                return 'bad volumes';
            }
            foreach ($d['volumes'] as $v) {
                if (!is_string($v) || !preg_match('#^/[A-Za-z0-9._/-]{1,200}$#', $v) || strpos($v, '..') !== false) {
                    return 'bad volume';
                }
            }
        }
        return '';
    }

    // ----- jobs -----

    static function Name($cid)
    {
        return 'sdh-' . (int) $cid;
    }

    /**
     * podman create's arguments for a container (the same for a new one and an updated one).
     */
    static function CreateArgs(array $d, $name)
    {
        $args = array('create', '--name', $name, '--label', 'sentora.cid=' . $d['cid'], '--restart', 'unless-stopped',
            '--cpus', sprintf('%d.%02d', intdiv($d['cpu'], 100), $d['cpu'] % 100), '--memory', $d['mem'] . 'm', '--memory-swap', $d['mem'] . 'm',
            '--pids-limit', '1024', '--security-opt', 'no-new-privileges', '--log-driver', 'k8s-file', '--log-opt', 'max-size=10mb',
            '--publish', '127.0.0.1:' . $d['hostport'] . ':' . $d['port']);
        foreach (array_values($d['volumes']) as $i => $path) {
            $args[] = '--volume';
            $args[] = self::Name($d['cid']) . '-' . $i . ':' . $path;
        }
        foreach ($d['env'] as $k => $v) {
            $args[] = '--env';
            $args[] = $k . '=' . $v;
        }
        $args[] = $d['image'];
        return $args;
    }

    /**
     * Update an app to its image's newest version: the new one replaces the old with the same data;
     * when it does not start, the old one runs again. [ok, output]
     */
    static function Update(array $d, array &$s, $pull = true)
    {
        $name = self::Name($d['cid']);
        $u = $d['user'];
        $cur = self::Podman($u, array('container', 'inspect', '--format', '{{.Image}}', $name));
        if ($cur[0] !== 0) {
            return array(false, 'no such container here');
        }
        if ($pull) {
            $p = self::Podman($u, array('pull', '--quiet', $d['image']), 1800);
            if ($p[0] !== 0) {
                return array(false, 'pull: ' . self::Tail($p[1]));
            }
            $new = self::Podman($u, array('image', 'inspect', '--format', '{{.Id}}', $d['image']));
            if ($new[0] === 0 && trim($new[1]) === trim($cur[1])) {
                $s['updates'][(string) $d['cid']] = false;
                return array(true, 'already the newest version');
            }
        } else {
            // (new settings, the same version: the image the container runs now, by its ID)
            $id = trim($cur[1]);
            if (!preg_match('/^(sha256:)?[A-Za-z0-9._-]{3,128}$/', $id)) {
                return array(false, 'the version the app runs could not be read');
            }
            $d['image'] = $id;
        }
        $old = $name . '-old';
        self::Podman($u, array('rm', '--force', '--ignore', $old));
        self::Podman($u, array('stop', '--time', '20', $name), 120);
        $rn = self::Podman($u, array('rename', $name, $old));
        if ($rn[0] !== 0) {
            self::Podman($u, array('start', $name));
            return array(false, 'rename: ' . self::Tail($rn[1]));
        }
        $back = function ($why) use ($u, $name, $old, $pull) {
            self::Podman($u, array('rm', '--force', '--ignore', $name));
            self::Podman($u, array('rename', $old, $name));
            self::Podman($u, array('start', $name));
            return array(false, $why . ($pull ? ': the old version runs again' : ': it runs again with its old settings'));
        };
        $cr = self::Podman($u, self::CreateArgs($d, $name));
        if ($cr[0] !== 0) {
            return $back(($pull ? 'the new version' : 'the app with its new settings') . ' could not be made (' . self::Tail($cr[1]) . ')');
        }
        $st = self::Podman($u, array('start', $name));
        if ($st[0] !== 0) {
            return $back(($pull ? 'the new version' : 'the app with its new settings') . ' did not start (' . self::Tail($st[1]) . ')');
        }
        // it must still run after a while (an app that crashes at once comes back as the old one)
        sleep((int) (getenv('SNODE_UPDATE_WAIT') !== false ? getenv('SNODE_UPDATE_WAIT') : 20));
        $state = self::Podman($u, array('container', 'inspect', '--format', '{{.State.Status}}', $name));
        if (trim($state[1]) !== 'running') {
            return $back(($pull ? 'the new version' : 'the app with its new settings') . ' stopped after starting (' . trim($state[1]) . ')');
        }
        self::Podman($u, array('rm', '--force', $old));
        if (!$pull) {
            return array(true, 'running with its new settings');
        }
        self::Podman($u, array('image', 'prune', '--force'), 300);
        $s['updates'][(string) $d['cid']] = false;
        return array(true, 'updated to the newest version');
    }

    /**
     * Whether a newer version of each app's image is published (checked once a day; skopeo reads the
     * registry without downloading the image). Results in $s['updates'].
     */
    static function CheckUpdates(array &$s, $force = false)
    {
        if (!$force && isset($s['updates_at']) && time() - (int) $s['updates_at'] < 86400) {
            return;
        }
        $s['updates_at'] = time();
        if (!isset($s['updates']) || !is_array($s['updates'])) {
            $s['updates'] = array();
        }
        foreach ($s['containers'] as $cid => $c) {
            if (self::Uid($c['user']) < 0) {
                continue;
            }
            if (!isset($c['image'])) {
                // (containers made by agent 102 and before: their image is read from Podman)
                $in = self::Podman($c['user'], array('container', 'inspect', '--format', '{{.ImageName}}', self::Name($cid)), 60);
                if ($in[0] !== 0 || !preg_match('#^[a-z0-9.-]+(:\d{1,5})?/[a-z0-9._/-]+(:[A-Za-z0-9._-]{1,128})?$#', trim($in[1]))) {
                    continue;
                }
                $s['containers'][$cid]['image'] = $c['image'] = trim($in[1]);
            }
            $local = self::Podman($c['user'], array('image', 'inspect', '--format', '{{json .RepoDigests}}', $c['image']), 60);
            $remote = self::Run(array('skopeo', 'inspect', '--no-tags', '--format', '{{.Digest}}', 'docker://' . $c['image']), null, 120);
            $digests = json_decode(trim($local[1]), true);
            if ($local[0] !== 0 || $remote[0] !== 0 || !is_array($digests) || !preg_match('/^sha256:[a-f0-9]{64}$/', trim($remote[1]))) {
                continue;
            }
            $found = false;
            foreach ($digests as $dg) {
                $found = $found || substr((string) $dg, -71) === trim($remote[1]);
            }
            $s['updates'][(string) $cid] = !$found;
        }
        self::SaveState($s);
    }

    /**
     * Jobs about the node itself (no container). [ok, output] or null when it is not one.
     */
    static function NodeJob($action, array $d, array &$s)
    {
        $bg = function (array $argv, $delay = 0) {
            $unit = 'sentora-node-' . preg_replace('/[^a-z]/', '', $argv[0] === '/usr/local/sbin/sentora-node-sysupdate' ? 'sysupdate' : basename($argv[0])) . '-' . time();
            $cmd = array_merge(array('systemd-run', '--unit', $unit, '--collect', '--quiet'), $delay > 0 ? array('--on-active=' . $delay) : array(), $argv);
            return self::Run($cmd, null, 60);
        };
        switch ($action) {
            case 'node-update':
                $r = $bg(array('/usr/local/sbin/sentora-node-update', '--yes'), 5);
                return array($r[0] === 0, $r[0] === 0 ? 'updating the node (agent ' . self::VERSION . ' now); it comes back in a few minutes' : 'node-update: ' . self::Tail($r[1]));
            case 'sys-update':
                $r = $bg(array('/usr/local/sbin/sentora-node-sysupdate', !empty($d['security']) ? '--security' : '--all'));
                return array($r[0] === 0, $r[0] === 0 ? 'installing ' . (!empty($d['security']) ? 'security' : 'all') . ' updates' : 'sys-update: ' . self::Tail($r[1]));
            case 'reboot':
                $r = self::Run(array('shutdown', '-r', '+1', 'The Sentora panel asked for a restart of this node'), null, 30);
                return array($r[0] === 0, $r[0] === 0 ? 'restarting in a minute' : 'reboot: ' . self::Tail($r[1]));
            case 'check-updates':
                self::CheckUpdates($s, true);
                $bg(array('/usr/local/sbin/sentora-node-sysupdate', '--check'));
                return array(true, 'checked');
            case 'settings':
                $email = isset($d['email']) && is_string($d['email']) ? $d['email'] : '';
                if ($email !== '' && !preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $email)) {
                    return array(false, 'refused: bad email');
                }
                return self::Settings($email, !empty($d['local_certs']));
            case 'network':
                // the customers (their Linux users) whose apps may reach the local network; kept here, applied at once
                $users = array();
                foreach (isset($d['lan_users']) && is_array($d['lan_users']) ? $d['lan_users'] : array() as $u) {
                    if (is_string($u) && preg_match('/^sdh\d{1,9}$/', $u)) {
                        $users[] = $u;
                    }
                }
                $users = array_values(array_unique($users));
                sort($users, SORT_STRING);
                if (!is_dir(self::$state)) {
                    mkdir(self::$state, 0700, true);
                }
                if (!self::WriteFile(self::$state . '/network.json', json_encode(array('lan_users' => $users)))) {
                    return array(false, 'network: the setting could not be kept');
                }
                $fe = self::Fence(true);
                return array($fe === '', $fe === '' ? ($users ? 'the local network is open to the apps of ' . implode(', ', $users) . ' only' : 'no app may reach the local network') : $fe);
            case 'uninstall':
                // a little later, so this answer reaches the panel first
                $r = $bg(array('/usr/local/sbin/sentora-node-uninstall', '--purge'), 30);
                return array($r[0] === 0, $r[0] === 0 ? 'uninstalling in 30 seconds' : 'uninstall: ' . self::Tail($r[1]));
        }
        return null;
    }

    /**
     * The node's settings from the panel: Let's Encrypt's email, or Caddy's own certificates. The
     * same Caddyfile the installer writes; checked, reloaded, put back on failure.
     */
    static function Settings($email, $local)
    {
        $file = self::$caddy . '/Caddyfile';
        $t = "# Written by the Sentora Node installer. The containers' sites are in sentora/ (the agent writes them).\n{\n"
            . ($email !== '' ? "\temail " . $email . "\n" : '') . ($local ? "\tlocal_certs\n" : '')
            . "\tskip_install_trust\n\tadmin localhost:2019\n}\n\nimport /etc/caddy/sentora/*.caddy\n";
        $old = is_file($file) ? (string) file_get_contents($file) : '';
        if ($old !== $t) {
            self::WriteFile($file, $t, 0644);
            $v = self::Run(array(self::P('CADDY_BIN', 'caddy'), 'validate', '--config', $file, '--adapter', 'caddyfile'), null, 60);
            $r = $v[0] === 0 ? self::Run(array('systemctl', 'reload', self::P('CADDY_SERVICE', 'caddy')), null, 60) : $v;
            if ($r[0] !== 0) {
                self::WriteFile($file, $old, 0644);
                self::Run(array('systemctl', 'reload', self::P('CADDY_SERVICE', 'caddy')), null, 60);
                return array(false, 'settings: ' . self::Tail($r[1]));
            }
        }
        self::WriteFile(self::$etc . '/install.conf', "# Settings this node was installed with (read by sentora-node-update; set from the panel)\n"
            . ($email !== '' ? 'EMAIL=' . $email . "\n" : '') . ($local ? "LOCAL_CERTS=1\n" : ''));
        return array(true, 'settings: ' . ($local ? 'Caddy\'s own certificates' : 'Let\'s Encrypt') . ($email !== '' ? ', ' . $email : ''));
    }

    /**
     * Do one job: [ok, output].
     */
    static function Job($action, array $d, array &$s)
    {
        $nj = self::NodeJob($action, $d, $s);
        if ($nj !== null) {
            return $nj;
        }
        $bad = self::Check($d, $action);
        if ($bad !== '') {
            return array(false, 'refused: ' . $bad);
        }
        $name = self::Name($d['cid']);
        $key = (string) $d['cid'];
        // a container belongs to the user it was made for
        if ($action !== 'move' && isset($s['containers'][$key]) && $s['containers'][$key]['user'] !== $d['user']) {
            return array(false, 'refused: that container belongs to another user');
        }
        switch ($action) {
            case 'create':
                $e = self::EnsureUser($d['user']);
                if ($e !== '') {
                    return array(false, $e);
                }
                $fe = self::Fence();
                if ($fe !== '') {
                    self::Out($fe);
                }
                foreach ($s['containers'] as $k => $c) {
                    if ($k !== $key && (int) $c['hostport'] === $d['hostport']) {
                        return array(false, 'refused: port ' . $d['hostport'] . ' is taken');
                    }
                }
                $exists = self::Podman($d['user'], array('container', 'exists', $name));
                if ($exists[0] !== 0) {
                    $pull = self::Podman($d['user'], array('pull', '--quiet', $d['image']), 1800);
                    if ($pull[0] !== 0) {
                        return array(false, 'pull: ' . self::Tail($pull[1]));
                    }
                    $cr = self::Podman($d['user'], self::CreateArgs($d, $name));
                    if ($cr[0] !== 0) {
                        return array(false, 'create: ' . self::Tail($cr[1]));
                    }
                }
                $s['containers'][$key] = array('user' => $d['user'], 'domain' => $d['domain'], 'hostport' => $d['hostport'], 'public' => (int) $d['public'],
                    'auth' => (string) $d['auth'], 'volumes' => count($d['volumes']), 'image' => $d['image']);
                self::SaveState($s);
                $st = self::Podman($d['user'], array('start', $name));
                if ($st[0] !== 0) {
                    return array(false, 'start: ' . self::Tail($st[1]));
                }
                $px = self::Proxy($s);
                return $px === '' ? array(true, 'created and started') : array(false, $px);
            case 'update':
                if (!isset($s['containers'][$key])) {
                    return array(false, 'no such container here');
                }
                if ((int) $s['containers'][$key]['hostport'] !== $d['hostport'] || $s['containers'][$key]['domain'] !== $d['domain']) {
                    return array(false, 'refused: the address or port of an app does not change with an update');
                }
                $res = self::Update($d, $s);
                $s['containers'][$key]['image'] = $d['image'];
                return $res;
            case 'recreate':
                // the app's settings changed (its owner's): made again from the same version, with the
                // same data; when it does not start with them, the old one runs again
                if (!isset($s['containers'][$key])) {
                    return array(false, 'no such container here');
                }
                if ((int) $s['containers'][$key]['hostport'] !== $d['hostport'] || $s['containers'][$key]['domain'] !== $d['domain']) {
                    return array(false, 'refused: the address or port of an app does not change with its settings');
                }
                return self::Update($d, $s, false);
            case 'start':
            case 'stop':
            case 'restart':
                if (!isset($s['containers'][$key])) {
                    return array(false, 'no such container here');
                }
                $r = self::Podman($d['user'], $action === 'start' ? array('start', $name) : array($action, '--time', '20', $name), 300);
                if ($r[0] === 0) {
                    // (kept: after a restart of the server only the apps not stopped on purpose are started)
                    if ($action === 'stop') {
                        $s['containers'][$key]['stopped'] = 1;
                    } else {
                        unset($s['containers'][$key]['stopped']);
                    }
                    self::SaveState($s);
                }
                return array($r[0] === 0, $r[0] === 0 ? $action . ': done' : $action . ': ' . self::Tail($r[1]));
            case 'limits':
                if (!isset($s['containers'][$key], $d['cpu'], $d['mem'])) {
                    return array(false, 'no such container here');
                }
                $r = self::Podman($d['user'], array('update', '--cpus', sprintf('%d.%02d', intdiv($d['cpu'], 100), $d['cpu'] % 100), '--memory', $d['mem'] . 'm', '--memory-swap', $d['mem'] . 'm', $name));
                return array($r[0] === 0, $r[0] === 0 ? 'limits: done' : 'limits: ' . self::Tail($r[1]));
            case 'logs':
                if (!isset($s['containers'][$key])) {
                    return array(false, 'no such container here');
                }
                $n = isset($d['lines']) && is_int($d['lines']) ? max(10, min(1000, $d['lines'])) : 200;
                $r = self::Podman($d['user'], array('logs', '--tail', (string) $n, $name), 60);
                return array($r[0] === 0, substr($r[1], -60000));
            case 'proxy':
                if (!isset($s['containers'][$key], $d['public'])) {
                    return array(false, 'no such container here');
                }
                $s['containers'][$key]['public'] = (int) $d['public'] ? 1 : 0;
                $s['containers'][$key]['auth'] = (int) $d['public'] ? '' : (string) $d['auth'];
                if (!(int) $d['public'] && $s['containers'][$key]['auth'] === '') {
                    return array(false, 'refused: no setup password');
                }
                $px = self::Proxy($s);
                return $px === '' ? array(true, 'proxy: done') : array(false, $px);
            case 'inspect':
                if (!isset($s['containers'][$key])) {
                    return array(false, 'no such container here');
                }
                return self::Inspect($d, $s);
            case 'move':
                return self::Move($d, $s);
            case 'delete':
                if (self::Uid($d['user']) >= 0) {
                    $r = self::Podman($d['user'], array('rm', '--force', '--time', '10', '--ignore', $name), 300);
                    if ($r[0] !== 0) {
                        return array(false, 'rm: ' . self::Tail($r[1]));
                    }
                    $vols = self::Podman($d['user'], array('volume', 'ls', '--quiet', '--filter', 'name=^' . $name . '-[0-9]+$'));
                    foreach (preg_split('/\s+/', trim($vols[1])) as $v) {
                        if (preg_match('/^' . $name . '-\d+$/', $v)) {
                            self::Podman($d['user'], array('volume', 'rm', '--force', $v));
                        }
                    }
                }
                unset($s['containers'][$key]);
                $px = self::Proxy($s);
                return $px === '' ? array(true, 'deleted') : array(false, $px);
        }
        return array(false, 'refused: unknown job');
    }

    static function Tail($out)
    {
        $out = trim((string) $out);
        return strlen($out) > 2000 ? '...' . substr($out, -2000) : $out;
    }

    /**
     * Write Caddy's sites from the containers here, check them, reload; put the old ones back on failure.
     */
    static function Proxy(array $s)
    {
        $file = self::$caddy . '/sentora/sites.caddy';
        $t = "# Written by Sentora Node (sentora-node-agent) from the panel's containers: do not change by hand.\n";
        ksort($s['containers']);
        foreach ($s['containers'] as $cid => $c) {
            $t .= "\n# container " . (int) $cid . " (" . $c['user'] . ")\n" . $c['domain'] . " {\n\tencode zstd gzip\n";
            if (!(int) $c['public'] && $c['auth'] !== '') {
                // (Caddy before 2.8 wants the hash base64-encoded, under its older name)
                $t .= self::CaddyOld() ? "\tbasicauth {\n\t\tsetup " . base64_encode($c['auth']) . "\n\t}\n" : "\tbasic_auth {\n\t\tsetup " . $c['auth'] . "\n\t}\n";
            }
            $t .= "\treverse_proxy 127.0.0.1:" . (int) $c['hostport'] . "\n}\n";
        }
        $old = is_file($file) ? (string) file_get_contents($file) : null;
        if ($old === $t) {
            return '';
        }
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
            chmod(dirname($file), 0755);
        }
        self::WriteFile($file, $t, 0644);
        // (it holds the setup passwords' hashes: root and Caddy read it, nobody else)
        if (@chgrp($file, self::P('CADDY_GROUP', 'caddy'))) {
            @chmod($file, 0640);
        }
        $v = self::Run(array(self::P('CADDY_BIN', 'caddy'), 'validate', '--config', self::$caddy . '/Caddyfile', '--adapter', 'caddyfile'), null, 60);
        $r = $v[0] === 0 ? self::Run(array('systemctl', 'reload', self::P('CADDY_SERVICE', 'caddy')), null, 60) : $v;
        if ($r[0] !== 0) {
            if ($old === null) {
                @unlink($file);
            } else {
                self::WriteFile($file, $old, 0644);
            }
            self::Run(array('systemctl', 'reload', self::P('CADDY_SERVICE', 'caddy')), null, 60);
            return 'proxy: ' . self::Tail($r[1]);
        }
        return '';
    }

    // ----- reporting -----

    static function Info()
    {
        $os = '';
        if (is_file('/etc/os-release') && preg_match('/^PRETTY_NAME="?([^"\n]*)/m', (string) file_get_contents('/etc/os-release'), $m)) {
            $os = $m[1];
        }
        $mem = 0;
        if (preg_match('/^MemTotal:\s+(\d+)/m', (string) @file_get_contents('/proc/meminfo'), $m)) {
            $mem = intdiv((int) $m[1], 1024);
        }
        $cpus = preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo'));
        $home = is_dir(self::$home) ? self::$home : '/';
        $p = self::Run(array('podman', '--version'), null, 20);
        $c = self::Run(array(self::P('CADDY_BIN', 'caddy'), 'version'), null, 20);
        $se = self::Run(array('getenforce'), null, 10);
        return array('cpus' => max(1, (int) $cpus), 'mem_mb' => $mem, 'disk_total_mb' => (int) (disk_total_space($home) / 1048576), 'disk_free_mb' => (int) (disk_free_space($home) / 1048576),
            'os' => $os, 'kernel' => php_uname('r'), 'podman' => $p[0] === 0 ? trim(str_replace('podman version', '', $p[1])) : 'missing',
            'caddy' => $c[0] === 0 ? strtok(trim($c[1]), ' ') : 'missing', 'agent' => self::VERSION, 'selinux' => $se[0] === 0 ? trim($se[1]) : '',
            'apparmor' => is_file('/sys/module/apparmor/parameters/enabled') ? (trim((string) @file_get_contents('/sys/module/apparmor/parameters/enabled')) === 'Y' ? 'on' : 'off') : '')
            + self::SysInfo();
    }

    /**
     * The system's waiting updates and the last update run (sentora-node-sysupdate writes them; it
     * looks again every 6 hours).
     */
    static function SysInfo()
    {
        $f = self::$state . '/sysupdate.json';
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        $j = is_array($j) ? $j : array();
        $checked = isset($j['checked_at']) ? (int) $j['checked_at'] : 0;
        if (time() - $checked > 6 * 3600 && time() - self::$sysCheckAt > 3600) {
            self::$sysCheckAt = time();
            self::Run(array('systemd-run', '--unit', 'sentora-node-syscheck-' . time(), '--collect', '--quiet', '/usr/local/sbin/sentora-node-sysupdate', '--check'), null, 30);
        }
        $out = array();
        // the node's own settings (so the panel shows what the node really uses)
        $conf = is_file(self::$etc . '/install.conf') ? (string) file_get_contents(self::$etc . '/install.conf') : '';
        $out['cfg_local_certs'] = preg_match('/^LOCAL_CERTS=1$/m', $conf) ? 1 : 0;
        $lu = self::LanUsers();
        sort($lu, SORT_STRING);
        $out['cfg_lan_users'] = $lu;
        $out['cfg_email'] = preg_match('/^EMAIL=([A-Za-z0-9._%+@-]{3,200})$/m', $conf, $m) ? $m[1] : '';
        foreach (array('updates', 'security', 'reboot', 'checked_at', 'last_at', 'last_ok', 'last_kind', 'running') as $k) {
            $out['sys_' . $k] = isset($j[$k]) ? (is_bool($j[$k]) ? (int) $j[$k] : $j[$k]) : 0;
        }
        return $out;
    }

    /**
     * The containers' states (and their disk use, measured every 5 minutes).
     */
    static function States(array $s)
    {
        $out = array();
        $users = array();
        foreach ($s['containers'] as $cid => $c) {
            $users[$c['user']][] = (string) $cid;
        }
        $measure = time() - self::$diskAt >= 300;
        foreach ($users as $user => $cids) {
            $found = array();
            if (self::Uid($user) >= 0) {
                $r = self::Podman($user, array('ps', '--all', '--filter', 'label=sentora.cid', '--format', 'json'), 60);
                $list = $r[0] === 0 ? json_decode($r[1], true) : null;
                foreach (is_array($list) ? $list : array() as $p) {
                    $cid = isset($p['Labels']['sentora.cid']) ? (string) $p['Labels']['sentora.cid'] : '';
                    if ($cid !== '') {
                        $found[$cid] = strtolower((string) (isset($p['State']) ? $p['State'] : 'unknown'));
                    }
                }
            }
            foreach ($cids as $cid) {
                $st = isset($found[$cid]) ? $found[$cid] : 'missing';
                $st = in_array($st, array('exited', 'stopped', 'created', 'configured'), true) ? 'stopped' : $st;
                if ($measure) {
                    self::$disk[$cid] = self::DiskUse($user, (int) $cid, (int) $s['containers'][$cid]['volumes']);
                }
                $c = $s['containers'][$cid];
                $out[$cid] = array('state' => $st, 'used_mb' => isset(self::$disk[$cid]) ? self::$disk[$cid] : 0, 'user' => $user,
                    'update' => !empty($s['updates'][$cid]) ? 1 : 0,
                    // (what the panel needs to show an app it does not know, and to take it back)
                    'image' => isset($c['image']) ? (string) $c['image'] : '', 'domain' => (string) $c['domain'], 'hostport' => (int) $c['hostport'],
                    'volumes' => (int) $c['volumes'], 'public' => (int) $c['public']);
            }
        }
        if ($measure) {
            self::$diskAt = time();
        }
        return $out;
    }

    static function DiskUse($user, $cid, $n)
    {
        $mb = 0;
        for ($i = 0; $i < $n; $i++) {
            $r = self::Podman($user, array('volume', 'inspect', '--format', '{{.Mountpoint}}', self::Name($cid) . '-' . $i), 30);
            $dir = trim($r[1]);
            if ($r[0] === 0 && $dir !== '' && $dir[0] === '/' && is_dir($dir)) {
                $du = self::Run(array('du', '-sm', '-x', $dir), null, 120);
                $mb += (int) $du[1];
            }
        }
        return $mb;
    }

    /**
     * Once after each start of the server: the apps that were not stopped on purpose are started
     * (Podman's own podman-restart unit does not start "unless-stopped" containers on every system).
     */
    static function BootStart(array &$s)
    {
        // (a mark in /run, which every start of the server, or of its container, begins empty)
        $mark = (getenv('SNODE_RUNMARK') ?: '/run/sentora-node') . '/started';
        if (is_file($mark)) {
            return;
        }
        if (!is_dir(dirname($mark))) {
            @mkdir(dirname($mark), 0700, true);
        }
        foreach ($s['containers'] as $cid => $c) {
            if (!empty($c['stopped']) || self::Uid($c['user']) < 0) {
                continue;
            }
            $name = self::Name((int) $cid);
            $st = self::Podman($c['user'], array('container', 'inspect', '--format', '{{.State.Status}}', $name), 60);
            if ($st[0] === 0 && !in_array(trim($st[1]), array('running', 'paused'), true)) {
                $r = self::Podman($c['user'], array('start', $name), 300);
                self::Out('started ' . $name . ' after the restart of the server: ' . ($r[0] === 0 ? 'ok' : self::Tail($r[1])));
            }
        }
        @touch($mark);
    }

    /**
     * The customers' Linux users (sdh*) on this node.
     */
    static function Users()
    {
        $out = array();
        foreach ((array) @file(getenv('SNODE_PASSWD') ?: '/etc/passwd', FILE_IGNORE_NEW_LINES) as $l) {
            $p = explode(':', (string) $l);
            if (count($p) > 2 && preg_match('/^sdh\d{1,9}$/', $p[0])) {
                $out[] = $p[0];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Apps that are on this node but not in its list (the list was lost: the node was set up again
     * with the customers' users kept, or state.json was removed): found by the label every app has,
     * with what can be read back (image, port, folders, its address from Caddy's sites), and listed
     * again, so the panel sees them and can take them back. Nothing is changed or removed.
     * Returns how many were added.
     */
    static function Scan(array &$s)
    {
        $added = 0;
        $sites = (string) @file_get_contents(self::$caddy . '/sentora/sites.caddy');
        foreach (self::Users() as $user) {
            if (self::Uid($user) < 0) {
                continue;
            }
            $r = self::Podman($user, array('ps', '--all', '--filter', 'label=sentora.cid', '--format', 'json'), 60);
            $list = $r[0] === 0 ? json_decode($r[1], true) : null;
            foreach (is_array($list) ? $list : array() as $p) {
                $cid = isset($p['Labels']['sentora.cid']) ? (string) $p['Labels']['sentora.cid'] : '';
                $names = isset($p['Names']) && is_array($p['Names']) ? $p['Names'] : array();
                if (!ctype_digit($cid) || (int) $cid <= 0 || isset($s['containers'][$cid]) || !in_array(self::Name((int) $cid), $names, true)) {
                    continue;
                }
                $name = self::Name((int) $cid);
                $img = self::Podman($user, array('container', 'inspect', '--format', '{{.ImageName}}', $name));
                // ("3001/tcp -> 127.0.0.1:20000")
                $ports = self::Podman($user, array('port', $name));
                $hostport = preg_match('/127\.0\.0\.1:(\d{5})\b/', $ports[1], $m) ? (int) $m[1] : 0;
                $vols = self::Podman($user, array('volume', 'ls', '--quiet', '--filter', 'name=^' . $name . '-[0-9]+$'));
                $nv = 0;
                foreach (preg_split('/\s+/', trim($vols[1])) as $v) {
                    $nv += preg_match('/^' . $name . '-\d+$/', $v) ? 1 : 0;
                }
                // its address and setup password (hash) from the sites Caddy has
                $domain = $auth = '';
                if (preg_match('/^# container ' . (int) $cid . ' \(' . $user . '\)\n([a-z0-9.-]{1,253}) \{\n(.*?)\n\}/ms', $sites, $m)) {
                    $domain = $m[1];
                    $auth = preg_match('#basic_?auth \{\n\t\tsetup ([A-Za-z0-9+/=$./]{60,90})\n#', $m[2], $a) ? $a[1] : '';
                    // (an older Caddy's base64-encoded hash)
                    if ($auth !== '' && strpos($auth, '$2') !== 0) {
                        $auth = (string) base64_decode($auth, true);
                    }
                    $auth = preg_match('#^\$2[aby]\$\d\d\$[./A-Za-z0-9]{53}$#', $auth) ? $auth : '';
                }
                $s['containers'][$cid] = array('user' => $user, 'domain' => $domain, 'hostport' => $hostport, 'public' => $auth === '' ? 1 : 0, 'auth' => $auth,
                    'volumes' => $nv, 'image' => trim($img[1]), 'found' => 1);
                self::Out('found ' . $name . ' of ' . $user . ($domain !== '' ? ' (' . $domain . ')' : '') . ': listed again for the panel');
                $added++;
            }
        }
        $s['scanned_at'] = time();
        return $added;
    }

    /**
     * What the panel needs to take an app back as it is: its own settings (the image's own
     * variables left out), limits and the exact version it runs. [ok, JSON]
     */
    static function Inspect(array $d, array $s)
    {
        $name = self::Name($d['cid']);
        $u = $d['user'];
        $env = self::Podman($u, array('container', 'inspect', '--format', '{{json .Config.Env}}', $name));
        if ($env[0] !== 0) {
            return array(false, 'no such container here');
        }
        $id = self::Podman($u, array('container', 'inspect', '--format', '{{.Image}}', $name));
        $lim = self::Podman($u, array('container', 'inspect', '--format', '{{.HostConfig.NanoCpus}} {{.HostConfig.Memory}}', $name));
        $imgEnv = self::Podman($u, array('image', 'inspect', '--format', '{{json .Config.Env}}', trim($id[1])));
        $base = $imgEnv[0] === 0 && is_array(json_decode($imgEnv[1], true)) ? json_decode($imgEnv[1], true) : array();
        $out = array();
        foreach ((array) json_decode($env[1], true) as $line) {
            if (!is_string($line) || in_array($line, $base, true) || !preg_match('/^([A-Za-z_][A-Za-z0-9_]{0,63})=(.*)$/s', $line, $m) || strlen($m[2]) > 4096 || preg_match('/[\x00\r\n]/', $m[2])
                || in_array($m[1], array('HOSTNAME', 'HOME', 'container', 'TERM'), true)) {
                // (Podman's own: not the app's settings)
                continue;
            }
            $out[$m[1]] = $m[2];
        }
        $l = preg_split('/\s+/', trim($lim[1]));
        return array(true, json_encode(array('env' => (object) array_slice($out, 0, 50, true), 'cpu' => isset($l[0]) ? (int) round((int) $l[0] / 10000000) : 0,
            'mem' => isset($l[1]) ? (int) round((int) $l[1] / 1048576) : 0, 'image' => isset($s['containers'][(string) $d['cid']]['image']) ? (string) $s['containers'][(string) $d['cid']]['image'] : '')));
    }

    /**
     * Move an app to another customer (Linux user) and/or number: the same version and the same
     * data, made again under its new owner; the old one is removed only once the new one runs.
     * When anything fails, the new one goes and the old one runs again. [ok, output]
     */
    static function Move(array $d, array &$s)
    {
        if (!isset($d['from_cid'], $d['from_user']) || !is_int($d['from_cid']) || $d['from_cid'] <= 0 || !is_string($d['from_user']) || !preg_match('/^sdh\d{1,9}$/', $d['from_user'])) {
            return array(false, 'refused: bad app to move');
        }
        $from = (string) $d['from_cid'];
        $fu = $d['from_user'];
        $u = $d['user'];
        $key = (string) $d['cid'];
        if ($from === $key && $fu === $u) {
            return array(false, 'refused: nothing to move');
        }
        if (!isset($s['containers'][$from]) || $s['containers'][$from]['user'] !== $fu) {
            return array(false, 'no such container here');
        }
        if ($from !== $key && isset($s['containers'][$key])) {
            return array(false, 'refused: app number ' . $key . ' is in use here');
        }
        foreach ($s['containers'] as $k => $c) {
            if ((string) $k !== $from && (int) $c['hostport'] === $d['hostport']) {
                return array(false, 'refused: port ' . $d['hostport'] . ' is taken');
            }
        }
        if ((int) $s['containers'][$from]['volumes'] !== count($d['volumes'])) {
            return array(false, 'refused: the app has ' . (int) $s['containers'][$from]['volumes'] . ' data folders, the panel\'s app ' . count($d['volumes']));
        }
        $e = self::EnsureUser($u);
        if ($e !== '') {
            return array(false, $e);
        }
        $fe = self::Fence();
        if ($fe !== '') {
            self::Out($fe);
        }
        $old = self::Name((int) $from);
        $name = self::Name($d['cid']);
        $id = self::Podman($fu, array('container', 'inspect', '--format', '{{.Image}}', $old));
        $id = trim($id[1]);
        if (!preg_match('/^(sha256:)?[A-Za-z0-9._-]{3,128}$/', $id)) {
            return array(false, 'the version the app runs could not be read');
        }
        // work folders in the two users' homes (each user reads only its own)
        $dir = function ($user) {
            $p = self::$home . '/' . $user . '/.sentora-move';
            if (!is_dir($p)) {
                @mkdir($p, 0700, true);
            }
            @chown($p, $user);
            @chmod($p, 0700);
            return $p;
        };
        $from_dir = $dir($fu);
        $to_dir = $dir($u);
        $hand = function ($file) use ($to_dir, $u) {
            $to = $to_dir . '/' . basename($file);
            if (!@rename($file, $to)) {
                return '';
            }
            @chown($to, $u);
            @chmod($to, 0600);
            return $to;
        };
        $made = array();
        $clean = function () use (&$made, $u, $from_dir, $to_dir) {
            foreach ($made as $m) {
                self::Podman($u, $m[0] === 'c' ? array('rm', '--force', '--ignore', $m[1]) : array('volume', 'rm', '--force', $m[1]));
            }
            foreach (array($from_dir, $to_dir) as $p) {
                foreach ((array) glob($p . '/*.tar') as $f) {
                    @unlink($f);
                }
                @rmdir($p);
            }
        };
        $back = function ($why) use ($clean, $fu, $old) {
            $clean();
            self::Podman($fu, array('start', $old));
            return array(false, $why . ': the app runs again where it was');
        };
        self::Podman($fu, array('stop', '--time', '20', $old), 120);
        if ($fu !== $u) {
            // the same version for the new owner (not downloaded again: it may be newer by now)
            @unlink($from_dir . '/image.tar');
            @unlink($to_dir . '/image.tar');
            $r = self::Podman($fu, array('image', 'save', '--output', $from_dir . '/image.tar', $id), 1800);
            $f = $r[0] === 0 ? $hand($from_dir . '/image.tar') : '';
            $r = $f !== '' ? self::Podman($u, array('image', 'load', '--input', $f), 1800) : $r;
            if ($f !== '') {
                @unlink($f);
            }
            if ($f === '' || $r[0] !== 0) {
                return $back('the app\'s version could not be handed over (' . self::Tail($r[1]) . ')');
            }
        }
        for ($i = 0; $i < count($d['volumes']); $i++) {
            $tar = $from_dir . '/v' . $i . '.tar';
            @unlink($tar);
            @unlink($to_dir . '/v' . $i . '.tar');
            $r = self::Podman($fu, array('volume', 'export', '--output', $tar, $old . '-' . $i), 3600);
            $f = $r[0] === 0 ? $hand($tar) : '';
            if ($f === '') {
                return $back('data folder ' . $i . ' could not be read (' . self::Tail($r[1]) . ')');
            }
            $r = self::Podman($u, array('volume', 'create', $name . '-' . $i));
            if ($r[0] !== 0) {
                return $back('data folder ' . $i . ' could not be made (' . self::Tail($r[1]) . ')');
            }
            $made[] = array('v', $name . '-' . $i);
            $r = self::Podman($u, array('volume', 'import', $name . '-' . $i, $f), 3600);
            @unlink($f);
            if ($r[0] !== 0) {
                return $back('data folder ' . $i . ' could not be copied (' . self::Tail($r[1]) . ')');
            }
        }
        $d['image'] = $id;
        $r = self::Podman($u, self::CreateArgs($d, $name));
        if ($r[0] !== 0) {
            return $back('the app could not be made for its new owner (' . self::Tail($r[1]) . ')');
        }
        $made[] = array('c', $name);
        $r = self::Podman($u, array('start', $name));
        if ($r[0] !== 0) {
            return $back('the app did not start for its new owner (' . self::Tail($r[1]) . ')');
        }
        sleep((int) (getenv('SNODE_UPDATE_WAIT') !== false ? getenv('SNODE_UPDATE_WAIT') : 20));
        $st = self::Podman($u, array('container', 'inspect', '--format', '{{.State.Status}}', $name));
        if (trim($st[1]) !== 'running') {
            return $back('the app stopped after starting for its new owner (' . trim($st[1]) . ')');
        }
        // it runs: the old one and its data go, the sites point to the new one
        $image = isset($s['containers'][$from]['image']) ? (string) $s['containers'][$from]['image'] : '';
        unset($s['containers'][$from]);
        $s['containers'][$key] = array('user' => $u, 'domain' => $d['domain'], 'hostport' => $d['hostport'], 'public' => (int) $d['public'],
            'auth' => (string) $d['auth'], 'volumes' => count($d['volumes']), 'image' => $image);
        self::SaveState($s);
        if ($fu !== $u || $old !== $name) {
            self::Podman($fu, array('rm', '--force', '--ignore', $old), 300);
            for ($i = 0; $i < count($d['volumes']); $i++) {
                self::Podman($fu, array('volume', 'rm', '--force', $old . '-' . $i));
            }
        }
        foreach (array($from_dir, $to_dir) as $p) {
            foreach ((array) glob($p . '/*.tar') as $f) {
                @unlink($f);
            }
            @rmdir($p);
        }
        $px = self::Proxy($s);
        return $px === '' ? array(true, 'moved to ' . $u . ' as ' . $name . ', with its data') : array(false, $px);
    }

    // ----- commands -----

    static function Pair(array $opt)
    {
        foreach (array('panel', 'key', 'code') as $k) {
            if (!isset($opt[$k])) {
                self::Out('pair needs --panel, --key and --code');
                return 2;
            }
        }
        if (!preg_match('#^https?://[A-Za-z0-9.-]{1,253}(:\d{1,5})?$#', rtrim($opt['panel'], '/'))) {
            self::Out('--panel must be https://name (with a port if needed)');
            return 2;
        }
        $k = explode('.', $opt['key']);
        $sp = count($k) === 3 && $k[0] === 'v1' ? self::Bin($k[1], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) : false;
        $bp = count($k) === 3 && $k[0] === 'v1' ? self::Bin($k[2], SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) : false;
        if (!$sp || !$bp || !preg_match('/^\d{1,9}\.[0-9a-f]{32}$/', $opt['code'])) {
            self::Out('--key or --code is not right: copy the command from the panel again');
            return 2;
        }
        $sign = sodium_crypto_sign_keypair();
        $box = sodium_crypto_box_keypair();
        $cfg = array('panel' => rtrim($opt['panel'], '/'), 'panel_sign_pk' => $k[1], 'panel_box_pk' => $k[2], 'tls_verify' => !isset($opt['no-tls-check']));
        $msg = self::Msg('pair', 0, array('code' => $opt['code'], 'sign_pk' => self::B64(sodium_crypto_sign_publickey($sign)), 'box_pk' => self::B64(sodium_crypto_box_publickey($box)),
            'info' => self::Info()));
        $r = self::Call($msg, sodium_crypto_sign_secretkey($sign), $box, $cfg);
        if (!$r || $r['type'] !== 'paired' || !isset($r['data']['node'])) {
            self::Out('Not linked. Check the panel\'s address, that the code is not older than an hour or used, and the clock (timedatectl).');
            return 1;
        }
        $cfg['node'] = (int) $r['data']['node'];
        $cfg['sign_sk'] = self::B64(sodium_crypto_sign_secretkey($sign));
        $cfg['box_kp'] = self::B64($box);
        if (!is_dir(self::$etc)) {
            mkdir(self::$etc, 0700, true);
        }
        self::WriteFile(self::$etc . '/node.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::Out('Linked to ' . $cfg['panel'] . ' as node ' . $cfg['node'] . ' ("' . (isset($r['data']['name']) ? $r['data']['name'] : '') . '").');
        return 0;
    }

    /**
     * Make this node's keys and its node key, for the panel's administrator to paste in "Add a node".
     */
    static function Enroll(array $opt)
    {
        if (!isset($opt['panel']) || !preg_match('#^https?://[A-Za-z0-9.-]{1,253}(:\d{1,5})?$#', rtrim($opt['panel'], '/'))) {
            self::Out('enroll needs --panel https://<the panel\'s name or IP address>');
            return 2;
        }
        $panel = rtrim($opt['panel'], '/');
        $k = self::PanelKey($panel, !isset($opt['no-tls-check']));
        if (!$k) {
            return 1;
        }
        $p = explode('.', $k[0]);
        $sp = self::Bin($p[1], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $bp = self::Bin($p[2], SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
        $fp = self::Fingerprint($sp, $bp);
        if (isset($opt['panel-code']) && $opt['panel-code'] !== '') {
            if (strtoupper(trim($opt['panel-code'])) !== $fp) {
                self::Out('The panel answered with code ' . $fp . ', not ' . $opt['panel-code'] . ': that is not your panel (or the code was mistyped). Nothing was changed.');
                return 1;
            }
        } else {
            self::Out('');
            self::Out('The panel at ' . $panel . ' answered with this code:  ' . $fp);
            self::Out('Check it in the panel: Docker Hosting > Nodes and apps shows "This panel\'s code". They must be the same:');
            self::Out('if they are not, something between this server and the panel is pretending to be it.');
            $ans = function_exists('readline') ? readline('Is it the same code? [y/N] ') : (fwrite(STDOUT, 'Is it the same code? [y/N] ') ? fgets(STDIN) : '');
            if (!in_array(strtolower(trim((string) $ans)), array('y', 'yes'), true)) {
                self::Out('Stopped: nothing was changed.');
                return 1;
            }
        }
        $secret = random_bytes(15);
        $sign = sodium_crypto_sign_keypair();
        $box = sodium_crypto_box_keypair();
        $cfg = array('panel' => $panel, 'panel_sign_pk' => $p[1], 'panel_box_pk' => $p[2], 'tls_verify' => $k[1], 'node' => 0,
            'claim' => bin2hex($secret), 'sign_sk' => self::B64(sodium_crypto_sign_secretkey($sign)), 'box_kp' => self::B64($box));
        if (!is_dir(self::$etc)) {
            mkdir(self::$etc, 0700, true);
        }
        self::WriteFile(self::$etc . '/node.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $key = 'SN1-' . implode('-', str_split(self::Base32($secret), 4));
        $file = getenv('SNODE_KEYFILE') ?: '/root/sentora-node-key.txt';
        self::WriteFile($file, "Sentora Node key for the panel at " . $panel . " (made " . date('Y-m-d H:i') . "):\n\n    " . $key . "\n\n"
            . "In the panel: Docker Hosting > Nodes and apps > Add a node: give the node a name and paste this key.\n"
            . "It links this node once; after that it is not needed any more (this file is then removed).\n");
        self::Out('');
        self::Out('Node key:  ' . $key);
        self::Out('(also saved in ' . $file . ', readable by root only)');
        return 0;
    }

    /**
     * Link this node again, keeping every app and its data:
     *  - the same panel at a new address (its keys are the same): only the address changes;
     *  - a panel that no longer knows this node (it was removed there, the panel was set up again or
     *    restored, its keys changed) or another panel: new keys and a new node key to add in the panel
     *    (--new-key forces this for the same panel). The apps are then listed there under "Found on
     *    this node" and the administrator gives each back to its owner.
     * (Run through sentora-node-relink, which stops the agent first and starts it again after.)
     */
    static function Relink(array $opt)
    {
        if (!isset($opt['panel']) || !preg_match('#^https?://[A-Za-z0-9.-]{1,253}(:\d{1,5})?$#', rtrim($opt['panel'], '/'))) {
            self::Out('relink needs --panel https://<the panel\'s name or IP address>');
            return 2;
        }
        $panel = rtrim($opt['panel'], '/');
        $old = self::LoadCfg();
        if ($old && (int) $old['node'] > 0 && !isset($opt['new-key'])) {
            $k = self::PanelKey($panel, !isset($opt['no-tls-check']));
            if (!$k) {
                return 1;
            }
            $p = explode('.', $k[0]);
            if ($p[1] === $old['panel_sign_pk'] && $p[2] === $old['panel_box_pk']) {
                if ($panel === $old['panel']) {
                    self::Out('This node is linked to ' . $panel . ' as node ' . $old['node'] . ' already, and that panel has the same keys.');
                    self::Out('If the panel no longer knows this node (it was removed there, or the panel was set up again), link it anew, apps kept:');
                    self::Out('  sentora-node-relink --panel ' . $panel . ' --new-key');
                    return 1;
                }
                // the same panel (the same keys) at another address
                $old['panel'] = $panel;
                $old['tls_verify'] = $k[1];
                self::WriteFile(self::$etc . '/node.json', json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                self::Out('The panel\'s address is now ' . $panel . ' (the same panel, its keys checked; still node ' . $old['node'] . '). Apps and settings unchanged.');
                return 0;
            }
        }
        // a new link: the old keys are kept aside, the apps stay; the answers to the old panel's jobs are dropped
        // (job numbers start again on another panel)
        // (the old keys stay in use if this stops half-way; once linked anew they are kept aside)
        $f = self::$etc . '/node.json';
        $before = is_file($f) ? (string) file_get_contents($f) : '';
        $r = self::Enroll($opt);
        if ($r !== 0) {
            return $r;
        }
        if ($before !== '') {
            self::WriteFile($f . '.old-' . date('YmdHis'), $before);
        }
        $s = self::State();
        $s['done'] = $s['results'] = array();
        unset($s['scanned_at']);
        self::SaveState($s);
        self::Out('');
        self::Out('The ' . count($s['containers']) . ' apps on this node keep running, with their data. Once the node is added in the panel,');
        self::Out('they show there under "Found on this node", where the administrator gives each back to its owner.');
        return 0;
    }

    /**
     * A node not added in the panel yet says hello until the administrator adds it with its node key.
     * Returns the seconds to wait.
     */
    static function Hello()
    {
        $cfg = self::$cfg;
        $sk = self::Bin($cfg['sign_sk'], SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $kp = self::Bin($cfg['box_kp'], SODIUM_CRYPTO_BOX_KEYPAIRBYTES);
        // (the key itself, sealed so only the panel reads it; the panel keeps only its hash)
        $msg = self::Msg('hello', 0, array('claim' => $cfg['claim'], 'sign_pk' => self::B64(sodium_crypto_sign_publickey_from_secretkey($sk)),
            'box_pk' => self::B64(sodium_crypto_box_publickey($kp)), 'info' => self::Info()));
        $r = self::Call($msg, $sk, $kp, $cfg);
        if (!$r) {
            return 30;
        }
        if ($r['type'] === 'linked' && isset($r['data']['node']) && (int) $r['data']['node'] > 0) {
            $cfg['node'] = (int) $r['data']['node'];
            unset($cfg['claim']);
            self::WriteFile(self::$etc . '/node.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            self::$cfg = $cfg;
            @unlink(getenv('SNODE_KEYFILE') ?: '/root/sentora-node-key.txt');
            self::Out('Linked to ' . $cfg['panel'] . ' as node ' . $cfg['node'] . ' ("' . (isset($r['data']['name']) ? $r['data']['name'] : '') . '").');
            return 1;
        }
        return 10;
    }

    /**
     * One call to the panel: the report and results out, the jobs in, done; then again while there were jobs.
     * Returns the seconds to wait before the next call.
     */
    /**
     * The customers (Linux users) whose apps may reach the local network: the panel's list, nobody until it says so.
     */
    static function LanUsers()
    {
        $j = @json_decode((string) @file_get_contents(self::$state . '/network.json'), true);
        $out = array();
        foreach (is_array($j) && isset($j['lan_users']) && is_array($j['lan_users']) ? $j['lan_users'] : array() as $u) {
            if (is_string($u) && preg_match('/^sdh\d{1,9}$/', $u)) {
                $out[] = $u;
            }
        }
        return $out;
    }

    /**
     * Where the apps may not go. The customers' Linux users (sdh*) are in an nftables set; their
     * traffic (Podman's network runs as them) is refused to link-local, where the cloud's metadata
     * address 169.254.169.254 hands out the server's own keys, and, unless the panel allows it, to
     * private addresses (the local network: other servers, routers, the panel by its local address).
     * The node's own DNS servers stay reachable (the apps' names are looked up through them).
     * Written again when the users or the setting change, and every hour (a firewall reload could remove it).
     */
    static function Fence($force = false)
    {
        $uids = $lanUids = array();
        $lanUsers = self::LanUsers();
        foreach ((array) @file(getenv('SNODE_PASSWD') ?: '/etc/passwd', FILE_IGNORE_NEW_LINES) as $l) {
            $p = explode(':', (string) $l);
            if (count($p) > 2 && preg_match('/^sdh\d{1,9}$/', $p[0]) && ctype_digit($p[2])) {
                $uids[] = (int) $p[2];
                if (in_array($p[0], $lanUsers, true)) {
                    $lanUids[] = (int) $p[2];
                }
            }
        }
        sort($uids);
        sort($lanUids);
        $dns4 = $dns6 = array();
        foreach ((array) @file(getenv('SNODE_RESOLV') ?: '/etc/resolv.conf', FILE_IGNORE_NEW_LINES) as $l) {
            if (preg_match('/^\s*nameserver\s+(\S+)/', (string) $l, $m)) {
                if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $dns4[] = $m[1];
                } elseif (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $dns6[] = $m[1];
                }
            }
        }
        $key = json_encode(array($uids, $lanUids, $dns4, $dns6));
        if (!$force && self::$fence === $key && time() - self::$fenceAt < 3600) {
            return '';
        }
        $t = "table inet sentora_node {}\ndelete table inet sentora_node\ntable inet sentora_node {\n"
            . "\tset apps { type uid;" . ($uids ? ' elements = { ' . implode(', ', $uids) . ' };' : '') . " }\n"
            . "\tset lan { type uid;" . ($lanUids ? ' elements = { ' . implode(', ', $lanUids) . ' };' : '') . " }\n"
            . "\tchain out {\n\t\ttype filter hook output priority filter; policy accept;\n";
        // (the node's own DNS servers: the apps' names are looked up through them, also on a local network)
        {
            foreach (array('ip' => $dns4, 'ip6' => $dns6) as $fam => $list) {
                if ($list) {
                    $t .= "\t\tmeta skuid @apps " . $fam . " daddr { " . implode(', ', $list) . " } meta l4proto { tcp, udp } th dport 53 accept\n";
                }
            }
        }
        $t .= "\t\tmeta skuid @apps ip daddr 169.254.0.0/16 reject\n"
            . "\t\tmeta skuid @apps ip6 daddr { fe80::/10, fd00:ec2::254/128 } reject\n";
        // (the customers the panel allows the local network skip these two)
        $t .= "\t\tmeta skuid @apps meta skuid != @lan ip daddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 } reject\n"
            . "\t\tmeta skuid @apps meta skuid != @lan ip6 daddr fc00::/7 reject\n";
        $t .= "\t}\n}\n";
        $f = self::$state . '/fence.nft';
        if (!is_dir(self::$state)) {
            mkdir(self::$state, 0700, true);
        }
        self::WriteFile($f, $t);
        $r = self::Run(array('nft', '-f', $f), null, 30);
        self::$fenceAt = time();
        if ($r[0] === 0) {
            self::$fence = $key;
            return '';
        }
        // (no nft here: said once an hour)
        return 'fence: ' . self::Tail($r[1]);
    }

    static function Once()
    {
        $cfg = self::$cfg;
        $fe = self::Fence();
        if ($fe !== '') {
            self::Out($fe);
        }
        $sk = self::Bin($cfg['sign_sk'], SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $kp = self::Bin($cfg['box_kp'], SODIUM_CRYPTO_BOX_KEYPAIRBYTES);
        $s = self::State();
        self::BootStart($s);
        if (!isset($s['scanned_at']) || time() - (int) $s['scanned_at'] > 3600) {
            if (self::Scan($s) > 0) {
                self::Proxy($s);
            }
            self::SaveState($s);
        }
        self::CheckUpdates($s);
        for ($round = 0; $round < 20; $round++) {
            $s = self::State();
            $msg = self::Msg('poll', $cfg['node'], array('info' => self::Info(), 'results' => array_values($s['results']), 'states' => (object) self::States($s)));
            $r = self::Call($msg, $sk, $kp, $cfg);
            if (!$r) {
                return 30;
            }
            // the panel has the results now
            $s['results'] = array();
            self::SaveState($s);
            $jobs = isset($r['data']['jobs']) && is_array($r['data']['jobs']) ? $r['data']['jobs'] : array();
            foreach ($jobs as $j) {
                if (!is_array($j) || !isset($j['id'], $j['action'], $j['data']) || !is_int($j['id']) || !is_string($j['action']) || !is_array($j['data'])) {
                    continue;
                }
                $id = (string) $j['id'];
                $s = self::State();
                if (isset($s['done'][$id])) {
                    // sent again: the same answer
                    $res = $s['done'][$id];
                } else {
                    $t = time();
                    $res = self::Job($j['action'], $j['data'], $s);
                    self::Out(sprintf('job %d %s %s: %s (%ds)', $j['id'], $j['action'], isset($j['data']['cid']) ? 'container ' . (int) $j['data']['cid'] : '', $res[0] ? 'ok' : 'failed: ' . strtok($res[1], "\n"), time() - $t));
                }
                $s['done'][$id] = $res;
                $s['results'][$id] = array('id' => (int) $id, 'ok' => (bool) $res[0], 'out' => (string) $res[1]);
                self::SaveState($s);
            }
            if (!$jobs) {
                return isset($r['data']['interval']) ? max(5, min(60, (int) $r['data']['interval'])) : 10;
            }
        }
        return 5;
    }

    static function Main(array $argv)
    {
        self::Init();
        $cmd = isset($argv[1]) ? $argv[1] : '';
        $opt = array();
        for ($i = 2; $i < count($argv); $i++) {
            if (preg_match('/^--([a-z-]+)$/', $argv[$i], $m)) {
                $opt[$m[1]] = in_array($m[1], array('no-tls-check', 'security', 'all', 'new-key'), true) ? true : (isset($argv[$i + 1]) ? $argv[++$i] : '');
            }
        }
        if ($cmd === 'pair') {
            return self::Pair($opt);
        }
        if ($cmd === 'enroll') {
            return self::Enroll($opt);
        }
        if ($cmd === 'relink') {
            return self::Relink($opt);
        }
        if (!self::LoadCfg()) {
            self::Out('This node is not linked to a panel yet (sentora-node-agent pair ...).');
            return 1;
        }
        if ($cmd === 'once') {
            if ((int) self::$cfg['node'] === 0) {
                self::Hello();
                return 0;
            }
            self::Once();
            return 0;
        }
        if ($cmd === 'status') {
            $s = self::State();
            if ((int) self::$cfg['node'] === 0) {
                self::Out('Sentora Node agent ' . self::VERSION . '. Panel: ' . self::$cfg['panel'] . ': not added there yet (its node key is in ' . (getenv('SNODE_KEYFILE') ?: '/root/sentora-node-key.txt') . ').');
                return 0;
            }
            self::Out('Sentora Node agent ' . self::VERSION . '. Panel: ' . self::$cfg['panel'] . ', node ' . self::$cfg['node'] . ', ' . count($s['containers']) . ' containers');
            foreach (self::States($s) as $cid => $st) {
                self::Out(sprintf('  %s  %-8s %-10s %s (%d MB)', self::Name($cid), $st['user'], $st['state'], $s['containers'][$cid]['domain'], $st['used_mb']));
            }
            return 0;
        }
        if ($cmd === 'run') {
            self::Out('Sentora Node agent ' . self::VERSION . ': calling ' . self::$cfg['panel'] . ((int) self::$cfg['node'] === 0 ? ', waiting to be added there with its node key' : ' as node ' . self::$cfg['node']));
            while (true) {
                sleep((int) self::$cfg['node'] === 0 ? self::Hello() : self::Once());
            }
        }
        self::Out("sentora-node-agent enroll|relink|pair|run|once|status");
        return 2;
    }
}

exit(snode::Main($argv));
