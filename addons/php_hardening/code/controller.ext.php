<?php

/**
 * Web Hardening (administrators): ModSecurity off, watch only or on (how strict, the domains and
 * rules left alone); each PHP's Snuffleupagus off, watch only or on (the rules, the accounts left
 * alone); what both caught. Saving only stores the choice: the daemon does the work
 * (ph_daemon) on its next run.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
require_once dirname(__FILE__) . '/ph.php';
require_once dirname(__FILE__) . '/modsec.php';

class module_controller extends ctrl_module
{
    /** @var array<int, array{0: string, 1: string}> Messages for the page. */
    static $msg = array();

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
     * Whether the logged-in account is an administrator.
     *
     * @return bool
     */
    static function Admin()
    {
        $me = ctrl_users::GetUserDetail();
        return (int) $me['usergroupid'] === 1;
    }

    /**
     * A posted field.
     *
     * @param string $n
     * @return string
     */
    static function Post($n)
    {
        return isset($_POST[$n]) && is_string($_POST[$n]) ? $_POST[$n] : '';
    }

    /**
     * Save the mode of each PHP.
     *
     * @return void
     */
    static function doModes()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $changed = 0;
        $modes = isset($_POST['inMode']) && is_array($_POST['inMode']) ? $_POST['inMode'] : array();
        foreach (array_keys(ph::Versions()) as $v) {
            $m = isset($modes[$v]) && is_string($modes[$v]) ? $modes[$v] : null;
            if ($m === null || !in_array($m, array('off', 'watch', 'on'), true)) {
                continue;
            }
            if (ph::Mode($v) !== $m) {
                ph::Set('mode:' . $v, $m);
                $changed++;
            }
        }
        self::Say('ok', $changed ? 'Saved. The daemon applies it on its next run (within a few minutes).' : 'Nothing changed.');
    }

    /**
     * Save the chosen rules.
     *
     * @return void
     */
    static function doRules()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $pick = isset($_POST['inRule']) && is_array($_POST['inRule']) ? $_POST['inRule'] : array();
        $chosen = array();
        foreach (array_keys(ph::Catalog()) as $id) {
            if (isset($pick[$id]) && $pick[$id] === '1') {
                $chosen[] = $id;
            }
        }
        // (saved even when empty: the defaults only count before the first save; one marker keeps "none" apart from "not saved")
        ph::Set('rules', $chosen ? implode(',', $chosen) : '-');
        self::Say('ok', 'Rules saved. The daemon applies them on its next run (within a few minutes).');
    }

    /**
     * Save the accounts left alone.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doExempt()
    {
        global $zdbh;
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $names = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', trim(self::Post('inExempt'))))));
        $known = array();
        $q = $zdbh->prepare("SELECT ac_user_vc FROM x_accounts WHERE ac_user_vc = :u AND ac_deleted_ts IS NULL");
        $bad = array();
        foreach ($names as $u) {
            $q->execute(array(':u' => $u));
            $found = $q->fetchColumn();
            if ($found !== false && preg_match('/^[a-z0-9][a-z0-9._-]{0,59}$/i', (string) $found)) {
                $known[] = (string) $found;
            } else {
                $bad[] = $u;
            }
        }
        if ($bad) {
            self::Say('error', 'Not saved: no account is called %s.', array(implode(', ', $bad)));
            return;
        }
        ph::Set('exempt', implode(',', $known));
        self::Say('ok', 'Saved. The daemon applies it on its next run (within a few minutes).');
    }

    /**
     * Save ModSecurity's mode, level and threshold.
     *
     * @return void
     */
    static function doModSec()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $m = self::Post('inMsMode');
        $l = self::Post('inMsLevel');
        $t = self::Post('inMsThreshold');
        if (!in_array($m, array('off', 'watch', 'on'), true) || !in_array($l, array('1', '2', '3'), true) || !in_array($t, array('5', '10', '20'), true)) {
            self::Say('error', 'Not saved: choose from the lists.');
            return;
        }
        ph::Set('ms:mode', $m);
        ph::Set('ms:level', $l);
        ph::Set('ms:threshold', $t);
        self::Say('ok', 'Saved. The daemon applies it on its next run (within a few minutes).');
    }

    /**
     * Save the domains and rules ModSecurity leaves alone.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doModSecSkip()
    {
        global $zdbh;
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $domains = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', strtolower(trim(self::Post('inMsDomains')))))));
        $rules = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', trim(self::Post('inMsRules'))))));
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_name_vc = :d");
        $bad = array();
        foreach ($domains as $i => $d) {
            $d = preg_replace('/^www\./', '', $d);
            $domains[$i] = $d;
            $q->execute(array(':d' => $d));
            if (!ph_modsec::IsDomain($d) || (int) $q->fetchColumn() === 0) {
                $bad[] = $d;
            }
        }
        if ($bad) {
            self::Say('error', 'Not saved: %s is not a domain on this server.', array(implode(', ', $bad)));
            return;
        }
        foreach ($rules as $r) {
            if (!preg_match('/^[1-9][0-9]{2,6}$/', $r)) {
                self::Say('error', 'Not saved: %s is not a rule number.', array($r));
                return;
            }
        }
        ph::Set('ms:skipdomains', implode(',', array_unique($domains)));
        ph::Set('ms:skiprules', implode(',', $rules));
        self::Say('ok', 'Saved. The daemon applies it on its next run (within a few minutes).');
    }

    /**
     * Empty the list of what was caught.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doClear()
    {
        global $zdbh;
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        ph::Ensure();
        $zdbh->exec("DELETE FROM x_ph_caught");
        self::Say('ok', 'The list is empty.');
    }

    /**
     * <@ Page @>.
     *
     * @return string HTML.
     */
    static function getPage()
    {
        static $page = null;
        if ($page !== null) {
            return $page;
        }
        return $page = self::PageHtml();
    }

    /**
     * The page.
     *
     * @return string
     */
    static function PageHtml()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= '<div class="ph-msg ' . ($m[0] === 'ok' ? 'is-ok' : 'is-bad') . '" role="' . ($m[0] === 'ok' ? 'status' : 'alert') . '">' . self::h($m[1]) . '</div>';
        }
        if (!self::Admin()) {
            return $out . '<div class="ph-card"><p>' . self::t('Only administrators can use Web Hardening.') . '</p></div>';
        }
        return $out . self::Intro() . self::ModSecCard() . self::VersionsCard() . self::RulesCard() . self::ExemptCard() . self::CaughtCard();
    }

    /**
     * What it is.
     *
     * @return string
     */
    static function Intro()
    {
        return '<div class="ph-card ph-intro"><p>' . self::t('Two guards for the websites on this server. ModSecurity sits in Apache and turns away requests that look like attacks (SQL injection, cross-site scripting, scanners, known exploits) before PHP sees them. Snuffleupagus sits inside PHP and stops what an attack does once it gets in (shell commands from uploaded scripts, mail() tricks, ways out of open_basedir).') . '</p>'
            . '<p>' . self::t('Start each in Watch only: nothing is blocked, and what would have been is listed at the bottom. Switch to On when the list shows nothing your sites need.') . '</p>'
            . '<p class="ph-muted">' . self::t('Changes are applied by the daemon on its next run (within a few minutes). Apache and PHP are tried with the new settings first; if they do not start, nothing is changed and the reason is shown here.') . '</p></div>';
    }

    /**
     * ModSecurity: mode, level, threshold, and what it leaves alone.
     *
     * @return string
     */
    static function ModSecCard()
    {
        $mode = ph_modsec::Mode();
        $st = ph::Status(ph_modsec::KEY);
        $sel = function ($name, array $opts, $cur) {
            $o = '<select name="' . $name . '">';
            foreach ($opts as $k => $label) {
                $o .= '<option value="' . self::h($k) . '"' . ((string) $k === (string) $cur ? ' selected' : '') . '>' . self::t($label) . '</option>';
            }
            return $o . '</select>';
        };
        $out = '<div class="ph-card"><h3>' . self::t('ModSecurity (in Apache)') . ' ' . self::Pill($st, $mode) . '</h3>'
            . ($st['st_sp_vc'] !== '' ? '<p class="ph-muted">' . self::h($st['st_sp_vc']) . ((int) $st['st_checked_in'] ? ', ' . self::t('checked %s', array(date('Y-m-d H:i', (int) $st['st_checked_in']))) : '') . '</p>' : '')
            . '<form method="post" action="./?module=php_hardening&amp;action=ModSec">' . runtime_csfr::Token()
            . '<table class="ph-table ph-form"><tbody>'
            . '<tr><th>' . self::t('ModSecurity') . '</th><td>' . $sel('inMsMode', array('off' => 'Off', 'watch' => 'Watch only', 'on' => 'On'), $mode) . '</td></tr>'
            . '<tr><th>' . self::t('How strict') . '</th><td>' . $sel('inMsLevel', array(1 => 'Level 1: the usual (few false alarms)', 2 => 'Level 2: stricter (some false alarms)', 3 => 'Level 3: strictest (many false alarms; for sites that need it)'), ph_modsec::Level()) . '</td></tr>'
            . '<tr><th>' . self::t('Stop a request at score') . '</th><td>' . $sel('inMsThreshold', array(5 => '5: one serious match (the rule set\'s own)', 10 => '10: two serious matches', 20 => '20: four serious matches (the most tolerant)'), ph_modsec::Threshold()) . '</td></tr>'
            . '</tbody></table><p><button class="btn btn-primary btn-sm" type="submit">' . self::t('Save') . '</button></p></form>'
            . '<form method="post" action="./?module=php_hardening&amp;action=ModSecSkip">' . runtime_csfr::Token()
            . '<p><label for="ph-msd">' . self::t('Domains left alone') . '</label> <span class="ph-muted">' . self::t('(with or without www.; the panel itself is always only watched, never blocked, so a false alarm can not lock you out)') . '</span><br>'
            . '<input class="form-control ph-wide" id="ph-msd" type="text" name="inMsDomains" maxlength="4000" value="' . self::h(implode(', ', ph_modsec::SkipDomains())) . '"></p>'
            . '<p><label for="ph-msr">' . self::t('Rules switched off') . '</label> <span class="ph-muted">' . self::t('(their numbers, as the list below shows them)') . '</span><br>'
            . '<input class="form-control ph-wide" id="ph-msr" type="text" name="inMsRules" maxlength="2000" value="' . self::h(implode(', ', ph_modsec::SkipRules())) . '"></p>'
            . '<p><button class="btn btn-primary btn-sm" type="submit">' . self::t('Save') . '</button></p></form></div>';
        return $out;
    }

    /**
     * A state pill (with the problem, when there is one).
     *
     * @param array $st Status row.
     * @param string $mode What was chosen.
     * @return string
     */
    static function Pill(array $st, $mode)
    {
        $state = $st['st_state_vc'];
        if ($state === 'problem') {
            return '<span class="ph-pill is-bad">' . self::t('Problem') . '</span> <span class="ph-note">' . self::h(ui_language::translate((string) $st['st_note_tx'])) . '</span>';
        }
        if ($state !== $mode) {
            return '<span class="ph-pill is-wait">' . self::t('Waiting for the daemon') . '</span>';
        }
        if ($state === 'on') {
            return '<span class="ph-pill is-ok">' . self::t('On') . '</span>';
        }
        if ($state === 'watch') {
            return '<span class="ph-pill is-watch">' . self::t('Watching') . '</span>';
        }
        return '<span class="ph-pill">' . self::t('Off') . '</span>';
    }

    /**
     * The PHP versions, each with its mode and state.
     *
     * @return string
     */
    static function VersionsCard()
    {
        $rows = '';
        foreach (ph::Versions() as $v => $kind) {
            $mode = ph::Mode($v);
            $st = ph::Status($v);
            $sel = '<select name="inMode[' . self::h($v) . ']" aria-label="' . self::t('PHP %s', array($v)) . '">';
            foreach (array('off' => 'Off', 'watch' => 'Watch only', 'on' => 'On') as $k => $label) {
                $sel .= '<option value="' . $k . '"' . ($k === $mode ? ' selected' : '') . '>' . self::t($label) . '</option>';
            }
            $sel .= '</select>';
            $pill = self::Pill($st, $mode);
            $rows .= '<tr><td><strong>PHP ' . self::h($v) . '</strong><br><span class="ph-muted">' . self::t($kind === 'system' ? 'the system\'s PHP (the panel and sites without a choice)' : 'extra version') . '</span></td>'
                . '<td>' . $sel . '</td><td>' . $pill . '</td><td class="ph-muted">' . ($st['st_sp_vc'] !== '' ? self::h($st['st_sp_vc']) : '–') . '</td>'
                . '<td class="ph-muted">' . ((int) $st['st_checked_in'] ? self::h(date('Y-m-d H:i', (int) $st['st_checked_in'])) : '–') . '</td></tr>';
        }
        return '<div class="ph-card"><h3>' . self::t('Snuffleupagus (in PHP): each PHP version') . '</h3><form method="post" action="./?module=php_hardening&amp;action=Modes">' . runtime_csfr::Token()
            . '<table class="ph-table"><thead><tr><th>' . self::t('PHP') . '</th><th>' . self::t('Hardening') . '</th><th>' . self::t('State') . '</th><th>' . self::t('Snuffleupagus') . '</th><th>' . self::t('Checked') . '</th></tr></thead><tbody>'
            . $rows . '</tbody></table><p><button class="btn btn-primary btn-sm" type="submit">' . self::t('Save') . '</button></p></form></div>';
    }

    /**
     * The rules to choose from.
     *
     * @return string
     */
    static function RulesCard()
    {
        $chosen = array_flip(ph::Chosen());
        $items = '';
        foreach (ph::Catalog() as $id => $r) {
            $items .= '<label class="ph-rule"><input type="checkbox" name="inRule[' . self::h($id) . ']" value="1"' . (isset($chosen[$id]) ? ' checked' : '') . '> '
                . '<span><strong>' . self::t($r['title']) . '</strong>' . ($r['default'] ? '' : ' <span class="ph-pill is-watch">' . self::t('try in watch only first') . '</span>')
                . '<br><span class="ph-muted">' . self::t($r['desc']) . ($r['watch'] ? '' : ' ' . self::t('(Always on when hardening is on; not part of watch only.)')) . '</span></span></label>';
        }
        $preview = ph::RulesFile('on', array_keys($chosen), ph::Allowed());
        return '<div class="ph-card"><h3>' . self::t('Snuffleupagus rules') . '</h3><form method="post" action="./?module=php_hardening&amp;action=Rules">' . runtime_csfr::Token()
            . $items . '<p><button class="btn btn-primary btn-sm" type="submit">' . self::t('Save rules') . '</button></p></form>'
            . '<details><summary class="ph-muted">' . self::t('The rules file these make') . '</summary><pre class="ph-pre">' . self::h($preview) . '</pre></details>'
            . '<p class="ph-muted">' . self::t('The panel\'s own scripts are always left alone.') . '</p></div>';
    }

    /**
     * The accounts the blocking rules leave alone.
     *
     * @return string
     */
    static function ExemptCard()
    {
        return '<div class="ph-card"><h3>' . self::t('Snuffleupagus: accounts left alone') . '</h3>'
            . '<p class="ph-muted">' . self::t('Scripts in these accounts\' folders are not blocked from calling functions (the safer random numbers, XML and cookie rules still apply). For a site that really needs something a rule blocks; list the user names, separated by spaces or commas.') . '</p>'
            . '<form method="post" action="./?module=php_hardening&amp;action=Exempt">' . runtime_csfr::Token()
            . '<input class="form-control ph-wide" type="text" name="inExempt" maxlength="2000" value="' . self::h(implode(', ', ph::Exempt())) . '" aria-label="' . self::t('Accounts left alone') . '">'
            . '<p><button class="btn btn-primary btn-sm" type="submit">' . self::t('Save') . '</button></p></form></div>';
    }

    /**
     * One caught line as a table row.
     *
     * @param array $c
     * @return string
     */
    static function Row(array $c)
    {
        $blocked = $c['ca_action_vc'] === 'drop';
        $kinds = ph::Kinds();
        $kind = isset($c['ca_kind_vc']) && isset($kinds[$c['ca_kind_vc']]) ? $kinds[$c['ca_kind_vc']] : '';
        return '<tr data-id="' . (int) $c['ca_id_pk'] . '"><td class="ph-nowrap">' . self::h(date('Y-m-d H:i:s', (int) $c['ca_time_in'])) . '</td>'
            . '<td>' . ($c['ca_account_vc'] !== '' ? self::h($c['ca_account_vc']) : '<span class="ph-muted">–</span>') . '</td>'
            . '<td><span class="ph-pill ' . ($blocked ? 'is-bad' : 'is-watch') . '">' . self::t($blocked ? 'Blocked' : 'Would block') . '</span><br><span class="ph-muted">' . self::t($c['ca_feature_vc'] === 'modsecurity' ? 'ModSecurity' : 'PHP') . '</span></td>'
            . '<td>' . ($kind !== '' ? '<b class="ph-kind">' . self::t($kind) . '</b><br>' : '') . self::h($c['ca_message_tx'])
            . '<br><span class="ph-muted ph-file">' . self::h($c['ca_file_tx']) . ((int) $c['ca_line_in'] ? ':' . (int) $c['ca_line_in'] : '') . '</span></td>'
            . '<td class="ph-muted">' . self::h($c['ca_ip_vc']) . '</td></tr>';
    }

    /**
     * When the server last read the guards' logs, in words.
     *
     * @return string Escaped.
     */
    static function LookedText()
    {
        $at = (int) ph::Get('read_at');
        return $at > 0 ? self::t('The server last looked at %s. It looks every 5 minutes, or at once when you press "Look now".', array(date('H:i:s', $at)))
            : self::t('The server has not looked yet: it looks every 5 minutes, or at once when you press "Look now".');
    }

    /**
     * What it caught: the list (search, filters, older pages, new lines added by themselves) and
     * the statistics. assets/ph.js asks code/caught.php (module_controller::Feed) for both.
     *
     * @return string
     */
    static function CaughtCard()
    {
        $page = ph::Find(array());
        $rows = '';
        $oldest = 0;
        foreach ($page['rows'] as $c) {
            $rows .= self::Row($c);
            $oldest = (int) $c['ca_id_pk'];
        }
        $kindOpts = '<option value="">' . self::t('Every kind of attack') . '</option>';
        foreach (ph::Kinds() as $k => $label) {
            $kindOpts .= '<option value="' . self::h($k) . '">' . self::t($label) . '</option>';
        }
        $data = array('feed' => 'modules/php_hardening/code/caught.php', 'last' => ph::NewestId(), 'oldest' => $oldest, 'more' => $page['more'] ? '1' : '0', 'count' => (int) $page['total'],
            'paused' => self::t('Paused'), 'live' => self::t('Live'), 'err' => self::t('Can not reach the panel; trying again'),
            'shown' => self::t('Showing %s of %s', array('{n}', '{t}')), 'none' => self::t('Nothing found.'), 'loading' => self::t('Loading...'),
            'total' => self::t('Caught'), 'drop' => self::t('Blocked'), 'watch' => self::t('Would block'), 'sources' => self::t('Different sources'),
            'other' => self::t('Other'), 'empty' => self::t('Nothing caught in this time.'));
        $attrs = '';
        foreach ($data as $k => $v) {
            $attrs .= ' data-' . $k . '="' . (is_int($v) ? $v : $v) . '"';
        }
        $range = '';
        foreach (array(1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => 'A year') as $d => $label) {
            $range .= '<button type="button" class="btn btn-default btn-xs' . ($d === 30 ? ' active' : '') . '" data-days="' . $d . '">' . self::t($label) . '</button>';
        }
        return '<div class="ph-card ph-live" id="ph-caught"' . $attrs . '>'
            . '<div class="ph-live-head"><h3>' . self::t('What it caught') . '</h3>'
            . '<div class="ph-live-tools"><span class="ph-live-dot" role="status"><i></i><span class="ph-live-text">' . self::t('Live') . '</span></span>'
            . '<button type="button" class="btn btn-default btn-xs ph-pause" data-pause="' . self::t('Pause') . '" data-resume="' . self::t('Resume') . '">' . self::t('Pause') . '</button>'
            . '<form class="ph-inline" method="post" action="./?module=php_hardening&amp;action=LookNow#ph-caught">' . runtime_csfr::Token() . '<button class="btn btn-primary btn-xs" type="submit">' . self::t('Look now') . '</button></form></div></div>'
            . '<p class="ph-muted ph-looked">' . self::LookedText() . ' ' . self::t('New lines appear here by themselves while this page is open.') . '</p>'
            . '<div class="ph-tabs" role="tablist"><button type="button" role="tab" class="on" data-tab="list" aria-selected="true">' . self::t('List') . '</button>'
            . '<button type="button" role="tab" data-tab="stats" aria-selected="false">' . self::t('Statistics') . '</button></div>'
            // the list
            . '<div class="ph-pane" data-pane="list"><div class="ph-filters">'
            . '<input type="search" class="form-control ph-q" maxlength="100" placeholder="' . self::t('Search: an address, a site, a rule number, words...') . '" aria-label="' . self::t('Search') . '">'
            . '<select class="form-control ph-f-kind" aria-label="' . self::t('Kind of attack') . '">' . $kindOpts . '</select>'
            . '<select class="form-control ph-f-act" aria-label="' . self::t('Blocked or not') . '"><option value="">' . self::t('Blocked and would block') . '</option><option value="drop">' . self::t('Blocked only') . '</option><option value="watch">' . self::t('Would block only') . '</option></select>'
            . '<select class="form-control ph-f-guard" aria-label="' . self::t('Guard') . '"><option value="">' . self::t('Both guards') . '</option><option value="modsecurity">ModSecurity</option><option value="php">' . self::t('PHP (Snuffleupagus)') . '</option></select>'
            . '</div><p class="ph-muted ph-count">' . self::t('Showing %s of %s', array(count($page['rows']), $page['total'])) . '</p>'
            . '<div class="ph-scroll"><table class="ph-table"><thead><tr><th>' . self::t('When') . '</th><th>' . self::t('Account') . '</th><th></th><th>' . self::t('What') . '</th><th>' . self::t('From') . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<p class="ph-muted ph-empty"' . ($rows !== '' ? ' hidden' : '') . '>' . self::t('Nothing yet.') . '</p>'
            . '<p class="ph-more-wrap"><button type="button" class="btn btn-default btn-sm ph-more"' . ($page['more'] ? '' : ' hidden') . '>' . self::t('Show older lines') . '</button></p></div>'
            . '<form method="post" action="./?module=php_hardening&amp;action=Clear" onsubmit="return confirm(this.dataset.ask)" data-ask="' . self::t('Empty the list? (The statistics are kept.)') . '">' . runtime_csfr::Token()
            . '<p><button class="btn btn-default btn-sm" type="submit">' . self::t('Empty the list') . '</button> <span class="ph-muted">' . self::t('The newest %s lines are kept; the statistics count every day for a year.', array(ph::KEEP)) . '</span></p></form></div>'
            // the statistics
            . '<div class="ph-pane" data-pane="stats" hidden><div class="ph-range" role="group" aria-label="' . self::t('Time') . '">' . $range . '</div>'
            . '<div class="ph-tiles"></div>'
            . '<div class="ph-charts"><section class="ph-chart ph-wide-chart"><h4>' . self::t('Each day, by kind of attack') . '</h4><div class="ph-c-days"></div></section>'
            . '<section class="ph-chart"><h4>' . self::t('Kinds of attack') . '</h4><div class="ph-c-kinds"></div></section>'
            . '<section class="ph-chart"><h4>' . self::t('The last 24 hours') . '</h4><div class="ph-c-hours"></div></section>'
            . '<section class="ph-chart"><h4>' . self::t('Where they came from') . '</h4><div class="ph-c-ips"></div></section>'
            . '<section class="ph-chart"><h4>' . self::t('What they went after') . '</h4><div class="ph-c-targets"></div></section></div>'
            . '<p class="ph-muted">' . self::t('Clicking a kind, an address or a site shows its lines in the list.') . '</p></div></div>';
    }

    /**
     * The answer for assets/ph.js (code/caught.php): a page of the list, new lines, or the statistics.
     *
     * @param array $p GET: after, before, q, kind, act, guard; or stats=1 and days.
     * @return array
     */
    static function Feed(array $p)
    {
        $s = function ($k) use ($p) {
            return isset($p[$k]) && is_string($p[$k]) ? $p[$k] : '';
        };
        $n = function ($k) use ($s) {
            return preg_match('/^\d{1,10}$/', $s($k)) ? (int) $s($k) : 0;
        };
        $looked = html_entity_decode(self::LookedText(), ENT_QUOTES, 'UTF-8') . ' ' . ui_language::translate('New lines appear here by themselves while this page is open.');
        if ($s('stats') === '1') {
            $st = ph::Stats(max(1, min(ph::DAYS, $n('days') ?: 30)));
            $labels = array();
            foreach (ph::Kinds() as $k => $l) {
                $labels[$k] = ui_language::translate($l);
            }
            $kinds = array();
            foreach ($st['kinds'] as $k => $c) {
                $kinds[] = array('key' => (string) $k, 'label' => isset($labels[$k]) ? $labels[$k] : (string) $k, 'n' => (int) $c);
            }
            $days = array();
            foreach ($st['days'] as $d => $by) {
                $days[] = array('day' => $d, 'by' => (object) array_map('intval', $by));
            }
            $pairs = function (array $a) {
                $o = array();
                foreach ($a as $k => $v) {
                    $o[] = array('label' => (string) $k, 'n' => (int) $v);
                }
                return $o;
            };
            $hours = $pairs($st['hours']);
            return array('stats' => array('kinds' => $kinds, 'days' => $days, 'drop' => $st['drop'], 'watch' => $st['watch'], 'sources' => $st['sources'],
                'ips' => $pairs($st['ips']), 'targets' => $pairs($st['targets']), 'hours' => $hours), 'looked' => $looked);
        }
        $f = array('q' => $s('q'), 'kind' => $s('kind'), 'act' => $s('act'), 'guard' => $s('guard'));
        $page = ph::Find($f, $n('before'), $n('after'));
        $rows = '';
        $last = $n('after');
        $oldest = 0;
        foreach ($page['rows'] as $c) {
            $rows .= self::Row($c);
            $last = max($last, (int) $c['ca_id_pk']);
            $oldest = (int) $c['ca_id_pk'];
        }
        return array('rows' => $rows, 'count' => count($page['rows']), 'last' => $last, 'oldest' => $oldest, 'more' => $page['more'], 'total' => $page['total'], 'looked' => $looked);
    }

    /**
     * "Look now": the daemon runs at once (zsudo daemon-run, the same as "Run the daemon now" in
     * the panel's settings), at most once a minute.
     *
     * @return void
     */
    static function doLookNow()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $last = (int) ph::Get('looknow_at');
        if ($last > time() - 60) {
            self::Say('ok', 'The server is looking already (at most once a minute). New lines show in the list by themselves.');
            return;
        }
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        $rc = 1;
        if ($zsudo !== '' && is_executable($zsudo) && function_exists('exec')) {
            $out = array();
            exec(escapeshellcmd($zsudo) . ' daemon-run 2>&1', $out, $rc);
        }
        if ((int) $rc === 0) {
            ph::Set('looknow_at', (string) time());
            self::Say('ok', 'The server is looking now. New lines show in the list by themselves within a minute.');
        } else {
            self::Say('error', 'The server could not be asked to look now (its root actions are not set up: run sentora-update on the server). It still looks every 5 minutes.');
        }
    }

    /**
     * Nothing to set up before the page.
     *
     * @return void
     */
    static function getInit()
    {
    }
}
