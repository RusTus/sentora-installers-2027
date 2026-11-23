<?php
// zGod: the views against a stand-in panel (resellers, clients, websites, mail, databases...), the two actions
// with their refusals, an attack test, the daily report and the install step. Run from run.sh (env ZG_DB, ZG_MOD,
// ZG_PAGES).
$MOD = getenv('ZG_MOD');
$PAGES = getenv('ZG_PAGES');
$pass = 0;
$fail = 0;
function check($ok, $what, $extra = '')
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $what\n";
    } else {
        $fail++;
        echo "  FAIL $what" . ($extra !== '' ? "\n       " . str_replace("\n", "\n       ", substr($extra, 0, 1500)) : '') . "\n";
    }
}
function section($t)
{
    echo "\n== $t\n";
}

chdir(__DIR__ . '/root');
require __DIR__ . '/root/dryden/loader.inc.php';
require $MOD . '/code/controller.ext.php';
$db = $GLOBALS['zdbh'];
$GB = 1073741824;
$now = time();
$m = date('Ym');
$lastYear = (date('Y') - 1) . '06';

// ---------------------------------------------------------------------------------------------
// the panel: an administrator, a reseller with two clients (one suspended), a client of the administrator,
// a deleted account, and a hostile one (names made to break HTML)
foreach (array('Administrators', 'Resellers', 'Users') as $g) {
    $db->prepare("INSERT INTO x_groups (ug_name_vc) VALUES (?)")->execute(array($g));
}
function pkg($name, $res, $disk, $bw)
{
    global $db;
    $db->prepare("INSERT INTO x_packages (pk_name_vc, pk_reseller_fk, pk_enablephp_in, pk_created_ts) VALUES (?, ?, 1, ?)")->execute(array($name, $res, time() - 86400 * 90));
    $id = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO x_quotas (qt_package_fk, qt_diskspace_bi, qt_bandwidth_bi) VALUES (?, ?, ?)")->execute(array($id, $disk, $bw));
    return $id;
}
function acc($user, $grp, $res, $pkg, $enabled = 1, $created = null, $deleted = null)
{
    global $db;
    $db->prepare("INSERT INTO x_accounts (ac_user_vc, ac_email_vc, ac_reseller_fk, ac_package_fk, ac_group_fk, ac_enabled_in, ac_lastlogon_ts, ac_created_ts, ac_deleted_ts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute(array($user, $user . '@example.com', $res, $pkg, $grp, $enabled, time() - 3600, $created === null ? time() - 86400 * 30 : $created, $deleted));
    $id = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO x_profiles (ud_user_fk, ud_fullname_vc, ud_phone_vc, ud_address_tx, ud_postcode_vc) VALUES (?, ?, '555-0100', '', '')")->execute(array($id, ucfirst($user) . ' Example'));
    return $id;
}
function bw($acc, $month, $trans, $disk)
{
    global $db;
    $db->prepare("INSERT INTO x_bandwidth (bd_acc_fk, bd_month_in, bd_transamount_bi, bd_diskamount_bi) VALUES (?, ?, ?, ?)")->execute(array($acc, $month, $trans, $disk));
}
$pAdmin = pkg('Administration', 1, 0, 0);
$admin = acc('zadmin', 1, 1, $pAdmin);
$db->exec("UPDATE x_accounts SET ac_reseller_fk = $admin WHERE ac_id_pk = $admin");
$pRes = pkg('Reseller', $admin, 20 * $GB, 200 * $GB);
$resel = acc('webco', 2, $admin, $pRes);
$pSilver = pkg('SILVER', $resel, 5 * $GB, 50 * $GB);
$pBasic = pkg('BASIC', $resel, 1 * $GB, 10 * $GB);
$alice = acc('alice', 3, $resel, $pSilver);
$bob = acc('bob', 3, $resel, $pBasic, 0);
$carol = acc('carol', 3, $admin, $pSilver, 1, $now - 3600);
$dave = acc('dave', 3, $resel, $pBasic, 1, null, $now - 100);
$evil = acc('<script>alert(1)</script>', 3, $resel, $pkgEvil = pkg('<img src=x onerror=alert(2)>', $resel, 1 * $GB, 1 * $GB));
bw($admin, $m, 3 * $GB, 2 * $GB);
bw($alice, $m, 46 * $GB, (int) (4.7 * $GB));  // 94% of bandwidth, 94% of disk
bw($bob, $m, 1 * $GB, (int) (0.2 * $GB));
bw($carol, $m, 2 * $GB, 1 * $GB);
bw($alice, $lastYear, 10 * $GB, 4 * $GB);
bw($resel, $m, 0, (int) (0.1 * $GB));
$db->exec("INSERT INTO x_vhosts (vh_acc_fk, vh_name_vc, vh_directory_vc, vh_type_in, vh_active_in, vh_enabled_in, vh_php_version_vc, vh_created_ts) VALUES
    ($alice, 'alice-shop.com', '/alice-shop_com', 1, 1, 1, '8.5', $now), ($alice, 'blog.alice-shop.com', '/blog', 2, 0, 1, '', $now),
    ($carol, 'carol.net', '/carol_net', 1, 1, 1, '8.4', $now), ($evil, '\"><svg onload=alert(3)>.com', '/x', 1, 1, 1, '', $now)");
$db->exec("INSERT INTO x_vhosts (vh_acc_fk, vh_name_vc, vh_directory_vc, vh_type_in, vh_active_in, vh_enabled_in, vh_created_ts, vh_deleted_ts) VALUES ($alice, 'old-deleted.com', '/old', 1, 1, 1, $now, $now)");
$db->exec("INSERT INTO x_mysql_databases (my_acc_fk, my_name_vc, my_usedspace_bi, my_created_ts) VALUES ($alice, 'alice_shop', 52428800, $now), ($carol, 'carol_wp', 1048576, $now)");
$db->exec("INSERT INTO x_mysql_users (mu_acc_fk, mu_name_vc, mu_pass_vc, mu_access_vc, mu_created_ts) VALUES ($alice, 'alice_app', 'enc:SECRETPASS', 'localhost', $now), ($alice, 'alice_ro', 'enc:OTHERSECRET', '%', $now)");
$db->exec("INSERT INTO x_mysql_users (mu_acc_fk, mu_name_vc, mu_pass_vc, mu_access_vc, mu_created_ts, mu_deleted_ts) VALUES ($alice, 'alice_gone', 'x', 'localhost', $now, $now)");
$db->exec("INSERT INTO x_mysql_dbmap (mm_acc_fk, mm_user_fk, mm_database_fk) VALUES ($alice, 1, 1)");
$db->exec("INSERT INTO x_dns (dn_acc_fk, dn_name_vc, dn_type_vc, dn_host_vc, dn_ttl_in, dn_target_vc, dn_priority_in, dn_created_ts) VALUES
    ($alice, 'alice-shop.com', 'A', '@', 3600, '203.0.113.10', 0, $now), ($alice, 'alice-shop.com', 'MX', '@', 3600, 'mail.alice-shop.com', 10, $now),
    ($alice, 'alice-shop.com', 'TXT', '@', 3600, '<script>alert(4)</script>', 0, $now), ($carol, 'carol.net', 'A', '@', 3600, '203.0.113.20', 0, $now)");
$db->exec("INSERT INTO x_dns (dn_acc_fk, dn_name_vc, dn_type_vc, dn_host_vc, dn_ttl_in, dn_target_vc, dn_created_ts, dn_deleted_ts) VALUES ($alice, 'alice-shop.com', 'A', 'old', 3600, '198.51.100.1', $now, $now)");
$db->exec("INSERT INTO x_mailboxes (mb_acc_fk, mb_address_vc, mb_enabled_in, mb_created_ts) VALUES ($alice, 'info@alice-shop.com', 1, $now), ($alice, 'sales@alice-shop.com', 0, $now), ($carol, 'me@carol.net', 1, $now)");
$db->exec("INSERT INTO x_forwarders (fw_acc_fk, fw_address_vc, fw_destination_vc, fw_keepmessage_in) VALUES ($alice, 'orders@alice-shop.com', 'alice@gmail.example', 1)");
$db->exec("INSERT INTO x_aliases (al_acc_fk, al_address_vc, al_destination_vc) VALUES ($alice, 'hello@alice-shop.com', 'info@alice-shop.com')");
$db->exec("INSERT INTO x_distlists (dl_acc_fk, dl_address_vc, dl_created_ts) VALUES ($alice, 'team@alice-shop.com', $now)");
$db->exec("INSERT INTO x_ftpaccounts (ft_acc_fk, ft_user_vc, ft_directory_vc, ft_access_vc, ft_password_vc, ft_created_ts) VALUES ($alice, 'alice_ftp', '/public_html', 'RW', 'secret-hash', $now)");
$db->exec("INSERT INTO x_cronjobs (ct_acc_fk, ct_script_vc, ct_timing_vc, ct_description_tx) VALUES ($alice, 'cron/backup.php', '0 3 * * *', 'nightly backup')");
$db->exec("INSERT INTO x_logs (lg_user_fk, lg_code_vc, lg_module_vc, lg_detail_tx, lg_when_ts) VALUES ($alice, '1', 'mailboxes', 'Created mailbox info@alice-shop.com', '" . gmdate('Y-m-d H:i:s', $now - 600) . "')");

// ---------------------------------------------------------------------------------------------
section('What it reads: every account across every reseller');
$all = zgod::Accounts();
check(count($all) === 6 && !isset($all[$dave]), '6 accounts (the deleted one left out)', implode(',', array_column($all, 'username')));
check($all[$resel]['clients'] === 3 && $all[$admin]['clients'] === 2, 'clients: the reseller 3, the administrator 2 (itself not counted)', $all[$resel]['clients'] . '/' . $all[$admin]['clients']);
check($all[$alice]['domains'] === 2 && $all[$alice]['mailboxes'] === 2 && $all[$alice]['databases'] === 1 && $all[$alice]['ftp'] === 1 && $all[$alice]['cron'] === 1, 'alice: 2 websites (not the deleted one), 2 mailboxes, a database, FTP, cron');
check($all[$alice]['resellername'] === 'webco' && $all[$alice]['package'] === 'SILVER' && $all[$alice]['groupname'] === 'Users', 'alice: package, group and reseller');
check($all[$alice]['bw'] === 46 * $GB && $all[$alice]['bwq'] === 50 * $GB, 'this month\'s bandwidth and its quota');
$t = zgod::Totals();
check($t['month'] === 52 * $GB && $t['total'] === 62 * $GB && $t['year'] === 52 * $GB, 'server bandwidth: month 52 GB, ever 62 GB (last year\'s 10 GB not in this year)', json_encode($t));
$rs = zgod::Resellers();
$r = null;
foreach ($rs as $x) {
    if ($x['id'] === $resel) {
        $r = $x;
    }
}
check($r !== null && $r['active'] === 2 && $r['off'] === 1 && $r['packages'] === 3 && $r['client_bw'] === 47 * $GB, 'the reseller: 2 active clients, 1 suspended, 3 packages, its clients\' bandwidth', json_encode($r));
$a = zgod::Account($alice);
check(count($a['domain_list']) === 2 && count($a['mailbox_list']) === 2 && count($a['forwarder_list']) === 1 && count($a['alias_list']) === 1 && count($a['distlist_list']) === 1, 'alice in full: websites, mailboxes, forwarders, aliases, lists');
check($a['ftp_list'][0]['ftpuser'] === 'alice_ftp' && !isset($a['ftp_list'][0]['password']) && strpos(json_encode($a), 'secret-hash') === false, 'FTP accounts without their passwords');
check(count($a['log_list']) === 1 && $a['profile']['name'] === 'Alice Example', 'the panel log and the profile');
check(zgod::Account(999999) === null && zgod::Account($dave) === null, 'no account, or a deleted one: nothing');
$ra = zgod::Account($resel);
check(count($ra['package_list']) === 3 && count($ra['client_list']) === 3, 'the reseller: its packages and clients');

// ---------------------------------------------------------------------------------------------
section('The pages (an administrator)');
$GLOBALS['ZG_ME'] = $admin;
function page(array $get, array $post = array(), $action = '')
{
    module_controller::$msg = array();
    $_GET = $get;
    $_POST = $post;
    $err = '';
    if ($action !== '') {
        try {
            call_user_func(array('module_controller', 'do' . $action));
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    }
    return array(module_controller::PageHtml(), $err);
}
function save($name, $html)
{
    global $PAGES;
    if ($PAGES) {
        @mkdir($PAGES, 0755, true);
        file_put_contents("$PAGES/$name.html", $html);
    }
}
list($html) = page(array());
save('overview', $html);
check(strpos($html, 'zg-tabs') !== false && strpos($html, 'class="on" aria-current="page">Overview') !== false, 'Overview: the tab bar, Overview open');
check(strpos($html, '52 GB') !== false && strpos($html, 'zg-ring') !== false && strpos($html, 'zg-chart') !== false, 'tiles, the month chart and the ring');
check(preg_match('/Quotas nearly full.*alice/s', $html) === 1, 'alice under quotas nearly full');
list($html) = page(array('tab' => 'accounts'));
save('accounts', $html);
check(substr_count($html, 'zg-row zg-acc') === 6 && strpos($html, '>dave<') === false, 'Accounts: 6 rows, not the deleted one');
check(strpos($html, 'Suspended') !== false && strpos($html, 'Near a quota') !== false, 'the suspended one and the one near a quota marked');
list($html) = page(array('tab' => 'accounts', 'reseller' => (string) $resel));
check(substr_count($html, 'zg-row zg-acc') === 3, 'filtered to one reseller: its 3 clients');
list($html) = page(array('tab' => 'accounts', 'status' => 'suspended'));
check(substr_count($html, 'zg-row zg-acc') === 1 && strpos($html, '>bob<') !== false, 'only the suspended: bob');
list($html) = page(array('tab' => 'accounts', 'status' => 'near'));
check(substr_count($html, 'zg-row zg-acc') === 1 && strpos($html, '>alice<') !== false, 'only those near a quota: alice');
$db->exec("UPDATE x_accounts SET ac_lastlogon_ts = 0 WHERE ac_id_pk = $bob");
$db->exec("UPDATE x_accounts SET ac_lastlogon_ts = " . (time() - 200 * 86400) . " WHERE ac_id_pk = $resel");
list($html) = page(array('tab' => 'accounts', 'status' => 'never'));
check(substr_count($html, 'zg-row zg-acc') === 1 && strpos($html, '>bob<') !== false && strpos($html, 'Never logged in') !== false, 'never logged in: bob');
list($html) = page(array('tab' => 'accounts', 'status' => 'inactive'));
check(substr_count($html, 'zg-row zg-acc') === 1 && strpos($html, '>webco<') !== false && strpos($html, '>Inactive<') !== false, 'inactive (no login for 90 days): webco');
list($html) = page(array('tab' => 'accounts', 'status' => 'recent'));
check(substr_count($html, 'zg-row zg-acc') === 4 && strpos($html, '>carol<') !== false && strpos($html, '>bob<') === false, 'logged in recently: the other four');
$db->exec("UPDATE x_accounts SET ac_lastlogon_ts = " . (time() - 3600) . " WHERE ac_id_pk IN ($bob, $resel)");
check(zgod::Activity(0) === 'never' && zgod::Activity(time() - 3 * 86400) === 'recent' && zgod::Activity(time() - 30 * 86400) === 'active' && zgod::Activity(time() - 200 * 86400) === 'inactive', 'activity: recent 7 days, active 90, then inactive');
list($html) = page(array('tab' => 'accounts', 'q' => 'car'));
check(substr_count($html, 'zg-row zg-acc') === 1, 'search: "car" finds carol');
list($html) = page(array('tab' => 'domains'));
save('domains', $html);
check(strpos($html, 'class="on" aria-current="page">Domains') !== false && substr_count($html, '<td data-label="Domain">') === 4 && strpos($html, 'old-deleted.com') === false, 'Domains: every domain on the panel (4), not the deleted one');
check(preg_match('/blog\.alice-shop\.com.*subdomain.*not live yet.*alice/s', $html) === 1 && strpos($html, '<td data-label="Reseller">webco<') !== false, 'each with its kind, state, account and reseller');
check(strpos($html, '<svg onload') === false && strpos($html, '&lt;svg onload') !== false, 'a hostile domain name is escaped');
list($html) = page(array('tab' => 'domains', 'kind' => '2'));
check(substr_count($html, '<td data-label="Domain">') === 1 && strpos($html, 'blog.alice-shop.com') !== false, 'only subdomains: blog.alice-shop.com');
list($html) = page(array('tab' => 'domains', 'reseller' => (string) $admin));
check(substr_count($html, '<td data-label="Domain">') === 1 && strpos($html, 'carol.net') !== false, 'one reseller\'s clients\' domains');
list($html) = page(array('tab' => 'domains', 'q' => 'alice'));
check(substr_count($html, '<td data-label="Domain">') === 2, 'search by account: alice\'s two');
list($html) = page(array('tab' => 'domains', 'q' => "' OR 1=1 --", 'kind' => '1 OR 1', 'sort' => 'x;DROP'));
check(substr_count($html, '<td data-label="Domain">') === 0 && (int) $db->query("SELECT COUNT(*) FROM x_vhosts")->fetchColumn() === 5, 'hostile filters find nothing and change nothing');
foreach (array('usage' => array('Of the server', '94%', 'zg-hot'), 'mail' => array('info@alice-shop.com', 'orders@alice-shop.com', 'hello@alice-shop.com', 'team@alice-shop.com', 'me@carol.net'),
        'mysql' => array('alice_shop', 'carol_wp', 'alice_app', 'alice_ro'), 'ftp' => array('alice_ftp', '/public_html'), 'cron' => array('0 3 * * *', 'nightly backup'),
        'packages' => array('SILVER', 'BASIC', 'Reseller')) as $t => $words) {
    list($html) = page(array('tab' => $t));
    save($t, $html);
    $miss = array_filter($words, function ($w) use ($html) { return strpos($html, $w) === false; });
    check(!$miss && strpos($html, 'aria-current="page">' . module_controller::TABS[$t]) !== false, "the $t list: everything of its kind on the panel", implode(', ', $miss));
}
list($html) = page(array('tab' => 'mysql'));
check(strpos($html, 'SECRET') === false && strpos($html, 'alice_gone') === false, 'MySQL list: no password, no deleted user');
list($html) = page(array('tab' => 'mail', 'q' => 'carol'));
check(strpos($html, 'me@carol.net') !== false && strpos($html, 'info@alice-shop.com') === false, 'a list\'s search: by account');
list($html) = page(array('tab' => 'usage', 'sort' => 'bandwidth'));
check(strpos($html, '>alice<') < strpos($html, '>carol<'), 'Usage by most bandwidth: alice first');
list($html) = page(array('tab' => 'packages'));
check(strpos($html, '&lt;img src=x') !== false && strpos($html, '<img src=x') === false, 'a hostile package name is escaped');
list($html) = page(array());
check(strpos($html, 'tab=docker') === false, 'no Docker apps tab while Docker Hosting is not on the panel');
list($html) = page(array('tab' => 'docker'));
check(strpos($html, 'aria-current="page">Overview') !== false, 'asking for it then shows the overview');
$db->exec("CREATE TABLE x_dh_nodes (nd_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, nd_name_vc TEXT)");
$db->exec("CREATE TABLE x_dh_containers (dc_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, dc_acc_fk INT, dc_node_fk INT, dc_app_vc TEXT, dc_label_vc TEXT, dc_cpu_in INT, dc_mem_in INT, dc_disk_in INT,
    dc_domain_vc TEXT, dc_state_vc TEXT, dc_used_in INT, dc_created_in INT, dc_deleted_in INT)");
$db->exec("INSERT INTO x_dh_nodes (nd_name_vc) VALUES ('node-one')");
$db->exec("INSERT INTO x_dh_containers (dc_acc_fk, dc_node_fk, dc_app_vc, dc_label_vc, dc_cpu_in, dc_mem_in, dc_disk_in, dc_domain_vc, dc_state_vc, dc_used_in, dc_created_in, dc_deleted_in) VALUES
    ($alice, 1, 'nextcloud', 'Team files', 150, 1024, 10240, 'files.alice-shop.com', 'running', 812, $now, NULL),
    ($carol, 1, 'ghost', '<b>blog</b>', 50, 512, 5120, 'carol.net', 'stopped', 120, $now, NULL),
    ($alice, 1, 'oldapp', '', 50, 256, 1024, '', 'deleted', 0, $now, $now)");
list($html) = page(array('tab' => 'docker'));
save('docker', $html);
check(strpos($html, 'aria-current="page">Docker apps') !== false && substr_count($html, '<td data-label="App">') === 2 && strpos($html, 'oldapp') === false, 'Docker apps: every app on the panel (2), not the deleted one');
check(strpos($html, '1.5 CPU, 1024 MB, 10 GB') !== false && strpos($html, '812&nbsp;MB') !== false && strpos($html, 'node-one') !== false && strpos($html, '>running<') !== false, 'each with its size, disk used, node and state');
check(strpos($html, '<b>blog</b>') === false && strpos($html, '&lt;b&gt;blog') !== false, 'an app\'s name is escaped');
list($html) = page(array('tab' => 'account', 'id' => (string) $alice));
save('account', $html);
check(strpos($html, 'files.alice-shop.com') !== false && strpos($html, 'carol.net</td>') === false, 'alice\'s page: her Docker app, not carol\'s');
list($html) = page(array('tab' => 'resellers'));
save('resellers', $html);
check(strpos($html, 'webco') !== false && strpos($html, '1 suspended') !== false, 'Resellers: webco with 1 suspended client');
list($html) = page(array('tab' => 'account', 'id' => (string) $alice));
save('account', $html);
foreach (array('alice-shop.com', 'alice_shop', 'info@alice-shop.com', 'orders@alice-shop.com', 'team@alice-shop.com', 'alice_ftp', 'nightly backup', 'Created mailbox', 'Alice Example', 'not live yet') as $w) {
    check(strpos($html, $w) !== false, "alice's page shows $w");
}
check(strpos($html, 'action=Suspend') !== false && strpos($html, 'csfr_token') !== false, 'a Suspend form with the form key');
check(strpos($html, 'alice_app') !== false && strpos($html, 'alice_ro') !== false && strpos($html, 'alice_gone') === false && preg_match('/alice_app.*from localhost.*alice_shop/s', $html) === 1, 'MySQL users: each with where from and its databases, not the deleted one');
check(strpos($html, 'SECRET') === false && strpos($html, 'enc:') === false, 'never a MySQL password, not even the stored copy');
check(strpos($html, 'DNS records') !== false && strpos($html, '203.0.113.10') !== false && strpos($html, 'priority 10') !== false && strpos($html, '198.51.100.1') === false && strpos($html, '203.0.113.20') === false, 'DNS records: hers by domain (an MX with its priority), not the deleted one, not carol\'s');
check(strpos($html, '<script>alert(4)') === false && strpos($html, '&lt;script&gt;alert(4)') !== false, 'a record\'s text is escaped');
check(strpos($html, 'Shadow</button>') === false && strpos($html, 'module=shadowing') === false, 'no Shadow button while the panel has no Shadowing module');
$db->exec("INSERT INTO x_modules (mo_name_vc, mo_folder_vc, mo_enabled_en) VALUES ('Shadowing', 'shadowing', 'true')");
list($html) = page(array('tab' => 'account', 'id' => (string) $alice));
save('account', $html);
check(preg_match('/<form method="post" class="zg-shadow" action="\.\/\?module=shadowing&amp;action=ShadowUser"><input type="hidden" name="csfr_token"[^>]*><input type="hidden" name="inShadow_' . $alice . '" value="1"><input type="hidden" name="inReturn" value="zgod">/', $html) === 1 && strpos($html, 'Shadow alice') !== false,
    'Shadow alice: a form to the panel\'s own Shadowing (its checks and log), with the form key', $html);
list($html) = page(array('tab' => 'account', 'id' => (string) $admin));
check(strpos($html, 'module=shadowing') === false, 'no Shadow button on your own account');
check(strpos($html, 'manage_clients') === false, 'no Manage Clients links for an administrator');
list($html) = page(array('tab' => 'account', 'id' => (string) $alice));
check(strpos($html, './?module=manage_clients&amp;other=' . $alice . '&amp;show=Delete') !== false && strpos($html, 'action=Delete') === false, 'Delete goes to Manage Clients (its confirmation); zGod never deletes');
list($html) = page(array('tab' => 'account', 'id' => (string) $resel));
save('account-reseller', $html);
check(strpos($html, 'Their clients') !== false && strpos($html, 'Their packages') !== false, 'a reseller\'s page: its packages and clients');
list($html) = page(array('tab' => 'account', 'id' => (string) $admin));
check(strpos($html, 'action=Suspend') === false && strpos($html, 'can not be suspended') !== false, 'an administrator: no Suspend button');
list($html) = page(array('tab' => 'report'));
save('report', $html);
check(strpos($html, 'zGod daily report') !== false && strpos($html, 'zadmin@example.com') !== false, 'Daily report: the preview, sent to the administrator by default');

// ---------------------------------------------------------------------------------------------
section('Suspend and switch on, as the panel does');
runtime_hook::$ran = array();
list($html, $err) = page(array('tab' => 'account', 'id' => (string) $carol), array('csfr_token' => 'session-key', 'inId' => (string) $carol, 'inReason' => 'not paid'), 'Suspend');
$en = (int) $db->query("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = $carol")->fetchColumn();
check($err === '' && $en === 0, 'carol suspended (switched off)', $err);
check(runtime_hook::$ran === array('OnBeforeDisableClient', 'OnAfterDisableClient'), 'the panel\'s hooks ran', implode(',', runtime_hook::$ran));
check(ws_suspend::Reason($carol) === 'not paid' && strpos($html, 'Suspended because') !== false, 'the reason kept and shown');
check((int) $db->query("SELECT COUNT(*) FROM x_logs WHERE lg_module_vc = 'zgod' AND lg_detail_tx LIKE 'Account suspended: carol%'")->fetchColumn() === 1, 'logged in the panel\'s log');
list($html, $err) = page(array('tab' => 'account', 'id' => (string) $carol), array('csfr_token' => 'session-key', 'inId' => (string) $carol), 'Unsuspend');
check((int) $db->query("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = $carol")->fetchColumn() === 1 && ws_suspend::Reason($carol) === '', 'switched on again, the reason cleared');
list($html, $err) = page(array(), array('csfr_token' => 'session-key', 'inId' => (string) $admin), 'Suspend');
check((int) $db->query("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = $admin")->fetchColumn() === 1 && strpos($html, 'can not be suspended') !== false, 'an administrator (here itself) is never suspended');
list($html, $err) = page(array(), array('csfr_token' => 'session-key', 'inId' => (string) $dave), 'Suspend');
check(strpos($html, 'no such account') !== false, 'a deleted account: refused');
list($html, $err) = page(array(), array('csfr_token' => 'session-key', 'inId' => (string) $carol, 'inReason' => '<b>x</b>'), 'Suspend');
check((int) $db->query("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = $carol")->fetchColumn() === 1 && strpos($html, 'one line of up to 200') !== false, 'a reason with < > is refused (and nothing suspended)');

section('A mailbox off and on');
$mb = (int) $db->query("SELECT mb_id_pk FROM x_mailboxes WHERE mb_address_vc = 'info@alice-shop.com'")->fetchColumn();
runtime_hook::$ran = array();
page(array('tab' => 'account', 'id' => (string) $alice), array('csfr_token' => 'session-key', 'inMb' => (string) $mb, 'inOn' => '0'), 'Mailbox');
check((int) $db->query("SELECT mb_enabled_in FROM x_mailboxes WHERE mb_id_pk = $mb")->fetchColumn() === 0 && runtime_hook::$ran === array('OnBeforeDisableMailbox', 'OnAfterDisableMailbox'), 'switched off, with the panel\'s hooks');
page(array(), array('csfr_token' => 'session-key', 'inMb' => (string) $mb, 'inOn' => '1'), 'Mailbox');
check((int) $db->query("SELECT mb_enabled_in FROM x_mailboxes WHERE mb_id_pk = $mb")->fetchColumn() === 1, 'switched on again');

// ---------------------------------------------------------------------------------------------
section('Attack test');
list($html) = page(array('tab' => 'accounts'));
check(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'a hostile user name is shown as text');
check(strpos($html, '<img src=x onerror') === false, 'a hostile package name is shown as text');
list($html) = page(array('tab' => 'account', 'id' => (string) $evil));
check(strpos($html, '<svg onload') === false && strpos($html, '<script>alert') === false, 'a hostile domain name is shown as text');
list($html) = page(array('tab' => 'report'));
check(strpos($html, '<script>alert(1)</script>') === false, 'the report escapes names too');
$before = $db->query("SELECT COUNT(*) FROM x_accounts")->fetchColumn();
foreach (array(array('tab' => 'accounts', 'q' => "' OR 1=1; DROP TABLE x_accounts; --"), array('tab' => 'accounts', 'reseller' => "2 OR 1=1"), array('tab' => 'account', 'id' => "1 UNION SELECT ac_pass_vc FROM x_accounts"),
    array('tab' => '../../../etc/passwd'), array('tab' => 'accounts', 'sort' => 'ac_pass_vc'), array('tab' => array('x')), array('id' => array('1'))) as $get) {
    list($html, $err) = page($get);
    check($err === '' && strpos($html, 'zg-tabs') !== false, 'a hostile address is handled as plain values: ' . substr(json_encode($get), 0, 80));
}
check($db->query("SELECT COUNT(*) FROM x_accounts")->fetchColumn() === $before, 'no table touched');
list($html) = page(array('tab' => 'accounts', 'reseller' => "$resel OR 1=1"));
check(substr_count($html, 'zg-row zg-acc') === 3, '"reseller=N OR 1=1" is just reseller N');
list($html, $err) = page(array(), array('inId' => (string) $alice), 'Suspend');
check($err === 'form key refused' && (int) $db->query("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = $alice")->fetchColumn() === 1, 'no form key (a forged click from another site): refused, nothing changed');
list($html, $err) = page(array(), array('csfr_token' => 'guess', 'inMb' => (string) $mb, 'inOn' => '0'), 'Mailbox');
check($err === 'form key refused' && (int) $db->query("SELECT mb_enabled_in FROM x_mailboxes WHERE mb_id_pk = $mb")->fetchColumn() === 1, 'a wrong form key: refused');
// a reseller and a client: nothing shown, nothing done
foreach (array($resel => 'a reseller', $alice => 'a client') as $who => $label) {
    $GLOBALS['ZG_ME'] = $who;
    list($html) = page(array('tab' => 'accounts'));
    check(strpos($html, 'Only administrators') !== false && strpos($html, 'carol') === false && strpos($html, 'zg-tabs') === false, "$label sees nothing");
    page(array(), array('csfr_token' => 'session-key', 'inId' => (string) $carol), 'Suspend');
    page(array(), array('csfr_token' => 'session-key', 'inMb' => (string) $mb, 'inOn' => '0'), 'Mailbox');
    page(array(), array('csfr_token' => 'session-key', 'inReport' => 'on', 'inTo' => 'x@evil.example'), 'Report');
    check((int) $db->query("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = $carol")->fetchColumn() === 1
        && (int) $db->query("SELECT mb_enabled_in FROM x_mailboxes WHERE mb_id_pk = $mb")->fetchColumn() === 1 && zgod::Get('report_to') === '', "$label can not suspend, switch a mailbox or set the report");
}
$GLOBALS['ZG_ME'] = $admin;
list($html) = page(array(), array('csfr_token' => 'session-key', 'inReport' => 'on', 'inTo' => "a@example.com\r\nBcc: victim@example.org"), 'Report');
check(strpos($html, 'not an email address') !== false && zgod::Get('report') !== 'on', 'a header trick in the address is refused, nothing saved', $html);
check(strpos(json_encode(zgod::Get('report_to')), '\r') === false && strpos(zgod::Get('report_to'), "\n") === false, 'no line break reaches the stored addresses');

// ---------------------------------------------------------------------------------------------
section('The daily report');
check(zgod::DailyReport(function () { return true; }) === '', 'off by default: nothing sent');
page(array('tab' => 'report'), array('csfr_token' => 'session-key', 'inReport' => 'on', 'inTo' => 'boss@example.com, ops@example.com'), 'Report');
check(zgod::Get('report') === 'on' && zgod::ReportTo() === array('boss@example.com', 'ops@example.com'), 'switched on, to two addresses');
$sent = array();
$line = zgod::DailyReport(function ($to, $subject, $html) use (&$sent) { $sent = array($to, $subject, $html); return true; });
check(strpos($line, 'sent to boss@example.com, ops@example.com') !== false && $sent[0] === array('boss@example.com', 'ops@example.com'), 'sent to both', $line);
check(strpos($sent[1], 'zGod: 6 accounts, 1 new') === 0 && strpos($sent[1], '1 near a quota') !== false && strpos($sent[1], '1 suspended') !== false, 'the subject: 6 accounts, 1 new (carol), 1 near a quota, 1 suspended', $sent[1]);
check(strpos($sent[2], 'carol') !== false && strpos($sent[2], 'alice') !== false && strpos($sent[2], '<script>alert') === false, 'the report: new, near a quota, biggest users; names escaped');
$line = zgod::DailyReport(function () { return false; });
check(strpos($line, 'could not be sent') !== false && strpos(zgod::Get('report_last'), 'failed') !== false, 'a failed send is said and kept');
page(array('tab' => 'report'), array('csfr_token' => 'session-key', 'inTo' => ''), 'Report');
check(zgod::Get('report') === 'off' && zgod::DailyReport(function () { return true; }) === '', 'switched off again: nothing sent');

// ---------------------------------------------------------------------------------------------
section('Lines for the Elk node (zgod.log)');
$elkFile = sys_get_temp_dir() . '/zgod-elk-' . getmypid() . '/zgod.log';
@mkdir(dirname($elkFile), 0700, true);
@unlink($elkFile);
$t0 = 1795000000;
$said = zgod::ElkRun($elkFile, $t0);
$lines = array_map(function ($l) { return json_decode($l, true); }, file($elkFile, FILE_IGNORE_NEW_LINES));
$byType = array();
foreach ($lines as $l) { $byType[$l['type']][] = $l; }
check(strpos($said, 'lines for the Elk node') !== false && count($byType['account']) === 6 && count($byType['server']) === 1, 'the first run: one line per account and the server\'s', $said);
check(!isset($byType['action']), 'the first run starts from now (the past actions are not sent again)');
check(substr(sprintf('%o', fileperms($elkFile)), -3) === '640', 'the file is not for everyone (640)');
$alice = array_values(array_filter($byType['account'], function ($l) { return $l['user'] === 'alice'; }))[0];
check(is_int($alice['disk']) && is_int($alice['bandwidth']) && $alice['reseller'] !== '' && array_key_exists('disk_pct', $alice), 'an account\'s figures, numbers as numbers', json_encode($alice));
check(strpos(file_get_contents($elkFile), '@') === strpos(file_get_contents($elkFile), '@timestamp') && strpos(file_get_contents($elkFile), 'example.') === false, 'no email address in the lines');
$server = $byType['server'][0];
check($server['accounts'] === 6 && $server['suspended'] >= 1, 'the server\'s line: accounts and suspended', json_encode($server));
$size = filesize($elkFile);
check(zgod::ElkRun($elkFile, $t0 + 300) === '' && filesize($elkFile) === $size, 'the same hour, nothing done: nothing written');
$victim = (int) $db->query("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc = 'carol'")->fetchColumn();
zgod::SetAccount($victim, false, $admin, 'did not pay');
zgod::SetAccount($victim, true, $admin);
zgod::SetMailbox($mb, false, $admin);
zgod::SetMailbox($mb, true, $admin);
zgod::ElkRun($elkFile, $t0 + 600);
$acts = array_values(array_filter(array_map(function ($l) { return json_decode($l, true); }, file($elkFile, FILE_IGNORE_NEW_LINES)), function ($l) { return $l['type'] === 'action'; }));
check(array_map(function ($l) { return $l['action']; }, $acts) === array('suspend', 'unsuspend', 'mailbox-off', 'mailbox-on'), 'what zGod did, in order', json_encode($acts));
check($acts[0]['user'] === 'carol' && $acts[0]['reason'] === 'did not pay' && $acts[0]['by'] !== '' && $acts[2]['user'] !== '' && strpos(str_replace('"@timestamp"', '', json_encode($acts[2])), '@') === false, 'who, by whom, why; a mailbox by its account, not its address', json_encode($acts));
zgod::ElkRun($elkFile, $t0 + 900);
check(count(file($elkFile)) === count($lines) + 4, 'an action is sent once');
zgod::ElkRun($elkFile, $t0 + 3600);
check(count(file($elkFile)) === count($lines) * 2 + 4, 'the next hour: the figures again');
@unlink($elkFile);
@symlink('/etc/passwd', $elkFile);
zgod::Set('elk_hour', '');
check(strpos(zgod::ElkRun($elkFile, $t0 + 7200), 'no log folder') !== false && strpos(file_get_contents('/etc/passwd'), 'zgod') === false, 'a link in its place is not written through');
@unlink($elkFile);
@rmdir(dirname($elkFile));

// ---------------------------------------------------------------------------------------------
section('The install step: administrators only');
$db->exec("INSERT INTO x_modules (mo_name_vc, mo_folder_vc, mo_enabled_en) VALUES ('zgod', 'zgod', 'true')");
$modId = (int) $db->lastInsertId();
$db->exec("INSERT INTO x_permissions (pe_group_fk, pe_module_fk) VALUES (2, $modId), (3, $modId)");
@mkdir(__DIR__ . '/root/modules', 0755, true);
@symlink($MOD, __DIR__ . '/root/modules/zgod');
ob_start();
include __DIR__ . '/root/modules/zgod/deploy/install.run';
$out = ob_get_clean();
@unlink(__DIR__ . '/root/modules/zgod');
@rmdir(__DIR__ . '/root/modules');
$groups = $db->query("SELECT pe_group_fk FROM x_permissions WHERE pe_module_fk = $modId")->fetchAll(PDO::FETCH_COLUMN);
check($groups === array('1') || $groups === array(1), 'only the administrators\' group may open it', json_encode($groups));
check((string) $db->query("SELECT mo_name_vc FROM x_modules WHERE mo_id_pk = $modId")->fetchColumn() === 'zGod', 'its name from module.xml', $out);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
