<?php

/**
 * Log Forwarder: the page. The account's destinations with their state, a form to add or change one
 * (with a connection test), and how to set up the receiving end in ELK, OpenSearch, Grafana Loki and
 * Graylog. The daemon puts the changes into rsyslog's settings within 5 minutes (log_forwarder::Apply).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */

require_once dirname(__FILE__) . '/logforwarder.php';

class module_controller extends ctrl_module
{
    /** @var string The outcome of the last action: a message and its kind (ok or error). */
    static $msg = '';
    static $msgKind = 'ok';
    /** @var array|null The form as typed, when saving or testing (shown again). */
    static $typed = null;
    /** @var array|null The connection test's result. */
    static $test = null;

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
     * Translate and escape.
     *
     * @param string $s
     * @return string
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * The logged-in account's ID.
     *
     * @return int
     */
    static function Uid()
    {
        return (int) ctrl_users::GetUserDetail()['userid'];
    }

    /**
     * An inline icon (stroke style).
     *
     * @param string $name
     * @return string
     */
    static function Icon($name)
    {
        $p = array(
            'send' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
            'server' => '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
            'pen' => '<path d="M12 20h8"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6 7 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
            'pause' => '<rect width="4" height="16" x="6" y="4" rx="1"/><rect width="4" height="16" x="14" y="4" rx="1"/>',
            'play' => '<path d="m6 4 14 8-14 8z"/>',
            'plug' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z"/>',
            'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
        );
        return '<svg class="lfw-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (isset($p[$name]) ? $p[$name] : '') . '</svg>';
    }

    // ------------------------------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------------------------------

    /**
     * The form's fields as typed.
     *
     * @return array
     */
    static function Typed()
    {
        global $controller;
        $f = $controller->GetAllControllerRequests('FORM');
        $logs = isset($_POST['inLogs']) && is_array($_POST['inLogs']) ? array_values(array_filter($_POST['inLogs'], 'is_string')) : array();
        return array('id' => (int) ($f['inId'] ?? 0), 'name' => (string) ($f['inName'] ?? ''), 'host' => (string) ($f['inHost'] ?? ''),
            'port' => (string) ($f['inPort'] ?? '6514'), 'framing' => (string) ($f['inFraming'] ?? 'octet'), 'ca' => (string) ($f['inCa'] ?? ''), 'logs' => $logs);
    }

    /**
     * Save a destination (new or changed).
     *
     * @return void
     */
    static function doSave()
    {
        runtime_csfr::Protect();
        $t = self::Typed();
        $err = log_forwarder::Save(self::Uid(), $t['id'], $t, $newId);
        if ($err !== '') {
            self::$msg = ui_language::translate($err);
            self::$msgKind = 'error';
            self::$typed = $t;
            return;
        }
        header('location: ./?module=log_forwarder&saved=1');
        exit;
    }

    /**
     * Test the connection with what is typed (nothing is saved).
     *
     * @return void
     */
    static function doTest()
    {
        runtime_csfr::Protect();
        $t = self::Typed();
        self::$typed = $t;
        $host = strtolower(trim($t['host']));
        $port = (int) $t['port'];
        if (!log_forwarder::ValidHost($host) || $port < 1 || $port > 65535) {
            self::$test = array('ok' => false, 'text' => ui_language::translate('Fill in a valid server and port first.'));
            return;
        }
        $ca = trim($t['ca']);
        if ($ca !== '' && ($ca = log_forwarder::CheckCa($ca)) === false) {
            self::$test = array('ok' => false, 'text' => ui_language::translate('The CA certificate is not a PEM certificate (-----BEGIN CERTIFICATE----- ...).'));
            return;
        }
        $ip = null;
        if (!log_forwarder::IsAdmin(self::Uid())) {
            $why = log_forwarder::PublicOnly($host);
            if ($why !== '') {
                self::$test = array('ok' => false, 'text' => ui_language::translate($why));
                return;
            }
            $ips = log_forwarder::Resolve($host);
            $ip = $ips[0];
        }
        self::$test = log_forwarder::Test(array('host' => $host, 'port' => $port, 'ca' => (string) $ca), $ip);
    }

    /**
     * Switch a destination on or off, or delete it.
     *
     * @return void
     */
    static function doChange()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $what = (string) ($f['inWhat'] ?? '');
        if (in_array($what, array('on', 'off', 'delete'), true)) {
            log_forwarder::Change(self::Uid(), (int) ($f['inId'] ?? 0), $what);
        }
        header('location: ./?module=log_forwarder&changed=' . rawurlencode($what));
        exit;
    }

    /**
     * The outcome of the last action (<@ Result @>).
     *
     * @return string HTML.
     */
    static function getResult()
    {
        if (self::$msg !== '') {
            return ui_sysmessage::shout(self::$msg, self::$msgKind === 'error' ? 'zannounceerror' : 'zannounceok');
        }
        if (isset($_GET['saved'])) {
            return ui_sysmessage::shout(ui_language::translate('Saved: the server starts forwarding with these settings within 5 minutes.'), 'zannounceok');
        }
        $c = isset($_GET['changed']) ? (string) $_GET['changed'] : '';
        $words = array('on' => 'Switched on: forwarding starts within 5 minutes.', 'off' => 'Switched off: forwarding stops within 5 minutes.', 'delete' => 'Deleted: forwarding to it stops within 5 minutes.');
        return isset($words[$c]) ? ui_sysmessage::shout(ui_language::translate($words[$c]), 'zannounceok') : '';
    }

    // ------------------------------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------------------------------

    /**
     * A warning when the server can not forward yet (rsyslog or its TLS driver missing) (<@ Ready @>).
     *
     * @return string HTML ('' when ready).
     */
    static function getReady()
    {
        // (the daemon, as root, notes what this server has: the panel itself may not look, under SELinux)
        $st = log_forwarder::State();
        if (!isset($st['ready'])) {
            return '<div class="lfw-card lfw-warn">' . self::t('The daemon has not checked this server for rsyslog yet: it does within 5 minutes, and installs what is missing.') . '</div>';
        }
        if (!empty($st['ready']['rsyslog']) && !empty($st['ready']['tls'])) {
            return '';
        }
        $missing = isset($st['ready']['missing']) ? implode(' ', array_map('strval', (array) $st['ready']['missing'])) : 'rsyslog rsyslog-gnutls';
        $cmd = trim((string) ctrl_options::GetSystemOption('package_install'));
        return '<div class="lfw-card lfw-warn">' . self::t('This server can not forward yet: rsyslog or its TLS support is missing. The module installs it by itself (it tries again every hour); if this stays, an administrator can run this as root:')
            . ' <code>' . htmlspecialchars(($cmd !== '' ? $cmd : 'install') . ' ' . $missing, ENT_QUOTES, 'UTF-8') . '</code></div>';
    }

    /**
     * The account's destinations with their state (<@ Destinations @>).
     *
     * @return string HTML.
     */
    static function getDestinations()
    {
        $uid = self::Uid();
        $dests = log_forwarder::Destinations($uid);
        if (!$dests) {
            return '<div class="lfw-card lfw-state">' . self::Icon('send') . '<b>' . self::t('No destinations yet') . '</b><span>'
                . self::t('Add your log server below: your logs then reach it as they are written, within a second or two.') . '</span></div>';
        }
        $st = log_forwarder::State();
        $sources = log_forwarder::Sources($uid);
        $month = date('Ym');
        $labels = array('on' => array('Sending', 'ok'), 'waiting' => array('Waiting', 'warn'), 'paused' => array('Paused', 'warn'),
            'blocked' => array('Blocked', 'bad'), 'error' => array('Not sending', 'bad'), 'off' => array('Switched off', 'off'), 'new' => array('Starts within 5 minutes', 'off'));
        $out = '<div class="lfw-grid">';
        foreach ($dests as $id => $d) {
            $s = isset($st['dest'][$id]) ? $st['dest'][$id] : array('state' => $d['enabled'] ? 'new' : 'off', 'text' => '');
            // changed since the daemon last looked
            if ($d['enabled'] && $s['state'] === 'off') {
                $s = array('state' => 'new', 'text' => '');
            }
            if (!$d['enabled']) {
                $s = array('state' => 'off', 'text' => '');
            }
            $l = isset($labels[$s['state']]) ? $labels[$s['state']] : $labels['new'];
            $sent = isset($st['month'][$id . ':' . $month]) ? (int) $st['month'][$id . ':' . $month] : 0;
            $names = array();
            foreach ($d['logs'] as $k) {
                if (isset($sources[$k])) {
                    $names[] = $sources[$k]['label'] . ($sources[$k]['group'] === 'server' ? '' : ' (' . ($sources[$k]['type'] === 'access' ? ui_language::translate('visits') : ui_language::translate('errors')) . ')');
                }
            }
            $btn = function ($what, $label, $icon, $confirm = '') use ($id) {
                return '<form method="post" action="./?module=log_forwarder&amp;action=Change" class="lfw-inline"' . ($confirm !== '' ? ' onsubmit="return confirm(this.getAttribute(\'data-confirm\'))" data-confirm="' . self::t($confirm) . '"' : '') . '>'
                    . '<input type="hidden" name="inId" value="' . (int) $id . '"><input type="hidden" name="inWhat" value="' . $what . '">' . runtime_csfr::Token()
                    . '<button class="btn btn-default btn-sm" type="submit">' . self::Icon($icon) . self::t($label) . '</button></form>';
            };
            $out .= '<article class="lfw-card lfw-dest is-' . self::h($l[1]) . '">'
                . '<header><span class="lfw-name">' . self::Icon('server') . self::h($d['name']) . '</span><span class="lfw-pill is-' . self::h($l[1]) . '">' . self::t($l[0]) . '</span></header>'
                . '<p class="lfw-host"><code>' . self::h($d['host']) . ':' . (int) $d['port'] . '</code> · ' . self::t('TLS') . ' · '
                . self::t($d['framing'] === 'line' ? 'one line per message' : 'syslog framing') . ($d['ca'] !== '' ? ' · ' . self::t('own CA') : '') . '</p>'
                . (!empty($s['text']) ? '<p class="lfw-why is-' . self::h($l[1]) . '">' . self::h(ui_language::translate($s['text'])) . '</p>' : '')
                . '<p class="lfw-logs"><b>' . self::t('Logs') . '</b> ' . self::h(implode(', ', $names)) . '</p>'
                . '<p class="lfw-sent"><b>' . self::t('Sent this month') . '</b> ' . self::h(fs_director::ShowHumanFileSize($sent))
                . (!empty($s['queued']) ? ' · <b>' . self::t('waiting to be sent') . '</b> ' . self::h(fs_director::ShowHumanFileSize((int) $s['queued'])) : '') . '</p>'
                . '<footer><a class="btn btn-default btn-sm" href="./?module=log_forwarder&amp;edit=' . (int) $id . '#lfw-form">' . self::Icon('pen') . self::t('Change') . '</a>'
                . ($d['enabled'] ? $btn('off', 'Switch off', 'pause') : $btn('on', 'Switch on', 'play'))
                . $btn('delete', 'Delete', 'trash', 'Delete this destination? Forwarding to it stops.') . '</footer>'
                . '</article>';
        }
        return $out . '</div>';
    }

    /**
     * The add or change form (<@ Form @>).
     *
     * @return string HTML.
     */
    static function getForm()
    {
        $uid = self::Uid();
        $dests = log_forwarder::Destinations($uid);
        $edit = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        if (self::$typed !== null) {
            $v = self::$typed;
        } elseif ($edit && isset($dests[$edit])) {
            $d = $dests[$edit];
            $v = array('id' => $edit, 'name' => $d['name'], 'host' => $d['host'], 'port' => (string) $d['port'], 'framing' => $d['framing'], 'ca' => $d['ca'], 'logs' => $d['logs']);
        } else {
            if (count($dests) >= log_forwarder::MAX_DEST) {
                return '<p class="lfw-note">' . self::t('You have the most destinations an account may have (3): change or delete one to add another.') . '</p>';
            }
            $v = array('id' => 0, 'name' => '', 'host' => '', 'port' => '6514', 'framing' => 'octet', 'ca' => '', 'logs' => array());
        }
        $sources = log_forwarder::Sources($uid);
        $groups = array('access' => 'Visits (access logs)', 'error' => 'Errors (error logs)', 'server' => 'Server logs (administrators)');
        $boxes = '';
        foreach ($groups as $g => $title) {
            $items = '';
            foreach ($sources as $k => $s) {
                if ($s['group'] === $g) {
                    $items .= '<label class="lfw-check"><input type="checkbox" name="inLogs[]" value="' . self::h($k) . '"' . (in_array($k, $v['logs'], true) ? ' checked' : '') . '> '
                        . self::h($g === 'server' ? ui_language::translate($s['label']) : $s['label']) . '</label>';
                }
            }
            if ($items !== '') {
                $boxes .= '<fieldset class="lfw-group"><legend>' . self::t($title) . '</legend>' . $items . '</fieldset>';
            }
        }
        if ($boxes === '') {
            $boxes = '<p class="lfw-note">' . self::t('You have no domains yet: add one, and its logs can be forwarded.') . '</p>';
        }
        $test = '';
        if (self::$test !== null) {
            $test = '<div class="lfw-test is-' . (self::$test['ok'] ? 'ok' : 'bad') . '">' . self::h(self::$test['text']) . '</div>';
        }
        $admin = log_forwarder::IsAdmin($uid);
        return '<form id="lfw-form" class="lfw-card lfw-form" method="post" action="./?module=log_forwarder&amp;action=Save">'
            . '<h3>' . self::Icon('send') . self::t($v['id'] ? 'Change the destination' : 'Add a destination') . '</h3>'
            . '<input type="hidden" name="inId" value="' . (int) $v['id'] . '">' . runtime_csfr::Token()
            . '<div class="lfw-row">'
            . '<label class="lfw-field lfw-wide"><span>' . self::t('Name') . '</span><input class="form-control" type="text" name="inName" maxlength="60" value="' . self::h($v['name']) . '" placeholder="' . self::t('e.g. Our ELK') . '"></label>'
            . '<label class="lfw-field lfw-wide"><span>' . self::t('Log server (host name or IP address)') . '</span><input class="form-control" type="text" name="inHost" maxlength="253" value="' . self::h($v['host']) . '" placeholder="logs.example.com"></label>'
            . '<label class="lfw-field"><span>' . self::t('Port') . '</span><input class="form-control" name="inPort" type="number" min="1" max="65535" value="' . self::h($v['port']) . '"></label>'
            . '</div>'
            . '<label class="lfw-field"><span>' . self::t('Format') . '</span><select class="form-control" name="inFraming">'
            . '<option value="octet"' . ($v['framing'] !== 'line' ? ' selected' : '') . '>' . self::t('Syslog framing (RFC 5425): Graylog, Grafana Alloy/Loki, Elastic Agent, Filebeat, Vector') . '</option>'
            . '<option value="line"' . ($v['framing'] === 'line' ? ' selected' : '') . '>' . self::t('One line per message: Logstash, Fluent Bit') . '</option></select></label>'
            . '<div class="lfw-logsel"><span class="lfw-label">' . self::t('Logs to send') . '</span>' . $boxes . '</div>'
            . '<details class="lfw-ca"' . ($v['ca'] !== '' ? ' open' : '') . '><summary>' . self::t('My log server uses a certificate of its own making (paste its CA certificate)') . '</summary>'
            . '<textarea class="form-control" name="inCa" rows="5" placeholder="-----BEGIN CERTIFICATE-----">' . self::h($v['ca']) . '</textarea>'
            . '<small>' . self::t('Without one, the log server needs a certificate from a public authority (for example Let\'s Encrypt) for its name.') . '</small></details>'
            . $test
            . '<p class="lfw-note">' . self::t($admin ? 'Sent over TLS only. Administrators may use a log server on the local network.'
                : 'Sent over TLS only, to a log server on the internet. What is sent counts towards your package\'s monthly bandwidth; when that is used up, forwarding pauses until the next month.')
            . ' ' . self::t('Access logs hold your visitors\' IP addresses: make sure your log server may keep them.') . '</p>'
            . '<div class="lfw-foot"><button class="btn btn-default" type="submit" formaction="./?module=log_forwarder&amp;action=Test">' . self::Icon('plug') . self::t('Test the connection') . '</button>'
            . '<button class="btn btn-primary" type="submit">' . self::t($v['id'] ? 'Save the changes' : 'Save the destination') . '</button>'
            . ($v['id'] ? '<a class="btn btn-default" href="./?module=log_forwarder">' . self::t('Cancel') . '</a>' : '') . '</div>'
            . '</form>';
    }

    /**
     * How to set up the receiving end (<@ Help @>).
     *
     * @return string HTML.
     */
    static function getHelp()
    {
        $pre = function ($s) { return '<pre>' . self::h($s) . '</pre>'; };
        $sd = log_forwarder::SDID;
        $sdl = str_replace('@', '_', $sd);
        $items = array(
            array('Graylog', 'Format: Syslog framing', 'System > Inputs > "Syslog TCP" > Launch new input: port 6514, TLS cert file and key file of the log server, "Enable TLS" ticked. Graylog reads the framing by itself; the fields account, domain and log arrive as message fields.'),
            array('Grafana + Loki (Grafana Alloy)', 'Format: Syslog framing', null, "loki.source.syslog \"sentora\" {\n  listener {\n    address  = \"0.0.0.0:6514\"\n    protocol = \"tcp\"\n    tls_config {\n      cert_file = \"/etc/alloy/tls/server.crt\"\n      key_file  = \"/etc/alloy/tls/server.key\"\n    }\n    labels = { job = \"sentora\" }\n  }\n  relabel_rules = loki.relabel.sentora.rules\n  forward_to    = [loki.write.default.receiver]\n}\n\nloki.relabel \"sentora\" {\n  forward_to = []\n  rule {\n    source_labels = [\"__syslog_message_sd_" . $sdl . "_domain\"]\n    target_label  = \"domain\"\n  }\n  rule {\n    source_labels = [\"__syslog_message_sd_" . $sdl . "_log\"]\n    target_label  = \"log\"\n  }\n  rule {\n    source_labels = [\"__syslog_message_hostname\"]\n    target_label  = \"server\"\n  }\n}"),
            array('ELK: Elastic Agent or Filebeat', 'Format: Syslog framing', null, "filebeat.inputs:\n  - type: tcp\n    host: \"0.0.0.0:6514\"\n    framing: rfc6587\n    ssl.enabled: true\n    ssl.certificate: \"/etc/filebeat/tls/server.crt\"\n    ssl.key: \"/etc/filebeat/tls/server.key\"\n    processors:\n      - syslog:\n          field: message\n\n# Elastic Agent: the \"Custom TCP Logs\" integration with the same\n# settings (framing rfc6587, SSL), and the syslog processor."),
            array('ELK or OpenSearch: Logstash', 'Format: One line per message', null, "input {\n  tcp {\n    port => 6514\n    ssl_enabled => true\n    ssl_certificate => \"/etc/logstash/tls/server.crt\"\n    ssl_key => \"/etc/logstash/tls/server.key\"\n    ssl_client_authentication => \"none\"\n  }\n}\nfilter {\n  grok {\n    match => { \"message\" => \"<%{NONNEGINT:priority}>1 %{TIMESTAMP_ISO8601:sent} %{HOSTNAME:server} sentora - %{WORD:log} \\[" . $sd . " account=\\\"%{DATA:account}\\\" domain=\\\"%{DATA:domain}\\\" log=\\\"%{DATA}\\\"\\] %{GREEDYDATA:line}\" }\n  }\n  if [log] == \"access\" {\n    grok { match => { \"line\" => \"%{COMBINEDAPACHELOG}\" } }\n  }\n}\noutput {\n  elasticsearch { hosts => [\"https://localhost:9200\"] index => \"sentora-%{+YYYY.MM.dd}\" }\n  # OpenSearch: opensearch { hosts => [\"https://localhost:9200\"] index => \"sentora-%{+YYYY.MM.dd}\" }\n}"),
        );
        $out = '<section class="lfw-card lfw-help"><h3>' . self::Icon('book') . self::t('Setting up your log server') . '</h3>'
            . '<p>' . self::t('Each line arrives as standard syslog (RFC 5424) over TLS, tagged with the account, the domain and the log (access, error, mail...), and keeps the original log line, so the usual Apache parsers work on it. Open port 6514 (or the one you chose) on your log server for this server\'s address.') . '</p>';
        foreach ($items as $it) {
            $out .= '<details><summary><b>' . self::h($it[0]) . '</b> <small>' . self::t($it[1]) . '</small></summary>'
                . ($it[2] !== null ? '<p>' . self::t($it[2]) . '</p>' : '') . (isset($it[3]) ? $pre($it[3]) : '') . '</details>';
        }
        return $out . '</section>';
    }

    /**
     * Nothing to prepare (template <@ Init @>).
     *
     * @return void
     */
    static function getInit()
    {
    }
}

?>
