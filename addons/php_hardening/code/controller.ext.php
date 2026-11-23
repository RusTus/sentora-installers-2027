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
require_once dirname(__FILE__) . '/bans.php';

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
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : '';
        $tabs = array('' => 'Web firewall and PHP', 'bans' => 'Bans', 'flood' => 'Flood protection');
        $tab = isset($tabs[$tab]) ? $tab : '';
        $bar = '<nav class="ph-tabs ph-pagetabs" aria-label="' . self::t('Web Hardening') . '">';
        foreach ($tabs as $k => $label) {
            $bar .= '<a href="./?module=' . self::Folder() . ($k !== '' ? '&amp;tab=' . $k : '') . '"' . ($k === $tab ? ' class="on" aria-current="page"' : '') . '>' . self::t($label) . '</a>';
        }
        $bar .= '</nav>';
        if ($tab === 'bans') {
            return $out . $bar . self::BansPage();
        }
        if ($tab === 'flood') {
            return $out . $bar . self::FloodPage();
        }
        return $out . $bar . self::Intro() . self::ModSecCard() . self::VersionsCard() . self::RulesCard() . self::ExemptCard() . self::CaughtCard();
    }

    /**
     * The module's folder (it was php_hardening before its name changed; links follow where it is).
     *
     * @return string
     */
    static function Folder()
    {
        return rawurlencode(basename(dirname(dirname(__FILE__))));
    }

    // ----- bans and flood protection -----

    /**
     * The daemon runs now (zsudo daemon-run), at most once every 10 seconds.
     *
     * @return bool
     */
    static function RunNow()
    {
        if ((int) ph::Get('bans_run_at') > time() - 10) {
            return true;
        }
        $zsudo = (string) ctrl_options::GetSystemOption('zsudo');
        $rc = 1;
        if ($zsudo !== '' && is_executable($zsudo) && function_exists('exec')) {
            $out = array();
            exec(escapeshellcmd($zsudo) . ' daemon-run 2>&1', $out, $rc);
        }
        if ((int) $rc === 0) {
            ph::Set('bans_run_at', (string) time());
            return true;
        }
        return false;
    }

    /**
     * Said after a change: done now, or on the daemon's next run.
     *
     * @param string $what English.
     * @param array $args
     * @return void
     */
    static function Done($what, array $args = array())
    {
        self::Say('ok', $what . (self::RunNow() ? ' The server does it now; this page shows it within a few seconds.' : ' The server does it on its next run (within 5 minutes).'), $args);
    }

    /**
     * The address of the browser in use now ('' when it is not known).
     *
     * @return string
     */
    static function MyIp()
    {
        return ph_bans::Ip(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
    }

    /**
     * Why an address may not be banned ('' when it may).
     *
     * @param string $ip
     * @return string English.
     */
    static function NotBannable($ip)
    {
        if ($ip === self::MyIp()) {
            return 'That is your own address now: banning it would shut you out of the panel.';
        }
        foreach (ph_bans::OwnIgnored() as $n) {
            if (ph_bans::InNet($ip, $n)) {
                return 'That is this server\'s own address.';
            }
        }
        foreach (ph_bans::Never() as $n) {
            if (ph_bans::InNet($ip, $n)) {
                return 'That address is on the never-ban list (%s).';
            }
        }
        return '';
    }

    /**
     * Ban an address by hand.
     *
     * @return void
     */
    static function doBan()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $ip = ph_bans::Ip(self::Post('inBanIp'));
        if ($ip === '') {
            self::Say('error', 'Type one address (like 203.0.113.7 or 2001:db8::7).');
            return;
        }
        $why = self::NotBannable($ip);
        if ($why !== '') {
            self::Say('error', $why, array($ip));
            return;
        }
        $hours = (int) self::Post('inBanHours');
        if (!in_array($hours, array(0, 1, 24, 168, 720), true)) {
            $hours = 24;
        }
        $me = ctrl_users::GetUserDetail();
        if (!ph_bans::Ask('ban', $ip, $hours, self::Post('inBanWhy'), (string) $me['username'])) {
            self::Say('error', 'Too much is waiting for the server already; try again in a minute.');
            return;
        }
        self::Done('%s will be banned.', array($ip));
    }

    /**
     * Unban an address (from every jail).
     *
     * @return void
     */
    static function doUnban()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $ip = ph_bans::Ip(self::Post('inUnban'));
        if ($ip === '') {
            self::Say('error', 'That is not an address.');
            return;
        }
        $me = ctrl_users::GetUserDetail();
        if (!ph_bans::Ask('unban', $ip, 0, '', (string) $me['username'])) {
            self::Say('error', 'Too much is waiting for the server already; try again in a minute.');
            return;
        }
        self::Done('%s will be unbanned.', array($ip));
    }

    /**
     * Save the never-ban list.
     *
     * @return void
     */
    static function doNever()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $good = array();
        $bad = array();
        foreach (preg_split('/[\s,]+/', self::Post('inNever')) as $w) {
            if ($w === '') {
                continue;
            }
            $n = ph_bans::Net($w);
            if ($n === '') {
                $bad[] = ph_bans::Line($w, 50);
            } elseif (!in_array($n, $good, true)) {
                $good[] = $n;
            }
        }
        if ($bad) {
            self::Say('error', 'Not saved: these are not addresses or networks: %s', array(implode(', ', array_slice($bad, 0, 5))));
            return;
        }
        if (count($good) > 200) {
            self::Say('error', 'At most 200 addresses or networks.');
            return;
        }
        ph::Set('ban_never', implode("\n", $good));
        self::Done('Saved: %s address(es) or network(s) are never banned.', array(count($good)));
    }

    /**
     * Save flood protection.
     *
     * @return void
     */
    static function doFlood()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $on = self::Post('inFloodOn') === '1';
        $limit = (int) self::Post('inFloodLimit');
        $minutes = (int) self::Post('inFloodMinutes');
        if ($limit < 30 || $limit > 100000) {
            self::Say('error', 'The limit: between 30 and 100000 requests a minute.');
            return;
        }
        if (!in_array($minutes, array(10, 60, 360, 1440, 10080), true)) {
            $minutes = 60;
        }
        ph::Set('flood_on', $on ? '1' : '0');
        ph::Set('flood_limit', (string) $limit);
        ph::Set('flood_minutes', (string) $minutes);
        self::Done($on ? 'Flood protection is on: more than %s requests a minute from one address is a ban.' : 'Flood protection is off.', array($limit));
    }

    /**
     * Look again now (who is banned).
     *
     * @return void
     */
    static function doBansLook()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        if (self::RunNow()) {
            self::Say('ok', 'The server looks now; this page shows it within a few seconds.');
        } else {
            self::Say('error', 'The server could not be asked to look now (its root actions are not set up: run sentora-update on the server). It still looks every 5 minutes.');
        }
    }

    /**
     * A time as "2026-11-23 10:40", or a dash.
     *
     * @param int $t
     * @return string
     */
    static function When($t)
    {
        return (int) $t > 0 ? self::h(date('Y-m-d H:i', (int) $t)) : '&ndash;';
    }

    /**
     * How long ago, in words.
     *
     * @param int $t
     * @return string
     */
    static function Ago($t)
    {
        $d = time() - (int) $t;
        if ((int) $t <= 0) {
            return self::t('never');
        }
        return $d < 90 ? self::t('just now') : ($d < 5400 ? self::t('%s minutes ago', array((int) round($d / 60))) : self::t('%s hours ago', array((int) round($d / 3600))));
    }

    /**
     * The state line: fail2ban running, when it was last looked at, Look now.
     *
     * @param array $st
     * @return string
     */
    static function BansStatus(array $st)
    {
        $wait = ph_bans::Asked() ? ' data-ph-wait="1"' : '';
        $pill = $st['at'] === 0 ? '<span class="ph-pill is-wait">' . self::t('not looked yet') . '</span>'
            : ($st['running'] ? '<span class="ph-pill is-ok">' . self::t('fail2ban running') . '</span>' : '<span class="ph-pill is-bad">' . self::t('fail2ban not running') . '</span>');
        return '<div class="ph-card ph-live-head"' . $wait . '><div>' . $pill . ' <span class="ph-muted">' . self::t('Looked %s', array(self::Ago($st['at'])))
            . ($st['version'] !== '' ? ' &middot; fail2ban ' . self::h($st['version']) : '') . '</span>'
            . ($st['problem'] !== '' ? '<div class="ph-note">' . self::h(ui_language::translate($st['problem'])) . '</div>' : '') . '</div>'
            . '<form method="post" class="ph-inline" action="./?module=' . self::Folder() . '&amp;tab=' . (isset($_GET['tab']) && $_GET['tab'] === 'flood' ? 'flood' : 'bans') . '&amp;action=BansLook">' . runtime_csfr::Token()
            . '<button class="btn btn-default btn-sm" type="submit">' . self::t('Look now') . '</button></form></div>';
    }

    /**
     * The Bans tab.
     *
     * @return string
     */
    static function BansPage()
    {
        $st = ph_bans::State();
        $names = ph_bans::Names();
        $manual = ph_bans::Manual();
        $rows = array();
        $byIp = array();
        foreach ($st['jails'] as $jail => $list) {
            foreach ($list as $b) {
                $rows[] = array('jail' => (string) $jail) + $b;
                $byIp[$b['ip']] = true;
            }
        }
        usort($rows, function ($a, $b) { return $b['since'] <=> $a['since']; });
        $flood = count(isset($st['jails'][ph_bans::FLOOD]) ? $st['jails'][ph_bans::FLOOD] : array());
        $tiles = '<div class="ph-tiles">'
            . '<div class="ph-tile' . ($rows ? ' is-bad' : '') . '"><b>' . count($byIp) . '</b><span>' . self::t('addresses banned now') . '</span></div>'
            . '<div class="ph-tile"><b>' . count($st['jails']) . '</b><span>' . self::t('jails watching') . '</span></div>'
            . '<div class="ph-tile' . ($flood ? ' is-watch' : '') . '"><b>' . $flood . '</b><span>' . self::t('for too many requests') . '</span></div>'
            . '<div class="ph-tile"><b>' . count(ph_bans::Never()) . '</b><span>' . self::t('never banned') . '</span></div></div>';
        $asked = '';
        foreach (ph_bans::Asked() as $a) {
            $asked .= '<li><span class="ph-pill is-wait">' . self::t('waiting for the server') . '</span> ' . self::t($a['do'] === 'unban' ? 'Unban %s' : 'Ban %s', array($a['ip'])) . '</li>';
        }
        $table = '';
        foreach ($rows as $r) {
            $m = $r['jail'] === ph_bans::MANUAL && isset($manual[$r['ip']]) ? $manual[$r['ip']] : null;
            $until = $m !== null ? (int) $m['until'] : (int) $r['until'];
            $table .= '<tr><td class="ph-nowrap" data-label="' . self::t('Address') . '"><b>' . self::h($r['ip']) . '</b></td><td data-label="' . self::t('Why') . '"><div>' . self::t(isset($names[$r['jail']]) ? $names[$r['jail']] : $r['jail'])
                . ($m !== null && $m['why'] !== '' ? '<div class="ph-muted">' . self::h($m['why']) . ($m['by'] !== '' ? ' &middot; ' . self::t('by %s', array($m['by'])) : '') . '</div>' : '') . '</div></td>'
                . '<td class="ph-nowrap" data-label="' . self::t('Since') . '">' . self::When($r['since']) . '</td><td class="ph-nowrap" data-label="' . self::t('Until') . '">' . ($until > 0 ? self::When($until) : self::t('until unbanned')) . '</td>'
                . '<td class="ph-unban"><form method="post" class="ph-inline" action="./?module=' . self::Folder() . '&amp;tab=bans&amp;action=Unban">' . runtime_csfr::Token()
                . '<input type="hidden" name="inUnban" value="' . self::h($r['ip']) . '"><button class="btn btn-default btn-xs" type="submit">' . self::t('Unban') . '</button></form></td></tr>';
        }
        $list = '<div class="ph-card"><h3>' . self::t('Banned now') . '</h3>'
            . ($asked !== '' ? '<ul class="ph-asked">' . $asked . '</ul>' : '')
            . ($table !== '' ? '<div class="ph-scroll ph-bans-box"><table class="ph-table ph-bans"><thead><tr><th>' . self::t('Address') . '</th><th>' . self::t('Why') . '</th><th>' . self::t('Since') . '</th><th>' . self::t('Until') . '</th><th></th></tr></thead><tbody>' . $table . '</tbody></table></div>'
                : '<p class="ph-muted">' . self::t($st['at'] ? 'Nobody is banned right now.' : 'The server has not looked yet: Look now, or wait for its next run.') . '</p>')
            . '<p class="ph-muted">' . self::t('Addresses are banned by fail2ban when they keep failing to log in (SSH, mail, FTP, webmail, the panel, phpMyAdmin), send too many requests (Flood protection), or are banned here by hand. Unban takes an address out of every jail.') . '</p></div>';
        $mine = self::MyIp();
        $ban = '<div class="ph-card"><h3>' . self::t('Ban an address') . '</h3>'
            . '<form method="post" class="ph-banform" action="./?module=' . self::Folder() . '&amp;tab=bans&amp;action=Ban">' . runtime_csfr::Token()
            . '<input class="form-control" type="text" name="inBanIp" maxlength="45" placeholder="' . self::t('Address (203.0.113.7)') . '" aria-label="' . self::t('Address') . '" required>'
            . '<select class="form-control" name="inBanHours" aria-label="' . self::t('For how long') . '"><option value="1">' . self::t('for an hour') . '</option><option value="24" selected>' . self::t('for a day') . '</option>'
            . '<option value="168">' . self::t('for a week') . '</option><option value="720">' . self::t('for 30 days') . '</option><option value="0">' . self::t('until unbanned') . '</option></select>'
            . '<input class="form-control" type="text" name="inBanWhy" maxlength="200" placeholder="' . self::t('Why (optional)') . '" aria-label="' . self::t('Why') . '">'
            . '<button class="btn btn-danger btn-sm" type="submit">' . self::t('Ban') . '</button></form>'
            . '<p class="ph-muted">' . self::t('Banned from every port of this server: websites, mail, FTP and SSH.') . '</p></div>';
        $never = '<div class="ph-card"><h3>' . self::t('Never ban') . '</h3>'
            . '<form method="post" action="./?module=' . self::Folder() . '&amp;tab=bans&amp;action=Never">' . runtime_csfr::Token()
            . '<textarea class="form-control ph-never" name="inNever" rows="4" aria-label="' . self::t('Never ban') . '" placeholder="203.0.113.7&#10;198.51.100.0/24">' . self::h(implode("\n", ph_bans::Never())) . '</textarea>'
            . '<p class="ph-muted">' . self::t('One address or network per line: your office, your monitoring service. No jail bans them, flood protection included. This server\'s own addresses are never banned anyway.')
            . ($mine !== '' ? ' ' . self::t('Your address now: %s', array($mine)) : '') . '</p>'
            . '<button class="btn btn-primary btn-sm" type="submit">' . self::t('Save') . '</button></form></div>';
        return self::BansStatus($st) . $tiles . $list . '<div class="ph-two">' . $ban . $never . '</div>';
    }

    /**
     * The Flood protection tab.
     *
     * @return string
     */
    static function FloodPage()
    {
        $st = ph_bans::State();
        $f = ph_bans::Flood();
        $opt = function ($v, $label, $cur) { return '<option value="' . (int) $v . '"' . ((int) $v === (int) $cur ? ' selected' : '') . '>' . self::t($label) . '</option>'; };
        $mine = self::MyIp();
        $mineKept = $mine === '';
        foreach (array_merge(ph_bans::OwnIgnored(), ph_bans::Never()) as $n) {
            $mineKept = $mineKept || ph_bans::InNet($mine, $n);
        }
        $flood = '<div class="ph-card"><h3>' . self::t('Too many requests') . ' ' . ($f['on'] ? '<span class="ph-pill is-ok">' . self::t('On') . '</span>' : '<span class="ph-pill">' . self::t('Off') . '</span>') . '</h3>'
            . '<p class="ph-wide">' . self::t('One address sending more requests to the websites than a person could (a flood, a bad crawler, a script) is banned for a while, at the firewall, so Apache never sees it again until the ban ends. Pictures, styles, scripts and fonts are not counted: a page brings many of them.') . '</p>'
            . '<form method="post" action="./?module=' . self::Folder() . '&amp;tab=flood&amp;action=Flood">' . runtime_csfr::Token()
            . '<table class="ph-table ph-form ph-wide"><tbody>'
            . '<tr><th>' . self::t('Flood protection') . '</th><td><select class="form-control" name="inFloodOn"><option value="0"' . ($f['on'] ? '' : ' selected') . '>' . self::t('Off') . '</option><option value="1"' . ($f['on'] ? ' selected' : '') . '>' . self::t('On') . '</option></select></td></tr>'
            . '<tr><th>' . self::t('Requests a minute') . '</th><td><input class="form-control" type="number" name="inFloodLimit" min="30" max="100000" value="' . (int) $f['limit'] . '"><div class="ph-muted">' . self::t('From one address, before it is banned. 300 is about five pages a second, more than any person.') . '</div></td></tr>'
            . '<tr><th>' . self::t('Banned for') . '</th><td><select class="form-control" name="inFloodMinutes">' . $opt(10, '10 minutes', $f['minutes']) . $opt(60, 'an hour', $f['minutes']) . $opt(360, '6 hours', $f['minutes']) . $opt(1440, 'a day', $f['minutes']) . $opt(10080, 'a week', $f['minutes']) . '</select></td></tr>'
            . '</tbody></table><button class="btn btn-primary btn-sm" type="submit">' . self::t('Save') . '</button></form>'
            . ($f['on'] && $st['at'] > 0 ? '<p class="ph-muted">' . self::t('Watching %s website log(s).', array($st['flood_logs'])) . '</p>' : '')
            . (!$mineKept ? '<p class="ph-muted">' . self::t('Your own address (%s) is not on the never-ban list: add it on the Bans tab if you work on the sites heavily from here.', array($mine)) . '</p>' : '')
            . '</div>';
        $slow = '<div class="ph-card"><h3>' . self::t('Slow attacks') . ' ' . ($st['at'] === 0 ? '<span class="ph-pill is-wait">' . self::t('not looked yet') . '</span>' : ($st['reqtimeout'] ? '<span class="ph-pill is-ok">' . self::t('On') . '</span>' : '<span class="ph-pill is-bad">' . self::t('Off') . '</span>')) . '</h3>'
            . '<p class="ph-wide">' . self::t('Some attacks open many connections and send each request a few bytes at a time, to keep every Apache worker busy (Slowloris). Apache\'s request timeouts (mod_reqtimeout) close a connection that does not send its request within 20 to 40 seconds, or sends slower than 500 bytes a second.') . '</p>'
            . ($st['at'] > 0 && !$st['reqtimeout'] ? '<p class="ph-note">' . self::t('Apache does not have mod_reqtimeout loaded on this server: load it again (it comes with Apache).') . '</p>' : '')
            . '</div>';
        return self::BansStatus($st) . $flood . $slow;
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
