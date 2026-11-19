<?php

/**
 * Sentora Docker Node agent: runs on a Docker Hosting node (a separate server, never the panel's) as the
 * root service sentora-docker-node. It calls the panel every few seconds (nothing on the node is opened for
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
 *   sentora-docker-node-agent enroll --panel https://panel.example [--panel-code XXXX-XXXX] [--no-tls-check]
 *                                (the installer's usual way: the node makes its own key, the panel
 *                                 administrator pastes it in "Add a node"; the panel's code is checked
 *                                 by the person installing, so the node never trusts a fake panel)
 *   sentora-docker-node-agent pair --panel https://panel.example --key v1.<sign>.<box> --code <id>.<secret> [--no-tls-check]
 *                                (the older way: the command the panel shows)
 *   sentora-docker-node-agent run       (the service)
 *   sentora-docker-node-agent once      (one call to the panel)
 *   sentora-docker-node-agent status
 *
 * Paths for tests: SNODE_ETC (/etc/sentora-docker-node), SNODE_STATE (/var/lib/sentora-docker-node),
 * SNODE_CADDY (/etc/caddy), SNODE_RUN (/run/user), SNODE_HOME (/home), SNODE_SUBID (/etc: subuid,
 * subgid), SNODE_USERPATH (PATH of the customers' commands), SNODE_KEYFILE (/root/sentora-docker-node-key.txt).
 * What differs between systems (Caddy's folder, service and group, the no-login shell) comes from
 * /etc/sentora-docker-node/platform.conf, written by the installer for its system (P()).
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
    const VERSION = '119';

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
    /** Each app's runtime as Podman last said ('gvisor' or 'standard'), read again when an app is made anew. */
    static $iso = array();
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
        self::$etc = getenv('SNODE_ETC') ?: '/etc/sentora-docker-node';
        // (a service has no HOME; Caddy before 2.8 will not even check its settings without one)
        if ((string) getenv('HOME') === '') {
            putenv('HOME=/root');
        }
        foreach ((array) @file(self::$etc . '/platform.conf', FILE_IGNORE_NEW_LINES) as $l) {
            if (preg_match('/^([A-Z_]{1,32})=([A-Za-z0-9_.\/@:+-]{0,200})$/', (string) $l, $m)) {
                self::$platform[$m[1]] = $m[2];
            }
        }
        self::$state = getenv('SNODE_STATE') ?: '/var/lib/sentora-docker-node';
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
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_HTTPHEADER => array('User-Agent: sentora-docker-node/' . self::VERSION)));
            if (!$v) {
                curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $body = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            $errno = curl_errno($c);
            $err = curl_error($c);
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
                CURLOPT_HTTPHEADER => array('Content-Type: ' . ($bare ? 'text/plain' : 'application/x-www-form-urlencoded'), 'User-Agent: sentora-docker-node/' . self::VERSION),
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP));
            if (empty($cfg['tls_verify'])) {
                // (safe: every reply is signed with the panel's key, which the node already knows)
                curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $body = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            $err = curl_error($c);
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
    /**
     * Run a command (an argument list, no shell). $in: a file for its standard input; $out: a file its
     * standard output goes to (then only its errors are returned as the text). Both are opened here, by
     * root, in root's own folders: that is how data goes in and out of a customer's Podman without root
     * ever touching a path in the customer's home (2026-11-15: a customer's own symlink there could
     * otherwise turn a root chown or rename into root on the node).
     */
    static function Run(array $argv, $cwd = null, $timeout = 600, $in = null, $out = null)
    {
        // (a command this system does not have, such as getenforce without SELinux: said, not warned about)
        if (strpos((string) $argv[0], '/') === false && self::Which($argv[0]) === '') {
            return array(127, $argv[0] . ': not on this system');
        }
        $io = array(0 => array('file', $in !== null ? $in : '/dev/null', 'r'));
        if ($out !== null) {
            @unlink($out);
            $io[1] = array('file', $out, 'w');
            $io[2] = array('pipe', 'w');
        } else {
            $io[1] = array('pipe', 'w');
            $io[2] = array('redirect', 1);
        }
        $p = proc_open($argv, $io, $all, $cwd);
        if (!is_resource($p)) {
            return array(127, 'could not run ' . $argv[0]);
        }
        $pipes = array(1 => $all[$out !== null ? 2 : 1]);
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
    static function AsUser($user, array $argv, $timeout = 600, $in = null, $out = null)
    {
        $uid = self::Uid($user);
        $env = array('env', 'HOME=' . self::$home . '/' . $user, 'XDG_RUNTIME_DIR=' . self::$run . '/' . $uid, 'DBUS_SESSION_BUS_ADDRESS=unix:path=' . self::$run . '/' . $uid . '/bus',
            'PATH=' . (getenv('SNODE_USERPATH') ?: '/usr/local/bin:/usr/bin:/bin'));
        // A file given as input or output (root's, opened here) goes through a pipe the user's own shell makes:
        // a program that opens /dev/stdout or /dev/stdin again (podman volume export) may not open root's file
        if ($in !== null || $out !== null) {
            $argv = array_merge(array('bash', '-c', 'set -o pipefail; ' . ($in !== null ? 'cat | ' : '') . '"$@"' . ($out !== null ? ' | cat' : ''), 'bash'), $argv);
        }
        return self::Run(array_merge(array('runuser', '-u', $user, '--'), $env, $argv), self::$home . '/' . $user, $timeout, $in, $out);
    }

    /**
     * Download an image as the customer's user. When the panel sent a login for the image's registry
     * (a private image, or Docker Hub's higher download limit), it goes into a file only that user can
     * read, for this one download, and is deleted right after: no login stays on the node.
     */
    static function Pull($user, $image, array $d)
    {
        $auth = '';
        $r = isset($d['registry']) && is_array($d['registry']) ? $d['registry'] : null;
        if ($r && isset($r['host'], $r['user'], $r['secret']) && is_string($r['host']) && is_string($r['user']) && is_string($r['secret'])
            && preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $r['host']) && preg_match('/^[\x21-\x7e]{1,255}$/', $r['user']) && preg_match('/^[\x21-\x7e]{1,4096}$/', $r['secret'])
            && explode('/', $image)[0] === $r['host']) {
            $e = self::Session($user);
            if ($e !== '') {
                return array(125, $e);
            }
            // (written by the user itself into its own runtime folder, from a file of root's on its input:
            // root writes nothing in a folder the user owns)
            $auth = self::$run . '/' . self::Uid($user) . '/sentora-pull-' . bin2hex(random_bytes(8)) . '.json';
            $src = self::RootTemp('auth');
            $old = umask(077);
            $ok = file_put_contents($src, json_encode(array('auths' => array($r['host'] => array('auth' => base64_encode($r['user'] . ':' . $r['secret']))))));
            umask($old);
            $w = $ok === false ? array(1, '') : self::AsUser($user, array('sh', '-c', 'umask 077; cat > "$1"', 'sh', $auth), 60, $src);
            @unlink($src);
            if ($w[0] !== 0) {
                return array(125, 'the registry login could not be written for the download');
            }
        }
        $p = self::Podman($user, array_merge(array('pull', '--quiet'), $auth !== '' ? array('--authfile', $auth) : array(), array($image)), 1800);
        if ($auth !== '') {
            self::AsUser($user, array('rm', '-f', '--', $auth), 60);
        }
        return $p;
    }

    static function Podman($user, array $args, $timeout = 600, $in = null, $out = null)
    {
        $e = self::Session($user);
        if ($e !== '') {
            return array(125, $e);
        }
        // An app in gVisor runs only inside its own slice, where its limits are: it is started from inside the slice
        // (rootless Podman leaves its monitor, and so gVisor's sandbox, where it was started from), checked after,
        // started once more if it is still outside, and stopped rather than run without its limits
        $last = (string) end($args);
        $m = array();
        $gv = in_array($args[0], array('start', 'restart'), true) && preg_match('/^sdh-(\d+)$/', $last, $m) && self::IsoOf($user, $last) === 'gvisor';
        if (!$gv) {
            return self::AsUser($user, array_merge(array('podman'), $args), $timeout, $in, $out);
        }
        $in_slice = function (array $cmd) use ($user, $m, $timeout) {
            // (gVisor's network helper can hold the app's port for a moment after a stop: a few tries)
            for ($try = 0; ; $try++) {
                $r = self::AsUser($user, array_merge(array('systemd-run', '--user', '--scope', '--quiet', '--collect', '--slice=' . self::Slice((int) $m[1]), 'podman'), $cmd), $timeout);
                if ($r[0] === 0 || $try >= 5 || stripos($r[1], 'Address already in use') === false) {
                    return $r;
                }
                sleep(2);
            }
        };
        if ($args[0] === 'restart') {
            // (a restart is a stop, then a start from inside the slice)
            $stop = $args;
            $stop[0] = 'stop';
            self::AsUser($user, array_merge(array('podman'), $stop), $timeout);
            $args = array('start', $last);
        }
        $r = $in_slice($args);
        if ($r[0] === 0 && !self::InSlice($user, $last, (int) $m[1])) {
            self::AsUser($user, array('podman', 'stop', '--time', '10', $last), 120);
            $r = $in_slice(array('--log-level=info', 'start', $last));
            if ($r[0] === 0 && !self::InSlice($user, $last, (int) $m[1])) {
                self::AsUser($user, array('podman', 'stop', '--time', '10', $last), 120);
                // (with what Podman said about where it put the app)
                preg_match_all('/^.*(conmon|cgroup|scope|slice|level=(warn|error)).*$/mi', (string) $r[1], $why);
                $r = array(1, 'the app did not start inside its limits (gVisor), so it was stopped. Podman: ' . substr(implode(' | ', $why[0]), 0, 1500));
            }
        }
        return $r;
    }

    /** Whether an app's processes (Podman's monitor and gVisor's sandbox) are inside its own slice. */
    static function InSlice($user, $name, $cid)
    {
        $r = self::AsUser($user, array('podman', 'container', 'inspect', '--format', '{{.State.ConmonPid}} {{.State.Pid}}', $name), 60);
        $pids = preg_split('/\s+/', trim($r[1]));
        if ($r[0] !== 0 || count($pids) !== 2) {
            return false;
        }
        foreach ($pids as $pid) {
            $cg = ctype_digit($pid) && (int) $pid > 0 ? (string) @file_get_contents((getenv('SNODE_PROC') ?: '/proc') . '/' . $pid . '/cgroup') : '';
            if (strpos($cg, '/' . self::Slice($cid) . '/') === false) {
                return false;
            }
        }
        return true;
    }

    /** A file of root's own for one piece of data on its way (root's transfer folder: customers can not reach it). */
    static function RootTemp($what)
    {
        @mkdir(self::$state . '/transfer', 0700, true);
        @chmod(self::$state . '/transfer', 0700);
        return self::$state . '/transfer/' . $what . '-' . bin2hex(random_bytes(8));
    }

    // ----- checking a job -----

    static function Check(array $d, $action)
    {
        if (isset($d['guards']) && (!is_array($d['guards']) || (isset($d['guards']['iso']) && !in_array($d['guards']['iso'], array('gvisor', 'standard'), true))
            || (isset($d['guards']['scan']) && !in_array($d['guards']['scan'], array('warn', 'block', 'off'), true))
            || (isset($d['guards']['waf']) && !in_array($d['guards']['waf'], array('on', 'watch', 'off'), true)))) {
            return 'bad guards';
        }
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
        if ($action === 'create' || $action === 'update' || $action === 'recreate' || $action === 'move' || $action === 'restore') {
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
    /**
     * Extra isolation (gVisor) asked for this app? The panel sends it in the job's guards; a panel from before
     * them sends none: the standard runtime, as before.
     */
    static function WantGvisor(array $d)
    {
        return isset($d['guards']['iso']) && $d['guards']['iso'] === 'gvisor';
    }

    /** gVisor's runtime for the apps (the installer's runsc-sentora), or '' when this node has none. */
    static function Runsc()
    {
        $r = self::P('RUNSC', '');
        if ($r === '' || $r[0] !== '/' || !is_executable($r)) {
            return '';
        }
        // (not with SELinux enforcing, for now: runsc takes no SELinux label, and started under the container's
        // label it can not set itself up (found on a real AlmaLinux 10, 2026-11-16). The app runs on the
        // standard runtime there, confined by SELinux as before; the panel shows "asked for, not in force".)
        static $enforcing = null;
        if ($enforcing === null) {
            $e = self::Run(array('getenforce'), null, 10);
            $enforcing = $e[0] === 0 && trim($e[1]) === 'Enforcing';
        }
        return $enforcing ? '' : $r;
    }

    /**
     * The image check (Trivy) asked for this app: 'warn' (checked, shown, never refused: the default, owner
     * 2026-11-15), 'block' (a new app with critical holes that have a fix is refused; an update only when it has
     * more of them than the version running), 'off'. A panel from before it sends none: 'warn'.
     */
    static function WantScan(array $d)
    {
        $g = isset($d['guards']['scan']) ? $d['guards']['scan'] : 'warn';
        return in_array($g, array('warn', 'block', 'off'), true) ? $g : 'warn';
    }

    /**
     * The web firewall asked for this app (Coraza with the OWASP Core Rule Set, in front of it in Caddy): 'on'
     * (attacks are refused: the default, owner 2026-11-16), 'watch' (only written to Caddy's log) or 'off'.
     * A panel from before it sends none: 'off', as before.
     */
    static function WantWaf(array $d)
    {
        $g = isset($d['guards']['waf']) ? $d['guards']['waf'] : 'off';
        return in_array($g, array('on', 'watch', 'off'), true) ? $g : 'off';
    }

    /** Whether this node's Caddy has the web firewall (the installer's Caddy with Coraza). */
    static function Waf()
    {
        return self::P('CADDY_WAF', '') === '1';
    }

    // ----- blocking repeat attackers (CrowdSec, Docker Hosting 139, owner 2026-11-19: local by default) -----

    /** Whether this node has CrowdSec (the installer's, with its nftables blocker). */
    static function Crowdsec()
    {
        return self::P('CROWDSEC', '') === '1';
    }

    static function CsDir()
    {
        return getenv('SNODE_CSDIR') ?: '/etc/crowdsec';
    }

    /** Whether this node shares with CrowdSec's community (its central API on, with this node registered). */
    static function CsSharing()
    {
        $local = (string) @file_get_contents(self::CsDir() . '/config.yaml.local');
        return is_file(self::CsDir() . '/online_api_credentials.yaml') && strpos($local, 'online_client: null') === false;
    }

    /**
     * An address or a range for the safe list ('' when it is neither).
     */
    static function CsAddr($a)
    {
        $a = trim((string) $a);
        if (filter_var($a, FILTER_VALIDATE_IP) !== false) {
            return $a;
        }
        if (preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#', $a, $m) && filter_var($m[1], FILTER_VALIDATE_IP) !== false
            && (int) $m[2] <= (strpos($m[1], ':') !== false ? 128 : 32) && (int) $m[2] >= (strpos($m[1], ':') !== false ? 16 : 8)) {
            return $m[1] . '/' . (int) $m[2];
        }
        return '';
    }

    /**
     * The panel's settings for CrowdSec: sharing on or off, and the safe list (never blocked; the panel's own
     * addresses always on it). Sharing off: its central API switched off and this node's registration there
     * removed; on: registered and switched on. CrowdSec is restarted with them.
     */
    static function CsSettings(array $d)
    {
        if (!self::Crowdsec()) {
            return array(false, 'this node has no CrowdSec');
        }
        $safe = array();
        foreach (isset($d['safe']) && is_array($d['safe']) ? array_slice($d['safe'], 0, 200) : array() as $a) {
            $a = self::CsAddr($a);
            if ($a === '') {
                return array(false, 'refused: not an address');
            }
            $safe[$a] = true;
        }
        // (the panel's own addresses: never blocked)
        $host = (string) parse_url((string) (self::$cfg['panel'] ?? ''), PHP_URL_HOST);
        if ($host !== '') {
            foreach (filter_var($host, FILTER_VALIDATE_IP) !== false ? array($host) : (array) @gethostbynamel($host) as $ip) {
                $safe[$ip] = true;
            }
            foreach (filter_var($host, FILTER_VALIDATE_IP) !== false ? array() : (array) @dns_get_record($host, DNS_AAAA) as $r) {
                if (isset($r['ipv6']) && filter_var($r['ipv6'], FILTER_VALIDATE_IP) !== false) {
                    $safe[$r['ipv6']] = true;
                }
            }
        }
        $ips = $cidrs = '';
        foreach (array_keys($safe) as $a) {
            if (strpos($a, '/') !== false) {
                $cidrs .= "    - \"" . $a . "\"\n";
            } else {
                $ips .= "    - \"" . $a . "\"\n";
            }
        }
        $f = self::CsDir() . '/parsers/s02-enrich/sentora-safe-list.yaml';
        @mkdir(dirname($f), 0755, true);
        self::WriteFile($f, "# Written by the Sentora Docker Node agent from the panel (Nodes > Manage): addresses never blocked.\n"
            . "name: sentora/safe-list\ndescription: \"Sentora: the panel's and the administrators' addresses, never blocked\"\nwhitelist:\n  reason: \"Sentora safe list\"\n"
            . ($ips !== '' ? "  ip:\n" . $ips : '') . ($cidrs !== '' ? "  cidr:\n" . $cidrs : '') . ($ips === '' && $cidrs === '' ? "  ip: []\n" : ''), 0644);
        $share = !empty($d['share']);
        $cred = self::CsDir() . '/online_api_credentials.yaml';
        if ($share) {
            self::WriteFile(self::CsDir() . '/config.yaml.local', "# Written by the Sentora Docker Node agent: sharing with CrowdSec's community is on (the panel's switch).\n", 0644);
            if (!is_file($cred) || filesize($cred) < 20) {
                $r = self::Run(array('cscli', 'capi', 'register', '-f', $cred), null, 60);
                if ($r[0] !== 0) {
                    return array(false, 'CrowdSec: could not register with its community: ' . self::Tail($r[1]));
                }
            }
        } else {
            // (local: nothing goes to CrowdSec's servers; the registration the package made at install is removed)
            self::WriteFile(self::CsDir() . '/config.yaml.local', "# Written by the Sentora Docker Node agent: local only, nothing is shared with CrowdSec's servers (the panel's switch).\napi:\n  server:\n    online_client: null\n", 0644);
            @unlink($cred);
        }
        $r = self::Run(array('systemctl', 'restart', self::P('CROWDSEC_SERVICE', 'crowdsec')), null, 120);
        self::$csAt = 0;
        return $r[0] === 0 ? array(true, 'CrowdSec: ' . ($share ? 'shares with its community' : 'local only') . ', ' . count($safe) . ' address(es) on the safe list')
            : array(false, 'CrowdSec did not start again: ' . self::Tail($r[1]));
    }

    /** @var int when CsInfo last asked CrowdSec */
    static $csAt = 0;
    /** @var array */
    static $cs = array();

    /**
     * CrowdSec's state for the panel (asked at most once a minute): on, sharing, the safe list's size, and the
     * addresses it blocks now (up to 100: the address, why, and for how much longer).
     */
    static function CsInfo()
    {
        if (!self::Crowdsec()) {
            return array('on' => 0);
        }
        if (time() - self::$csAt < 60 && self::$cs) {
            return self::$cs;
        }
        self::$csAt = time();
        $act = self::Run(array('systemctl', 'is-active', self::P('CROWDSEC_SERVICE', 'crowdsec')), null, 10);
        $r = self::Run(array('cscli', 'decisions', 'list', '-o', 'json', '--limit', '200'), null, 30);
        $j = json_decode(trim($r[1]), true);
        $blocked = array();
        $count = 0;
        foreach (is_array($j) ? $j : array() as $alert) {
            foreach (isset($alert['decisions']) && is_array($alert['decisions']) ? $alert['decisions'] : array() as $dc) {
                if (($dc['type'] ?? '') !== 'ban' || !isset($dc['value'])) {
                    continue;
                }
                $count++;
                if (count($blocked) < 100) {
                    $left = 0;
                    if (preg_match_all('/(\d+(?:\.\d+)?)(h|m|s)/', (string) ($dc['duration'] ?? ''), $mm, PREG_SET_ORDER)) {
                        foreach ($mm as $x) {
                            $left += (int) round((float) $x[1] * array('h' => 3600, 'm' => 60, 's' => 1)[$x[2]]);
                        }
                    }
                    $blocked[] = array('ip' => (string) $dc['value'], 'why' => (string) ($dc['scenario'] ?? ($alert['scenario'] ?? '')), 'left' => $left);
                }
            }
        }
        $safe = (string) @file_get_contents(self::CsDir() . '/parsers/s02-enrich/sentora-safe-list.yaml');
        self::$cs = array('on' => trim($act[1]) === 'active' ? 1 : 2, 'share' => self::CsSharing() ? 1 : 0, 'safe' => substr(md5($safe), 0, 12),
            'count' => $count, 'blocked' => $blocked);
        return self::$cs;
    }

    /** Trivy on this node (the installer's, a pinned release), or '' when this node has none. */
    static function Trivy()
    {
        $t = self::P('TRIVY', '');
        return $t !== '' && $t[0] === '/' && is_executable($t) ? $t : '';
    }

    /** The image check's folder (its database; the images being checked) and its own user. */
    static function ScanDir()
    {
        return getenv('SNODE_SCANDIR') ?: '/var/lib/sentora-scan';
    }

    static function ScanUser()
    {
        return self::P('SCAN_USER', 'sentora-scan');
    }

    /**
     * Trivy's database brought up to date (once a day), by its own user (started in its own folder: Trivy reads a
     * trivy.yaml from where it is started).
     */
    static function ScanDb()
    {
        return self::Run(array('runuser', '-u', self::ScanUser(), '--', self::Trivy(), 'image', '--download-db-only', '--cache-dir', self::ScanDir() . '/cache', '--quiet'), self::ScanDir(), 900);
    }

    /**
     * Check an image for known security holes. The customer's user saves the image (podman image save) into a
     * folder of root's that only the check's own user may also read; Trivy reads it there as that user (never
     * as root: it reads what the customer's image contains), with a memory cap of its own, and without network
     * use (its database is brought up to date once a day). Its answer is counted here.
     *
     * @return array|string a summary (at, id, c, cf, h, hf, m, l, top) or why it could not be checked
     */
    static function ImageCheck($user, $image)
    {
        $t = self::Trivy();
        if ($t === '') {
            return 'this node has no image check';
        }
        $grp = self::ScanUser();
        if (self::Uid($grp) < 0) {
            return 'the image check\'s user is missing';
        }
        $in = self::ScanDir() . '/in';
        @mkdir($in, 0750, true);
        @chown($in, 0);
        @chgrp($in, $grp);
        @chmod($in, 0750);
        $id = self::Podman($user, array('image', 'inspect', '--format', '{{.Id}}', $image), 60);
        $f = $in . '/image-' . bin2hex(random_bytes(8)) . '.tar';
        $sv = self::AsUser($user, array('podman', 'image', 'save', $image), 1800, null, $f);
        if ($sv[0] !== 0 || !is_file($f)) {
            @unlink($f);
            return 'the image could not be read (' . self::Tail($sv[1]) . ')';
        }
        @chgrp($f, $grp);
        @chmod($f, 0640);
        $res = self::RootTemp('scan');
        $r = self::Run(array('systemd-run', '--scope', '--quiet', '--collect', '-p', 'MemoryMax=1536M', '-p', 'MemorySwapMax=0', '-p', 'CPUQuota=100%',
            'runuser', '-u', self::ScanUser(), '--', $t, 'image', '--input', $f, '--cache-dir', self::ScanDir() . '/cache', '--skip-db-update', '--skip-java-db-update',
            '--scanners', 'vuln', '--format', 'json', '--quiet', '--timeout', '10m'), self::ScanDir(), 900, null, $res);
        @unlink($f);
        $json = is_file($res) && filesize($res) < 64 * 1048576 ? (string) file_get_contents($res) : '';
        @unlink($res);
        $j = json_decode($json, true);
        if ($r[0] !== 0 || !is_array($j)) {
            return 'the check did not finish (' . self::Tail($r[1]) . ')';
        }
        $sum = array('at' => time(), 'id' => substr(preg_replace('/^sha256:/', '', trim($id[1])), 0, 12), 'c' => 0, 'cf' => 0, 'h' => 0, 'hf' => 0, 'm' => 0, 'l' => 0, 'top' => array());
        foreach (isset($j['Results']) && is_array($j['Results']) ? $j['Results'] : array() as $res1) {
            foreach (isset($res1['Vulnerabilities']) && is_array($res1['Vulnerabilities']) ? $res1['Vulnerabilities'] : array() as $v) {
                $sev = isset($v['Severity']) ? (string) $v['Severity'] : '';
                $fix = isset($v['FixedVersion']) && (string) $v['FixedVersion'] !== '';
                if ($sev === 'CRITICAL') {
                    $sum['c']++;
                    if ($fix) {
                        $sum['cf']++;
                        if (count($sum['top']) < 8) {
                            // (only plain words go to the panel: package, versions, the hole's name)
                            $sum['top'][] = substr(preg_replace('/[^A-Za-z0-9 ._:+~,>\/-]/', '', (string) ($v['PkgName'] ?? '') . ' ' . (string) ($v['InstalledVersion'] ?? '')
                                . ' > ' . (string) $v['FixedVersion'] . ' ' . (string) ($v['VulnerabilityID'] ?? '')), 0, 160);
                        }
                    }
                } elseif ($sev === 'HIGH') {
                    $sum['h']++;
                    $sum['hf'] += $fix ? 1 : 0;
                } elseif ($sev === 'MEDIUM') {
                    $sum['m']++;
                } elseif ($sev === 'LOW') {
                    $sum['l']++;
                }
            }
        }
        return $sum;
    }

    /**
     * The image check before an app is made or updated: [ok, why, summary]. Not ok only when the app's
     * check is 'block' (a new app: critical holes with a fix; an update: more of them than the version
     * running, $now). 'warn': never refused, also when the check could not be done.
     */
    static function Gate(array $d, $image, $now = null)
    {
        $mode = self::WantScan($d);
        if ($mode === 'off' || self::Trivy() === '') {
            return array(true, '', null);
        }
        $sum = self::ImageCheck($d['user'], $image);
        if (!is_array($sum)) {
            return $mode === 'block' ? array(false, 'refused by the image check: ' . $sum, null) : array(true, '', array('at' => time(), 'err' => $sum));
        }
        if ($mode === 'block' && ($now === null ? $sum['cf'] > 0 : $sum['cf'] > (int) $now)) {
            return array(false, 'refused by the image check: ' . $sum['cf'] . ' critical security hole' . ($sum['cf'] === 1 ? '' : 's') . ' with a fix'
                . ($now === null ? '' : ' (the version running has ' . (int) $now . ')') . ($sum['top'] ? ': ' . implode('; ', array_slice($sum['top'], 0, 3)) : '')
                . '. An administrator can set the app\'s image check to Warn to allow it.', $sum);
        }
        return array(true, '', $sum);
    }

    /** The slice of the user's own that holds an app's limits when it runs on gVisor. */
    static function Slice($cid)
    {
        return 'sentora-app-' . (int) $cid . '.slice';
    }

    /**
     * An app on gVisor: its limits on its slice (memory with 64 MB for gVisor's own kernel, so the app keeps
     * what its customer has; no swap; its CPU share; tasks: gVisor's own threads, the app's processes live
     * inside it). Kept by the user's systemd, so they hold again after a restart.
     */
    static function SetSlice($user, $cid, $cpu, $mem)
    {
        return self::AsUser($user, array('systemctl', '--user', 'set-property', self::Slice($cid),
            'MemoryMax=' . ((int) $mem + 64) . 'M', 'MemorySwapMax=0', 'CPUQuota=' . max(1, (int) $cpu) . '%', 'TasksMax=4096'), 60);
    }

    /** Which runtime an app runs on, from Podman: 'gvisor' or 'standard'. */
    static function IsoOf($user, $name)
    {
        $r = self::Podman($user, array('container', 'inspect', '--format', '{{.OCIRuntime}}', $name), 60);
        return $r[0] === 0 && strpos($r[1], 'runsc') !== false ? 'gvisor' : 'standard';
    }

    static function CreateArgs(array $d, $name)
    {
        // Extra isolation: gVisor's runtime and the app's own slice (its limits set first)
        $pre = array();
        unset(self::$iso[(int) $d['cid']]);
        if (self::WantGvisor($d) && self::Runsc() !== '') {
            self::SetSlice($d['user'], $d['cid'], $d['cpu'], $d['mem']);
            $pre = array('--runtime', self::Runsc());
        }
        $args = array_merge($pre, array('create', '--name', $name, '--label', 'sentora.cid=' . $d['cid'], '--restart', 'unless-stopped',
            '--cpus', sprintf('%d.%02d', intdiv($d['cpu'], 100), $d['cpu'] % 100), '--memory', $d['mem'] . 'm', '--memory-swap', $d['mem'] . 'm',
            '--pids-limit', '1024', '--security-opt', 'no-new-privileges', '--log-driver', 'k8s-file', '--log-opt', 'max-size=10mb',
            '--publish', '127.0.0.1:' . $d['hostport'] . ':' . $d['port']));
        if ($pre) {
            $args[] = '--cgroup-parent';
            $args[] = self::Slice($d['cid']);
        }
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
            $p = self::Pull($u, $d['image'], $d);
            if ($p[0] !== 0) {
                return array(false, 'pull: ' . self::Tail($p[1]));
            }
            $new = self::Podman($u, array('image', 'inspect', '--format', '{{.Id}}', $d['image']));
            if ($new[0] === 0 && trim($new[1]) === trim($cur[1])) {
                $s['updates'][(string) $d['cid']] = false;
                return array(true, 'already the newest version');
            }
            // the new version's image check (block: refused when it has more critical holes with a fix than
            // the one running, which is checked too when its last check is not of this version)
            $now = null;
            if (self::WantScan($d) === 'block' && self::Trivy() !== '') {
                $k = (string) $d['cid'];
                $was = isset($s['scan'][$k]['cf'], $s['scan'][$k]['id']) && $s['scan'][$k]['id'] === substr(preg_replace('/^sha256:/', '', trim($cur[1])), 0, 12) ? $s['scan'][$k] : self::ImageCheck($u, trim($cur[1]));
                $now = is_array($was) && isset($was['cf']) ? (int) $was['cf'] : 0;
            }
            $g = self::Gate($d, $d['image'], $now === null ? 0 : $now);
            if (!$g[0]) {
                self::Podman($u, array('image', 'prune', '--force'), 300);
                return array(false, $g[1] . ' The version running stays.');
            }
            $checked = $g[2];
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
        if (isset($checked) && $checked !== null) {
            $s['scan'][(string) $d['cid']] = $checked;
        }
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
            // (asked as the app's own user, inside its fence: the registry is the customer's choice)
            $remote = self::AsUser($c['user'], array('skopeo', 'inspect', '--no-tags', '--format', '{{.Digest}}', 'docker://' . $c['image']), 120);
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
        // the image check: its database brought up to date, then every app's image checked again (new holes
        // are found in old images every day; this only tells, it never stops an app)
        if (self::Trivy() !== '') {
            $db = self::ScanDb();
            if ($db[0] !== 0) {
                self::Out('image check: the database could not be brought up to date: ' . self::Tail($db[1]));
            }
            foreach ($s['containers'] as $cid => $c) {
                if (self::Uid($c['user']) < 0 || (isset($c['scan']) && $c['scan'] === 'off')) {
                    continue;
                }
                $img = self::Podman($c['user'], array('container', 'inspect', '--format', '{{.Image}}', self::Name($cid)), 60);
                if ($img[0] === 0 && preg_match('/^(sha256:)?[A-Za-z0-9._-]{3,128}$/', trim($img[1]))) {
                    $sum = self::ImageCheck($c['user'], trim($img[1]));
                    $s['scan'][(string) $cid] = is_array($sum) ? $sum : array('at' => time(), 'err' => $sum);
                }
            }
            self::SaveState($s);
        }
    }

    /**
     * Jobs about the node itself (no container). [ok, output] or null when it is not one.
     */
    static function NodeJob($action, array $d, array &$s)
    {
        $bg = function (array $argv, $delay = 0) {
            $unit = 'sentora-docker-node-' . preg_replace('/[^a-z]/', '', preg_replace('/^sentora-docker-node-/', '', basename($argv[0]))) . '-' . time();
            $cmd = array_merge(array('systemd-run', '--unit', $unit, '--collect', '--quiet'), $delay > 0 ? array('--on-active=' . $delay) : array(), $argv);
            return self::Run($cmd, null, 60);
        };
        switch ($action) {
            case 'node-update':
                $r = $bg(array('/usr/local/sbin/sentora-docker-node-update', '--yes'), 5);
                return array($r[0] === 0, $r[0] === 0 ? 'updating the node (agent ' . self::VERSION . ' now); it comes back in a few minutes' : 'node-update: ' . self::Tail($r[1]));
            case 'sys-update':
                $r = $bg(array('/usr/local/sbin/sentora-docker-node-sysupdate', !empty($d['security']) ? '--security' : '--all'));
                return array($r[0] === 0, $r[0] === 0 ? 'installing ' . (!empty($d['security']) ? 'security' : 'all') . ' updates' : 'sys-update: ' . self::Tail($r[1]));
            case 'reboot':
                $r = self::Run(array('shutdown', '-r', '+1', 'The Sentora panel asked for a restart of this node'), null, 30);
                return array($r[0] === 0, $r[0] === 0 ? 'restarting in a minute' : 'reboot: ' . self::Tail($r[1]));
            case 'check-updates':
                self::CheckUpdates($s, true);
                $bg(array('/usr/local/sbin/sentora-docker-node-sysupdate', '--check'));
                return array(true, 'checked');
            case 'settings':
                $email = isset($d['email']) && is_string($d['email']) ? $d['email'] : '';
                if ($email !== '' && !preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $email)) {
                    return array(false, 'refused: bad email');
                }
                return self::Settings($email, !empty($d['local_certs']));
            case 'crowdsec':
                return self::CsSettings($d);
            case 'unblock':
                // (an address CrowdSec blocks, let through again)
                $ip = isset($d['ip']) && is_string($d['ip']) && filter_var($d['ip'], FILTER_VALIDATE_IP) !== false ? $d['ip'] : '';
                if ($ip === '' || !self::Crowdsec()) {
                    return array(false, $ip === '' ? 'refused: not an address' : 'this node has no CrowdSec');
                }
                $r = self::Run(array('cscli', 'decisions', 'delete', '--ip', $ip), null, 60);
                self::$csAt = 0;
                return array($r[0] === 0, 'unblocked ' . $ip . ': ' . self::Tail($r[1]));
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
                $r = $bg(array('/usr/local/sbin/sentora-docker-node-uninstall', '--purge'), 30);
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
        $t = "# Written by the Sentora Docker Node installer. The containers' sites are in sentora/ (the agent writes them).\n{\n"
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
        self::WriteFile(self::$etc . '/install.conf', "# Settings this node was installed with (read by sentora-docker-node-update; set from the panel)\n"
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
                    $pull = self::Pull($d['user'], $d['image'], $d);
                    if ($pull[0] !== 0) {
                        return array(false, 'pull: ' . self::Tail($pull[1]));
                    }
                    $g = self::Gate($d, $d['image']);
                    if (!$g[0]) {
                        self::Podman($d['user'], array('image', 'prune', '--all', '--force'), 300);
                        return array(false, $g[1]);
                    }
                    if ($g[2] !== null) {
                        $s['scan'][$key] = $g[2];
                    }
                    $cr = self::Podman($d['user'], self::CreateArgs($d, $name));
                    if ($cr[0] !== 0) {
                        return array(false, 'create: ' . self::Tail($cr[1]));
                    }
                }
                $s['containers'][$key] = array('user' => $d['user'], 'domain' => $d['domain'], 'hostport' => $d['hostport'], 'public' => (int) $d['public'],
                    'auth' => (string) $d['auth'], 'volumes' => count($d['volumes']), 'image' => $d['image'], 'scan' => self::WantScan($d), 'waf' => self::WantWaf($d));
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
                $s['containers'][$key]['scan'] = self::WantScan($d);
                $s['containers'][$key]['waf'] = self::WantWaf($d);
                $res = self::Update($d, $s);
                $s['containers'][$key]['image'] = $d['image'];
                // (its site again: the web firewall's mode may have changed)
                $px = self::Proxy($s);
                return $res[0] && $px !== '' ? array(false, $px) : $res;
            case 'recreate':
                // the app's settings changed (its owner's): made again from the same version, with the
                // same data; when it does not start with them, the old one runs again
                if (!isset($s['containers'][$key])) {
                    return array(false, 'no such container here');
                }
                if ((int) $s['containers'][$key]['hostport'] !== $d['hostport'] || $s['containers'][$key]['domain'] !== $d['domain']) {
                    return array(false, 'refused: the address or port of an app does not change with its settings');
                }
                $s['containers'][$key]['scan'] = self::WantScan($d);
                $s['containers'][$key]['waf'] = self::WantWaf($d);
                $res = self::Update($d, $s, false);
                $px = self::Proxy($s);
                return $res[0] && $px !== '' ? array(false, $px) : $res;
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
                // (an app on gVisor: its slice holds the limits; Podman's own update does not reach it there)
                $r = self::IsoOf($d['user'], $name) === 'gvisor' ? self::SetSlice($d['user'], $d['cid'], $d['cpu'], $d['mem'])
                    : self::Podman($d['user'], array('update', '--cpus', sprintf('%d.%02d', intdiv($d['cpu'], 100), $d['cpu'] % 100), '--memory', $d['mem'] . 'm', '--memory-swap', $d['mem'] . 'm', $name));
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
                if (isset($d['guards'])) {
                    // (the web firewall's mode changes with the site only: the app keeps running)
                    $s['containers'][$key]['waf'] = self::WantWaf($d);
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
            case 'backup':
                return self::Backup($d, $s);
            case 'restore':
                return self::Restore($d, $s);
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
                    // (113) the images no container of this user uses any more
                    self::Podman($d['user'], array('image', 'prune', '--all', '--force'), 300);
                }
                unset($s['containers'][$key], $s['scan'][$key]);
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
        $t = "# Written by Sentora Docker Node (sentora-docker-node-agent) from the panel's containers: do not change by hand.\n";
        $waf = self::Waf();
        if ($waf) {
            // the web firewall (Coraza, OWASP Core Rule Set at its first paranoia level): refusing (on) or only
            // writing to Caddy's log (watch). Uploads larger than its limit are checked in their first part
            // and let through (not refused); the apps' answers are not read (speed, streaming).
            foreach (array('on' => 'On', 'watch' => 'DetectionOnly') as $k => $mode) {
                $t .= "\n(sentora_waf_" . $k . ") {\n\tcoraza_waf {\n\t\tload_owasp_crs\n\t\tdirectives `\n\t\t\tInclude @coraza.conf-recommended\n\t\t\tInclude @crs-setup.conf.example\n"
                    . "\t\t\tInclude @owasp_crs/*.conf\n\t\t\tSecRuleEngine " . $mode . "\n\t\t\tSecRequestBodyLimitAction ProcessPartial\n\t\t\tSecResponseBodyAccess Off\n\t\t\tSecAuditEngine Off\n\t\t`\n\t}\n}\n";
            }
        }
        $log = self::Crowdsec();
        if ($log) {
            // the apps' access log, which CrowdSec reads (rolled at 20 MB, 3 kept: it can not fill the disk)
            $t .= "\n(sentora_log) {\n\tlog {\n\t\toutput file " . self::P('CADDY_LOG', '/var/log/caddy/sentora-access.log') . " {\n\t\t\troll_size 20MiB\n\t\t\troll_keep 3\n\t\t}\n\t\tformat json\n\t}\n}\n";
        }
        ksort($s['containers']);
        foreach ($s['containers'] as $cid => $c) {
            $t .= "\n# container " . (int) $cid . " (" . $c['user'] . ")\n" . $c['domain'] . " {\n\tencode zstd gzip\n" . ($log ? "\timport sentora_log\n" : '');
            if (!(int) $c['public'] && $c['auth'] !== '') {
                // (Caddy before 2.8 wants the hash base64-encoded, under its older name)
                $t .= self::CaddyOld() ? "\tbasicauth {\n\t\tsetup " . base64_encode($c['auth']) . "\n\t}\n" : "\tbasic_auth {\n\t\tsetup " . $c['auth'] . "\n\t}\n";
            }
            $w = isset($c['waf']) ? (string) $c['waf'] : 'off';
            if ($waf && ($w === 'on' || $w === 'watch')) {
                // (after the setup password, before the app)
                $t .= "\troute {\n\t\timport sentora_waf_" . $w . "\n\t\treverse_proxy 127.0.0.1:" . (int) $c['hostport'] . "\n\t}\n}\n";
            } else {
                $t .= "\treverse_proxy 127.0.0.1:" . (int) $c['hostport'] . "\n}\n";
            }
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
            'apparmor' => is_file('/sys/module/apparmor/parameters/enabled') ? (trim((string) @file_get_contents('/sys/module/apparmor/parameters/enabled')) === 'Y' ? 'on' : 'off') : '',
            'crowdsec' => self::CsInfo())
            + self::SysInfo();
    }

    /**
     * The system's waiting updates and the last update run (sentora-docker-node-sysupdate writes them; it
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
            self::Run(array('systemd-run', '--unit', 'sentora-docker-node-syscheck-' . time(), '--collect', '--quiet', '/usr/local/sbin/sentora-docker-node-sysupdate', '--check'), null, 30);
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
                if ($st !== 'missing' && !isset(self::$iso[$cid])) {
                    self::$iso[$cid] = self::IsoOf($user, self::Name((int) $cid));
                }
                $out[$cid] = array('state' => $st, 'iso' => isset(self::$iso[$cid]) ? self::$iso[$cid] : '', 'used_mb' => isset(self::$disk[$cid]) ? self::$disk[$cid] : 0, 'user' => $user,
                    'update' => !empty($s['updates'][$cid]) ? 1 : 0, 'scan' => isset($s['scan'][$cid]) && is_array($s['scan'][$cid]) ? $s['scan'][$cid] : null,
                    'waf' => self::Waf() ? (isset($c['waf']) ? (string) $c['waf'] : 'off') : 'none',
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
        $mark = (getenv('SNODE_RUNMARK') ?: '/run/sentora-docker-node') . '/started';
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
            } elseif ($st[0] === 0 && self::IsoOf($c['user'], $name) === 'gvisor' && !self::InSlice($c['user'], $name, (int) $cid)) {
                // (started by Podman's own unit outside its slice: started again inside it, where its limits are)
                $r = self::Podman($c['user'], array('restart', '--time', '20', $name), 300);
                self::Out('restarted ' . $name . ' inside its limits after the restart of the server: ' . ($r[0] === 0 ? 'ok' : self::Tail($r[1])));
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
     * Leftovers, once an hour (agent 113): an app's "-old" copy from an update the node did not finish
     * (removed when the app itself runs; put back when the app itself is missing), and a customer's
     * user with no containers and no volumes at all for 7 days (removed: no data is ever deleted here).
     * Returns lines for the log.
     */
    static function Tidy(array &$s)
    {
        $out = array();
        $known = array();
        foreach ($s['containers'] as $c) {
            $known[(string) $c['user']] = true;
        }
        if (!isset($s['empty']) || !is_array($s['empty'])) {
            $s['empty'] = array();
        }
        foreach (self::Users() as $user) {
            $uid = self::Uid($user);
            if ($uid < 0) {
                continue;
            }
            $r = self::Podman($user, array('ps', '--all', '--format', 'json'), 60);
            if ($r[0] !== 0) {
                continue;
            }
            $states = array();
            foreach ((array) json_decode($r[1], true) as $p) {
                foreach (isset($p['Names']) && is_array($p['Names']) ? $p['Names'] : array() as $nm) {
                    $states[(string) $nm] = isset($p['State']) ? strtolower((string) $p['State']) : '';
                }
            }
            foreach ($states as $nm => $st) {
                if (!preg_match('/^(sdh-(\d+))-old$/', $nm, $m)) {
                    continue;
                }
                if (isset($states[$m[1]])) {
                    if ($states[$m[1]] === 'running') {
                        self::Podman($user, array('rm', '--force', '--ignore', $nm), 120);
                        $out[] = 'tidy: removed ' . $nm . ' of ' . $user . ' (left by an update that did not finish; ' . $m[1] . ' runs)';
                    }
                } elseif (isset($s['containers'][$m[2]])) {
                    // the update stopped half way: the old version comes back
                    if (self::Podman($user, array('rename', $nm, $m[1]))[0] === 0) {
                        self::Podman($user, array('start', $m[1]), 120);
                        $out[] = 'tidy: put back ' . $m[1] . ' of ' . $user . ' (an update stopped half way)';
                    }
                }
            }
            $vols = self::Podman($user, array('volume', 'ls', '--quiet'), 60);
            $empty = !$states && !isset($known[$user]) && $vols[0] === 0 && trim($vols[1]) === '';
            if (!$empty) {
                unset($s['empty'][$user]);
                continue;
            }
            if (!isset($s['empty'][$user])) {
                $s['empty'][$user] = time();
                continue;
            }
            if (time() - (int) $s['empty'][$user] < (int) (getenv('SNODE_EMPTY_DAYS') !== false ? getenv('SNODE_EMPTY_DAYS') : 7) * 86400) {
                continue;
            }
            self::Run(array('loginctl', 'disable-linger', $user), null, 30);
            self::Run(array('systemctl', 'stop', 'user@' . $uid . '.service'), null, 60);
            $d = self::Run(array('userdel', '--remove', $user), null, 60);
            if ($d[0] === 0) {
                unset($s['empty'][$user]);
                $out[] = 'tidy: removed the user ' . $user . ' (no apps and no data for 7 days)';
            } else {
                $out[] = 'tidy: ' . $user . ' could not be removed: ' . self::Tail($d[1]);
            }
        }
        if ($out) {
            self::Fence(true);
        }
        return $out;
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
        // the version and the data go from one user to the other through files of root's own (Podman writes
        // them to its output and reads them from its input): root never works in either user's home
        $temps = array();
        $made = array();
        $clean = function () use (&$made, &$temps, $u) {
            foreach ($made as $m) {
                self::Podman($u, $m[0] === 'c' ? array('rm', '--force', '--ignore', $m[1]) : array('volume', 'rm', '--force', $m[1]));
            }
            foreach ($temps as $f) {
                @unlink($f);
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
            $f = $temps[] = self::RootTemp('move-image');
            $r = self::Podman($fu, array('image', 'save', $id), 1800, null, $f);
            $ok = $r[0] === 0;
            $r = $ok ? self::Podman($u, array('image', 'load'), 1800, $f) : $r;
            @unlink($f);
            if (!$ok || $r[0] !== 0) {
                return $back('the app\'s version could not be handed over (' . self::Tail($r[1]) . ')');
            }
        }
        for ($i = 0; $i < count($d['volumes']); $i++) {
            $f = $temps[] = self::RootTemp('move-v' . $i);
            $r = self::Podman($fu, array('volume', 'export', $old . '-' . $i), 3600, null, $f);
            if ($r[0] !== 0) {
                return $back('data folder ' . $i . ' could not be read (' . self::Tail($r[1]) . ')');
            }
            $r = self::Podman($u, array('volume', 'create', $name . '-' . $i));
            if ($r[0] !== 0) {
                return $back('data folder ' . $i . ' could not be made (' . self::Tail($r[1]) . ')');
            }
            $made[] = array('v', $name . '-' . $i);
            $r = self::Podman($u, array('volume', 'import', $name . '-' . $i, '-'), 3600, $f);
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
        foreach ($temps as $f) {
            @unlink($f);
        }
        $px = self::Proxy($s);
        return $px === '' ? array(true, 'moved to ' . $u . ' as ' . $name . ', with its data') : array(false, $px);
    }

    // ----- app backups, restores and moves (agent 111) -----
    // An app's data folders go to the panel in pieces (the panel's sealed messages carry at most 4 MB) and
    // come back the same way: nodes never talk to each other, and nothing on a node is opened for the panel.

    /** One piece of a backup on its way to or from the panel. */
    const PIECE = 2097152;

    /** A sealed message to the panel outside the poll (a piece of a backup), or null. */
    static function Tell($type, array $data)
    {
        $cfg = self::$cfg;
        $sk = self::Bin($cfg['sign_sk'], SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $kp = self::Bin($cfg['box_kp'], SODIUM_CRYPTO_BOX_KEYPAIRBYTES);
        return self::Call(self::Msg($type, $cfg['node'], $data), $sk, $kp, $cfg);
    }

    /** Root's own work folder for one backup (customers can not reach it). */
    static function TransferDir($token)
    {
        @mkdir(self::$state . '/transfer', 0700, true);
        @chmod(self::$state . '/transfer', 0700);
        $p = self::$state . '/transfer/' . $token;
        @mkdir($p, 0700, true);
        return $p;
    }

    static function Wipe($dir)
    {
        foreach ((array) glob($dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    static function Pause($s)
    {
        $w = getenv('SNODE_RETRY_WAIT');
        sleep($w !== false ? (int) $w : $s);
    }

    /** Whether a transfer's details are sound: '' or what is wrong. */
    static function CheckTransfer(array $d, $withFiles)
    {
        if (!isset($d['token']) || !is_string($d['token']) || !preg_match('/^[a-f0-9]{32}$/', $d['token'])) {
            return 'bad transfer';
        }
        if (!$withFiles) {
            return '';
        }
        if (!isset($d['files']) || !is_array($d['files']) || !isset($d['volumes']) || count($d['files']) !== count($d['volumes'])) {
            return 'bad files';
        }
        foreach (array_values($d['files']) as $i => $f) {
            if (!is_array($f) || !isset($f['name'], $f['size'], $f['sha']) || $f['name'] !== 'v' . $i . '.tar.gz' || !is_int($f['size']) || $f['size'] < 1
                || !is_string($f['sha']) || !preg_match('/^[a-f0-9]{64}$/', $f['sha'])) {
                return 'bad files';
            }
        }
        return '';
    }

    /** Send one file to the panel, piece by piece: '' or what went wrong. */
    static function Upload($token, $file, $path)
    {
        $size = (int) filesize($path);
        $sha = hash_file('sha256', $path);
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return 'the copy could not be read';
        }
        $off = 0;
        $tries = 0;
        $end = time() + 6 * 3600;
        while (time() < $end) {
            fseek($fh, $off);
            $piece = $off < $size ? (string) fread($fh, self::PIECE) : '';
            $r = self::Tell('put', array('token' => $token, 'file' => $file, 'offset' => $off, 'size' => $size, 'sha' => $sha, 'data' => base64_encode($piece)));
            $d = $r && isset($r['data']) && is_array($r['data']) ? $r['data'] : null;
            if (!$d) {
                if (++$tries > 10) {
                    fclose($fh);
                    return 'the panel did not take the copy';
                }
                self::Pause(min(60, 5 * $tries));
                continue;
            }
            if (isset($d['stop'])) {
                fclose($fh);
                return 'the panel stopped it: ' . substr((string) $d['stop'], 0, 200);
            }
            if (isset($d['busy'])) {
                self::Pause(max(5, min(120, (int) $d['busy'])));
                continue;
            }
            $tries = 0;
            if (!empty($d['done'])) {
                fclose($fh);
                return '';
            }
            if (!isset($d['have']) || !is_int($d['have']) || $d['have'] < 0 || $d['have'] > $size) {
                fclose($fh);
                return 'the panel answered oddly';
            }
            $off = $d['have'];
        }
        fclose($fh);
        return 'the copy took too long';
    }

    /** Fetch one file from the panel, piece by piece, and check it: '' or what went wrong. */
    static function Download($token, array $f, $path)
    {
        $fh = fopen($path, 'wb');
        if (!$fh) {
            return 'no room for the copy';
        }
        $off = 0;
        $tries = 0;
        $end = time() + 6 * 3600;
        while ($off < $f['size']) {
            if (time() > $end) {
                fclose($fh);
                return 'the copy took too long';
            }
            $r = self::Tell('get', array('token' => $token, 'file' => $f['name'], 'offset' => $off));
            $d = $r && isset($r['data']) && is_array($r['data']) ? $r['data'] : null;
            if (!$d) {
                if (++$tries > 10) {
                    fclose($fh);
                    return 'the panel did not hand the copy over';
                }
                self::Pause(min(60, 5 * $tries));
                continue;
            }
            if (isset($d['stop'])) {
                fclose($fh);
                return 'the panel stopped it: ' . substr((string) $d['stop'], 0, 200);
            }
            if (isset($d['busy'])) {
                self::Pause(max(5, min(120, (int) $d['busy'])));
                continue;
            }
            $bin = isset($d['data']) && is_string($d['data']) ? base64_decode($d['data'], true) : false;
            if ($bin === false || $bin === '' || strlen($bin) > self::PIECE || $off + strlen($bin) > $f['size']) {
                fclose($fh);
                return 'a piece of the copy did not arrive whole';
            }
            fwrite($fh, $bin);
            $off += strlen($bin);
            $tries = 0;
        }
        fclose($fh);
        clearstatcache();
        if ((int) filesize($path) !== $f['size'] || hash_file('sha256', $path) !== $f['sha']) {
            return 'the copy did not arrive whole (its checksum differs)';
        }
        return '';
    }

    /**
     * Back an app's data up to the panel: the app stops while its data folders are read (so the copy is
     * whole), then runs again (for a move, it stays stopped until the panel says how the move went).
     * [ok, JSON of the files sent, the exact version it runs and whether it ran]
     */
    static function Backup(array $d, array &$s)
    {
        $key = (string) $d['cid'];
        if (!isset($s['containers'][$key])) {
            return array(false, 'no such container here');
        }
        $bad = self::CheckTransfer($d, false);
        if ($bad !== '') {
            return array(false, 'refused: ' . $bad);
        }
        $u = $d['user'];
        $name = self::Name($d['cid']);
        $n = (int) $s['containers'][$key]['volumes'];
        $dir = self::TransferDir($d['token']);
        $st = self::Podman($u, array('container', 'inspect', '--format', '{{.State.Status}}', $name));
        if ($st[0] !== 0) {
            return array(false, 'no such container here');
        }
        $was = trim($st[1]);
        $id = trim(self::Podman($u, array('container', 'inspect', '--format', '{{.Image}}', $name))[1]);
        $ref = '';
        $rd = self::Podman($u, array('image', 'inspect', '--format', '{{json .RepoDigests}}', $id), 60);
        $list = json_decode(trim($rd[1]), true);
        foreach (is_array($list) ? $list : array() as $x) {
            if (is_string($x) && strlen($x) <= 255 && preg_match('#^[a-z0-9.-]+(:\d{1,5})?/[a-z0-9._/-]+@sha256:[a-f0-9]{64}$#', $x)) {
                $ref = $x;
                break;
            }
        }
        $keepStopped = !empty($d['move']);
        if ($was === 'running') {
            self::Podman($u, array('stop', '--time', '20', $name), 120);
        }
        $files = array();
        $err = '';
        for ($i = 0; $i < $n; $i++) {
            // (straight from Podman's output into root's own folder: nothing in the user's home)
            $to = $dir . '/v' . $i . '.tar';
            $r = self::Podman($u, array('volume', 'export', $name . '-' . $i), 3600, null, $to);
            clearstatcache();
            if ($r[0] !== 0 || is_link($to) || !is_file($to)) {
                @unlink($to);
                $err = 'data folder ' . $i . ' could not be read (' . self::Tail($r[1]) . ')';
                break;
            }
            $g = self::Run(array('gzip', '-1', '-f', $to), null, 3600);
            if ($g[0] !== 0 || !is_file($to . '.gz')) {
                $err = 'data folder ' . $i . ' could not be packed (' . self::Tail($g[1]) . ')';
                break;
            }
            $files[] = array('name' => 'v' . $i . '.tar.gz', 'path' => $to . '.gz');
        }
        // the app runs again as soon as its data is read (a move: only when the panel says so)
        if ($was === 'running' && ($err !== '' || !$keepStopped)) {
            self::Podman($u, array('start', $name));
        } elseif ($keepStopped) {
            $s['containers'][$key]['stopped'] = 1;
            self::SaveState($s);
        }
        if ($err !== '') {
            self::Wipe($dir);
            return array(false, $err);
        }
        $out = array();
        foreach ($files as $f) {
            $size = (int) filesize($f['path']);
            $sha = hash_file('sha256', $f['path']);
            $e = self::Upload($d['token'], $f['name'], $f['path']);
            if ($e !== '') {
                self::Wipe($dir);
                if ($keepStopped && $was === 'running') {
                    unset($s['containers'][$key]['stopped']);
                    self::SaveState($s);
                    self::Podman($u, array('start', $name));
                }
                return array(false, $e);
            }
            $out[] = array('name' => $f['name'], 'size' => $size, 'sha' => $sha);
        }
        self::Wipe($dir);
        return array(true, json_encode(array('files' => $out, 'image' => $ref, 'was' => $was)));
    }

    /**
     * Put a backup back: over the app's own data (today's data is kept aside and put back when anything
     * fails), or as a new app on this node (a move: the same version, by its digest). [ok, output]
     */
    static function Restore(array $d, array &$s)
    {
        $bad = self::CheckTransfer($d, true);
        if ($bad !== '') {
            return array(false, 'refused: ' . $bad);
        }
        $key = (string) $d['cid'];
        $u = $d['user'];
        $name = self::Name($d['cid']);
        $here = isset($s['containers'][$key]);
        $n = count($d['volumes']);
        $catalogImage = $d['image'];
        if ($here && (int) $s['containers'][$key]['volumes'] !== $n) {
            return array(false, 'refused: the app has ' . (int) $s['containers'][$key]['volumes'] . ' data folders, the backup ' . $n);
        }
        if (!$here) {
            foreach ($s['containers'] as $k => $c) {
                if ((int) $c['hostport'] === $d['hostport']) {
                    return array(false, 'refused: port ' . $d['hostport'] . ' is taken');
                }
            }
            if (self::Podman($u, array('container', 'exists', $name))[0] === 0) {
                return array(false, 'refused: ' . $name . ' is already here');
            }
            $e = self::EnsureUser($u);
            if ($e !== '') {
                return array(false, $e);
            }
            $fe = self::Fence();
            if ($fe !== '') {
                self::Out($fe);
            }
        }
        $dir = self::TransferDir($d['token']);
        $tars = array();
        $clean = function () use ($dir, &$tars) {
            foreach ($tars as $t) {
                @unlink($t);
            }
            self::Wipe($dir);
        };
        // 1. the copy, checked
        foreach (array_values($d['files']) as $i => $f) {
            $p = $dir . '/' . $f['name'];
            $e = self::Download($d['token'], $f, $p);
            if ($e === '') {
                $g = self::Run(array('gzip', '-d', '-f', $p), null, 3600);
                $e = $g[0] === 0 && is_file($dir . '/v' . $i . '.tar') ? '' : 'the copy could not be unpacked (' . self::Tail($g[1]) . ')';
            }
            // (it stays in root's own folder: Podman reads it on its input when the data folder is made)
            $t = $dir . '/v' . $i . '.tar';
            if ($e !== '') {
                $clean();
                return array(false, $e);
            }
            $tars[] = $t;
        }
        // 2. the version: the one the app runs (here), or the same one again (a move)
        $was = '';
        if ($here) {
            $id = trim(self::Podman($u, array('container', 'inspect', '--format', '{{.Image}}', $name))[1]);
            if (!preg_match('/^(sha256:)?[A-Za-z0-9._-]{3,128}$/', $id)) {
                $clean();
                return array(false, 'the version the app runs could not be read');
            }
            $d['image'] = $id;
            $was = trim(self::Podman($u, array('container', 'inspect', '--format', '{{.State.Status}}', $name))[1]);
        } else {
            $ref = isset($d['image_ref']) && is_string($d['image_ref']) && preg_match('#^[a-z0-9.-]+(:\d{1,5})?/[a-z0-9._/-]+@sha256:[a-f0-9]{64}$#', $d['image_ref']) ? $d['image_ref'] : '';
            $pull = $ref !== '' ? self::Pull($u, $ref, $d) : array(1, '');
            if ($pull[0] === 0) {
                $d['image'] = $ref;
            } else {
                $pull = self::Pull($u, $d['image'], $d);
                if ($pull[0] !== 0) {
                    $clean();
                    return array(false, 'pull: ' . self::Tail($pull[1]));
                }
            }
            $was = 'running';
        }
        // 3. today's data kept aside (an app restored in place)
        $safe = array();
        if ($here) {
            self::Podman($u, array('stop', '--time', '20', $name), 120);
            for ($i = 0; $i < $n; $i++) {
                $t = $dir . '/s' . $i . '.tar';
                $r = self::Podman($u, array('volume', 'export', $name . '-' . $i), 3600, null, $t);
                if ($r[0] !== 0) {
                    $clean();
                    if ($was === 'running') {
                        self::Podman($u, array('start', $name));
                    }
                    return array(false, 'today\'s data (folder ' . $i . ') could not be kept aside, so nothing was changed (' . self::Tail($r[1]) . ')');
                }
                $safe[] = $t;
                $tars[] = $t;
            }
        }
        // 4. the data folders made anew from a set of copies, and the app with them
        $build = function (array $from, $start) use ($u, $name, $n, $d) {
            self::Podman($u, array('rm', '--force', '--ignore', $name), 300);
            for ($i = 0; $i < $n; $i++) {
                self::Podman($u, array('volume', 'rm', '--force', $name . '-' . $i));
                $r = self::Podman($u, array('volume', 'create', $name . '-' . $i));
                if ($r[0] === 0) {
                    $r = self::Podman($u, array('volume', 'import', $name . '-' . $i, '-'), 3600, $from[$i]);
                }
                if ($r[0] !== 0) {
                    return 'data folder ' . $i . ' could not be put back (' . self::Tail($r[1]) . ')';
                }
            }
            $r = self::Podman($u, self::CreateArgs($d, $name));
            if ($r[0] !== 0) {
                return 'create: ' . self::Tail($r[1]);
            }
            if (!$start) {
                return '';
            }
            $r = self::Podman($u, array('start', $name));
            if ($r[0] !== 0) {
                return 'start: ' . self::Tail($r[1]);
            }
            sleep((int) (getenv('SNODE_UPDATE_WAIT') !== false ? getenv('SNODE_UPDATE_WAIT') : 20));
            $st = trim(self::Podman($u, array('container', 'inspect', '--format', '{{.State.Status}}', $name))[1]);
            return $st === 'running' ? '' : 'it stopped after starting (' . $st . ')';
        };
        $e = $build($tars, $was === 'running');
        if ($e !== '') {
            if ($here) {
                $e2 = $build($safe, $was === 'running');
                $clean();
                return array(false, $e . ($e2 === '' ? ': the app runs with its data from before the restore' : '; putting its data back failed too: ' . $e2));
            }
            self::Podman($u, array('rm', '--force', '--ignore', $name), 300);
            for ($i = 0; $i < $n; $i++) {
                self::Podman($u, array('volume', 'rm', '--force', $name . '-' . $i));
            }
            $clean();
            return array(false, $e . ': nothing was left on this node');
        }
        $clean();
        if (!$here) {
            $s['containers'][$key] = array('user' => $u, 'domain' => $d['domain'], 'hostport' => $d['hostport'], 'public' => (int) $d['public'],
                'auth' => (string) $d['auth'], 'volumes' => $n, 'image' => $catalogImage);
        } elseif ($was === 'running') {
            unset($s['containers'][$key]['stopped']);
        }
        self::SaveState($s);
        $px = self::Proxy($s);
        return $px === '' ? array(true, $here ? 'restored, with the backup\'s data' : 'made here from the backup, with its data') : array(false, $px);
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
        $file = getenv('SNODE_KEYFILE') ?: '/root/sentora-docker-node-key.txt';
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
     * (Run through sentora-docker-node-relink, which stops the agent first and starts it again after.)
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
                    self::Out('  sentora-docker-node-relink --panel ' . $panel . ' --new-key');
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
            @unlink(getenv('SNODE_KEYFILE') ?: '/root/sentora-docker-node-key.txt');
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
        $t = "table inet sentora_docker_node {}\ndelete table inet sentora_docker_node\ntable inet sentora_docker_node {\n"
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
        // (nor this node's own loopback: Caddy's admin interface and the other customers' apps listen there;
        // only new connections: the replies of an app's own port, which Caddy connects to, go out as its user)
        $t .= "\t\tmeta skuid @apps ip daddr 127.0.0.0/8 ct state new reject\n"
            . "\t\tmeta skuid @apps ip6 daddr ::1 ct state new reject\n";
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
            foreach (self::Tidy($s) as $line) {
                self::Out($line);
            }
            self::SaveState($s);
        }
        // the apps' sites written again once each time the agent starts, after it looked for apps missing from its
        // list (e.g. after the node's update brought the web firewall: the apps it already has are put behind it;
        // Caddy is reloaded only when something changed)
        static $sites = false;
        if (!$sites) {
            $sites = true;
            $px = self::Proxy($s);
            if ($px !== '') {
                self::Out($px);
            }
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
            self::Out('This node is not linked to a panel yet (sentora-docker-node-agent pair ...).');
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
                self::Out('Sentora Docker Node agent ' . self::VERSION . '. Panel: ' . self::$cfg['panel'] . ': not added there yet (its node key is in ' . (getenv('SNODE_KEYFILE') ?: '/root/sentora-docker-node-key.txt') . ').');
                return 0;
            }
            self::Out('Sentora Docker Node agent ' . self::VERSION . '. Panel: ' . self::$cfg['panel'] . ', node ' . self::$cfg['node'] . ', ' . count($s['containers']) . ' containers');
            foreach (self::States($s) as $cid => $st) {
                self::Out(sprintf('  %s  %-8s %-10s %s (%d MB)', self::Name($cid), $st['user'], $st['state'], $s['containers'][$cid]['domain'], $st['used_mb']));
            }
            return 0;
        }
        if ($cmd === 'run') {
            self::Out('Sentora Docker Node agent ' . self::VERSION . ': calling ' . self::$cfg['panel'] . ((int) self::$cfg['node'] === 0 ? ', waiting to be added there with its node key' : ' as node ' . self::$cfg['node']));
            while (true) {
                sleep((int) self::$cfg['node'] === 0 ? self::Hello() : self::Once());
            }
        }
        self::Out("sentora-docker-node-agent enroll|relink|pair|run|once|status");
        return 2;
    }
}

exit(snode::Main($argv));
