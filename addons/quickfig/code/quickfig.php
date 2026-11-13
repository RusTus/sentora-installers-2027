<?php

/**
 * QuickFig, the panel's side: the account's sites, the settings QuickFig offers for WordPress
 * (each with its type and the values allowed), and the worker that touches the sites' files
 * (helper.php, see there: confined to the account's folder).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class quickfig
{
    /** Seconds the worker may take. */
    const TIMEOUT = 30;

    /**
     * WordPress settings: name => group, label, help, kind (bool, url, choice) and for a choice its
     * values (value as sent => array(label, the PHP value: bool, int or string)). '' is always
     * "WordPress's own default" (the setting is taken out of wp-config.php).
     *
     * @return array
     */
    static function Settings()
    {
        return array(
            'WP_HOME' => array('address', 'Site address', 'The address visitors use, such as https://www.example.com. Set it when the site sends visitors to a wrong or old address.', 'url'),
            'WP_SITEURL' => array('address', 'WordPress address', 'Where WordPress\'s own files are; nearly always the same as the site address.', 'url'),
            'DISALLOW_FILE_EDIT' => array('security', 'Turn off the theme and plugin editor', 'WordPress\'s built-in code editor is what an attacker who gets an admin login uses first. Changes are then made by FTP or the File Manager.', 'bool'),
            'FORCE_SSL_ADMIN' => array('security', 'Log in and administer over https only', 'The admin pages and the login always use https (the site needs a certificate: Sencrypt SSL).', 'bool'),
            'WP_MEMORY_LIMIT' => array('performance', 'Memory for WordPress', 'More for large sites, shops and page builders; the server\'s PHP limit still applies.', 'choice',
                array('64M' => array('64 MB', '64M'), '128M' => array('128 MB', '128M'), '256M' => array('256 MB', '256M'), '512M' => array('512 MB', '512M'))),
            'WP_POST_REVISIONS' => array('performance', 'Old versions of posts kept', 'Each edit keeps a copy; many copies make the database large and slow.', 'choice',
                array('off' => array('None', false), '3' => array('3', 3), '5' => array('5', 5), '10' => array('10', 10), '25' => array('25', 25))),
            'EMPTY_TRASH_DAYS' => array('performance', 'Days before the trash is emptied', 'Deleted posts and comments stay in the trash this long.', 'choice',
                array('0' => array('Delete at once (no trash)', 0), '7' => array('7 days', 7), '30' => array('30 days', 30), '90' => array('90 days', 90))),
            'WP_AUTO_UPDATE_CORE' => array('updates', 'WordPress updates', 'Which updates of WordPress itself install on their own (plugins and themes are set in WordPress).', 'choice',
                array('minor' => array('Security and small updates only', 'minor'), 'all' => array('All updates', true), 'none' => array('None (I update myself)', false))),
            'DISABLE_WP_CRON' => array('cron', 'Run WordPress\'s scheduled tasks from the server', 'WordPress normally runs its tasks (scheduled posts, backups, emails) when someone visits, so a quiet site misses them and a busy one runs them too often. Turned on, the panel runs them every 15 minutes instead (a task of its own in Cron Manager).', 'bool'),
        );
    }

    /**
     * Crawlers QuickFig can turn away: name (as it says it is) => group, what it is.
     * Groups: rules (ignores robots.txt or crawls very hard), seo (an SEO tool's index: useful only
     * to those who use that tool), ai (collects pages for AI). Search engines are not here.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    static function Bots()
    {
        return array(
            'TLM-Audit-Scanner' => array('rules', 'A scanner of unknown use that ignores robots.txt and asks for page after page.'),
            'PetalBot' => array('rules', 'Huawei\'s search engine (Petal Search); crawls hard, little traffic outside China.'),
            'MJ12bot' => array('rules', 'Majestic\'s link index; crawls hard and often.'),
            'DataForSeoBot' => array('rules', 'Collects pages to sell SEO data; crawls hard.'),
            'BLEXBot' => array('rules', 'An SEO link index (WebMeUp); crawls hard.'),
            'Bytespider' => array('rules', 'ByteDance (TikTok) collecting pages for AI; known to ignore robots.txt.'),
            'SemrushBot' => array('seo', 'Semrush\'s SEO tool. Keep it if you use Semrush.'),
            'AhrefsBot' => array('seo', 'Ahrefs\' SEO tool. Keep it if you use Ahrefs.'),
            'DotBot' => array('seo', 'Moz\'s SEO tool. Keep it if you use Moz.'),
            'serpstatbot' => array('seo', 'Serpstat\'s SEO tool.'),
            'MegaIndex' => array('seo', 'MegaIndex\'s SEO tool.'),
            'GPTBot' => array('ai', 'OpenAI collecting pages to train its AI (it follows robots.txt).'),
            'CCBot' => array('ai', 'Common Crawl\'s open web archive, much used to train AI.'),
            'ClaudeBot' => array('ai', 'Anthropic collecting pages for its AI (it follows robots.txt).'),
            'ElevenlabsCrawler' => array('ai', 'ElevenLabs collecting pages for its AI.'),
            'Amazonbot' => array('ai', 'Amazon\'s crawler (Alexa and AI answers); polite but busy.'),
        );
    }

    /**
     * The Chrome version about 18 months old today (Chrome gets a new main version about every 4
     * weeks: 120 in December 2023). Older ones are rare among real visitors: their Chrome updates
     * itself.
     *
     * @return int
     */
    /** Search engines (and link previews) a site wants: never offered for blocking. */
    const GOOD_BOTS = '/googlebot|google-inspectiontool|storebot-google|googleother|adsbot-google|mediapartners-google|bingbot|adidxbot|bingpreview|duckduckbot|applebot|yandex|baiduspider|slurp|facebookexternalhit|linkedinbot|twitterbot|chrome-lighthouse|pinterestbot|whatsapp|telegrambot|slackbot|discordbot|uptimerobot|sentora-sitehealth/i';

    /**
     * What a browser name (user agent) is, for "Who visited": array(label, kind, name to block or '').
     * Kinds: engine (a search engine: kept), bot (a crawler: can be blocked), tool (a script or
     * library: can be blocked), browser, other.
     *
     * @param string $ua
     * @return array{0:string, 1:string, 2:string}
     */
    static function Agent($ua)
    {
        $ua = trim((string) $ua);
        if ($ua === '' || $ua === '-') {
            return array('(no browser name)', 'tool', '');
        }
        if (preg_match(self::GOOD_BOTS, $ua, $m)) {
            return array(self::AgentName($ua, $m[0]), 'engine', '');
        }
        foreach (array_keys(self::Bots()) as $name) {
            if (stripos($ua, $name) !== false) {
                return array($name, 'bot', $name);
            }
        }
        if (preg_match('/([A-Za-z][A-Za-z0-9._-]*(?:bot|crawler|spider|scanner|crawl|scraper|fetcher|archiver|checker|monitor)[A-Za-z0-9._-]*)/i', $ua, $m)
            && strlen($m[1]) >= 3 && strlen($m[1]) <= 60 && !preg_match('/^(mozilla|applewebkit|chrome|safari)$/i', $m[1])) {
            return array($m[1], 'bot', $m[1]);
        }
        // "Mozilla/5.0 (compatible; Name/1.0; +https://...)": a crawler giving its own name (not old Internet Explorer)
        if (preg_match('/\(compatible;\s*([A-Za-z][A-Za-z0-9._-]{2,40})/', $ua, $m) && !preg_match('/^(MSIE|Konqueror)/i', $m[1])) {
            return array($m[1], 'bot', $m[1]);
        }
        if (preg_match('/^(python-requests|python-urllib|python-httpx|aiohttp|curl|wget|go-http-client|libwww-perl|scrapy|okhttp|axios|node-fetch|java|apache-httpclient|guzzlehttp|php|ruby|http_request2|httpclient|masscan|zgrab|nmap)\b/i', $ua, $m)
            || preg_match('/\b(HeadlessChrome|PhantomJS|Puppeteer|Playwright|Selenium)\b/i', $ua, $m)) {
            return array($m[1], 'tool', $m[1]);
        }
        if (preg_match('#Edg/(\d+)#', $ua, $m)) {
            return array('Edge ' . $m[1], 'browser', '');
        }
        if (preg_match('#(?:OPR|Opera)/(\d+)#', $ua, $m)) {
            return array('Opera ' . $m[1], 'browser', '');
        }
        if (preg_match('#Firefox/(\d+)#', $ua, $m)) {
            return array('Firefox ' . $m[1], 'browser', '');
        }
        if (preg_match('#(?:Chrome|CriOS)/(\d+)#', $ua, $m)) {
            return array('Chrome ' . $m[1], (int) $m[1] < self::OldChrome() ? 'oldchrome' : 'browser', '');
        }
        if (preg_match('#(?:MSIE |Trident/.*rv:)(\d+)#', $ua, $m)) {
            return array('Internet Explorer ' . $m[1], 'browser', '');
        }
        if (preg_match('#Version/(\d+)[0-9.]* .*Safari/#', $ua, $m)) {
            return array('Safari ' . $m[1], 'browser', '');
        }
        $first = preg_match('#^([A-Za-z][A-Za-z0-9._-]{2,40})#', $ua, $m) ? $m[1] : substr($ua, 0, 40);
        return array($first, 'other', strlen($first) >= 3 && preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{2,59}$#', $first) && strcasecmp($first, 'Mozilla') !== 0 ? $first : '');
    }

    /**
     * A search engine's name as it gives it.
     *
     * @param string $ua
     * @param string $hit The part that matched.
     * @return string
     */
    static function AgentName($ua, $hit)
    {
        return preg_match('/(' . preg_quote($hit, '/') . '[A-Za-z0-9._-]*)/i', $ua, $m) ? $m[1] : $hit;
    }

    /**
     * What wordpress.org says is newer than a site's WordPress, plugins and themes (the same
     * questions WordPress itself asks), kept for 6 hours in the panel session.
     *
     * @param array $v The worker's wp_versions answer.
     * @return array|null array('core' => latest or '', 'plugins' => file => array(name, have, latest), 'themes' => ...);
     *     null when wordpress.org could not be asked.
     */
    static function WpUpdates(array $v)
    {
        $key = 'quickfig_wpup_' . sha1(json_encode($v));
        if (isset($_SESSION[$key]) && $_SESSION[$key]['t'] > time() - 6 * 3600) {
            return $_SESSION[$key]['r'];
        }
        $wpCore = preg_replace('/[^0-9.]/', '', (string) $v['core']);
        $ask = function ($url, $post = null) use ($wpCore) {
            $c = curl_init($url);
            curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'WordPress/' . $wpCore . '; Sentora QuickFig'));
            if ($post !== null) {
                curl_setopt($c, CURLOPT_POST, true);
                curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
            }
            $body = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            curl_close($c);
            $j = $code === 200 && is_string($body) ? json_decode($body, true) : null;
            return is_array($j) ? $j : null;
        };
        $core = $ask('https://api.wordpress.org/core/version-check/1.7/?version=' . rawurlencode($wpCore));
        if ($core === null) {
            return null;
        }
        $latest = isset($core['offers'][0]['current']) ? (string) $core['offers'][0]['current'] : '';
        $out = array('core' => $latest !== '' && version_compare($latest, (string) $v['core'], '>') ? $latest : '', 'plugins' => array(), 'themes' => array());
        if (!empty($v['plugins'])) {
            $p = $ask('https://api.wordpress.org/plugins/update-check/1.1/', array('plugins' => json_encode(array('plugins' => $v['plugins'], 'active' => array())), 'translations' => '[]', 'locale' => '[]', 'all' => 'true'));
            foreach (isset($p['plugins']) && is_array($p['plugins']) ? $p['plugins'] : array() as $file => $u) {
                if (isset($v['plugins'][$file], $u['new_version']) && version_compare((string) $u['new_version'], (string) $v['plugins'][$file]['Version'], '>')) {
                    $out['plugins'][$file] = array((string) $v['plugins'][$file]['Name'], (string) $v['plugins'][$file]['Version'], (string) $u['new_version']);
                }
            }
        }
        if (!empty($v['themes'])) {
            $t = $ask('https://api.wordpress.org/themes/update-check/1.1/', array('themes' => json_encode(array('active' => '', 'themes' => $v['themes'])), 'translations' => '[]', 'locale' => '[]'));
            foreach (isset($t['themes']) && is_array($t['themes']) ? $t['themes'] : array() as $slug => $u) {
                if (isset($v['themes'][$slug], $u['new_version']) && version_compare((string) $u['new_version'], (string) $v['themes'][$slug]['Version'], '>')) {
                    $out['themes'][$slug] = array((string) $v['themes'][$slug]['Name'], (string) $v['themes'][$slug]['Version'], (string) $u['new_version']);
                }
            }
        }
        $_SESSION[$key] = array('t' => time(), 'r' => $out);
        return $out;
    }

    static function OldChrome()
    {
        $now = 120 + intdiv(time() - mktime(0, 0, 0, 12, 5, 2023), 28 * 86400);
        return max(100, min(199, $now - 19));
    }

    /**
     * The groups, in order.
     *
     * @return array<string, string>
     */
    static function Groups()
    {
        return array('address' => 'Site address', 'security' => 'Security', 'performance' => 'Performance', 'updates' => 'Updates', 'cron' => 'Scheduled tasks');
    }

    /**
     * The account's sites: its domains and sub-domains with their folders.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid The account.
     * @return array<int, array{id:int, name:string, type:int, dir:string}> By vhost ID.
     */
    static function Sites($uid)
    {
        global $zdbh;
        $u = ctrl_users::GetUserDetail((int) $uid);
        $base = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $u['username'] . '/public_html';
        $q = $zdbh->prepare("SELECT vh_id_pk, vh_name_vc, vh_type_in, vh_directory_vc FROM x_vhosts WHERE vh_acc_fk = :u AND vh_type_in IN (1, 2) AND vh_deleted_ts IS NULL ORDER BY vh_name_vc");
        $q->execute(array(':u' => (int) $uid));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['vh_id_pk']] = array('id' => (int) $r['vh_id_pk'], 'name' => (string) $r['vh_name_vc'], 'type' => (int) $r['vh_type_in'],
                'dir' => $base . '/' . trim((string) $r['vh_directory_vc'], '/'));
        }
        return $out;
    }

    /**
     * The account's websites that share their files with another one (several domains or
     * sub-domains pointing at the same folder): a change on one is a change on all of them.
     * The folder is followed through links where the panel can (realpath), else compared by name.
     *
     * A site whose WordPress or Magento was found (detect's root, which may be a folder inside
     * another site's, or reached through a link) is compared by that instead.
     *
     * @param array $sites quickfig::Sites()
     * @param array<int, string> $roots Per site id: where detect found its WordPress or Magento.
     * @return array<int, string[]> Per site id: the names of the other sites with the same files.
     */
    static function SameFiles(array $sites, array $roots = array())
    {
        $by = array();
        foreach ($sites as $s) {
            if (!empty($roots[$s['id']])) {
                $by['found:' . $roots[$s['id']]][] = $s;
                continue;
            }
            $real = @realpath($s['dir']);
            $by['dir:' . ($real !== false ? $real : rtrim($s['dir'], '/'))][] = $s;
        }
        $out = array();
        foreach ($by as $group) {
            if (count($group) < 2) {
                continue;
            }
            foreach ($group as $s) {
                $out[$s['id']] = array();
                foreach ($group as $o) {
                    if ($o['id'] !== $s['id']) {
                        $out[$s['id']][] = $o['name'];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Does the site have its certificate in use (Sencrypt SSL: its https settings)?
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $vh The site.
     * @return bool
     */
    static function HasSsl($vh)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT vh_ssl_tx FROM x_vhosts WHERE vh_id_pk = :v AND vh_deleted_ts IS NULL");
        $q->execute(array(':v' => (int) $vh));
        return trim((string) $q->fetchColumn()) !== '';
    }

    /**
     * The account's folder.
     *
     * @param int $uid
     * @return string
     */
    static function Home($uid)
    {
        $u = ctrl_users::GetUserDetail((int) $uid);
        return rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $u['username'];
    }

    /**
     * Ask the worker (helper.php), confined to the account's folder.
     *
     * @param int $uid The account (the logged-in one).
     * @param array $site From Sites().
     * @param string $op The request.
     * @param array $extra More for it (sub: the WordPress folder inside the site, set, unset...).
     * @param bool $asUser Run it as the account's own Linux user (the daemon, which is root: a root
     *                     process never touches an account's files itself).
     * @return array Its answer ('error' => text on failure).
     */
    static function Run($uid, array $site, $op, array $extra = array(), $asUser = false)
    {
        $home = realpath(self::Home($uid));
        if ($home === false || !preg_match('#^/[A-Za-z0-9_./-]+$#', $home)) {
            return array('error' => 'Your hosting folder does not exist yet.');
        }
        $helper = dirname(__FILE__) . '/helper.php';
        $php = PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (!is_executable($php)) {
            $php = PHP_BINDIR . '/php';
        }
        // confined to the account's folder (and its own code); no commands, no URLs, no panel settings
        $cmd = array($php, '-d', 'open_basedir=' . $home . '/:' . dirname($helper) . '/',
            '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,putenv,mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,dl,ini_set,set_include_path',
            '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'memory_limit=128M', '-d', 'max_execution_time=' . self::TIMEOUT,
            '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'auto_prepend_file=', $helper);
        if ($asUser) {
            if (posix_getpwnam(sys_accountphp::UserName($uid)) === false) {
                return array('error' => 'The account\'s Linux user is not made yet.');
            }
            $user = sys_accountphp::UserName($uid);
            $cmd = array_merge(array('setpriv', '--reuid=' . $user, '--regid=' . $user, '--clear-groups', '--reset-env', 'env', '-i', 'PATH=/usr/bin:/bin', 'LANG=C.UTF-8'), $cmd);
        }
        $p = @proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $home, array('PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8'));
        if (!is_resource($p)) {
            return array('error' => 'QuickFig\'s worker could not be started.');
        }
        fwrite($pipes[0], json_encode(array('op' => $op, 'home' => $home, 'dir' => $site['dir']) + $extra));
        fclose($pipes[0]);
        $out = '';
        $until = time() + self::TIMEOUT + 5;
        stream_set_blocking($pipes[1], false);
        while (!feof($pipes[1]) && time() < $until) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === '' || $chunk === false) {
                usleep(20000);
                continue;
            }
            $out .= $chunk;
            if (strlen($out) > 4194304) {
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($p);
        proc_close($p);
        $a = json_decode($out, true);
        return is_array($a) ? $a : array('error' => 'QuickFig\'s worker gave no answer.');
    }

    /**
     * The panel task that runs WordPress's scheduled tasks (Cron Manager), when there is one.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @param string $full wp-cron.php's full path.
     * @return int Its ID, or 0.
     */
    static function CronJob($uid, $full)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ct_id_pk FROM x_cronjobs WHERE ct_acc_fk = :a AND ct_fullpath_vc = :f AND ct_deleted_ts IS NULL");
        $q->execute(array(':a' => (int) $uid, ':f' => (string) $full));
        return (int) $q->fetchColumn();
    }

    /**
     * Add or remove the panel task for WordPress's wp-cron.php (every 15 minutes), the way Cron
     * Manager adds one (cron_safe::ResolveFile: a real .php file in public_html).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @param string $username
     * @param string $rel wp-cron.php inside public_html.
     * @param bool $on
     * @return string '' when done, else what is wrong.
     */
    static function SetCron($uid, $username, $rel, $on)
    {
        global $zdbh;
        require_once dirname(__FILE__) . '/../../cron/code/cronsafe.php';
        $full = cron_safe::ResolveFile($username, $rel);
        if ($full === false) {
            return 'wp-cron.php can not be scheduled (Cron Manager takes .php files in public_html, with letters, digits, dots, dashes and underscores in their path).';
        }
        $id = self::CronJob($uid, $full);
        if (!$on) {
            if ($id > 0) {
                $zdbh->prepare("UPDATE x_cronjobs SET ct_deleted_ts = :t WHERE ct_id_pk = :id AND ct_acc_fk = :a")->execute(array(':t' => time(), ':id' => $id, ':a' => (int) $uid));
            }
            return '';
        }
        if ($id > 0) {
            return '';
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk = :a AND ct_deleted_ts IS NULL");
        $q->execute(array(':a' => (int) $uid));
        if ((int) $q->fetchColumn() >= 50) {
            return 'You have the most scheduled tasks an account can have: delete one in Cron Manager first.';
        }
        $zdbh->prepare("INSERT INTO x_cronjobs (ct_acc_fk, ct_script_vc, ct_description_tx, ct_timing_vc, ct_fullpath_vc, ct_created_ts) VALUES (:a, :s, :d, :t, :f, :c)")
            ->execute(array(':a' => (int) $uid, ':s' => $rel, ':d' => 'WordPress scheduled tasks (QuickFig)', ':t' => '*/15 * * * *', ':f' => $full, ':c' => time()));
        return '';
    }
}
