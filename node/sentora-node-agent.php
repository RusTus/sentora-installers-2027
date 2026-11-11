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
 *   sentora-node-agent pair --panel https://panel.example --key v1.<sign>.<box> --code <id>.<secret> [--no-tls-check]
 *   sentora-node-agent run       (the service)
 *   sentora-node-agent once      (one call to the panel)
 *   sentora-node-agent status
 *
 * Paths for tests: SNODE_ETC (/etc/sentora-node), SNODE_STATE (/var/lib/sentora-node),
 * SNODE_CADDY (/etc/caddy), SNODE_RUN (/run/user), SNODE_HOME (/home), SNODE_SUBID (/etc: subuid,
 * subgid), SNODE_USERPATH (PATH of the customers' commands).
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
    const VERSION = '100';
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

    static function Init()
    {
        self::$etc = getenv('SNODE_ETC') ?: '/etc/sentora-node';
        self::$state = getenv('SNODE_STATE') ?: '/var/lib/sentora-node';
        self::$caddy = getenv('SNODE_CADDY') ?: '/etc/caddy';
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
     * Send a message to the panel; its opened reply, or null (with the reason on the output).
     */
    static function Call(array $msg, $signSk, $boxKp, array $cfg)
    {
        $panelSign = self::Bin($cfg['panel_sign_pk'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $panelBox = self::Bin($cfg['panel_box_pk'], SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
        $c = curl_init(rtrim($cfg['panel'], '/') . '/modules/docker_hosting/code/node.php');
        curl_setopt_array($c, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => self::Pack($msg, $signSk, $panelBox), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array('Content-Type: text/plain', 'User-Agent: sentora-node/' . self::VERSION), CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP));
        if (empty($cfg['tls_verify'])) {
            // (safe: every reply is signed with the panel's key, which the node already knows)
            curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $body = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        $err = curl_error($c);
        curl_close($c);
        if ($body === false || $code !== 200) {
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
        if (!is_array($c) || !isset($c['node'], $c['panel'], $c['sign_sk'], $c['box_kp'], $c['panel_sign_pk'], $c['panel_box_pk'])) {
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
     * Run a command (no shell). Returns [exit code, output (stdout and stderr together)].
     */
    static function Run(array $argv, $cwd = null, $timeout = 600)
    {
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
            $r = self::Run(array('useradd', '--create-home', '--home-dir', self::$home . '/' . $user, '--shell', '/sbin/nologin', '--comment', 'Sentora Docker Hosting', $user));
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
        if ($action === 'create') {
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
     * Do one job: [ok, output].
     */
    static function Job($action, array $d, array &$s)
    {
        $bad = self::Check($d, $action);
        if ($bad !== '') {
            return array(false, 'refused: ' . $bad);
        }
        $name = self::Name($d['cid']);
        $key = (string) $d['cid'];
        // a container belongs to the user it was made for
        if (isset($s['containers'][$key]) && $s['containers'][$key]['user'] !== $d['user']) {
            return array(false, 'refused: that container belongs to another user');
        }
        switch ($action) {
            case 'create':
                $e = self::EnsureUser($d['user']);
                if ($e !== '') {
                    return array(false, $e);
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
                    $args = array('create', '--name', $name, '--label', 'sentora.cid=' . $d['cid'], '--restart', 'unless-stopped',
                        '--cpus', sprintf('%d.%02d', intdiv($d['cpu'], 100), $d['cpu'] % 100), '--memory', $d['mem'] . 'm', '--memory-swap', $d['mem'] . 'm',
                        '--pids-limit', '1024', '--security-opt', 'no-new-privileges', '--log-driver', 'k8s-file', '--log-opt', 'max-size=10mb',
                        '--publish', '127.0.0.1:' . $d['hostport'] . ':' . $d['port']);
                    foreach (array_values($d['volumes']) as $i => $path) {
                        $args[] = '--volume';
                        $args[] = $name . '-' . $i . ':' . $path;
                    }
                    foreach ($d['env'] as $k => $v) {
                        $args[] = '--env';
                        $args[] = $k . '=' . $v;
                    }
                    $args[] = $d['image'];
                    $cr = self::Podman($d['user'], $args);
                    if ($cr[0] !== 0) {
                        return array(false, 'create: ' . self::Tail($cr[1]));
                    }
                }
                $s['containers'][$key] = array('user' => $d['user'], 'domain' => $d['domain'], 'hostport' => $d['hostport'], 'public' => (int) $d['public'],
                    'auth' => (string) $d['auth'], 'volumes' => count($d['volumes']));
                self::SaveState($s);
                $st = self::Podman($d['user'], array('start', $name));
                if ($st[0] !== 0) {
                    return array(false, 'start: ' . self::Tail($st[1]));
                }
                $px = self::Proxy($s);
                return $px === '' ? array(true, 'created and started') : array(false, $px);
            case 'start':
            case 'stop':
            case 'restart':
                if (!isset($s['containers'][$key])) {
                    return array(false, 'no such container here');
                }
                $r = self::Podman($d['user'], $action === 'start' ? array('start', $name) : array($action, '--time', '20', $name), 300);
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
                $t .= "\tbasic_auth {\n\t\tsetup " . $c['auth'] . "\n\t}\n";
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
        $v = self::Run(array('caddy', 'validate', '--config', self::$caddy . '/Caddyfile', '--adapter', 'caddyfile'), null, 60);
        $r = $v[0] === 0 ? self::Run(array('systemctl', 'reload', 'caddy'), null, 60) : $v;
        if ($r[0] !== 0) {
            if ($old === null) {
                @unlink($file);
            } else {
                self::WriteFile($file, $old, 0644);
            }
            self::Run(array('systemctl', 'reload', 'caddy'), null, 60);
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
        $c = self::Run(array('caddy', 'version'), null, 20);
        $se = self::Run(array('getenforce'), null, 10);
        return array('cpus' => max(1, (int) $cpus), 'mem_mb' => $mem, 'disk_total_mb' => (int) (disk_total_space($home) / 1048576), 'disk_free_mb' => (int) (disk_free_space($home) / 1048576),
            'os' => $os, 'kernel' => php_uname('r'), 'podman' => $p[0] === 0 ? trim(str_replace('podman version', '', $p[1])) : 'missing',
            'caddy' => $c[0] === 0 ? strtok(trim($c[1]), ' ') : 'missing', 'agent' => self::VERSION, 'selinux' => $se[0] === 0 ? trim($se[1]) : '');
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
                $out[$cid] = array('state' => $st, 'used_mb' => isset(self::$disk[$cid]) ? self::$disk[$cid] : 0, 'user' => $user);
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
     * One call to the panel: the report and results out, the jobs in, done; then again while there were jobs.
     * Returns the seconds to wait before the next call.
     */
    static function Once()
    {
        $cfg = self::$cfg;
        $sk = self::Bin($cfg['sign_sk'], SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $kp = self::Bin($cfg['box_kp'], SODIUM_CRYPTO_BOX_KEYPAIRBYTES);
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
                $opt[$m[1]] = in_array($m[1], array('no-tls-check'), true) ? true : (isset($argv[$i + 1]) ? $argv[++$i] : '');
            }
        }
        if ($cmd === 'pair') {
            return self::Pair($opt);
        }
        if (!self::LoadCfg()) {
            self::Out('This node is not linked to a panel yet (sentora-node-agent pair ...).');
            return 1;
        }
        if ($cmd === 'once') {
            self::Once();
            return 0;
        }
        if ($cmd === 'status') {
            $s = self::State();
            self::Out('Panel: ' . self::$cfg['panel'] . ', node ' . self::$cfg['node'] . ', ' . count($s['containers']) . ' containers');
            foreach (self::States($s) as $cid => $st) {
                self::Out(sprintf('  %s  %-8s %-10s %s (%d MB)', self::Name($cid), $st['user'], $st['state'], $s['containers'][$cid]['domain'], $st['used_mb']));
            }
            return 0;
        }
        if ($cmd === 'run') {
            self::Out('Sentora Node agent ' . self::VERSION . ': calling ' . self::$cfg['panel'] . ' as node ' . self::$cfg['node']);
            while (true) {
                sleep(self::Once());
            }
        }
        self::Out("sentora-node-agent pair|run|once|status");
        return 2;
    }
}

exit(snode::Main($argv));
