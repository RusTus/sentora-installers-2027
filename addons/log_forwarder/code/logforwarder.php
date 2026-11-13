<?php

/**
 * Log Forwarder: sends the logs an account chooses, live, to its own log server (ELK, OpenSearch,
 * Grafana Loki through Alloy, Graylog...) as standard syslog (RFC 5424) over TLS, through this
 * server's own rsyslog.
 *
 * - The panel keeps the destinations (x_logforward): a host, a port, an optional CA certificate
 *   for a receiver with a certificate of its own making, and which logs.
 * - The daemon (root, every run) turns them into rsyslog's settings (CONF): rsyslog follows each
 *   chosen log file and sends every new line within about a second, keeping lines in a size-capped
 *   queue on disk while the receiver is away. A new configuration is checked by rsyslog before it
 *   is used; one that rsyslog refuses is never left in place.
 * - Every system Sentora supports (202): rsyslog and its TLS support are installed with the system's
 *   own package command; the TLS driver, the trusted CA file and the mail and login logs' names are
 *   found on the server. Where rsyslog runs as its own user (syslog on Ubuntu) the module lets it read
 *   the forwarded files only (file ACLs, one by one); where it runs under AppArmor it adds a rule file
 *   to its profile; under SELinux rsyslog may already read every log, and a destination on a port
 *   other than syslog's gets that port labelled for syslog over TLS (semanage; a port SELinux keeps
 *   for another service is refused, with the reason on the page). The page learns what the server
 *   has from the daemon (root), since the panel itself may not look under SELinux.
 * - What a client account sends counts towards its package's monthly bandwidth; over the limit its
 *   forwarding pauses until the next month (or a bigger package). Administrators' server logs
 *   (mail, panel logins...) belong to no account and are not counted.
 * - A client's destination must be on the internet: never this server or a private or internal
 *   address (checked when saved and on every run; rsyslog is given the checked address, so a
 *   changed DNS answer can not point it inwards). Administrators may use internal addresses.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09; every supported system 2026-11-12
 */
class log_forwarder
{
    const CONF = '/etc/rsyslog.d/60-sentora-logforward.conf';
    const CA_DIR = '/etc/sentora/logforward';
    const STATE_DIR = '/var/lib/sentora/logforward';
    const AA_SNIPPET = '/etc/apparmor.d/rsyslog.d/sentora-logforward';
    const AA_PROFILE = '/etc/apparmor.d/usr.sbin.rsyslogd';
    /** Destinations per account. */
    const MAX_DEST = 3;
    /** The queue on disk while a receiver is away: a client's, an administrator's. */
    const QUEUE_CLIENT = '100m';
    const QUEUE_ADMIN = '1g';
    /** The structured-data ID of the lines (the documentation enterprise number, RFC 5612). */
    const SDID = 'sentora@32473';

    // ---------------------------------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------------------------------

    /**
     * The destinations table, made when missing.
     *
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if (!$done) {
            $zdbh->exec("CREATE TABLE IF NOT EXISTS x_logforward (lf_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, lf_acc_fk INT NOT NULL,
                lf_name_vc VARCHAR(60) NOT NULL DEFAULT '', lf_host_vc VARCHAR(253) NOT NULL DEFAULT '', lf_port_in INT NOT NULL DEFAULT 6514,
                lf_framing_vc VARCHAR(10) NOT NULL DEFAULT 'octet', lf_ca_tx TEXT NULL, lf_logs_tx TEXT NULL, lf_enabled_in TINYINT NOT NULL DEFAULT 1,
                lf_created_ts INT NULL, lf_updated_ts INT NULL,
                KEY lf_acc (lf_acc_fk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $done = true;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // This system (found on the server, so the module works on every system Sentora supports)
    // ---------------------------------------------------------------------------------------------

    /**
     * A command that runs with only its own input and output open (the core's ctrl_system::OwnFiles,
     * when this panel has it; panels before 2027.81 run it as it is).
     *
     * @param string $cmd An escaped shell command.
     * @return string
     */
    static function Own($cmd)
    {
        return method_exists('ctrl_system', 'OwnFiles') ? ctrl_system::OwnFiles($cmd) : $cmd;
    }

    /**
     * rsyslog's program ('' when it is not installed).
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
     * rsyslog's TLS driver ('' when missing): its module folder differs between systems.
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
     * The system's file of trusted CA certificates (for destinations without a CA of their own).
     *
     * @return string
     */
    static function SystemCa()
    {
        $loc = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : array();
        $cands = array(isset($loc['default_cert_file']) ? (string) $loc['default_cert_file'] : '',
            '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem');
        foreach ($cands as $f) {
            $r = $f !== '' ? realpath($f) : false;
            if ($r !== false && is_file($r) && filesize($r) > 0) {
                return $r;
            }
        }
        return '/etc/ssl/certs/ca-certificates.crt';
    }

    /**
     * The first of a system log's names that exists here (mail.log or maillog, auth.log or secure).
     *
     * @param string[] $names Full paths, in order.
     * @return string '' when none exists.
     */
    static function FirstLog(array $names)
    {
        foreach ($names as $f) {
            if (is_file($f) && !is_link($f)) {
                return $f;
            }
        }
        return '';
    }

    /**
     * The packages the module needs that are missing (the same names on every supported system).
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
     * Root: install what is missing with this system's package command (Sentora's setting
     * package_install, set by each system's installer), then let rsyslog start with the system.
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
        exec(self::Own('DEBIAN_FRONTEND=noninteractive ' . $cmd . ' ' . implode(' ', array_map('escapeshellarg', $need)) . ' > /dev/null 2>&1'), $o, $rc);
        if (in_array('rsyslog', $need, true) && self::RsyslogBin() !== '') {
            exec(self::Own('systemctl enable --now rsyslog > /dev/null 2>&1'));
        }
        $left = self::Missing();
        return $left ? 'could not install ' . implode(', ', $left) . ': forwarding waits for them.' : 'installed ' . implode(', ', $need) . '.';
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
     * Is SELinux on (enforcing or permissive)?
     *
     * @return bool
     */
    static function SelinuxOn()
    {
        if (!is_executable('/usr/sbin/selinuxenabled')) {
            return false;
        }
        exec('/usr/sbin/selinuxenabled', $o, $rc);
        return $rc === 0;
    }

    /**
     * Root, SELinux only: let rsyslog connect to each destination's port. SELinux lets rsyslog
     * connect to syslog's own ports (514, 6514) and a few others; a destination on another free port
     * gets that port labelled as a syslog-over-TLS port (semanage). A port SELinux keeps for another
     * service is left alone: that destination says so. Labels the module added and no destination
     * uses any more are taken away again.
     *
     * @param int[] $ports The active destinations' ports.
     * @param int[] $added The ports the module labelled before.
     * @return array{0: int[], 1: array<int, string>} The ports labelled now, and port => why it can not be used.
     */
    static function SelinuxPorts(array $ports, array $added)
    {
        $ports = array_values(array_unique(array_map('intval', $ports)));
        if (!self::SelinuxOn() || !is_executable('/usr/sbin/semanage')) {
            return array(array(), array());
        }
        $out = array();
        exec(self::Own('/usr/sbin/semanage port -l -C 2> /dev/null; /usr/sbin/semanage port -l 2> /dev/null'), $out);
        $label = array();   // port => type (single ports only; ranges are the general ones)
        foreach ($out as $l) {
            if (preg_match('/^(\S+)\s+tcp\s+(.+)$/', trim($l), $m)) {
                foreach (preg_split('/,\s*/', $m[2]) as $p) {
                    if (ctype_digit($p) && !isset($label[(int) $p])) {
                        $label[(int) $p] = $m[1];
                    }
                }
            }
        }
        $may = array('syslog_tls_port_t', 'syslogd_port_t', 'http_port_t', 'mysqld_port_t', 'postgresql_port_t', 'rsh_port_t', 'wap_wsp_port_t');
        $now = array();
        $bad = array();
        foreach ($ports as $p) {
            $t = isset($label[$p]) ? $label[$p] : '';
            if ($t === '' || (in_array($p, $added, true) && $t === 'syslog_tls_port_t')) {
                if ($t === '') {
                    exec(self::Own('/usr/sbin/semanage port -a -t syslog_tls_port_t -p tcp ' . (int) $p . ' 2>&1'), $o, $rc);
                    if ($rc !== 0) {
                        $bad[$p] = 'SELinux did not let port ' . $p . ' be used for syslog: ' . substr(implode(' ', $o), 0, 160);
                        continue;
                    }
                }
                $now[] = $p;
            } elseif (!in_array($t, $may, true)) {
                $bad[$p] = 'SELinux keeps port ' . $p . ' for another service (' . $t . '): use another port on the receiver (6514 is syslog over TLS).';
            }
        }
        foreach (array_diff($added, $now, $ports) as $p) {
            exec(self::Own('/usr/sbin/semanage port -d -t syslog_tls_port_t -p tcp ' . (int) $p . ' > /dev/null 2>&1'));
        }
        return array(array_values(array_unique(array_merge($now, array_intersect($added, $ports)))), $bad);
    }

    /**
     * An account's destinations (or all of them: $uid null).
     *
     * @param int|null $uid The account, or null for every account.
     * @return array<int, array{id:int, acc:int, name:string, host:string, port:int, ca:string, framing:string, logs:string[], enabled:bool}>
     */
    static function Destinations($uid = null)
    {
        global $zdbh;
        self::Ensure();
        $q = $uid === null ? $zdbh->query("SELECT * FROM x_logforward ORDER BY lf_id_pk")
            : $zdbh->prepare("SELECT * FROM x_logforward WHERE lf_acc_fk = :a ORDER BY lf_id_pk");
        if ($uid !== null) {
            $q->execute(array(':a' => (int) $uid));
        }
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $logs = json_decode((string) $r['lf_logs_tx'], true);
            $out[(int) $r['lf_id_pk']] = array('id' => (int) $r['lf_id_pk'], 'acc' => (int) $r['lf_acc_fk'], 'name' => (string) $r['lf_name_vc'],
                'host' => (string) $r['lf_host_vc'], 'port' => (int) $r['lf_port_in'], 'ca' => (string) $r['lf_ca_tx'],
                'framing' => $r['lf_framing_vc'] === 'line' ? 'line' : 'octet',
                'logs' => is_array($logs) ? array_values(array_filter($logs, 'is_string')) : array(), 'enabled' => (int) $r['lf_enabled_in'] === 1);
        }
        return $out;
    }

    /**
     * Is an account an administrator's (group 1)?
     *
     * @param int $uid The account.
     * @return bool
     */
    static function IsAdmin($uid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_group_fk FROM x_accounts WHERE ac_id_pk = :a AND ac_deleted_ts IS NULL");
        $q->execute(array(':a' => (int) $uid));
        return (int) $q->fetchColumn() === 1;
    }

    /**
     * The logs an account may forward: its own domains' access and error logs, and for an
     * administrator the server's logs too.
     *
     * @param int $uid The account.
     * @return array<string, array{label:string, group:string, path:string, account:string, domain:string, type:string, counted:bool}>
     */
    static function Sources($uid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_user_vc FROM x_accounts WHERE ac_id_pk = :a AND ac_deleted_ts IS NULL");
        $q->execute(array(':a' => (int) $uid));
        $user = (string) $q->fetchColumn();
        $out = array();
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $user)) {
            return $out;
        }
        $logDir = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/domains/' . $user . '/';
        $q = $zdbh->prepare("SELECT vh_id_pk, vh_name_vc FROM x_vhosts WHERE vh_acc_fk = :a AND vh_deleted_ts IS NULL AND vh_type_in IN (1, 2) ORDER BY vh_name_vc");
        $q->execute(array(':a' => (int) $uid));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $name = (string) $v['vh_name_vc'];
            if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $name)) {
                continue;
            }
            foreach (array('access' => 'visits', 'error' => 'errors') as $type => $words) {
                $out[$type . ':' . (int) $v['vh_id_pk']] = array('label' => $name, 'group' => $type, 'path' => $logDir . $name . '-' . $type . '.log',
                    'account' => $user, 'domain' => $name, 'type' => $type, 'counted' => true);
            }
        }
        if (self::IsAdmin($uid)) {
            $panel = (string) ctrl_options::GetSystemOption('sentora_domain');
            $logs = rtrim((string) ctrl_options::GetSystemOption('log_dir'), '/') . '/';
            foreach (array(
                'mail' => array('Mail (Postfix, Dovecot)', self::FirstLog(array('/var/log/mail.log', '/var/log/maillog'))),
                'auth' => array('Server logins (SSH, sudo)', self::FirstLog(array('/var/log/auth.log', '/var/log/secure'))),
                'fail2ban' => array('fail2ban (blocked addresses)', '/var/log/fail2ban.log'),
                'panel-logins' => array('Panel logins', '/var/lib/sentora/sentora-auth.log'),
                'panel-access' => array('Panel requests', $logs . 'sentora-access.log'),
                'panel-error' => array('Panel errors', $logs . 'sentora-error.log'),
            ) as $k => $s) {
                if ($s[1] === '') {
                    continue;   // (this system keeps that log in the journal only)
                }
                $out['server:' . $k] = array('label' => $s[0], 'group' => 'server', 'path' => $s[1], 'account' => '', 'domain' => $panel, 'type' => $k, 'counted' => false);
            }
        }
        return $out;
    }

    /**
     * Check and save a destination (new: $id 0). Returns '' when saved, else what is wrong.
     *
     * @param int $uid The account.
     * @param int $id The destination (0: a new one).
     * @param array $f name, host, port, framing ('octet' or 'line'), ca, logs (string[]).
     * @param int|null $newId Set to the saved destination's ID.
     * @return string '' or an error message (English, translated by the caller).
     */
    static function Save($uid, $id, array $f, &$newId = null)
    {
        global $zdbh;
        self::Ensure();
        $mine = self::Destinations($uid);
        if ($id && !isset($mine[$id])) {
            return 'That destination does not exist.';
        }
        if (!$id && count($mine) >= self::MAX_DEST) {
            return 'You already have the most destinations an account may have (3).';
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($f['name'] ?? '')));
        $host = strtolower(trim((string) ($f['host'] ?? '')));
        $port = (int) ($f['port'] ?? 0);
        $ca = trim(str_replace("\r", '', (string) ($f['ca'] ?? '')));
        $framing = (($f['framing'] ?? '') === 'line') ? 'line' : 'octet';
        $logs = array_values(array_unique(array_filter((array) ($f['logs'] ?? array()), 'is_string')));
        if ($name === '' || mb_strlen($name) > 60 || preg_match('/[\x00-\x1f<>"]/u', $name)) {
            return 'Give the destination a name (up to 60 characters).';
        }
        if (!self::ValidHost($host)) {
            return 'The server is not a valid host name or IP address.';
        }
        if ($port < 1 || $port > 65535) {
            return 'The port is not valid (1 to 65535; syslog over TLS is usually 6514).';
        }
        if ($ca !== '' && self::CheckCa($ca) === false) {
            return 'The CA certificate is not a PEM certificate (-----BEGIN CERTIFICATE----- ...).';
        }
        $sources = self::Sources($uid);
        $logs = array_values(array_filter($logs, function ($k) use ($sources) { return isset($sources[$k]); }));
        if (!$logs) {
            return 'Choose at least one log to send.';
        }
        if (!self::IsAdmin($uid)) {
            $why = self::PublicOnly($host);
            if ($why !== '') {
                return $why;
            }
        }
        if ($id) {
            $zdbh->prepare("UPDATE x_logforward SET lf_name_vc = :n, lf_host_vc = :h, lf_port_in = :p, lf_framing_vc = :f, lf_ca_tx = :c, lf_logs_tx = :l, lf_updated_ts = :t WHERE lf_id_pk = :id AND lf_acc_fk = :a")
                ->execute(array(':n' => $name, ':h' => $host, ':p' => $port, ':f' => $framing, ':c' => $ca, ':l' => json_encode($logs), ':t' => time(), ':id' => (int) $id, ':a' => (int) $uid));
            $newId = (int) $id;
        } else {
            $zdbh->prepare("INSERT INTO x_logforward (lf_acc_fk, lf_name_vc, lf_host_vc, lf_port_in, lf_framing_vc, lf_ca_tx, lf_logs_tx, lf_enabled_in, lf_created_ts, lf_updated_ts) VALUES (:a, :n, :h, :p, :f, :c, :l, 1, :t, :t)")
                ->execute(array(':a' => (int) $uid, ':n' => $name, ':h' => $host, ':p' => $port, ':f' => $framing, ':c' => $ca, ':l' => json_encode($logs), ':t' => time()));
            $newId = (int) $zdbh->lastInsertId();
        }
        return '';
    }

    /**
     * Switch a destination on or off, or delete it (only the account's own).
     *
     * @param int $uid The account.
     * @param int $id The destination.
     * @param string $what 'on', 'off' or 'delete'.
     * @return bool
     */
    static function Change($uid, $id, $what)
    {
        global $zdbh;
        self::Ensure();
        if ($what === 'delete') {
            $q = $zdbh->prepare("DELETE FROM x_logforward WHERE lf_id_pk = :id AND lf_acc_fk = :a");
        } else {
            $q = $zdbh->prepare("UPDATE x_logforward SET lf_enabled_in = " . ($what === 'on' ? 1 : 0) . ", lf_updated_ts = " . time() . " WHERE lf_id_pk = :id AND lf_acc_fk = :a");
        }
        $q->execute(array(':id' => (int) $id, ':a' => (int) $uid));
        return $q->rowCount() > 0;
    }

    /**
     * A host name or IP address?
     *
     * @param string $host
     * @return bool
     */
    static function ValidHost($host)
    {
        return strlen($host) <= 253 && (filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1);
    }

    /**
     * The certificates in a pasted CA (one or more PEM blocks), cleaned; false when one is not valid.
     *
     * @param string $pem
     * @return string|false
     */
    static function CheckCa($pem)
    {
        if (strlen($pem) > 65536 || !preg_match_all('/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $pem, $m) || !$m[0]) {
            return false;
        }
        foreach ($m[0] as $c) {
            if (@openssl_x509_read($c) === false) {
                return false;
            }
        }
        return implode("\n", $m[0]) . "\n";
    }

    /**
     * The addresses a host name stands for (an IP address: itself).
     *
     * @param string $host
     * @return string[]
     */
    static function Resolve($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return array($host);
        }
        // the system's own lookup (it reads /etc/hosts too, as rsyslog would), then DNS for IPv6
        $ips = (array) @gethostbynamel($host);
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
            if (isset($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * For a client's destination: every address of the host must be on the internet, and none of
     * this server's own. Returns '' when fine, else why not.
     *
     * @param string $host
     * @return string
     */
    static function PublicOnly($host)
    {
        $ips = self::Resolve($host);
        if (!$ips) {
            return 'The server\'s name does not resolve to an address.';
        }
        $own = preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null')));
        $own[] = (string) ctrl_options::GetSystemOption('server_ip');
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false || in_array($ip, $own, true)) {
                return 'The server must be on the internet: not this server, nor a private or internal address (' . $ip . ').';
            }
        }
        return '';
    }

    /**
     * Try a TLS connection to a destination, as rsyslog will: the receiver's certificate must be
     * trusted (the pasted CA, else the usual public ones) and name the host.
     *
     * @param array $d A destination (host, port, ca).
     * @param string|null $ip Connect to this checked address (clients), else the host.
     * @return array{ok:bool, text:string}
     */
    static function Test($d, $ip = null)
    {
        $caFile = null;
        if ($d['ca'] !== '') {
            $caFile = tempnam(sys_get_temp_dir(), 'lfca');
            file_put_contents($caFile, $d['ca']);
        }
        $ctx = stream_context_create(array('ssl' => array('peer_name' => $d['host'], 'SNI_enabled' => true, 'verify_peer' => true,
            'verify_peer_name' => true, 'allow_self_signed' => false, 'capture_peer_cert' => true,
        ) + ($caFile !== null ? array('cafile' => $caFile) : array())));
        $target = $ip !== null ? $ip : $d['host'];
        if (strpos($target, ':') !== false && filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $target = '[' . $target . ']';
        }
        $err = '';
        set_error_handler(function ($no, $str) use (&$err) { $err = $err === '' ? $str : $err; return true; });
        $fp = stream_socket_client('tls://' . $target . ':' . (int) $d['port'], $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        restore_error_handler();
        if ($caFile !== null) {
            @unlink($caFile);
        }
        if ($fp === false) {
            $why = $errstr !== '' ? $errstr : $err;
            if (stripos($err, 'certificate verify failed') !== false || stripos($err, 'peer certificate') !== false) {
                $why = 'its certificate is not trusted, or does not name ' . $d['host'] . ' (a receiver with a certificate of its own making: paste its CA certificate)';
            }
            return array('ok' => false, 'text' => 'Could not connect: ' . trim(preg_replace('/^stream_socket_client\(\):\s*/', '', $why)));
        }
        $p = stream_context_get_params($fp);
        fclose($fp);
        $c = isset($p['options']['ssl']['peer_certificate']) ? @openssl_x509_parse($p['options']['ssl']['peer_certificate']) : false;
        $who = is_array($c) && isset($c['issuer']['O']) ? (is_array($c['issuer']['O']) ? reset($c['issuer']['O']) : $c['issuer']['O'])
            : (is_array($c) && isset($c['issuer']['CN']) ? (is_array($c['issuer']['CN']) ? reset($c['issuer']['CN']) : $c['issuer']['CN']) : '');
        return array('ok' => true, 'text' => 'Connected over TLS; the certificate is trusted' . ($who !== '' ? ' (issued by ' . $who . ')' : '') . '.');
    }

    // ---------------------------------------------------------------------------------------------
    // Status (written by the daemon, read by the panel)
    // ---------------------------------------------------------------------------------------------

    /**
     * The state the daemon last left: per destination, and its own notes.
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
     * Root: set up what the module needs (folders, the AppArmor rule file, rsyslog's TLS driver
     * present). Install, upgrade and every daemon run call it; it changes only what is not right.
     *
     * @return string[] What it did.
     */
    static function Setup()
    {
        $did = array();
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return $did;
        }
        foreach (array(self::CA_DIR => array(0755, 'root'), self::STATE_DIR => array(0750, 'sentora')) as $dir => $m) {
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
        // rsyslog's AppArmor profile: let it read the logs it forwards and the CA certificates
        if (is_file(self::AA_PROFILE) && is_dir(dirname(self::AA_SNIPPET))) {
            $rules = "# Sentora Log Forwarder: the logs it forwards, and the CA certificates of its destinations\n"
                . "  /var/sentora/logs/ r,\n  /var/sentora/logs/** r,\n  /var/lib/sentora/*.log r,\n"
                . "  " . self::CA_DIR . "/ r,\n  " . self::CA_DIR . "/** r,\n"
                . "  /etc/ssl/certs/ r,\n  /etc/ssl/certs/** r,\n  /usr/share/ca-certificates/** r,\n";
            if (@file_get_contents(self::AA_SNIPPET) !== $rules) {
                if (is_link(self::AA_SNIPPET)) {
                    @unlink(self::AA_SNIPPET);
                }
                file_put_contents(self::AA_SNIPPET, $rules);
                @chmod(self::AA_SNIPPET, 0644);
                if (is_executable('/sbin/apparmor_parser') || is_executable('/usr/sbin/apparmor_parser')) {
                    exec('apparmor_parser -r ' . escapeshellarg(self::AA_PROFILE) . ' 2>&1', $o, $rc);
                    $did[] = $rc === 0 ? 'rsyslog may read the forwarded logs (AppArmor)' : 'AppArmor did not take the rule file: ' . implode(' ', array_slice($o, -2));
                }
            }
        }
        return $did;
    }

    /**
     * Root, every daemon run: bring rsyslog's settings in line with the destinations, let rsyslog
     * read exactly the forwarded files, count what clients sent towards their bandwidth, and note
     * each destination's state for the panel.
     *
     * @return string[] What it did, for the daemon log.
     */
    static function Apply()
    {
        global $zdbh;
        $say = array();
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return array('Skipped (needs root).');
        }
        foreach (self::Setup() as $l) {
            $say[] = $l;
        }
        $old = self::State();
        // what the module needs, installed when missing (at most once an hour while it fails)
        if (self::Missing() && time() - (int) (isset($old['install_tried']) ? $old['install_tried'] : 0) > 3600) {
            $old['install_tried'] = time();
            $say[] = self::InstallMissing();
        }
        $status = array('dest' => array(), 'files' => isset($old['files']) && is_array($old['files']) ? $old['files'] : array(),
            'month' => isset($old['month']) && is_array($old['month']) ? $old['month'] : array(), 'acl' => isset($old['acl']) && is_array($old['acl']) ? $old['acl'] : array());
        $month = date('Ym');
        $dests = self::Destinations();
        $files = array();   // path => array(source, dest ids)
        $active = array();  // id => array(dest, target, caFile, admin)
        foreach ($dests as $id => $d) {
            $st = array('state' => 'off', 'text' => '', 'checked' => time());
            $acct = $zdbh->prepare("SELECT ac_enabled_in, ac_group_fk FROM x_accounts WHERE ac_id_pk = :a AND ac_deleted_ts IS NULL");
            $acct->execute(array(':a' => $d['acc']));
            $a = $acct->fetch(PDO::FETCH_ASSOC);
            if (!$a) {
                // the account is gone: so is its destination
                $zdbh->prepare("DELETE FROM x_logforward WHERE lf_id_pk = :id")->execute(array(':id' => $id));
                continue;
            }
            $admin = (int) $a['ac_group_fk'] === 1;
            if (!$d['enabled']) {
                $st['text'] = 'Switched off.';
            } elseif ((int) $a['ac_enabled_in'] !== 1) {
                $st['state'] = 'paused';
                $st['text'] = 'The account is switched off.';
            } elseif (!$admin && self::OverBandwidth($d['acc'])) {
                $st['state'] = 'paused';
                $st['text'] = 'Paused: the account has used its bandwidth for this month. It starts again next month, or with a bigger package.';
            } else {
                $target = $d['host'];
                if (!$admin) {
                    $why = self::PublicOnly($d['host']);
                    if ($why !== '') {
                        $st['state'] = 'blocked';
                        $st['text'] = $why;
                        $status['dest'][$id] = $st;
                        continue;
                    }
                    $ips = self::Resolve($d['host']);
                    $target = $ips[0];
                }
                $sources = self::Sources($d['acc']);
                $n = 0;
                foreach ($d['logs'] as $k) {
                    if (isset($sources[$k])) {
                        $p = $sources[$k]['path'];
                        if (!isset($files[$p])) {
                            $files[$p] = array('src' => $sources[$k], 'dests' => array());
                        }
                        $files[$p]['dests'][] = $id;
                        $n++;
                    }
                }
                if (!$n) {
                    $st['text'] = 'None of its logs exist any more.';
                    $status['dest'][$id] = $st;
                    continue;
                }
                $caFile = self::SystemCa();
                if ($d['ca'] !== '' && ($pem = self::CheckCa($d['ca'])) !== false) {
                    $caFile = self::CA_DIR . '/dest-' . $id . '.pem';
                    if (@file_get_contents($caFile) !== $pem) {
                        self::WriteFile($caFile, $pem, 0644, self::CA_DIR);
                    }
                }
                $active[$id] = array('d' => $d, 'target' => $target, 'ca' => $caFile, 'admin' => $admin);
                $st['state'] = 'on';
                $st['logs'] = $n;
            }
            $status['dest'][$id] = $st;
        }
        // SELinux: rsyslog may connect to each destination's port (or that destination says why not)
        $ports = array();
        foreach ($active as $id => $x) {
            $ports[] = (int) $x['d']['port'];
        }
        list($status['selports'], $badPorts) = self::SelinuxPorts($ports, isset($old['selports']) && is_array($old['selports']) ? array_map('intval', $old['selports']) : array());
        foreach ($active as $id => $x) {
            if (isset($badPorts[(int) $x['d']['port']])) {
                $status['dest'][$id]['state'] = 'error';
                $status['dest'][$id]['text'] = $badPorts[(int) $x['d']['port']];
                unset($active[$id]);
            }
        }
        // CA files of destinations that are gone or have none
        foreach ((array) glob(self::CA_DIR . '/dest-*.pem') as $f) {
            if (preg_match('/dest-(\d+)\.pem$/', $f, $m) && (!isset($active[(int) $m[1]]) || $active[(int) $m[1]]['ca'] !== $f)) {
                @unlink($f);
            }
        }
        // rsyslog's settings
        $conf = self::Config($files, $active);
        $current = @file_get_contents(self::CONF);
        if ($conf === '' && $current !== false) {
            @unlink(self::CONF);
            self::Restart();
            $say[] = 'No destinations left: rsyslog no longer forwards.';
        } elseif ($conf !== '' && $current !== $conf) {
            $res = self::Install($conf);
            $say[] = $res;
            if (strpos($res, 'refused') !== false) {
                foreach ($active as $id => $x) {
                    $status['dest'][$id]['state'] = 'error';
                    $status['dest'][$id]['text'] = $res;
                }
            }
        }
        // read access for rsyslog: exactly the forwarded files
        $status['acl'] = self::Grant(array_keys($files), $status['acl']);
        $status['acl_user'] = self::RsyslogUser();
        // for the panel (which may not look at rsyslog itself under SELinux): what this server has
        $status['ready'] = array('rsyslog' => self::RsyslogBin() !== '', 'tls' => self::TlsModule() !== '', 'missing' => self::Missing());
        $status['install_tried'] = isset($old['install_tried']) ? (int) $old['install_tried'] : 0;
        // counting: what was written to the forwarded files since the last run
        $status = self::Count($files, $active, $status, $month);
        // the state of each active destination: the queue on disk, a check of the connection
        foreach ($active as $id => $x) {
            $q = 0;
            foreach ((array) glob('/var/spool/rsyslog/sentora_lf_' . $id . '.*') as $qf) {
                $q += (int) @filesize($qf);
            }
            $prev = isset($old['dest'][$id]) ? $old['dest'][$id] : array();
            $probe = isset($prev['probe']) && time() - (int) $prev['probe'] < 600 ? $prev : null;
            if ($probe === null) {
                $t = self::Test($x['d'], $x['admin'] ? null : $x['target']);
                $probe = array('probe' => time(), 'ok' => $t['ok'], 'probetext' => $t['text']);
            }
            $status['dest'][$id] = array_merge($status['dest'][$id], array('probe' => $probe['probe'], 'ok' => $probe['ok'], 'probetext' => $probe['probetext'], 'queued' => $q));
            if (!$probe['ok']) {
                $status['dest'][$id]['state'] = 'waiting';
                $status['dest'][$id]['text'] = $probe['probetext'];
            } elseif ($q > 1048576) {
                $status['dest'][$id]['state'] = 'waiting';
                $status['dest'][$id]['text'] = 'Catching up: lines are waiting to be sent.';
            }
        }
        $status['time'] = time();
        self::WriteFile(self::STATE_DIR . '/status.json', json_encode($status, JSON_UNESCAPED_SLASHES), 0640, self::STATE_DIR, 'sentora');
        $say[] = count($active) . ' destination(s) forwarding ' . count($files) . ' log(s).';
        return $say;
    }

    /**
     * Has an account used more than its package's bandwidth this month (0: no limit)?
     *
     * @param int $uid The account.
     * @return bool
     */
    static function OverBandwidth($uid)
    {
        global $zdbh;
        $u = ctrl_users::GetUserDetail($uid);
        $quota = isset($u['bandwidthquota']) ? (float) $u['bandwidthquota'] : 0;
        if ($quota <= 0) {
            return false;
        }
        $q = $zdbh->prepare("SELECT bd_transamount_bi FROM x_bandwidth WHERE bd_acc_fk = :a AND bd_month_in = :m");
        $q->execute(array(':a' => (int) $uid, ':m' => date('Ym')));
        return (float) $q->fetchColumn() > $quota;
    }

    /**
     * rsyslog's settings for the forwarded files and the active destinations ('' when none).
     *
     * @param array $files path => array(src, dests).
     * @param array $active id => array(d, target, ca, admin).
     * @return string
     */
    static function Config($files, $active)
    {
        if (!$files || !$active) {
            return '';
        }
        $q = function ($s) { return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $s) . '"'; };
        $c = "# Sentora Log Forwarder: written by the Sentora daemon from the panel's Log Forwarder page.\n"
            . "# Changes made here are replaced; use the panel.\n\n";
        if (!self::ImfileLoadedElsewhere()) {
            $c .= "module(load=\"imfile\" mode=\"inotify\")\n";
        }
        foreach (array('SentoraLogForward' => '', 'SentoraLogForwardLine' => "\n") as $tname => $end) {
        $c .= "template(name=\"" . $tname . "\" type=\"list\") {\n"
            . "    constant(value=\"<\") property(name=\"pri\") constant(value=\">1 \")\n"
            . "    property(name=\"timegenerated\" dateFormat=\"rfc3339\") constant(value=\" \")\n"
            . "    property(name=\"hostname\") constant(value=\" sentora - \") property(name=\"\$.lftype\")\n"
            . "    constant(value=\" [" . self::SDID . " account=\\\"\") property(name=\"\$.lfacct\")\n"
            . "    constant(value=\"\\\" domain=\\\"\") property(name=\"\$.lfdomain\")\n"
            . "    constant(value=\"\\\" log=\\\"\") property(name=\"\$.lftype\") constant(value=\"\\\"] \")\n"
            . "    property(name=\"msg\" droplastlf=\"on\")" . ($end !== '' ? " constant(value=\"\\n\")" : '') . "\n}\n";
        }
        $c .= "\n";
        foreach ($active as $id => $x) {
            $c .= "ruleset(name=\"sentora_lf_d" . $id . "\") {\n"
                . "    action(type=\"omfwd\" name=\"sentora_lf_" . $id . "\" target=" . $q($x['target']) . " port=\"" . (int) $x['d']['port'] . "\" protocol=\"tcp\"\n"
                . "        template=\"" . ($x['d']['framing'] === 'line' ? 'SentoraLogForwardLine" TCP_Framing="traditional' : 'SentoraLogForward" TCP_Framing="octet-counted') . "\"\n"
                . "        StreamDriver=\"gtls\" StreamDriverMode=\"1\" StreamDriverAuthMode=\"x509/name\" StreamDriverPermittedPeers=" . $q($x['d']['host']) . "\n"
                . "        streamDriver.CAFile=" . $q($x['ca']) . "\n"
                . "        queue.type=\"LinkedList\" queue.filename=\"sentora_lf_" . $id . "\" queue.maxDiskSpace=\"" . ($x['admin'] ? self::QUEUE_ADMIN : self::QUEUE_CLIENT) . "\"\n"
                . "        queue.saveOnShutdown=\"on\" action.resumeRetryCount=\"-1\" action.resumeInterval=\"30\")\n}\n";
        }
        $c .= "\n";
        $i = 0;
        foreach ($files as $path => $f) {
            $i++;
            $s = $f['src'];
            $c .= "ruleset(name=\"sentora_lf_f" . $i . "\") {\n"
                . "    set \$.lftype = " . $q($s['type']) . ";\n    set \$.lfacct = " . $q($s['account']) . ";\n    set \$.lfdomain = " . $q($s['domain']) . ";\n";
            foreach (array_unique($f['dests']) as $id) {
                $c .= "    call sentora_lf_d" . (int) $id . "\n";
            }
            $c .= "}\n"
                . "input(type=\"imfile\" file=" . $q($path) . " tag=\"sentora\" ruleset=\"sentora_lf_f" . $i . "\" facility=\"local6\" severity="
                . ($s['type'] === 'error' || $s['type'] === 'panel-error' ? '"err"' : '"info"') . " freshStartTail=\"on\" reopenOnTruncate=\"on\")\n";
        }
        return $c;
    }

    /**
     * Does another rsyslog file load imfile already (loading it twice is an error)?
     *
     * @return bool
     */
    static function ImfileLoadedElsewhere()
    {
        foreach (array_merge(array('/etc/rsyslog.conf'), (array) glob('/etc/rsyslog.d/*.conf')) as $f) {
            if ($f !== self::CONF && is_file($f) && preg_match('/^\s*(module\s*\(\s*load\s*=\s*"imfile"|\$ModLoad\s+imfile)/mi', (string) @file_get_contents($f))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Put new settings in place only if rsyslog accepts them (rsyslogd -N1 checks all its settings);
     * otherwise the previous ones stay. Then restart rsyslog.
     *
     * @param string $conf The settings.
     * @return string What happened.
     */
    static function Install($conf)
    {
        $backup = @file_get_contents(self::CONF);
        self::WriteFile(self::CONF, $conf, 0644, dirname(self::CONF));
        exec(self::Own(escapeshellarg(self::RsyslogBin() !== '' ? self::RsyslogBin() : 'rsyslogd') . ' -N1 2>&1'), $out, $rc);
        $errors = array_values(array_filter($out, function ($l) { return stripos($l, 'error') !== false; }));
        if ($rc !== 0 || $errors) {
            if ($backup !== false) {
                self::WriteFile(self::CONF, $backup, 0644, dirname(self::CONF));
            } else {
                @unlink(self::CONF);
            }
            return 'rsyslog refused the new settings; the previous ones stay: ' . substr(implode(' ', $errors ?: array_slice($out, -2)), 0, 300);
        }
        self::Restart();
        return 'rsyslog forwards with the new settings.';
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
     * Let rsyslog (user syslog) read exactly the forwarded files: a read ACL on each, passage (x)
     * through their closed folders; taken away again from files no longer forwarded.
     *
     * @param string[] $paths The forwarded files.
     * @param string[] $before The files that had it.
     * @return string[] The files that have it now.
     */
    static function Grant($paths, $before)
    {
        $user = self::RsyslogUser();
        if ($user === 'root' || !is_executable('/usr/bin/setfacl') && !is_executable('/bin/setfacl')) {
            // (rsyslog runs as root here: it reads them as they are)
            return $user === 'root' ? array() : $before;
        }
        $now = array();
        foreach ($paths as $p) {
            if (!is_file($p) || is_link($p)) {
                continue;
            }
            // the closed folders on the way (the hosting logs, the account's logs)
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
     * Count what was written to each forwarded client log since the last run, as it is sent (each
     * line with its syslog header, once per destination), add it to the account's bandwidth this
     * month, and to each destination's own total.
     *
     * @param array $files path => array(src, dests).
     * @param array $active Active destinations.
     * @param array $status The state (files: read positions, month: totals).
     * @param string $month 'Ym'.
     * @return array The state.
     */
    static function Count($files, $active, $status, $month)
    {
        global $zdbh;
        $add = array(); // account id => bytes
        $seen = array();
        foreach ($files as $path => $f) {
            $seen[$path] = true;
            $st = @stat($path);
            if ($st === false) {
                continue;
            }
            $prev = isset($status['files'][$path]) ? $status['files'][$path] : null;
            // first seen: rsyslog starts at its end too (freshStartTail)
            $from = ($prev !== null && (int) $prev[0] === (int) $st['ino'] && (int) $prev[1] <= (int) $st['size']) ? (int) $prev[1] : ($prev === null ? (int) $st['size'] : 0);
            $status['files'][$path] = array((int) $st['ino'], (int) $st['size']);
            $len = (int) $st['size'] - $from;
            if ($len <= 0) {
                continue;
            }
            $lines = 0;
            $fh = @fopen($path, 'rb');
            if ($fh !== false) {
                fseek($fh, $from);
                $left = min($len, 268435456);
                while ($left > 0 && !feof($fh)) {
                    $chunk = (string) fread($fh, min(1048576, $left));
                    $lines += substr_count($chunk, "\n");
                    $left -= strlen($chunk);
                }
                fclose($fh);
            }
            $s = $f['src'];
            $header = strlen('<190>1 2026-01-01T00:00:00.000000+00:00 ' . gethostname() . ' sentora - ' . $s['type'] . ' [' . self::SDID . ' account="'
                . $s['account'] . '" domain="' . $s['domain'] . '" log="' . $s['type'] . '"] ') + 6;
            $bytes = $len + $lines * $header;
            foreach (array_unique($f['dests']) as $id) {
                if (!isset($active[$id])) {
                    continue;
                }
                $key = $id . ':' . $month;
                $status['month'][$key] = (isset($status['month'][$key]) ? (int) $status['month'][$key] : 0) + $bytes;
                if ($s['counted'] && !$active[$id]['admin']) {
                    $acc = $active[$id]['d']['acc'];
                    $add[$acc] = (isset($add[$acc]) ? $add[$acc] : 0) + $bytes;
                }
            }
        }
        foreach (array_keys($status['files']) as $p) {
            if (!isset($seen[$p])) {
                unset($status['files'][$p]);
            }
        }
        // this month's and last month's totals only
        $keep = array($month => true, date('Ym', strtotime('first day of last month')) => true);
        foreach (array_keys($status['month']) as $k) {
            if (!isset($keep[substr($k, strpos($k, ':') + 1)])) {
                unset($status['month'][$k]);
            }
        }
        foreach ($add as $acc => $bytes) {
            $has = $zdbh->prepare("SELECT COUNT(*) FROM x_bandwidth WHERE bd_acc_fk = :a AND bd_month_in = :m");
            $has->execute(array(':a' => $acc, ':m' => $month));
            if ((int) $has->fetchColumn() === 0) {
                $zdbh->prepare("INSERT INTO x_bandwidth (bd_acc_fk, bd_month_in, bd_transamount_bi, bd_diskamount_bi, bd_diskover_in, bd_diskcheck_in, bd_transover_in, bd_transcheck_in) VALUES (:a, :m, 0, 0, 0, 0, 0, 0)")
                    ->execute(array(':a' => $acc, ':m' => $month));
            }
            $zdbh->prepare("UPDATE x_bandwidth SET bd_transamount_bi = bd_transamount_bi + :b WHERE bd_acc_fk = :a AND bd_month_in = :m")
                ->execute(array(':b' => (int) $bytes, ':a' => $acc, ':m' => $month));
        }
        return $status;
    }

    /**
     * Write a file safely in a folder that is root's: a new temporary file there, renamed over the name.
     *
     * @param string $file
     * @param string $data
     * @param int $mode
     * @param string $dir The folder (the temporary file goes there).
     * @param string $group The group ('root' unless the panel reads it).
     * @return bool
     */
    static function WriteFile($file, $data, $mode, $dir, $group = 'root')
    {
        $tmp = @tempnam($dir, '.lf.');
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
     * Root, when the module is removed: take everything away again (settings, ACLs, rule file).
     *
     * @return void
     */
    static function Remove()
    {
        $st = self::State();
        $user = isset($st['acl_user']) && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', (string) $st['acl_user']) ? (string) $st['acl_user'] : 'syslog';
        foreach ((array) (isset($st['acl']) ? $st['acl'] : array()) as $p) {
            if ($user !== 'root' && is_file($p) && !is_link($p)) {
                exec('setfacl -x ' . escapeshellarg('u:' . $user) . ' ' . escapeshellarg($p) . ' 2>/dev/null');
            }
        }
        // the SELinux port labels the module added
        if (!empty($st['selports']) && is_executable('/usr/sbin/semanage')) {
            foreach ((array) $st['selports'] as $p) {
                exec(self::Own('/usr/sbin/semanage port -d -t syslog_tls_port_t -p tcp ' . (int) $p . ' > /dev/null 2>&1'));
            }
        }
        if (is_file(self::CONF)) {
            @unlink(self::CONF);
            self::Restart();
        }
        foreach ((array) glob(self::CA_DIR . '/dest-*.pem') as $f) {
            @unlink($f);
        }
        if (is_file(self::AA_SNIPPET)) {
            @unlink(self::AA_SNIPPET);
            exec('apparmor_parser -r ' . escapeshellarg(self::AA_PROFILE) . ' > /dev/null 2>&1');
        }
    }
}
