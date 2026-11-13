<?php

/**
 * Apps: install a web app (WordPress, OpenCart, Snipe-IT) into one of the account's websites in a few clicks,
 * or deploy the account's own app from Git (deploy again, roll back, webhook),
 * and the account's apps with their addresses, versions and removal. The page checks the request,
 * makes the app's database and MySQL user and records it; the daemon installs it as the account's
 * own Linux user (apps_jobs). The admin password the owner chooses is kept only as the app's own
 * hash, until the install has set it.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
require_once dirname(__FILE__) . '/apps.php';
require_once dirname(__FILE__) . '/jobs.php';

class module_controller extends ctrl_module
{
    /** @var array<int, array{0:string, 1:string}> Messages: [type ('ok'|'error'), text]. */
    static $msg = array();

    /** @var bool The install form is to be shown again (a mistake in it). */
    static $again = false;

    /** Names an admin should not have (the first ones attackers try). */
    const WEAK_ADMINS = array('admin', 'administrator', 'root', 'wordpress', 'wpadmin', 'test', 'user', 'webmaster', 'demo');

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
     * Translate, put words in for %s, escape.
     *
     * @param string $s
     * @param array $args
     * @return string
     */
    static function tf($s, array $args)
    {
        $text = ui_language::translate($s);
        foreach ($args as $a) {
            $text = preg_replace('/%s/', str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $a), $text, 1);
        }
        return self::h($text);
    }

    /**
     * The logged-in account.
     *
     * @return array
     */
    static function Me()
    {
        return ctrl_users::GetUserDetail();
    }

    /**
     * A message for the page.
     *
     * @param string $type ok or error
     * @param string $text English, translated here.
     * @param array $args Words for %s.
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
     * A form field posted.
     *
     * @param string $n
     * @return string
     */
    static function Post($n)
    {
        return isset($_POST[$n]) && is_string($_POST[$n]) ? $_POST[$n] : '';
    }

    /**
     * Install an app (the form).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doInstall()
    {
        global $zdbh;
        runtime_csfr::Protect();
        self::$again = true;
        $me = self::Me();
        $uid = (int) $me['userid'];
        $app = self::Post('inApp');
        $recipes = apps::Recipes();
        if (!isset($recipes[$app])) {
            self::Say('error', 'No such app.');
            return;
        }
        if ($app === 'git') {
            self::GitInstall();
            return;
        }
        $r = $recipes[$app];
        $sites = apps::Sites($uid);
        $vh = ctype_digit(self::Post('inSite')) ? (int) self::Post('inSite') : 0;
        if (!isset($sites[$vh])) {
            self::Say('error', 'Choose one of your websites.');
            return;
        }
        $site = $sites[$vh];
        $sub = trim(self::Post('inFolder'), " /");
        if ($sub !== '' && (!preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $sub) || preg_match('#(^|/)\.\.?(/|$)#', $sub) || preg_match('#(^|/)(_errorpages|cgi-bin|\.well-known)(/|$)#i', $sub))) {
            self::Say('error', 'A folder name has letters, digits, dots, dashes and underscores (and / between folders).');
            return;
        }
        $title = trim(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', self::Post('inTitle')));
        if ($title === '' || mb_strlen($title) > 100 || !mb_check_encoding($title, 'UTF-8')) {
            self::Say('error', 'Give the site a title (up to 100 characters).');
            return;
        }
        $admin = trim(self::Post('inAdmin'));
        if (!preg_match('/^[A-Za-z0-9._-]{3,' . (int) $r['user'] . '}$/', $admin)) {
            self::Say('error', 'The admin\'s user name has 3 to %s letters, digits, dots, dashes or underscores.', array((int) $r['user']));
            return;
        }
        if (in_array(strtolower($admin), self::WEAK_ADMINS, true)) {
            self::Say('error', 'Choose another admin user name: %s is the first one attackers try.', array($admin));
            return;
        }
        $email = trim(self::Post('inEmail'));
        if (strlen($email) > 96 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::Say('error', 'The admin\'s email address is not valid (%s sends password resets there).', array($r['name']));
            return;
        }
        $pass = self::Post('inPass');
        $min = max(10, (int) ctrl_options::GetSystemOption('password_minlength'));
        if (strlen($pass) < $min || strlen($pass) > (int) $r['pass']) {
            self::Say('error', 'The admin\'s password needs %s to %s characters.', array($min, (int) $r['pass']));
            return;
        }
        if ($pass !== self::Post('inPass2')) {
            self::Say('error', 'The two passwords are not the same.');
            return;
        }
        if (strcasecmp($pass, $admin) === 0 || stripos($pass, $admin) !== false) {
            self::Say('error', 'The password must not contain the user name.');
            return;
        }
        if (version_compare(apps::SitePhp($site), $r['php'], '<')) {
            self::Say('error', '%s needs PHP %s or newer, and %s runs PHP %s: choose a newer PHP for it first (Domains, or PHP Versions).', array($r['name'], $r['php'], $site['name'], apps::SitePhp($site)));
            return;
        }
        if ($app === 'wordpress' && !is_file(apps_jobs::WPCLI)) {
            self::Say('error', 'Apps is not ready on this server yet (it downloads its tools within the hour). Try again later.');
            return;
        }
        // an app already there (or on its way)
        apps::Ensure();
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_apps WHERE ap_acc_fk = :u AND ap_vh_fk = :v AND ap_sub_vc = :s AND ap_deleted_in IS NULL");
        $q->execute(array(':u' => $uid, ':v' => $vh, ':s' => $sub));
        if ((int) $q->fetchColumn() > 0) {
            self::Say('error', 'There is an app in that folder already (Your apps, below).');
            return;
        }
        // the folder: missing, empty, or only the panel's welcome page
        $c = apps::Run($uid, $site, 'check', array('sub' => $sub));
        if (isset($c['error'])) {
            self::Say('error', $c['error']);
            return;
        }
        if (empty($c['free'])) {
            self::Say('error', 'That folder is not empty: %s. Choose another folder (such as blog), or empty it first in the File Manager.',
                array(implode(', ', (array) $c['found']) . (!empty($c['more']) ? ' (+' . (int) $c['more'] . ')' : '')));
            return;
        }
        $disk = apps::DiskRoom($uid, (int) $r['disk']);
        if (!$disk[0]) {
            self::Say('error', '%s needs about %s MB of disk space and your package has %s MB free (of %s MB). Make room (files, mail, databases) or ask for a larger package: over its disk space, all your websites show "disk space exceeded".',
                array($r['name'], apps::Mb((int) $r['disk']), apps::Mb($disk[1]), apps::Mb($disk[2])));
            return;
        }
        if (!apps::DbRoom($uid)) {
            self::Say('error', 'Your package has no room for another MySQL database (the app needs one). Delete one in MySQL Databases, or ask for a larger package.');
            return;
        }
        $name = apps::FreeDbName((string) $me['username'], (string) $r['short']);
        if ($name === '') {
            self::Say('error', 'No database name is free for the app.');
            return;
        }
        $made = apps::MakeDb($uid, $name);
        if (!is_array($made)) {
            self::Say('error', $made);
            return;
        }
        $url = (apps::HasSsl($vh) ? 'https://' : 'http://') . $site['name'] . ($sub !== '' ? '/' . $sub : '');
        $zdbh->prepare("INSERT INTO x_apps (ap_acc_fk, ap_vh_fk, ap_sub_vc, ap_app_vc, ap_version_vc, ap_url_vc, ap_title_vc, ap_admin_vc, ap_email_vc, ap_hash_vc, ap_welcome_in, ap_db_fk, ap_dbuser_fk, ap_state_vc, ap_created_in)
            VALUES (:u, :v, :s, :a, :ver, :url, :title, :admin, :email, :hash, :w, :db, :dbu, 'queued', :t)")
            ->execute(array(':u' => $uid, ':v' => $vh, ':s' => $sub, ':a' => $app, ':ver' => (string) $r['version'], ':url' => $url, ':title' => $title,
                ':admin' => $admin, ':email' => $email, ':hash' => apps::AppHash($app, $pass), ':w' => !empty($c['welcome']) ? 1 : 0, ':db' => $made[0], ':dbu' => $made[1], ':t' => time()));
        self::$again = false;
        if ($app === 'snipeit' && !is_file(apps::DIR . '/packages/snipeit-' . $r['version'] . '.tar.gz')) {
            self::Say('ok', '%s is being installed at %s: the server first prepares its package (once), so it is ready within about 20 minutes (Your apps, below). Its database is %s.', array($r['name'], $url, $name));
            return;
        }
        self::Say('ok', '%s is being installed at %s: it is ready within about 5 minutes (Your apps, below). Its database is %s.', array($r['name'], $url, $name));
    }

    /**
     * Deploy an app from Git (the form): the repository's address, branch and web folder checked,
     * a database if asked, then recorded; the daemon clones it as the account's own user (a private
     * repository's deploy key first).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function GitInstall()
    {
        global $zdbh;
        $me = self::Me();
        $uid = (int) $me['userid'];
        $r = apps::Recipes()['git'];
        $sites = apps::Sites($uid);
        $vh = ctype_digit(self::Post('inSite')) ? (int) self::Post('inSite') : 0;
        if (!isset($sites[$vh])) {
            self::Say('error', 'Choose one of your websites.');
            return;
        }
        $site = $sites[$vh];
        $sub = trim(self::Post('inFolder'), " /");
        if ($sub !== '' && (!preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $sub) || preg_match('#(^|/)\.\.?(/|$)#', $sub) || preg_match('#(^|/)(_errorpages|cgi-bin|\.well-known|_app)(/|$)#i', $sub))) {
            self::Say('error', 'A folder name has letters, digits, dots, dashes and underscores (and / between folders).');
            return;
        }
        $repo = trim(self::Post('inRepo'));
        $why = apps::GitUrlProblem($repo);
        if ($why !== '') {
            self::Say('error', $why);
            return;
        }
        $branch = trim(self::Post('inBranch'));
        if (!apps::ValidBranch($branch)) {
            self::Say('error', 'A branch name has letters, digits, dots, dashes, underscores and / (or leave it empty for the repository\'s own).');
            return;
        }
        $web = self::Post('inWeb') === 'custom' ? trim(self::Post('inWebPath'), " /") : self::Post('inWeb');
        if (!in_array(self::Post('inWeb'), array('*', '', 'custom'), true) || !apps::ValidWeb($web) || preg_match('#(^|/)\.#', $web)) {
            self::Say('error', 'The web folder is a folder of the repository, such as public or dist.');
            return;
        }
        if (version_compare(apps::SitePhp($site), $r['php'], '<')) {
            self::Say('error', '%s needs PHP %s or newer, and %s runs PHP %s: choose a newer PHP for it first (Domains, or PHP Versions).', array($r['name'], $r['php'], $site['name'], apps::SitePhp($site)));
            return;
        }
        apps::Ensure();
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_apps WHERE ap_acc_fk = :u AND ap_vh_fk = :v AND ap_sub_vc = :s AND ap_deleted_in IS NULL");
        $q->execute(array(':u' => $uid, ':v' => $vh, ':s' => $sub));
        if ((int) $q->fetchColumn() > 0) {
            self::Say('error', 'There is an app in that folder already (Your apps, below).');
            return;
        }
        $c = apps::Run($uid, $site, 'check', array('sub' => $sub));
        if (isset($c['error'])) {
            self::Say('error', $c['error']);
            return;
        }
        if (empty($c['free'])) {
            self::Say('error', 'That folder is not empty: %s. Choose another folder (such as app), or empty it first in the File Manager.',
                array(implode(', ', (array) $c['found']) . (!empty($c['more']) ? ' (+' . (int) $c['more'] . ')' : '')));
            return;
        }
        $disk = apps::DiskRoom($uid, (int) $r['disk']);
        if (!$disk[0]) {
            self::Say('error', 'An app from Git needs room for its code and libraries (about %s MB) and your package has %s MB free (of %s MB). Make room or ask for a larger package.',
                array(apps::Mb((int) $r['disk']), apps::Mb($disk[1]), apps::Mb($disk[2])));
            return;
        }
        $opts = array();
        if (self::Post('inComposer') === '1') {
            $opts[] = 'composer';
        }
        if (self::Post('inMigrate') === '1') {
            $opts[] = 'migrate';
        }
        $made = array(null, null);
        $dbName = '';
        if (self::Post('inDb') === '1') {
            if (!apps::DbRoom($uid)) {
                self::Say('error', 'Your package has no room for another MySQL database. Untick "A database for it", delete one in MySQL Databases, or ask for a larger package.');
                return;
            }
            $dbName = apps::FreeDbName((string) $me['username'], 'app');
            if ($dbName === '') {
                self::Say('error', 'No database name is free for the app.');
                return;
            }
            $made = apps::MakeDb($uid, $dbName);
            if (!is_array($made)) {
                self::Say('error', $made);
                return;
            }
            $opts[] = 'db';
        }
        $ssh = apps::GitSsh($repo);
        if ($ssh) {
            $opts[] = 'ssh';
        }
        $url = (apps::HasSsl($vh) ? 'https://' : 'http://') . $site['name'] . ($sub !== '' ? '/' . $sub : '');
        $title = preg_match('#([A-Za-z0-9._-]+?)(\.git)?$#', $repo, $m) ? $m[1] : 'app';
        $zdbh->prepare("INSERT INTO x_apps (ap_acc_fk, ap_vh_fk, ap_sub_vc, ap_app_vc, ap_url_vc, ap_title_vc, ap_welcome_in, ap_db_fk, ap_dbuser_fk, ap_state_vc, ap_created_in,
            ap_repo_vc, ap_branch_vc, ap_web_vc, ap_token_vc, ap_opts_vc, ap_layout_in) VALUES (:u, :v, :s, 'git', :url, :title, :w, :db, :dbu, :st, :t, :repo, :b, :web, :tok, :o, 1)")
            ->execute(array(':u' => $uid, ':v' => $vh, ':s' => $sub, ':url' => $url, ':title' => $title, ':w' => !empty($c['welcome']) ? 1 : 0, ':db' => $made[0], ':dbu' => $made[1],
                ':st' => $ssh ? 'key' : 'queued', ':t' => time(), ':repo' => $repo, ':b' => $branch, ':web' => $web, ':tok' => bin2hex(random_bytes(32)), ':o' => implode(',', $opts)));
        self::$again = false;
        if ($ssh) {
            self::Say('ok', 'A deploy key is being made for %s (within 5 minutes, Your apps, below): add it to the repository, then start the install.', array($title));
            return;
        }
        self::Say('ok', '%s is being deployed at %s: it is ready within about 5 minutes (Your apps, below).%s', array($title, $url, $dbName !== '' ? ' ' . str_replace('%s', $dbName, ui_language::translate('Its database is %s.')) : ''));
    }

    /**
     * A Git app's own buttons: start the install (its deploy key added), deploy now, roll back, a new
     * webhook key.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doGit()
    {
        global $zdbh;
        runtime_csfr::Protect();
        $uid = (int) self::Me()['userid'];
        $a = ctype_digit(self::Post('inId')) ? apps::Get($uid, (int) self::Post('inId')) : null;
        if (!$a || $a['ap_app_vc'] !== 'git') {
            return;
        }
        $do = self::Post('inDo');
        $set = function ($from, $to) use ($zdbh, $a) {
            $u = $zdbh->prepare("UPDATE x_apps SET ap_state_vc = :to, ap_out_tx = NULL WHERE ap_id_pk = :id AND ap_state_vc = :from");
            $u->execute(array(':to' => $to, ':id' => (int) $a['ap_id_pk'], ':from' => $from));
            return $u->rowCount() === 1;
        };
        if ($do === 'start') {
            if ($set('waitkey', 'queued')) {
                self::Say('ok', 'The install starts within 5 minutes. If the repository does not know the key yet, the clone fails: then remove the app and deploy again.');
            } else {
                self::Say('error', 'Wait until the app\'s current task is finished.');
            }
        } elseif ($do === 'deploy') {
            if ($set('ready', 'deploy')) {
                self::Say('ok', 'A deploy of %s is queued: it runs within 5 minutes.', array($a['ap_branch_vc']));
            } else {
                self::Say('error', 'Wait until the app\'s current task is finished.');
            }
        } elseif ($do === 'rollback') {
            if (!preg_match('/^[0-9a-f]{40}$/', (string) $a['ap_prev_vc'])) {
                self::Say('error', 'There is no earlier deploy to go back to.');
            } elseif ($set('ready', 'rollback')) {
                self::Say('ok', 'Going back to %s: within 5 minutes.', array(substr((string) $a['ap_prev_vc'], 0, 7)));
            } else {
                self::Say('error', 'Wait until the app\'s current task is finished.');
            }
        } elseif ($do === 'newkey') {
            $zdbh->prepare("UPDATE x_apps SET ap_token_vc = :t WHERE ap_id_pk = :id")->execute(array(':t' => bin2hex(random_bytes(32)), ':id' => (int) $a['ap_id_pk']));
            self::Say('ok', 'The webhook has a new address: change it in the repository\'s settings too (the old one no longer works).');
        }
    }

    /**
     * The panel's address as the account uses it (for the webhook).
     *
     * @param int $uid
     * @return string
     */
    static function PanelUrl($uid)
    {
        $u = class_exists('ctrl_branding') ? ctrl_branding::PanelUrlFor((int) $uid) : '';
        return rtrim($u !== '' ? $u : 'https://' . ctrl_options::GetSystemOption('sentora_domain'), '/');
    }

    /**
     * The form to deploy an app from Git.
     *
     * @param array $r Its recipe.
     * @return string HTML.
     */
    static function GitForm(array $r)
    {
        $me = self::Me();
        $sites = apps::Sites((int) $me['userid']);
        $back = '<div class="ap-head"><a class="btn btn-default btn-sm" href="./?module=apps">' . self::Icon('back') . self::t('All apps') . '</a><h2>' . self::t('Deploy from Git') . '</h2></div>';
        if (!$sites) {
            return $back . '<div class="ap-card"><p>' . self::t('You have no website yet: add a domain or sub-domain first.') . '</p></div>';
        }
        $keep = self::$again;
        $chosen = $keep ? self::Post('inSite') : '';
        $opts = '';
        foreach ($sites as $s) {
            $opts .= '<option value="' . (int) $s['id'] . '"' . ((string) $s['id'] === $chosen ? ' selected' : '') . '>' . self::h($s['name']) . ' (PHP ' . self::h(apps::SitePhp($s)) . ')</option>';
        }
        $f = function ($label, $input, $help = '') {
            return '<label class="ap-field"><span>' . self::t($label) . '</span>' . $input . ($help !== '' ? '<small>' . $help . '</small>' : '') . '</label>';
        };
        $v = function ($n, $default = '') use ($keep) {
            return self::h($keep ? self::Post($n) : $default);
        };
        $chk = function ($n, $label, $help, $default) use ($keep) {
            $on = $keep ? self::Post($n) === '1' : $default;
            return '<label class="ap-check"><input type="checkbox" name="' . $n . '" value="1"' . ($on ? ' checked' : '') . '> <span><b>' . self::t($label) . '</b> ' . $help . '</span></label>';
        };
        $webNow = $keep ? self::Post('inWeb') : '*';
        $webOpt = function ($val, $label) use ($webNow) {
            return '<option value="' . self::h($val) . '"' . ($webNow === $val ? ' selected' : '') . '>' . self::t($label) . '</option>';
        };
        $disk = apps::DiskRoom((int) $me['userid'], (int) $r['disk']);
        $out = $back . '<div class="ap-card">';
        if (!$disk[0]) {
            $out .= '<div class="ap-msg is-bad">' . self::tf('An app from Git needs room for its code and libraries (about %s MB) and your package has %s MB free (of %s MB).', array(apps::Mb((int) $r['disk']), apps::Mb($disk[1]), apps::Mb($disk[2]))) . '</div>';
        }
        $out .= '<form class="ap-form" action="./?module=apps&amp;action=Install" method="post" autocomplete="off">' . runtime_csfr::Token()
            . '<input type="hidden" name="inApp" value="git">'
            . '<fieldset><legend>' . self::t('The repository') . '</legend>'
            . $f('Address', '<input class="form-control" type="text" name="inRepo" value="' . $v('inRepo') . '" maxlength="250" required placeholder="https://github.com/owner/repo.git">',
                self::t('A public repository: its https:// address. A private one: its SSH address (git@github.com:owner/repo.git); the panel makes a deploy key for it, which you add to the repository (read access is enough). Never put a password or token in the address.'))
            . $f('Branch', '<input class="form-control" type="text" name="inBranch" value="' . $v('inBranch') . '" maxlength="100" placeholder="' . self::t('empty: the repository\'s own (main)') . '" pattern="[A-Za-z0-9._/\-]*">',
                self::t('The branch that is deployed, now and every time you deploy again.'))
            . '</fieldset><fieldset><legend>' . self::t('Where') . '</legend>'
            . $f('Website', '<select class="form-control" name="inSite" required>' . $opts . '</select>')
            . $f('Folder', '<input class="form-control" type="text" name="inFolder" value="' . $v('inFolder') . '" maxlength="100" placeholder="' . self::t('empty: the website itself') . '" pattern="[A-Za-z0-9._/\-]*">',
                self::t('Leave it empty for the website\'s own address, or name a folder (such as app) for example.com/app. The folder must be empty or new.'))
            . $f('Web folder', '<select class="form-control" name="inWeb">' . $webOpt('*', 'Find it (public, web, dist, build...)') . $webOpt('', 'The repository\'s top folder') . $webOpt('custom', 'This folder:') . '</select>'
                . '<input class="form-control ap-webpath" type="text" name="inWebPath" value="' . $v('inWebPath') . '" maxlength="80" placeholder="public" pattern="[A-Za-z0-9._/\-]*">',
                self::t('Only this folder of the repository is served; the rest (code, .env, libraries) stays out of the web\'s reach. Laravel and Symfony use public, a built front end dist or build.'))
            . '</fieldset><fieldset><legend>' . self::t('Setting it up') . '</legend>'
            . $chk('inComposer', 'Install its libraries (Composer)', self::t('When it has a composer.json: composer install, without the development ones.'), true)
            . $chk('inDb', 'A database for it', self::t('A MySQL database and user of its own (they show in MySQL Databases); their settings go into its .env (made from .env.example): DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD or DATABASE_URL.'), false)
            . $chk('inMigrate', 'Make its database tables at every deploy (Laravel)', self::t('php artisan migrate --force. A Laravel app also gets its key and the storage link at the install.'), false)
            . '</fieldset>'
            . '<p class="ap-muted ap-note">' . self::t('The repository is cloned into the folder\'s _app, its .git kept in git in your home folder; the website\'s PHP may only read the website\'s own folder, which is why the code is there. Deploy again with one click, or automatically with the webhook shown under Your apps; a deploy that fails goes back to where it was, and Roll back returns to the deploy before. Its build steps (Composer, your own scripts) run as your account. Node.js builds are not run: commit the built files (dist, build) or build them elsewhere.') . '</p>'
            . '<div class="ap-foot"><small class="ap-muted">' . self::tf('From %s. Room for about %s MB is checked first.', array($r['from'], apps::Mb((int) $r['disk']))) . '</small>'
            . '<button class="btn btn-primary" type="submit"' . ($disk[0] ? '' : ' disabled') . '>' . self::t('Deploy') . '</button></div></form></div>';
        return $out;
    }

    /**
     * A Git app's part of its row: the repository, branch, web folder and commit, its buttons, the
     * deploy key and the webhook.
     *
     * @param array $a
     * @return array{0:string, 1:string} Its details line and its buttons (HTML).
     */
    static function GitRow(array $a)
    {
        $id = (int) $a['ap_id_pk'];
        $form = function ($do, $label, $class, $ask = '') use ($id) {
            return '<form class="ap-inline" action="./?module=apps&amp;action=Git" method="post"' . ($ask !== '' ? ' onsubmit="return confirm(this.dataset.ask)" data-ask="' . self::h($ask) . '"' : '') . '>'
                . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . $id . '"><input type="hidden" name="inDo" value="' . self::h($do) . '"><button class="btn ' . $class . ' btn-sm" type="submit">' . $label . '</button></form>';
        };
        $commit = (string) $a['ap_commit_vc'];
        $line = '<div class="ap-git"><code>' . self::h($a['ap_repo_vc']) . '</code>' . ($a['ap_branch_vc'] !== '' ? ' · ' . self::tf('branch %s', array($a['ap_branch_vc'])) : '')
            . ($a['ap_web_vc'] !== '' && $a['ap_web_vc'] !== '*' ? ' · ' . self::tf('serves %s', array($a['ap_web_vc'] . '/')) : '')
            . ($commit !== '' ? '<br>' . self::tf('Commit %s', array(substr($commit, 0, 7))) . ' <span class="ap-muted">' . self::h(mb_substr(substr($commit, 41), 0, 90)) . '</span>'
                . ($a['ap_deployed_in'] ? ' · ' . self::h(ui_language::Date('j M Y, H:i', (int) $a['ap_deployed_in'])) : '') : '')
            . ((int) $a['ap_rc_in'] !== 0 && $a['ap_state_vc'] === 'ready' ? ' <span class="ap-pill is-bad">' . self::t('Last deploy failed') . '</span>' : '') . '</div>';
        $buttons = '';
        if ($a['ap_state_vc'] === 'waitkey') {
            $buttons .= $form('start', self::t('The key is added: start'), 'btn-primary');
        }
        if ($a['ap_state_vc'] === 'ready') {
            $buttons .= $form('deploy', self::Icon('refresh') . self::t('Deploy now'), 'btn-default');
            if (preg_match('/^[0-9a-f]{40}$/', (string) $a['ap_prev_vc']) && strpos($commit, (string) $a['ap_prev_vc']) !== 0) {
                $buttons .= $form('rollback', self::t('Roll back'), 'btn-default', str_replace('%s', substr((string) $a['ap_prev_vc'], 0, 7), ui_language::translate('Go back to commit %s (the deploy before)? The database is not changed back.')));
            }
        }
        $more = '';
        if (trim((string) $a['ap_pubkey_tx']) !== '') {
            $more .= '<p><b>' . self::t('Deploy key') . '</b> ' . self::t('Add it to the repository with read access: GitHub: Settings > Deploy keys; GitLab: Settings > Repository > Deploy keys; Bitbucket: Repository settings > Access keys.') . '</p>'
                . '<pre class="ap-log ap-copy">' . self::h($a['ap_pubkey_tx']) . '</pre>';
        }
        if ($a['ap_state_vc'] !== 'key' && $a['ap_state_vc'] !== 'keying' && strlen((string) $a['ap_token_vc']) === 64) {
            $hook = self::PanelUrl((int) $a['ap_acc_fk']) . '/modules/apps/code/hook.php?app=' . $id . '&key=' . $a['ap_token_vc'];
            $more .= '<p><b>' . self::t('Deploy when you push (webhook)') . '</b> ' . self::tf('Add this address as a webhook of the repository (GitHub: Settings > Webhooks, content type application/json, just the push event; GitLab: Settings > Webhooks, Push events). A push to %s then deploys within 5 minutes. Keep it to yourself: it is the key to deploying.', array($a['ap_branch_vc'] !== '' ? $a['ap_branch_vc'] : 'its branch')) . '</p>'
                . '<pre class="ap-log ap-copy">' . self::h($hook) . '</pre>' . $form('newkey', self::t('New webhook address'), 'btn-default', ui_language::translate('Make a new webhook address? The old one stops working.'));
        }
        if ($more !== '') {
            $line .= '<details' . ($a['ap_state_vc'] === 'waitkey' ? ' open' : '') . '><summary>' . self::t('Deploy key and webhook') . '</summary><div class="ap-more">' . $more . '</div></details>';
        }
        return array($line, $buttons);
    }

    /**
     * Remove an app: its database and MySQL user now, its files in the background.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doRemove()
    {
        global $zdbh;
        runtime_csfr::Protect();
        $uid = (int) self::Me()['userid'];
        $a = ctype_digit(self::Post('inId')) ? apps::Get($uid, (int) self::Post('inId')) : null;
        if (!$a) {
            return;
        }
        if (in_array($a['ap_state_vc'], array('installing', 'removing', 'remove', 'moving', 'deploying', 'rollingback', 'keying', 'deploy', 'rollback', 'move'), true)) {
            self::Say('error', 'Wait until the app\'s current task is finished.');
            return;
        }
        if ($a['ap_db_fk']) {
            apps::DropDb($uid, (int) $a['ap_db_fk'], (int) $a['ap_dbuser_fk']);
        }
        if ($a['ap_app_vc'] === 'snipeit') {
            apps_jobs::SchedulerCronOff($a);
        }
        if (in_array($a['ap_state_vc'], array('queued', 'key', 'waitkey'), true) && !($a['ap_app_vc'] === 'git' && trim((string) $a['ap_pubkey_tx']) !== '')) {
            // not started: nothing in the folder yet
            $zdbh->prepare("UPDATE x_apps SET ap_state_vc = 'removed', ap_hash_vc = NULL, ap_deleted_in = :t WHERE ap_id_pk = :id")->execute(array(':t' => time(), ':id' => (int) $a['ap_id_pk']));
            self::Say('ok', 'The install was cancelled and its database deleted.');
            return;
        }
        $zdbh->prepare("UPDATE x_apps SET ap_state_vc = 'remove', ap_hash_vc = NULL, ap_out_tx = NULL, ap_finished_in = NULL WHERE ap_id_pk = :id")->execute(array(':id' => (int) $a['ap_id_pk']));
        self::Say('ok', 'The database is deleted; the files follow within 5 minutes.');
    }

    /**
     * An icon.
     *
     * @param string $n
     * @return string
     */
    static function Icon($n)
    {
        $p = array(
            'wordpress' => '<circle cx="12" cy="12" r="9"/><path d="M5 8l4 11 3-8 3 8 4-11"/>',
            'snipeit' => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 10h.01"/><path d="M11 10h6"/>',
            'opencart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L22 8H6.2"/>',
            'apps' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
            'back' => '<path d="m15 18-6-6 6-6"/>',
            'open' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
            'git' => '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="9" r="2.5"/><path d="M6 8.5v7"/><path d="M18 11.5a6 6 0 0 1-6 6H8.5"/>',
            'refresh' => '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
        );
        return '<svg class="ap-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
    }

    /**
     * <@ Page @>.
     *
     * @return string HTML.
     */
    static function getPage()
    {
        // (the template asks twice, compiling and then running the page)
        static $page = null;
        if ($page !== null) {
            return $page;
        }
        return $page = self::PageHtml();
    }

    /**
     * The page: messages, then the install form (an app chosen) or the apps offered and the
     * account's own.
     *
     * @return string HTML.
     */
    static function PageHtml()
    {
        $out = '';
        foreach (self::$msg as $m) {
            $out .= '<div class="ap-msg ' . ($m[0] === 'ok' ? 'is-ok' : 'is-bad') . '" role="' . ($m[0] === 'ok' ? 'status' : 'alert') . '">' . self::h($m[1]) . '</div>';
        }
        $recipes = apps::Recipes();
        $app = isset($_GET['app']) && is_string($_GET['app']) ? $_GET['app'] : (self::$again ? self::Post('inApp') : '');
        if ($app === 'git') {
            return $out . self::GitForm($recipes['git']);
        }
        if (isset($recipes[$app])) {
            return $out . self::Form($app, $recipes[$app]);
        }
        return $out . self::Offered($recipes) . self::Mine($recipes);
    }

    /**
     * The apps offered.
     *
     * @param array $recipes
     * @return string HTML.
     */
    static function Offered(array $recipes)
    {
        $cards = '';
        foreach ($recipes as $k => $r) {
            $cards .= '<div class="ap-app"><div class="ap-app-head"><span class="ap-logo">' . self::Icon($k) . '</span><b>' . self::h($r['name']) . '</b>'
                . ($r['version'] !== '' ? '<span class="ap-muted">' . self::h($r['version']) . '</span>' : '') . '</div>'
                . '<p>' . self::t($r['desc']) . '</p>'
                . '<a class="btn btn-primary btn-sm" href="./?module=apps&amp;app=' . self::h($k) . '">' . ($k === 'git' ? self::t('Deploy from Git') : self::tf('Install %s', array($r['name']))) . '</a></div>';
        }
        return '<div class="ap-card"><h3>' . self::Icon('apps') . self::t('Install an app') . '</h3><p class="ap-muted">'
            . self::t('The panel installs it into one of your websites, with a database of its own, in a few minutes. No setup page is ever left open on the internet.') . '</p>'
            . '<div class="ap-apps">' . $cards . '</div></div>';
    }

    /**
     * The install form of an app.
     *
     * @param string $key
     * @param array $r Its recipe.
     * @return string HTML.
     */
    static function Form($key, array $r)
    {
        $me = self::Me();
        $sites = apps::Sites((int) $me['userid']);
        $back = '<div class="ap-head"><a class="btn btn-default btn-sm" href="./?module=apps">' . self::Icon('back') . self::t('All apps') . '</a><h2>' . self::tf('Install %s', array($r['name'])) . '</h2></div>';
        if (!$sites) {
            return $back . '<div class="ap-card"><p>' . self::t('You have no website yet: add a domain or sub-domain first.') . '</p></div>';
        }
        $keep = self::$again;
        $chosen = $keep ? self::Post('inSite') : '';
        $opts = '';
        foreach ($sites as $s) {
            $old = version_compare(apps::SitePhp($s), $r['php'], '<');
            $opts .= '<option value="' . (int) $s['id'] . '"' . ((string) $s['id'] === $chosen ? ' selected' : '') . '>' . self::h($s['name'])
                . ($old ? ' (' . self::tf('PHP %s: too old', array(apps::SitePhp($s))) . ')' : '') . '</option>';
        }
        $min = max(10, (int) ctrl_options::GetSystemOption('password_minlength'));
        $max = (int) $r['pass'];
        $disk = apps::DiskRoom((int) $me['userid'], (int) $r['disk']);
        $room = apps::DbRoom((int) $me['userid']) && $disk[0];
        $f = function ($name, $label, $input, $help = '') {
            return '<label class="ap-field"><span>' . self::t($label) . '</span>' . $input . ($help !== '' ? '<small>' . $help . '</small>' : '') . '</label>';
        };
        $v = function ($n, $default = '') use ($keep) {
            return self::h($keep ? self::Post($n) : $default);
        };
        $out = $back . '<div class="ap-card">';
        if (!$disk[0]) {
            $out .= '<div class="ap-msg is-bad">' . self::tf('%s needs about %s MB of disk space and your package has %s MB free (of %s MB). Make room (files, mail, databases) or ask for a larger package.',
                array($r['name'], apps::Mb((int) $r['disk']), apps::Mb($disk[1]), apps::Mb($disk[2]))) . '</div>';
        } elseif (!$room) {
            $out .= '<div class="ap-msg is-bad">' . self::t('Your package has no room for another MySQL database, and the app needs one. Delete one in MySQL Databases, or ask for a larger package.') . '</div>';
        }
        $out .= '<form class="ap-form" action="./?module=apps&amp;action=Install" method="post" autocomplete="off">' . runtime_csfr::Token()
            . '<input type="hidden" name="inApp" value="' . self::h($key) . '">'
            . '<fieldset><legend>' . self::t('Where') . '</legend>'
            . $f('inSite', 'Website', '<select class="form-control" name="inSite" required>' . $opts . '</select>')
            . $f('inFolder', 'Folder', '<input class="form-control" type="text" name="inFolder" value="' . $v('inFolder') . '" maxlength="100" placeholder="' . self::t('empty: the website itself') . '" pattern="[A-Za-z0-9._/\-]*">',
                self::t('Leave it empty to put the app at the website\'s own address, or name a folder (such as blog) for an address like example.com/blog. The folder must be empty or new.'))
            . '</fieldset><fieldset><legend>' . self::t('The site') . '</legend>'
            . $f('inTitle', $key === 'opencart' ? 'Store name' : 'Site title', '<input class="form-control" type="text" name="inTitle" value="' . $v('inTitle') . '" maxlength="100" required>', self::tf('You can change it later in %s.', array($r['name'])))
            . '</fieldset><fieldset><legend>' . self::t('Its administrator') . '</legend>'
            . $f('inAdmin', 'User name', '<input class="form-control" type="text" name="inAdmin" value="' . $v('inAdmin') . '" minlength="3" maxlength="' . (int) $r['user'] . '" pattern="[A-Za-z0-9._\-]+" required autocomplete="off">',
                self::tf('3 to %s letters or digits. Not "admin": that is the first name attackers try.', array((int) $r['user'])))
            . $f('inEmail', 'Email address', '<input class="form-control" type="email" name="inEmail" value="' . $v('inEmail', (string) $me['email']) . '" maxlength="96" required>', self::tf('%s sends password resets and notices there.', array($r['name'])))
            . $f('inPass', 'Password', '<input class="form-control" type="password" name="inPass" minlength="' . $min . '" maxlength="' . $max . '" required autocomplete="new-password">',
                self::tf('%s to %s characters. The panel keeps only a scrambled form of it (as %s itself does), never the password.', array($min, $max, $r['name'])))
            . $f('inPass2', 'The password again', '<input class="form-control" type="password" name="inPass2" minlength="' . $min . '" maxlength="' . $max . '" required autocomplete="new-password">')
            . '</fieldset>' . ($key === 'opencart' ? '<p class="ap-muted ap-note">' . self::t('Made safe as OpenCart asks after an install: its install folder is removed, its storage folder (logs, cache, uploads) is moved out of the website into opencart-storage in your home folder, and its admin folder gets a name of its own (shown under Your apps, with Log in).') . '</p>' : '')
            . ($key === 'snipeit' ? '<p class="ap-muted ap-note">' . self::t('Only its public files go in the website\'s folder; its code, settings and data stay in snipeit in your home folder, out of the web\'s reach. Its scheduler (alerts, reminders, a weekly backup) runs every minute as a task in your Cron Manager. The first Snipe-IT install on this server waits while the server prepares its package (about 15 minutes, once).') . '</p>' : '')
            . '<div class="ap-foot"><small class="ap-muted">' . self::tf('From %s. It takes about %s MB of your disk space and gets a database and MySQL user of its own (they show in MySQL Databases and count toward your package). The site uses https when it has a certificate.', array($r['from'], apps::Mb((int) $r['disk']))) . '</small>'
            . '<button class="btn btn-primary" type="submit"' . ($room ? '' : ' disabled') . '>' . self::tf('Install %s', array($r['name'])) . '</button></div></form></div>';
        return $out;
    }

    /**
     * The account's apps.
     *
     * @param array $recipes
     * @return string HTML.
     */
    static function Mine(array $recipes)
    {
        $me = self::Me();
        $sites = apps::Sites((int) $me['userid']);
        $states = array('queued' => array('Waiting to start', 'is-run'), 'installing' => array('Installing', 'is-run'), 'ready' => array('Ready', 'is-ok'),
            'failed' => array('Failed', 'is-bad'), 'remove' => array('Being removed', 'is-run'), 'removing' => array('Being removed', 'is-run'),
            'move' => array('Being moved', 'is-run'), 'moving' => array('Being moved', 'is-run'), 'deploy' => array('Deploying', 'is-run'), 'deploying' => array('Deploying', 'is-run'),
            'rollback' => array('Going back', 'is-run'), 'rollingback' => array('Going back', 'is-run'), 'key' => array('Making a deploy key', 'is-run'), 'keying' => array('Making a deploy key', 'is-run'),
            'waitkey' => array('Add the deploy key', 'is-warn'));
        $rows = '';
        $busy = false;
        foreach (apps::Installed((int) $me['userid']) as $a) {
            $r = isset($recipes[$a['ap_app_vc']]) ? $recipes[$a['ap_app_vc']] : array('name' => $a['ap_app_vc'], 'admin' => '');
            // the admin's address: recorded at the install (OpenCart's renamed admin/), else the app's own
            $login = preg_match('#^[A-Za-z0-9._-]+/$#', (string) $a['ap_login_vc']) ? (string) $a['ap_login_vc'] : (string) $r['admin'];
            $st = isset($states[$a['ap_state_vc']]) ? $states[$a['ap_state_vc']] : array($a['ap_state_vc'], '');
            $busy = $busy || in_array($a['ap_state_vc'], array('queued', 'installing', 'remove', 'removing', 'move', 'moving', 'deploy', 'deploying', 'rollback', 'rollingback', 'key', 'keying'), true);
            $git = $a['ap_app_vc'] === 'git' ? self::GitRow($a) : array('', '');
            $site = isset($sites[(int) $a['ap_vh_fk']]) ? $sites[(int) $a['ap_vh_fk']]['name'] : '?';
            $url = (string) $a['ap_url_vc'];
            $links = '';
            if ($a['ap_state_vc'] === 'ready' && preg_match('#^https?://#', $url)) {
                $links .= '<a class="btn btn-default btn-sm" href="' . self::h($url) . '/" target="_blank" rel="noopener">' . self::Icon('open') . self::t('Open the site') . '</a>'
                    . ($login !== '' ? '<a class="btn btn-default btn-sm" href="' . self::h($url . '/' . $login) . '" target="_blank" rel="noopener">' . self::Icon('login') . self::t('Log in') . '</a>' : '');
            }
            $links .= $git[1];
            if (in_array($a['ap_state_vc'], array('ready', 'failed', 'queued', 'waitkey'), true)) {
                $links .= '<form class="ap-inline" action="./?module=apps&amp;action=Remove" method="post" onsubmit="return confirm(this.dataset.ask)" data-ask="'
                    . ($a['ap_state_vc'] === 'queued' || $a['ap_state_vc'] === 'waitkey'
                        ? self::tf('Cancel the install of %s at %s? Its new database (%s) is deleted.', array($r['name'], $url, self::DbName($a) !== '' ? self::DbName($a) : '-'))
                        : self::tf('Remove %s from %s? Its files and its database (%s) are deleted for good: make a backup first if you may need them.', array($r['name'], $url, self::DbName($a) !== '' ? self::DbName($a) : '-'))) . '">'
                    . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . (int) $a['ap_id_pk'] . '"><button class="btn btn-danger btn-sm" type="submit">'
                    . self::t($a['ap_state_vc'] === 'queued' || $a['ap_state_vc'] === 'waitkey' ? 'Cancel' : 'Remove') . '</button></form>';
            }
            $out = trim((string) $a['ap_out_tx']);
            $rows .= '<li class="ap-row"><div class="ap-row-main"><span class="ap-logo">' . self::Icon($a['ap_app_vc']) . '</span><div><b>' . self::h($a['ap_app_vc'] === 'git' ? $a['ap_title_vc'] : $r['name']) . '</b> '
                . '<span class="ap-pill ' . $st[1] . '">' . self::t($st[0]) . '</span>'
                . '<div class="ap-muted">' . self::h($url !== '' ? $url : $site) . ($a['ap_version_vc'] !== '' ? ' · ' . self::h($a['ap_version_vc']) : '')
                . ($a['ap_admin_vc'] !== '' ? ' · ' . self::tf('admin %s', array($a['ap_admin_vc'])) : '') . ' · ' . self::h(ui_language::Date('j M Y, H:i', (int) $a['ap_created_in'])) . '</div>' . $git[0] . '</div></div>'
                . '<div class="ap-actions">' . $links . '</div>'
                . ($out !== '' ? '<details' . ($a['ap_state_vc'] === 'failed' ? ' open' : '') . '><summary>' . self::t('What the server did') . '</summary><pre class="ap-log">' . self::h($out) . '</pre></details>' : '')
                . '</li>';
        }
        if ($rows === '') {
            return '';
        }
        return '<div class="ap-card"><h3>' . self::Icon('apps') . self::t('Your apps') . '</h3>'
            . ($busy ? '<p class="ap-muted">' . self::t('The server works on it as your own account, within 5 minutes. This page looks again every 20 seconds.') . '</p>'
                . '<script>setTimeout(function () { if (!document.querySelector(".ap-form")) { location.href = "./?module=apps"; } }, 20000);</script>' : '')
            . '<ul class="ap-list">' . $rows . '</ul></div>';
    }

    /**
     * The name of an app's database (for the removal question).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $a
     * @return string
     */
    static function DbName(array $a)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT my_name_vc FROM x_mysql_databases WHERE my_id_pk = :id AND my_acc_fk = :u");
        $q->execute(array(':id' => (int) $a['ap_db_fk'], ':u' => (int) $a['ap_acc_fk']));
        return (string) $q->fetchColumn();
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
