<?php

/**
 * Log Forwarder's Elk node (administrators): the panel's logs, live, to the panel's Elk node (Elasticsearch, Logstash and Kibana on a
 * server of its own, installed with sentora2027-elk-node), through this server's own rsyslog.
 *
 * - The panel has a certificate of its own for this (made by the daemon, root's key in DIR; the certificate itself is
 *   public: the node's installer fetches it from modules/log_forwarder/code/node.php?cert=1 and shows the panel's code, which the
 *   administrator compares with this page). The node takes lines only from that certificate.
 * - "Add the Elk node" stores the node's address and key (SE1-...); the daemon connects, finds the node's certificate
 *   authority in what the node shows, compares its code with the key typed, and only then pins it: rsyslog checks the
 *   node against that authority and the node's name (sentora-elk-node) on every connection.
 * - The daemon (root, every run) turns the chosen groups of logs into rsyslog's settings: files followed with imfile
 *   (websites, the panel, Dovecot, FTP, DNS, fail2ban...) and the lines rsyslog already receives from the journal by
 *   program (Postfix, SSH logins, sudo), sent as JSON lines over TLS with a disk queue (1 GB) while the node is away.
 *   A setting rsyslog refuses is never left in place. Paths come from the panel's settings (log_dir) and the
 *   system's own files; rsyslog's user, AppArmor and SELinux are handled as Log Forwarder does.
 * - Every supported system: rsyslog and its TLS driver are installed with the system's package command.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-20 (part of Log Forwarder since 203)
 */
class elk
{
    const DIR = '/etc/sentora/elk';
    /** The separate rsyslog file of the first version (removed: the Elk node's lines are in Log Forwarder's file). */
    const CONF = '/etc/rsyslog.d/61-sentora-elk.conf';
    const STATE_DIR = '/var/lib/sentora/elk';
    const AA_SNIPPET = '/etc/apparmor.d/rsyslog.d/sentora-elk';
    const AA_PROFILE = '/etc/apparmor.d/usr.sbin.rsyslogd';
    const PORT = 6514;
    const NODE_NAME = 'sentora-elk-node';
    const NODE_TYPE = 'sentora-elk';
    const PREFIX = 'SE1';
    const QUEUE = '1g';
    /** The hidden settings (x_settings, module Elk). */
    const S_CERT = 'elk_panel_cert';
    const S_NODE = 'elk_node';
    const S_NODEKEY = 'elk_nodekey';
    const S_NODECA = 'elk_node_ca';
    const S_PAIRING = 'elk_pairing';
    const S_GROUPS = 'elk_groups';
    const S_CHECK = 'elk_check';
    const S_IMPORT = 'elk_import';

    // ---------------------------------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------------------------------

    /**
     * A hidden setting of this module ('' when not there).
     *
     * @param string $name One of the S_ constants.
     * @return string
     */
    static function Setting($name)
    {
        $v = ctrl_options::GetSystemOption($name);
        return $v === false || $v === null ? '' : (string) $v;
    }

    /**
     * Write a hidden setting (made when it is not there; never shown in Sentora Config).
     *
     * @param string $name One of the S_ constants.
     * @param string $value
     * @return void
     */
    static function Save($name, $value)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_settings WHERE so_name_vc = :n");
        $q->execute(array(':n' => $name));
        if ((int) $q->fetchColumn() === 0) {
            $zdbh->prepare("INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                VALUES (:n, :c, '', NULL, 'Log Forwarder: the Elk node (written by the panel and the daemon).', 'Log Forwarder', 'false')")
                ->execute(array(':n' => $name, ':c' => ucfirst(str_replace('_', ' ', $name))));
        }
        $zdbh->prepare("UPDATE x_settings SET so_value_tx = :v WHERE so_name_vc = :n")->execute(array(':v' => (string) $value, ':n' => $name));
    }

    /**
     * The groups of logs an administrator can send, in the page's order: key => (label, what is in it).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    static function Groups()
    {
        return array(
            'websites' => array('Websites', 'Every hosted website\'s visits and errors (Apache).'),
            'mail' => array('Mail', 'Postfix (sent, bounced, refused), spam scores (SpamAssassin), Dovecot (mailbox logins), webmail logins.'),
            'logins' => array('Server logins', 'SSH logins and sudo, FTP logins.'),
            'bans' => array('Bans', 'fail2ban: addresses found and banned, by jail.'),
            'panel' => array('The panel', 'Panel pages and errors, failed panel logins, what root did for the panel, the daemon, the problems log.'),
            'ftp' => array('FTP', 'Uploads and other file changes over FTP.'),
            'dns' => array('DNS', 'BIND\'s log.'),
            'health' => array('Server health', 'CPU, memory, disk, network, Apache, MySQL and the mail queue, every 5 minutes.'),
            'alerts' => array('Alerts', 'What the node finds that needs a look (a website down, a certificate running out, a full disk, a burst of failed logins...): a notice in the panel and a mail to the administrators.'),
        );
    }

    /**
     * The groups switched on (all of them until the administrator chooses).
     *
     * @return string[]
     */
    static function Chosen()
    {
        $v = self::Setting(self::S_GROUPS);
        if ($v === '') {
            return array_keys(self::Groups());
        }
        $parts = explode(',', $v);
        // (a choice saved before a group was there gets it too, unless nothing was chosen: "v2" knew Server health, "v3" Alerts)
        if (!in_array('v3', $parts, true) && $v !== 'none') {
            $parts[] = 'alerts';
            if (!in_array('v2', $parts, true)) {
                $parts[] = 'health';
            }
        }
        return array_values(array_intersect(array_keys(self::Groups()), $parts));
    }

    /**
     * The panel's certificate (public; '' until the daemon has made it).
     *
     * @return string
     */
    static function PanelCert()
    {
        $pem = self::Setting(self::S_CERT);
        return strpos($pem, '-----BEGIN CERTIFICATE-----') === 0 ? $pem : '';
    }

    /**
     * The node: array(addr, key typed, pinned CA, pairing state) ('' parts when not set).
     *
     * @return array{addr: string, key: string, ca: string, pairing: string}
     */
    static function Node()
    {
        return array('addr' => self::Setting(self::S_NODE), 'key' => self::Setting(self::S_NODEKEY), 'ca' => self::Setting(self::S_NODECA),
            'pairing' => self::Setting(self::S_PAIRING));
    }

    /**
     * Is the node paired (its certificate authority pinned)?
     *
     * @return bool
     */
    static function Paired()
    {
        $n = self::Node();
        return $n['addr'] !== '' && strpos($n['ca'], '-----BEGIN CERTIFICATE-----') === 0;
    }

    // ---------------------------------------------------------------------------------------------
    // Codes (the node framework's, as the core's sys_nodes and NODES.md: base32 without I, O, 0, 1)
    // ---------------------------------------------------------------------------------------------

    /**
     * Base32 of bytes, with the node framework's letters.
     *
     * @param string $bin
     * @return string
     */
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
     * A certificate's public key, as one line: base64 of its SubjectPublicKeyInfo (DER).
     *
     * @param string $pem A certificate.
     * @return string '' when it is not one.
     */
    static function Spki($pem)
    {
        $k = @openssl_pkey_get_public($pem);
        if ($k === false) {
            return '';
        }
        $d = openssl_pkey_get_details($k);
        if (!is_array($d) || !isset($d['key'])) {
            return '';
        }
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', (string) $d['key']);
    }

    /**
     * The panel's code for the Elk node type (shown on the page; the node's installer shows the same).
     *
     * @return string XXXX-XXXX, or '' before the certificate exists.
     */
    static function PanelCode()
    {
        $spki = self::Spki(self::PanelCert());
        if ($spki === '') {
            return '';
        }
        if (class_exists('sys_nodes') && method_exists('sys_nodes', 'PanelCode')) {
            return sys_nodes::PanelCode(self::NODE_TYPE, $spki);
        }
        $c = substr(self::Base32(substr(hash('sha256', self::NODE_TYPE . '|' . $spki, true), 0, 10)), 0, 8);
        return substr($c, 0, 4) . '-' . substr($c, 4, 4);
    }

    /**
     * A certificate's key code: the first 16 letters of base32 of the SHA-256 of its public key (DER).
     *
     * @param string $pem
     * @return string '' when it is not a certificate.
     */
    static function KeyCode($pem)
    {
        $spki = self::Spki($pem);
        return $spki === '' ? '' : substr(self::Base32(substr(hash('sha256', (string) base64_decode($spki), true), 0, 10)), 0, 16);
    }

    /**
     * The 16 letters of a node key as typed (SE1-XXXX-XXXX-XXXX-XXXX; spaces, case and dashes do not matter).
     *
     * @param string $typed
     * @return string '' when it is not one.
     */
    static function ParseKey($typed)
    {
        $k = strtoupper(preg_replace('/[\s-]+/', '', (string) $typed));
        if (strpos($k, self::PREFIX) === 0) {
            $k = substr($k, strlen(self::PREFIX));
        }
        return preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{16}$/', $k) ? $k : '';
    }

    /**
     * A node key written out.
     *
     * @param string $code The 16 letters.
     * @return string
     */
    static function NodeKey($code)
    {
        return self::PREFIX . '-' . implode('-', str_split($code, 4));
    }

    /**
     * Is this an address a node can have (a name or an IP address)?
     *
     * @param string $a
     * @return bool
     */
    static function ValidAddr($a)
    {
        return filter_var($a, FILTER_VALIDATE_IP) !== false
            || (strlen($a) <= 253 && preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $a));
    }

    /**
     * The page: store the node to add (the daemon pairs it). Returns '' or what is wrong.
     *
     * @param string $addr
     * @param string $key
     * @return string
     */
    static function AddNode($addr, $key)
    {
        $addr = strtolower(trim((string) $addr));
        if (!self::ValidAddr($addr)) {
            return 'That is not an address: the Elk node\'s IP address or name.';
        }
        if (self::ParseKey($key) === '') {
            return 'That is not an Elk node key: it looks like SE1-XXXX-XXXX-XXXX-XXXX (sentora-elk-node key on the node shows it).';
        }
        self::Save(self::S_NODE, $addr);
        self::Save(self::S_NODEKEY, self::NodeKey(self::ParseKey($key)));
        self::Save(self::S_NODECA, '');
        self::Save(self::S_PAIRING, 'wait ' . time());
        return '';
    }

    /**
     * The page: forget the node (the panel stops sending at the next daemon run; the logs stay on the node).
     *
     * @return void
     */
    static function RemoveNode()
    {
        foreach (array(self::S_NODE, self::S_NODEKEY, self::S_NODECA, self::S_PAIRING) as $n) {
            self::Save($n, '');
        }
    }

    /**
     * The page: the groups to send.
     *
     * @param string[] $groups
     * @return void
     */
    static function SaveGroups(array $groups)
    {
        $g = array_values(array_intersect(array_keys(self::Groups()), $groups));
        $g[] = 'v3';
        self::Save(self::S_GROUPS, implode(',', $g));
    }

    /**
     * What the daemon last noted (for the page).
     *
     * @return array
     */
    static function State()
    {
        $j = @json_decode((string) @file_get_contents(self::STATE_DIR . '/status.json'), true);
        return is_array($j) ? $j : array();
    }

    // ---------------------------------------------------------------------------------------------
    // The daemon's side (root)
    // ---------------------------------------------------------------------------------------------

    /**
     * A command that runs with only its own input and output open (the core's ctrl_system::OwnFiles, when this panel
     * has it).
     *
     * @param string $cmd
     * @return string
     */
    static function Own($cmd)
    {
        return method_exists('ctrl_system', 'OwnFiles') ? ctrl_system::OwnFiles($cmd) : $cmd;
    }

    /**
     * rsyslog's program ('' when not installed).
     *
     * @return string
     */
    static function RsyslogBin()
    {
        foreach (array('/usr/sbin/rsyslogd', '/sbin/rsyslogd') as $b) {
            if (is_file($b) && is_executable($b)) {
                return $b;
            }
        }
        return '';
    }

    /**
     * rsyslog's TLS driver ('' when missing).
     *
     * @return string
     */
    static function TlsModule()
    {
        foreach (array_merge((array) glob('/usr/lib*/rsyslog/lmnsd_gtls.so'), (array) glob('/usr/lib/*/rsyslog/lmnsd_gtls.so')) as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        return '';
    }

    /**
     * The packages missing (the same names on every supported system).
     *
     * @return string[]
     */
    static function Missing()
    {
        $need = array();
        if (self::RsyslogBin() === '') {
            $need[] = 'rsyslog';
        }
        if (self::TlsModule() === '') {
            $need[] = 'rsyslog-gnutls';
        }
        if (!is_executable('/usr/bin/setfacl') && !is_executable('/bin/setfacl')) {
            $need[] = 'acl';
        }
        return $need;
    }

    /**
     * Root: install what is missing with this system's package command (the setting package_install), let rsyslog
     * start with the system, and have the journal hand it its lines (rsyslog's package asks the journal for them;
     * the journal reads that when it starts).
     *
     * @return string What happened ('' when nothing was missing).
     */
    static function InstallMissing()
    {
        $need = self::Missing();
        if (!$need) {
            return '';
        }
        $cmd = trim((string) ctrl_options::GetSystemOption('package_install'));
        if ($cmd === '') {
            $cmd = is_executable('/usr/bin/apt-get') ? 'apt-get -qq -y install' : (is_executable('/usr/bin/dnf') ? 'dnf -y -q install' : '');
        }
        if ($cmd === '') {
            return 'could not install ' . implode(', ', $need) . ': this system\'s package command is not known.';
        }
        exec(self::Own('DEBIAN_FRONTEND=noninteractive ' . $cmd . ' ' . implode(' ', array_map('escapeshellarg', $need)) . ' > /dev/null 2>&1'));
        if (in_array('rsyslog', $need, true) && self::RsyslogBin() !== '') {
            exec(self::Own('systemctl restart systemd-journald > /dev/null 2>&1; systemctl enable --now rsyslog > /dev/null 2>&1'));
        }
        $left = self::Missing();
        return $left ? 'could not install ' . implode(', ', $left) . ': sending waits for them.' : 'installed ' . implode(', ', $need) . '.';
    }

    /**
     * The user rsyslog runs as (root on most systems; Ubuntu's drops to syslog).
     *
     * @return string
     */
    static function RsyslogUser()
    {
        $out = array();
        exec('ps -o user= -C rsyslogd 2> /dev/null', $out);
        $u = isset($out[0]) ? trim($out[0]) : '';
        return preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $u) ? $u : 'root';
    }

    /**
     * The group rsyslog's user is in ('root' for root).
     *
     * @return string
     */
    static function RsyslogGroup()
    {
        $u = self::RsyslogUser();
        if ($u === 'root' || !function_exists('posix_getpwnam')) {
            return 'root';
        }
        $p = posix_getpwnam($u);
        $g = is_array($p) ? posix_getgrgid((int) $p['gid']) : false;
        return is_array($g) ? (string) $g['name'] : 'root';
    }

    /**
     * Write a file through a temporary file in its own (root's) folder.
     *
     * @param string $file
     * @param string $data
     * @param int $mode
     * @param string $group
     * @return bool
     */
    static function WriteFile($file, $data, $mode, $group = 'root')
    {
        $dir = dirname($file);
        $tmp = @tempnam($dir, '.elk.');
        if ($tmp === false || dirname($tmp) !== rtrim($dir, '/')) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            return false;
        }
        $ok = @file_put_contents($tmp, $data) !== false && @chmod($tmp, $mode) && @chgrp($tmp, $group) && @rename($tmp, $file);
        if (!$ok) {
            @unlink($tmp);
        }
        return $ok;
    }

    /**
     * Root: the module's folders, the panel's own key and certificate (made once; the certificate is kept public in
     * the settings for code/node.php), and rsyslog's AppArmor rule file. Install, upgrade and every daemon run call it.
     *
     * @return string[] What it did.
     */
    static function Setup()
    {
        $did = array();
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return $did;
        }
        $grp = self::RsyslogGroup();
        foreach (array(self::DIR => array(0750, $grp), self::STATE_DIR => array(0750, 'sentora')) as $dir => $m) {
            if (is_link($dir)) {
                @unlink($dir);
            }
            if (!is_dir($dir)) {
                @mkdir($dir, $m[0], true);
                $did[] = 'made ' . $dir;
            }
            @chown($dir, 0);
            @chgrp($dir, $m[1]);
            @chmod($dir, $m[0]);
        }
        $key = self::DIR . '/panel.key';
        $crt = self::DIR . '/panel.crt';
        if (!is_file($key) || !is_file($crt) || self::Spki((string) @file_get_contents($crt)) === '') {
            $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ctrl_options::GetSystemOption('sentora_domain'));
            $cmd = 'openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout ' . escapeshellarg($key . '.new') . ' -out ' . escapeshellarg($crt . '.new')
                . ' -days 7300 -subj ' . escapeshellarg('/CN=sentora-panel/O=' . ($host !== '' ? $host : 'Sentora panel'))
                . ' -addext "basicConstraints=critical,CA:FALSE" -addext "keyUsage=critical,digitalSignature" -addext "extendedKeyUsage=clientAuth" 2>&1';
            exec(self::Own('umask 077; ' . $cmd), $o, $rc);
            if ($rc === 0 && @rename($key . '.new', $key) && @rename($crt . '.new', $crt)) {
                $did[] = 'made the panel\'s certificate for its Elk node';
            } else {
                @unlink($key . '.new');
                @unlink($crt . '.new');
                $did[] = 'could not make the panel\'s certificate: ' . substr(implode(' ', $o), 0, 200);
            }
        }
        // the key: root's, readable by rsyslog's group (rsyslog opens the connection after it gives up root)
        foreach (array($key => 0640, $crt => 0644) as $f => $mode) {
            if (is_file($f) && !is_link($f)) {
                @chown($f, 0);
                @chgrp($f, $grp);
                @chmod($f, $mode);
            }
        }
        $pem = (string) @file_get_contents($crt);
        if ($pem !== '' && self::Setting(self::S_CERT) !== $pem) {
            self::Save(self::S_CERT, $pem);
        }
        // rsyslog's AppArmor profile (Ubuntu, Debian): the logs it sends, its key and the node's authority
        if (is_file(self::AA_PROFILE) && is_dir(dirname(self::AA_SNIPPET))) {
            $rules = "# Sentora Elk: the logs it sends to the Elk node, the panel's certificate and the node's authority\n"
                . "  /var/sentora/logs/ r,\n  /var/sentora/logs/** r,\n  /var/lib/sentora/*.log r,\n  /var/log/dovecot*.log r,\n  /var/log/fail2ban.log r,\n"
                . "  " . self::DIR . "/ r,\n  " . self::DIR . "/** r,\n";
            if (@file_get_contents(self::AA_SNIPPET) !== $rules) {
                if (is_link(self::AA_SNIPPET)) {
                    @unlink(self::AA_SNIPPET);
                }
                file_put_contents(self::AA_SNIPPET, $rules);
                @chmod(self::AA_SNIPPET, 0644);
                if (is_executable('/sbin/apparmor_parser') || is_executable('/usr/sbin/apparmor_parser')) {
                    exec('apparmor_parser -r ' . escapeshellarg(self::AA_PROFILE) . ' 2>&1', $o2, $rc2);
                    $did[] = $rc2 === 0 ? 'rsyslog may read the logs it sends (AppArmor)' : 'AppArmor did not take the rule file: ' . implode(' ', array_slice($o2, -2));
                }
            }
        }
        return $did;
    }

    /**
     * Root: connect to the node with the panel's certificate and return the certificates it shows.
     *
     * @param string $addr
     * @param bool $pinned Check against the pinned authority and the node's name (else: take what it shows).
     * @return array{ok: bool, chain: string[], text: string}
     */
    static function Connect($addr, $pinned)
    {
        $ssl = array('local_cert' => self::DIR . '/panel.crt', 'local_pk' => self::DIR . '/panel.key', 'capture_peer_cert_chain' => true,
            'SNI_enabled' => true, 'peer_name' => self::NODE_NAME);
        $caFile = '';
        if ($pinned) {
            $caFile = self::DIR . '/node-ca.crt';
            $ssl += array('verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $caFile, 'allow_self_signed' => false);
        } else {
            $ssl += array('verify_peer' => false, 'verify_peer_name' => false);
        }
        $err = '';
        set_error_handler(function ($no, $str) use (&$err) { $err = $err === '' ? $str : $err; return true; });
        $host = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $addr . ']' : $addr;
        $s = stream_socket_client('tls://' . $host . ':' . self::PORT, $no, $str, 10, STREAM_CLIENT_CONNECT, stream_context_create(array('ssl' => $ssl)));
        restore_error_handler();
        if ($s === false) {
            $why = $str !== '' ? $str : $err;
            return array('ok' => false, 'chain' => array(), 'text' => 'The Elk node at ' . $addr . ' did not answer on port ' . self::PORT . ' (' . trim(preg_replace('/^stream_socket_client\(\): /', '', $why)) . ').');
        }
        $p = stream_context_get_params($s);
        fclose($s);
        $chain = array();
        foreach ((array) (isset($p['options']['ssl']['peer_certificate_chain']) ? $p['options']['ssl']['peer_certificate_chain'] : array()) as $c) {
            if (openssl_x509_export($c, $out)) {
                $chain[] = $out;
            }
        }
        return array('ok' => true, 'chain' => $chain, 'text' => '');
    }

    /**
     * Root: pair the node typed on the page. Its key must be the code of the authority that signed the certificate it
     * shows (named sentora-elk-node); only then is that authority pinned.
     *
     * @return string What happened.
     */
    static function Pair()
    {
        $n = self::Node();
        $want = self::ParseKey($n['key']);
        $c = self::Connect($n['addr'], false);
        if (!$c['ok']) {
            self::Save(self::S_PAIRING, 'fail ' . time() . ' ' . $c['text']);
            return $c['text'];
        }
        $leaf = isset($c['chain'][0]) ? $c['chain'][0] : '';
        $info = $leaf !== '' ? openssl_x509_parse($leaf) : false;
        $named = is_array($info) && isset($info['subject']['CN']) && $info['subject']['CN'] === self::NODE_NAME;
        foreach ($c['chain'] as $ca) {
            if ($want !== '' && self::KeyCode($ca) === $want) {
                if (!$named || openssl_x509_verify($leaf, $ca) !== 1) {
                    break;
                }
                self::Save(self::S_NODECA, $ca);
                self::WriteFile(self::DIR . '/node-ca.crt', $ca, 0644, self::RsyslogGroup());
                self::Save(self::S_PAIRING, 'ok ' . time());
                return 'Paired with the Elk node at ' . $n['addr'] . ' (key ' . self::NodeKey($want) . ').';
            }
        }
        $seen = array();
        foreach (array_slice($c['chain'], 1) as $ca) {
            if (($k = self::KeyCode($ca)) !== '') {
                $seen[] = self::NodeKey($k);
            }
        }
        $t = 'The server at ' . $n['addr'] . ' does not have that key' . ($seen ? ' (it shows ' . implode(', ', $seen) . ')' : '')
            . ': nothing was sent there. Check the address and the key (sentora-elk-node key on the node).';
        self::Save(self::S_PAIRING, 'fail ' . time() . ' ' . $t);
        return $t;
    }

    /**
     * The files to follow for the chosen groups: path => array(kind, account, domain).
     *
     * @param string[] $groups
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    static function Files(array $groups)
    {
        global $zdbh;
        $logs = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/');
        $logs = $logs !== '' ? $logs : '/var/sentora/logs';
        $f = array();
        $add = function ($path, $kind, $acct = '', $dom = '') use (&$f) {
            if (is_file($path) && !is_link($path)) {
                $f[$path] = array($kind, $acct, $dom);
            }
        };
        if (in_array('websites', $groups, true)) {
            $q = $zdbh->query("SELECT a.ac_user_vc, v.vh_name_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
                WHERE v.vh_deleted_ts IS NULL AND a.ac_deleted_ts IS NULL AND v.vh_type_in IN (1, 2) ORDER BY v.vh_name_vc");
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
                list($user, $name) = array((string) $r[0], strtolower((string) $r[1]));
                if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $user) || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $name)) {
                    continue;
                }
                $add($logs . '/domains/' . $user . '/' . $name . '-access.log', 'web-access', $user, $name);
                $add($logs . '/domains/' . $user . '/' . $name . '-error.log', 'web-error', $user, $name);
            }
        }
        if (in_array('panel', $groups, true)) {
            $add($logs . '/sentora-access.log', 'panel-access');
            $add($logs . '/sentora-error.log', 'panel-error');
            $add('/var/lib/sentora/sentora-auth.log', 'panel-login');
            $add($logs . '/zsudo.log', 'zsudo');
            $add($logs . '/sentora.log', 'daemon');
            $add($logs . '/problems.log', 'problems');
        }
        if (in_array('mail', $groups, true)) {
            foreach (array('/var/log/dovecot-info.log', '/var/log/dovecot.log') as $p) {
                $add($p, 'dovecot');
            }
            $add($logs . '/roundcube/errors.log', 'webmail');
            $add($logs . '/roundcube/errors', 'webmail');
        }
        if (in_array('logins', $groups, true)) {
            $add($logs . '/proftpd/auth.log', 'ftp-login');
        }
        if (in_array('ftp', $groups, true)) {
            $add($logs . '/proftpd/access.log', 'ftp');
        }
        if (in_array('dns', $groups, true)) {
            $add($logs . '/bind/bind.log', 'dns');
        }
        if (in_array('bans', $groups, true)) {
            $add('/var/log/fail2ban.log', 'fail2ban');
        }
        return $f;
    }

    /**
     * What the Elk node gets, for Log Forwarder's rsyslog settings (null: nothing, not paired or rsyslog missing).
     *
     * @return array{addr: string, files: array<string, array{0: string, 1: string, 2: string}>, streams: string[]}|null
     */
    static function Plan()
    {
        $groups = self::Chosen();
        if (!self::Paired() || !$groups || self::Missing()) {
            return null;
        }
        $n = self::Node();
        $streams = array_values(array_intersect($groups, array('mail', 'logins')));
        $files = self::Files($groups);
        return ($files || $streams) ? array('addr' => $n['addr'], 'files' => $files, 'streams' => $streams) : null;
    }

    /**
     * The Elk node's part of Log Forwarder's rsyslog settings: the template and the output (head), and the lines rsyslog
     * already receives from the journal, by program (tail). The files are followed by Log Forwarder's own inputs (each
     * file once: rsyslog follows a file in one input only), whose rulesets call sentora_elk_out with $.ek* set.
     *
     * @param array $plan From Plan().
     * @return array{head: string, tail: string}
     */
    static function Part(array $plan)
    {
        $q = function ($s) { return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $s) . '"'; };
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ctrl_options::GetSystemOption('sentora_domain'));
        $head = "# The Elk node (administrators): the panel's own logs as JSON lines over TLS, the node's authority pinned\n"
            . "template(name=\"SentoraElk\" type=\"list\" option.jsonf=\"on\") {\n"
            . "    property(outname=\"@timestamp\" name=\"timereported\" dateFormat=\"rfc3339\" format=\"jsonf\")\n"
            . "    property(outname=\"sentora_host\" name=\"\$.ekhost\" format=\"jsonf\")\n"
            . "    property(outname=\"sentora_log\" name=\"\$.eklog\" format=\"jsonf\")\n"
            . "    property(outname=\"sentora_account\" name=\"\$.ekacct\" format=\"jsonf\")\n"
            . "    property(outname=\"sentora_domain\" name=\"\$.ekdomain\" format=\"jsonf\")\n"
            . "    property(outname=\"message\" name=\"\$.ekmsg\" format=\"jsonf\")\n}\n"
            . "ruleset(name=\"sentora_elk_out\") {\n    set \$.ekhost = " . $q($host) . ";\n"
            . "    action(type=\"omfwd\" name=\"sentora_elk\" target=" . $q($plan['addr']) . " port=\"" . self::PORT . "\" protocol=\"tcp\" template=\"SentoraElk\" TCP_Framing=\"traditional\"\n"
            . "        StreamDriver=\"gtls\" StreamDriverMode=\"1\" StreamDriverAuthMode=\"x509/name\" StreamDriverPermittedPeers=\"" . self::NODE_NAME . "\"\n"
            . "        streamDriver.CAFile=\"" . self::DIR . "/node-ca.crt\" streamDriver.CertFile=\"" . self::DIR . "/panel.crt\" streamDriver.KeyFile=\"" . self::DIR . "/panel.key\"\n"
            . "        queue.type=\"LinkedList\" queue.filename=\"sentora_elk\" queue.maxDiskSpace=\"" . self::QUEUE . "\" queue.saveOnShutdown=\"on\"\n"
            . "        action.resumeRetryCount=\"-1\" action.resumeInterval=\"30\")\n}\n\n";
        $rules = array();
        if (in_array('mail', $plan['streams'], true)) {
            $rules[] = "if (\$programname startswith \"postfix\" or \$programname == \"spamd\" or \$programname startswith \"spamass\") then {\n    set \$.eklog = \"mail\"; set \$.ekacct = \"\"; set \$.ekdomain = \"\"; set \$.ekmsg = \$syslogtag & \$msg; call sentora_elk_out\n}";
        }
        if (in_array('logins', $plan['streams'], true)) {
            $rules[] = "if (\$programname == \"sshd\" or \$programname == \"sshd-session\" or \$programname == \"sudo\") then {\n    set \$.eklog = \"auth\"; set \$.ekacct = \"\"; set \$.ekdomain = \"\"; set \$.ekmsg = \$syslogtag & \$msg; call sentora_elk_out\n}";
        }
        return array('head' => $head, 'tail' => $rules ? "\n# The Elk node: lines rsyslog already receives (the journal), by program, sent with their tag\n" . implode("\n", $rules) . "\n" : '');
    }

    /**
     * Restart rsyslog (its settings are read at start).
     *
     * @return void
     */
    static function Restart()
    {
        exec(self::Own('systemctl enable rsyslog > /dev/null 2>&1; systemctl restart rsyslog > /dev/null 2>&1 || service rsyslog restart > /dev/null 2>&1'));
    }

    /**
     * Let rsyslog (when it is not root) read exactly the files it sends: a read ACL on each, passage through their
     * closed folders; taken away again from files no longer sent.
     *
     * @param string[] $paths
     * @param string[] $before
     * @return string[] The files that have it now.
     */
    static function Grant(array $paths, array $before)
    {
        $user = self::RsyslogUser();
        if ($user === 'root' || !is_executable('/usr/bin/setfacl') && !is_executable('/bin/setfacl')) {
            return $user === 'root' ? array() : $before;
        }
        $now = array();
        foreach ($paths as $p) {
            if (!is_file($p) || is_link($p)) {
                continue;
            }
            foreach (array(dirname(dirname($p)), dirname($p)) as $dir) {
                if (is_dir($dir) && !is_link($dir) && (fileperms($dir) & 0001) === 0) {
                    exec('setfacl -m ' . escapeshellarg('u:' . $user . ':x') . ' ' . escapeshellarg($dir) . ' 2>/dev/null');
                }
            }
            if ((fileperms($p) & 0004) === 0) {
                exec('setfacl -m ' . escapeshellarg('u:' . $user . ':r') . ' ' . escapeshellarg($p) . ' 2>/dev/null');
            }
            $now[] = $p;
        }
        foreach (array_diff($before, $now) as $p) {
            if (is_file($p) && !is_link($p)) {
                exec('setfacl -x ' . escapeshellarg('u:' . $user) . ' ' . escapeshellarg($p) . ' 2>/dev/null');
            }
        }
        return $now;
    }

    /**
     * Root, every daemon run, before Log Forwarder writes rsyslog's settings: the panel's certificate, the node paired when
     * one was added on the page, rsyslog installed when the node is to get lines.
     *
     * @return string[] What it did, for the daemon log.
     */
    static function Prepare()
    {
        $say = array();
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return $say;
        }
        foreach (self::Setup() as $l) {
            $say[] = $l;
        }
        // the separate file of the Elk add-on's first version: its lines are in Log Forwarder's file now
        if (is_file(self::CONF)) {
            @unlink(self::CONF);
        }
        $n = self::Node();
        if ($n['addr'] !== '' && !self::Paired()) {
            $say[] = self::Pair();
        } elseif (self::Paired() && @file_get_contents(self::DIR . '/node-ca.crt') !== $n['ca']) {
            self::WriteFile(self::DIR . '/node-ca.crt', $n['ca'], 0644, self::RsyslogGroup());
        }
        $old = self::State();
        if (self::Paired() && self::Chosen() && self::Missing() && time() - (int) ($old['install_tried'] ?? 0) > 3600) {
            $old['install_tried'] = time();
            self::WriteFile(self::STATE_DIR . '/status.json', json_encode($old, JSON_UNESCAPED_SLASHES), 0640, 'sentora');
            $say[] = self::InstallMissing();
        }
        return $say;
    }

    /**
     * Root, every daemon run, after Log Forwarder wrote rsyslog's settings: rsyslog may read the Elk node's files, and
     * the state for the page (the queue on disk, a check of the connection every 10 minutes or when asked).
     *
     * @param string $rsyslogSaid What Log Forwarder's installing of the settings said ('' when unchanged).
     * @return string[] What it did, for the daemon log.
     */
    static function After($rsyslogSaid = '')
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return array();
        }
        $old = self::State();
        $plan = self::Plan();
        $files = $plan ? $plan['files'] : array();
        $st = array('time' => time(), 'install_tried' => (int) ($old['install_tried'] ?? 0));
        $st['acl'] = self::Grant(array_keys($files), isset($old['acl']) && is_array($old['acl']) ? $old['acl'] : array());
        $st['files'] = count($files);
        $st['kinds'] = array_values(array_unique(array_map(function ($x) { return $x[0]; }, $files)));
        $st['missing'] = self::Missing();
        if (strpos($rsyslogSaid, 'refused') !== false) {
            $st['error'] = $rsyslogSaid;
        } elseif ($rsyslogSaid === '' && !empty($old['error'])) {
            $st['error'] = $old['error'];
        }
        $qb = 0;
        foreach ((array) glob('/var/spool/rsyslog/sentora_elk*') as $qf) {
            $qb += (int) @filesize($qf);
        }
        $st['queued'] = $qb;
        if (self::Paired()) {
            $n = self::Node();
            $fresh = isset($old['probe']) && time() - (int) $old['probe'] < 600 && (int) self::Setting(self::S_CHECK) < (int) $old['probe'];
            if ($fresh) {
                $st['probe'] = $old['probe'];
                $st['reachable'] = $old['reachable'];
                $st['probetext'] = $old['probetext'];
            } else {
                $c = self::Connect($n['addr'], true);
                $st['probe'] = time();
                $st['reachable'] = $c['ok'];
                $st['probetext'] = $c['text'];
            }
        }
        // the list of websites for the node's uptime checks
        list($st['sites_hash'], $st['sites_sent']) = self::SendSites($old);
        self::WriteFile(self::STATE_DIR . '/status.json', json_encode($st, JSON_UNESCAPED_SLASHES), 0640, 'sentora');
        $n = self::Node();
        return array($plan ? ('sending ' . count($files) . ' log file(s)' . ($plan['streams'] ? ' and the mail and login lines' : '') . ' to ' . $n['addr'] . '.')
            : (self::Paired() ? 'paired; nothing chosen to send.' : ($n['addr'] !== '' ? 'waiting to pair with ' . $n['addr'] . '.' : 'no Elk node.')));
    }

    /**
     * The websites whose older access logs can be sent (name => account), for the page.
     *
     * @return array<string, string>
     */
    static function Sites()
    {
        global $zdbh;
        $out = array();
        $q = $zdbh->query("SELECT LOWER(v.vh_name_vc), a.ac_user_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
            WHERE v.vh_deleted_ts IS NULL AND a.ac_deleted_ts IS NULL AND v.vh_type_in IN (1, 2) ORDER BY v.vh_name_vc");
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', (string) $r[0]) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $r[1])) {
                $out[(string) $r[0]] = (string) $r[1];
            }
        }
        return $out;
    }

    /**
     * The list of websites the node checks from outside (uptime and certificates): every website, and the panel's own
     * address; https where the website has its own certificate.
     *
     * @return array<int, array{host: string, https: bool}>
     */
    static function SiteList()
    {
        global $zdbh;
        $out = array();
        $q = $zdbh->query("SELECT LOWER(vh_name_vc), vh_ssl_tx FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_type_in IN (1, 2) ORDER BY vh_name_vc");
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', (string) $r[0]) && strpos((string) $r[0], '.') !== false) {
                $out[(string) $r[0]] = array('host' => (string) $r[0], 'https' => stripos((string) $r[1], 'SSL') !== false);
            }
        }
        $panel = strtolower(preg_replace('/[^A-Za-z0-9.-]/', '', (string) ctrl_options::GetSystemOption('sentora_domain')));
        if ($panel !== '' && strpos($panel, '.') !== false) {
            $out[$panel] = array('host' => $panel, 'https' => true);
        }
        return array_values($out);
    }

    /**
     * Root: send the list of websites to the node (one line, kind "sites": the node keeps it for its uptime checks),
     * when it changed or once an hour.
     *
     * @param array $old The last state.
     * @return array{0: string, 1: int} The list's hash and when it was sent (unchanged when nothing was sent).
     */
    static function SendSites(array $old)
    {
        $list = self::SiteList();
        $hash = hash('sha256', json_encode($list));
        $prevHash = (string) ($old['sites_hash'] ?? '');
        $prevTime = (int) ($old['sites_sent'] ?? 0);
        if (!self::Paired() || ($hash === $prevHash && time() - $prevTime < 3600)) {
            return array($prevHash, $prevTime);
        }
        $node = self::Node();
        $ctx = stream_context_create(array('ssl' => array('local_cert' => self::DIR . '/panel.crt', 'local_pk' => self::DIR . '/panel.key',
            'cafile' => self::DIR . '/node-ca.crt', 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => self::NODE_NAME, 'allow_self_signed' => false)));
        $host = filter_var($node['addr'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $node['addr'] . ']' : $node['addr'];
        $c = @stream_socket_client('tls://' . $host . ':' . self::PORT, $no, $str, 10, STREAM_CLIENT_CONNECT, $ctx);
        if ($c === false) {
            return array($prevHash, $prevTime);
        }
        $panel = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ctrl_options::GetSystemOption('sentora_domain'));
        @fwrite($c, json_encode(array('@timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'sentora_host' => $panel, 'sentora_log' => 'sites',
            'message' => json_encode($list)), JSON_UNESCAPED_SLASHES) . "\n");
        fclose($c);
        return array($hash, time());
    }

    /**
     * The imports done (site => what happened), from the marks the import leaves.
     *
     * @return array<string, string>
     */
    static function Imported()
    {
        $out = array();
        foreach ((array) glob(self::STATE_DIR . '/imported-*') as $f) {
            $out[substr(basename($f), 9)] = trim((string) @file_get_contents($f));
        }
        return $out;
    }

    /**
     * The page: ask the daemon to send a website's older access logs (one request per site at a time).
     *
     * @param string $site
     * @param int $days 0: all the panel keeps.
     * @return string '' or what is wrong.
     */
    static function QueueImport($site, $days)
    {
        $site = strtolower(trim((string) $site));
        if (!isset(self::Sites()[$site])) {
            return 'Choose one of this panel\'s websites.';
        }
        if (isset(self::Imported()[$site])) {
            return 'That website\'s older logs were sent before.';
        }
        $q = json_decode(self::Setting(self::S_IMPORT), true);
        $q = is_array($q) ? $q : array();
        $q[$site] = array('days' => max(0, min(365, (int) $days)), 'asked' => time());
        self::Save(self::S_IMPORT, json_encode($q));
        return '';
    }

    /**
     * The imports asked for on the page and not done yet (site => days).
     *
     * @return array<string, int>
     */
    static function Queued()
    {
        $q = json_decode(self::Setting(self::S_IMPORT), true);
        $out = array();
        foreach (is_array($q) ? $q : array() as $site => $x) {
            $out[(string) $site] = (int) (is_array($x) ? ($x['days'] ?? 0) : 0);
        }
        return $out;
    }

    /**
     * Root: send what the panel still keeps of one website's access log (the current file and the rotated ones, about
     * three weeks) to the Elk node, each visit at its own date. Lines from after the node was paired (already sent
     * live) and older than $days are left out; a site imported once is not again unless $again. Used by the daemon (the
     * page's "Bring in older logs") and by elk-node/sentora-elk-import.
     *
     * @param string $site
     * @param int $days 0: all.
     * @param bool $again
     * @param bool $dry Count only.
     * @param callable|null $say Progress lines.
     * @return array{ok: bool, text: string, sent: int}
     */
    static function Import($site, $days = 0, $again = false, $dry = false, $say = null)
    {
        $say = $say ?: function ($l) {};
        $fail = function ($t) { return array('ok' => false, 'text' => $t, 'sent' => 0); };
        $site = strtolower((string) $site);
        $sites = self::Sites();
        if (!isset($sites[$site])) {
            return $fail("No website $site on this panel.");
        }
        if (!self::Paired()) {
            return $fail('No Elk node is paired yet.');
        }
        $mark = self::STATE_DIR . '/imported-' . $site;
        if (is_file($mark) && !$again && !$dry) {
            return $fail("$site was imported before (" . trim((string) file_get_contents($mark)) . ').');
        }
        $node = self::Node();
        $live = preg_match('/^ok (\d+)/', $node['pairing'], $m) ? (int) $m[1] : time();
        $from = $days > 0 ? time() - $days * 86400 : 0;
        $user = $sites[$site];
        $logs = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/');
        $base = ($logs !== '' ? $logs : '/var/sentora/logs') . '/domains/' . $user . '/' . $site . '-access.log';
        $files = array();
        foreach (array_merge(array($base), (array) glob($base . '.*')) as $f) {
            if (is_file($f) && !is_link($f) && preg_match('/-access\.log(\.\d+(\.gz)?)?$/', $f)) {
                $files[] = $f;
            }
        }
        if (!$files) {
            return $fail("$site has no access log on this panel.");
        }
        usort($files, function ($a, $b) {
            $n = function ($f) { return preg_match('/\.log\.(\d+)/', $f, $m) ? (int) $m[1] : 0; };
            return $n($b) <=> $n($a);
        });
        $conn = null;
        if (!$dry) {
            $ctx = stream_context_create(array('ssl' => array('local_cert' => self::DIR . '/panel.crt', 'local_pk' => self::DIR . '/panel.key',
                'cafile' => self::DIR . '/node-ca.crt', 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => self::NODE_NAME, 'allow_self_signed' => false)));
            $host = filter_var($node['addr'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $node['addr'] . ']' : $node['addr'];
            $conn = @stream_socket_client('tls://' . $host . ':' . self::PORT, $no, $str, 15, STREAM_CLIENT_CONNECT, $ctx);
            if ($conn === false) {
                return $fail('The Elk node at ' . $node['addr'] . ' did not answer on port ' . self::PORT . " ($str).");
            }
        }
        $panel = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ctrl_options::GetSystemOption('sentora_domain'));
        $sent = $skipped = 0;
        $first = $last = null;
        foreach ($files as $f) {
            $gz = substr($f, -3) === '.gz';
            $h = $gz ? @gzopen($f, 'rb') : @fopen($f, 'rb');
            if ($h === false) {
                $say('could not read ' . basename($f));
                continue;
            }
            $n = 0;
            while (($line = ($gz ? gzgets($h, 65536) : fgets($h, 65536))) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || !preg_match('/\[(\d{2}\/\w{3}\/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\]/', $line, $mm)) {
                    continue;
                }
                $t = DateTime::createFromFormat('d/M/Y:H:i:s O', $mm[1]);
                if ($t === false) {
                    continue;
                }
                $ts = $t->getTimestamp();
                if ($ts >= $live || $ts < $from) {
                    $skipped++;
                    continue;
                }
                $first = $first === null ? $ts : min($first, $ts);
                $last = $last === null ? $ts : max($last, $ts);
                $n++;
                $sent++;
                if ($conn && @fwrite($conn, json_encode(array('@timestamp' => gmdate('Y-m-d\TH:i:s\Z', $ts), 'sentora_host' => $panel, 'sentora_log' => 'web-access',
                        'sentora_account' => $user, 'sentora_domain' => $site, 'message' => mb_substr($line, 0, 32768)), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n") === false) {
                    $gz ? gzclose($h) : fclose($h);
                    return $fail("The connection to the Elk node broke after $sent lines.");
                }
                if ($sent % 50000 === 0) {
                    $say("... $sent lines");
                }
            }
            $gz ? gzclose($h) : fclose($h);
            $say(basename($f) . ": $n line(s)");
        }
        if ($conn) {
            // (a moment for the node to read what was sent before the connection closes)
            sleep(3);
            fclose($conn);
        }
        $range = $first === null ? 'nothing older than when the node started' : (date('Y-m-d H:i', $first) . ' to ' . date('Y-m-d H:i', $last));
        if (!$dry) {
            self::WriteFile($mark, date('Y-m-d H:i') . ": $sent visits, $range\n", 0640, 'sentora');
        }
        return array('ok' => true, 'sent' => $sent, 'text' => ($dry ? 'Would send ' : 'Sent ') . "$sent visit(s) of $site ($range); $skipped left out (already sent live, or outside the days asked).");
    }

    /**
     * Root, every daemon run: the imports asked for on the page (each once; the request is taken first, so a
     * failure is not repeated every run: the page says what happened).
     *
     * @return string[]
     */
    static function RunImports()
    {
        $out = array();
        $queued = self::Queued();
        if (!$queued || !self::Paired()) {
            return $out;
        }
        self::Save(self::S_IMPORT, '');
        foreach ($queued as $site => $days) {
            $r = self::Import($site, $days);
            if (!$r['ok']) {
                self::WriteFile(self::STATE_DIR . '/import-failed-' . $site, date('Y-m-d H:i') . ': ' . $r['text'] . "\n", 0640, 'sentora');
            } else {
                @unlink(self::STATE_DIR . '/import-failed-' . $site);
            }
            $out[] = 'older logs: ' . $r['text'];
        }
        return $out;
    }

    /**
     * Imports that could not be done (site => why), for the page.
     *
     * @return array<string, string>
     */
    static function ImportFailed()
    {
        $out = array();
        foreach ((array) glob(self::STATE_DIR . '/import-failed-*') as $f) {
            $out[substr(basename($f), 14)] = trim((string) @file_get_contents($f));
        }
        return $out;
    }

    /**
     * Root, when the module is removed or switched off: the ACLs and the AppArmor rule file go (Log Forwarder takes its
     * rsyslog settings away, the Elk node's part with them).
     * The panel's certificate and the node stay (a reinstall keeps them).
     *
     * @return void
     */
    static function Remove()
    {
        // the alerts' notices go with it
        $al = json_decode((string) @file_get_contents(self::STATE_DIR . '/alerts.json'), true);
        foreach (is_array($al) && isset($al['sent']) && is_array($al['sent']) ? array_keys($al['sent']) : array() as $id) {
            if (class_exists('ctrl_notices')) {
                ctrl_notices::Withdraw('elk-alert-' . md5($id), 0);
            }
        }
        @unlink(self::STATE_DIR . '/alerts.json');
        $st = self::State();
        $user = self::RsyslogUser();
        foreach ((array) (isset($st['acl']) ? $st['acl'] : array()) as $p) {
            if ($user !== 'root' && is_file($p) && !is_link($p)) {
                exec('setfacl -x ' . escapeshellarg('u:' . $user) . ' ' . escapeshellarg($p) . ' 2>/dev/null');
            }
        }
        if (is_file(self::CONF)) {
            @unlink(self::CONF);
            self::Restart();
        }
        if (is_file(self::AA_SNIPPET)) {
            @unlink(self::AA_SNIPPET);
            exec('apparmor_parser -r ' . escapeshellarg(self::AA_PROFILE) . ' > /dev/null 2>&1');
        }
    }
}
