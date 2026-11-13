<?php

/**
 * Visitor Stats: the Visitors report of each of the account's own domains, in the panel's style.
 *
 * A picker for the domain (it opens its report at once), when the report was made and will be next,
 * Reload and New window, then the report itself (code/report.php, styled to match the panel's
 * Light or Dark). Reports are made every hour by hooks/OnDaemonHour.hook.php.
 *
 * Updated by RusTus, 2026-11-08
 */

require_once dirname(__FILE__) . '/visitorstats.php';

class module_controller extends ctrl_module
{

    /**
     * Escape text for HTML.
     *
     * @param mixed $s The text.
     * @return string
     */
    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Translate and escape.
     *
     * @param string $s The English text.
     * @return string
     */
    static function t($s)
    {
        return self::h(ui_language::translate($s));
    }

    /**
     * The logged-in account's domains (template <% if Domains %>).
     *
     * @return array|false
     */
    static function getDomains()
    {
        static $list = null;
        if ($list === null) {
            $list = visitor_stats_helper::Domains(ctrl_users::GetUserDetail()['userid']);
        }
        return count($list) ? $list : false;
    }

    /**
     * No domains yet (template <% if NoDomains %>).
     *
     * @return bool
     */
    static function getNoDomains()
    {
        return !self::getDomains();
    }

    /**
     * When a domain's report was last made.
     *
     * @param string $domain The domain name.
     * @return int|false Unix time, or false when there is no report yet.
     */
    static function Made($domain)
    {
        $file = visitor_stats_helper::ReportFile(ctrl_users::GetUserDetail()['username'], $domain);
        return ($file !== false && is_file($file)) ? filemtime($file) : false;
    }

    /**
     * The domain shown: the one asked for (when it is the account's own), else the first with a report, else the first.
     *
     * @return string The domain name, or '' when the account has none.
     */
    static function Current()
    {
        global $controller;
        $domains = (array) self::getDomains();
        if (!$domains) {
            return '';
        }
        $asked = (string) $controller->GetControllerRequest('URL', 'domain');
        foreach ($domains as $d) {
            if ($asked !== '' && strcasecmp((string) $d['vh_name_vc'], $asked) === 0) {
                return (string) $d['vh_name_vc'];
            }
        }
        foreach ($domains as $d) {
            if (self::Made((string) $d['vh_name_vc'])) {
                return (string) $d['vh_name_vc'];
            }
        }
        return (string) $domains[0]['vh_name_vc'];
    }

    /**
     * How far a time is from now, in words ("23 min ago"), worded as GoAccess Stats does.
     *
     * @param int $time Unix time.
     * @return string
     */
    static function Ago($time)
    {
        $d = time() - (int) $time;
        if ($d < 60) {
            return ui_language::translate('just now');
        }
        if ($d < 3600) {
            return floor($d / 60) . ' min ' . ui_language::translate('ago');
        }
        if ($d < 172800) {
            return floor($d / 3600) . ' h ' . ui_language::translate('ago');
        }
        return floor($d / 86400) . ' ' . ui_language::translate('days ago');
    }

    /**
     * The domain picker (<@ DomainPicker @>): opens the chosen domain's report at once.
     *
     * @return string The HTML.
     */
    static function getDomainPicker()
    {
        $current = self::Current();
        $options = '';
        foreach ((array) self::getDomains() as $d) {
            $name = (string) $d['vh_name_vc'];
            $options .= '<option value="' . self::h($name) . '"' . ($name === $current ? ' selected' : '') . '>'
                . self::h($name) . (self::Made($name) ? '' : ' (' . self::t('not made yet') . ')') . '</option>';
        }
        return '<form method="get" action="./" class="vz-pick">'
            . '<input type="hidden" name="module" value="visitor_stats">'
            . '<label for="vz-domain"><svg class="vz-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/></svg>'
            . self::t('Domain') . '</label>'
            . '<select id="vz-domain" name="domain" class="form-control" onchange="this.form.submit()">' . $options . '</select>'
            . '<noscript><button type="submit" class="btn btn-primary btn-sm">' . self::t('Show') . '</button></noscript>'
            . '</form>';
    }

    /**
     * The report's address (the page's script adds Light or Dark).
     *
     * @param string $domain The domain name.
     * @return string
     */
    static function ReportUrl($domain)
    {
        return 'modules/visitor_stats/code/report.php?domain=' . rawurlencode($domain);
    }

    /**
     * When the report was made and will be next, with Reload and New window (<@ ReportInfo @>).
     *
     * @return string The HTML ('' when there is no report).
     */
    static function getReportInfo()
    {
        $domain = self::Current();
        $made = $domain !== '' ? self::Made($domain) : false;
        if ($made === false) {
            return '';
        }
        $next = (int) (floor(time() / 3600) + 1) * 3600;
        $url = self::ReportUrl($domain);
        return '<div class="vz-facts">'
            . '<span title="' . self::h(date('Y-m-d H:i', $made)) . '"><b>' . self::t('Updated') . '</b> ' . self::h(self::Ago($made)) . '</span>'
            . '<span><b>' . self::t('Next update') . '</b> ' . self::h(date('H:i', $next)) . '</span>'
            . '</div><div class="vz-btns">'
            . '<button type="button" class="btn btn-default btn-sm vz-reload"><svg class="vz-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>' . self::t('Reload') . '</button>'
            . '<a class="btn btn-default btn-sm vz-newwin" href="' . self::h($url) . '" target="_blank" rel="noopener"><svg class="vz-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>' . self::t('New window') . '</a>'
            . '</div>';
    }

    /**
     * The chosen domain has no report yet (template <% if NotMadeYet %>).
     *
     * @return bool
     */
    static function getNotMadeYet()
    {
        $domain = self::Current();
        return $domain !== '' && self::Made($domain) === false;
    }

    /**
     * The report frame (<@ Frame @>), sized to its content by the panel's frame-height script.
     *
     * @return string The HTML ('' when there is no report).
     */
    static function getFrame()
    {
        $domain = self::Current();
        if ($domain === '' || self::Made($domain) === false) {
            return '';
        }
        return '<iframe class="vz-frame sn-autoheight" src="' . self::h(self::ReportUrl($domain)) . '" title="' . self::h('Visitors: ' . $domain) . '"></iframe>'
            . '<script src="etc/lib/sentora/frame-height.js"></script>';
    }

}

?>
