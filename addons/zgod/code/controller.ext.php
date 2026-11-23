<?php

/**
 * zGod (administrators): everything on the panel in one place, across every reseller. Overview (the
 * server's totals, the biggest users, quotas nearly full), Accounts (every account, with filters),
 * Resellers (their clients and totals), one account in full, and the daily report. It changes only
 * two things, the way the panel's own modules do: an account suspended or switched on again, a
 * mailbox switched off or on. Every change is a POST with the panel's form key, logged.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-23 (zGod for ZPanel, 2011, written again)
 */
require_once dirname(__FILE__) . '/zgod.php';

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
     * The logged-in account.
     *
     * @return array<string, mixed>
     */
    static function Me()
    {
        return ctrl_users::GetUserDetail();
    }

    /**
     * Whether the logged-in account is an administrator.
     *
     * @return bool
     */
    static function Admin()
    {
        $me = self::Me();
        return (int) $me['usergroupid'] === zgod::ADMINS;
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
     * A field of the address (GET).
     *
     * @param string $n
     * @return string
     */
    static function Q($n)
    {
        return isset($_GET[$n]) && is_string($_GET[$n]) ? $_GET[$n] : '';
    }

    /**
     * A link to one of zGod's pages.
     *
     * @param array $args
     * @return string Escaped for an attribute.
     */
    static function Link(array $args = array())
    {
        return self::h('./?' . http_build_query(array_merge(array('module' => 'zgod'), $args)));
    }

    /**
     * Suspend an account (the panel's own suspension: its websites show the suspended page).
     *
     * @return void
     */
    static function doSuspend()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $me = self::Me();
        $why = zgod::SetAccount((int) self::Post('inId'), false, (int) $me['userid'], trim(self::Post('inReason')));
        $why === '' ? self::Say('ok', 'Suspended. Its websites show the suspended page and it can not log in.') : self::Say('error', $why);
    }

    /**
     * Switch a suspended account on again.
     *
     * @return void
     */
    static function doUnsuspend()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $me = self::Me();
        $why = zgod::SetAccount((int) self::Post('inId'), true, (int) $me['userid']);
        $why === '' ? self::Say('ok', 'Switched on again.') : self::Say('error', $why);
    }

    /**
     * Switch a mailbox off or on.
     *
     * @return void
     */
    static function doMailbox()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $me = self::Me();
        $on = self::Post('inOn') === '1';
        $why = zgod::SetMailbox((int) self::Post('inMb'), $on, (int) $me['userid']);
        $why === '' ? self::Say('ok', $on ? 'Mailbox switched on.' : 'Mailbox switched off: it no longer receives mail or lets anyone log in.') : self::Say('error', $why);
    }

    /**
     * Save the daily report's settings.
     *
     * @return void
     */
    static function doReport()
    {
        runtime_csfr::Protect();
        if (!self::Admin()) {
            return;
        }
        $to = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', trim(self::Post('inTo'))))));
        foreach ($to as $a) {
            if (filter_var($a, FILTER_VALIDATE_EMAIL) === false || strlen($a) > 200) {
                self::Say('error', 'Not saved: %s is not an email address.', array($a));
                return;
            }
        }
        if (count($to) > 10) {
            self::Say('error', 'Not saved: ten addresses at most.');
            return;
        }
        zgod::Set('report', self::Post('inReport') === 'on' ? 'on' : 'off');
        zgod::Set('report_to', implode(', ', $to));
        self::Say('ok', zgod::Get('report') === 'on' ? 'Saved: the report goes out once a day.' : 'Saved: no daily report.');
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
            $out .= '<div class="zg-msg ' . ($m[0] === 'ok' ? 'is-ok' : 'is-bad') . '" role="' . ($m[0] === 'ok' ? 'status' : 'alert') . '">' . self::h($m[1]) . '</div>';
        }
        if (!self::Admin()) {
            return $out . '<div class="zg-card"><p>' . self::t('Only administrators can use zGod.') . '</p></div>';
        }
        $tab = self::Q('tab');
        $id = (int) self::Q('id');
        if ($tab === 'account' && $id > 0) {
            return $out . self::Tabs('accounts') . self::AccountPage($id);
        }
        if (!in_array($tab, array('overview', 'accounts', 'resellers', 'report'), true)) {
            $tab = 'overview';
        }
        $body = $tab === 'accounts' ? self::AccountsPage() : ($tab === 'resellers' ? self::ResellersPage() : ($tab === 'report' ? self::ReportPage() : self::Overview()));
        return $out . self::Tabs($tab) . $body;
    }

    /**
     * The tab bar, the open tab underlined.
     *
     * @param string $on
     * @return string
     */
    static function Tabs($on)
    {
        $out = '<nav class="zg-tabs" aria-label="' . self::t('zGod') . '">';
        foreach (array('overview' => 'Overview', 'accounts' => 'Accounts', 'resellers' => 'Resellers', 'report' => 'Daily report') as $k => $label) {
            $out .= '<a href="' . self::Link(array('tab' => $k)) . '"' . ($k === $on ? ' class="on" aria-current="page"' : '') . '>' . self::t($label) . '</a>';
        }
        return $out . '</nav>';
    }

    /**
     * An icon (line drawing in the text colour).
     *
     * @param string $d SVG inside.
     * @param string $cls
     * @return string
     */
    static function Ico($d, $cls = 'zg-ico-lg')
    {
        return '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
    }

    /**
     * A tile: a figure and what it is.
     *
     * @param string $ico
     * @param string $figure
     * @param string $what Escaped.
     * @param string $cls
     * @return string
     */
    static function Tile($ico, $figure, $what, $cls = '')
    {
        return '<div class="zg-tile ' . $cls . '">' . self::Ico($ico) . '<div><b>' . self::h($figure) . '</b><span>' . $what . '</span></div></div>';
    }

    /**
     * A usage bar against a quota.
     *
     * @param int $used
     * @param int $quota
     * @return string
     */
    static function Bar($used, $quota)
    {
        $s = zgod::Share($used, $quota);
        if ($s === null) {
            return '<div class="zg-use"><span class="zg-use-txt">' . self::h(zgod::Size($used)) . ' <i>' . self::t('of no limit') . '</i></span></div>';
        }
        $pct = (int) round(min($s, 1) * 100);
        $cls = $s >= 1 ? 'is-bad' : ($s >= 0.9 ? 'is-warn' : '');
        return '<div class="zg-use ' . $cls . '"><span class="zg-use-txt">' . self::h(zgod::Size($used)) . ' <i>' . self::t('of %s', array(zgod::Size($quota))) . '</i> <b>' . (int) round($s * 100) . '%</b></span>'
            . '<span class="zg-bar" role="img" aria-label="' . (int) round($s * 100) . '%"><span style="width:' . $pct . '%"></span></span></div>';
    }

    /**
     * The status pill of an account.
     *
     * @param array $a
     * @return string
     */
    static function Status(array $a)
    {
        if (!$a['enabled']) {
            return '<span class="zg-pill is-bad">' . self::t('Suspended') . '</span>';
        }
        foreach (array(array('disk', 'diskq'), array('bw', 'bwq')) as $p) {
            $s = zgod::Share($a[$p[0]], $a[$p[1]]);
            if ($s !== null && $s >= 0.9) {
                return '<span class="zg-pill is-warn">' . self::t($s >= 1 ? 'Over a quota' : 'Near a quota') . '</span>';
            }
        }
        return '<span class="zg-pill is-ok">' . self::t('Active') . '</span>';
    }

    /**
     * A date, or a dash.
     *
     * @param int $t
     * @return string
     */
    static function Day($t)
    {
        return $t > 0 ? self::h(date('Y-m-d', $t)) : '–';
    }

    /**
     * Overview: tiles, bandwidth by month, the biggest users, quotas nearly full.
     *
     * @return string
     */
    static function Overview()
    {
        $all = zgod::Accounts();
        $t = zgod::Totals();
        $resellers = $off = 0;
        $near = array();
        $mailboxes = $domains = 0;
        foreach ($all as $a) {
            $resellers += $a['grp'] === 2 ? 1 : 0;
            $off += $a['enabled'] ? 0 : 1;
            $mailboxes += $a['mailboxes'];
            $domains += $a['domains'];
            foreach (array(array('disk', 'diskq', 'disk'), array('bw', 'bwq', 'bandwidth')) as $p) {
                $s = zgod::Share($a[$p[0]], $a[$p[1]]);
                if ($s !== null && $s >= 0.8) {
                    $near[] = array($a, $p[2], $s);
                }
            }
        }
        usort($near, function ($x, $y) { return $y[2] <=> $x[2]; });
        $out = '<div class="zg-tiles">'
            . self::Tile('<path d="M3 12h4l3 8 4-16 3 8h4"/>', zgod::Size($t['month']), self::t('bandwidth this month (%s this year)', array(zgod::Size($t['year']))))
            . self::Tile('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>', zgod::Size($t['disk']), self::t('disk used by the accounts'))
            . self::Tile('<circle cx="9" cy="8" r="4"/><path d="M2 21v-1a7 7 0 0 1 14 0v1"/><path d="M17 11a4 4 0 0 0 0-7M22 21v-1a7 7 0 0 0-4-6"/>', (string) count($all), self::t($resellers === 1 ? 'accounts, %s reseller, %s suspended' : 'accounts, %s resellers, %s suspended', array($resellers, $off)), $off ? 'warn' : '')
            . self::Tile('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>', (string) $domains, self::t('websites and domains'))
            . self::Tile('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>', (string) $mailboxes, self::t('mailboxes'))
            . self::Tile('<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>', (string) count($near), self::t('quotas at 80% or more'), $near ? 'warn' : 'ok')
            . '</div>';
        $out .= '<div class="zg-cols"><div class="zg-card"><h3>' . self::t('Bandwidth by month') . '</h3>' . self::MonthChart(zgod::ByMonth()) . '</div>'
            . '<div class="zg-card"><h3>' . self::t('Who used it this month') . '</h3>' . self::Ring($all, $t['month']) . '</div></div>';
        $rows = '';
        foreach (array_slice($near, 0, 15) as $n) {
            $a = $n[0];
            $rows .= '<a class="zg-row" href="' . self::Link(array('tab' => 'account', 'id' => $a['id'])) . '"><div class="zg-main"><b>' . self::h($a['username']) . '</b> <span>' . self::h($a['package']) . ' · ' . self::t('reseller %s', array($a['resellername'])) . '</span></div>'
                . '<div class="zg-what">' . self::t($n[1]) . '</div>' . self::Bar($n[1] === 'disk' ? $a['disk'] : $a['bw'], $n[1] === 'disk' ? $a['diskq'] : $a['bwq']) . '</a>';
        }
        $out .= '<div class="zg-card"><h3>' . self::t('Quotas nearly full') . '</h3>' . ($rows !== '' ? '<div class="zg-rows">' . $rows . '</div>' : '<p class="zg-muted">' . self::t('Every account is under 80% of its quotas.') . '</p>') . '</div>';
        return $out;
    }

    /**
     * Bandwidth by month as columns.
     *
     * @param array<string, int> $months
     * @return string
     */
    static function MonthChart(array $months)
    {
        $max = max(1, max($months));
        $w = 34;
        $svg = '<svg class="zg-chart" viewBox="0 0 ' . (count($months) * $w) . ' 140" role="img" aria-label="' . self::t('Bandwidth by month') . '">';
        $i = 0;
        foreach ($months as $m => $b) {
            $hgt = (int) round($b / $max * 100);
            $x = $i * $w + 6;
            $svg .= '<rect class="zg-col" x="' . $x . '" y="' . (112 - $hgt) . '" width="' . ($w - 12) . '" height="' . max($hgt, 1) . '" rx="3"><title>' . self::h(date('M Y', mktime(0, 0, 0, (int) substr($m, 4, 2), 1, (int) substr($m, 0, 4))) . ': ' . zgod::Size($b)) . '</title></rect>'
                . '<text x="' . ($x + ($w - 12) / 2) . '" y="130" text-anchor="middle">' . self::h(date('M', mktime(0, 0, 0, (int) substr($m, 4, 2), 1))) . '</text>';
            $i++;
        }
        return $svg . '</svg>';
    }

    /**
     * This month's bandwidth by account: a ring with the five biggest and the rest.
     *
     * @param array $all
     * @param int $total
     * @return string
     */
    static function Ring(array $all, $total)
    {
        if ($total <= 0) {
            return '<p class="zg-muted">' . self::t('No bandwidth counted yet this month.') . '</p>';
        }
        usort($all, function ($x, $y) { return $y['bw'] <=> $x['bw']; });
        $parts = array();
        $rest = $total;
        foreach (array_slice($all, 0, 5) as $a) {
            if ($a['bw'] > 0) {
                $parts[] = array($a['username'], $a['bw']);
                $rest -= $a['bw'];
            }
        }
        if ($rest > 0) {
            $parts[] = array(ui_language::translate('everyone else'), $rest);
        }
        $r = 15.915;
        $svg = '<svg class="zg-ring" viewBox="0 0 42 42" role="img" aria-label="' . self::t('Who used it this month') . '"><circle cx="21" cy="21" r="' . $r . '" fill="none" class="zg-ring-bg" stroke-width="6"/>';
        $off = 25;
        $legend = '';
        foreach ($parts as $i => $p) {
            $pct = $p[1] / $total * 100;
            $svg .= '<circle cx="21" cy="21" r="' . $r . '" fill="none" class="zg-c' . $i . '" stroke-width="6" stroke-dasharray="' . round($pct, 2) . ' ' . round(100 - $pct, 2) . '" stroke-dashoffset="' . round($off, 2) . '"/>';
            $off = $off - $pct;
            $legend .= '<li><i class="zg-c' . $i . '"></i>' . self::h($p[0]) . ' <span>' . self::h(zgod::Size($p[1])) . ' · ' . (int) round($pct) . '%</span></li>';
        }
        return '<div class="zg-ringbox">' . $svg . '</svg><ul class="zg-legend">' . $legend . '</ul></div>';
    }

    /**
     * Accounts: every account, filtered.
     *
     * @return string
     */
    static function AccountsPage()
    {
        $all = zgod::Accounts();
        $q = strtolower(trim(self::Q('q')));
        $res = (int) self::Q('reseller');
        $st = self::Q('status');
        $sort = self::Q('sort');
        $list = array();
        foreach ($all as $a) {
            if ($q !== '' && strpos(strtolower($a['username'] . ' ' . $a['email'] . ' ' . $a['package']), $q) === false) {
                continue;
            }
            if ($res > 0 && $a['reseller'] !== $res) {
                continue;
            }
            $sd = zgod::Share($a['disk'], $a['diskq']);
            $sb = zgod::Share($a['bw'], $a['bwq']);
            $nearq = ($sd !== null && $sd >= 0.9) || ($sb !== null && $sb >= 0.9);
            if (($st === 'active' && !$a['enabled']) || ($st === 'suspended' && $a['enabled']) || ($st === 'near' && !$nearq)) {
                continue;
            }
            $list[] = $a;
        }
        $keys = array('disk' => 'disk', 'bandwidth' => 'bw', 'created' => 'created', 'login' => 'lastlogon');
        if (isset($keys[$sort])) {
            $k = $keys[$sort];
            usort($list, function ($x, $y) use ($k) { return $y[$k] <=> $x[$k]; });
        }
        $opt = function ($v, $label, $cur) { return '<option value="' . self::h($v) . '"' . ((string) $v === (string) $cur ? ' selected' : '') . '>' . $label . '</option>'; };
        $rs = $opt('', self::t('Every reseller'), $res ?: '');
        foreach ($all as $a) {
            if ($a['clients'] > 0) {
                $rs .= $opt($a['id'], self::h($a['username']), $res);
            }
        }
        $form = '<form class="zg-filters" method="get" action="./"><input type="hidden" name="module" value="zgod"><input type="hidden" name="tab" value="accounts">'
            . '<input class="form-control" type="text" name="q" maxlength="100" value="' . self::h(self::Q('q')) . '" placeholder="' . self::t('Name, email or package') . '" aria-label="' . self::t('Search') . '">'
            . '<select class="form-control" name="reseller" aria-label="' . self::t('Reseller') . '">' . $rs . '</select>'
            . '<select class="form-control" name="status" aria-label="' . self::t('Status') . '">' . $opt('', self::t('Any status'), $st) . $opt('active', self::t('Active'), $st) . $opt('suspended', self::t('Suspended'), $st) . $opt('near', self::t('Near or over a quota'), $st) . '</select>'
            . '<select class="form-control" name="sort" aria-label="' . self::t('Order') . '">' . $opt('', self::t('By name'), $sort) . $opt('disk', self::t('Most disk'), $sort) . $opt('bandwidth', self::t('Most bandwidth'), $sort) . $opt('created', self::t('Newest'), $sort) . $opt('login', self::t('Last logged in'), $sort) . '</select>'
            . '<button class="btn btn-primary btn-sm" type="submit">' . self::t('Show') . '</button></form>';
        $rows = '';
        foreach ($list as $a) {
            $rows .= '<a class="zg-row zg-acc" href="' . self::Link(array('tab' => 'account', 'id' => $a['id'])) . '">'
                . '<div class="zg-main"><b>' . self::h($a['username']) . '</b> ' . self::Status($a)
                . '<div class="zg-sub">' . self::h($a['package'] !== null ? $a['package'] : '–') . ' · ' . self::h($a['groupname']) . ' · ' . self::t('reseller %s', array($a['resellername'])) . '</div>'
                . '<div class="zg-chips">' . self::Chip($a['domains'], 'websites') . self::Chip($a['mailboxes'], 'mailboxes') . self::Chip($a['databases'], 'databases') . self::Chip($a['ftp'], 'FTP') . ($a['clients'] ? self::Chip($a['clients'], 'clients') : '') . '</div></div>'
                . '<div class="zg-uses"><div><span class="zg-lbl">' . self::t('Disk') . '</span>' . self::Bar($a['disk'], $a['diskq']) . '</div><div><span class="zg-lbl">' . self::t('Bandwidth') . '</span>' . self::Bar($a['bw'], $a['bwq']) . '</div></div>'
                . '<div class="zg-when"><span>' . self::t('created %s', array($a['created'] ? date('Y-m-d', $a['created']) : '–')) . '</span><span>' . self::t('last login %s', array($a['lastlogon'] ? date('Y-m-d', $a['lastlogon']) : self::t('never'))) . '</span></div></a>';
        }
        return '<div class="zg-card">' . $form . '<p class="zg-muted zg-count">' . self::t('%s of %s accounts', array(count($list), count($all))) . '</p>'
            . ($rows !== '' ? '<div class="zg-rows">' . $rows . '</div>' : '<p class="zg-muted">' . self::t('No account matches.') . '</p>') . '</div>';
    }

    /**
     * A small count chip.
     *
     * @param int $n
     * @param string $what
     * @return string
     */
    static function Chip($n, $what)
    {
        $one = array('websites' => 'website', 'mailboxes' => 'mailbox', 'databases' => 'database', 'clients' => 'client', 'active clients' => 'active client', 'packages' => 'package', 'accounts' => 'account');
        return '<span class="zg-chip' . ($n ? '' : ' is-none') . '">' . (int) $n . ' ' . self::t((int) $n === 1 && isset($one[$what]) ? $one[$what] : $what) . '</span>';
    }

    /**
     * Resellers: each with their clients and totals.
     *
     * @return string
     */
    static function ResellersPage()
    {
        $rows = '';
        foreach (zgod::Resellers() as $r) {
            $rows .= '<div class="zg-row zg-res"><div class="zg-main"><b><a href="' . self::Link(array('tab' => 'account', 'id' => $r['id'])) . '">' . self::h($r['username']) . '</a></b> <span>' . self::h($r['groupname']) . '</span>'
                . '<div class="zg-chips">' . self::Chip($r['active'], 'active clients') . ($r['off'] ? '<span class="zg-chip is-bad">' . (int) $r['off'] . ' ' . self::t('suspended') . '</span>' : '') . self::Chip($r['packages'], 'packages')
                . self::Chip($r['client_domains'], 'websites') . self::Chip($r['client_mailboxes'], 'mailboxes') . '</div></div>'
                . '<div class="zg-figs"><div><b>' . self::h(zgod::Size($r['client_disk'])) . '</b><span>' . self::t('clients\' disk') . '</span></div><div><b>' . self::h(zgod::Size($r['client_bw'])) . '</b><span>' . self::t('clients\' bandwidth this month') . '</span></div></div>'
                . '<a class="btn btn-default btn-sm" href="' . self::Link(array('tab' => 'accounts', 'reseller' => $r['id'])) . '">' . self::t('Their clients') . '</a></div>';
        }
        return '<div class="zg-card"><h3>' . self::t('Resellers and their clients') . '</h3>' . ($rows !== '' ? '<div class="zg-rows">' . $rows . '</div>' : '<p class="zg-muted">' . self::t('No resellers yet.') . '</p>') . '</div>';
    }

    /**
     * One account in full.
     *
     * @param int $id
     * @return string
     */
    static function AccountPage($id)
    {
        $a = zgod::Account($id);
        if ($a === null) {
            return '<div class="zg-card"><p>' . self::t('There is no such account.') . '</p></div>';
        }
        $me = self::Me();
        $back = '<a class="zg-back" href="' . self::Link(array('tab' => 'accounts')) . '">' . self::Ico('<path d="M15 18l-6-6 6-6"/>', 'zg-ico') . self::t('All accounts') . '</a>';
        $head = '<div class="zg-card zg-head"><div><h3>' . self::h($a['username']) . ' ' . self::Status($a) . '</h3><p class="zg-muted">'
            . self::h($a['profile']['name']) . ($a['email'] !== '' ? ' · ' . self::h($a['email']) : '') . ($a['profile']['phone'] !== '' ? ' · ' . self::h($a['profile']['phone']) : '') . '</p>'
            . '<dl class="zg-dl"><dt>' . self::t('Package') . '</dt><dd>' . self::h($a['package'] !== null ? $a['package'] : '–') . '</dd><dt>' . self::t('Group') . '</dt><dd>' . self::h($a['groupname']) . '</dd>'
            . '<dt>' . self::t('Reseller') . '</dt><dd>' . ($a['reseller'] && $a['reseller'] !== $a['id'] ? '<a href="' . self::Link(array('tab' => 'account', 'id' => $a['reseller'])) . '">' . self::h($a['resellername']) . '</a>' : self::h($a['resellername'])) . '</dd>'
            . '<dt>' . self::t('Created') . '</dt><dd>' . self::Day($a['created']) . '</dd><dt>' . self::t('Last login') . '</dt><dd>' . ($a['lastlogon'] ? self::h(date('Y-m-d H:i', $a['lastlogon'])) : self::t('never')) . '</dd>'
            . ($a['reason'] !== '' ? '<dt>' . self::t('Suspended because') . '</dt><dd>' . self::h($a['reason']) . '</dd>' : '') . '</dl></div>'
            . '<div class="zg-headside"><div><span class="zg-lbl">' . self::t('Disk') . '</span>' . self::Bar($a['disk'], $a['diskq']) . '</div><div><span class="zg-lbl">' . self::t('Bandwidth this month') . '</span>' . self::Bar($a['bw'], $a['bwq']) . '</div>'
            . self::Actions($a, (int) $me['userid']) . '</div></div>';
        $sec = '';
        $sec .= self::Section('Websites and domains', $a['domain_list'], function ($d) {
            $type = array(1 => 'domain', 2 => 'subdomain', 3 => 'parked');
            return '<div class="zg-line"><b>' . self::h($d['name']) . '</b><span>' . self::t(isset($type[(int) $d['type']]) ? $type[(int) $d['type']] : 'domain') . ' · ' . self::h($d['dir']) . ($d['php'] ? ' · PHP ' . self::h($d['php']) : '') . '</span>'
                . ((int) $d['active'] ? '' : '<span class="zg-pill is-warn">' . self::t('not live yet') . '</span>') . '<span class="zg-dim">' . self::Day((int) $d['created']) . '</span></div>';
        });
        $sec .= self::Section('MySQL databases', $a['database_list'], function ($d) {
            return '<div class="zg-line"><b>' . self::h($d['name']) . '</b><span>' . self::h(zgod::Size((int) $d['size'])) . '</span><span class="zg-dim">' . self::Day((int) $d['created']) . '</span></div>';
        });
        $sec .= self::Section('Mailboxes', $a['mailbox_list'], function ($m) use ($a) {
            $on = (int) $m['enabled'] === 1;
            return '<div class="zg-line"><b>' . self::h($m['address']) . '</b>' . ($on ? '<span class="zg-pill is-ok">' . self::t('On') . '</span>' : '<span class="zg-pill is-bad">' . self::t('Off') . '</span>')
                . '<span class="zg-dim">' . self::Day((int) $m['created']) . '</span>'
                . '<form method="post" class="zg-inline" action="' . self::Link(array('tab' => 'account', 'id' => $a['id'], 'action' => 'Mailbox')) . '">' . runtime_csfr::Token()
                . '<input type="hidden" name="inMb" value="' . (int) $m['id'] . '"><input type="hidden" name="inOn" value="' . ($on ? '0' : '1') . '">'
                . '<button class="btn btn-default btn-xs" type="submit">' . self::t($on ? 'Switch off' : 'Switch on') . '</button></form></div>';
        });
        $sec .= self::Section('Forwarders', $a['forwarder_list'], function ($f) {
            return '<div class="zg-line"><b>' . self::h($f['address']) . '</b><span>→ ' . self::h($f['dest']) . ((int) $f['keep'] ? ' ' . self::t('(a copy kept)') : '') . '</span></div>';
        });
        $sec .= self::Section('Aliases', $a['alias_list'], function ($f) {
            return '<div class="zg-line"><b>' . self::h($f['address']) . '</b><span>→ ' . self::h($f['dest']) . '</span></div>';
        });
        $sec .= self::Section('Distribution lists', $a['distlist_list'], function ($d) {
            return '<div class="zg-line"><b>' . self::h($d['address']) . '</b><span class="zg-dim">' . self::Day((int) $d['created']) . '</span></div>';
        });
        $sec .= self::Section('FTP accounts', $a['ftp_list'], function ($f) {
            return '<div class="zg-line"><b>' . self::h($f['ftpuser']) . '</b><span>' . self::h($f['dir']) . ' · ' . self::h($f['access']) . '</span><span class="zg-dim">' . self::Day((int) $f['created']) . '</span></div>';
        });
        $sec .= self::Section('Cron jobs', $a['cron_list'], function ($c) {
            return '<div class="zg-line"><b>' . self::h($c['timing']) . '</b><span>' . self::h($c['script']) . ($c['description'] !== '' ? ' · ' . self::h($c['description']) : '') . '</span></div>';
        });
        if ($a['package_list'] || $a['client_list']) {
            $sec .= self::Section('Their packages', $a['package_list'], function ($p) {
                return '<div class="zg-line"><b>' . self::h($p['name']) . '</b><span>' . self::t('disk %s, bandwidth %s', array((int) $p['diskq'] ? zgod::Size((int) $p['diskq']) : self::t('no limit'), (int) $p['bwq'] ? zgod::Size((int) $p['bwq']) : self::t('no limit')))
                    . ' · ' . self::t((int) $p['php'] ? 'PHP' : 'no PHP') . '</span>' . self::Chip((int) $p['used'], 'accounts') . '</div>';
            });
            $sec .= self::Section('Their clients', $a['client_list'], function ($c) {
                return '<div class="zg-line"><b><a href="' . self::Link(array('tab' => 'account', 'id' => $c['id'])) . '">' . self::h($c['username']) . '</a></b>' . self::Status($c) . '<span>' . self::h($c['package']) . ' · ' . self::h(zgod::Size($c['disk'])) . ' ' . self::t('disk') . ' · ' . self::h(zgod::Size($c['bw'])) . ' ' . self::t('this month') . '</span></div>';
            });
        }
        $sec .= self::Section('Recent panel log', $a['log_list'], function ($l) {
            return '<div class="zg-line"><span class="zg-dim">' . self::h(date('Y-m-d H:i', (int) $l['t'])) . '</span><b>' . self::h($l['module']) . '</b><span>' . self::h(mb_strimwidth((string) $l['detail'], 0, 200, '…')) . '</span></div>';
        });
        return $back . $head . '<div class="zg-grid">' . $sec . '</div>';
    }

    /**
     * Suspend or switch on (never an administrator, never yourself).
     *
     * @param array $a
     * @param int $me
     * @return string
     */
    static function Actions(array $a, $me)
    {
        if ($a['grp'] === zgod::ADMINS || $a['id'] === $me) {
            return '<p class="zg-muted">' . self::t('Administrators can not be suspended here.') . '</p>';
        }
        $url = self::Link(array('tab' => 'account', 'id' => $a['id'], 'action' => $a['enabled'] ? 'Suspend' : 'Unsuspend'));
        if (!$a['enabled']) {
            return '<form method="post" action="' . $url . '">' . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . (int) $a['id'] . '">'
                . '<button class="btn btn-primary btn-sm" type="submit">' . self::t('Switch on again') . '</button></form>';
        }
        return '<form method="post" class="zg-suspend" action="' . $url . '">' . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . (int) $a['id'] . '">'
            . '<input class="form-control" type="text" name="inReason" maxlength="200" placeholder="' . self::t('Why (optional): not paid, abuse...') . '" aria-label="' . self::t('Why') . '">'
            . '<button class="btn btn-danger btn-sm" type="submit" onclick="return confirm(this.getAttribute(\'data-ask\'))" data-ask="' . self::t('Suspend %s? Its websites show the suspended page and it can not log in, until it is switched on again.', array($a['username'])) . '">' . self::t('Suspend') . '</button></form>';
    }

    /**
     * A section of the account page: its rows, or that there are none.
     *
     * @param string $title English.
     * @param array $items
     * @param callable $row
     * @return string
     */
    static function Section($title, array $items, $row)
    {
        $body = '';
        foreach (array_slice($items, 0, 200) as $i) {
            $body .= $row($i);
        }
        return '<div class="zg-card zg-sec"><h4>' . self::t($title) . ' <span class="zg-count-pill">' . count($items) . '</span></h4>' . ($body !== '' ? $body : '<p class="zg-muted">' . self::t('None.') . '</p>') . '</div>';
    }

    /**
     * Daily report: on or off, where to, and what it looks like today.
     *
     * @return string
     */
    static function ReportPage()
    {
        $on = zgod::Get('report', 'off') === 'on';
        $last = zgod::Get('report_last');
        $r = zgod::Report();
        $to = zgod::ReportTo();
        return '<div class="zg-card"><h3>' . self::t('Daily report by email') . '</h3>'
            . '<p class="zg-muted">' . self::t('Once a day: new accounts, quotas at 90% or more, suspended accounts and the biggest users of bandwidth. It goes out with the panel\'s own mail settings.') . '</p>'
            . '<form method="post" action="' . self::Link(array('tab' => 'report', 'action' => 'Report')) . '">' . runtime_csfr::Token()
            . '<label class="zg-check"><input type="checkbox" name="inReport" value="on"' . ($on ? ' checked' : '') . '> ' . self::t('Send it every day') . '</label>'
            . '<p><label for="zg-to">' . self::t('To') . '</label> <span class="zg-muted">' . self::t('(empty: every administrator\'s address; up to ten, separated by commas)') . '</span><br>'
            . '<input class="form-control zg-wide" id="zg-to" type="text" name="inTo" maxlength="2000" value="' . self::h(zgod::Get('report_to')) . '" placeholder="' . self::h(implode(', ', $to)) . '"></p>'
            . '<p><button class="btn btn-primary btn-sm" type="submit">' . self::t('Save') . '</button></p></form>'
            . ($last !== '' ? '<p class="zg-muted">' . self::t('Last sent: %s', array(date('Y-m-d H:i', (int) $last) . (strpos($last, 'failed') !== false ? ' (' . ui_language::translate('failed') . ')' : ''))) . '</p>' : '')
            . '</div><div class="zg-card"><h3>' . self::t('Today\'s report') . '</h3><p class="zg-muted">' . self::h($r['subject']) . '</p><div class="zg-preview">' . $r['html'] . '</div></div>';
    }
}
