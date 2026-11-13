<?php

/**
 * QuickFig: the account's websites, and for a WordPress site its wp-config.php settings as a form,
 * with rescue tools (a site stuck in maintenance, a plugin that breaks the site, the cache, new
 * secret keys, the database connection, the debug log) and its earlier versions; for a Magento
 * shop its cache and maintenance mode. Every file is read and changed by QuickFig's worker,
 * confined to the account's own folder (quickfig::Run(), helper.php).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
require_once dirname(__FILE__) . '/quickfig.php';
require_once dirname(__FILE__) . '/jobs.php';
require_once dirname(__FILE__) . '/watch.php';

class module_controller extends ctrl_module
{
    /** @var array<int, array{0:string, 1:string}> Messages: [type ('ok'|'error'), text]. */
    static $msg = array();

    /** @var array|null The site's state, read once per page. */
    static $state = null;

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
     * @param string $s English.
     * @return string
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * The logged-in account.
     *
     * @return int
     */
    static function Uid()
    {
        return (int) ctrl_users::GetUserDetail()['userid'];
    }

    /**
     * The site asked for (one of the account's own), or null.
     *
     * @return array|null
     */
    static function Site()
    {
        $id = isset($_GET['site']) && ctype_digit((string) $_GET['site']) ? (int) $_GET['site'] : 0;
        $sites = quickfig::Sites(self::Uid());
        return isset($sites[$id]) ? $sites[$id] : null;
    }

    /**
     * The folder inside the site WordPress or Magento is in ('' for the site's own folder).
     *
     * @return string
     */
    static function Sub()
    {
        $s = isset($_GET['sub']) ? trim((string) $_GET['sub'], '/') : '';
        return preg_match('#^[A-Za-z0-9._/-]{0,200}$#', $s) && !preg_match('#(^|/)\.\.?(/|$)#', $s) ? $s : '';
    }

    /**
     * Ask the worker about the site asked for.
     *
     * @param string $op
     * @param array $extra
     * @return array
     */
    static function Ask($op, array $extra = array())
    {
        $site = self::Site();
        if (!$site) {
            return array('error' => 'No such website of yours.');
        }
        return quickfig::Run(self::Uid(), $site, $op, array('sub' => self::Sub()) + $extra);
    }

    /**
     * Note the worker's answer as a message.
     *
     * @param array $r
     * @param string $ok The message when it worked.
     * @param array $args Words for %s in it.
     * @return bool Whether it worked.
     */
    static function Note(array $r, $ok, array $args = array())
    {
        if (isset($r['error'])) {
            self::$msg[] = array('error', ui_language::translate($r['error']));
            return false;
        }
        $text = ui_language::translate($ok);
        foreach ($args as $a) {
            $text = preg_replace('/%s/', str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $a), $text, 1);
        }
        self::$msg[] = array('ok', $text);
        self::$state = null;
        return true;
    }

    /**
     * Save the WordPress settings.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doSave()
    {
        global $controller;
        runtime_csfr::Protect();
        $f = $controller->GetAllControllerRequests('FORM');
        $now = self::Ask('wp_read');
        if (isset($now['error'])) {
            self::Note($now, '');
            return;
        }
        $set = array();
        $unset = array();
        foreach (quickfig::Settings() as $name => $s) {
            $cur = isset($now['defines'][$name]) ? $now['defines'][$name] : null;
            // (a setting the form did not send stays as it is; one set by code is never touched)
            if (($cur && $cur['kind'] === 'expr') || !isset($f['q_' . $name])) {
                continue;
            }
            $v = trim((string) $f['q_' . $name]);
            if ($v === '') {
                if ($cur) {
                    $unset[] = $name;
                }
                continue;
            }
            if ($s[3] === 'url') {
                $v = rtrim($v, '/');
                if (!preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~%/+-]*)?$#', $v)) {
                    self::$msg[] = array('error', ui_language::translate($s[1]) . ': ' . ui_language::translate('an address such as https://www.example.com'));
                    return;
                }
                $set[$name] = array('t' => 'string', 'v' => $v);
            } elseif ($s[3] === 'bool') {
                $set[$name] = array('t' => 'bool', 'v' => $v === 'on');
            } elseif (isset($s[4][$v])) {
                $pv = $s[4][$v][1];
                $set[$name] = is_bool($pv) ? array('t' => 'bool', 'v' => $pv) : (is_int($pv) ? array('t' => 'int', 'v' => $pv) : array('t' => 'string', 'v' => $pv));
            }
        }
        // debug mode: off, a log only, or a log and errors on the pages
        $debug = isset($f['q_debug']) ? (string) $f['q_debug'] : '';
        $logIsPath = isset($now['defines']['WP_DEBUG_LOG']) && $now['defines']['WP_DEBUG_LOG']['kind'] !== 'bool';
        if ($debug === 'off') {
            $set['WP_DEBUG'] = array('t' => 'bool', 'v' => false);
            foreach (array('WP_DEBUG_DISPLAY') as $n) {
                if (isset($now['defines'][$n]) && $now['defines'][$n]['kind'] !== 'expr') {
                    $unset[] = $n;
                }
            }
            if (isset($now['defines']['WP_DEBUG_LOG']) && !$logIsPath) {
                $unset[] = 'WP_DEBUG_LOG';
            }
        } elseif ($debug === 'log' || $debug === 'show') {
            $set['WP_DEBUG'] = array('t' => 'bool', 'v' => true);
            if (!$logIsPath) {
                $set['WP_DEBUG_LOG'] = array('t' => 'bool', 'v' => true);
            }
            $set['WP_DEBUG_DISPLAY'] = array('t' => 'bool', 'v' => $debug === 'show');
        }
        $r = self::Ask('wp_write', array('set' => $set, 'unset' => $unset));
        if (!self::Note($r, empty($r['changed']) ? 'Nothing to change.' : 'Saved: WordPress uses the new settings at its next page.')) {
            return;
        }
        if ($debug === 'log' || $debug === 'show') {
            // the debug log in wp-content is never served to visitors
            self::Ask('wp_protectlog');
        }
        // the panel task for wp-cron.php follows DISABLE_WP_CRON
        $after = self::Ask('wp_read');
        if (!isset($after['error'])) {
            $cronOff = isset($after['defines']['DISABLE_WP_CRON']) && $after['defines']['DISABLE_WP_CRON']['value'] === true;
            $root = (string) $after['root'];
            if (strpos($root, 'public_html/') === 0) {
                $why = quickfig::SetCron(self::Uid(), (string) ctrl_users::GetUserDetail()['username'], substr($root, strlen('public_html/')) . '/wp-cron.php', $cronOff);
                if ($why !== '') {
                    self::$msg[] = array('error', ui_language::translate($why));
                }
            }
        }
    }

    /** New secret keys. @return void */
    static function doSalts()
    {
        runtime_csfr::Protect();
        self::Note(self::Ask('wp_salts'), 'New secret keys: everyone, you too, is logged out of WordPress and logs in again.');
    }

    /** Put an earlier version back. @return void */
    static function doUndo()
    {
        global $controller;
        runtime_csfr::Protect();
        self::Note(self::Ask('wp_undo', array('file' => (string) $controller->GetControllerRequest('FORM', 'inFile'))), 'The earlier version is back (the one it replaced is kept too).');
    }

    /** The site stuck in maintenance. @return void */
    static function doMaintOff()
    {
        runtime_csfr::Protect();
        self::Note(self::Ask('wp_maintenance_off'), 'The maintenance file is removed: the site shows normally.');
    }

    /** A plugin off or back on. @return void */
    static function doPlugin()
    {
        global $controller;
        runtime_csfr::Protect();
        $name = (string) $controller->GetControllerRequest('FORM', 'inPlugin');
        $off = $controller->GetControllerRequest('FORM', 'inOff') === '1';
        self::Note(self::Ask('wp_plugin', array('plugin' => $name, 'off' => $off)),
            $off ? 'Plugin %s is switched off (its folder renamed). Put it back here when the site works again.' : 'Plugin %s is back: switch it on again in WordPress (Plugins).', array($name));
    }

    /** Empty the cache. @return void */
    static function doCache()
    {
        runtime_csfr::Protect();
        $r = self::Ask('wp_cache');
        self::Note($r, 'The cache is empty (%s files).', array(isset($r['files']) ? (int) $r['files'] : 0));
    }

    /** Test the database connection. @return void */
    static function doDbTest()
    {
        runtime_csfr::Protect();
        $r = self::Ask('wp_dbtest');
        if (isset($r['ok']) && $r['ok'] === false) {
            self::$msg[] = array('error', ui_language::translate('WordPress can not reach its database with the settings in wp-config.php.') . ' ' . (string) $r['why']);
            return;
        }
        self::Note($r, 'WordPress reaches its database with the settings in wp-config.php.');
    }

    /** Empty the debug log. @return void */
    static function doLogClear()
    {
        runtime_csfr::Protect();
        self::Note(self::Ask('wp_debuglog', array('clear' => true)), 'The debug log is empty.');
    }

    /**
     * Switch the site to https: its addresses in wp-config.php, then (in the background, WP-CLI) the
     * links in its database. Only with a certificate in use.
     *
     * @return void
     */
    static function doHttps()
    {
        runtime_csfr::Protect();
        $site = self::Site();
        if (!$site) {
            return;
        }
        if (!quickfig::HasSsl($site['id'])) {
            self::$msg[] = array('error', ui_language::translate('The site has no certificate yet: get one in Sencrypt SSL first (switching without one would break the site).'));
            return;
        }
        $address = $site['name'] . (self::Sub() !== '' ? '/' . self::Sub() : '');
        $r = self::Ask('wp_write', array('set' => array('WP_HOME' => array('t' => 'string', 'v' => 'https://' . $address), 'WP_SITEURL' => array('t' => 'string', 'v' => 'https://' . $address))));
        if (!self::Note($r, 'WordPress\'s addresses are https://%s now.', array($address))) {
            return;
        }
        $why = quickfig_jobs::Queue(self::Uid(), $site['id'], self::Sub(), 'wp_https', $address);
        self::$msg[] = $why === '' ? array('ok', ui_language::translate('The links in the database follow within 5 minutes (Background tasks, below).'))
            : array('error', ui_language::translate($why));
    }

    /**
     * Magento: queue one of its own tools.
     *
     * @global runtime_controller $controller The request controller.
     * @return void
     */
    static function doMageJob()
    {
        global $controller;
        runtime_csfr::Protect();
        $site = self::Site();
        $op = (string) $controller->GetControllerRequest('FORM', 'inOp');
        if (!$site || strpos($op, 'mage_') !== 0) {
            return;
        }
        $d = self::Ask('detect');
        if (!isset($d['type']) || $d['type'] !== 'magento') {
            self::$msg[] = array('error', ui_language::translate(isset($d['error']) ? (string) $d['error'] : 'There is no Magento in that folder.'));
            return;
        }
        $why =quickfig_jobs::Queue(self::Uid(), $site['id'], self::Sub(), $op, (string) $controller->GetControllerRequest('FORM', 'inArg'));
        self::$msg[] = $why === '' ? array('ok', ui_language::translate('Queued: it starts within 5 minutes; the result shows under Background tasks.'))
            : array('error', ui_language::translate($why));
    }

    /** Magento: empty the cache. @return void */
    static function doMageCache()
    {
        runtime_csfr::Protect();
        $r = self::Ask('mage_cache');
        self::Note($r, 'Magento\'s cache is empty (%s files): the first pages are slower while it fills again.', array(isset($r['files']) ? (int) $r['files'] : 0));
    }

    /**
     * Save the crawlers turned away and the robots.txt section.
     *
     * @return void
     */
    /** @var array|null The permissions check just run (shown on this page view only). */
    static $perm = null;

    static function doPermScan()
    {
        runtime_csfr::Protect();
        $r = self::Ask('perm_scan');
        if (isset($r['error'])) {
            self::Note($r, '');
            return;
        }
        self::$perm = $r;
    }

    static function doPermFix()
    {
        runtime_csfr::Protect();
        $site = self::Site();
        if (!$site) {
            return;
        }
        $why = quickfig_jobs::Queue(self::Uid(), $site['id'], self::Sub(), 'perm_fix');
        self::$msg[] = $why === '' ? array('ok', ui_language::translate('Queued: it starts within 5 minutes; the result shows under Background tasks.'))
            : array('error', ui_language::translate($why));
    }

    static function doHarden()
    {
        runtime_csfr::Protect();
        $site = self::Site();
        $opts = array();
        foreach (array('xmlrpc', 'headers', 'hsts', 'https') as $o) {
            if (!empty($_POST['in_' . $o])) {
                $opts[] = $o;
            }
        }
        // https and its year-long promise only for a website that has its certificate
        if (!$site || !quickfig::HasSsl($site['id'])) {
            $opts = array_values(array_diff($opts, array('https', 'hsts')));
        }
        $www = isset($_POST['inWww']) && in_array($_POST['inWww'], array('www', 'bare'), true) && $site && (int) $site['type'] === 1 ? $_POST['inWww'] : '';
        $r = self::Ask('harden_write', array('options' => $opts, 'www' => $www, 'nophp' => !empty($_POST['in_nophp'])));
        if (self::Note($r, 'Hardening saved: %s switch(es) on.', array(count($opts) + ($www !== '' ? 1 : 0) + (!empty($r['nophp']) ? 1 : 0)))) {
            self::$msg[] = array('ok', ui_language::translate('The earlier .htaccess files are in your quickfig-backups folder.'));
        }
    }

    static function doBlockAgent()
    {
        runtime_csfr::Protect();
        $site = self::Site();
        $want = isset($_POST['inBlock']) && is_string($_POST['inBlock']) ? $_POST['inBlock'] : '';
        // only a name of this website's own visitors list (nothing typed reaches the file from here)
        $v = $site ? quickfig_watch::Visitors($site['id']) : null;
        $name = '';
        foreach ($v ? $v['agents'] : array() as $a) {
            if ($a[2] !== '' && $a[2] === $want && in_array($a[1], array('bot', 'tool', 'other'), true)) {
                $name = $a[2];
            }
        }
        if ($name === '' || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/ -]{2,59}$#', $name)) {
            self::$msg[] = array('error', ui_language::translate('That name can not be blocked from here.'));
            return;
        }
        $b = self::Ask('bots_read');
        if (isset($b['error'])) {
            self::Note($b, '');
            return;
        }
        $names = (array) $b['names'];
        $names[] = $name;
        $r = self::Ask('bots_write', array('names' => $names, 'oldchrome' => (int) $b['oldchrome'], 'robots' => !empty($b['robots'])));
        self::Note($r, '%s is turned away from now on ("403 Forbidden"). It is in the list below; untick or remove it there to let it in again.', array($name));
    }

    static function doBots()
    {
        runtime_csfr::Protect();
        $names = array();
        // the ones of the list: by their place in it (nothing typed reaches the file from there)
        $list = array_keys(quickfig::Bots());
        foreach ((array) ($_POST['inBot'] ?? array()) as $i) {
            if (is_string($i) && ctype_digit($i) && isset($list[(int) $i])) {
                $names[] = $list[(int) $i];
            }
        }
        $extra = isset($_POST['inBotExtra']) && is_string($_POST['inBotExtra']) ? $_POST['inBotExtra'] : '';
        foreach (preg_split('/[\r\n,]+/', $extra) as $n) {
            $n = trim($n);
            if ($n === '') {
                continue;
            }
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/ -]{2,59}$#', $n)) {
                self::$msg[] = array('error', ui_language::translate('A crawler\'s name has 3 to 60 letters, digits, dots, dashes, slashes or spaces (one per line):') . ' ' . mb_substr($n, 0, 60));
                return;
            }
            // never a search engine or a whole browser by mistake
            if (preg_match('/^(mozilla|chrome|safari|firefox|edge|edg|opera|applewebkit|gecko|khtml|windows|android|iphone|linux|mobile|googlebot|bingbot|duckduckbot|applebot|yandex|baiduspider)$/i', $n)) {
                self::$msg[] = array('error', ui_language::translate('That name is part of every browser or a search engine: it would turn away real visitors or search engines.') . ' ' . $n);
                return;
            }
            $names[] = $n;
        }
        $r = self::Ask('bots_write', array('names' => $names, 'oldchrome' => isset($_POST['inOldChrome']) ? quickfig::OldChrome() : 0, 'robots' => isset($_POST['inRobots'])));
        if (self::Note($r, 'Saved: %s crawler names turned away%s%s.', array(isset($r['names']) ? (int) $r['names'] : 0,
            !empty($r['oldchrome']) ? ', and Chrome older than version ' . (int) $r['oldchrome'] : '', !empty($r['robots']) ? ', and robots.txt asks crawlers to keep off search pages' : ''))) {
            self::$msg[] = array('ok', ui_language::translate('The earlier .htaccess and robots.txt are in your quickfig-backups folder.'));
        }
    }

    /** Magento: delete the searches that found nothing (bots' junk). @return void */
    static function doMageSearch()
    {
        runtime_csfr::Protect();
        // all of them now (days 0); a big table takes more than one go (20 seconds each)
        $r = self::Ask('mage_search_clean', array('days' => 0));
        if (self::Note($r, '%s searches that found nothing are deleted; the searches that found products stay.', array(isset($r['deleted']) ? number_format((int) $r['deleted']) : 0)) && !empty($r['more'])) {
            self::$msg[] = array('ok', ui_language::translate('There are more: click again (or leave it to the weekly clean-up).'));
        }
    }

    /** Magento: maintenance mode on or off. @return void */
    static function doMageMaint()
    {
        global $controller;
        runtime_csfr::Protect();
        $on = $controller->GetControllerRequest('FORM', 'inOn') === '1';
        self::Note(self::Ask('mage_maintenance', array('on' => $on)), $on ? 'Maintenance mode is on: visitors see Magento\'s maintenance page.' : 'Maintenance mode is off: the shop is open.');
    }

    /**
     * An inline icon.
     *
     * @param string $n
     * @return string
     */
    static function Icon($n)
    {
        $p = array(
            'wp' => '<circle cx="12" cy="12" r="9"/><path d="M5 8l4 11 3-8 3 8 4-11"/>',
            'shop' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
            'folder' => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9L9.6 3.9A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
            'tools' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9z"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
            'back' => '<path d="m15 18-6-6 6-6"/>',
            'lock' => '<rect width="16" height="10" x="4" y="11" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'log' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'refresh' => '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
        );
        return '<svg class="qf-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
    }

    /**
     * The site's state (what is there, and for WordPress its settings), read once per page.
     *
     * @return array
     */
    static function State()
    {
        if (self::$state === null) {
            $d = self::Ask('detect');
            if (isset($d['type']) && $d['type'] === 'wordpress') {
                $d += self::Ask('wp_read');
            } elseif (isset($d['type']) && $d['type'] === 'magento') {
                $d += self::Ask('mage_read');
            }
            self::$state = $d;
        }
        return self::$state;
    }

    /**
     * Size in words.
     *
     * @param int $b
     * @return string
     */
    static function Size($b)
    {
        return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : ($b >= 1024 ? round($b / 1024) . ' KB' : (int) $b . ' B');
    }

    /**
     * A form button for an action of this site.
     *
     * @param string $action
     * @param string $label
     * @param string $class
     * @param array $fields
     * @param string $ask A question to confirm first ('' for none).
     * @return string
     */
    static function Button($action, $label, $class = 'btn-default', array $fields = array(), $ask = '')
    {
        $url = './?module=quickfig&amp;site=' . (int) self::Site()['id'] . (self::Sub() !== '' ? '&amp;sub=' . rawurlencode(self::Sub()) : '') . '&amp;action=' . $action;
        $out = '<form class="qf-inline" action="' . $url . '" method="post"' . ($ask !== '' ? ' onsubmit="return confirm(this.dataset.ask)" data-ask="' . self::t($ask) . '"' : '') . '>';
        foreach ($fields as $k => $v) {
            $out .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        }
        return $out . runtime_csfr::Token() . '<button class="btn btn-sm ' . $class . '" type="submit">' . self::t($label) . '</button></form>';
    }

    /**
     * <@ Page @>: the list of sites, or a site's page.
     *
     * @return string HTML.
     */
    static function getPage()
    {
        // (the template asks twice, compiling and then running the page: the sites are looked at once)
        static $page = null;
        if ($page !== null) {
            return $page;
        }
        return $page = self::PageHtml();
    }

    /**
     * The page (see getPage()).
     *
     * @return string HTML.
     */
    static function PageHtml()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= '<div class="qf-msg ' . ($m[0] === 'ok' ? 'is-ok' : 'is-bad') . '" role="' . ($m[0] === 'ok' ? 'status' : 'alert') . '">' . self::h($m[1]) . '</div>';
        }
        $site = self::Site();
        if (!$site) {
            return $out . self::SiteList();
        }
        unset($_SESSION['quickfig_detect']);
        $st = self::State();
        $head = '<div class="qf-head"><a class="btn btn-default btn-sm" href="./?module=quickfig">' . self::Icon('back') . self::t('All websites') . '</a>'
            . '<h2>' . self::h($site['name']) . (self::Sub() !== '' ? ' <span class="qf-sub">/' . self::h(self::Sub()) . '</span>' : '') . '</h2></div>';
        $roots = isset($_SESSION['quickfig_roots']) && is_array($_SESSION['quickfig_roots']) ? $_SESSION['quickfig_roots'] : array();
        if (!empty($st['root'])) {
            $roots[$site['id']] = (string) $st['root'];
        }
        $same = quickfig::SameFiles(quickfig::Sites(self::Uid()), $roots);
        if (isset($same[$site['id']])) {
            $head .= '<div class="qf-msg is-info" role="note">' . self::Icon('folder') . self::h(str_replace('%s', implode(', ', $same[$site['id']]), self::t('These are the same files as %s (their domains point at the same folder): what you change here changes those too, so there is no need to set them up separately.'))) . '</div>';
        }
        if (isset($st['error'])) {
            return $out . $head . '<div class="qf-card"><p class="qf-bad">' . self::h(ui_language::translate($st['error'])) . '</p>' . self::SubForm($site) . '</div>';
        }
        if ($st['type'] === 'wordpress') {
            return $out . $head . self::WordPress($st);
        }
        if ($st['type'] === 'magento') {
            return $out . $head . self::Magento($st);
        }
        // another web app QuickFig knows, or a plain website: no settings page, but the crawler blocker
        $found = !empty($st['appname'])
            ? str_replace('%s', '<b>' . self::h($st['appname']) . '</b>', self::t('QuickFig found %s in this website\'s folder. QuickFig has no settings page for it yet; its crawler blocker below works for it.'))
            : self::t('QuickFig found no WordPress, Magento or other web app it knows in this website\'s folder. The crawler blocker below works for any website.');
        return $out . $head . '<div class="qf-card"><p>' . $found . '</p>' . self::SubForm($site) . '</div>' . self::Crawlers() . self::Hardening() . self::Permissions() . self::Jobs();
    }

    /**
     * The account's websites, each with what QuickFig finds in it.
     *
     * @return string HTML.
     */
    static function SiteList()
    {
        $sites = quickfig::Sites(self::Uid());
        if (!$sites) {
            return '<div class="qf-card"><p>' . self::t('You have no websites yet: add a domain first.') . '</p></div>';
        }
        $found = $roots = array();
        // what is in each site, remembered for 5 minutes (a site's own page forgets it: things may change there)
        $key = sha1(self::Uid() . json_encode($sites));
        $memo = isset($_SESSION['quickfig_detect']) && is_array($_SESSION['quickfig_detect']) && $_SESSION['quickfig_detect']['k'] === $key
            && $_SESSION['quickfig_detect']['t'] > time() - 300 ? $_SESSION['quickfig_detect']['d'] : array();
        foreach ($sites as $s) {
            $found[$s['id']] = isset($memo[$s['id']]) ? $memo[$s['id']] : quickfig::Run(self::Uid(), $s, 'detect');
            if (!empty($found[$s['id']]['root'])) {
                $roots[$s['id']] = (string) $found[$s['id']]['root'];
            }
        }
        // (kept for a site's own page, which does not look at every site again)
        $_SESSION['quickfig_roots'] = $roots;
        if (!$memo) {
            $_SESSION['quickfig_detect'] = array('k' => $key, 't' => time(), 'd' => $found);
        }
        $same = quickfig::SameFiles($sites, $roots);
        $cards = '';
        foreach ($sites as $s) {
            $d = $found[$s['id']];
            $type = isset($d['type']) ? $d['type'] : 'none';
            $badge = $type === 'wordpress' ? '<span class="qf-pill is-wp">' . self::Icon('wp') . 'WordPress</span>'
                : ($type === 'magento' ? '<span class="qf-pill is-mage">' . self::Icon('shop') . 'Magento</span>'
                : (!empty($d['appname']) ? '<span class="qf-pill is-app">' . self::h($d['appname']) . '</span>' : '<span class="qf-pill">' . self::t('Nothing found') . '</span>'));
            $cards .= '<a class="qf-site" href="./?module=quickfig&amp;site=' . (int) $s['id'] . '"><b>' . self::h($s['name']) . '</b>' . $badge
                . (isset($same[$s['id']]) ? '<small class="qf-same">' . self::Icon('folder') . self::h(str_replace('%s', implode(', ', $same[$s['id']]), self::t('Same files as %s'))) . '</small>' : '')
                . '<small>' . self::t($type === 'none' ? 'Open to block crawlers, or look in a folder of the site' : 'Open its settings') . '</small></a>';
        }
        return '<p class="qf-intro">' . self::t('Choose a website. QuickFig finds WordPress (and Magento) by itself; for one in a folder of the site, open the site and give the folder.')
            . ($same ? ' ' . self::t('Websites marked "Same files as" are one website under several names (their domains point at the same folder): a setting changed on one of them is changed on all of them.') : '')
            . '</p><div class="qf-sites">' . $cards . '</div>';
    }

    /**
     * The form to look in a folder of the site.
     *
     * @param array $site
     * @return string HTML.
     */
    static function SubForm(array $site)
    {
        return '<form class="qf-subform" action="./" method="get"><input type="hidden" name="module" value="quickfig"><input type="hidden" name="site" value="' . (int) $site['id'] . '">'
            . '<label class="qf-field"><span>' . self::t('WordPress (or Magento) is in this folder of the site') . '</span><input class="form-control" type="text" name="sub" value="' . self::h(self::Sub()) . '" placeholder="blog" pattern="[A-Za-z0-9._/-]+"></label>'
            . '<button class="btn btn-default" type="submit">' . self::Icon('folder') . self::t('Look there') . '</button></form>';
    }

    /**
     * A WordPress site: its settings, rescue tools, the debug log, earlier versions.
     *
     * @param array $st
     * @return string HTML.
     */
    static function WordPress(array $st)
    {
        $def = isset($st['defines']) ? $st['defines'] : array();
        $url = './?module=quickfig&amp;site=' . (int) self::Site()['id'] . (self::Sub() !== '' ? '&amp;sub=' . rawurlencode(self::Sub()) : '');
        // the settings, by group
        $groups = array();
        foreach (quickfig::Settings() as $name => $s) {
            $cur = isset($def[$name]) ? $def[$name] : null;
            $field = '<label class="qf-field"><span>' . self::t($s[1]) . ' <code>' . self::h($name) . '</code></span>';
            if ($cur && $cur['kind'] === 'expr') {
                $field .= '<input class="form-control" type="text" value="' . self::t('set by code in wp-config.php') . '" disabled>';
            } elseif ($s[3] === 'url') {
                $field .= '<input class="form-control" type="text" name="q_' . $name . '" value="' . self::h($cur ? $cur['value'] : '') . '" placeholder="' . self::t('WordPress\'s own setting (in its database)') . '">';
            } else {
                $opts = array('' => 'WordPress\'s default');
                $sel = '';
                if ($s[3] === 'bool') {
                    $opts += array('on' => 'On', 'off' => 'Off');
                    $sel = $cur ? ($cur['value'] ? 'on' : 'off') : '';
                } else {
                    foreach ($s[4] as $k => $o) {
                        $opts[$k] = $o[0];
                        if ($cur && $cur['value'] === $o[1]) {
                            $sel = (string) $k;
                        }
                    }
                }
                $field .= '<select class="form-control" name="q_' . $name . '">';
                foreach ($opts as $k => $label) {
                    $field .= '<option value="' . self::h($k) . '"' . ((string) $k === $sel ? ' selected' : '') . '>' . self::t($label) . '</option>';
                }
                $field .= '</select>';
            }
            $groups[$s[0]][] = $field . '<small>' . self::t($s[2]) . '</small></label>';
        }
        $debugOn = isset($def['WP_DEBUG']) && $def['WP_DEBUG']['value'] === true;
        $show = $debugOn && isset($def['WP_DEBUG_DISPLAY']) && $def['WP_DEBUG_DISPLAY']['value'] === true;
        $dsel = $debugOn ? ($show ? 'show' : (isset($def['WP_DEBUG_DISPLAY']) ? 'log' : 'show')) : 'off';
        $dopts = '';
        foreach (array('off' => 'Off', 'log' => 'On: errors go to a log only you see (below)', 'show' => 'On: errors also show on the pages (visitors see them too)') as $k => $label) {
            $dopts .= '<option value="' . $k . '"' . ($k === $dsel ? ' selected' : '') . '>' . self::t($label) . '</option>';
        }
        $groups['debug'][] = '<label class="qf-field"><span>' . self::t('Debug mode') . ' <code>WP_DEBUG</code></span><select class="form-control" name="q_debug">' . $dopts . '</select>'
            . '<small>' . self::t('For finding what breaks a site: PHP errors and warnings are written down. Turn it off again when done.') . '</small></label>';
        $names = quickfig::Groups() + array('debug' => 'Debugging');
        $form = '';
        foreach ($names as $g => $title) {
            if (!empty($groups[$g])) {
                $form .= '<fieldset class="qf-group"><legend>' . self::t($title) . '</legend>' . implode('', $groups[$g]) . '</fieldset>';
            }
        }
        $info = '<dl class="qf-dl"><dt>' . self::t('Settings file') . '</dt><dd><code>' . self::h($st['config']) . '</code></dd>';
        foreach (array('DB_NAME' => 'Database', 'DB_USER' => 'Database user', 'DB_HOST' => 'Database server') as $k => $label) {
            $info .= '<dt>' . self::t($label) . '</dt><dd>' . (isset($def[$k]) && $def[$k]['kind'] === 'string' ? self::h($def[$k]['value']) : '<span class="qf-muted">' . self::t('set by code') . '</span>') . '</dd>';
        }
        $info .= '<dt>' . self::t('Table prefix') . '</dt><dd>' . self::h((string) $st['prefix']) . '</dd></dl>';
        $out = '<div class="qf-grid"><form class="qf-card qf-settings" action="' . $url . '&amp;action=Save" method="post"><h3>' . self::Icon('wp') . self::t('WordPress settings') . '</h3>'
            . $form . '<div class="qf-foot">' . runtime_csfr::Token() . '<span class="qf-muted">' . self::t('Each change keeps the file as it was first (Earlier versions).') . '</span>'
            . '<button class="btn btn-primary" type="submit">' . self::t('Save') . '</button></div></form><div class="qf-side">';
        // rescue tools
        $tools = '';
        if (!empty($st['maintenance']) && empty($st['maintenance_shown'])) {
            // WordPress ignores a .maintenance file 10 minutes after the update began
            $tools .= '<div class="qf-tool"><b>' . self::t('An update did not finish') . '</b><small>'
                . str_replace('%s', self::h(ui_language::Date('j M Y, H:i', (int) $st['maintenance'])), self::t('An update on %s stopped half-way and left its maintenance file behind. The site shows again by itself, but the update should be run again; removing the file tidies up.'))
                . '</small>' . self::Button('MaintOff', 'Remove the file') . '</div>';
        } elseif (!empty($st['maintenance'])) {
            $tools .='<div class="qf-tool is-warn"><b>' . self::t('The site shows "Briefly unavailable for scheduled maintenance"') . '</b><small>'
                . str_replace('%s', self::h(ui_language::Date('j M Y, H:i', (int) $st['maintenance'])), self::t('Since %s: the maintenance file in the site\'s folder keeps it there. Removing the notice brings the site back; then run any update that did not finish again.'))
                . '</small>' . self::Button('MaintOff', 'Remove the notice', 'btn-warning') . '</div>';
        }
        $tools .= '<div class="qf-tool"><b>' . self::t('Database connection') . '</b><small>' . self::t('Check that WordPress reaches its database ("Error establishing a database connection").') . '</small>' . self::Button('DbTest', 'Test it') . '</div>';
        $tools .= '<div class="qf-tool"><b>' . self::t('Cache') . '</b><small>' . str_replace('%s', self::h(self::Size((int) $st['cache'])), self::t('Cache plugins keep copies of pages in wp-content/cache (%s now). Empty it when changes do not show.')) . '</small>' . self::Button('Cache', 'Empty the cache') . '</div>';
        $tools .= '<div class="qf-tool"><b>' . self::t('New secret keys') . '</b><small>' . self::t('Logs everyone out of WordPress at once: after a break-in, or when a login may be known to someone else.') . '</small>'
            . self::Button('Salts', 'Make new keys', 'btn-default', array(), 'Everyone, you too, is logged out of WordPress. Go on?') . '</div>';
        $out .= '<div class="qf-card"><h3>' . self::Icon('tools') . self::t('Rescue tools') . '</h3>' . $tools . '</div>';
        // plugins
        $rows = '';
        foreach ((array) $st['plugins'] as $p) {
            $rows .= '<li><span>' . self::h($p['name']) . ($p['off'] ? ' <span class="qf-pill is-off">' . self::t('switched off') . '</span>' : '') . '</span>'
                . ($p['off'] ? self::Button('Plugin', 'Put back', 'btn-default', array('inPlugin' => $p['name'], 'inOff' => '0'))
                    : self::Button('Plugin', 'Switch off', 'btn-default', array('inPlugin' => $p['name'], 'inOff' => '1'), 'Switch this plugin off? Use it when the site breaks and you can not get into WordPress.')) . '</li>';
        }
        $out .= '<div class="qf-card"><h3>' . self::Icon('folder') . self::t('Plugins') . '</h3><p class="qf-muted">'
            . self::t('When a plugin breaks the site so you can not log in, switch it off here: its folder is renamed, so WordPress can not load it.') . '</p>'
            . ($rows !== '' ? '<ul class="qf-plugins">' . $rows . '</ul>' : '<p class="qf-muted">' . self::t('No plugins.') . '</p>') . '</div>';
        $out .= self::HttpsCard($st) . '<div class="qf-card"><h3>' . self::t('About this site') . '</h3>' . $info . '</div></div></div>' . self::WpUpdatesCard() . self::Crawlers() . self::Hardening() . self::Permissions() . self::Jobs();
        // debug log
        if ((int) $st['debuglog'] >= 0) {
            $log = self::Ask('wp_debuglog');
            $lines = isset($log['lines']) ? (array) $log['lines'] : array();
            $out .= '<div class="qf-card"><h3>' . self::Icon('log') . self::t('Debug log') . ' <span class="qf-muted">' . self::h(self::Size((int) $st['debuglog'])) . '</span></h3>'
                . ($lines ? '<pre class="qf-log">' . self::h(implode("\n", $lines)) . '</pre>' : '<p class="qf-muted">' . self::t('Empty.') . '</p>')
                . '<div class="qf-foot"><span class="qf-muted">' . self::t('The last 200 lines. It is never shown to visitors.') . '</span>' . self::Button('LogClear', 'Empty the log') . '</div></div>';
        }
        // earlier versions
        $vers = '';
        foreach ((array) $st['backups'] as $b) {
            $vers .= '<li><span>' . self::h(ui_language::Date('j M Y, H:i:s', (int) $b['time'])) . ' <span class="qf-muted">' . self::h(self::Size((int) $b['size'])) . '</span></span>'
                . self::Button('Undo', 'Put back', 'btn-default', array('inFile' => $b['file']), 'Put this version of wp-config.php back? The current one is kept too.') . '</li>';
        }
        $out .= '<div class="qf-card"><h3>' . self::Icon('clock') . self::t('Earlier versions of wp-config.php') . '</h3><p class="qf-muted">'
            . self::t('QuickFig keeps the file before each change (the last 10), in quickfig-backups in your home folder, outside the website.') . '</p>'
            . ($vers !== '' ? '<ul class="qf-versions">' . $vers . '</ul>' : '<p class="qf-muted">' . self::t('None yet.') . '</p>') . '</div>';
        return $out;
    }

    /**
     * A Magento shop: its mode, cache and maintenance mode.
     *
     * @param array $st
     * @return string HTML.
     */
    static function Magento(array $st)
    {
        $on = !empty($st['maintenance']);
        $tools = '<div class="qf-tool' . ($on ? ' is-warn' : '') . '"><b>' . self::t('Maintenance mode') . ': ' . self::t($on ? 'on' : 'off') . '</b><small>'
            . self::t('On: visitors see Magento\'s maintenance page while you work on the shop.') . '</small>'
            . ($on ? self::Button('MageMaint', 'Open the shop again', 'btn-warning', array('inOn' => '0'))
                : self::Button('MageMaint', 'Turn maintenance on', 'btn-default', array('inOn' => '1'), 'Close the shop to visitors?')) . '</div>';
        $tools .= '<div class="qf-tool"><b>' . self::t('Cache') . '</b><small>' . str_replace(array('%1', '%2'), array(self::h(self::Size((int) $st['cache'])), self::h(self::Size((int) $st['pagecache']))),
            self::t('Magento\'s cache (%1) and full-page cache (%2), in var/. Empty them when changes to settings, products or the design do not show.')) . '</small>' . self::Button('MageCache', 'Empty the caches') . '</div>';
        $job = function ($op, $label, $arg = '', $ask = '') {
            return self::Button('MageJob', $label, 'btn-default', array('inOp' => $op, 'inArg' => $arg), $ask);
        };
        $tools .= '<div class="qf-tool"><b>' . self::t('Magento\'s own tools') . '</b><small>'
            . self::t('Run by the server with bin/magento as your own account, in the background (within 5 minutes; the result shows below).') . '</small>'
            . '<div class="qf-btns">' . $job('mage_flush', 'Flush the caches') . $job('mage_reindex', 'Reindex')
            . ($st['mode'] === 'production' ? $job('mage_mode', 'Developer mode', 'developer', 'Switch the shop to developer mode? It is slower; use it while you work on the shop.')
                : $job('mage_mode', 'Production mode', 'production', 'Switch the shop to production mode? Magento compiles its code and builds its static files: this takes several minutes, and the shop may be slow meanwhile.'))
            . '</div></div>';
        // the search terms Magento keeps: bots fill them by the million, and the shop counts them on its pages
        $sq = self::Ask('mage_search_read');
        if (isset($sq['total'])) {
            $many = (int) $sq['empty'] > 100000;
            $tools .= '<div class="qf-tool' . ($many ? ' is-warn' : '') . '"><b>' . self::t('Search terms') . '</b><small>'
                . str_replace(array('%1', '%2', '%3'), array(self::h(number_format((int) $sq['total'])), self::h(number_format((int) $sq['empty'])), self::h(self::Size((int) $sq['bytes']))),
                    self::t('%1 searches saved (%3), %2 of them found nothing. Bots searching the shop fill this table with junk, and the shop counts it on its pages: a big one makes the database slow. QuickFig deletes searches that found nothing once a week (older than a week); this deletes them all now.'))
                . '</small>' . self::Button('MageSearch', 'Delete searches that found nothing', $many ? 'btn-warning' : 'btn-default', array(), 'Delete every saved search that found nothing? Searches that found products stay.') . '</div>';
        }
        return '<div class="qf-card"><h3>' . self::Icon('shop') . self::t('Magento') . '</h3><dl class="qf-dl"><dt>' . self::t('Settings file') . '</dt><dd><code>' . self::h($st['config']) . '</code></dd><dt>'
            . self::t('Mode') . '</dt><dd>' . self::h($st['mode']) . '</dd></dl>' . $tools
            . '<p class="qf-muted">' . self::t('Emptying the cache folders deletes var/cache and var/page_cache at once; Magento builds them again. Compiled code and static files are left alone.') . '</p></div>' . self::Crawlers() . self::Hardening() . self::Permissions() . self::Jobs();
    }

    /**
     * The crawlers card (WordPress and Magento).
     *
     * @return string HTML.
     */
    static function Crawlers()
    {
        $b = self::Ask('bots_read');
        if (isset($b['error'])) {
            return '';
        }
        $on = array_map('strtolower', (array) $b['names']);
        $groups = array('rules' => 'Ignore the rules or crawl very hard', 'seo' => 'SEO tools (useful only if you use that tool)', 'ai' => 'Collect pages for AI');
        $boxes = '';
        $list = array_keys(quickfig::Bots());
        foreach ($groups as $g => $label) {
            $boxes .= '<div class="qf-botgroup"><b>' . self::t($label) . '</b>';
            foreach ($list as $idx => $name) {
                $d = quickfig::Bots()[$name];
                if ($d[0] === $g) {
                    $boxes .= '<label class="qf-bot"><input type="checkbox" name="inBot[]" value="' . $idx . '"' . (in_array(strtolower($name), $on, true) ? ' checked' : '') . '> <span><b>'
                        . self::h($name) . '</b> ' . self::t($d[1]) . '</span></label>';
                }
            }
            $boxes .= '</div>';
        }
        $extra = array();
        foreach ((array) $b['names'] as $n) {
            if (!in_array(strtolower($n), array_map('strtolower', $list), true)) {
                $extra[] = $n;
            }
        }
        $site = self::Site();
        $url = './?module=quickfig&amp;site=' . (int) $site['id'] . (self::Sub() !== '' ? '&amp;sub=' . rawurlencode(self::Sub()) : '') . '&amp;action=Bots';
        return '<div class="qf-card"><h3>' . self::Icon('tools') . self::t('Crawlers') . '</h3><p class="qf-muted">'
            . self::t('Robots crawl every site, and some ask for page after page, especially search and filter pages: each one costs the database (a real case: a shop\'s MySQL at 200% CPU). The ones ticked get "403 Forbidden" from the web server before the site is even started; search engines (Google, Bing, DuckDuckGo, Apple, Yandex, Baidu) are never on this list.') . '</p>'
            . self::Visitors($on)
            . '<form method="post" action="' . $url . '">' . runtime_csfr::Token() . '<div class="qf-bots">' . $boxes . '</div>'
            . '<label class="qf-field"><span>' . self::t('Other crawler names, one per line') . '</span><textarea class="form-control" name="inBotExtra" rows="2" placeholder="BadBot">' . self::h(implode("\n", $extra)) . '</textarea>'
            . '<small>' . self::t('The name a crawler gives as its browser (in the site\'s access log). A part of it is enough; not a word every browser has, such as Mozilla or Chrome.') . '</small></label>'
            . '<label class="qf-bot"><input type="checkbox" name="inOldChrome" value="1"' . (!empty($b['oldchrome']) ? ' checked' : '') . '> <span><b>' . str_replace('%s', (string) quickfig::OldChrome(), self::t('Chrome older than version %s')) . '</b> '
            . self::t('Scrapers often pretend to be a Chrome that never updates; real Chrome updates itself, so an 18-month-old version is almost always a robot (the line moves on each time you save). Search engines that call themselves an old Chrome, as Bing does, are let through. A few real visitors can no longer update (Windows 7 and 8 stop at Chrome 109, some old Android phones earlier): they would see "Forbidden" too, so leave this off if your visitors use old computers.') . '</span></label>'
            . '<label class="qf-bot"><input type="checkbox" name="inRobots" value="1"' . (!empty($b['robots']) ? ' checked' : '') . '> <span><b>' . self::t('robots.txt: ask crawlers to keep off search pages') . '</b> '
            . ($b['kind'] === 'magento' ? self::t('Search, price filters, cart and customer pages (catalogsearch, ?q=, ?price=...), and a pause between pages. Search engines follow it; product and category pages stay open to them.')
                : ($b['kind'] === 'wordpress' ? (!empty($b['woo']) ? self::t('Search pages (?s=, /search/), WooCommerce\'s cart, checkout and account pages and its sorting and filter links, and a pause between pages. Search engines follow it; products, posts and pages stay open to them.')
                    : self::t('Search pages (?s=, /search/) and a pause between pages. Search engines follow it; posts and pages stay open to them.'))
                : (!empty($b['covers']) ? self::t($b['covers'])
                    : self::t('Search pages (/search, ?q=, ?s=, ?search=) and a pause between pages. Search engines follow it; the other pages stay open to them.')))) . '</span></label>'
            . '<div class="qf-foot"><small class="qf-muted">' . str_replace('%s', '<code>' . self::h($b['root']) . '</code>', self::t('Written in QuickFig\'s own marked part at the top of .htaccess and in robots.txt (in %s); the rest of both files stays as it is. Untick everything to take it out.')) . '</small>'
            . '<button class="btn btn-primary" type="submit">' . self::t('Save') . '</button></div></form></div>';
    }

    /**
     * WordPress's updates at a glance: the core, plugins and themes that have a newer version on
     * wordpress.org (asked as WordPress itself asks, kept 6 hours).
     *
     * @return string HTML.
     */
    static function WpUpdatesCard()
    {
        $v = self::Ask('wp_versions');
        if (isset($v['error']) || !isset($v['core'])) {
            return '';
        }
        $u = quickfig::WpUpdates($v);
        $head = '<div class="qf-card"><h3>' . self::Icon('refresh') . self::t('Updates') . '</h3>';
        if ($u === null) {
            return $head . '<p class="qf-muted">' . self::t('wordpress.org could not be asked right now; this is tried again the next time the page opens.') . '</p></div>';
        }
        $rows = '';
        if ($u['core'] !== '') {
            $rows .= '<tr><td><b>WordPress</b></td><td>' . self::h($v['core']) . '</td><td><b>' . self::h($u['core']) . '</b></td></tr>';
        }
        foreach (array('plugins' => 'Plugin', 'themes' => 'Theme') as $k => $what) {
            foreach ($u[$k] as $x) {
                $rows .= '<tr><td>' . self::h($x[0]) . ' <span class="qf-muted">(' . self::t($what) . ')</span></td><td>' . self::h($x[1]) . '</td><td><b>' . self::h($x[2]) . '</b></td></tr>';
            }
        }
        $count = count((array) $v['plugins']) + count((array) $v['themes']) + 1;
        if ($rows === '') {
            return $head . '<p class="qf-ok-line">' . self::Icon('check') . str_replace(array('%s', '%n'), array(self::h($v['core']), (string) $count), self::t('Everything is up to date: WordPress %s and its plugins and themes (%n in all, as wordpress.org knows them).')) . '</p></div>';
        }
        return $head . '<p class="qf-muted">' . self::t('Out-of-date plugins are the most common way into a WordPress site. Update them in WordPress (Dashboard > Updates); take a backup first for WordPress itself. Plugins and themes not from wordpress.org (bought ones) are not checked here.') . '</p>'
            . '<div class="qf-scroll"><table class="qf-table"><thead><tr><th>' . self::t('What') . '</th><th>' . self::t('Installed') . '</th><th>' . self::t('Newest') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
    }

    /**
     * The Permissions card: Check (on request: a big site takes a few seconds) and Fix (a background
     * task as the account's own user: only a file's owner may change who can use it).
     *
     * @return string HTML.
     */
    static function Permissions()
    {
        $site = self::Site();
        $base = './?module=quickfig&amp;site=' . (int) $site['id'] . (self::Sub() !== '' ? '&amp;sub=' . rawurlencode(self::Sub()) : '');
        $p = self::$perm;
        $body = '';
        if ($p !== null) {
            if ((int) $p['open'] === 0 && !$p['secrets']) {
                $body = '<p class="qf-ok-line">' . self::Icon('check') . str_replace('%s', number_format((int) $p['scanned']), self::t('All good: none of the %s files and folders can be changed by other users, and the config files with passwords are closed to them.')) . '</p>';
            } else {
                if ((int) $p['open'] > 0) {
                    $body .= '<p><b>' . str_replace('%s', number_format((int) $p['open']), self::t('%s files and folders anyone on the server could change')) . '</b> '
                        . self::t('(permission 777 or 666, usually from an FTP program or an old install guide). Another website on the server that gets hacked could change them too.') . '</p>'
                        . '<pre class="qf-log">' . self::h(implode("\n", (array) $p['list'])) . ((int) $p['open'] > count((array) $p['list']) ? "\n..." : '') . '</pre>';
                }
                if ($p['secrets']) {
                    $list = array();
                    foreach ((array) $p['secrets'] as $x) {
                        $list[] = $x['path'] . ' (' . $x['mode'] . ')';
                    }
                    $body .= '<p><b>' . self::t('Config files with passwords that other users can read') . '</b> ' . self::t('(the database password is in them).') . '</p><pre class="qf-log">' . self::h(implode("\n", $list)) . '</pre>';
                }
                $body .= '<form method="post" action="' . $base . '&amp;action=PermFix">' . runtime_csfr::Token()
                    . '<p class="qf-muted">' . self::t('Fix takes the "anyone" rights away from these (they keep working: the website, you and the panel have their own rights). It runs in the background as your own account.') . '</p>'
                    . '<button class="btn btn-primary" type="submit">' . self::t('Fix') . '</button></form>';
            }
        }
        return '<div class="qf-card"><h3>' . self::Icon('shield') . self::t('Permissions') . '</h3><p class="qf-muted">'
            . self::t('Looks for files and folders anyone on the server could change, and config files with passwords that other users could read.') . '</p>'
            . $body . ($p === null ? '<form method="post" action="' . $base . '&amp;action=PermScan">' . runtime_csfr::Token() . '<button class="btn btn-default" type="submit">' . self::t('Check') . '</button></form>' : '') . '</div>';
    }

    /**
     * The Hardening card: switches written in QuickFig's own part of .htaccess (and in the upload
     * folders' own .htaccess), each explained.
     *
     * @return string HTML.
     */
    static function Hardening()
    {
        $h = self::Ask('harden_read');
        if (isset($h['error'])) {
            return '';
        }
        $site = self::Site();
        $ssl = quickfig::HasSsl($site['id']);
        $on = array_flip((array) $h['options']);
        $box = function ($name, $title, $why, $checked, $disabled = false) {
            return '<label class="qf-bot' . ($disabled ? ' is-off' : '') . '"><input type="checkbox" name="in_' . $name . '" value="1"' . ($checked ? ' checked' : '') . ($disabled ? ' disabled' : '') . '> <span><b>' . self::t($title) . '</b> ' . $why . '</span></label>';
        };
        $url = './?module=quickfig&amp;site=' . (int) $site['id'] . (self::Sub() !== '' ? '&amp;sub=' . rawurlencode(self::Sub()) : '') . '&amp;action=Harden';
        $rows = '';
        $dirs = (array) $h['uploads'];
        $rows .= $box('nophp', 'No PHP in upload folders', $dirs
            ? str_replace('%s', '<code>' . self::h(implode('</code>, <code>', $dirs)) . '</code>', self::t('Uploaded files (pictures, attachments) never need to run as code, but a hacked site hides its back door exactly there. A small .htaccess in %s refuses every PHP file in them.'))
            : self::t('This website has no upload folder QuickFig knows.'), (bool) $h['nophp'], !$dirs);
        if ($h['kind'] === 'wordpress') {
            $rows .= $box('xmlrpc', 'Turn off xmlrpc.php', self::t('WordPress\'s old remote interface, a favourite of password-guessing robots (hundreds of passwords in one request). Leave it on only if you use the Jetpack plugin or an old app that posts to the site.'), isset($on['xmlrpc']));
        }
        $rows .= $box('headers', 'Security headers', self::t('Tell browsers not to guess file types (nosniff), not to show the site inside another site\'s frame (clickjacking), and to send other sites only the address of your site, not the page.'), isset($on['headers']));
        $rows .= $box('https', 'Always https', $ssl ? self::t('Send every http:// visit to https:// (a permanent redirect), so nobody uses the site unencrypted.') : self::t('Needs the site\'s certificate first (Sencrypt SSL).'), $ssl && isset($on['https']), !$ssl);
        $rows .= $box('hsts', 'Remember https (HSTS)', $ssl ? self::t('Browsers then use https for a year without asking. Turn it on only once https works for good: until the year is over, a visitor who came once can not open the site without a working certificate.') : self::t('Needs the site\'s certificate first (Sencrypt SSL).'), $ssl && isset($on['hsts']), !$ssl);
        $www = '';
        if ((int) $site['type'] === 1 && !in_array($h['kind'], array('wordpress', 'magento'), true) && self::Sub() === '') {
            $opt = function ($v, $label) use ($h) {
                return '<option value="' . $v . '"' . ($h['www'] === $v ? ' selected' : '') . '>' . self::h($label) . '</option>';
            };
            $name = self::h($site['name']);
            $www = '<label class="qf-field"><span>' . self::t('One address for the website') . '</span><select class="form-control" name="inWww">'
                . $opt('', ui_language::translate('Both work (as now)')) . $opt('bare', $site['name'] . ' (www. sends there)') . $opt('www', 'www.' . $site['name'] . ' (without www. sends there)') . '</select>'
                . '<small>' . self::t('Search engines count www. and the bare name as two websites; one address with the other sending to it keeps them together.') . '</small></label>';
        }
        return '<div class="qf-card"><h3>' . self::Icon('lock') . self::t('Hardening') . '</h3><p class="qf-muted">'
            . self::t('Switches the web server applies before the website runs. Each is safe to turn off again: untick it and save.') . '</p>'
            . '<form method="post" action="' . $url . '">' . runtime_csfr::Token() . '<div class="qf-hard">' . $rows . '</div>' . $www
            . '<div class="qf-foot"><small class="qf-muted">' . str_replace('%s', '<code>' . self::h($h['root']) . '</code>', self::t('Written in QuickFig\'s own marked part of .htaccess (in %s, after the crawlers\' part) and in the upload folders\' .htaccess; the rest stays as it is.')) . '</small>'
            . '<button class="btn btn-primary" type="submit">' . self::t('Save') . '</button></div></form></div>';
    }

    /**
     * Who visited in the last 24 hours (counted every hour from the site's access log), the
     * busiest first, with Block beside a crawler or script.
     *
     * @param string[] $on The names turned away now (lower case).
     * @return string HTML.
     */
    static function Visitors(array $on)
    {
        $site = self::Site();
        $v = self::Sub() === '' ? quickfig_watch::Visitors($site['id']) : null;
        $head = '<h4 class="qf-subhead">' . self::t('Who visited in the last 24 hours') . '</h4>';
        if (self::Sub() !== '') {
            return '';
        }
        if (!$v || !$v['agents']) {
            return $head . '<p class="qf-muted">' . self::t('Counted every hour from the website\'s access log: the first count appears within the hour.') . '</p>';
        }
        $total = array_sum(array_map(function ($a) { return $a[0]; }, $v['agents']));
        $kinds = array('engine' => 'Search engine', 'bot' => 'Crawler', 'tool' => 'Script', 'browser' => 'Browser', 'oldchrome' => 'Old Chrome', 'other' => 'Other');
        $url = './?module=quickfig&amp;site=' . (int) $site['id'] . '&amp;action=BlockAgent';
        $rows = '';
        foreach (array_slice($v['agents'], 0, 15, true) as $label => $a) {
            list($n, $kind, $token) = $a;
            $blocked = false;
            foreach ($on as $name) {
                if ($token !== '' && stripos($token, $name) !== false) {
                    $blocked = true;
                }
            }
            $pct = $total > 0 ? round(100 * $n / $total, 1) : 0;
            $act = $blocked ? '<span class="qf-pill is-off">' . self::t('Blocked') . '</span>'
                : ($token !== '' && in_array($kind, array('bot', 'tool', 'other'), true)
                    ? '<button class="btn btn-default btn-xs" type="submit" name="inBlock" value="' . self::h($token) . '" onclick="return confirm(this.dataset.ask)" data-ask="' . self::h(str_replace('%s', $token, self::t('Turn away %s from this website?'))) . '">' . self::t('Block') . '</button>' : '');
            $rows .= '<tr><td><b>' . self::h($label) . '</b></td><td><span class="qf-pill' . ($kind === 'engine' ? ' is-ok' : ($kind === 'bot' || $kind === 'tool' || $kind === 'oldchrome' ? ' is-mage' : '')) . '">' . self::t($kinds[$kind] ?? 'Other') . '</span></td>'
                . '<td class="qf-num">' . number_format($n) . '</td><td class="qf-share"><span class="qf-bar"><i style="width:' . min(100, $pct) . '%"></i></span> ' . $pct . '%</td><td>' . $act . '</td></tr>';
        }
        return $head . '<p class="qf-muted">' . str_replace(array('%s', '%t'), array(number_format($total), date('H:i', $v['time'])), self::t('%s requests, counted from the website\'s access log (last counted at %t). A search engine is never offered for blocking; a crawler or script you do not know is usually safe to block.')) . '</p>'
            . '<form method="post" action="' . $url . '">' . runtime_csfr::Token() . '<div class="qf-scroll"><table class="qf-table qf-visitors"><thead><tr><th>' . self::t('Browser name') . '</th><th>' . self::t('What it is') . '</th><th class="qf-num">' . self::t('Requests') . '</th><th>' . self::t('Share') . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></form>'
            . '<h4 class="qf-subhead">' . self::t('Turn away') . '</h4>';
    }

    /**
     * The site's background tasks (the daemon's), newest first.
     *
     * @return string HTML ('' when there are none).
     */
    static function Jobs()
    {
        $site = self::Site();
        $rows = '';
        $states = array('queued' => array('Queued', ''), 'running' => array('Running', 'is-wp'), 'done' => array('Done', 'is-ok'), 'failed' => array('Failed', 'is-bad'));
        foreach (quickfig_jobs::Recent(self::Uid(), $site['id']) as $j) {
            if ((string) $j['qj_sub_vc'] !== self::Sub()) {
                continue;
            }
            $st = isset($states[$j['qj_state_vc']]) ? $states[$j['qj_state_vc']] : array($j['qj_state_vc'], '');
            $op = isset(quickfig_jobs::OPS[$j['qj_op_vc']]) ? quickfig_jobs::OPS[$j['qj_op_vc']][0] : $j['qj_op_vc'];
            $when = $j['qj_finished_in'] ? (int) $j['qj_finished_in'] : (int) $j['qj_created_in'];
            $out = trim((string) $j['qj_out_tx']);
            $rows .= '<li><div class="qf-job"><span>' . self::t($op) . ($j['qj_arg_vc'] !== '' && $j['qj_op_vc'] === 'mage_mode' ? ': ' . self::h($j['qj_arg_vc']) : '') . '</span>'
                . '<span class="qf-pill ' . $st[1] . '">' . self::t($st[0]) . '</span><span class="qf-muted">' . self::h(ui_language::Date('j M, H:i', $when)) . '</span></div>'
                . ($out !== '' ? '<details><summary>' . self::t('Output') . '</summary><pre class="qf-log">' . self::h($out) . '</pre></details>' : '') . '</li>';
        }
        if ($rows === '') {
            return '';
        }
        return '<div class="qf-card"><h3>' . self::Icon('clock') . self::t('Background tasks') . '</h3><p class="qf-muted">'
            . self::t('Run by the server as your own account, within 5 minutes of being queued. Reload the page to see them finish.') . '</p><ul class="qf-jobs">' . $rows . '</ul></div>';
    }

    /**
     * The https card of a WordPress site.
     *
     * @param array $st The site's state.
     * @return string HTML.
     */
    static function HttpsCard(array $st)
    {
        $site = self::Site();
        $address = $site['name'] . (self::Sub() !== '' ? '/' . self::Sub() : '');
        $home = isset($st['defines']['WP_HOME']) && $st['defines']['WP_HOME']['kind'] === 'string' ? (string) $st['defines']['WP_HOME']['value'] : '';
        $ssl = quickfig::HasSsl($site['id']);
        $out = '<div class="qf-card"><h3>' . self::Icon('lock') . self::t('HTTPS') . '</h3>';
        if (!$ssl) {
            return $out . '<p>' . self::t('This site has no certificate in use yet. Get one first (free, from Let\'s Encrypt), then come back here to switch WordPress to https.') . '</p>'
                . '<a class="btn btn-default btn-sm" href="./?module=sencrypt">' . self::t('Open Sencrypt SSL') . '</a></div>';
        }
        $on = stripos($home, 'https://') === 0;
        $out .= '<p>' . ($on ? self::t('WordPress uses https: its addresses start with https://.') : self::t('The site has a certificate and the server sends http visitors to https; WordPress itself still uses http addresses (or its own setting).')) . '</p>'
            . '<p class="qf-muted">' . str_replace('%s', '<code>https://' . self::h($address) . '</code>', self::t('Switching sets both addresses to %s, then changes the http:// links in posts, pages and settings in the database to https:// (in the background, with WP-CLI). The file is kept first; the database is not: make a backup in Backups if you are unsure.')) . '</p>'
            . self::Button('Https', $on ? 'Fix the links in the database again' : 'Switch this site to https', $on ? 'btn-default' : 'btn-primary', array(),
                'Switch WordPress to https and change the http:// links in its database? Make a backup first if you are unsure.');
        return $out . '</div>';
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
