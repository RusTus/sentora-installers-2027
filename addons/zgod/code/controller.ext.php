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
    /** zGod's tabs: the page's parts, then a list of everything of each kind on the panel. */
    const TABS = array('overview' => 'Overview', 'accounts' => 'Accounts', 'resellers' => 'Resellers', 'usage' => 'Usage', 'domains' => 'Domains',
        'mail' => 'Mail', 'mysql' => 'MySQL', 'ftp' => 'FTP', 'cron' => 'Cron jobs', 'docker' => 'Docker apps', 'packages' => 'Packages', 'report' => 'Daily report');

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
        if (!isset(self::TABS[$tab]) || ($tab === 'docker' && !zgod::HasDocker())) {
            $tab = 'overview';
        }
        $pages = array('overview' => 'Overview', 'accounts' => 'AccountsPage', 'resellers' => 'ResellersPage', 'usage' => 'UsagePage', 'domains' => 'DomainsPage',
            'mail' => 'MailPage', 'mysql' => 'MysqlPage', 'ftp' => 'FtpPage', 'cron' => 'CronPage', 'docker' => 'DockerPage', 'packages' => 'PackagesPage', 'report' => 'ReportPage');
        $body = call_user_func(array('module_controller', $pages[$tab]));
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
        foreach (self::TABS as $k => $label) {
            if ($k === 'docker' && !zgod::HasDocker()) {
                continue;
            }
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
        $out = '';
        if (!$a['enabled']) {
            $out = '<span class="zg-pill is-bad">' . self::t('Suspended') . '</span> ';
        } else {
            foreach (array(array('disk', 'diskq'), array('bw', 'bwq')) as $p) {
                $s = zgod::Share($a[$p[0]], $a[$p[1]]);
                if ($s !== null && $s >= 0.9) {
                    $out = '<span class="zg-pill is-warn">' . self::t($s >= 1 ? 'Over a quota' : 'Near a quota') . '</span> ';
                    break;
                }
            }
        }
        return $out . self::Activity($a['lastlogon']);
    }

    /**
     * How active the account is (by its last login), as a pill with the date in its title.
     *
     * @param int $lastlogon
     * @return string
     */
    static function Activity($lastlogon)
    {
        $act = zgod::Activity($lastlogon);
        $look = array('recent' => array('is-ok', 'Logged in recently'), 'active' => array('is-on', 'Active'), 'inactive' => array('is-warn', 'Inactive'), 'never' => array('', 'Never logged in'));
        return '<span class="zg-pill ' . $look[$act][0] . '" title="' . self::h(self::Ago($lastlogon)) . '">' . self::t($look[$act][1]) . '</span>';
    }

    /**
     * The last login in words: the date and how long ago.
     *
     * @param int $t
     * @return string
     */
    static function Ago($t)
    {
        if ((int) $t <= 0) {
            return self::t('never');
        }
        $d = (int) floor((time() - (int) $t) / 86400);
        return date('Y-m-d H:i', (int) $t) . ' (' . ($d < 1 ? self::t('today') : ($d === 1 ? self::t('yesterday') : self::t('%s days ago', array($d)))) . ')';
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
            if (($st === 'enabled' && !$a['enabled']) || ($st === 'suspended' && $a['enabled']) || ($st === 'near' && !$nearq)
                || (in_array($st, array('recent', 'active', 'inactive', 'never'), true) && zgod::Activity($a['lastlogon']) !== $st)) {
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
            . '<select class="form-control" name="status" aria-label="' . self::t('Status') . '">' . $opt('', self::t('Any status'), $st) . $opt('enabled', self::t('Not suspended'), $st) . $opt('suspended', self::t('Suspended'), $st) . $opt('near', self::t('Near or over a quota'), $st)
                . $opt('recent', self::t('Logged in recently (7 days)'), $st) . $opt('active', self::t('Active (logged in within 90 days)'), $st) . $opt('inactive', self::t('Inactive (no login for 90 days)'), $st) . $opt('never', self::t('Never logged in'), $st) . '</select>'
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
     * Every domain on the panel in one list: its account, reseller and state; search, kind, reseller, order.
     *
     * @return string
     */
    static function DomainsPage()
    {
        $all = zgod::Domains();
        $q = strtolower(trim(self::Q('q')));
        $kind = (int) self::Q('kind');
        $res = (int) self::Q('reseller');
        $sort = self::Q('sort');
        $types = array(1 => 'domain', 2 => 'subdomain', 3 => 'parked');
        $list = array();
        $resellers = array();
        foreach ($all as $d) {
            $resellers[$d['reseller']] = (string) $d['resellername'];
            if ($q !== '' && strpos(strtolower($d['name'] . ' ' . $d['username'] . ' ' . $d['resellername']), $q) === false) {
                continue;
            }
            if (($kind > 0 && $d['type'] !== $kind) || ($res > 0 && $d['reseller'] !== $res)) {
                continue;
            }
            $list[] = $d;
        }
        if ($sort === 'created') {
            usort($list, function ($x, $y) { return $y['created'] <=> $x['created']; });
        } elseif ($sort === 'account') {
            usort($list, function ($x, $y) { return strcmp($x['username'] . ' ' . $x['name'], $y['username'] . ' ' . $y['name']); });
        }
        asort($resellers);
        $opt = function ($v, $label, $cur) { return '<option value="' . self::h($v) . '"' . ((string) $v === (string) $cur ? ' selected' : '') . '>' . $label . '</option>'; };
        $rs = $opt('', self::t('Every reseller'), $res ?: '');
        foreach ($resellers as $rid => $rname) {
            if ($rid > 0) {
                $rs .= $opt($rid, self::h($rname), $res);
            }
        }
        $ks = $opt('', self::t('Every kind'), $kind ?: '');
        foreach ($types as $k => $label) {
            $ks .= $opt($k, self::t($label), $kind);
        }
        $form = '<form class="zg-filters" method="get" action="./"><input type="hidden" name="module" value="zgod"><input type="hidden" name="tab" value="domains">'
            . '<input class="form-control" type="text" name="q" maxlength="100" value="' . self::h(self::Q('q')) . '" placeholder="' . self::t('Domain, account or reseller') . '" aria-label="' . self::t('Search') . '">'
            . '<select class="form-control" name="kind" aria-label="' . self::t('Kind') . '">' . $ks . '</select>'
            . '<select class="form-control" name="reseller" aria-label="' . self::t('Reseller') . '">' . $rs . '</select>'
            . '<select class="form-control" name="sort" aria-label="' . self::t('Order') . '">' . $opt('', self::t('By name'), $sort) . $opt('account', self::t('By account'), $sort) . $opt('created', self::t('Newest'), $sort) . '</select>'
            . '<button class="btn btn-primary btn-sm" type="submit">' . self::t('Show') . '</button></form>';
        $rows = array();
        foreach ($list as $d) {
            $state = !$d['acc_enabled'] ? '<span class="zg-pill is-bad">' . self::t('account suspended') . '</span>'
                : ($d['active'] ? '<span class="zg-pill is-ok">' . self::t('live') . '</span>' : '<span class="zg-pill is-warn">' . self::t('not live yet') . '</span>');
            $rows[] = array('<b>' . self::h($d['name']) . '</b>', self::t(isset($types[$d['type']]) ? $types[$d['type']] : 'domain'), $state, self::AccLink($d),
                self::h($d['resellername']), self::h($d['dir']), self::h($d['php'] ? $d['php'] : '–'), self::Day($d['created']));
        }
        return '<div class="zg-card">' . $form . '<p class="zg-muted zg-count">' . self::t('%s of %s domains', array(count($list), count($all))) . '</p>'
            . self::Table(array('Domain', 'Kind', 'State', 'Account', 'Reseller', 'Folder', 'PHP', 'Created'), $rows, 'No domain matches.') . '</div>';
    }

    /**
     * A plain table: headings (English) and rows of ready HTML cells; on a phone each row becomes a small card.
     *
     * @param string[] $heads
     * @param array<int, string[]> $rows
     * @param string $none English.
     * @return string
     */
    static function Table(array $heads, array $rows, $none = 'None.')
    {
        if (!$rows) {
            return '<p class="zg-muted">' . self::t($none) . '</p>';
        }
        $labels = array_map(function ($h) { return self::t($h); }, $heads);
        $out = '<div class="zg-tablebox"><table class="zg-table"><thead><tr>' . implode('', array_map(function ($l) { return '<th>' . $l . '</th>'; }, $labels)) . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $out .= '<tr>';
            foreach (array_values($r) as $i => $c) {
                $out .= '<td data-label="' . (isset($labels[$i]) ? $labels[$i] : '') . '">' . ($c === '' ? '–' : $c) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table></div>';
    }

    /**
     * A size for a table cell (kept on one line).
     *
     * @param int|float $b
     * @return string
     */
    static function Sz($b)
    {
        return str_replace(' ', '&nbsp;', self::h(zgod::Size($b)));
    }

    /**
     * A link to an account's page in zGod.
     *
     * @param array $r With 'acc' and 'username'.
     * @return string
     */
    static function AccLink(array $r)
    {
        return '<a href="' . self::Link(array('tab' => 'account', 'id' => (int) $r['acc'])) . '">' . self::h($r['username']) . '</a>';
    }

    /**
     * A search box for one of the lists (by its words: the list's text, account included).
     *
     * @param string $tab
     * @param string $hint English.
     * @return string
     */
    static function Find($tab, $hint)
    {
        return '<form class="zg-find" method="get" action="./"><input type="hidden" name="module" value="zgod"><input type="hidden" name="tab" value="' . self::h($tab) . '">'
            . '<input class="form-control" type="text" name="q" maxlength="100" value="' . self::h(self::Q('q')) . '" placeholder="' . self::t($hint) . '" aria-label="' . self::t('Search') . '">'
            . '<button class="btn btn-primary btn-sm" type="submit">' . self::t('Show') . '</button>'
            . (self::Q('q') !== '' ? '<a class="btn btn-default btn-sm" href="' . self::Link(array('tab' => $tab)) . '">' . self::t('Show all') . '</a>' : '') . '</form>';
    }

    /**
     * The rows that match the search (q) in any of the given fields.
     *
     * @param array $rows
     * @param string[] $fields
     * @return array
     */
    static function Match(array $rows, array $fields)
    {
        $q = strtolower(trim(self::Q('q')));
        if ($q === '') {
            return $rows;
        }
        return array_values(array_filter($rows, function ($r) use ($q, $fields) {
            foreach ($fields as $f) {
                if (isset($r[$f]) && strpos(strtolower((string) $r[$f]), $q) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * One list of a kind on the panel, as a card: its heading with the count, then the table.
     *
     * @param string $title English.
     * @param array $all Every row (before the search).
     * @param array $shown
     * @param string[] $heads
     * @param callable $cells function(array $row): string[]
     * @return string
     */
    static function ListCard($title, array $all, array $shown, array $heads, $cells)
    {
        return '<div class="zg-card"><h3>' . self::t($title) . ' <span class="zg-count-pill">' . (count($shown) === count($all) ? count($all) : self::t('%s of %s', array(count($shown), count($all)))) . '</span></h3>'
            . self::Table($heads, array_map($cells, $shown)) . '</div>';
    }

    /**
     * Usage: every account's disk and bandwidth against its quota and its share of the whole server's.
     *
     * @return string
     */
    static function UsagePage()
    {
        $all = array_values(zgod::Accounts());
        $disk = array_sum(array_map(function ($a) { return $a['disk']; }, $all));
        $bw = array_sum(array_map(function ($a) { return $a['bw']; }, $all));
        $list = self::Match($all, array('username', 'resellername', 'package'));
        $sort = self::Q('sort');
        $k = $sort === 'bandwidth' ? 'bw' : ($sort === 'name' ? '' : 'disk');
        if ($k !== '') {
            usort($list, function ($x, $y) use ($k) { return $y[$k] <=> $x[$k]; });
        }
        $pct = function ($u, $q) { return $q > 0 ? round($u * 100 / $q) . '%' : '–'; };
        $rows = array();
        foreach ($list as $a) {
            $sd = zgod::Share($a['disk'], $a['diskq']);
            $sb = zgod::Share($a['bw'], $a['bwq']);
            $mark = function ($share, $text) { return $share !== null && $share >= 0.9 ? '<b class="zg-hot">' . $text . '</b>' : $text; };
            $rows[] = array(self::AccLink(array('acc' => $a['id'], 'username' => $a['username'])) . ($a['enabled'] ? '' : ' <span class="zg-pill is-bad">' . self::t('suspended') . '</span>'),
                self::Activity($a['lastlogon']) . ($a['lastlogon'] ? '<br><span class="zg-muted">' . self::h(date('Y-m-d', $a['lastlogon'])) . '</span>' : ''),
                self::h($a['resellername']), self::h($a['package'] !== null ? $a['package'] : '–'),
                self::Sz($a['disk']), $a['diskq'] ? self::Sz($a['diskq']) : self::t('no limit'), $mark($sd, $pct($a['disk'], $a['diskq'])),
                self::Sz($a['bw']), $a['bwq'] ? self::Sz($a['bwq']) : self::t('no limit'), $mark($sb, $pct($a['bw'], $a['bwq'])),
                $pct($a['disk'], $disk), $pct($a['bw'], $bw));
        }
        $opt = function ($v, $label) use ($sort) { return '<option value="' . $v . '"' . ($v === $sort ? ' selected' : '') . '>' . self::t($label) . '</option>'; };
        $form = '<form class="zg-find" method="get" action="./"><input type="hidden" name="module" value="zgod"><input type="hidden" name="tab" value="usage">'
            . '<input class="form-control" type="text" name="q" maxlength="100" value="' . self::h(self::Q('q')) . '" placeholder="' . self::t('Account, reseller or package') . '" aria-label="' . self::t('Search') . '">'
            . '<select class="form-control" name="sort" aria-label="' . self::t('Order') . '">' . $opt('', 'Most disk') . $opt('bandwidth', 'Most bandwidth') . $opt('name', 'By name') . '</select>'
            . '<button class="btn btn-primary btn-sm" type="submit">' . self::t('Show') . '</button></form>';
        return '<div class="zg-card"><h3>' . self::t('Disk and bandwidth, every account') . '</h3><p class="zg-muted">' . self::t('The server in all: %s disk, %s bandwidth this month.', array(zgod::Size($disk), zgod::Size($bw))) . '</p>'
            . $form . self::Table(array('Account', 'Last login', 'Reseller', 'Package', 'Disk', 'Disk quota', 'Of quota', 'Bandwidth', 'Bandwidth quota', 'Of quota', 'Of the server\'s disk', 'Of the server\'s bandwidth'), $rows, 'No account matches.') . '</div>';
    }

    /**
     * Mail: every mailbox, forwarder, alias and distribution list on the panel.
     *
     * @return string
     */
    static function MailPage()
    {
        $f = array('name', 'dest', 'username');
        $mb = zgod::All('mailboxes');
        $fw = zgod::All('forwarders');
        $al = zgod::All('aliases');
        $dl = zgod::All('distlists');
        return self::Find('mail', 'Address or account')
            . self::ListCard('Mailboxes', $mb, self::Match($mb, $f), array('Address', 'State', 'Account', 'Created'), function ($r) {
                return array('<b>' . self::h($r['name']) . '</b>', (int) $r['enabled'] ? '<span class="zg-pill is-ok">' . self::t('On') . '</span>' : '<span class="zg-pill is-bad">' . self::t('Off') . '</span>', self::AccLink($r), self::Day((int) $r['created']));
            })
            . self::ListCard('Forwarders', $fw, self::Match($fw, $f), array('From', 'To', 'Copy kept', 'Account'), function ($r) {
                return array('<b>' . self::h($r['name']) . '</b>', self::h($r['dest']), self::t((int) $r['keep'] ? 'yes' : 'no'), self::AccLink($r));
            })
            . self::ListCard('Aliases', $al, self::Match($al, $f), array('Alias', 'Delivered to', 'Account'), function ($r) {
                return array('<b>' . self::h($r['name']) . '</b>', self::h($r['dest']), self::AccLink($r));
            })
            . self::ListCard('Distribution lists', $dl, self::Match($dl, $f), array('Address', 'Account', 'Created'), function ($r) {
                return array('<b>' . self::h($r['name']) . '</b>', self::AccLink($r), self::Day((int) $r['created']));
            });
    }

    /**
     * MySQL: every database and every MySQL user on the panel (never a password).
     *
     * @return string
     */
    static function MysqlPage()
    {
        $db = zgod::All('databases');
        $mu = zgod::All('dbusers');
        foreach ($mu as $k => $u) {
            $mu[$k]['dbs'] = implode(' ', $u['databases']);
        }
        return self::Find('mysql', 'Database, user or account')
            . self::ListCard('MySQL databases', $db, self::Match($db, array('name', 'username')), array('Database', 'Size', 'Users', 'Account', 'Created'), function ($r) {
                return array('<b>' . self::h($r['name']) . '</b>', self::Sz((int) $r['size']), (string) (int) $r['users'], self::AccLink($r), self::Day((int) $r['created']));
            })
            . self::ListCard('MySQL users', $mu, self::Match($mu, array('name', 'username', 'dbs', 'access')), array('User', 'From', 'Databases', 'Account', 'Created'), function ($r) {
                return array('<b>' . self::h($r['name']) . '</b>', self::h($r['access'] !== '' ? $r['access'] : '%'),
                    $r['databases'] ? implode(' ', array_map(function ($d) { return '<span class="zg-chip">' . self::h($d) . '</span>'; }, $r['databases'])) : '<span class="zg-chip is-none">' . self::t('no database') . '</span>',
                    self::AccLink($r), self::Day((int) $r['created']));
            });
    }

    /**
     * FTP: every FTP account on the panel.
     *
     * @return string
     */
    static function FtpPage()
    {
        $ft = zgod::All('ftp');
        return self::Find('ftp', 'FTP user, folder or account')
            . self::ListCard('FTP accounts', $ft, self::Match($ft, array('name', 'dir', 'username')), array('FTP user', 'Folder', 'Access', 'Account', 'Created'), function ($r) {
                return array('<b>' . self::h($r['name']) . '</b>', self::h($r['dir']), self::h($r['access']), self::AccLink($r), self::Day((int) $r['created']));
            });
    }

    /**
     * Cron jobs: every cron job on the panel.
     *
     * @return string
     */
    static function CronPage()
    {
        $ct = zgod::All('cron');
        return self::Find('cron', 'Script, description or account')
            . self::ListCard('Cron jobs', $ct, self::Match($ct, array('name', 'description', 'username')), array('When', 'Script', 'Description', 'Account'), function ($r) {
                return array('<code>' . self::h($r['timing']) . '</code>', '<b>' . self::h($r['name']) . '</b>', self::h($r['description']), self::AccLink($r));
            });
    }

    /**
     * Docker apps (Docker Hosting): every app on the panel's Docker nodes, its state, size and node.
     *
     * @return string
     */
    static function DockerPage()
    {
        $dc = zgod::All('docker');
        return self::Find('docker', 'App, address, node or account')
            . self::ListCard('Docker apps', $dc, self::Match($dc, array('app', 'name', 'domain', 'node', 'username')), array('App', 'State', 'Address', 'Size', 'Disk used', 'Node', 'Account', 'Created'), function ($r) {
                return self::DockerCells($r, true);
            });
    }

    /**
     * One Docker app's cells.
     *
     * @param array $r
     * @param bool $withAccount
     * @return string[]
     */
    static function DockerCells(array $r, $withAccount)
    {
        $look = array('running' => 'is-ok', 'stopped' => '', 'missing' => 'is-bad', 'failed' => 'is-bad', 'deleting' => 'is-warn');
        $st = (string) $r['state'];
        $cpu = (int) $r['cpu'];
        $c = array('<b>' . self::h($r['app']) . '</b>' . ($r['name'] !== '' && $r['name'] !== $r['app'] ? ' <span class="zg-muted">' . self::h($r['name']) . '</span>' : ''),
            '<span class="zg-pill ' . (isset($look[$st]) ? $look[$st] : 'is-on') . '">' . self::h($st) . '</span>',
            self::h($r['domain']),
            self::h(rtrim(rtrim(sprintf('%d.%02d', intdiv($cpu, 100), $cpu % 100), '0'), '.') . ' CPU, ' . (int) $r['mem'] . ' MB, ' . round((int) $r['disk'] / 1024, 1) . ' GB'),
            self::Sz((int) $r['used'] * 1048576), self::h((string) $r['node']));
        if ($withAccount) {
            $c[] = self::AccLink($r);
        }
        $c[] = self::Day((int) $r['created']);
        return $c;
    }

    /**
     * Packages: every package, whose it is, its quotas and how many accounts use it.
     *
     * @return string
     */
    static function PackagesPage()
    {
        $pk = zgod::All('packages');
        $n = function ($v) { return (int) $v < 0 ? self::t('no limit') : (string) (int) $v; };
        return self::Find('packages', 'Package or owner')
            . self::ListCard('Packages', $pk, self::Match($pk, array('name', 'username')), array('Package', 'Owner', 'Accounts', 'Disk', 'Bandwidth', 'Domains', 'Subdomains', 'Mailboxes', 'Databases', 'FTP', 'PHP'), function ($r) use ($n) {
                return array('<b>' . self::h($r['name']) . '</b>', self::AccLink($r), (string) (int) $r['used'],
                    (int) $r['diskq'] ? self::Sz((int) $r['diskq']) : self::t('no limit'), (int) $r['bwq'] ? self::Sz((int) $r['bwq']) : self::t('no limit'),
                    $n($r['domains']), $n($r['subdomains']), $n($r['mailboxes']), $n($r['mysql']), $n($r['ftp']), self::t((int) $r['php'] ? 'yes' : 'no'));
            });
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
        $one = array('websites' => 'website', 'mailboxes' => 'mailbox', 'databases' => 'database', 'clients' => 'client', 'active clients' => 'active client', 'packages' => 'package', 'accounts' => 'account', 'records' => 'record');
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
            . '<dt>' . self::t('Created') . '</dt><dd>' . self::Day($a['created']) . '</dd><dt>' . self::t('Last login') . '</dt><dd>' . self::h(self::Ago($a['lastlogon'])) . '</dd>'
            . ($a['reason'] !== '' ? '<dt>' . self::t('Suspended because') . '</dt><dd>' . self::h($a['reason']) . '</dd>' : '') . '</dl></div>'
            . '<div class="zg-headside"><div><span class="zg-lbl">' . self::t('Disk') . '</span>' . self::Bar($a['disk'], $a['diskq']) . '</div><div><span class="zg-lbl">' . self::t('Bandwidth this month') . '</span>' . self::Bar($a['bw'], $a['bwq']) . '</div>'
            . self::Shadow($a, (int) $me['userid']) . self::Actions($a, (int) $me['userid']) . self::Manage($a, (int) $me['userid']) . '</div></div>';
        $sec = '';
        $sec .= self::Section('Websites and domains', $a['domain_list'], function ($d) {
            $type = array(1 => 'domain', 2 => 'subdomain', 3 => 'parked');
            return '<div class="zg-line"><b>' . self::h($d['name']) . '</b><span>' . self::t(isset($type[(int) $d['type']]) ? $type[(int) $d['type']] : 'domain') . ' · ' . self::h($d['dir']) . ($d['php'] ? ' · PHP ' . self::h($d['php']) : '') . '</span>'
                . ((int) $d['active'] ? '' : '<span class="zg-pill is-warn">' . self::t('not live yet') . '</span>') . '<span class="zg-dim">' . self::Day((int) $d['created']) . '</span></div>';
        });
        $sec .= self::Section('MySQL databases', $a['database_list'], function ($d) {
            return '<div class="zg-line"><b>' . self::h($d['name']) . '</b><span>' . self::h(zgod::Size((int) $d['size'])) . '</span><span class="zg-dim">' . self::Day((int) $d['created']) . '</span></div>';
        });
        $sec .= self::Section('MySQL users', $a['dbuser_list'], function ($u) {
            return '<div class="zg-line"><b>' . self::h($u['name']) . '</b><span>' . self::t('from %s', array($u['access'] !== '' ? $u['access'] : '%')) . '</span>'
                . implode('', array_map(function ($db) { return '<span class="zg-chip">' . self::h($db) . '</span>'; }, $u['databases']))
                . ($u['databases'] ? '' : '<span class="zg-chip is-none">' . self::t('no database') . '</span>') . '<span class="zg-dim">' . self::Day((int) $u['created']) . '</span></div>';
        });
        $sec .= self::Section('Mailboxes', $a['mailbox_list'], function ($m) use ($a) {
            $on = (int) $m['enabled'] === 1;
            return '<div class="zg-line"><b>' . self::h($m['address']) . '</b>' . ($on ? '<span class="zg-pill is-ok">' . self::t('On') . '</span>' : '<span class="zg-pill is-bad">' . self::t('Off') . '</span>')
                . '<span class="zg-dim">' . self::Day((int) $m['created']) . '</span>'
                . '<form method="post" class="zg-inline" action="' . self::Link(array('tab' => 'account', 'id' => $a['id'], 'action' => 'Mailbox')) . '">' . runtime_csfr::Token()
                . '<input type="hidden" name="inMb" value="' . (int) $m['id'] . '"><input type="hidden" name="inOn" value="' . ($on ? '0' : '1') . '">'
                . '<button class="btn btn-default btn-xs" type="submit">' . self::t($on ? 'Switch off' : 'Switch on') . '</button></form></div>';
        });
        $sec .= self::Section('DNS records', $a['dns_list'], function ($z) {
            $out = '<div class="zg-zone"><b>' . self::h($z['domain']) . '</b> ' . self::Chip(count($z['records']), 'records') . '</div>';
            foreach ($z['records'] as $r) {
                $out .= '<div class="zg-line zg-rec"><span class="zg-pill">' . self::h($r['type']) . '</span><b>' . self::h($r['host']) . '</b><span>' . self::h($r['target'])
                    . (in_array($r['type'], array('MX', 'SRV'), true) ? ' · ' . self::t('priority %s', array((int) $r['priority'])) : '') . '</span><span class="zg-dim">' . self::t('TTL %s', array((int) $r['ttl'])) . '</span></div>';
            }
            return $out;
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
        if (zgod::HasDocker()) {
            $sec .= '<div class="zg-card zg-sec zg-span"><h4>' . self::t('Docker apps') . ' <span class="zg-count-pill">' . count($a['docker_list']) . '</span></h4>'
                . self::Table(array('App', 'State', 'Address', 'Size', 'Disk used', 'Node', 'Created'), array_map(function ($r) { return self::DockerCells($r, false); }, $a['docker_list'])) . '</div>';
        }
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
            return '<div class="zg-line"><span>' . self::h(date('Y-m-d H:i', zgod::When($l['t']))) . '</span><b>' . self::h($l['module']) . '</b><span>' . self::h(mb_strimwidth((string) $l['detail'], 0, 200, '…')) . '</span></div>';
        });
        return $back . $head . '<div class="zg-grid">' . $sec . '</div>';
    }

    /**
     * Change or delete the account where the panel does it (Manage Clients: its checks, its clean-up, its confirmation);
     * zGod itself never deletes.
     *
     * @param array $a
     * @param int $me
     * @return string
     */
    static function Manage(array $a, $me)
    {
        if ($a['id'] === $me || $a['grp'] === zgod::ADMINS) {
            return '';
        }
        $u = './?module=manage_clients&amp;other=' . (int) $a['id'] . '&amp;show=';
        return '<div class="zg-manage"><a href="' . $u . 'Edit">' . self::t('Edit in Manage Clients') . '</a><a class="zg-del" href="' . $u . 'Delete">' . self::t('Delete in Manage Clients') . '</a></div>';
    }

    /**
     * Shadow: open the panel as this account, through the panel's own Shadowing module (its checks, its log; back with
     * the panel's "return to your own account"). Not for yourself, and only when Shadowing is there.
     *
     * @param array $a
     * @param int $me
     * @return string
     */
    static function Shadow(array $a, $me)
    {
        if ($a['id'] === $me || !zgod::CanShadow()) {
            return '';
        }
        return '<form method="post" class="zg-shadow" action="./?module=shadowing&amp;action=ShadowUser">' . runtime_csfr::Token()
            . '<input type="hidden" name="inShadow_' . (int) $a['id'] . '" value="1"><input type="hidden" name="inReturn" value="zgod">'
            . '<button class="btn btn-default btn-sm" type="submit">' . self::Ico('<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>', 'zg-ico') . ' ' . self::t('Shadow %s', array($a['username'])) . '</button></form>';
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
