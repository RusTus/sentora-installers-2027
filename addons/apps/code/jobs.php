<?php

/**
 * Apps' background work, done by the daemon as the account's own Linux user (web<account>):
 * installing an app (WordPress with WP-CLI: download checked against wordpress.org's checksums,
 * wp-config.php, the install, the admin's password; OpenCart with its own installer, then made
 * safe as OpenCart asks; Snipe-IT from a package the server prepares once, with its own
 * commands) and removing its files. The page made the
 * database and recorded the request (x_apps); the daemon checks it again (a live account, its own
 * site, a free folder, a PHP that runs the app) and starts it in the background, at low priority
 * and for a limited time, keeping its output for the page.
 *
 * The daemon is root: it never touches the account's files itself. The database password and the
 * admin's password (as WordPress's own hash) reach the install on its input, from a file only root
 * can read: never on a command line, where every user of the server could see them.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class apps_jobs
{
    /** WP-CLI, downloaded by the install step (and again when missing or a month old). */
    const WPCLI = '/var/lib/sentora/apps/wp-cli.phar';

    /** Seconds an install or a removal may take. */
    const SECONDS = 900;

    /** Tasks run at once, on the whole server. */
    const PARALLEL = 2;

    /** StartInstall's answer while the app's package is being prepared: the app stays queued. */
    const WAIT = 'wait';

    /** Snipe-IT's scheduler script, in its website's folder (Cron Manager takes .php files there only). */
    const SCHEDULER = 'snipeit-scheduler.php';

    /** Each waiting state of a task and the state while it runs. */
    const RUNNING = array('queued' => 'installing', 'remove' => 'removing', 'move' => 'moving', 'deploy' => 'deploying', 'rollback' => 'rollingback', 'key' => 'keying');

    /** Seconds a Git clone or deploy may take (Composer can be slow). */
    const GIT_SECONDS = 1800;

    /** Composer (for Snipe-IT's libraries), downloaded with its published SHA-256 checked. */
    const COMPOSER = '/var/lib/sentora/apps/composer.phar';

    /**
     * Daemon: collect the tasks that finished, start queued ones.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string[] What happened (for the daemon's log).
     */
    static function Run()
    {
        global $zdbh;
        apps::Ensure();
        $log = array();
        $jobs = apps::DIR . '/jobs';
        if (!is_dir($jobs)) {
            @mkdir($jobs, 0700, true);
        }
        if (!is_dir($jobs) || is_link($jobs) || is_link(apps::DIR)) {
            return array('Apps: ' . $jobs . ' could not be made.');
        }
        @chmod($jobs, 0700);
        // Snipe-IT installed before Apps 105 has its code outside its website, where the website's
        // PHP (limited to the website's folder) can not read it: moved into the website's _app
        $zdbh->exec("UPDATE x_apps SET ap_state_vc = 'move' WHERE ap_app_vc = 'snipeit' AND ap_layout_in = 0 AND ap_state_vc = 'ready' AND ap_deleted_in IS NULL");
        $runningStates = "'" . implode("', '", array_values(self::RUNNING)) . "'";
        $waitingStates = "'" . implode("', '", array_keys(self::RUNNING)) . "'";
        // finished (or stuck) tasks
        foreach ($zdbh->query("SELECT * FROM x_apps WHERE ap_state_vc IN (" . $runningStates . ") AND ap_deleted_in IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $id = (int) $a['ap_id_pk'];
            $rc = $jobs . '/' . $id . '.rc';
            $out = $jobs . '/' . $id . '.out';
            $max = $a['ap_app_vc'] === 'git' ? self::GIT_SECONDS : self::SECONDS;
            if (!is_file($rc) && time() - (int) $a['ap_started_in'] <= $max + 900) {
                continue;
            }
            $code = is_file($rc) ? (int) trim((string) file_get_contents($rc)) : 124;
            $text = is_file($out) ? (string) file_get_contents($out, false, null, max(0, (int) filesize($out) - 65536)) : '';
            // WP-CLI shows the admin password it made up: it was replaced by the owner's own
            $text = preg_replace('/^(Admin password:).*$/m', '$1 (replaced by the one you chose)', $text);
            // (and a database password, should a tool ever show its command line)
            $text = preg_replace('/(--dbpass=)(\'[^\']*\'|\S+)/', '$1********', $text);
            if ($code === 124) {
                $text .= "\n(stopped: it took longer than " . round($max / 60) . " minutes)";
            }
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            $m = self::Markers($text);
            @unlink($rc);
            @unlink($out);
            @unlink($jobs . '/' . $id . '.in');
            $state = (string) $a['ap_state_vc'];
            if ($state === 'removing') {
                ctrl_notices::Withdraw('apps-deploy-' . $id, 0);
                $zdbh->prepare("UPDATE x_apps SET ap_state_vc = :s, ap_rc_in = :r, ap_out_tx = :o, ap_finished_in = :t, ap_deleted_in = :d WHERE ap_id_pk = :id")
                    ->execute(array(':s' => $code === 0 ? 'removed' : 'failed', ':r' => $code, ':o' => $text, ':t' => time(), ':d' => $code === 0 ? time() : null, ':id' => $id));
                $log[] = 'Apps: ' . $id . ' (' . $a['ap_app_vc'] . ') ' . ($code === 0 ? 'removed' : 'not removed, exit code ' . $code);
                continue;
            }
            if ($state === 'keying') {
                $ok = $code === 0 && isset($m['PUBKEY']) && preg_match('#^ssh-ed25519 [A-Za-z0-9+/=]{60,100}( [A-Za-z0-9@._-]{1,80})?$#', $m['PUBKEY']);
                $zdbh->prepare("UPDATE x_apps SET ap_state_vc = :s, ap_rc_in = :r, ap_out_tx = :o, ap_finished_in = :t, ap_pubkey_tx = :k WHERE ap_id_pk = :id")
                    ->execute(array(':s' => $ok ? 'waitkey' : 'failed', ':r' => $code, ':o' => $text, ':t' => time(), ':k' => $ok ? $m['PUBKEY'] : null, ':id' => $id));
                $log[] = 'Apps: ' . $id . ' deploy key ' . ($ok ? 'made' : 'not made');
                continue;
            }
            if ($state === 'moving') {
                // (a failed move leaves the app as it was: shown, tried again only by hand)
                $zdbh->prepare("UPDATE x_apps SET ap_state_vc = 'ready', ap_rc_in = :r, ap_out_tx = :o, ap_finished_in = :t, ap_layout_in = :l WHERE ap_id_pk = :id")
                    ->execute(array(':r' => $code, ':o' => ($code === 0 ? '' : "Moving its code into the website did not work:\n") . $text, ':t' => time(), ':l' => $code === 0 ? 1 : 2, ':id' => $id));
                $log[] = 'Apps: ' . $id . ' (snipeit) ' . ($code === 0 ? 'moved into its website' : 'not moved, exit code ' . $code);
                continue;
            }
            if ($state === 'deploying' || $state === 'rollingback') {
                $set = "ap_state_vc = 'ready', ap_rc_in = :r, ap_out_tx = :o, ap_finished_in = :t";
                $args = array(':r' => $code, ':o' => $text, ':t' => time(), ':id' => $id);
                if (isset($m['COMMIT']) && preg_match('/^[0-9a-f]{40}( .*)?$/', $m['COMMIT'])) {
                    $set .= ", ap_commit_vc = :c, ap_version_vc = :v, ap_deployed_in = :dt";
                    $args += array(':c' => mb_substr($m['COMMIT'], 0, 250), ':v' => substr($m['COMMIT'], 0, 7), ':dt' => time());
                    if (isset($m['PREV']) && preg_match('/^[0-9a-f]{40}$/', $m['PREV']) && strpos($m['COMMIT'], $m['PREV']) !== 0) {
                        $set .= ", ap_prev_vc = :p";
                        $args[':p'] = $m['PREV'];
                    }
                }
                $zdbh->prepare("UPDATE x_apps SET " . $set . " WHERE ap_id_pk = :id")->execute($args);
                $log[] = 'Apps: ' . $id . ' (git) ' . ($state === 'deploying' ? 'deploy' : 'roll back') . ' ' . ($code === 0 ? 'done' : 'failed, exit code ' . $code);
                // a deploy that failed (often one started by a push, which nobody watches): a notice for the
                // app's owner, taken away by the next one that works
                if ($code !== 0) {
                    ctrl_notices::Send(array('scope' => 'user', 'targets' => array((int) $a['ap_acc_fk']), 'type' => 'warning', 'from' => 0, 'source' => 'apps',
                        'key' => 'apps-deploy-' . $id, 'closable' => 'login', 'title' => 'A deploy of ' . $a['ap_title_vc'] . ' failed',
                        'body' => 'The deploy of ' . $a['ap_title_vc'] . ' (' . $a['ap_url_vc'] . ') did not work, so the app went back to the code it had before and still runs. What the server did is under Domain Management > Apps, on the app\'s row.'));
                } else {
                    ctrl_notices::Withdraw('apps-deploy-' . $id, 0);
                }
                continue;
            }
            $version = '';
            $extra = '';
            $args = array();
            if ($code === 0) {
                $sites = apps::Sites((int) $a['ap_acc_fk']);
                if ($a['ap_app_vc'] === 'git') {
                    if (isset($m['COMMIT']) && preg_match('/^[0-9a-f]{40}( .*)?$/', $m['COMMIT'])) {
                        $version = substr($m['COMMIT'], 0, 7);
                        $extra .= ', ap_commit_vc = :c, ap_deployed_in = :dt';
                        $args += array(':c' => mb_substr($m['COMMIT'], 0, 250), ':dt' => time());
                    }
                    if (isset($m['WEB']) && apps::ValidWeb($m['WEB']) && $m['WEB'] !== '*') {
                        $extra .= ', ap_web_vc = :w';
                        $args[':w'] = $m['WEB'];
                    }
                    if (isset($m['BRANCH']) && apps::ValidBranch($m['BRANCH']) && $m['BRANCH'] !== '') {
                        $extra .= ', ap_branch_vc = :b';
                        $args[':b'] = $m['BRANCH'];
                    }
                } elseif (isset($sites[(int) $a['ap_vh_fk']])) {
                    $v = apps::Run((int) $a['ap_acc_fk'], $sites[(int) $a['ap_vh_fk']], 'version', array('sub' => (string) $a['ap_sub_vc']), true);
                    $version = isset($v['version']) ? (string) $v['version'] : '';
                }
                if ($a['ap_app_vc'] === 'snipeit') {
                    $text .= self::SchedulerCron($a) . "\n";
                }
            }
            $zdbh->prepare("UPDATE x_apps SET ap_state_vc = :s, ap_rc_in = :r, ap_out_tx = :o, ap_finished_in = :t, ap_hash_vc = NULL, ap_version_vc = :v" . $extra . " WHERE ap_id_pk = :id")
                ->execute(array(':s' => $code === 0 ? 'ready' : 'failed', ':r' => $code, ':o' => $text, ':t' => time(), ':v' => $version !== '' ? $version : (string) $a['ap_version_vc'], ':id' => $id) + $args);
            $log[] = 'Apps: ' . $id . ' (' . $a['ap_app_vc'] . ') ' . ($code === 0 ? 'installed' : 'failed, exit code ' . $code);
        }
        // waiting tasks: a few at once on the server, one at a time per account
        $running = (int) $zdbh->query("SELECT COUNT(*) FROM x_apps WHERE ap_state_vc IN (" . $runningStates . ") AND ap_deleted_in IS NULL")->fetchColumn();
        foreach ($zdbh->query("SELECT * FROM x_apps WHERE ap_state_vc IN (" . $waitingStates . ") AND ap_deleted_in IS NULL ORDER BY ap_id_pk")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if ($running >= self::PARALLEL) {
                break;
            }
            $busy = $zdbh->prepare("SELECT COUNT(*) FROM x_apps WHERE ap_acc_fk = :u AND ap_state_vc IN (" . $runningStates . ") AND ap_deleted_in IS NULL");
            $busy->execute(array(':u' => (int) $a['ap_acc_fk']));
            if ((int) $busy->fetchColumn() > 0) {
                continue;
            }
            $state = (string) $a['ap_state_vc'];
            $remove = $state === 'remove';
            if ($state === 'move') {
                $why = self::StartMove($a);
            } elseif ($state === 'deploy' || $state === 'rollback') {
                $why = self::StartDeploy($a, $state === 'rollback');
            } elseif ($state === 'key') {
                $why = self::StartKey($a);
            } else {
                $why = $remove ? self::StartRemove($a) : self::StartInstall($a);
            }
            if ($why !== '' && in_array($state, array('move', 'deploy', 'rollback'), true)) {
                // (the app stays as it is: why, on its row)
                $zdbh->prepare("UPDATE x_apps SET ap_state_vc = 'ready', ap_rc_in = 1, ap_out_tx = :o, ap_finished_in = :t" . ($state === 'move' ? ', ap_layout_in = 2' : '') . " WHERE ap_id_pk = :id")
                    ->execute(array(':o' => $why, ':t' => time(), ':id' => (int) $a['ap_id_pk']));
                $log[] = 'Apps: ' . (int) $a['ap_id_pk'] . ' ' . $state . ' not started: ' . $why;
                continue;
            }
            if ($why !== '' && $remove) {
                // its database is gone already (the page dropped it): the app is removed, its files left alone
                $zdbh->prepare("UPDATE x_apps SET ap_state_vc = 'removed', ap_out_tx = :o, ap_finished_in = :t, ap_deleted_in = :t2 WHERE ap_id_pk = :id")
                    ->execute(array(':o' => 'The files were left alone: ' . $why, ':t' => time(), ':t2' => time(), ':id' => (int) $a['ap_id_pk']));
                $log[] = 'Apps: ' . (int) $a['ap_id_pk'] . ' removed (files left alone: ' . $why . ')';
                continue;
            }
            if ($why === self::WAIT) {
                // its package is being prepared (once per server): it starts on a later run
                $zdbh->prepare("UPDATE x_apps SET ap_out_tx = :o WHERE ap_id_pk = :id")
                    ->execute(array(':o' => 'The server is preparing this app\'s package (once, for every account): the install starts when it is ready, within about 15 minutes.', ':id' => (int) $a['ap_id_pk']));
                $log[] = 'Apps: ' . (int) $a['ap_id_pk'] . ' waits for its package';
                continue;
            }
            if ($why !== '') {
                $zdbh->prepare("UPDATE x_apps SET ap_state_vc = 'failed', ap_out_tx = :o, ap_finished_in = :t, ap_hash_vc = NULL WHERE ap_id_pk = :id")
                    ->execute(array(':o' => $why, ':t' => time(), ':id' => (int) $a['ap_id_pk']));
                $log[] = 'Apps: ' . (int) $a['ap_id_pk'] . ' not started: ' . $why;
                continue;
            }
            $running++;
            $log[] = 'Apps: ' . (int) $a['ap_id_pk'] . ' (' . $a['ap_app_vc'] . ') ' . $state . ' started';
        }
        return $log;
    }

    /**
     * The lines a task prints for the daemon (APPS-NAME: value), taken out of its output.
     *
     * @param string $text The output (the lines are taken out).
     * @return array<string, string>
     */
    static function Markers(&$text)
    {
        $m = array();
        if (preg_match_all('/^APPS-([A-Z]+): ?(.*)$/m', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $x) {
                $m[$x[1]] = trim($x[2]);
            }
            $text = trim(preg_replace('/^APPS-[A-Z]+:.*\R?/m', '', $text));
        }
        return $m;
    }

    /**
     * The account and site of a task, checked again: a live account with its Linux user, and the
     * site still its own.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $a The x_apps row.
     * @return array|string array(site, home) or why not.
     */
    static function Where(array $a)
    {
        global $zdbh;
        $uid = (int) $a['ap_acc_fk'];
        $q = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_id_pk = :u AND ac_enabled_in = 1 AND ac_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid));
        if (!$q->fetchColumn() || posix_getpwnam(sys_accountphp::UserName($uid)) === false) {
            return 'The account is switched off, or its Linux user is not made yet.';
        }
        $sites = apps::Sites($uid);
        if (!isset($sites[(int) $a['ap_vh_fk']])) {
            return 'The website is gone.';
        }
        $home = realpath(apps::Home($uid));
        if ($home === false || !preg_match('#^/[A-Za-z0-9_./-]+$#', $home)) {
            return 'The hosting folder is missing.';
        }
        return array($sites[(int) $a['ap_vh_fk']], $home);
    }

    /**
     * Start an install: the request checked again (a live account, its own site, a free folder, a
     * PHP that runs the app, the database and its password, the details), then the app's own steps.
     *
     * @param array $a The x_apps row.
     * @return string '' when started, else why not.
     */
    static function StartInstall(array $a)
    {
        $recipes = apps::Recipes();
        $app = (string) $a['ap_app_vc'];
        if (!isset($recipes[$app])) {
            return 'No such app.';
        }
        $r = $recipes[$app];
        $w = self::Where($a);
        if (!is_array($w)) {
            return $w;
        }
        list($site, $home) = $w;
        $uid = (int) $a['ap_acc_fk'];
        if (version_compare(apps::SitePhp($site), $r['php'], '<')) {
            return $r['name'] . ' needs PHP ' . $r['php'] . ' or newer; the site runs ' . apps::SitePhp($site) . '.';
        }
        // the disk space again (the daemon's count is fresher now): over it, every website of the account is shown as "disk space exceeded"
        $disk = apps::DiskRoom($uid, (int) $r['disk'], (int) $a['ap_id_pk']);
        if (!$disk[0]) {
            return $r['name'] . ' needs about ' . apps::Mb((int) $r['disk']) . ' MB of disk space and the package has ' . apps::Mb($disk[1]) . ' MB free (of ' . apps::Mb($disk[2]) . ' MB): make room, then remove this and install again.';
        }
        // the folder, looked at again as the account's own user
        $c = apps::Run($uid, $site, 'check', array('sub' => (string) $a['ap_sub_vc']), true);
        if (isset($c['error'])) {
            return (string) $c['error'];
        }
        if (empty($c['free'])) {
            return 'The folder is not empty any more: ' . implode(', ', (array) $c['found']) . '.';
        }
        $target = $home . '/' . (string) $c['rel'];
        $php = escapeshellarg(self::Php(apps::SitePhp($site)));
        $limit = ' -d open_basedir=' . escapeshellarg($home . '/:' . apps::DIR . '/') . ' -d sys_temp_dir=' . escapeshellarg($home . '/tmp') . ' -d upload_tmp_dir=' . escapeshellarg($home . '/tmp');
        $start = 'set -e; umask 022; mkdir -p ' . escapeshellarg($home . '/tmp') . ' ' . escapeshellarg($target) . '; cd ' . escapeshellarg($target) . '; '
            . (!empty($c['welcome']) ? 'rm -f index.html; ' : '');
        if ($app === 'git') {
            $login = null;
            if (!empty(self::Opts($a)['db'])) {
                $login = apps::DbLogin($a);
                if ($login === null) {
                    return 'The app\'s database or MySQL user is gone.';
                }
            }
            if (apps::GitSsh((string) $a['ap_repo_vc']) && trim((string) $a['ap_pubkey_tx']) === '') {
                return 'The deploy key is missing.';
            }
            return self::InstallGit($a, $site, $home, $target, $php, $limit, $start, $login);
        }
        $login = apps::DbLogin($a);
        if ($login === null) {
            return 'The app\'s database or MySQL user is gone.';
        }
        $hash = (string) $a['ap_hash_vc'];
        if (strpos($hash, $app === 'wordpress' ? '$wp$2y$' : '$2y$') !== 0 || strlen($hash) > 100) {
            return 'The admin password is missing.';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,' . (int) $r['user'] . '}$/', (string) $a['ap_admin_vc']) || strlen((string) $a['ap_email_vc']) > 96
            || !filter_var((string) $a['ap_email_vc'], FILTER_VALIDATE_EMAIL) || !preg_match('#^https?://[A-Za-z0-9.-]+(/[A-Za-z0-9._/-]*)?$#', (string) $a['ap_url_vc'])) {
            return 'The install\'s details are not valid.';
        }
        if ($app === 'snipeit') {
            return self::InstallSnipeIt($a, $r, $home, $target, $php, $limit, $start, $login, $hash);
        }
        if ($app === 'opencart') {
            return self::InstallOpenCart($a, $r, $home, $target, $php, $limit, $start, $login, $hash);
        }
        return self::InstallWordPress($a, $home, $target, $php, $limit, $start, $login, $hash);
    }

    /**
     * WordPress: unpacked from wordpress.org's checked package, every file checked again, then
     * wp-config.php and the install with WP-CLI, and the owner's password (as its hash).
     *
     * @param array $a
     * @param string $home
     * @param string $target
     * @param string $php The site's PHP (escaped).
     * @param string $limit Its confinement (-d ...).
     * @param string $start The first commands.
     * @param array $login Database, user, password.
     * @param string $hash
     * @return string
     */
    static function InstallWordPress(array $a, $home, $target, $php, $limit, $start, array $login, $hash)
    {
        if (!is_file(self::WPCLI)) {
            return 'WP-CLI is not on this server yet (the daemon downloads it every hour until it is).';
        }
        $version = (string) $a['ap_version_vc'];
        if ($version === '') {
            $latest = apps::Latest();
            $version = isset($latest['wordpress']['version']) ? (string) $latest['wordpress']['version'] : '';
        }
        $package = self::WordPressPackage($version);
        if ($package === '') {
            return 'WordPress ' . $version . ' could not be downloaded from wordpress.org (or its checksum did not match): try again later.';
        }
        $wp = $php . ' -d memory_limit=512M -d error_reporting=' . escapeshellarg('E_ALL & ~E_WARNING & ~E_DEPRECATED') . $limit
            . ' ' . escapeshellarg(self::WPCLI) . ' --path=' . escapeshellarg($target) . ' --no-color';
        // the passwords come first on the input (read by the shell itself: no command line shows them)
        $setHash = 'global $wpdb; $h = getenv("APPS_ADMIN_HASH"); $u = get_user_by("login", getenv("APPS_ADMIN"));'
            . ' if (!$u || strpos($h, "\$wp\$2y\$") !== 0) { fwrite(STDERR, "The admin password could not be set.\n"); exit(1); }'
            . ' $wpdb->update($wpdb->users, array("user_pass" => $h), array("ID" => $u->ID)); clean_user_cache($u->ID); echo "The admin\'s password is the one you chose.\n";';
        $inner = 'IFS= read -r DBPASS; IFS= read -r APPS_ADMIN_HASH; export APPS_ADMIN_HASH; APPS_ADMIN=' . escapeshellarg((string) $a['ap_admin_vc']) . '; export APPS_ADMIN; '
            . $start . 'wp() { ' . $wp . ' "$@"; }; '
            . 'echo "== Unpacking WordPress ' . $version . ' (downloaded from wordpress.org, SHA-1 checked)"; tar -xzf ' . escapeshellarg($package) . ' --strip-components=1 --no-same-owner --no-same-permissions; '
            . 'echo "== Checking its files against wordpress.org"; wp core verify-checksums; '
            . 'echo "== wp-config.php"; printf "%s\n" "$DBPASS" | wp config create --dbname=' . escapeshellarg($login[0]) . ' --dbuser=' . escapeshellarg($login[1])
            . ' --dbhost=' . escapeshellarg(apps::DbHost()) . ' --dbcharset=utf8mb4 --prompt=dbpass --skip-check > /dev/null; unset DBPASS; chmod 640 wp-config.php; echo "Success: wp-config.php written."; '
            . 'echo "== Installing"; wp core install --url=' . escapeshellarg((string) $a['ap_url_vc']) . ' --title=' . escapeshellarg((string) $a['ap_title_vc'])
            . ' --admin_user=' . escapeshellarg((string) $a['ap_admin_vc']) . ' --admin_email=' . escapeshellarg((string) $a['ap_email_vc']) . ' --skip-email; '
            . 'wp eval ' . escapeshellarg($setHash) . '; '
            . 'wp plugin delete hello >/dev/null 2>&1 || true; '
            . 'echo "== Done: WordPress " $(wp core version)';
        return self::Launch($a, $home, 'set -e; ' . $inner, (string) $login[2] . "\n" . $hash . "\n", 'wp-admin/');
    }

    /**
     * OpenCart: unpacked from its checked release, installed with its own command-line installer
     * (its settings on the input, through a small wrapper: never on a command line), the owner's
     * password set (as its hash), and what OpenCart asks for after an install done: install/
     * removed, storage/ moved out of the website (to opencart-storage/app<id> in the account's
     * folder) and admin/ renamed to a name of its own.
     *
     * @param array $a
     * @param array $r Its recipe.
     * @param string $home
     * @param string $target
     * @param string $php
     * @param string $limit
     * @param string $start
     * @param array $login
     * @param string $hash
     * @return string
     */
    static function InstallOpenCart(array $a, array $r, $home, $target, $php, $limit, $start, array $login, $hash)
    {
        $package = self::OpenCartPackage((string) $r['version'], (string) $r['sha256']);
        if ($package === '') {
            return 'OpenCart ' . $r['version'] . ' could not be downloaded from GitHub (or its checksum did not match): try again later.';
        }
        $admin = 'admin' . bin2hex(random_bytes(3));
        $store = $home . '/opencart-storage/app' . (int) $a['ap_id_pk'];
        list($host, $port) = apps::DbHostPort();
        $settings = json_encode(array('hash' => $hash, 'title' => (string) $a['ap_title_vc'], 'args' => array(
            'username' => (string) $a['ap_admin_vc'], 'password' => bin2hex(random_bytes(8)), 'email' => (string) $a['ap_email_vc'],
            'http_server' => rtrim((string) $a['ap_url_vc'], '/') . '/', 'language' => 'en-gb', 'db_driver' => 'mysqli',
            'db_hostname' => $host, 'db_username' => $login[1], 'db_password' => $login[2], 'db_database' => $login[0], 'db_port' => $port, 'db_prefix' => 'oc_')));
        // OpenCart's installer reads its settings from the command line: the wrapper gives them from the
        // input instead, then sets the owner's password (its hash) in the new database
        $install = '$o = json_decode((string) fgets(STDIN), true); if (!is_array($o) || !isset($o["args"], $o["hash"])) { fwrite(STDERR, "No settings.\n"); exit(1); }'
            . ' $args = array("cli_install.php", "install"); foreach ($o["args"] as $k => $v) { $args[] = "--" . $k; $args[] = (string) $v; }'
            . ' $_SERVER["argv"] = $args; $_SERVER["argc"] = count($args);'
            . ' ob_start(); require "install/cli_install.php"; $out = ob_get_clean(); echo preg_replace("/^Admin link:.*\\R/m", "", $out);'
            . ' if (strpos($out, "SUCCESS!") === false) { exit(1); }'
            . ' $c = $o["args"]; $db = new mysqli($c["db_hostname"], $c["db_username"], $c["db_password"], $c["db_database"], (int) $c["db_port"]);'
            . ' $q = $db->prepare("UPDATE `oc_user` SET `password` = ? WHERE `user_id` = 1"); $q->bind_param("s", $o["hash"]); $q->execute();'
            . ' if ($q->affected_rows !== 1) { fwrite(STDERR, "The admin password could not be set.\n"); exit(1); }'
            . ' echo "The admin\'s password is the one you chose.\n";'
            // the store's name (OpenCart's installer takes none), escaped as OpenCart's own settings form keeps it
            . ' $t = htmlspecialchars(trim((string) $o["title"]), ENT_COMPAT, "UTF-8"); $q = $db->prepare("UPDATE `oc_setting` SET `value` = ? WHERE `store_id` = 0 AND `key` = \'config_name\'");'
            . ' $q->bind_param("s", $t); $q->execute(); echo "The store\'s name is set.\n";';
        // the addresses of storage/ and the renamed admin/ in both config.php files (as OpenCart's own
        // Security page writes them)
        $configs = '$store = $argv[1]; $admin = $argv[2]; $url = $argv[3];'
            . ' foreach (array("config.php" => false, $admin . "/config.php" => true) as $f => $isAdmin) {'
            . ' $out = ""; foreach (file($f) as $line) {'
            . ' if (strpos($line, "define(\'DIR_STORAGE\'") === 0) { $line = "define(\'DIR_STORAGE\', \'" . $store . "/\');\n"; }'
            . ' if ($isAdmin && strpos($line, "define(\'HTTP_SERVER\'") === 0) { $line = "define(\'HTTP_SERVER\', \'" . $url . $admin . "/\');\n"; }'
            . ' if ($isAdmin && strpos($line, "define(\'DIR_APPLICATION\'") === 0) { $line = "define(\'DIR_APPLICATION\', DIR_OPENCART . \'" . $admin . "/\');\n"; }'
            . ' $out .= $line; } file_put_contents($f, $out); } echo "Success: config.php written.\n";';
        $p = $php . ' -d memory_limit=256M' . $limit;
        $inner = 'IFS= read -r SETTINGS; ' . $start
            . 'if [ -e ' . escapeshellarg($store) . ' ]; then echo "' . $store . ' is there already."; exit 1; fi; '
            . 'echo "== Unpacking OpenCart ' . $r['version'] . ' (its release from GitHub, SHA-256 checked)"; tar -xzf ' . escapeshellarg($package) . ' --strip-components=1 --no-same-owner --no-same-permissions; '
            . 'cp config-dist.php config.php; cp admin/config-dist.php admin/config.php; '
            . 'echo "== Installing"; printf "%s\n" "$SETTINGS" | ' . $p . ' -r ' . escapeshellarg($install) . '; unset SETTINGS; '
            . 'echo "== Making it safe: install/ removed, storage/ moved out of the website, admin/ renamed"; rm -rf install; '
            . 'mkdir -p ' . escapeshellarg(dirname($store)) . '; mv system/storage ' . escapeshellarg($store) . '; mv admin ' . escapeshellarg($admin) . '; '
            . $p . ' -r ' . escapeshellarg($configs) . ' -- ' . escapeshellarg($store) . ' ' . escapeshellarg($admin) . ' ' . escapeshellarg(rtrim((string) $a['ap_url_vc'], '/') . '/') . '; '
            . $p . ' -l config.php > /dev/null; ' . $p . ' -l ' . escapeshellarg($admin . '/config.php') . ' > /dev/null; '
            . 'chmod 640 config.php ' . escapeshellarg($admin . '/config.php') . '; '
            . 'echo "== Done: OpenCart ' . $r['version'] . ', its admin at ' . $admin . '/"';
        return self::Launch($a, $home, 'set -e; ' . $inner, $settings . "\n", $admin . '/');
    }

    /**
     * Snipe-IT: its prepared package (source at the pinned commit, its libraries) unpacked into the
     * website's _app, where the website's PHP (limited to the website's folder) can read it; the
     * website's .htaccess serves only _app/public and refuses the rest (code, .env, data). Then .env, the key, the
     * database tables and OAuth keys with its own commands, and the first admin and its settings
     * made as its setup page makes them (that page is never open).
     *
     * @param array $a
     * @param array $r Its recipe.
     * @param string $home
     * @param string $target
     * @param string $php
     * @param string $limit
     * @param string $start
     * @param array $login
     * @param string $hash
     * @return string
     */
    static function InstallSnipeIt(array $a, array $r, $home, $target, $php, $limit, $start, array $login, $hash)
    {
        $package = self::SnipeItPackage($r);
        if ($package === self::WAIT) {
            return self::WAIT;
        }
        if ($package === '') {
            return 'Snipe-IT ' . $r['version'] . ' could not be prepared on this server: ' . self::BuildProblem($r);
        }
        // (in the website's _app: the website's PHP may only read the website's own folder)
        $app = $target . '/' . apps::APP_DIR;
        list($host, $port) = apps::DbHostPort();
        $url = rtrim((string) $a['ap_url_vc'], '/');
        $settings = json_encode(array(
            'env' => array('APP_URL' => $url, 'APP_TIMEZONE' => date_default_timezone_get(), 'DB_HOST' => $host, 'DB_PORT' => $port,
                'DB_DATABASE' => $login[0], 'DB_USERNAME' => $login[1], 'DB_PASSWORD' => $login[2], 'MAIL_MAILER' => 'sendmail',
                'MAIL_FROM_ADDR' => (string) $a['ap_email_vc'], 'MAIL_REPLYTO_ADDR' => (string) $a['ap_email_vc'], 'MAIL_FROM_NAME' => (string) $a['ap_title_vc'],
                'MAIL_REPLYTO_NAME' => (string) $a['ap_title_vc'], 'SECURE_COOKIES' => strpos($url, 'https://') === 0 ? 'true' : 'false'),
            'admin' => array('username' => (string) $a['ap_admin_vc'], 'email' => (string) $a['ap_email_vc'], 'hash' => $hash, 'site' => (string) $a['ap_title_vc'])));
        // .env from its example, each value quoted
        $env = '$o = json_decode((string) fgets(STDIN), true); if (!is_array($o) || !isset($o["env"])) { fwrite(STDERR, "No settings.\n"); exit(1); }'
            . ' $env = (string) file_get_contents(".env.example");'
            . ' foreach ($o["env"] as $k => $v) { $line = $k . "=\"" . addcslashes((string) $v, "\\\\\"\$") . "\""; $n = 0;'
            . ' $env = preg_replace("/^" . preg_quote($k, "/") . "=.*\$/m", str_replace(array("\\\\", "\$"), array("\\\\\\\\", "\\\\\$"), $line), $env, 1, $n);'
            . ' if ($n === 0) { $env .= "\n" . $line . "\n"; } }'
            . ' file_put_contents(".env", $env); chmod(".env", 0640); echo "Success: .env written.\n";';
        // the first admin and the settings, as Snipe-IT's setup page makes them (with the password's hash)
        $admin = '$o = json_decode((string) fgets(STDIN), true); $o = $o["admin"];'
            . ' require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            . ' if (App\Models\Setting::setupCompleted()) { fwrite(STDERR, "Its setup was done already.\n"); exit(1); }'
            . ' $u = new App\Models\User; $u->first_name = $o["username"]; $u->email = $o["email"]; $u->username = $o["username"];'
            . ' $u->activated = 1; $u->permissions = json_encode(array("superuser" => 1)); $u->password = $o["hash"];'
            . ' $s = new App\Models\Setting; $s->full_multiple_companies_support = 0; $s->site_name = $o["site"]; $s->alert_email = $o["email"]; $s->alerts_enabled = 1;'
            . ' $s->pwd_secure_min = 10; $s->brand = 1; $s->link_light_color = "#296282"; $s->link_dark_color = "#296282"; $s->nav_link_color = "#FFFFFF";'
            . ' $s->locale = "en-US"; $s->default_currency = "USD"; $s->created_by = 1; $s->next_auto_tag_base = 1; $s->auto_increment_assets = 0; $s->zerofill_count = 0;'
            . ' if (!$u->isValid() || !$s->isValid()) { fwrite(STDERR, "Not valid: " . json_encode(array($u->getErrors(), $s->getErrors())) . "\n"); exit(1); }'
            . ' $u->save(); $s->save(); echo "The admin\'s password is the one you chose; its setup is done.\n";';
        // what Laravel and Snipe-IT need of the site's PHP
        $exts = 'ctype curl exif fileinfo gd iconv mbstring openssl pdo_mysql tokenizer xml';
        $check = '$miss = array(); foreach (explode(" ", "' . $exts . '") as $e) { if (!extension_loaded($e)) { $miss[] = $e; } }'
            . ' if ($miss) { fwrite(STDERR, "The site\'s PHP lacks: " . implode(", ", $miss) . " (PHP Versions, or ask the administrator).\n"); exit(1); } echo "PHP " . PHP_VERSION . " has what Snipe-IT needs.\n";';
        $p = $php . ' -d memory_limit=512M' . $limit;
        $inner = 'IFS= read -r SETTINGS; ' . $start
            . 'if [ -e ' . escapeshellarg($app) . ' ]; then echo "' . $app . ' is there already."; exit 1; fi; '
            . $p . ' -r ' . escapeshellarg($check) . '; '
            . 'echo "== Unpacking Snipe-IT ' . $r['version'] . ' (its source at the checked commit, with its libraries)"; mkdir -p ' . escapeshellarg($app) . '; '
            . 'tar -xzf ' . escapeshellarg($package) . ' -C ' . escapeshellarg($app) . ' --strip-components=1 --no-same-owner --no-same-permissions; '
            . $p . ' -r ' . escapeshellarg(self::RulesCode()) . ' -- ' . escapeshellarg($target) . ' ' . escapeshellarg(self::UrlPath($a)) . ' public; cd ' . escapeshellarg($app) . '; '
            . 'echo "== .env"; printf "%s\n" "$SETTINGS" | ' . $p . ' -r ' . escapeshellarg($env) . '; '
            . 'echo "== Its key, its database tables, its API keys"; ' . $p . ' artisan key:generate --force --no-interaction > /dev/null; '
            . $p . ' artisan package:discover --no-interaction > /dev/null; ' . $p . ' artisan migrate --force --no-interaction > /dev/null; echo "Success: the database tables are made."; '
            . $p . ' artisan passport:install --no-interaction > /dev/null; echo "Success: its OAuth keys are made."; '
            . 'echo "== Its admin"; printf "%s\n" "$SETTINGS" | ' . $p . ' -r ' . escapeshellarg($admin) . '; unset SETTINGS; '
            . 'echo "== Its scheduler"; ' . $p . ' -r ' . escapeshellarg('if (file_put_contents($argv[1], $argv[2]) === false) { exit(1); } echo "Success: snipeit-scheduler.php written (Cron Manager runs it every minute).\n";')
            . ' -- ' . escapeshellarg($target . '/' . self::SCHEDULER) . ' ' . escapeshellarg(self::SchedulerScript($app)) . '; '
            . 'echo "== Done: Snipe-IT ' . $r['version'] . ' (its code in ' . apps::APP_DIR . '/, out of the web\'s reach: only its public/ is served)"';
        return self::Launch($a, $home, 'set -e; ' . $inner, $settings . "\n");
    }

    /**
     * PHP (run as the account's user: php -r CODE -- <website folder> <its address path> <web folder>)
     * that writes the app's rules into the website folder's .htaccess, in a marked part of their own
     * after QuickFig's parts (its crawler and hardening rules apply first): only _app/<web folder>
     * is served, every request goes to it, names starting with a dot are refused, and the rest of
     * _app (code, .env, libraries, data) never. The website's PHP is limited to the website's folder,
     * which is why the code is in it, in _app, and not beside it.
     *
     * @return string
     */
    static function RulesCode()
    {
        return '$t = $argv[1]; $sub = $argv[2]; $web = $argv[3];'
            . ' if (!preg_match("#^[A-Za-z0-9._/-]*$#", $sub . $web)) { fwrite(STDERR, "Not a folder.\n"); exit(1); }'
            . ' $w = "' . apps::APP_DIR . '" . ($web !== "" ? "/" . $web : ""); $q = function ($s) { return str_replace(".", "\\\\.", $s); };'
            . ' $pre = "^/" . ($sub !== "" ? $q($sub) . "/" : "");'
            . ' $b = "# BEGIN Apps (only " . $w . "/ is served: the app\'s code, settings and data in ' . apps::APP_DIR . '/ are not)\n"'
            . ' . "<If \"%{REQUEST_URI} =~ m#" . $pre . "' . apps::APP_DIR . '(/|$)# && (%{REQUEST_URI} !~ m#" . $pre . $q($w) . "(/|$)# || %{REQUEST_URI} =~ m#/\\\\.#)\">\nRequire all denied\n</If>\n"'
            . ' . "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule (^|/)\\\\.(?!well-known(/|$)) - [F,L]\nRewriteRule ^" . $q($w) . "(/|$) - [L]\n"'
            // (a file of the website's own folder is served as it is: QuickFig's robots.txt, say)
            . ' . "RewriteRule ^(\\\\.well-known|_errorpages)/ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(.*)$ " . $w . "/$1 [L]\n</IfModule>\n# END Apps\n";'
            . ' $f = $t . "/.htaccess"; if (is_link($f)) { fwrite(STDERR, ".htaccess is a link.\n"); exit(1); }'
            . ' $old = is_file($f) ? (string) file_get_contents($f) : ""; $old = preg_replace("/# BEGIN Apps .*?# END Apps\\\\n?/s", "", $old);'
            // after QuickFig's parts, which must come first
            . ' $at = 0; if (preg_match_all("/^# END QuickFig[^\\\\n]*\\\\n/m", $old, $mm, PREG_OFFSET_CAPTURE)) { $last = end($mm[0]); $at = $last[1] + strlen($last[0]); }'
            . ' $new = rtrim(substr($old, 0, $at)) . ($at ? "\n\n" : "") . $b . (trim(substr($old, $at)) !== "" ? "\n" . ltrim(substr($old, $at)) : "");'
            . ' if (file_put_contents($f, $new) === false) { exit(1); } chmod($f, 0644); echo "Success: only " . $w . "/ is served (.htaccess).\n";';
    }

    /**
     * The address path of an app's folder inside its website (for its rules): the folder given at
     * the install ('' for the website itself).
     *
     * @param array $a
     * @return string
     */
    static function UrlPath(array $a)
    {
        return trim((string) $a['ap_sub_vc'], '/');
    }

    /**
     * Snipe-IT installed before Apps 105: its code in snipeit/app<id> in the account's folder and its
     * public files in the website, which the website's PHP (limited to its own folder) can not join
     * up. Moved, as the account's own user: the code into the website's _app, its public files
     * (uploads too) into _app/public, index.php as Snipe-IT ships it, the rules, the scheduler.
     *
     * @param array $a The x_apps row.
     * @return string '' when started, else why not.
     */
    static function StartMove(array $a)
    {
        $w = self::Where($a);
        if (!is_array($w)) {
            return $w;
        }
        list($site, $home) = $w;
        $c = apps::Run((int) $a['ap_acc_fk'], $site, 'check', array('sub' => (string) $a['ap_sub_vc']), true);
        if (isset($c['error']) || empty($c['exists'])) {
            return isset($c['error']) ? (string) $c['error'] : 'The website\'s folder is gone.';
        }
        $target = $home . '/' . (string) $c['rel'];
        $old = $home . '/snipeit/app' . (int) $a['ap_id_pk'];
        $app = $target . '/' . apps::APP_DIR;
        $php = escapeshellarg(self::Php(apps::SitePhp($site))) . ' -d open_basedir=' . escapeshellarg($home . '/');
        $q = function ($p) {
            return escapeshellarg($p);
        };
        $unpatch = 'file_put_contents($argv[1], str_replace("\'" . $argv[2] . "/", "__DIR__.\'/../", (string) file_get_contents($argv[1]))); echo "Success: index.php is as Snipe-IT ships it.\n";';
        $inner = 'set -e; umask 022; cd ' . $q($target) . '; '
            . 'if [ ! -d ' . $q($old) . ' ]; then if [ -d ' . $q($app) . ' ]; then echo "Its code is in the website already."; exit 0; fi; echo "Its code is not in ' . $old . ' any more."; exit 1; fi; '
            . 'if [ -e ' . $q($app) . ' ]; then echo "' . $app . ' is there already."; exit 1; fi; '
            . 'echo "== Moving Snipe-IT\'s code into the website (' . apps::APP_DIR . '/): the website\'s PHP may only read the website\'s own folder"; '
            . 'mv ' . $q($old) . ' ' . $q($app) . '; if [ -L ' . $q($app . '/public') . ' ]; then rm ' . $q($app . '/public') . '; fi; mkdir -p ' . $q($app . '/public') . '; '
            . 'for f in * .[!.]* ..?*; do case "$f" in ' . apps::APP_DIR . '|_errorpages|.well-known|' . self::SCHEDULER . '|"*"|".[!.]*"|"..?*") continue;; esac; [ -e "$f" ] || [ -L "$f" ] || continue; mv "$f" ' . $q($app . '/public/') . '; done; '
            . $php . ' -r ' . $q($unpatch) . ' -- ' . $q($app . '/public/index.php') . ' ' . $q($old) . '; '
            . $php . ' -r ' . $q(self::RulesCode()) . ' -- ' . $q($target) . ' ' . $q(self::UrlPath($a)) . ' public; '
            . $php . ' -r ' . $q('if (file_put_contents($argv[1], $argv[2]) === false) { exit(1); } echo "Success: the scheduler runs the moved code.\n";') . ' -- ' . $q($target . '/' . self::SCHEDULER) . ' ' . $q(self::SchedulerScript($app)) . '; '
            . 'cd ' . $q($app) . '; ' . $php . ' artisan optimize:clear --no-interaction > /dev/null 2>&1 || true; '
            . 'rmdir ' . $q($home . '/snipeit') . ' 2>/dev/null || true; echo "== Done: Snipe-IT runs from ' . $app . ' (only its public/ is served)"';
        return self::Launch($a, $home, $inner, '');
    }

    /**
     * The Git command and its environment for an app (as the account's user): only https and SSH,
     * no hooks, no stored passwords, never a question; for an SSH address the app's own deploy key
     * and known hosts (first seen, then kept).
     *
     * @param array $a
     * @param string $home
     * @return string Shell lines that set G (the git command) and the environment.
     */
    static function GitEnv(array $a, $home)
    {
        $q = function ($p) {
            return escapeshellarg($p);
        };
        $keys = self::GitKeyDir($a, $home);
        $env = 'export GIT_TERMINAL_PROMPT=0 GIT_ASKPASS=/bin/false SSH_ASKPASS=/bin/false GIT_CONFIG_NOSYSTEM=1; '
            . 'G="git -c protocol.allow=never -c protocol.https.allow=always -c protocol.ssh.allow=always -c core.hooksPath=/dev/null -c credential.helper= -c submodule.recurse=false -c advice.detachedHead=false"; ';
        if (apps::GitSsh((string) $a['ap_repo_vc'])) {
            $env .= 'export GIT_SSH_COMMAND=' . $q('ssh -F /dev/null -i ' . $keys . '/id_ed25519 -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . $keys . '/known_hosts -o ConnectTimeout=20') . '; ';
        }
        return $env;
    }

    /**
     * Where an app's Git data (its .git, outside the website) and its deploy key are kept.
     *
     * @param array $a
     * @param string $home
     * @return string
     */
    static function GitDir(array $a, $home)
    {
        return rtrim($home, '/') . '/git/app' . (int) $a['ap_id_pk'] . '.git';
    }

    /**
     * @param array $a
     * @param string $home
     * @return string
     */
    static function GitKeyDir(array $a, $home)
    {
        return rtrim($home, '/') . '/git/app' . (int) $a['ap_id_pk'] . '.key';
    }

    /**
     * The options of a Git app (composer, migrate, db, ssh).
     *
     * @param array $a
     * @return array<string, bool>
     */
    static function Opts(array $a)
    {
        return array_fill_keys(array_filter(explode(',', (string) $a['ap_opts_vc'])), true);
    }

    /**
     * A deploy key for a private repository, made as the account's own user (its private half stays
     * in git/app<id>.key in the account's folder, outside the website); the page shows its public half
     * to be added to the repository.
     *
     * @param array $a
     * @return string
     */
    static function StartKey(array $a)
    {
        $w = self::Where($a);
        if (!is_array($w)) {
            return $w;
        }
        $home = $w[1];
        $keys = self::GitKeyDir($a, $home);
        $q = function ($p) {
            return escapeshellarg($p);
        };
        $inner = 'set -e; umask 077; mkdir -p ' . $q(dirname($keys)) . ' ' . $q($keys) . '; chmod 700 ' . $q($keys) . '; '
            . 'if [ ! -f ' . $q($keys . '/id_ed25519') . ' ]; then ssh-keygen -q -t ed25519 -N "" -C ' . $q('sentora-apps-' . (int) $a['ap_id_pk']) . ' -f ' . $q($keys . '/id_ed25519') . '; fi; '
            . 'echo "APPS-PUBKEY: $(cat ' . $q($keys . '/id_ed25519.pub') . ')"; echo "A deploy key is made: add it to the repository (read access), then start the install."';
        return self::Launch($a, $home, $inner, '');
    }

    /**
     * The lines that install the app's libraries (Composer, when it has a composer.json) and run a
     * Laravel app's own steps (its key, its database tables if asked, the storage link), in _app.
     *
     * @param array $a
     * @param string $home
     * @param string $php The site's PHP with its limits.
     * @param bool $first The install (not a deploy): the key and the storage link.
     * @return string
     */
    static function GitBuild(array $a, $home, $php, $first)
    {
        $o = self::Opts($a);
        $q = function ($p) {
            return escapeshellarg($p);
        };
        $s = '';
        if (!empty($o['composer'])) {
            $s .= 'if [ -f composer.json ]; then echo "== Its libraries (Composer)"; COMPOSER_HOME=' . $q($home . '/tmp/.composer') . ' COMPOSER_NO_INTERACTION=1 '
                . $php . ' -d memory_limit=1536M ' . $q(self::COMPOSER) . ' install --no-dev --no-interaction --no-progress --optimize-autoloader || exit 1; fi; ';
        }
        $s .= 'if [ -f artisan ] && [ -f vendor/autoload.php ]; then :; '
            // (set -e does not stop a deploy's build, which runs where its result is tested: each step says so itself)
            . ($first ? 'if [ -f .env ] && grep -q "^APP_KEY=\s*$" .env; then ' . $php . ' artisan key:generate --force --no-interaction || exit 1; fi; ' : '')
            . (!empty($o['migrate']) ? 'echo "== Its database tables (artisan migrate)"; ' . $php . ' artisan migrate --force --no-interaction || exit 1; ' : '')
            . ($first ? 'if [ -d public ] && [ ! -e public/storage ]; then ' . $php . ' artisan storage:link --no-interaction > /dev/null 2>&1 && echo "Success: public/storage linked." || true; fi; ' : '')
            . 'fi; ';
        return $s;
    }

    /**
     * A Git app: cloned (its .git kept outside the website, in git/app<id>.git), its web folder
     * found (public, web, dist...) unless given, .env made from .env.example (with its database, if
     * it has one), its libraries and Laravel's steps, then the rules that serve only its web folder.
     *
     * @param array $a
     * @param array $site
     * @param string $home
     * @param string $target
     * @param string $php
     * @param string $limit
     * @param string $start
     * @param array|null $login The database, when the app has one.
     * @return string
     */
    static function InstallGit(array $a, array $site, $home, $target, $php, $limit, $start, $login)
    {
        $url = (string) $a['ap_repo_vc'];
        $why = apps::GitUrlProblem($url);
        if ($why !== '') {
            return $why;
        }
        $branch = (string) $a['ap_branch_vc'];
        $web = (string) $a['ap_web_vc'];
        if (!apps::ValidBranch($branch) || !apps::ValidWeb($web)) {
            return 'The branch or web folder is not valid.';
        }
        $o = self::Opts($a);
        if (!empty($o['composer']) && !self::GetComposer()) {
            return 'Composer is not on this server yet: try again in an hour.';
        }
        $q = function ($p) {
            return escapeshellarg($p);
        };
        $app = $target . '/' . apps::APP_DIR;
        $gitdir = self::GitDir($a, $home);
        $p = $php . $limit;
        $settings = json_encode(array('env' => $login ? array('DB_HOST' => apps::DbHostPort()[0], 'DB_PORT' => apps::DbHostPort()[1], 'DB_DATABASE' => $login[0],
            'DB_USERNAME' => $login[1], 'DB_PASSWORD' => $login[2], 'DATABASE_URL' => 'mysql://' . rawurlencode($login[1]) . ':' . rawurlencode($login[2]) . '@' . apps::DbHostPort()[0] . ':' . apps::DbHostPort()[1] . '/' . $login[0]) : array(),
            'url' => (string) $a['ap_url_vc']));
        // .env: from .env.example when it has none; the database's settings and the address put in
        // for the names it uses (Laravel's DB_*, Symfony's DATABASE_URL, APP_URL)
        $env = '$o = json_decode((string) fgets(STDIN), true); if (!is_array($o)) { exit(1); }'
            . ' if (!is_file(".env")) { if (!is_file(".env.example")) { echo "(no .env.example: no .env made)\n"; exit(0); } copy(".env.example", ".env"); echo "Success: .env made from .env.example.\n"; }'
            . ' chmod(".env", 0640); $e = (string) file_get_contents(".env"); $set = $o["env"]; $set["APP_URL"] = $o["url"]; $n = 0;'
            // a database given: Laravel 11's .env.example uses SQLite, its MySQL lines commented out
            . ' if ($set && isset($set["DB_HOST"])) { $e = preg_replace("/^#\\s*(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=/m", "\\\\1=", $e); if (preg_match("/^DB_CONNECTION=/m", $e)) { $set = array("DB_CONNECTION" => "mysql") + $set; } }'
            . ' foreach ($set as $k => $v) { if (!preg_match("/^" . preg_quote($k, "/") . "=/m", $e)) { continue; }'
            . ' $line = $k . "=\"" . addcslashes((string) $v, "\\\\\"\$") . "\""; $e = preg_replace("/^" . preg_quote($k, "/") . "=.*$/m", str_replace(array("\\\\", "\$"), array("\\\\\\\\", "\\\\\$"), $line), $e, 1); $n++; }'
            . ' file_put_contents(".env", $e); if ($n) { echo "Success: " . $n . " setting(s) put in .env (database, address).\n"; }';
        $detect = 'W=' . $q($web) . '; if [ "$W" = "*" ]; then W=""; for d in public public_html web htdocs www dist build; do if [ -f "$d/index.php" ] || [ -f "$d/index.html" ]; then W="$d"; break; fi; done; fi; '
            . 'if [ -n "$W" ] && [ ! -d "$W" ]; then echo "The web folder $W is not in the repository."; exit 1; fi; echo "APPS-WEB: $W"; '
            . 'echo "Served: ' . apps::APP_DIR . '/$W (the rest of the repository is not)"; ';
        $inner = 'IFS= read -r SETTINGS; ' . $start . self::GitEnv($a, $home)
            . 'if [ -e ' . $q($gitdir) . ' ]; then echo "' . $gitdir . ' is there already."; exit 1; fi; mkdir -p ' . $q(dirname($gitdir)) . '; '
            . 'echo "== Cloning ' . $url . ($branch !== '' ? ' (' . $branch . ')' : '') . '"; '
            . '$G clone -q --depth 50 --no-recurse-submodules --separate-git-dir=' . $q($gitdir) . ($branch !== '' ? ' --branch ' . $q($branch) . ' --single-branch' : '') . ' -- ' . $q($url) . ' ' . $q(apps::APP_DIR) . '; '
            . 'cd ' . $q($app) . '; echo "APPS-BRANCH: $(git symbolic-ref --short -q HEAD || echo ' . $q($branch) . ')"; echo "$(du -sm . | cut -f1) MB, commit $(git log -1 --format=%h)"; '
            . $detect
            . 'echo "== .env"; printf "%s\n" "$SETTINGS" | ' . $p . ' -r ' . $q($env) . '; unset SETTINGS; '
            . self::GitBuild($a, $home, $p, true)
            . 'cd ' . $q($target) . '; ' . $p . ' -r ' . $q(self::RulesCode()) . ' -- ' . $q($target) . ' ' . $q(self::UrlPath($a)) . ' "$W"; '
            . 'echo "APPS-COMMIT: $(git -C ' . $q($app) . ' log -1 --format=\'%H %s\')"; echo "== Done"';
        return self::Launch($a, $home, 'set -e; ' . $inner, $settings . "\n", '');
    }

    /**
     * Deploy a Git app again (its branch's newest commit), or roll back to the commit before: the
     * code reset to it (.env, libraries and uploads are no part of the repository and stay), then
     * its libraries and Laravel's steps; a failed deploy goes back to where it was.
     *
     * @param array $a
     * @param bool $back Roll back to ap_prev_vc.
     * @return string
     */
    static function StartDeploy(array $a, $back)
    {
        if ($a['ap_app_vc'] !== 'git') {
            return 'Not a Git app.';
        }
        $w = self::Where($a);
        if (!is_array($w)) {
            return $w;
        }
        list($site, $home) = $w;
        $c = apps::Run((int) $a['ap_acc_fk'], $site, 'check', array('sub' => (string) $a['ap_sub_vc']), true);
        if (isset($c['error']) || empty($c['exists'])) {
            return isset($c['error']) ? (string) $c['error'] : 'The website\'s folder is gone.';
        }
        $target = $home . '/' . (string) $c['rel'];
        $app = $target . '/' . apps::APP_DIR;
        $branch = (string) $a['ap_branch_vc'];
        $prev = (string) $a['ap_prev_vc'];
        if (!apps::ValidBranch($branch) || $branch === '' || ($back && !preg_match('/^[0-9a-f]{40}$/', $prev))) {
            return $back ? 'There is no earlier deploy to go back to.' : 'The branch is not known.';
        }
        if (!$back && apps::GitUrlProblem((string) $a['ap_repo_vc']) !== '') {
            return apps::GitUrlProblem((string) $a['ap_repo_vc']);
        }
        if (version_compare(apps::SitePhp($site), '7.4', '<')) {
            return 'The site\'s PHP is too old.';
        }
        $q = function ($p) {
            return escapeshellarg($p);
        };
        $p = escapeshellarg(self::Php(apps::SitePhp($site))) . ' -d open_basedir=' . escapeshellarg($home . '/:' . apps::DIR . '/') . ' -d sys_temp_dir=' . escapeshellarg($home . '/tmp');
        $build = self::GitBuild($a, $home, $p, false);
        $to = $back ? $q($prev) : 'FETCH_HEAD';
        $inner = 'set -e; umask 022; cd ' . $q($app) . '; ' . self::GitEnv($a, $home)
            . 'PREV=$(git rev-parse HEAD); echo "APPS-PREV: $PREV"; '
            . ($back ? 'echo "== Back to ' . substr($prev, 0, 7) . '"; ' : 'echo "== Fetching ' . $branch . '"; $G fetch -q --depth 50 --no-recurse-submodules origin ' . $q($branch) . '; ')
            . 'NEW=$(git rev-parse ' . $to . '); if [ "$NEW" = "$PREV" ]; then echo "Already at $(git log -1 --format=\'%h %s\')."; fi; '
            . '$G reset -q --hard ' . $to . '; echo "Now at $(git log -1 --format=\'%h %s\')"; '
            . 'if ! ( set -e; ' . $build . ' ); then echo "== It did not work: back to ${PREV%${PREV#???????}}"; $G reset -q --hard "$PREV"; ( ' . $build . ' ) > /dev/null 2>&1 || true; '
            . 'echo "APPS-COMMIT: $(git log -1 --format=\'%H %s\')"; exit 1; fi; '
            . 'echo "APPS-COMMIT: $(git log -1 --format=\'%H %s\')"; echo "== Done"';
        return self::Launch($a, $home, $inner, '');
    }

    /**
     * Start a removal: the app's files, as the account's own user (the page already dropped its
     * database). An app in the site's own folder leaves the folder (and its error pages) with the
     * panel's welcome page again; one in a folder of its own takes that folder away.
     *
     * @param array $a The x_apps row.
     * @return string '' when started, else why not.
     */
    static function StartRemove(array $a)
    {
        $w = self::Where($a);
        if (!is_array($w)) {
            return $w;
        }
        list($site, $home) = $w;
        // a Git app's .git and deploy key (in git/ in the account's folder, outside the website)
        $gitData = $a['ap_app_vc'] === 'git' ? 'rm -rf ' . escapeshellarg(self::GitDir($a, $home)) . ' ' . escapeshellarg(self::GitKeyDir($a, $home))
            . '; rmdir ' . escapeshellarg($home . '/git') . ' 2>/dev/null; ' : '';
        $c = apps::Run((int) $a['ap_acc_fk'], $site, 'check', array('sub' => (string) $a['ap_sub_vc']), true);
        if (isset($c['error'])) {
            // (a folder that became a link, say: nothing in it is touched; the database is gone already)
            return self::Launch($a, $home, $gitData . 'echo ' . escapeshellarg('The files were left alone: ' . $c['error']), '');
        }
        if (empty($c['exists'])) {
            return self::Launch($a, $home, $gitData . 'echo "The folder was already gone."', '');
        }
        $target = $home . '/' . (string) $c['rel'];
        $welcome = rtrim((string) ctrl_options::GetSystemOption('static_dir'), '/') . '/pages/welcome.html';
        // OpenCart's storage/ (moved out of the website at the install), Snipe-IT's own folder
        $extra = '';
        foreach (array('opencart' => 'opencart-storage', 'snipeit' => 'snipeit') as $k => $folder) {
            if ($a['ap_app_vc'] === $k && ($k !== 'snipeit' || (int) $a['ap_layout_in'] !== 1)) {
                $extra = 'rm -rf ' . escapeshellarg($home . '/' . $folder . '/app' . (int) $a['ap_id_pk'])
                    . ' && { rmdir ' . escapeshellarg($home . '/' . $folder) . ' 2>/dev/null || true; } && ';
            }
        }
        if ($a['ap_app_vc'] === 'git') {
            $extra = 'rm -rf ' . escapeshellarg(self::GitDir($a, $home)) . ' ' . escapeshellarg(self::GitKeyDir($a, $home))
                . ' && { rmdir ' . escapeshellarg($home . '/git') . ' 2>/dev/null || true; } && ';
        }
        if ((string) $a['ap_sub_vc'] === '') {
            $inner = $extra . 'cd ' . escapeshellarg($target) . ' && find . -mindepth 1 -maxdepth 1 ! -name _errorpages ! -name .well-known -exec rm -rf {} + && '
                . '{ cp ' . escapeshellarg($welcome) . ' index.html 2>/dev/null || true; } && echo "The app\'s files are removed; the site shows the welcome page."';
        } else {
            $inner = $extra . 'rm -rf ' . escapeshellarg($target) . ' && echo "The app\'s folder is removed."';
        }
        return self::Launch($a, $home, $inner, '');
    }

    /**
     * Start a task in the background, as the account's own user, its input from a file only root
     * can read and its output kept in root's folder.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $a The x_apps row.
     * @param string $home
     * @param string $inner The commands (escaped), run by sh as the account's user.
     * @param string $input What it gets on its input.
     * @param string|null $loginPath The app's admin address (inside its own), when it has one.
     * @return string ''
     */
    static function Launch(array $a, $home, $inner, $input, $loginPath = null)
    {
        global $zdbh;
        $id = (int) $a['ap_id_pk'];
        $jobs = apps::DIR . '/jobs';
        $in = $jobs . '/' . $id . '.in';
        @unlink($in);
        $f = fopen($in, 'x');
        if ($f === false) {
            return 'The task could not be prepared.';
        }
        chmod($in, 0600);
        fwrite($f, $input);
        fclose($f);
        $q = function ($p) {
            return escapeshellarg($p);
        };
        $running = self::RUNNING[$a['ap_state_vc']] ?? 'installing';
        $full = '{ ' . self::AsUser((int) $a['ap_acc_fk'], $home, $inner, in_array($running, array('installing', 'deploying', 'rollingback'), true) && $a['ap_app_vc'] === 'git' ? self::GIT_SECONDS : self::SECONDS) . '; } < ' . $q($in) . ' > ' . $q($jobs . '/' . $id . '.out') . ' 2>&1; rc=$?; rm -f ' . $q($in)
            . '; echo $rc > ' . $q($jobs . '/' . $id . '.rc.new') . ' && mv ' . $q($jobs . '/' . $id . '.rc.new') . ' ' . $q($jobs . '/' . $id . '.rc');
        $zdbh->prepare("UPDATE x_apps SET ap_state_vc = :s, ap_started_in = :t" . ($loginPath !== null ? ", ap_login_vc = :l" : "") . " WHERE ap_id_pk = :id")
            ->execute(array(':s' => $running, ':t' => time(), ':id' => $id) + ($loginPath !== null ? array(':l' => $loginPath) : array()));
        exec('setsid nohup sh -c ' . escapeshellarg($full) . ' > /dev/null 2>&1 < /dev/null &');
        return '';
    }

    /**
     * A command line run as the account's own user: no other groups, a clean environment, low
     * priority, for a limited time.
     *
     * @param int $uid
     * @param string $home
     * @param string $inner The command (escaped), run by sh as that user.
     * @return string
     */
    static function AsUser($uid, $home, $inner, $seconds = self::SECONDS)
    {
        $user = escapeshellarg(sys_accountphp::UserName((int) $uid));
        $tmp = rtrim($home, '/') . '/tmp';
        return 'setpriv --reuid=' . $user . ' --regid=' . $user . ' --clear-groups --reset-env env -i PATH=/usr/local/bin:/usr/bin:/bin LANG=C.UTF-8'
            . ' HOME=' . escapeshellarg($tmp) . ' WP_CLI_CACHE_DIR=' . escapeshellarg($tmp . '/.wp-cli-cache')
            . ' timeout -k 30 ' . (int) $seconds . ' nice -n 10 ionice -c 3 sh -c ' . escapeshellarg($inner);
    }

    /**
     * The PHP binary of a version (the server's own for its default).
     *
     * @param string $version
     * @return string
     */
    static function Php($version)
    {
        if ($version !== '' && $version !== (string) sys_phpversions::DefaultVersion() && preg_match('/^\d+\.\d+$/', $version)
            && sys_phpversions::IsEnabled($version) && is_executable(sys_phpversions::BASE_DIR . $version . '/bin/php')) {
            return sys_phpversions::BASE_DIR . $version . '/bin/php';
        }
        // the system's own PHP: its command as this system's installer set it (php_cli: /usr/bin/php8.3 on
        // Debian/Ubuntu, /usr/bin/php on AlmaLinux/Rocky/RHEL/Fedora); panels from before that setting: the
        // Debian/Ubuntu name, else plain php
        $v = (string) sys_phpversions::DefaultVersion();
        if (class_exists('sys_platform') && method_exists('sys_platform', 'Get')) {
            $cli = (string) sys_platform::Get('php_cli', array('v' => $v));
            if ($cli !== '' && is_executable($cli)) {
                return $cli;
            }
        }
        return is_executable('/usr/bin/php' . $v) ? '/usr/bin/php' . $v : '/usr/bin/php';
    }

    /**
     * WP-CLI on the server: downloaded with its published SHA-512 checked, kept in root's folder.
     *
     * @return string What happened ('' when it is there already).
     */
    static function GetWpCli()
    {
        if (is_file(self::WPCLI) && filemtime(self::WPCLI) > time() - 30 * 86400) {
            return '';
        }
        if (!is_dir(apps::DIR)) {
            @mkdir(apps::DIR, 0755, true);
        }
        $base = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
        $ctx = stream_context_create(array('http' => array('timeout' => 60, 'user_agent' => 'Sentora-Apps')));
        $phar = @file_get_contents($base, false, $ctx);
        $sum = trim((string) @file_get_contents($base . '.sha512', false, $ctx));
        if (!is_string($phar) || strlen($phar) < 100000 || !preg_match('/^[0-9a-f]{128}/', $sum) || !hash_equals(substr($sum, 0, 128), hash('sha512', $phar))) {
            return 'WP-CLI could not be downloaded (or its checksum did not match): Apps tries again in an hour.';
        }
        file_put_contents(self::WPCLI . '.new', $phar);
        chmod(self::WPCLI . '.new', 0644);
        rename(self::WPCLI . '.new', self::WPCLI);
        return 'WP-CLI downloaded (checksum checked).';
    }

    /**
     * WordPress's package (en_US) of a version, in root's folder: downloaded once per server and
     * checked against the SHA-1 wordpress.org publishes for it (every file is checked again after
     * the install, with WP-CLI's verify-checksums). Readable by the accounts, who unpack it.
     * (WP-CLI's own core download unpacks with PHP's tar reader, which cuts the names of files
     * longer than 100 characters, and WordPress 7.1 has such files.)
     *
     * @param string $version Such as 7.1.4.
     * @return string Its path, or '' when it could not be had.
     */
    static function WordPressPackage($version)
    {
        if (!preg_match('/^[0-9]+(\.[0-9]+){1,3}$/', $version)) {
            return '';
        }
        $dir = apps::DIR . '/packages';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || is_link($dir)) {
            return '';
        }
        $file = $dir . '/wordpress-' . $version . '.tar.gz';
        if (is_file($file) && !is_link($file)) {
            return $file;
        }
        $url = 'https://downloads.wordpress.org/release/wordpress-' . $version . '.tar.gz';
        $ctx = stream_context_create(array('http' => array('timeout' => 120, 'user_agent' => 'Sentora-Apps')));
        $sum = trim((string) @file_get_contents($url . '.sha1', false, $ctx));
        $data = @file_get_contents($url, false, $ctx);
        if (!preg_match('/^[0-9a-f]{40}$/', $sum) || !is_string($data) || strlen($data) < 1000000 || !hash_equals($sum, sha1($data))) {
            return '';
        }
        file_put_contents($file . '.new', $data);
        chmod($file . '.new', 0644);
        rename($file . '.new', $file);
        // older versions go (the newest 2 stay)
        $all = glob($dir . '/wordpress-*.tar.gz');
        usort($all, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        foreach (array_slice($all, 2) as $old) {
            @unlink($old);
        }
        return $file;
    }

    /**
     * OpenCart's package of a version, in root's folder: its release zip downloaded from GitHub
     * once per server and checked against the SHA-256 the recipe pins, unpacked by root (the
     * panel's PHP has zip) and its upload/ folder packed again as a .tar.gz the accounts unpack
     * with tar (unzip is not on every server).
     *
     * @param string $version Such as 4.1.0.4.
     * @param string $sha256 The release's SHA-256 (the recipe's).
     * @return string Its path, or '' when it could not be had.
     */
    static function OpenCartPackage($version, $sha256)
    {
        if (!preg_match('/^[0-9]+(\.[0-9]+){1,3}$/', $version) || !preg_match('/^[0-9a-f]{64}$/', $sha256) || !class_exists('ZipArchive')) {
            return '';
        }
        $dir = apps::DIR . '/packages';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || is_link($dir)) {
            return '';
        }
        $file = $dir . '/opencart-' . $version . '.tar.gz';
        if (is_file($file) && !is_link($file)) {
            return $file;
        }
        $ctx = stream_context_create(array('http' => array('timeout' => 180, 'user_agent' => 'Sentora-Apps', 'follow_location' => 1)));
        $data = @file_get_contents('https://github.com/opencart/opencart/releases/download/' . $version . '/opencart-' . $version . '.zip', false, $ctx);
        if (!is_string($data) || !hash_equals($sha256, hash('sha256', $data))) {
            return '';
        }
        $work = $dir . '/.opencart-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0700)) {
            return '';
        }
        $ok = false;
        file_put_contents($work . '/release.zip', $data);
        $zip = new ZipArchive();
        if ($zip->open($work . '/release.zip') === true) {
            $ok = $zip->extractTo($work . '/x');
            $zip->close();
        }
        if ($ok && is_dir($work . '/x/upload')) {
            exec('tar -czf ' . escapeshellarg($file . '.new') . ' --owner=0 --group=0 -C ' . escapeshellarg($work . '/x') . ' upload 2>&1', $o, $rc);
            $ok = $rc === 0 && is_file($file . '.new');
        } else {
            $ok = false;
        }
        exec('rm -rf ' . escapeshellarg($work));
        if (!$ok) {
            @unlink($file . '.new');
            return '';
        }
        chmod($file . '.new', 0644);
        rename($file . '.new', $file);
        foreach (glob($dir . '/opencart-*.tar.gz') as $old) {
            if ($old !== $file) {
                @unlink($old);
            }
        }
        return $file;
    }

    /**
     * The script Cron Manager runs every minute for a Snipe-IT install: Snipe-IT's scheduler, as
     * "php artisan schedule:run" starts it (its alerts, reminders, weekly backup and clean-ups;
     * the scheduler itself decides which are due). It is no web page: opened from the web it
     * answers 404.
     *
     * @param string $app The install's own folder (outside the website).
     * @return string PHP.
     */
    static function SchedulerScript($app)
    {
        $d = var_export(rtrim((string) $app, '/'), true);
        return "<?php\n"
            . "// Snipe-IT's scheduler (php artisan schedule:run): its alerts, reminders, weekly backup and clean-ups.\n"
            . "// Run every minute by Cron Manager, as this account. Made by the Apps add-on. It is no web page.\n"
            . "if (PHP_SAPI !== 'cli') {\n    http_response_code(404);\n    exit;\n}\n"
            . "chdir(" . $d . ");\n"
            . "require " . $d . " . '/bootstrap/autoload.php';\n"
            . "\$app = require_once " . $d . " . '/bootstrap/app.php';\n"
            . "\$kernel = \$app->make(Illuminate\\Contracts\\Console\\Kernel::class);\n"
            . "\$status = \$kernel->handle(\$input = new Symfony\\Component\\Console\\Input\\ArgvInput(array('artisan', 'schedule:run')), new Symfony\\Component\\Console\\Output\\ConsoleOutput());\n"
            . "\$kernel->terminate(\$input, \$status);\n"
            . "exit(\$status);\n";
    }

    /**
     * The scheduler script's path inside public_html (as Cron Manager takes it) and in full.
     *
     * @param array $a The x_apps row.
     * @return array{0:string, 1:string}|null
     */
    static function SchedulerPath(array $a)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT vh_directory_vc FROM x_vhosts WHERE vh_id_pk = :v AND vh_acc_fk = :u");
        $q->execute(array(':v' => (int) $a['ap_vh_fk'], ':u' => (int) $a['ap_acc_fk']));
        $dir = $q->fetchColumn();
        if ($dir === false) {
            return null;
        }
        $rel = trim(trim((string) $dir, '/') . '/' . trim((string) $a['ap_sub_vc'], '/'), '/') . '/' . self::SCHEDULER;
        return array($rel, rtrim(apps::Home((int) $a['ap_acc_fk']), '/') . '/public_html/' . $rel);
    }

    /**
     * A Snipe-IT install's Cron Manager task (every minute), as Cron Manager makes one: shown in the
     * client's Cron Manager with the rest, run as the account with the site's PHP.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $a The x_apps row.
     * @return string What happened (for the install's output).
     */
    static function SchedulerCron(array $a)
    {
        global $zdbh;
        require_once dirname(__FILE__) . '/../../cron/code/cronsafe.php';
        $uid = (int) $a['ap_acc_fk'];
        $u = ctrl_users::GetUserDetail($uid);
        $path = self::SchedulerPath($a);
        $full = $path ? cron_safe::ResolveFile((string) $u['username'], $path[0]) : false;
        if ($full === false) {
            return 'Its scheduler: the Cron Manager task could not be made (' . self::SCHEDULER . ' is missing): add it in Cron Manager, every minute.';
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk = :a AND ct_fullpath_vc = :f AND ct_deleted_ts IS NULL");
        $q->execute(array(':a' => $uid, ':f' => $full));
        if ((int) $q->fetchColumn() > 0) {
            return 'Its scheduler: the Cron Manager task is there already.';
        }
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk = :a AND ct_deleted_ts IS NULL");
        $q->execute(array(':a' => $uid));
        if ((int) $q->fetchColumn() >= 50) {
            return 'Its scheduler: you have the most scheduled tasks an account can have (50); delete one in Cron Manager, then add ' . $path[0] . ' there, every minute.';
        }
        $zdbh->prepare("INSERT INTO x_cronjobs (ct_acc_fk, ct_script_vc, ct_description_tx, ct_timing_vc, ct_fullpath_vc, ct_created_ts) VALUES (:a, :s, :d, :t, :f, :c)")
            ->execute(array(':a' => $uid, ':s' => $path[0], ':d' => 'Snipe-IT scheduler (Apps): alerts, reminders, weekly backup', ':t' => '* * * * *', ':f' => $full, ':c' => time()));
        return 'Its scheduler: a Cron Manager task runs it every minute (alerts, reminders, weekly backup).';
    }

    /**
     * Take a Snipe-IT install's Cron Manager task away (its removal).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $a The x_apps row.
     * @return void
     */
    static function SchedulerCronOff(array $a)
    {
        global $zdbh;
        $path = self::SchedulerPath($a);
        if ($path) {
            $zdbh->prepare("UPDATE x_cronjobs SET ct_deleted_ts = :t WHERE ct_acc_fk = :a AND ct_fullpath_vc = :f AND ct_deleted_ts IS NULL")
                ->execute(array(':t' => time(), ':a' => (int) $a['ap_acc_fk'], ':f' => $path[1]));
        }
    }

    /**
     * Composer on the server: downloaded with its published SHA-256 checked, kept in root's folder.
     *
     * @return bool Whether it is there.
     */
    static function GetComposer()
    {
        if (is_file(self::COMPOSER) && filemtime(self::COMPOSER) > time() - 30 * 86400) {
            return true;
        }
        $base = 'https://getcomposer.org/download/latest-stable/composer.phar';
        $ctx = stream_context_create(array('http' => array('timeout' => 60, 'user_agent' => 'Sentora-Apps')));
        $phar = @file_get_contents($base, false, $ctx);
        $sum = substr(trim((string) @file_get_contents($base . '.sha256sum', false, $ctx)), 0, 64);
        if (!is_string($phar) || strlen($phar) < 100000 || !preg_match('/^[0-9a-f]{64}$/', $sum) || !hash_equals($sum, hash('sha256', $phar))) {
            return is_file(self::COMPOSER);
        }
        file_put_contents(self::COMPOSER . '.new', $phar);
        chmod(self::COMPOSER . '.new', 0644);
        rename(self::COMPOSER . '.new', self::COMPOSER);
        return true;
    }

    /**
     * Snipe-IT's package of its pinned version, in root's folder: its source cloned at the tag and
     * checked to be the pinned commit, its libraries installed with Composer from its
     * composer.lock, then packed for the accounts. Built once per server, in the background (it
     * takes minutes), as the unprivileged user nobody, with Composer's scripts and plugins off:
     * nothing downloaded runs while it is built, and never as root.
     *
     * @param array $r The recipe.
     * @return string Its path; WAIT while it is being built; '' when the last try failed (tried
     *                again an hour later).
     */
    static function SnipeItPackage(array $r)
    {
        $v = (string) $r['version'];
        if (!preg_match('/^[0-9]+(\.[0-9]+){1,3}$/', $v) || !preg_match('/^v[0-9.]+$/', (string) $r['tag']) || !preg_match('/^[0-9a-f]{40}$/', (string) $r['commit'])) {
            return '';
        }
        $dir = apps::DIR . '/packages';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || is_link($dir)) {
            return '';
        }
        $file = $dir . '/snipeit-' . $v . '.tar.gz';
        if (is_file($file) && !is_link($file)) {
            return $file;
        }
        $busy = $dir . '/.snipeit-' . $v . '.building';
        $failed = $dir . '/.snipeit-' . $v . '.failed';
        if (is_file($busy) && filemtime($busy) > time() - 3600) {
            return self::WAIT;
        }
        if (is_file($failed) && filemtime($failed) > time() - 3600) {
            return '';
        }
        if (posix_getpwnam('nobody') === false || !self::GetComposer()) {
            @file_put_contents($failed, "The user nobody or Composer is missing.\n");
            return '';
        }
        @unlink($failed);
        $work = $dir . '/.snipeit-build-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0755)) {
            return '';
        }
        chown($work, 'nobody');
        $log = $dir . '/.snipeit-' . $v . '.log';
        $q = function ($p) {
            return escapeshellarg($p);
        };
        $nobody = 'setpriv --reuid=nobody --regid=nogroup --clear-groups env -i PATH=/usr/local/bin:/usr/bin:/bin LANG=C.UTF-8 HOME=' . $q($work)
            . ' COMPOSER_HOME=' . $q($work . '/.composer') . ' GIT_TERMINAL_PROMPT=0';
        $script = 'set -e; cd ' . $q($work) . '; '
            . $nobody . ' git -c advice.detachedHead=false clone -q --depth 1 --branch ' . $q((string) $r['tag']) . ' https://github.com/grokability/snipe-it.git src; '
            . 'test "$(' . $nobody . ' git -C src rev-parse HEAD)" = ' . $q((string) $r['commit']) . ' || { echo "The source is not the pinned commit."; exit 1; }; '
            . 'rm -rf src/.git; cd src; '
            . 'timeout 2400 nice -n 10 ' . $nobody . ' php -d memory_limit=1536M ' . $q(self::COMPOSER)
            . ' install --no-dev --no-scripts --no-plugins --no-interaction --no-progress --optimize-autoloader --ignore-platform-reqs; '
            . 'cd ..; find src/vendor -name .git -type d -prune -exec rm -rf {} +; '
            . 'tar -czf ' . $q($file . '.new') . ' --owner=0 --group=0 --transform ' . $q('s#^src#snipe-it#') . ' src; chmod 644 ' . $q($file . '.new') . '; mv ' . $q($file . '.new') . ' ' . $q($file);
        $full = 'touch ' . $q($busy) . '; ( ' . $script . ' ) > ' . $q($log) . ' 2>&1 || { rm -f ' . $q($file . '.new') . '; touch ' . $q($failed) . '; }; '
            . 'rm -rf ' . $q($work) . ' ' . $q($busy);
        touch($busy);
        exec('setsid nohup sh -c ' . escapeshellarg($full) . ' > /dev/null 2>&1 < /dev/null &');
        return self::WAIT;
    }

    /**
     * Why the last Snipe-IT build failed (the end of its log).
     *
     * @param array $r
     * @return string
     */
    static function BuildProblem(array $r)
    {
        $log = apps::DIR . '/packages/.snipeit-' . $r['version'] . '.log';
        $t = is_file($log) ? trim((string) file_get_contents($log, false, null, max(0, (int) filesize($log) - 600))) : '';
        return ($t !== '' ? $t : 'see the daemon\'s log') . ' (tried again in an hour)';
    }

    /**
     * The latest version of each app and the PHP it needs (WordPress: wordpress.org's own update
     * check), kept for the page in versions.json. Once a day.
     *
     * @return string What happened ('' when nothing was to do).
     */
    static function GetVersions()
    {
        $f = apps::DIR . '/versions.json';
        if (is_file($f) && filemtime($f) > time() - 86400) {
            return '';
        }
        $ctx = stream_context_create(array('http' => array('timeout' => 30, 'user_agent' => 'Sentora-Apps')));
        $j = json_decode((string) @file_get_contents('https://api.wordpress.org/core/version-check/1.7/', false, $ctx), true);
        $o = isset($j['offers'][0]) ? $j['offers'][0] : array();
        if (!isset($o['current'], $o['php_version']) || !preg_match('/^[0-9][0-9.]{0,15}$/', (string) $o['current']) || !preg_match('/^\d+\.\d+(\.\d+)?$/', (string) $o['php_version'])) {
            return 'Apps: the latest WordPress version could not be read (tried again in an hour).';
        }
        $v = array('wordpress' => array('version' => (string) $o['current'], 'php' => (string) $o['php_version'], 'checked' => time()));
        if (!is_dir(apps::DIR)) {
            @mkdir(apps::DIR, 0755, true);
        }
        file_put_contents($f . '.new', json_encode($v));
        chmod($f . '.new', 0644);
        rename($f . '.new', $f);
        return '';
    }
}
