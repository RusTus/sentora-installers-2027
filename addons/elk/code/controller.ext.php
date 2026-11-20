<?php

/**
 * Elk (administrators): the page. The Elk node with its state (or how to set one up: the node's installer, this
 * panel's code, then "Add the Elk node"), which groups of logs go there, and Open Kibana. The daemon pairs the node
 * and puts the choices into rsyslog's settings (elk::Apply); the buttons start it at once where the panel can.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-20
 */

require_once dirname(__FILE__) . '/elk.php';

class module_controller extends ctrl_module
{
    /** @var string The outcome of the last action, and its kind (ok or bad). */
    static $msg = '';
    static $msgKind = 'ok';
    /** @var array|null The node form as typed (shown again after a mistake). */
    static $typed = null;

    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * Is the logged-in account an administrator?
     *
     * @return bool
     */
    static function IsAdmin()
    {
        $me = ctrl_users::GetUserDetail();
        return (int) $me['usergroupid'] === 1;
    }

    /**
     * An inline icon (stroke style, as the reworked pages).
     *
     * @param string $name
     * @return string
     */
    static function Icon($name)
    {
        $p = array(
            'server' => '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
            'open' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6 7 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
            'save' => '<path d="M5 21h14a2 2 0 0 0 2-2V8l-5-5H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z"/><path d="M7 3v5h8"/><path d="M7 21v-7h10v7"/>',
        );
        return '<svg class="ek-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (isset($p[$name]) ? $p[$name] : '') . '</svg>';
    }

    /**
     * Start the daemon now (zsudo daemon-run, as Backup Config does), so the page shows the result soon.
     *
     * @return bool Whether it could be started.
     */
    static function RunNow()
    {
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        if ($zsudo === '' || !is_executable($zsudo) || !function_exists('exec')) {
            return false;
        }
        @touch('/var/lib/sentora/tmp/daemon-again');
        $out = array();
        $rc = 1;
        exec(escapeshellcmd($zsudo) . ' daemon-run 2>&1', $out, $rc);
        return (int) $rc === 0;
    }

    // ------------------------------------------------------------------------------------------
    // Actions (administrators only; every form carries the panel's CSRF token)
    // ------------------------------------------------------------------------------------------

    static function doAddNode()
    {
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        global $controller;
        $f = $controller->GetAllControllerRequests('FORM');
        $addr = (string) ($f['inAddr'] ?? '');
        $key = (string) ($f['inKey'] ?? '');
        $err = elk::AddNode($addr, $key);
        if ($err !== '') {
            self::$msg = ui_language::translate($err);
            self::$msgKind = 'bad';
            self::$typed = array('addr' => $addr, 'key' => $key);
            return;
        }
        self::RunNow();
        header('location: ./?module=elk&added=1');
        exit;
    }

    static function doRemoveNode()
    {
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        elk::RemoveNode();
        self::RunNow();
        header('location: ./?module=elk&removed=1');
        exit;
    }

    static function doSaveGroups()
    {
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        $g = isset($_POST['inGroups']) && is_array($_POST['inGroups']) ? array_values(array_filter($_POST['inGroups'], 'is_string')) : array();
        elk::SaveGroups($g);
        self::RunNow();
        header('location: ./?module=elk&saved=1');
        exit;
    }

    static function doCheckNow()
    {
        runtime_csfr::Protect();
        if (!self::IsAdmin()) {
            return;
        }
        // (the daemon looks again at its next run: now, where the panel can start it)
        elk::Save(elk::S_CHECK, (string) time());
        $n = elk::Node();
        if ($n['addr'] !== '' && !elk::Paired()) {
            elk::Save(elk::S_PAIRING, 'wait ' . time());
        }
        self::RunNow();
        header('location: ./?module=elk&checked=1');
        exit;
    }

    // ------------------------------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------------------------------

    static function getResult()
    {
        $m = self::$msg;
        $k = self::$msgKind;
        if ($m === '') {
            foreach (array('added' => 'The Elk node was added: the panel checks its key now (this page shows the result in a moment).',
                'removed' => 'The Elk node was removed: the panel stops sending to it. Its logs stay on the node.',
                'saved' => 'Saved: the panel sends the chosen logs from its next run (within a minute or two).',
                'checked' => 'Checked again.') as $g => $text) {
                if (isset($_GET[$g])) {
                    $m = ui_language::translate($text);
                }
            }
        }
        return $m === '' ? '' : '<div class="ek-msg is-' . ($k === 'bad' ? 'bad' : 'ok') . '" role="status">' . self::h($m) . '</div>';
    }

    /**
     * The node's state for the page: array(kind, words), kind one of none, wait, fail, ok, away, error.
     *
     * @return array{0: string, 1: string}
     */
    static function NodeState()
    {
        $n = elk::Node();
        $st = elk::State();
        if ($n['addr'] === '') {
            return array('none', 'No Elk node yet');
        }
        if (!elk::Paired()) {
            if (strpos($n['pairing'], 'fail ') === 0) {
                return array('fail', preg_replace('/^fail \d+ /', '', $n['pairing']));
            }
            return array('wait', 'Checking the node\'s key...');
        }
        if (!empty($st['missing'])) {
            return array('error', 'Waiting for rsyslog to be installed here (' . implode(', ', (array) $st['missing']) . ').');
        }
        if (!empty($st['error'])) {
            return array('error', (string) $st['error']);
        }
        if (!isset($st['reachable'])) {
            return array('wait', 'Paired; the first check follows within a few minutes.');
        }
        if (!$st['reachable']) {
            return array('away', (string) $st['probetext'] . ' The lines wait here (up to 1 GB) and follow when it answers.');
        }
        return array('ok', 'Sending');
    }

    static function getTiles()
    {
        if (!self::IsAdmin()) {
            return '';
        }
        list($kind, $words) = self::NodeState();
        $st = elk::State();
        $n = elk::Node();
        $q = isset($st['queued']) ? (int) $st['queued'] : 0;
        $tile = function ($value, $label, $cls = '') {
            return '<div class="ek-tile' . ($cls !== '' ? ' is-' . $cls : '') . '"><b>' . self::h($value) . '</b><span>' . self::t($label) . '</span></div>';
        };
        $state = array('none' => 'Not set up', 'wait' => 'Checking', 'fail' => 'Key not matched', 'ok' => 'Sending', 'away' => 'Not reachable', 'error' => 'Needs a look');
        $cls = array('none' => '', 'wait' => 'wait', 'fail' => 'bad', 'ok' => 'ok', 'away' => 'watch', 'error' => 'bad');
        return '<div class="ek-tiles">'
            . $tile(ui_language::translate($state[$kind]), $n['addr'] !== '' ? $n['addr'] : 'Elk node', $cls[$kind])
            . $tile(elk::Paired() ? (string) (int) ($st['files'] ?? 0) : '-', 'Log files followed')
            . $tile(count(elk::Chosen()) . ' / ' . count(elk::Groups()), 'Kinds of logs sent')
            . $tile($q > 0 ? ($q >= 1048576 ? round($q / 1048576, 1) . ' MB' : max(1, round($q / 1024)) . ' KB') : '0', 'Waiting to be sent', $q > 104857600 ? 'watch' : '')
            . '</div>';
    }

    static function getNode()
    {
        if (!self::IsAdmin()) {
            return '<div class="ek-card"><p>' . self::t('Only administrators can use Elk.') . '</p></div>';
        }
        $n = elk::Node();
        list($kind, $words) = self::NodeState();
        $tok = runtime_csfr::Token();
        $out = '<div class="ek-card"><h3>' . self::t('Your Elk node') . '</h3>';
        if ($n['addr'] !== '' && $kind !== 'fail') {
            $pill = array('wait' => 'wait', 'ok' => 'ok', 'away' => 'watch', 'error' => 'bad');
            $out .= '<div class="ek-row"><div class="ek-row-main">' . self::Icon('server')
                . '<div><b class="ek-addr">' . self::h($n['addr']) . '</b> <span class="ek-pill is-' . $pill[$kind] . '">' . self::t(array('wait' => 'Checking', 'ok' => 'Sending', 'away' => 'Not reachable', 'error' => 'Needs a look')[$kind]) . '</span>'
                . '<div class="ek-muted">' . self::t('Key') . ' ' . self::h($n['key']) . ($kind !== 'ok' ? ' &middot; ' . self::t($words) : '') . '</div></div></div>'
                . '<div class="ek-row-btns">';
            if (elk::Paired()) {
                $out .= '<a class="btn btn-primary btn-sm" href="' . self::h('https://' . (filter_var($n['addr'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $n['addr'] . ']' : $n['addr']) . '/') . '" target="_blank" rel="noopener noreferrer">'
                    . self::Icon('open') . ' ' . self::t('Open Kibana') . '</a>';
            }
            $out .= '<form method="post" action="./?module=elk&action=CheckNow" class="ek-inline">' . $tok . '<button type="submit" class="btn btn-default btn-sm">' . self::Icon('refresh') . ' ' . self::t('Check now') . '</button></form>'
                . '<form method="post" action="./?module=elk&action=RemoveNode" class="ek-inline" onsubmit="return confirm(' . self::h(json_encode(ui_language::translate('Remove the Elk node? The panel stops sending to it; its logs stay on the node.'))) . ');">'
                . $tok . '<button type="submit" class="btn btn-default btn-sm">' . self::Icon('trash') . ' ' . self::t('Remove') . '</button></form>'
                . '</div></div>';
            if (elk::Paired()) {
                $out .= '<p class="ek-muted ek-after">' . self::t('Kibana asks for its own login: sentora-admin, with the password the node\'s installer showed (sentora-elk-node password on the node makes a new one). The browser warns once: the certificate is the node\'s own.') . '</p>';
            }
            return $out . '</div>';
        }
        // not set up yet (or the key did not match): the three steps
        if ($kind === 'fail') {
            $out .= '<div class="ek-msg is-bad">' . self::t($words) . '</div>';
        }
        $code = elk::PanelCode();
        $cmd = 'curl -fsSL https://raw.githubusercontent.com/RusTus/sentora-installers-2027/master2027/elk-node/sentora2027-elk-node -o sentora2027-elk-node' . "\n" . 'sudo bash sentora2027-elk-node';
        $t = self::$typed ?: array('addr' => $n['addr'], 'key' => $n['key']);
        $out .= '<ol class="ek-steps">'
            . '<li><b>' . self::t('On a new server of its own') . '</b> (' . self::t('Ubuntu 26.04, at least 4 GB of memory, 8 GB or more is better') . '), ' . self::t('as root:') . '<pre class="ek-pre">' . self::h($cmd) . '</pre></li>'
            . '<li><b>' . self::t('It asks for this panel\'s address and shows a code.') . '</b> ' . self::t('Answer y only when it is this panel\'s code:')
            . ' <span class="ek-code">' . ($code !== '' ? self::h($code) : self::t('made by the panel\'s next run, in a minute or two')) . '</span></li>'
            . '<li><b>' . self::t('It ends with the node\'s key and the Kibana login.') . '</b> ' . self::t('Add the node here:') . '</li></ol>'
            . '<form method="post" action="./?module=elk&action=AddNode" class="ek-form">' . $tok
            . '<label>' . self::t('The Elk node\'s address') . '<input type="text" name="inAddr" value="' . self::h($t['addr']) . '" placeholder="172.16.1.20" maxlength="253" autocomplete="off" required></label>'
            . '<label>' . self::t('Elk node key') . '<input type="text" name="inKey" value="' . self::h($t['key']) . '" placeholder="SE1-XXXX-XXXX-XXXX-XXXX" maxlength="40" autocomplete="off" spellcheck="false" required></label>'
            . '<div><button type="submit" class="btn btn-primary">' . self::Icon('plus') . ' ' . self::t('Add the Elk node') . '</button></div></form>';
        return $out . '</div>';
    }

    static function getGroups()
    {
        if (!self::IsAdmin()) {
            return '';
        }
        $on = elk::Chosen();
        $st = elk::State();
        $out = '<div class="ek-card"><h3>' . self::t('What goes to the Elk node') . '</h3><form method="post" action="./?module=elk&action=SaveGroups">' . runtime_csfr::Token() . '<div class="ek-groups">';
        foreach (elk::Groups() as $k => $g) {
            $out .= '<label class="ek-group"><input type="checkbox" name="inGroups[]" value="' . self::h($k) . '"' . (in_array($k, $on, true) ? ' checked' : '') . '>'
                . '<span><b>' . self::t($g[0]) . '</b><span class="ek-muted">' . self::t($g[1]) . '</span></span></label>';
        }
        $out .= '</div><button type="submit" class="btn btn-primary">' . self::Icon('save') . ' ' . self::t('Save') . '</button></form>'
            . '<p class="ek-muted ek-after">' . self::t('Each line goes once, over TLS, as it is written; while the node is away the lines wait here (up to 1 GB). Logs are kept on the node for 30 days (sentora-elk-node retention DAYS on the node changes it).') . '</p></div>';
        return $out;
    }

    static function getHelp()
    {
        if (!self::IsAdmin()) {
            return '';
        }
        return '<div class="ek-card ek-help"><h3>' . self::t('On the Elk node') . '</h3><table class="ek-table">'
            . '<tr><td><code>sentora-elk-node status</code></td><td>' . self::t('the services, the logs kept, the disk') . '</td></tr>'
            . '<tr><td><code>sentora-elk-node password</code></td><td>' . self::t('a new password for Kibana\'s sentora-admin login') . '</td></tr>'
            . '<tr><td><code>sentora-elk-node retention 90</code></td><td>' . self::t('keep the logs 90 days') . '</td></tr>'
            . '<tr><td><code>sentora-elk-node kibana-from 203.0.113.5</code></td><td>' . self::t('only that address (or network) may open Kibana; any opens it again') . '</td></tr>'
            . '<tr><td><code>sentora-elk-node update</code></td><td>' . self::t('Sentora\'s newest rules and dashboards; Elastic\'s updates come with the system\'s') . '</td></tr>'
            . '</table></div>';
    }
}
