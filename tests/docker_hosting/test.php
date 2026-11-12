<?php
// Docker Hosting 100: the panel's side and the node agent together. Run from run.sh (it starts the
// stand-in panel's web server, DH_WEB, and sets the agent's paths to a scratch folder with fake tools).
$T = __DIR__;
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
        echo "  FAIL $what" . ($extra !== '' ? "\n       " . str_replace("\n", "\n       ", $extra) : '') . "\n";
    }
}
function section($t)
{
    echo "\n== $t\n";
}
function req($me, $action = '', $post = array(), $get = array())
{
    global $T;
    $env = 'DH_REQ=' . escapeshellarg(json_encode(array('me' => $me, 'action' => $action, 'post' => $post, 'get' => $get)));
    $out = shell_exec($env . ' php ' . escapeshellarg($T . '/run_req.php') . ' 2>&1');
    $j = json_decode((string) $out, true);
    if (!is_array($j)) {
        check(false, "page request ($action) ran without PHP problems", (string) $out);
        return array('msg' => array(), 'command' => '', 'html' => '', 'text' => '');
    }
    $j['text'] = implode(' / ', array_map(function ($m) { return $m[0] . ': ' . $m[1]; }, $j['msg']));
    return $j;
}
function daemon()
{
    global $T;
    $out = shell_exec('php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1; echo "exit=$?"');
    check(strpos($out, 'exit=0') !== false && strpos($out, 'PHP problem') === false, 'daemon run without PHP problems', $out);
    return $out;
}
function agent($args)
{
    global $T;
    $out = shell_exec('PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg(getenv('DH_AGENT')) . ' ' . $args . ' 2>&1; echo "exit=$?"');
    return $out;
}
function row($sql, $a = array())
{
    global $zdbh;
    $q = $zdbh->prepare($sql);
    $q->execute($a);
    return $q->fetch(PDO::FETCH_ASSOC);
}
function val($sql, $a = array())
{
    global $zdbh;
    $q = $zdbh->prepare($sql);
    $q->execute($a);
    return $q->fetchColumn();
}
function post($body)
{
    $c = curl_init(getenv('DH_WEB') . '/modules/docker_hosting/code/node.php');
    curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body));
    $r = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return array($code, (string) $r);
}
function sites()
{
    $f = getenv('SNODE_CADDY') . '/sentora/sites.caddy';
    return is_file($f) ? (string) file_get_contents($f) : '';
}
function calls()
{
    $f = getenv('FAKE_DIR') . '/calls.log';
    return is_file($f) ? (string) file_get_contents($f) : '';
}
function lastjob($cid)
{
    return row("SELECT * FROM x_dh_jobs WHERE jb_ct_fk = :c ORDER BY jb_id_pk DESC LIMIT 1", array(':c' => $cid));
}

chdir($T . '/root');
require $T . '/root/dryden/loader.inc.php';
require $T . '/root/modules/docker_hosting/code/dh.php';
$GLOBALS['DH_ME'] = 1;
// the panel: an administrator, two resellers, their clients, domains
$zdbh->exec("INSERT INTO x_groups (ug_id_pk) VALUES (1), (2), (3)");
$zdbh->exec("INSERT INTO x_modules (mo_folder_vc, mo_name_vc, mo_enabled_en) VALUES ('docker_hosting', 'Docker Hosting', 'true')");
$zdbh->exec("INSERT INTO x_settings (so_name_vc, so_value_tx) VALUES ('dns_hasupdates', '')");
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_reseller_fk, ac_group_fk) VALUES (1, 'zadmin', 1, 1), (2, 'res1', 1, 2), (3, 'client1', 2, 3), (4, 'client2', 2, 3), (5, 'res2', 1, 2), (6, 'client3', 5, 3)");
$zdbh->exec("INSERT INTO x_vhosts (vh_id_pk, vh_acc_fk, vh_name_vc) VALUES (10, 3, 'example.com'), (11, 3, 'nodns.com'), (12, 4, 'other.com'), (13, 3, 'www.example.com')");
$zdbh->exec("INSERT INTO x_dns (dn_acc_fk, dn_name_vc, dn_vhost_fk, dn_type_vc, dn_host_vc, dn_ttl_in, dn_target_vc) VALUES (3, 'example.com', 10, 'A', '@', 3600, '192.0.2.1'), (3, 'example.com', 10, 'CNAME', 'taken', 3600, '@'), (4, 'other.com', 12, 'A', '@', 3600, '192.0.2.2')");

section('install');
$out = shell_exec('php ' . escapeshellarg($T . '/run_install.php') . ' 2>&1');
check(strpos($out, 'tables and keys are there') !== false, 'install step: tables and keys', $out);
check((int) val("SELECT COUNT(*) FROM x_permissions") === 3, 'every group may open it');
$out = shell_exec('php ' . escapeshellarg($T . '/run_install.php') . ' upgrade.run 2>&1');
check(strpos($out, 'tables and keys are there') !== false && (int) val("SELECT COUNT(*) FROM x_permissions") === 3, 'upgrade step: the same, nothing doubled', $out);
check(strpos((string) val("SELECT st_value_tx FROM x_dh_settings WHERE st_name_vc = 'sign_sk'"), 'enc1:') === 0 && strpos((string) val("SELECT st_value_tx FROM x_dh_settings WHERE st_name_vc = 'box_kp'"), 'enc1:') === 0, 'the panel\'s secret keys are kept encrypted');

section('pages before anything');
$r = req(1);
check(strpos($r['html'], 'Nodes and apps') !== false && strpos($r['html'], 'no limit') !== false, 'administrator: nodes tab, no limit');
check(strpos($r['html'], 'has no domain yet') !== false && strpos($r['html'], 'module=domains') !== false && strpos($r['html'], 'start one below') === false, 'without a domain: told to add one (with links), not "start one below"');
$r = req(3);
check(strpos($r['html'], 'not part of your account') !== false && strpos($r['html'], 'Nodes and apps') === false, 'client without a share: told to ask; no nodes tab');
$r = req(3, 'AddNode', array('inName' => 'sneaky'));
check((int) val("SELECT COUNT(*) FROM x_dh_nodes") === 0, 'a client can not add a node');

section('adding and linking a node');
$r = req(1, 'AddNode', array('inName' => 'bad name;rm'));
check(strpos($r['text'], 'Give the node a name') !== false, 'a bad node name is refused');
$r = req(1, 'AddNode', array('inName' => 'node1'));
check(preg_match('/--key (v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+) --code (\d+\.[0-9a-f]{32})/', $r['command'], $m) === 1, 'install command with the panel\'s keys and a code', $r['command']);
check(strpos($r['html'], 'sentora2027-node-alma10') !== false && strpos($r['html'], '--panel &#039;https://panel.example&#039;') !== false, 'the command is shown with the installer and the panel\'s address');
$key = $m[1];
$code = $m[2];
check(val("SELECT nd_code_vc FROM x_dh_nodes") === hash('sha256', explode('.', $code)[1]), 'only the code\'s hash is kept');
$web = getenv('DH_WEB');
$o = agent("pair --panel $web --key $key --code " . explode('.', $code)[0] . '.' . str_repeat('0', 32));
check(strpos($o, 'Not linked') !== false && strpos($o, 'exit=1') !== false, 'a wrong code does not link', $o);
$bad = 'v1.' . sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING) . '.' . explode('.', $key)[2];
$o = agent("pair --panel $web --key $bad --code $code");
check(strpos($o, 'not signed by the panel') !== false && strpos($o, 'exit=1') !== false, 'a node given another panel key does not trust the reply', $o);
check((string) val("SELECT nd_signpk_vc FROM x_dh_nodes") !== '', '(that pairing did reach the panel: the code is used up)');
// a used code can not link again: a new one
$r = req(1, 'NodeCode', array('inId' => '1'));
check(strpos($r['text'], 'linked already') !== false, 'no new code for a linked node');
$zdbh->exec("UPDATE x_dh_nodes SET nd_signpk_vc = '', nd_boxpk_vc = ''");
$o = agent("pair --panel $web --key $key --code $code");
check(strpos($o, 'Not linked') !== false, 'a code works once');
$r = req(1, 'NodeCode', array('inId' => '1'));
preg_match('/--code (\d+\.[0-9a-f]{32})/', $r['command'], $m2);
$o = agent("pair --panel $web --key $key --code " . $m2[1]);
check(strpos($o, 'Linked to') !== false && strpos($o, 'exit=0') !== false, 'linked with a new code', $o);
$cfg = json_decode(file_get_contents(getenv('SNODE_ETC') . '/node.json'), true);
check((fileperms(getenv('SNODE_ETC') . '/node.json') & 0777) === 0600 && $cfg['node'] === 1, 'node keys kept by root only (0600)');
$zdbh->exec("UPDATE x_dh_nodes SET nd_codeexp_in = 1 WHERE nd_id_pk = 1");
$r = req(1, 'AddNode', array('inName' => 'node2'));
preg_match('/--code (\d+\.[0-9a-f]{32})/', $r['command'], $m3);
$zdbh->exec("UPDATE x_dh_nodes SET nd_codeexp_in = " . (time() - 1) . " WHERE nd_id_pk = 2");
$tmpEtc = getenv('DH_W') . '/node2';
$o = shell_exec('SNODE_ETC=' . $tmpEtc . ' ' . 'php ' . escapeshellarg(getenv('DH_AGENT')) . " pair --panel $web --key $key --code " . $m3[1] . ' 2>&1');
check(strpos((string) $o, 'Not linked') !== false, 'an expired code does not link');
$r = req(1, 'Node', array('inId' => '2', 'inRemove' => '1'));
check(strpos($r['text'], 'was removed') !== false, 'a node can be removed');

section('the node calls');
$o = agent('once');
check(strpos($o, 'exit=0') !== false && strpos($o, 'panel:') === false, 'first call', $o);
$n = row("SELECT * FROM x_dh_nodes WHERE nd_id_pk = 1");
$info = json_decode($n['nd_info_tx'], true);
check($n['nd_seen_in'] >= time() - 5 && $info['podman'] === '5.6.0' && $info['cpus'] > 0 && $info['selinux'] === 'Enforcing', 'node online, its report kept', $n['nd_info_tx']);
$r = req(1, '', array(), array('tab' => 'nodes'));
check(strpos($r['html'], '>online<') !== false && strpos($r['html'], 'public address: 127.0.0.1') !== false, 'the page shows it online, with its address');
preg_match("/const VERSION = '([^']+)'/", file_get_contents(getenv('DH_AGENT')), $vm);
check(strpos($r['html'], 'agent ' . $vm[1]) !== false, 'and the version of its agent on its row (' . $vm[1] . ')');
check(preg_match('/class="dh-fp">([A-Z2-9]{4}-[A-Z2-9]{4})</', $r['html'], $fpm) === 1 && $fpm[1] === dh::Fingerprint(), 'the page shows the panel\'s code for new nodes');
// a message played back, tampered with, or from another key
$k = dh::Keys();
$sk = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
$msg = dh_proto::Msg('poll', 1, array());
list($c) = post(dh_proto::Pack($msg, $sk, $k['box_pk']));
check($c === 400, 'a poll signed with another key is refused');
$nsk = snode_key($cfg['sign_sk'], SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
$good = dh_proto::Pack(dh_proto::Msg('poll', 1, array()), $nsk, $k['box_pk']);
list($c) = post($good);
check($c === 200, 'a fresh poll from the node is answered');
list($c) = post($good);
check($c === 400, 'the same message again is refused (played back)');
$old = dh_proto::Msg('poll', 1, array());
$old['ts'] = time() - 600;
list($c) = post(dh_proto::Pack($old, $nsk, $k['box_pk']));
check($c === 400, 'an old message is refused');
$raw = base64_decode($good);
$raw[60] = chr(ord($raw[60]) ^ 1);
list($c) = post(base64_encode($raw));
check($c === 400, 'a changed message is refused');
list($c, $body) = post('hello');
check($c === 400 && $body === '', 'anything else: an empty 400');
list($c) = post(dh_proto::Pack(dh_proto::Msg('poll', 2, array()), $nsk, $k['box_pk']));
check($c === 400, 'a removed node is refused');
function snode_key($b64, $len)
{
    return dh_proto::Bin($b64, $len);
}

section('shares down the tree');
$r = req(2);
check(strpos($r['html'], 'not part of your account') !== false && strpos($r['html'], 'Shares for my clients') === false, 'reseller without a share: no shares tab');
$r = req(1, 'Grant', array('inAcc' => '2', 'inCount' => '5', 'inCpu' => '4', 'inMem' => '4096', 'inDisk' => '20', 'inApps' => array('uptime-kuma', 'gitea', 'vaultwarden')));
check(strpos($r['text'], 'ok: The share was saved') !== false, 'administrator gives res1 a share', $r['text']);
$r = req(1, 'Grant', array('inAcc' => '3', 'inCount' => '5', 'inCpu' => '4', 'inMem' => '4096', 'inDisk' => '20', 'inApps' => array('gitea')));
check(strpos($r['text'], 'not one of your clients') !== false, 'only to its own direct clients');
$r = req(2, '', array(), array('tab' => 'shares'));
check(strpos($r['html'], 'client1') !== false && strpos($r['html'], 'client3') === false && strpos($r['html'], 'Nodes and apps') === false, 'res1 sees its own clients only');
$r = req(2, 'Grant', array('inAcc' => '3', 'inCount' => '6', 'inCpu' => '1', 'inMem' => '512', 'inDisk' => '5', 'inApps' => array('gitea')));
check(strpos($r['text'], 'more than you have free: check the containers') !== false, 'not more than it has', $r['text']);
$r = req(2, 'Grant', array('inAcc' => '3', 'inCount' => '2', 'inCpu' => '1.5', 'inMem' => '1024', 'inDisk' => '5', 'inApps' => array('uptime-kuma', 'gitea', 'nextcloud')));
check(strpos($r['text'], 'ok:') !== false, 'res1 gives client1 a part', $r['text']);
check(val("SELECT gr_apps_tx FROM x_dh_grants WHERE gr_acc_fk = 3") === 'uptime-kuma,gitea', 'apps: only those res1 was given (nextcloud dropped)');
$r = req(2, 'Grant', array('inAcc' => '4', 'inCount' => '3', 'inCpu' => '2.6', 'inMem' => '512', 'inDisk' => '1', 'inApps' => array('gitea')));
check(strpos($r['text'], 'check the CPU') !== false, 'what it gave counts: CPU over (4 - 1.5 < 2.6)', $r['text']);
$r = req(2, 'Grant', array('inAcc' => '4', 'inCount' => '1', 'inCpu' => '0.5', 'inMem' => '256', 'inDisk' => '1', 'inApps' => array('vaultwarden')));
check(strpos($r['text'], 'ok:') !== false, 'res1 gives client2 a part');
$r = req(3, 'Grant', array('inAcc' => '4', 'inCount' => '1', 'inCpu' => '0.1', 'inMem' => '128', 'inDisk' => '1', 'inApps' => array('gitea')));
check(strpos($r['text'], 'not one of your clients') !== false, 'a client can not give to another client');
$r = req(2, 'Grant', array('inAcc' => '3', 'inCount' => 'x'));
check(strpos($r['text'], 'as numbers') !== false, 'bad numbers are refused');
$r = req(1, 'Grant', array('inAcc' => '2', 'inCount' => '2', 'inCpu' => '1', 'inMem' => '1024', 'inDisk' => '5', 'inApps' => array('gitea')));
check(strpos($r['text'], 'Not less than the client uses now') !== false, 'a share can not shrink below what was given on', $r['text']);
$r = req(2);
check(strpos($r['html'], '3 free') !== false || strpos($r['html'], '>2 free<') !== false, 'res1 sees what it has free', substr(strip_tags($r['html']), 0, 400));

section('Docker plans (a seller\'s own packages)');
$r = req(2, 'Plan', array('inPlan' => '0', 'inName' => 'Small', 'inCount' => '2', 'inCpu' => '1.5', 'inMem' => '1024', 'inDisk' => '5', 'inApps' => array('uptime-kuma', 'gitea', 'nextcloud')));
$small = (int) val("SELECT pl_id_pk FROM x_dh_plans WHERE pl_name_vc = 'Small'");
check($small > 0 && strpos($r['text'], 'Plan "Small" saved') !== false && val("SELECT pl_apps_tx FROM x_dh_plans WHERE pl_id_pk = $small") === 'uptime-kuma,gitea', 'res1 makes a plan (only apps it has)', $r['text']);
$r = req(2, 'Plan', array('inPlan' => '0', 'inName' => 'small', 'inCount' => '1', 'inCpu' => '1', 'inMem' => '512', 'inDisk' => '1', 'inApps' => array('gitea')));
check(strpos($r['text'], 'with that name already') !== false, 'not two plans with the same name');
$r = req(2, 'Plan', array('inPlan' => '0', 'inName' => '<b>x', 'inCount' => '1', 'inCpu' => '1', 'inMem' => '512', 'inDisk' => '1', 'inApps' => array('gitea')));
check(strpos($r['text'], 'plain characters') !== false, 'a name with markup is refused');
$r = req(2, 'Grant', array('inAcc' => '3', 'inPlan' => (string) $small));
$gr = row("SELECT * FROM x_dh_grants WHERE gr_acc_fk = 3");
check(strpos($r['text'], 'on that plan now') !== false && (int) $gr['gr_plan_fk'] === $small && (int) $gr['gr_mem_in'] === 1024 && (int) $gr['gr_cpu_in'] === 150, 'client1 is put on it: its share is the plan\'s', $r['text']);
$r = req(2, '', array(), array('tab' => 'shares'));
check(strpos($r['html'], 'Docker plans') !== false && strpos($r['html'], '1 client') !== false && preg_match('/client1.*?dh-pill is-ok">Small</s', $r['html']) === 1, 'the shares tab shows the plan and who is on it');
$r = req(2, 'Plan', array('inPlan' => (string) $small, 'inName' => 'Small', 'inCount' => '2', 'inCpu' => '1.5', 'inMem' => '1536', 'inDisk' => '5', 'inApps' => array('uptime-kuma', 'gitea')));
check((int) val("SELECT gr_mem_in FROM x_dh_grants WHERE gr_acc_fk = 3") === 1536 && strpos($r['text'], 'Its clients follow it') !== false, 'the plan changes: its client follows', $r['text']);
$r = req(2, 'Plan', array('inPlan' => (string) $small, 'inName' => 'Small', 'inCount' => '2', 'inCpu' => '1.5', 'inMem' => '4000', 'inDisk' => '5', 'inApps' => array('uptime-kuma', 'gitea')));
check((int) val("SELECT gr_mem_in FROM x_dh_grants WHERE gr_acc_fk = 3") === 1536 && strpos($r['text'], 'keep their amounts for now: client1 (That is more than you have free: check the memory') !== false,
    'more than res1 has free: client1 keeps its amounts, and is named', $r['text']);
req(2, 'Plan', array('inPlan' => (string) $small, 'inName' => 'Small', 'inCount' => '2', 'inCpu' => '1.5', 'inMem' => '1024', 'inDisk' => '5', 'inApps' => array('uptime-kuma', 'gitea')));
check((int) val("SELECT gr_mem_in FROM x_dh_grants WHERE gr_acc_fk = 3") === 1024, '(and back)');
$r = req(5, 'Grant', array('inAcc' => '6', 'inPlan' => (string) $small));
check(strpos($r['text'], 'no Docker Hosting share') !== false || strpos($r['text'], 'No such plan') !== false, 'another reseller can not use res1\'s plan', $r['text']);
$r = req(3, 'Plan', array('inPlan' => (string) $small, 'inName' => 'Mine', 'inCount' => '9', 'inCpu' => '9', 'inMem' => '9000', 'inDisk' => '90', 'inApps' => array('gitea')));
check(val("SELECT pl_name_vc FROM x_dh_plans WHERE pl_id_pk = $small") === 'Small', 'a client can not change res1\'s plan', $r['text']);
$r = req(2, 'PlanRemove', array('inPlan' => (string) $small));
$gr = row("SELECT * FROM x_dh_grants WHERE gr_acc_fk = 3");
check(strpos($r['text'], 'keep their amounts') !== false && (int) $gr['gr_plan_fk'] === 0 && (int) $gr['gr_mem_in'] === 1024 && $gr['gr_apps_tx'] === 'uptime-kuma,gitea', 'a plan removed: its client keeps its amounts as its own', $r['text']);

section('starting containers');
$r = req(3);
check(strpos($r['html'], 'Start an app') !== false && strpos($r['html'], 'Uptime Kuma') !== false && strpos($r['html'], 'Vaultwarden') === false, 'client1 sees its own apps only');
check(strpos($r['html'], 'other.com') === false && strpos($r['html'], 'example.com') !== false, 'only its own domains');
$base = array('inApp' => 'uptime-kuma', 'inLabel' => 'Status', 'inName' => 'kuma', 'inDomain' => '10', 'inCpu' => '0.5', 'inMem' => '256', 'inDisk' => '1', 'inDns' => '1');
$r = req(3, 'Deploy', array_merge($base, array('inApp' => 'vaultwarden')));
check(strpos($r['text'], 'not one you may start') !== false, 'an app not given is refused');
$r = req(3, 'Deploy', array_merge($base, array('inDomain' => '12')));
check(strpos($r['text'], 'your own domains') !== false, 'another account\'s domain is refused');
$r = req(3, 'Deploy', array_merge($base, array('inName' => 'taken')));
check(strpos($r['text'], 'already has a DNS record') !== false, 'a name with a record (CNAME) is refused');
$r = req(3, 'Deploy', array_merge($base, array('inName' => 'www')));
check(strpos($r['text'], 'website on this panel') !== false, 'a name that is a website here is refused');
$r = req(3, 'Deploy', array_merge($base, array('inName' => '-bad')));
check(strpos($r['text'], 'letters, digits and hyphens') !== false, 'a bad name is refused');
$r = req(3, 'Deploy', array_merge($base, array('inMem' => '64')));
check(strpos($r['text'], 'at least 128 MB') !== false, 'too little memory for the app is refused');
$r = req(3, 'Deploy', array_merge($base, array('inMem' => '2048')));
check(strpos($r['text'], 'more than your share') !== false, 'over the share is refused');
$r = req(3, 'Deploy', array_merge($base, array('inDomain' => '11')));
check(strpos($r['text'], 'no DNS records yet') !== false, 'DNS for a domain without DNS is refused (does not make a zone)');
$r = req(3, 'Deploy', $base);
check(strpos($r['text'], 'ok: Starting Uptime Kuma at kuma.example.com') !== false, 'Uptime Kuma started at kuma.example.com', $r['text']);
$c1 = row("SELECT * FROM x_dh_containers WHERE dc_domain_vc = 'kuma.example.com'");
check($c1 && (int) $c1['dc_node_fk'] === 1 && (int) $c1['dc_port_in'] === 20000 && $c1['dc_state_vc'] === 'creating', 'placed on node1, port 20000');
$dns = row("SELECT * FROM x_dns WHERE dn_id_pk = :d", array(':d' => (int) $c1['dc_dns_fk']));
check($dns && $dns['dn_type_vc'] === 'A' && $dns['dn_host_vc'] === 'kuma' && $dns['dn_target_vc'] === '127.0.0.1' && (int) $dns['dn_acc_fk'] === 3 && (int) $dns['dn_vhost_fk'] === 10, 'DNS record added as DNS Manager does (A kuma -> the node)');
check(ctrl_options::GetSystemOption('dns_hasupdates') === '10', 'the domain is on dns_hasupdates (the daemon rewrites its zone)');
$j = lastjob((int) $c1['dc_id_pk']);
check($j['jb_action_vc'] === 'create' && strpos($j['jb_data_tx'], 'enc1:') === 0, 'a create job, its data encrypted');
$r = req(3, 'Deploy', array_merge($base, array('inName' => 'kuma2', 'inDns' => '')));
check(strpos($r['text'], 'ok:') !== false, 'a second one without DNS');
$c2 = row("SELECT * FROM x_dh_containers WHERE dc_domain_vc = 'kuma2.example.com'");
check((int) $c2['dc_dns_fk'] === 0 && (int) $c2['dc_port_in'] === 20001, 'no record, next port');
$r = req(3, 'Deploy', array_merge($base, array('inName' => 'kuma3')));
check(strpos($r['text'], 'no containers left') !== false, 'its count is used up');
$r = req(3);
check(strpos($r['html'], 'add an A record for kuma2.example.com pointing to 127.0.0.1') !== false, 'told to add the record itself where it was not added (with the node\'s address)');
check(strpos($r['html'], 'Web address of the app') !== false && strpos($r['html'], 'id="dh-url"') !== false, 'the form explains the address and shows where the app will be');

section('the node does the jobs');
$o = agent('once');
check(strpos($o, 'create container ' . $c1['dc_id_pk'] . ': ok') !== false && strpos($o, 'create container ' . $c2['dc_id_pk'] . ': ok') !== false, 'the agent created both', $o);
$cl = calls();
check(strpos($cl, 'useradd --create-home --home-dir ' . getenv('SNODE_HOME') . '/sdh3 --shell /sbin/nologin') !== false && substr_count($cl, 'useradd') === 1, 'one Linux user for the customer (sdh3), no login', $cl);
check(strpos(file_get_contents(getenv('SNODE_SUBID') . '/subuid'), 'sdh3:100000:65536') !== false, 'its own subordinate IDs');
check(strpos($cl, 'loginctl enable-linger sdh3') !== false && strpos($cl, 'systemctl --user enable podman-restart.service (as sdh3)') !== false, 'lingering, and its containers start again after a restart');
$args = file(getenv('FAKE_DIR') . '/podman/sdh3/c/sdh-' . $c1['dc_id_pk'] . '/args', FILE_IGNORE_NEW_LINES);
$a = implode(' ', $args);
check(strpos($a, '--cpus 0.50 --memory 256m --memory-swap 256m --pids-limit 1024') !== false && strpos($a, '--publish 127.0.0.1:20000:3001') !== false
    && strpos($a, '--volume sdh-' . $c1['dc_id_pk'] . '-0:/app/data') !== false && strpos($a, 'no-new-privileges') !== false && end($args) === 'docker.io/louislam/uptime-kuma:1', 'podman create: limits, 127.0.0.1 only, own volume, no new privileges', $a);
check(strpos($cl, 'podman[root]') === false || strpos($cl, 'podman[root] --version') !== false, 'containers are never run by root', $cl);
$s = sites();
check(strpos($s, "kuma.example.com {\n\tencode zstd gzip\n\tbasic_auth {\n\t\tsetup \$2y\$") !== false && strpos($s, 'reverse_proxy 127.0.0.1:20000') !== false, 'Caddy: the address behind the setup password', $s);
check(strpos($cl, 'caddy validate') !== false && strpos($cl, 'systemctl reload caddy') !== false, 'Caddy checked, then reloaded');
$o = agent('once');
$c1 = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']));
check($c1['dc_state_vc'] === 'running' && lastjob((int) $c1['dc_id_pk'])['jb_state_vc'] === 'done', 'the panel knows it runs', json_encode($c1));
$r = req(3);
preg_match('/password <code>([A-Za-z0-9]{20})<\/code>/', $r['html'], $pm);
preg_match('/setup (\$2y\$\S+)/', $s, $hm);
check(!empty($pm[1]) && !empty($hm[1]) && password_verify($pm[1], $hm[1]), 'the page shows the setup password that opens it', substr(strip_tags($r['html']), 0, 600));
check(strpos($r['html'], '>Running<') !== false, 'shown running');
$r = req(4, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'stop'));
check(strpos($r['text'], 'No such container') !== false, 'another client can not touch it');
$r = req(2, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'delete'));
check(strpos($r['text'], 'No such container') !== false, 'nor can its reseller');

section('actions');
req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'stop'));
check(row("SELECT dc_state_vc FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']))['dc_state_vc'] === 'stopping', 'stopping...');
agent('once');
check(trim(file_get_contents(getenv('FAKE_DIR') . '/podman/sdh3/c/sdh-' . $c1['dc_id_pk'] . '/state')) === 'exited', 'stopped on the node');
agent('once');
check(row("SELECT dc_state_vc FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']))['dc_state_vc'] === 'stopped', 'shown stopped');
req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'start'));
agent('once');
agent('once');
check(row("SELECT dc_state_vc FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']))['dc_state_vc'] === 'running', 'started again');
$r = req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'logs'));
check(strpos($r['html'], 'Waiting for the node') !== false, 'logs: waiting for the node');
agent('once');
$r = req(3, '', array(), array('logs' => (string) $c1['dc_id_pk']));
check(strpos($r['html'], 'fake log of sdh-' . $c1['dc_id_pk']) !== false, 'logs shown');
$r = req(3, '', array(), array('settings' => (string) $c1['dc_id_pk']));
check(strpos($r['html'], 'dh-settings"') !== false && strpos($r['html'], 'name="inCpu"') !== false && strpos($r['html'], 'Nightly updates') !== false
    && preg_match('#name="inWhat" value="public"><label class="sf-switch"><input type="checkbox"#', $r['html']) === 1 && strpos($r['html'], 'Open to everyone') !== false
    && strpos($r['html'], 'Password protected') !== false && strpos($r['html'], '>Running<') !== false, 'Settings opens under the row (size); switches for nightly updates and open to everyone; status in words');
$set = array('inId' => (string) $c1['dc_id_pk'], 'inCpu' => '0.5', 'inMem' => '256', 'inDisk' => '1');
$r = req(3, 'CtSettings', $set);
check(strpos($r['text'], 'Nothing changed') !== false, 'saved as it was: nothing changed');
$r = req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'public'));
check(strpos($r['text'], 'ok:') !== false, 'switching on "Open to everyone"', $r['text']);
$r = req(4, 'CtSettings', $set);
check(strpos($r['text'], 'No such container') !== false, '(another client can not change its settings)');
agent('once');
$s = sites();
check(strpos($s, 'kuma.example.com {') !== false && substr_count($s, 'basic_auth') === 1, 'open to the public: no setup password for it (the other still has one)', $s);
$r = req(3);
check(strpos($r['html'], 'Open to everyone: no password') !== false && preg_match('#name="inWhat" value="private"><label class="sf-switch"><input type="checkbox"[^>]* checked#', $r['html']) === 1, 'shown as open to everyone (no password), its switch on');
req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'private'));
agent('once');
check(substr_count(sites(), 'basic_auth') === 2, 'closed again: behind a (new) setup password');
$r = req(3, 'CtSettings', array('inId' => (string) $c1['dc_id_pk'], 'inCpu' => '1', 'inMem' => '900', 'inDisk' => '2'));
check(strpos($r['text'], 'more than your share') !== false, 'limits over the share are refused');
$r = req(3, 'CtSettings', array('inId' => (string) $c1['dc_id_pk'], 'inCpu' => '0.75', 'inMem' => '512', 'inDisk' => '2'));
check(strpos($r['text'], 'ok:') !== false, 'limits within the share', $r['text']);
agent('once');
check(strpos((string) @file_get_contents(getenv('FAKE_DIR') . '/podman/sdh3/c/sdh-' . $c1['dc_id_pk'] . '/updates'), 'update --cpus 0.75 --memory 512m --memory-swap 512m') !== false, 'podman update with the new limits');

// an agent from before 105 sends its message bare (text/plain): still understood
$oldAgent = getenv('DH_W') . '/old-agent.php';
$src = file_get_contents(getenv('DH_AGENT'));
// (the same as an agent from before 105: only bare messages)
file_put_contents($oldAgent, str_replace('static $bare = false;', 'static $bare = true;', $src));
check(strpos(file_get_contents($oldAgent), 'static $bare = true;') !== false, '(an agent that sends bare messages only, as before 105)');
$o = shell_exec('PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg($oldAgent) . ' once 2>&1; echo "exit=$?"');
check(strpos($o, 'exit=0') !== false && strpos($o, 'HTTP 400') === false, 'an agent from before 105 (its message sent bare) is still understood', $o);
section('the node checks what it is given');
$cid = (int) $c2['dc_id_pk'];
dh::Queue(1, $cid, 'create', array('cid' => $cid, 'user' => 'sdh3', 'image' => 'docker.io/x/y;rm -rf /', 'port' => 80, 'hostport' => 20001, 'env' => array(), 'volumes' => array(),
    'cpu' => 50, 'mem' => 256, 'domain' => 'kuma2.example.com', 'public' => 0, 'auth' => ''));
dh::Queue(1, $cid, 'logs', array('cid' => $cid, 'user' => '../root'));
dh::Queue(1, $cid, 'create', array('cid' => $cid, 'user' => 'sdh3', 'image' => 'docker.io/x/y:1', 'port' => 80, 'hostport' => 20001, 'env' => array("A\nB" => 'x'), 'volumes' => array(),
    'cpu' => 50, 'mem' => 256, 'domain' => 'kuma2.example.com', 'public' => 0, 'auth' => ''));
dh::Queue(1, $cid, 'create', array('cid' => $cid, 'user' => 'sdh3', 'image' => 'docker.io/x/y:1', 'port' => 80, 'hostport' => 20001, 'env' => array(), 'volumes' => array('/../../etc'),
    'cpu' => 50, 'mem' => 256, 'domain' => 'kuma2.example.com', 'public' => 0, 'auth' => ''));
dh::Queue(1, $cid, 'proxy', array('cid' => $cid, 'user' => 'sdh3', 'domain' => "x.com {\n}\n:80", 'hostport' => 20001, 'public' => 1, 'auth' => ''));
dh::Queue(1, $cid, 'stop', array('cid' => $cid, 'user' => 'sdh4'));
dh::Queue(1, $cid, 'format', array('cid' => $cid, 'user' => 'sdh3'));
$o = agent('once');
check(substr_count($o, 'failed: refused:') === 7, 'bad image, user, environment, volume, domain, another user\'s container, unknown job: all refused', $o);
check((int) val("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_state_vc = 'failed' AND jb_result_tx LIKE 'refused:%'") === 7, 'the panel has the refusals');
agent('once');
$c2now = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $cid));
check($c2now['dc_note_vc'] !== '' && strpos($c2now['dc_note_vc'], 'failed: refused') !== false, 'the container shows the failure', json_encode($c2now));
check(strpos(sites(), 'x.com {') === false, 'Caddy\'s file was not touched by the bad domain');

section('a job sent twice, a failed reload, results kept');
$jid = dh::Queue(1, $cid, 'restart', dh::Who($c2now));
agent('once');
$zdbh->prepare("UPDATE x_dh_jobs SET jb_state_vc = 'sent', jb_sent_in = :t WHERE jb_id_pk = :j")->execute(array(':t' => time() - 1000, ':j' => $jid));
$before = substr_count(calls(), 'restart --time 20 sdh-' . $cid);
$o = agent('once');
check(substr_count(calls(), 'restart --time 20 sdh-' . $cid) === $before && row("SELECT jb_state_vc FROM x_dh_jobs WHERE jb_id_pk = :j", array(':j' => $jid))['jb_state_vc'] === 'done', 'a job sent again is answered without doing it twice');
touch(getenv('FAKE_DIR') . '/caddy-fail');
$keep = sites();
req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'public'));
$o = agent('once');
check(strpos($o, 'proxy') !== false && strpos($o, 'failed') !== false && sites() === $keep, 'Caddy refuses: its old sites are put back', $o);
unlink(getenv('FAKE_DIR') . '/caddy-fail');

section('app updates');
// a newer version of the app's image is published
$imgFile = getenv('FAKE_DIR') . '/registry/docker.io_louislam_uptime-kuma_1';
@mkdir(getenv('FAKE_DIR') . '/registry', 0755, true);
file_put_contents($imgFile, 'id-kuma-2 sha256:' . str_repeat('2', 64));
$r = req(1, 'NodeAct', array('inId' => '1', 'inWhat' => 'check-updates'));
check(strpos($r['text'], 'looks for updates now') !== false, 'administrator asks the node to look for updates');
$o = agent('once');
check(strpos($o, 'check-updates') !== false && strpos(calls(), 'skopeo inspect --no-tags --format {{.Digest}} docker://docker.io/louislam/uptime-kuma:1') !== false, 'the node asks the registry (skopeo, nothing downloaded)', $o);
agent('once');
$cu = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']));
check((int) $cu['dc_update_in'] === 1, 'the panel knows a newer version is out');
$r = req(3);
check(strpos($r['html'], 'A newer version is out') !== false && strpos($r['html'], "name=\"inWhat\" value=\"update\"") !== false, 'the owner sees "update available" and an Update button');
$r = req(4, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'update'));
check(strpos($r['text'], 'No such container') !== false, 'nobody else can update it');
$r = req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'update'));
check(strpos($r['text'], 'Updating it') !== false, 'the owner presses Update');
$r = req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'update'));
check(strpos($r['text'], 'being updated already') !== false, 'not twice at once');
$o = agent('once');
$cdir = getenv('FAKE_DIR') . '/podman/sdh3/c/sdh-' . $c1['dc_id_pk'];
check(strpos($o, 'update container ' . $c1['dc_id_pk'] . ': ok') !== false && trim(file_get_contents($cdir . '/imageid')) === 'id-kuma-2' && !is_dir($cdir . '-old'), 'the node swapped in the new version (the old one removed)', $o);
check(is_dir(getenv('FAKE_DIR') . '/podman/sdh3/v/sdh-' . $c1['dc_id_pk'] . '-0'), 'its data (volume) stayed');
$a2 = implode(' ', file($cdir . '/args', FILE_IGNORE_NEW_LINES));
check(strpos($a2, '--cpus 0.75 --memory 512m') !== false && strpos($a2, '--publish 127.0.0.1:20000:3001') !== false, 'with the same limits and port', $a2);
agent('once');
$cu = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']));
check((int) $cu['dc_update_in'] === 0 && $cu['dc_state_vc'] === 'running', 'the panel: running, no update waiting');
// the next version crashes at once: the old one comes back
file_put_contents($imgFile, 'id-kuma-3 sha256:' . str_repeat('3', 64));
file_put_contents(getenv('FAKE_DIR') . '/crash-id', 'id-kuma-3');
req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'update'));
$o = agent('once');
check(strpos($o, 'failed: the new version stopped after starting') !== false && trim(file_get_contents($cdir . '/imageid')) === 'id-kuma-2' && trim(file_get_contents($cdir . '/state')) === 'running', 'a new version that crashes: the old one runs again', $o);
agent('once');
$cu = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']));
check(strpos($cu['dc_note_vc'], 'old version runs again') !== false, 'the owner is told', json_encode($cu));
unlink(getenv('FAKE_DIR') . '/crash-id');
$r = req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'auto-on'));
check((int) row("SELECT dc_autoupd_in FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']))['dc_autoupd_in'] === 1, 'automatic updates switched on');
check(preg_match('#name="inWhat" value="auto-off"><label class="sf-switch"><input type="checkbox" onchange="this.form.submit\(\)" checked#', $r['html']) === 1 && strpos($r['html'], 'Nightly updates') !== false, 'a "Nightly updates" switch on the row, now on (flipping it turns it off)');
req(3, 'CtSettings', array('inId' => (string) $c1['dc_id_pk'], 'inCpu' => '0.75', 'inMem' => '512', 'inDisk' => '2'));
check((int) row("SELECT dc_autoupd_in FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']))['dc_autoupd_in'] === 1, '(saving Settings leaves nightly updates as they are)');
$zdbh->prepare("UPDATE x_dh_containers SET dc_update_in = 1, dc_state_vc = 'running', dc_note_vc = '' WHERE dc_id_pk = :c")->execute(array(':c' => $c1['dc_id_pk']));
$d = shell_exec('DH_TEST_HOUR=14 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'automatic updates') === false, 'not in the day');
$d = shell_exec('DH_TEST_HOUR=3 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'updating kuma.example.com (automatic updates)') !== false, 'at night it updates by itself', (string) $d);
$o = agent('once');
check(strpos($o, 'update container ' . $c1['dc_id_pk'] . ': ok') !== false && trim(file_get_contents($cdir . '/imageid')) === 'id-kuma-3', 'updated by itself', $o);
agent('once');

section("an app's own settings (Advanced)");
$cid = (int) $c1['dc_id_pk'];
agent('once');
agent('once');
$zdbh->prepare("UPDATE x_dh_containers SET dc_state_vc = 'running', dc_note_vc = '' WHERE dc_id_pk = :c")->execute(array(':c' => $cid));
$r = req(3, '', array(), array('settings' => (string) $cid));
check(strpos($r['html'], 'Advanced: the app&#039;s own settings') !== false && strpos($r['html'], 'name="inEnv"') !== false && strpos($r['html'], 'some apps read a setting only the first time') !== false, 'Settings has an Advanced part: the app\'s own settings, the owner\'s lines, and what to know');
$r = req(3, 'CtEnv', array('inId' => (string) $cid, 'inEnv' => "TZ=America/Chicago\nNOT A SETTING"));
check(strpos($r['text'], 'one line NAME=value') !== false && strpos($r['html'], 'NOT A SETTING') !== false, 'a bad line is refused (what was typed stays in the box)');
$r = req(4, 'CtEnv', array('inId' => (string) $cid, 'inEnv' => "TZ=UTC"));
check(strpos($r['text'], 'No such app') !== false, 'another client can not change them');
$before = calls();
$r = req(3, 'CtEnv', array('inId' => (string) $cid, 'inEnv' => "TZ=America/Chicago\nAPP_URL=https://{domain}\n"));
check(strpos($r['text'], 'ok: Saved') !== false && row("SELECT dc_state_vc FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $cid))['dc_state_vc'] === 'applying', 'saved: the app takes its new settings', $r['text']);
check(strpos((string) row("SELECT dc_env_tx FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $cid))['dc_env_tx'], 'enc1:') === 0, 'kept encrypted');
check(strpos(req(3)['html'], 'Taking its new settings') !== false, 'shown as "Taking its new settings"');
$imgBefore = trim(file_get_contents($cdir . '/imageid'));
$o = agent('once');
$args = file($cdir . '/args', FILE_IGNORE_NEW_LINES);
check(strpos($o, 'recreate container ' . $cid . ': ok') !== false && in_array('TZ=America/Chicago', $args, true) && in_array('APP_URL=https://kuma.example.com', $args, true), 'the node made it again with them', $o . implode(' ', $args));
check(trim(file_get_contents($cdir . '/imageid')) === $imgBefore && strpos(substr(calls(), strlen($before)), ' pull ') === false && trim(file_get_contents($cdir . '/state')) === 'running', 'the same version (nothing downloaded), running, its data kept');
agent('once');
$cu = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $cid));
check($cu['dc_state_vc'] === 'running' && $cu['dc_envprev_tx'] === null, 'the panel: running with them');
$r = req(3, 'CtEnv', array('inId' => (string) $cid, 'inEnv' => "TZ=America/Chicago\nAPP_URL=https://{domain}\n"));
check(strpos($r['text'], 'Nothing changed') !== false, 'saved unchanged: nothing to do');
// settings the app does not start with: back to the ones before
file_put_contents(getenv('FAKE_DIR') . '/crash-env', 'TZ=Mars/Olympus');
req(3, 'CtEnv', array('inId' => (string) $cid, 'inEnv' => "TZ=Mars/Olympus"));
$o = agent('once');
check(strpos($o, 'failed') !== false && strpos($o, 'runs again with its old settings') !== false && in_array('TZ=America/Chicago', file($cdir . '/args', FILE_IGNORE_NEW_LINES), true), 'it does not start with them: the node puts the app back as it was', $o);
unlink(getenv('FAKE_DIR') . '/crash-env');
agent('once');
$cu = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $cid));
check($cu['dc_state_vc'] === 'running' && strpos($cu['dc_note_vc'], 'new settings did not work') !== false && dh::OwnerEnv($cu) === array('TZ' => 'America/Chicago', 'APP_URL' => 'https://{domain}'), 'the panel: running, told so, and the settings before are back', json_encode($cu));
// a node with an older agent can not do it yet
$ni = json_decode((string) val("SELECT nd_info_tx FROM x_dh_nodes WHERE nd_id_pk = 1"), true);
$ni['agent'] = '105';
$zdbh->prepare("UPDATE x_dh_nodes SET nd_info_tx = :i WHERE nd_id_pk = 1")->execute(array(':i' => json_encode($ni)));
$r = req(3, 'CtEnv', array('inId' => (string) $cid, 'inEnv' => "TZ=UTC"));
check(strpos($r['text'], 'older agent') !== false && dh::OwnerEnv(row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $cid)))['TZ'] === 'America/Chicago', 'a node with an agent before 106: told to update it first, nothing changed', $r['text']);
agent('once');
$c1 = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $cid));

section('the node from the panel');
foreach (array('node-update' => 'updates itself', 'sys-security' => 'security updates', 'reboot' => 'restarts') as $w => $txt) {
    $r = req(1, 'NodeAct', array('inId' => '1', 'inWhat' => $w));
    check(strpos($r['text'], $txt) !== false, 'administrator: ' . $w, $r['text']);
}
$r = req(1, 'NodeAct', array('inId' => '1', 'inWhat' => 'reboot'));
check(strpos($r['text'], 'asked already') !== false, 'not twice while waiting');
$r = req(3, 'NodeAct', array('inId' => '1', 'inWhat' => 'reboot'));
check((int) val("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_action_vc = 'reboot'") === 1, 'a client can not');
$o = agent('once');
$cl = calls();
check(strpos($cl, 'systemd-run --unit sentora-node-sentoranodeupdate-') !== false && strpos($cl, '--on-active=5 /usr/local/sbin/sentora-node-update --yes') !== false, 'the node runs sentora-node-update --yes (on its own, it restarts the agent)', $cl);
check(strpos($cl, '/usr/local/sbin/sentora-node-sysupdate --security') !== false, 'the node installs its security updates (sentora-node-sysupdate)');
check(strpos($cl, 'shutdown -r +1') !== false, 'the node restarts in a minute');
agent('once');
check((int) val("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_ct_fk = 0 AND jb_state_vc = 'done'") >= 4, 'the panel has their results');
// what the node's system reports
file_put_contents(getenv('SNODE_STATE') . '/sysupdate.json', json_encode(array('updates' => 7, 'security' => 2, 'reboot' => 1, 'checked_at' => time(), 'last_at' => time() - 60, 'last_ok' => 1, 'last_kind' => 'security', 'running' => '')));
agent('once');
$r = req(1, '', array(), array('tab' => 'nodes', 'manage' => '1'));
check(strpos($r['html'], '7 updates waiting, 2 of them security fixes') !== false && strpos($r['html'], 'A restart is needed') !== false, 'the page shows the waiting updates and that a restart is needed');
check(strpos($r['html'], 'Needs a look') !== false && strpos($r['html'], 'has 2 security updates waiting') !== false, 'and lists it under "Needs a look"');
$r = req(1);
check(strpos($r['html'], 'Needs a look') !== false && strpos($r['html'], 'Needs a look') < strpos($r['html'], 'class="dh-tabs"') && strpos($r['html'], 'tab=nodes">Nodes and apps</a>') !== false, 'administrators see it at the top of every tab (above the tabs), with a link to Nodes and apps');
$r = req(3);
check(strpos($r['html'], 'Needs a look') === false, 'clients do not see it');
$zdbh->exec("UPDATE x_accounts SET ac_email_vc = 'admin@example.com' WHERE ac_id_pk = 1");
@unlink(getenv('DH_MAIL'));
daemon();
$mail = (string) @file_get_contents(getenv('DH_MAIL'));
check(strpos($mail, 'To: admin@example.com') !== false && strpos($mail, 'has 2 security updates waiting') !== false && strpos($mail, 'needs a restart') !== false, 'the administrators are mailed', $mail);
@unlink(getenv('DH_MAIL'));
daemon();
check(!is_file(getenv('DH_MAIL')), 'not again on the next run (only again after a day)');
$d = shell_exec('DH_TEST_HOUR=3 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'installs its security updates') === false, 'security updates wait while automatic is off');
req(1, 'NodeAct', array('inId' => '1', 'inWhat' => 'autosec', 'inAutosec' => '1'));
check(preg_match('#name="inAutosec" value="0"><label class="sf-switch"><input type="checkbox" onchange="this.form.submit\(\)" checked#', req(1, '', array(), array('tab' => 'nodes', 'manage' => '1'))['html']) === 1, 'the node: a "Nightly security updates" switch, now on');
$d = shell_exec('DH_TEST_HOUR=3 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'installs its security updates') !== false, 'with automatic on, they install at night', (string) $d);
$d = shell_exec('DH_TEST_HOUR=3 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'installs its security updates') === false, 'once a night');
// node updates: flagged with Update now; installed at night only while "Nightly node updates" is on (on by default)
dh::Set('latest_agent', '9999');
dh::Set('latest_agent_at', (string) time());
$r = req(1, '', array(), array('tab' => 'nodes'));
check(strpos($r['html'], 'Update available: 9999') !== false, 'a node behind the newest agent is flagged on its row');
check(strpos($r['html'], 'Update now') !== false && strpos($r['html'], 'name="inAutoagent"') === false, '"Update now" is on the row itself; Manage opens under the row only when pressed');
req(1, 'NodeAct', array('inId' => '1', 'inWhat' => 'node-update'));
$h = req(1, '', array(), array('tab' => 'nodes'))['html'];
check(strpos($h, '>Queued</button>') !== false && strpos($h, 'Update now</button>') === false, 'after Update now the button says Queued (as in Repo Browser)');
$zdbh->exec("UPDATE x_dh_jobs SET jb_state_vc = 'sent' WHERE jb_action_vc = 'node-update' AND jb_state_vc = 'queued'");
$h = req(1, '', array(), array('tab' => 'nodes', 'manage' => '1'))['html'];
check(strpos($h, '>In progress</button>') !== false && strpos($h, 'Look for updates now</button>') !== false, 'once the node took it: In progress; the other buttons stay as they are', implode(' | ', array_map('strip_tags', preg_match_all('#<button[^>]*>.*?</button>#s', $h, $bm) ? $bm[0] : array())));
$zdbh->exec("DELETE FROM x_dh_jobs WHERE jb_action_vc = 'node-update' AND jb_state_vc = 'sent'");
check(strpos(req(1, '', array(), array('tab' => 'nodes'))['html'], 'Update now') !== false, 'and back to Update now once it is no longer waiting');
$r = req(1, '', array(), array('tab' => 'nodes', 'manage' => '1'));
check(preg_match('#name="inAutoagent" value="0"><label class="sf-switch"><input type="checkbox" onchange="this.form.submit\(\)" checked#', $r['html']) === 1, '"Nightly node updates" is on by default');
$d = shell_exec('DH_TEST_HOUR=14 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'updates to agent') === false, 'not during the day');
$d = shell_exec('DH_TEST_HOUR=2 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'updates to agent 9999') !== false, 'at night the node is asked to update', (string) $d);
check((int) $zdbh->query("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_node_fk = 1 AND jb_action_vc = 'node-update' AND jb_state_vc = 'queued'")->fetchColumn() === 1, 'one node-update job queued');
$d = shell_exec('DH_TEST_HOUR=2 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'updates to agent') === false, 'once a night');
$zdbh->exec("DELETE FROM x_dh_jobs WHERE jb_action_vc = 'node-update' AND jb_state_vc = 'queued'");
dh::Set('autoagent_1', '0');
req(1, 'NodeAct', array('inId' => '1', 'inWhat' => 'autoagent', 'inAutoagent' => '0'));
$d = shell_exec('DH_TEST_HOUR=2 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'updates to agent') === false, 'switched off, it waits for "Update now"');
check((int) $zdbh->query("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_action_vc = 'node-update' AND jb_state_vc = 'queued'")->fetchColumn() === 0, 'and nothing is queued');
req(1, 'NodeAct', array('inId' => '1', 'inWhat' => 'autoagent', 'inAutoagent' => '1'));
dh::Set('latest_agent', '');
agent('once');
agent('once');
// settings
$r = req(1, 'NodeSettings', array('inId' => '1', 'inEmail' => 'bad@', 'inLocal' => '1'));
check(strpos($r['text'], 'not an email') !== false, 'a bad email is refused');
$r = req(1, 'NodeSettings', array('inId' => '1', 'inEmail' => 'certs@example.com', 'inLocal' => '1'));
check(strpos($r['text'], 'Saved') !== false, 'settings saved');
agent('once');
$cf = (string) @file_get_contents(getenv('SNODE_CADDY') . '/Caddyfile');
check(strpos($cf, "\temail certs@example.com\n\tlocal_certs\n\tskip_install_trust") !== false && strpos($cf, 'import /etc/caddy/sentora/*.caddy') !== false, 'the node rewrote Caddy\'s settings', $cf);
check(strpos((string) @file_get_contents(getenv('SNODE_ETC') . '/install.conf'), "EMAIL=certs@example.com\nLOCAL_CERTS=1") !== false, 'and kept them for its own updates (install.conf)');
agent('once');
$r = req(1, '', array(), array('tab' => 'nodes', 'manage' => '1'));
check(strpos($r['html'], 'value="certs@example.com"') !== false && preg_match('/name="inLocal" value="1" checked/', $r['html']) === 1, 'the page shows the settings the node reports it uses');
touch(getenv('FAKE_DIR') . '/caddy-fail');
req(1, 'NodeSettings', array('inId' => '1', 'inEmail' => '', 'inLocal' => '0'));
$o = agent('once');
check(strpos($o, 'settings') !== false && strpos($o, 'failed') !== false && strpos((string) file_get_contents(getenv('SNODE_CADDY') . '/Caddyfile'), 'local_certs') !== false, 'Caddy refuses: the old settings stay', $o);
unlink(getenv('FAKE_DIR') . '/caddy-fail');
agent('once');

section('a node that makes its own key');
$fp = dh::Fingerprint();
$e2 = getenv('DH_W') . '/n2etc';
$k2 = getenv('DH_W') . '/n2key.txt';
$env2 = 'SNODE_ETC=' . escapeshellarg($e2) . ' SNODE_KEYFILE=' . escapeshellarg($k2) . ' SNODE_STATE=' . escapeshellarg(getenv('DH_W') . '/n2state') . ' ';
$o = shell_exec($env2 . 'PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg(getenv('DH_AGENT')) . " enroll --panel $web --panel-code AAAA-BBBB 2>&1; echo exit=$?");
check(strpos($o, 'not your panel') !== false && strpos($o, 'exit=1') !== false && !is_file($e2 . '/node.json'), 'a wrong panel code: nothing is made', $o);
$o = shell_exec('echo n | ' . $env2 . 'PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg(getenv('DH_AGENT')) . " enroll --panel $web 2>&1; echo exit=$?");
check(strpos($o, 'answered with this code:  ' . $fp) !== false && strpos($o, 'Stopped') !== false && !is_file($e2 . '/node.json'), 'asked to compare the code; "n": nothing is made', $o);
$o = shell_exec('echo y | ' . $env2 . 'PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg(getenv('DH_AGENT')) . " enroll --panel $web 2>&1; echo exit=$?");
check(preg_match('/Node key:  (SN1(-[A-Z2-9]{4}){6})/', $o, $km) === 1 && strpos($o, 'exit=0') !== false, 'answered "y": the node shows its key', $o);
$nodekey = $km[1];
check((fileperms($k2) & 0777) === 0600 && strpos(file_get_contents($k2), $nodekey) !== false, 'and keeps it for root only');
$n2 = json_decode(file_get_contents($e2 . '/node.json'), true);
check($n2['node'] === 0 && strlen($n2['claim']) === 30 && (fileperms($e2 . '/node.json') & 0777) === 0600, 'node.json keeps the key for root only until it is linked');
check(strpos(json_encode(row("SELECT * FROM x_dh_settings")), $n2['claim']) === false, '(the panel never sees it before it is pasted)');
$o = shell_exec($env2 . 'PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg(getenv('DH_AGENT')) . ' once 2>&1; echo exit=$?');
check(strpos($o, 'Linked') === false && strpos($o, 'panel:') === false, 'before it is added: the panel answers "not yet"', $o);
$r = req(1, 'AddNode', array('inName' => 'keynode', 'inKey' => 'SN1-AAAA-BBBB'));
check(strpos($r['text'], 'not a node key') !== false, 'a wrong key is refused');
$r = req(1, 'AddNode', array('inName' => 'keynode', 'inKey' => strtolower($nodekey)));
check(strpos($r['text'], 'Node "keynode" added') !== false, 'added with its key (typed in small letters works too)', $r['text']);
$r = req(1, 'AddNode', array('inName' => 'again', 'inKey' => $nodekey));
check(strpos($r['text'], 'added already') !== false, 'the same key can not be added twice');
$o = shell_exec($env2 . 'PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg(getenv('DH_AGENT')) . ' once 2>&1; echo exit=$?');
clearstatcache();
check(strpos($o, 'Linked to ' . $web . ' as node') !== false && !is_file($k2), 'the node is linked the next time it calls; its key file is removed', $o);
$n2 = json_decode(file_get_contents($e2 . '/node.json'), true);
$kn = row("SELECT * FROM x_dh_nodes WHERE nd_name_vc = 'keynode'");
check((int) $n2['node'] === (int) $kn['nd_id_pk'] && !isset($n2['claim']) && $kn['nd_claim_vc'] === '' && $kn['nd_signpk_vc'] !== '', 'both sides agree; the key can not be used again');
$o = shell_exec($env2 . 'PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg(getenv('DH_AGENT')) . ' once 2>&1; echo exit=$?');
check(strpos($o, 'panel:') === false && dh::Online(dh::Node((int) $kn['nd_id_pk'])), 'and it calls as a node from then on', $o);
list($c, $body) = (function () use ($web) { $c = curl_init($web . '/modules/docker_hosting/code/node.php?key=1'); curl_setopt($c, CURLOPT_RETURNTRANSFER, true); $b = curl_exec($c); $h = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c); return array($h, $b); })();
check($c === 200 && $body === dh::PanelKey(), 'the panel\'s public keys are offered (they are not secret)');
// removing an online node: it removes itself
$r = req(1, 'Node', array('inId' => (string) $kn['nd_id_pk'], 'inRemove' => '1'));
check(strpos($r['text'], 'asked to remove its agent') !== false, 'removing an online node: it is asked to remove itself');
$o = shell_exec($env2 . 'PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg(getenv('DH_AGENT')) . ' once 2>&1');
check(strpos(calls(), '--on-active=30 /usr/local/sbin/sentora-node-uninstall --purge') !== false, 'the node uninstalls itself (30 seconds later, so its answer gets through)', $o);
shell_exec($env2 . 'PATH=' . escapeshellarg($T . '/fakebin') . ':$PATH php ' . escapeshellarg(getenv('DH_AGENT')) . ' once 2>&1');
check(dh::Node((int) $kn['nd_id_pk']) === null, 'and the panel forgets it when it says so');

section('apps of the administrator\'s own');
$zdbh->exec("INSERT INTO x_vhosts (vh_id_pk, vh_acc_fk, vh_name_vc) VALUES (20, 1, 'admin.test')");
$zdbh->exec("INSERT INTO x_dns (dn_acc_fk, dn_name_vc, dn_vhost_fk, dn_type_vc, dn_host_vc, dn_ttl_in, dn_target_vc) VALUES (1, 'admin.test', 20, 'A', '@', 3600, '192.0.2.9')");
$own = array('inName' => 'Linkding', 'inDesc' => 'Bookmarks you keep and share.', 'inImage' => 'ghcr.io/sissbruecker/linkding:1', 'inPort' => '9090', 'inVolumes' => "/etc/linkding/data/\n\n",
    'inEnv' => "LD_CSRF_TRUSTED_ORIGINS=https://{domain}\r\nLD_SECRET={secret}\nLD_NOTE=a=b\n", 'inMinMem' => '128', 'inMem' => '256', 'inCpu' => '0.5', 'inDisk' => '1', 'inWeb' => 'https://linkding.link');
$r = req(2, 'App', $own);
check((int) val("SELECT COUNT(*) FROM x_dh_apps") === 0, 'a reseller can not add an app');
$refused = array(
    array('inImage' => 'linkding', 'full name'), array('inImage' => 'sissbruecker/linkding:1', 'full name'), array('inImage' => 'ghcr.io/x/y:1 --privileged', 'full name'),
    array('inPort' => '70000', 'from 1 to 65535'), array('inVolumes' => "/data\n/../etc", 'not a folder inside'), array('inVolumes' => "data", 'not a folder inside'),
    array('inEnv' => "NOT A SETTING", 'one line NAME=value'), array('inEnv' => "A=1\nA=2", 'there twice'), array('inMem' => '64', 'not less than the least'),
    array('inName' => '<script>', 'Give the app a name'), array('inWeb' => 'javascript:alert(1)', 'starting with https://'), array('inCpu' => 'x', 'as numbers'),
);
foreach ($refused as $x) {
    $why = array_pop($x);
    $r = req(1, 'App', array_merge($own, $x));
    check(strpos($r['text'], $why) !== false && (int) val("SELECT COUNT(*) FROM x_dh_apps") === 0, 'refused: ' . json_encode($x), $r['text']);
}
$r = req(1, 'App', array_merge($own, array('inCpu' => 'x')), array('tab' => 'nodes'));
check(strpos($r['html'], 'value="x"') !== false && strpos($r['html'], 'Linkding') !== false, 'a refused form is shown again with what was typed');
$r = req(1, 'App', $own);
check(strpos($r['text'], 'ok: Linkding was added') !== false, 'the administrator adds an app of its own', $r['text']);
$ap = row("SELECT * FROM x_dh_apps");
check($ap['ap_id_vc'] === 'my-linkding' && $ap['ap_volumes_tx'] === '["\/etc\/linkding\/data"]' && strpos($ap['ap_env_tx'], 'enc1:') === 0 && strpos($ap['ap_env_tx'], 'LD_SECRET') === false, 'kept: its id, folders; its settings encrypted', json_encode($ap));
$r = req(1, '', array(), array('tab' => 'nodes'));
check(strpos($r['html'], 'Your own apps') !== false && strpos($r['html'], 'ghcr.io/sissbruecker/linkding:1') !== false && strpos($r['html'], 'your own') !== false && strpos($r['html'], 'LD_SECRET={secret}') !== false, 'listed on Nodes and apps, settings shown to the administrator');
$r = req(1, 'App', $own);
check(strpos($r['text'], 'ok:') !== false && (int) val("SELECT COUNT(*) FROM x_dh_apps WHERE ap_id_vc = 'my-linkding-2'") === 1, 'the same name again gets its own id');
$r = req(1, 'AppRemove', array('inId' => 'my-linkding-2'));
check(strpos($r['text'], 'removed') !== false && (int) val("SELECT COUNT(*) FROM x_dh_apps") === 1, 'an unused app is removed');
$r = req(1, 'AppRemove', array('inId' => 'gitea'));
check(strpos($r['text'], 'No such app of your own') !== false, 'a built-in app can not be removed');
$r = req(1);
check(strpos($r['html'], 'Linkding') !== false && strpos($r['html'], 'Bookmarks you keep and share.') !== false, 'offered to the administrator at once');
$r = req(3);
check(strpos($r['html'], 'Linkding') === false, 'not to a client until it is in their share');
$r = req(1, 'Deploy', array('inApp' => 'my-linkding', 'inName' => 'links', 'inDomain' => '20', 'inCpu' => '0.5', 'inMem' => '256', 'inDisk' => '1', 'inDns' => '1'));
check(strpos($r['text'], 'ok: Starting Linkding at links.admin.test') !== false, 'started like any app', $r['text']);
$lc = row("SELECT * FROM x_dh_containers WHERE dc_domain_vc = 'links.admin.test'");
agent('once');
$args = file(getenv('FAKE_DIR') . '/podman/sdh1/c/sdh-' . $lc['dc_id_pk'] . '/args', FILE_IGNORE_NEW_LINES);
$a = implode(' ', $args);
check(strpos($a, '--publish 127.0.0.1:' . $lc['dc_port_in'] . ':9090') !== false && strpos($a, '--volume sdh-' . $lc['dc_id_pk'] . '-0:/etc/linkding/data') !== false
    && strpos($a, 'no-new-privileges') !== false && end($args) === 'ghcr.io/sissbruecker/linkding:1', 'the node runs it with the same limits as every app', $a);
check(in_array('LD_CSRF_TRUSTED_ORIGINS=https://links.admin.test', $args, true) && in_array('LD_NOTE=a=b', $args, true), '{domain} is the app\'s address; a value may hold =', $a);
$sec = dh::AppSecret((int) $lc['dc_id_pk'], 'LD_SECRET');
check(in_array('LD_SECRET=' . $sec, $args, true) && preg_match('/^[0-9a-f]{40}$/', $sec) && $sec !== dh::AppSecret((int) $lc['dc_id_pk'] + 1, 'LD_SECRET') && $sec !== dh::AppSecret((int) $lc['dc_id_pk'], 'OTHER'), '{secret}: the container\'s own, different for every container and setting', $a);
agent('once');
$r = req(1, 'App', array_merge($own, array('inId' => 'my-linkding', 'inPort' => '9091')));
check(strpos($r['text'], 'port and data folders stay') !== false, 'in use: its port can not change', $r['text']);
$r = req(1, 'App', array_merge($own, array('inId' => 'my-linkding', 'inVolumes' => "/data")));
check(strpos($r['text'], 'port and data folders stay') !== false, 'in use: its folders can not change');
$r = req(1, 'App', array_merge($own, array('inId' => 'my-linkding', 'inImage' => 'ghcr.io/sissbruecker/linkding:1.40', 'inEnv' => "LD_SECRET={secret}")));
check(strpos($r['text'], 'ok: Linkding was saved') !== false && dh_catalog::Custom() !== null, 'in use: the image and settings can change', $r['text']);
$r = req(1, 'AppRemove', array('inId' => 'my-linkding'));
check(strpos($r['text'], 'still use Linkding') !== false, 'in use: it can not be removed');
req(1, 'Act', array('inId' => (string) $lc['dc_id_pk'], 'inWhat' => 'update'));
$o = agent('once');
$args = file(getenv('FAKE_DIR') . '/podman/sdh1/c/sdh-' . $lc['dc_id_pk'] . '/args', FILE_IGNORE_NEW_LINES);
check(end($args) === 'ghcr.io/sissbruecker/linkding:1.40' && in_array('LD_SECRET=' . $sec, $args, true) && !in_array('LD_NOTE=a=b', $args, true), 'Update brings the new image and settings; the secret stays the same', $o . implode(' ', $args));
agent('once');
req(1, 'Act', array('inId' => (string) $lc['dc_id_pk'], 'inWhat' => 'delete'));
agent('once');
agent('once');
$r = req(1, 'AppRemove', array('inId' => 'my-linkding'));
check(strpos($r['text'], 'removed') !== false && (int) val("SELECT COUNT(*) FROM x_dh_apps") === 0, 'once its containers are gone it can be removed');
$r = req(1, '', array(), array('tab' => 'log'));
check(strpos($r['html'], 'Own app Linkding (my-linkding) added') !== false, 'the log has it');

section('the local network, per customer');
$f = file_get_contents(getenv('FAKE_DIR') . '/fence.nft');
check(strpos($f, 'meta skuid @apps meta skuid != @lan ip daddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 } reject') !== false
    && strpos($f, 'ip daddr 169.254.0.0/16 reject') !== false && preg_match('/set lan \{ type uid; \}/', $f) === 1, 'the node fences every app off the local network and the cloud\'s metadata by default', $f);
check(strpos($f, 'ip daddr { 192.168.1.1, 8.8.8.8 } meta l4proto { tcp, udp } th dport 53 accept') !== false, 'the node\'s own DNS servers stay reachable (also one on the local network)');
check(preg_match('/set apps \{ type uid; elements = \{ ([0-9, ]+) \}; \}/', $f, $um) === 1, 'the customers\' users are in it', $f);
$r = req(1, '', array(), array('tab' => 'nodes'));
check(strpos($r['html'], 'Local network') !== false && strpos($r['html'], 'name="inAcc" value="3"') !== false, 'the administrator sees the accounts with Docker Hosting, each with a switch');
$r = req(2, 'Lan', array('inAcc' => '3', 'inLan' => '1'));
check(dh::LanAccounts() === array(), 'a reseller can not open it for its client');
$r = req(1, 'Lan', array('inAcc' => '3', 'inLan' => '1'));
check(strpos($r['text'], 'client1\'s apps may reach the local network now') !== false && dh::LanAccounts() === array(3), 'the administrator opens it for client1', $r['text']);
$j = row("SELECT * FROM x_dh_jobs WHERE jb_node_fk = 1 AND jb_action_vc = 'network' AND jb_state_vc = 'queued'");
check($j && json_decode(ctrl_secret::Decrypt($j['jb_data_tx']), true) === array('lan_users' => array('sdh3')), 'node 1 gets the list (sdh3)');
agent('once');
$f = file_get_contents(getenv('FAKE_DIR') . '/fence.nft');
check(preg_match('/set lan \{ type uid; elements = \{ (\d+) \}; \}/', $f, $lm) === 1 && $lm[1] === trim(file_get_contents(getenv('FAKE_DIR') . '/users/sdh3')), 'the node lets only sdh3\'s apps through to the local network', $f);
check(json_decode(row("SELECT nd_info_tx FROM x_dh_nodes WHERE nd_id_pk = 1")['nd_info_tx'], true)['cfg_lan_users'] === array('sdh3'), 'and reports it');
// the node lost its setting (set up again): the panel sends it again by itself
$last = (int) val("SELECT COALESCE(MAX(jb_id_pk), 0) FROM x_dh_jobs WHERE jb_action_vc = 'network'");
unlink(getenv('SNODE_STATE') . '/network.json');
agent('once');
check((int) val("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_node_fk = 1 AND jb_action_vc = 'network' AND jb_id_pk > :l", array(':l' => $last)) === 1, 'a node that lost the list gets it again by itself (once)');
agent('once');
check(strpos(file_get_contents(getenv('FAKE_DIR') . '/fence.nft'), 'set lan { type uid; elements = {') !== false
    && json_decode(row("SELECT nd_info_tx FROM x_dh_nodes WHERE nd_id_pk = 1")['nd_info_tx'], true)['cfg_lan_users'] === array('sdh3'), 'and applies it');
$r = req(1, 'Lan', array('inAcc' => '3', 'inLan' => '0'));
agent('once');
check(dh::LanAccounts() === array() && preg_match('/set lan \{ type uid; \}/', file_get_contents(getenv('FAKE_DIR') . '/fence.nft')) === 1, 'closed again: no app may reach the local network');
// a node that can not load the rules says so
touch(getenv('FAKE_DIR') . '/nft-fail');
req(1, 'Lan', array('inAcc' => '3', 'inLan' => '1'));
agent('once');
check(strpos((string) val("SELECT jb_result_tx FROM x_dh_jobs WHERE jb_action_vc = 'network' ORDER BY jb_id_pk DESC LIMIT 1"), 'fence:') !== false
    && val("SELECT jb_state_vc FROM x_dh_jobs WHERE jb_action_vc = 'network' ORDER BY jb_id_pk DESC LIMIT 1") === 'failed', 'rules that do not load: the job fails, with the reason');
unlink(getenv('FAKE_DIR') . '/nft-fail');
req(1, 'Lan', array('inAcc' => '3', 'inLan' => '0'));
agent('once');

section('daemon: account switched off, disk limit, account deleted');
agent('once');
$zdbh->exec("UPDATE x_accounts SET ac_enabled_in = 0 WHERE ac_id_pk = 3");
$d = daemon();
check(strpos($d, 'stopped (account switched off)') !== false, 'containers of a switched-off account are stopped', $d);
$r = req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'start'));
check(strpos($r['text'], 'switched off') !== false, 'and can not be started');
agent('once');
agent('once');
$zdbh->exec("UPDATE x_accounts SET ac_enabled_in = 1 WHERE ac_id_pk = 3");
$d = daemon();
check(strpos($d, 'started again (account switched on)') !== false, 'switched on: started again', $d);
agent('once');
agent('once');
check(row("SELECT dc_state_vc FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']))['dc_state_vc'] === 'running', 'running again');
// disk: the node measures the volumes
file_put_contents(getenv('FAKE_DIR') . '/podman/sdh3/v/sdh-' . $c1['dc_id_pk'] . '-0/big', str_repeat('x', 3 * 1048576));
$st = json_decode(file_get_contents(getenv('SNODE_STATE') . '/state.json'), true);
agent('once');
$used = (int) row("SELECT dc_used_in FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']))['dc_used_in'];
check($used >= 3, 'disk use measured on the node: ' . $used . ' MB');
$zdbh->prepare("UPDATE x_dh_containers SET dc_disk_in = 2 WHERE dc_id_pk = :c")->execute(array(':c' => $c1['dc_id_pk']));
$d = daemon();
check(strpos($d, 'over its disk limit') !== false, 'over its disk limit: stopped', $d);
$r = req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'start'));
check(strpos($r['text'], 'raise its disk limit') !== false, 'not started again until the limit is raised');
$zdbh->prepare("UPDATE x_dh_containers SET dc_disk_in = 2048 WHERE dc_id_pk = :c")->execute(array(':c' => $c1['dc_id_pk']));
agent('once');

section('deleting');
$r = req(2, 'Grant', array('inAcc' => '3', 'inOff' => '1'));
check(strpos($r['text'], 'still runs containers') !== false, 'a share in use can not be taken back');
$r = req(1, 'Node', array('inId' => '1', 'inRemove' => '1'));
check(strpos($r['text'], 'still has containers') !== false, 'a node with containers can not be removed');
req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'delete'));
agent('once');
agent('once');
$c1d = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c1['dc_id_pk']));
check($c1d['dc_deleted_in'] !== null && $c1d['dc_setup_tx'] === null, 'deleted in the panel (its setup password gone)');
check(!is_dir(getenv('FAKE_DIR') . '/podman/sdh3/c/sdh-' . $c1['dc_id_pk']) && !is_dir(getenv('FAKE_DIR') . '/podman/sdh3/v/sdh-' . $c1['dc_id_pk'] . '-0'), 'container and its volume gone on the node');
check(strpos(sites(), 'kuma.example.com') === false && strpos(sites(), 'kuma2.example.com') !== false, 'its site gone from Caddy, the other stays');
check(row("SELECT dn_deleted_ts FROM x_dns WHERE dn_id_pk = :d", array(':d' => $c1['dc_dns_fk']))['dn_deleted_ts'] !== null, 'its DNS record deleted (the one it added only)');
check((int) val("SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk = 10 AND dn_deleted_ts IS NULL") === 2, 'the domain\'s other records stay');
// the node has a container the panel does not know: kept (never deleted by itself), listed
$st = json_decode(file_get_contents(getenv('SNODE_STATE') . '/state.json'), true);
$st['containers']['999'] = array('user' => 'sdh3', 'domain' => 'ghost.example.com', 'hostport' => 20099, 'public' => 1, 'auth' => '', 'volumes' => 0);
file_put_contents(getenv('SNODE_STATE') . '/state.json', json_encode($st));
$o = agent('once');
check(strpos($o, 'delete container 999') === false && isset(json_decode(file_get_contents(getenv('SNODE_STATE') . '/state.json'), true)['containers']['999']),
    'a container the panel does not know is not deleted', $o);
$fd = row("SELECT * FROM x_dh_found WHERE fd_cid_in = 999");
check($fd && $fd['fd_user_vc'] === 'sdh3' && $fd['fd_domain_vc'] === 'ghost.example.com' && (int) $fd['fd_hostport_in'] === 20099 && (int) $fd['fd_volumes_in'] === 0,
    'it is listed as found, with what the node knows of it', json_encode($fd));
check(row("SELECT dc_state_vc, dc_deleted_in FROM x_dh_containers WHERE dc_id_pk = 999")['dc_state_vc'] === 'reserved', 'its number is kept from new apps (a reserved row)');
// the account is deleted: its containers go
$zdbh->exec("UPDATE x_accounts SET ac_deleted_ts = " . time() . " WHERE ac_id_pk = 3");
$d = daemon();
check(strpos($d, 'of a deleted account is being deleted') !== false, 'containers of a deleted account are deleted', $d);
agent('once');
agent('once');
check((int) val("SELECT COUNT(*) FROM x_dh_containers WHERE dc_deleted_in IS NULL") === 0 && (int) val("SELECT COUNT(*) FROM x_dh_grants WHERE gr_acc_fk = 3") === 0, 'nothing left of it; its share gone');
$r = req(2, 'Grant', array('inAcc' => '4', 'inOff' => '1'));
check(strpos($r['text'], 'taken back') !== false, 'a share not in use can be taken back');

section('apps a node has that the panel does not know; apps a node lost; a node gone for good');
$fake = getenv('FAKE_DIR');
// a customer's Linux user and an app on the node, made by hand as an older setup would have left them
function fakeuser($u)
{
    global $T;
    $fake = getenv('FAKE_DIR');
    if (!is_file("$fake/users/$u")) {
        shell_exec('FAKE_DIR=' . escapeshellarg($fake) . ' ' . escapeshellarg($T . '/fakebin/useradd') . ' --home-dir ' . escapeshellarg(getenv('SNODE_HOME') . '/' . $u) . ' ' . $u);
    }
    $d = getenv('SNODE_RUN') . '/' . trim(file_get_contents("$fake/users/$u"));
    @mkdir($d, 0700, true);
    touch("$d/bus");
    return $d;
}
function seed($u, $cid, $port, $domain, $private, array $env)
{
    global $T;
    $run = fakeuser($u);
    $args = array('create', '--name', 'sdh-' . $cid, '--label', 'sentora.cid=' . $cid, '--cpus', '0.50', '--memory', '256m', '--publish', "127.0.0.1:$port:3001", '--volume', "sdh-$cid-0:/app/data");
    foreach ($env as $k => $v) {
        $args[] = '--env';
        $args[] = "$k=$v";
    }
    $args[] = 'docker.io/louislam/uptime-kuma:1';
    $pre = 'FAKE_DIR=' . escapeshellarg(getenv('FAKE_DIR')) . ' FAKE_USER=' . $u . ' XDG_RUNTIME_DIR=' . escapeshellarg($run) . ' SNODE_RUN=' . escapeshellarg(getenv('SNODE_RUN')) . ' ';
    shell_exec($pre . escapeshellarg($T . '/fakebin/podman') . ' ' . implode(' ', array_map('escapeshellarg', $args)));
    shell_exec($pre . escapeshellarg($T . '/fakebin/podman') . ' start sdh-' . $cid);
    file_put_contents(getenv('FAKE_DIR') . "/podman/$u/v/sdh-$cid-0/data.txt", "kept data of $cid\n");
    $f = getenv('SNODE_CADDY') . '/sentora/sites.caddy';
    file_put_contents($f, (is_file($f) ? file_get_contents($f) : '') . "\n# container $cid ($u)\n$domain {\n\tencode zstd gzip\n"
        . ($private ? "\tbasic_auth {\n\t\tsetup " . password_hash('old-pass', PASSWORD_BCRYPT) . "\n\t}\n" : '') . "\treverse_proxy 127.0.0.1:$port\n}\n");
    // (the node looks for lost apps at its next call)
    $st = json_decode(file_get_contents(getenv('SNODE_STATE') . '/state.json'), true);
    unset($st['scanned_at']);
    file_put_contents(getenv('SNODE_STATE') . '/state.json', json_encode($st));
}
$zdbh->prepare("INSERT INTO x_dh_grants (gr_acc_fk, gr_by_fk, gr_count_in, gr_cpu_in, gr_mem_in, gr_disk_in, gr_apps_tx, gr_updated_in) VALUES (4, 2, 5, 400, 4096, 20480, 'uptime-kuma', :t)")->execute(array(':t' => time()));
$r = req(1, '', array(), array('tab' => 'nodes'));
check(strpos($r['html'], 'Found on the nodes') !== false && strpos($r['html'], 'ghost.example.com') !== false && strpos($r['html'], 'app(s) the panel does not know') !== false,
    'administrator: the found app is shown (and in Needs a look)', substr(strip_tags($r['html']), 0, 3000));
check(strpos($r['html'], 'Its owner is not known here') !== false, 'its account is not on this panel: no owner suggested');
$r = req(4, 'FoundDelete', array('inFound' => (string) $fd['fd_id_pk']));
check(row("SELECT fd_step_vc FROM x_dh_found WHERE fd_cid_in = 999")['fd_step_vc'] === '', 'a client can not delete a found app');
$r = req(1, 'FoundDelete', array('inFound' => (string) $fd['fd_id_pk']));
agent('once');
agent('once');
check(!row("SELECT * FROM x_dh_found WHERE fd_cid_in = 999") && !isset(json_decode(file_get_contents(getenv('SNODE_STATE') . '/state.json'), true)['containers']['999']),
    'the administrator deletes it: gone on the node and from the list');
// a made-up number far past the panel's: listed, but not kept from the panel's numbering
$st = json_decode(file_get_contents(getenv('SNODE_STATE') . '/state.json'), true);
$st['containers']['1999999999'] = array('user' => 'sdh4', 'domain' => 'far.example.com', 'hostport' => 20098, 'public' => 1, 'auth' => '', 'volumes' => 0);
file_put_contents(getenv('SNODE_STATE') . '/state.json', json_encode($st));
agent('once');
check(row("SELECT * FROM x_dh_found WHERE fd_cid_in = 1999999999") && !row("SELECT * FROM x_dh_containers WHERE dc_id_pk = 1999999999"),
    'a number far past the panel\'s own: listed, but not reserved (a node can not push the numbering to its end)');
req(1, 'FoundDelete', array('inFound' => (string) row("SELECT fd_id_pk FROM x_dh_found WHERE fd_cid_in = 1999999999")['fd_id_pk']));
agent('once');
agent('once');
check(!row("SELECT * FROM x_dh_found WHERE fd_cid_in = 1999999999"), '(deleted)');
// the node lost its list of apps (state.json): it finds them again by their label
seed('sdh6', 555, 20077, 'kuma.other.com', true, array('FOO' => 'bar baz', 'ADMIN_SECRET' => 'from-another-panel'));
$o = agent('once');
check(strpos($o, 'found sdh-555 of sdh6 (kuma.other.com)') !== false, 'the node finds an app missing from its list and lists it again', $o);
$fd = row("SELECT * FROM x_dh_found WHERE fd_cid_in = 555");
check($fd && $fd['fd_user_vc'] === 'sdh6' && $fd['fd_domain_vc'] === 'kuma.other.com' && (int) $fd['fd_hostport_in'] === 20077 && (int) $fd['fd_volumes_in'] === 1
    && $fd['fd_image_vc'] === 'docker.io/louislam/uptime-kuma:1' && (int) $fd['fd_public_in'] === 0, 'with its address, port, data folder, image and setup password (from Caddy\'s sites)', json_encode($fd));
$sg = dh::Suggest($fd);
check($sg['acc'] === 4 && $sg['app'] === 'uptime-kuma' && $sg['back'] === 0 && strpos($sg['why'], 'other.com, client2') !== false, 'suggested: client2 (its address is under client2\'s domain), Uptime Kuma (by its image)', json_encode($sg));
$r = req(1, 'Adopt', array('inFound' => (string) $fd['fd_id_pk'], 'inAcc' => '6', 'inApp' => 'uptime-kuma'));
check(strpos($r['text'], 'belongs to client2, not client3') !== false, 'not to another owner than the domain\'s', $r['text']);
$zdbh->exec("UPDATE x_dh_found SET fd_volumes_in = 2 WHERE fd_cid_in = 555");
$r = req(1, 'Adopt', array('inFound' => (string) $fd['fd_id_pk'], 'inAcc' => '4', 'inApp' => 'uptime-kuma'));
$zdbh->exec("UPDATE x_dh_found SET fd_volumes_in = 1 WHERE fd_cid_in = 555");
check(strpos($r['text'], 'it is not that app') !== false, 'not as an app with other data folders', $r['text']);
$r = req(4, 'Adopt', array('inFound' => (string) $fd['fd_id_pk'], 'inAcc' => '4', 'inApp' => 'uptime-kuma'));
check(row("SELECT fd_step_vc FROM x_dh_found WHERE fd_cid_in = 555")['fd_step_vc'] === '', 'a client can not take a found app');
$zdbh->exec("UPDATE x_dh_grants SET gr_apps_tx = 'gitea' WHERE gr_acc_fk = 4");
$r = req(1, 'Adopt', array('inFound' => (string) $fd['fd_id_pk'], 'inAcc' => '4', 'inApp' => 'uptime-kuma'));
check(strpos($r['text'], 'may not run Uptime Kuma') !== false, 'not when the owner\'s share does not offer that app', $r['text']);
$zdbh->exec("UPDATE x_dh_grants SET gr_apps_tx = 'uptime-kuma', gr_mem_in = 100 WHERE gr_acc_fk = 4");
req(1, 'Adopt', array('inFound' => (string) $fd['fd_id_pk'], 'inAcc' => '4', 'inApp' => 'uptime-kuma'));
agent('once');
$f2 = row("SELECT * FROM x_dh_found WHERE fd_cid_in = 555");
check($f2 && $f2['fd_step_vc'] === '' && strpos($f2['fd_note_vc'], 'not enough memory free') !== false && !row("SELECT * FROM x_dh_containers WHERE dc_id_pk = 555 AND dc_deleted_in IS NULL"),
    'not past the owner\'s share (its real size, read from the node): still on the list, with why', json_encode($f2));
$zdbh->exec("UPDATE x_dh_grants SET gr_mem_in = 4096 WHERE gr_acc_fk = 4");
$r = req(1, 'Adopt', array('inFound' => (string) $fd['fd_id_pk'], 'inAcc' => '4', 'inApp' => 'uptime-kuma'));
check(strpos($r['text'], 'asked for the app') !== false, 'given to client2', $r['text']);
$o = agent('once') . agent('once');
$c = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = 555");
check($c && (int) $c['dc_acc_fk'] === 4 && (int) $c['dc_node_fk'] === 1 && $c['dc_state_vc'] === 'running' && $c['dc_deleted_in'] === null && (int) $c['dc_port_in'] === 20077,
    'it is client2\'s app now, the same number and port', $o . json_encode($c));
check(is_dir("$fake/podman/sdh4/c/sdh-555") && trim(file_get_contents("$fake/podman/sdh4/c/sdh-555/state")) === 'running' && !is_dir("$fake/podman/sdh6/c/sdh-555"),
    'on the node it was moved under client2\'s user (sdh4), the old one removed');
check(@file_get_contents("$fake/podman/sdh4/v/sdh-555-0/data.txt") === "kept data of 555\n" && !is_dir("$fake/podman/sdh6/v/sdh-555-0"), 'with its data');
check(strpos(file_get_contents("$fake/podman/sdh4/c/sdh-555/args"), "FOO=bar baz\n--env\nADMIN_SECRET=from-another-panel") !== false, 'and the settings it ran with');
$cd = dh::CreateData(dh::Container(555), '');
check($cd['env']['FOO'] === 'bar baz' && $cd['env']['ADMIN_SECRET'] === 'from-another-panel' && !isset($cd['env']['PATH']) && !isset($cd['env']['HOSTNAME']) && !isset($cd['env']['container']),
    'those settings are kept for its updates (not the image\'s or Podman\'s own)', json_encode($cd['env']));
check(strpos((string) $c['dc_keepenv_tx'], 'enc1:') === 0 && strpos(json_encode(row("SELECT * FROM x_dh_jobs WHERE jb_action_vc = 'inspect' ORDER BY jb_id_pk DESC LIMIT 1")), 'from-another-panel') === false,
    'kept encrypted, and not in the jobs\' results');
check(preg_match('/# container 555 \(sdh4\)\nkuma\.other\.com \{.*?setup (\S+)\n/s', sites(), $hm) === 1 && password_verify(dh::SetupPassword($c), $hm[1]), 'its site points to it, with a new setup password the owner can read');
check(!row("SELECT * FROM x_dh_found WHERE fd_cid_in = 555"), 'off the found list');
$r = req(4);
check(strpos($r['html'], 'kuma.other.com') !== false, 'client2 sees it among its apps');
// moving fails: the app runs where it was, stays on the list
seed('sdh6', 556, 20078, 'kuma4.other.com', false, array());
agent('once');
$fd = row("SELECT * FROM x_dh_found WHERE fd_cid_in = 556");
touch("$fake/save-fail");
req(1, 'Adopt', array('inFound' => (string) $fd['fd_id_pk'], 'inAcc' => '4', 'inApp' => 'uptime-kuma'));
$o = agent('once') . agent('once');
unlink("$fake/save-fail");
$fd = row("SELECT * FROM x_dh_found WHERE fd_cid_in = 556");
check($fd && $fd['fd_step_vc'] === '' && strpos($fd['fd_note_vc'], 'Could not be moved') !== false, 'a move that fails: still on the list, with why', $o . json_encode($fd));
check(trim(file_get_contents("$fake/podman/sdh6/c/sdh-556/state")) === 'running' && !is_dir("$fake/podman/sdh4/c/sdh-556"), 'and the app runs again where it was');
check(row("SELECT dc_state_vc FROM x_dh_containers WHERE dc_id_pk = 556")['dc_state_vc'] === 'reserved', 'its number is kept for it again');
req(1, 'FoundDelete', array('inFound' => (string) $fd['fd_id_pk']));
agent('once');
agent('once');
check(!row("SELECT * FROM x_dh_found WHERE fd_cid_in = 556") && !is_dir("$fake/podman/sdh6/c/sdh-556"), '(deleted again)');
// the node lost an app (set up again, restored): shown as missing, made again by its owner
shell_exec('rm -rf ' . escapeshellarg("$fake/podman/sdh4/c/sdh-555") . ' ' . escapeshellarg("$fake/podman/sdh4/v/sdh-555-0"));
$st = json_decode(file_get_contents(getenv('SNODE_STATE') . '/state.json'), true);
unset($st['containers']['555']);
file_put_contents(getenv('SNODE_STATE') . '/state.json', json_encode($st));
agent('once');
check(row("SELECT dc_state_vc FROM x_dh_containers WHERE dc_id_pk = 555")['dc_state_vc'] === 'missing', 'an app the node no longer has is shown as missing');
$r = req(4);
check(strpos($r['html'], 'Missing on its node') !== false && strpos($r['html'], 'Make it again') !== false, 'its owner sees it, with Make it again');
$r = req(1, '', array(), array('tab' => 'nodes'));
check(strpos($r['html'], 'no longer has 1 of its apps') !== false, 'the administrators are told');
$r = req(4, 'Act', array('inId' => '555', 'inWhat' => 'remake'));
agent('once');
check(row("SELECT dc_state_vc FROM x_dh_containers WHERE dc_id_pk = 555")['dc_state_vc'] === 'running' && is_dir("$fake/podman/sdh4/c/sdh-555")
    && strpos(file_get_contents("$fake/podman/sdh4/c/sdh-555/args"), 'FOO=bar baz') !== false, 'made again, with its kept settings', $r['text']);
// an app of a node that no longer reaches the panel (node set up again with its apps kept, relinked): given back as it was
$zdbh->prepare("INSERT INTO x_dh_nodes (nd_name_vc, nd_signpk_vc, nd_boxpk_vc, nd_seen_in, nd_created_in) VALUES ('oldnode', 'x', 'y', :t, :t)")->execute(array(':t' => time() - 90000));
$old = (int) $zdbh->lastInsertId();
$zdbh->prepare("INSERT INTO x_dh_containers (dc_id_pk, dc_acc_fk, dc_node_fk, dc_app_vc, dc_label_vc, dc_cpu_in, dc_mem_in, dc_disk_in, dc_port_in, dc_domain_vc, dc_state_vc, dc_public_in, dc_created_in)
    VALUES (777, 4, :n, 'uptime-kuma', 'monitor', 50, 256, 2048, 20050, 'kuma3.other.com', 'running', 1, :t)")->execute(array(':n' => $old, ':t' => time()));
$r = req(1, '', array(), array('tab' => 'nodes'));
check(strpos($r['html'], 'has been away for over a day with 1 app(s)') !== false, 'a node away for over a day with apps: the administrators are told');
seed('sdh4', 777, 20050, 'kuma3.other.com', false, array());
agent('once');
$fd = row("SELECT * FROM x_dh_found WHERE fd_cid_in = 777");
$sg = dh::Suggest($fd);
check($sg['back'] === 1 && $sg['acc'] === 4 && strpos($sg['why'], 'last on node "oldnode"') !== false, 'recognised: client2\'s app from the old node', json_encode($sg));
$jobs = (int) val("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_action_vc = 'move'");
req(1, 'Adopt', array('inFound' => (string) $fd['fd_id_pk'], 'inAcc' => '4', 'inApp' => 'uptime-kuma'));
agent('once');
agent('once');
$c = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = 777");
check((int) $c['dc_node_fk'] === 1 && $c['dc_state_vc'] === 'running' && $c['dc_label_vc'] === 'monitor' && (int) $c['dc_disk_in'] === 2048 && (int) val("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_action_vc = 'move'") === $jobs,
    'given back as it was (its name and size kept), nothing moved on the node', json_encode($c));
// a node gone for good: its apps start on the other nodes, fresh
$zdbh->prepare("INSERT INTO x_dns (dn_acc_fk, dn_name_vc, dn_vhost_fk, dn_type_vc, dn_host_vc, dn_ttl_in, dn_target_vc) VALUES (4, 'other.com', 12, 'A', 'kuma5', 3600, '192.0.2.99')")->execute();
$dn = (int) $zdbh->lastInsertId();
$zdbh->prepare("INSERT INTO x_dh_containers (dc_acc_fk, dc_node_fk, dc_app_vc, dc_cpu_in, dc_mem_in, dc_disk_in, dc_port_in, dc_domain_vc, dc_vhost_fk, dc_dns_fk, dc_state_vc, dc_public_in, dc_created_in)
    VALUES (4, :n, 'uptime-kuma', 50, 256, 1024, 20051, 'kuma5.other.com', 12, :d, 'running', 0, :t)")->execute(array(':n' => $old, ':d' => $dn, ':t' => time()));
$c5 = (int) $zdbh->lastInsertId();
$r = req(1, '', array(), array('tab' => 'nodes', 'manage' => (string) $old));
check(strpos($r['html'], 'Start its apps on other nodes') !== false, 'Manage of a node away: Start its apps on other nodes');
$r = req(1, '', array(), array('tab' => 'nodes', 'manage' => '1'));
check(strpos($r['html'], 'Start its apps on other nodes') === false && strpos($r['html'], 'sentora-node-relink') !== false, '(not for an online node; how to link a node again is there)');
$r = req(1, 'Rehome', array('inId' => '1'));
check(strpos($r['text'], 'is online') !== false, 'refused for a node that is online');
$r = req(1, 'Rehome', array('inId' => (string) $old));
agent('once');
$c = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $c5));
check((int) $c['dc_node_fk'] === 1 && $c['dc_state_vc'] === 'running' && is_dir("$fake/podman/sdh4/c/sdh-$c5") && strpos(sites(), 'kuma5.other.com') !== false, 'started on node1, fresh', $r['text'] . json_encode($c));
check(row("SELECT dn_target_vc FROM x_dns WHERE dn_id_pk = :d", array(':d' => $dn))['dn_target_vc'] === dh::PublicIp(dh::Node(1)), 'its DNS record points to node1 now');
check((int) dh::Placed($old)['count'] === 0, 'nothing left on the old node');
// tidy up: node1 empty again, the old node gone
foreach (array(555, 777, $c5) as $id) {
    req(4, 'Act', array('inId' => (string) $id, 'inWhat' => 'delete'));
}
agent('once');
agent('once');
$zdbh->exec("DELETE FROM x_dh_nodes WHERE nd_id_pk = " . $old);
$zdbh->exec("DELETE FROM x_dh_grants WHERE gr_acc_fk = 4");
check((int) val("SELECT COUNT(*) FROM x_dh_containers WHERE dc_deleted_in IS NULL") === 0 && !is_dir("$fake/podman/sdh4/c/sdh-555"), '(all deleted again)');

section('a node speaks only for its own apps; linking messages are limited');
// a second node, linked with keys this test holds (as if it were broken into)
$xs = sodium_crypto_sign_keypair();
$xb = sodium_crypto_box_keypair();
$zdbh->prepare("INSERT INTO x_dh_nodes (nd_name_vc, nd_signpk_vc, nd_boxpk_vc, nd_code_vc, nd_codeexp_in, nd_seen_in, nd_ip_vc, nd_info_tx, nd_accepting_in, nd_created_in)
    VALUES ('rogue', :s, :b, '', 0, :t, '127.0.0.1', '{}', 0, :t)")->execute(array(':s' => dh_proto::B64(sodium_crypto_sign_publickey($xs)), ':b' => dh_proto::B64(sodium_crypto_box_publickey($xb)), ':t' => time()));
$xid = (int) $zdbh->lastInsertId();
$zdbh->prepare("INSERT INTO x_dh_containers (dc_acc_fk, dc_node_fk, dc_app_vc, dc_cpu_in, dc_mem_in, dc_disk_in, dc_port_in, dc_domain_vc, dc_state_vc, dc_want_vc, dc_created_in)
    VALUES (3, 1, 'uptime-kuma', 50, 256, 1024, 29999, 'victim.example.com', 'running', 'running', :t)")->execute(array(':t' => time()));
$victim = (int) $zdbh->lastInsertId();
check($victim > 0, '(an app of node 1 to aim at)');
$vrow = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $victim));
$before = (int) val("SELECT COUNT(*) FROM x_dh_jobs");
list($c) = post(dh_proto::Pack(dh_proto::Msg('poll', $xid, array('states' => array((string) $victim => array('state' => 'exited', 'used_mb' => 999999, 'user' => 'sdh' . $vrow['dc_acc_fk'])))), sodium_crypto_sign_secretkey($xs), $k['box_pk']));
$after = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $victim));
check($c === 200 && (int) val("SELECT COUNT(*) FROM x_dh_jobs") === $before && $after['dc_state_vc'] === $vrow['dc_state_vc'] && (int) $after['dc_used_in'] === (int) $vrow['dc_used_in'],
    'another node reporting node 1\'s app: no delete queued, its state and disk use untouched');
// a job of the rogue node naming node 1's app (as an old panel could have queued): its result changes nothing
$jid = dh::Queue($xid, $victim, 'delete', array('cid' => $victim, 'user' => 'sdh' . $vrow['dc_acc_fk'], 'domain' => ''));
list($c) = post(dh_proto::Pack(dh_proto::Msg('poll', $xid, array('results' => array(array('id' => $jid, 'ok' => true, 'out' => 'deleted')))), sodium_crypto_sign_secretkey($xs), $k['box_pk']));
$after = row("SELECT * FROM x_dh_containers WHERE dc_id_pk = :c", array(':c' => $victim));
check($c === 200 && $after['dc_deleted_in'] === null && $after['dc_state_vc'] !== 'deleted', 'its "deleted" for node 1\'s app is ignored: the app and its DNS record stay');
$zdbh->exec("DELETE FROM x_dh_jobs WHERE jb_node_fk = " . $xid);
$zdbh->exec("DELETE FROM x_dh_nodes WHERE nd_id_pk = " . $xid);
$zdbh->exec("DELETE FROM x_dh_containers WHERE dc_id_pk = " . $victim);
// linking messages need no known key: at most 300 from one address in 10 minutes
$hs = sodium_crypto_sign_keypair();
$hello = function () use ($hs, $k) {
    return post(dh_proto::Pack(dh_proto::Msg('hello', 0, array('claim' => bin2hex(random_bytes(15)), 'sign_pk' => dh_proto::B64(sodium_crypto_sign_publickey($hs)),
        'box_pk' => dh_proto::B64(sodium_crypto_box_publickey(sodium_crypto_box_keypair())))), sodium_crypto_sign_secretkey($hs), $k['box_pk']));
};
list($c) = $hello();
check($c === 200, 'a node waiting to be added is answered');
$ins = $zdbh->prepare("INSERT INTO x_dh_nonces (nn_nonce_vc, nn_time_in, nn_ip_vc, nn_link_in) VALUES (:n, :t, '127.0.0.1', 1)");
for ($i = 0; $i < 300; $i++) {
    $ins->execute(array(':n' => bin2hex(random_bytes(16)), ':t' => time()));
}
list($c) = $hello();
check($c === 400, 'after 300 from the same address in 10 minutes: refused');
$logs = (int) val("SELECT COUNT(*) FROM x_dh_log WHERE lg_what_vc = 'pair-refused'");
list($c) = post(dh_proto::Pack(dh_proto::Msg('pair', 0, array('code' => '1.' . str_repeat('0', 32), 'sign_pk' => dh_proto::B64(sodium_crypto_sign_publickey($hs)),
    'box_pk' => dh_proto::B64(sodium_crypto_box_publickey(sodium_crypto_box_keypair())))), sodium_crypto_sign_secretkey($hs), $k['box_pk']));
check($c === 400 && (int) val("SELECT COUNT(*) FROM x_dh_log WHERE lg_what_vc = 'pair-refused'") === $logs, 'and nothing more lands in the log');
$zdbh->exec("UPDATE x_dh_nonces SET nn_time_in = nn_time_in - 700 WHERE nn_link_in = 1");
list($c) = $hello();
check($c === 200, '10 minutes later it is answered again');

section('the rest');
$r = req(1, 'Settings', array('inApps' => array('gitea')));
$r = req(1, 'AddNode', array('inName' => 'node3', 'inUrl' => 'https://panel.example:2083'));
check(strpos($r['command'], "--panel 'https://panel.example:2083'") !== false, 'the panel address typed when adding a node goes into its command');
check(dh::Get('apps') === 'gitea' && dh::PanelUrl() === 'https://panel.example:2083', 'apps offered and the panel\'s address saved');
$r = req(1, 'AddNode', array('inName' => 'node4', 'inUrl' => 'https://x.example/path"'));
check(strpos($r['text'], 'nothing after it') !== false, 'a bad panel address is refused');
check(dh::Grant(2)['apps'] === array('gitea') && dh::Grant(1)['apps'] === array('gitea'), 'an app taken away is not offered any more (down the tree too)');
$r = req(1, 'Node', array('inId' => '1', 'inIp' => '10.0.0.1', 'inAccepting' => '1'));
check(strpos($r['text'], 'public IPv4') !== false, 'a private address is refused as the public one');
$r = req(1, 'Node', array('inId' => '1', 'inIp' => '203.0.113.7'));
check(row("SELECT nd_publicip_vc, nd_accepting_in FROM x_dh_nodes WHERE nd_id_pk = 1") == array('nd_publicip_vc' => '203.0.113.7', 'nd_accepting_in' => 0), 'node: public address set, takes no new containers');
$r = req(1, 'Grant', array('inAcc' => '2', 'inCount' => '5', 'inCpu' => '4', 'inMem' => '4096', 'inDisk' => '20', 'inApps' => array('gitea')));
$r = req(2, 'Deploy', array('inApp' => 'gitea', 'inName' => 'git', 'inDomain' => '10', 'inCpu' => '1', 'inMem' => '512', 'inDisk' => '5'));
check(strpos($r['text'], 'your own domains') !== false, 'res1 can not use its client\'s domain');
$r = req(1, '', array(), array('tab' => 'log'));
check(strpos($r['html'], 'Uptime Kuma at kuma.example.com') !== false && strpos($r['html'], 'linked from') !== false, 'administrator\'s log has everything');
$r = req(4, '', array(), array('tab' => 'log'));
check(strpos($r['html'], 'kuma.example.com') === false, 'a client\'s log has only its own');
$o = agent('status');
check(strpos($o, 'node 1') !== false && strpos($o, 'kuma2.example.com') === false, 'agent status', $o);
$r = req(1, 'Node', array('inId' => '1', 'inRemove' => '1'));
check(strpos($r['text'], 'asked to remove its agent') !== false, 'node 1 (online, empty) is asked to remove itself');
agent('once');
agent('once');
check(dh::Node(1) === null, 'and is gone once it has');
$o = agent('once');
check(strpos($o, 'HTTP 400') !== false, 'its keys no longer work', $o);
$out = shell_exec('DH_SECRET=other-secret-0123456789abcdef DH_REQ=' . escapeshellarg(json_encode(array('me' => 1))) . ' php ' . escapeshellarg($T . '/run_req.php') . ' 2>&1');
check(strpos((string) $out, 'no secret key') !== false && (string) dh::Get('sign_sk') !== '', 'the server\'s secret changed: the page says so, keys are not made anew');
check(strpos((string) $out, 'Start over with new keys') !== false, 'and offers to start over with new keys (keys there but unreadable)');
$before = (string) dh::Get('sign_sk');
$out = shell_exec('DH_SECRET=other-secret-0123456789abcdef DH_REQ=' . escapeshellarg(json_encode(array('me' => 4, 'action' => 'NewKeys'))) . ' php ' . escapeshellarg($T . '/run_req.php') . ' 2>&1');
check((string) dh::Get('sign_sk') === $before, 'a client can not');


section('linking a node again (sentora-node-relink): its apps stay');
// node1 was removed above (the panel forgot it): its apps and data are still on the server
seed('sdh4', 990, 20090, 'kuma9.other.com', false, array('KEEP' => 'me'));
$o = agent("relink --panel $web");
check(strpos($o, 'linked to ' . $web . ' as node 1 already') !== false && strpos($o, '--new-key') !== false && strpos($o, 'exit=1') !== false, 'the same panel and address: says how to link anew', $o);
$o = agent('relink --panel http://localhost:18092');
$nj = json_decode(file_get_contents(getenv('SNODE_ETC') . '/node.json'), true);
check(strpos($o, 'address is now http://localhost:18092') !== false && $nj['panel'] === 'http://localhost:18092' && (int) $nj['node'] === 1, 'the same panel at another address: only the address changes', $o);
$o = agent('relink --panel http://localhost:18092 --new-key --panel-code AAAA-BBBB');
check(strpos($o, 'not your panel') !== false && json_decode(file_get_contents(getenv('SNODE_ETC') . '/node.json'), true) == $nj, 'a wrong panel code: nothing changes', $o);
$o = agent("relink --panel $web --new-key --panel-code " . dh::Fingerprint());
check(preg_match('/Node key:  (SN1(-[A-Z2-9]{4}){6})/', $o, $km) === 1 && strpos($o, 'keep running, with their data') !== false && strpos($o, 'exit=0') !== false, 'linked anew: a new node key, the apps stay', $o);
$st = json_decode(file_get_contents(getenv('SNODE_STATE') . '/state.json'), true);
check(count(glob(getenv('SNODE_ETC') . '/node.json.old-*')) === 1 && $st['done'] === array() && $st['results'] === array(), 'the old keys are kept aside; the old panel\'s job answers are dropped');
$r = req(1, 'AddNode', array('inName' => 'relinked', 'inKey' => $km[1]));
$o = agent('once') . agent('once');
$rn = row("SELECT * FROM x_dh_nodes WHERE nd_name_vc = 'relinked'");
$fd = row("SELECT * FROM x_dh_found WHERE fd_cid_in = 990");
check(strpos($o, 'found sdh-990 of sdh4') !== false && $fd && (int) $fd['fd_node_fk'] === (int) $rn['nd_id_pk'] && dh::Suggest($fd)['acc'] === 4, 'linked as a new node: its app is found there, client2 suggested', $o);

$jobsBefore = (int) val("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_state_vc IN ('queued','sent')");
$out = shell_exec('DH_SECRET=other-secret-0123456789abcdef DH_REQ=' . escapeshellarg(json_encode(array('me' => 1, 'action' => 'NewKeys'))) . ' php ' . escapeshellarg($T . '/run_req.php') . ' 2>&1');
check((string) dh::Get('sign_sk') !== $before && strpos((string) $out, 'sentora-node-relink') !== false && (int) val("SELECT COUNT(*) FROM x_dh_jobs WHERE jb_state_vc IN ('queued','sent')") === 0,
    'the administrator starts over: new keys, told to relink the nodes, unreadable jobs dropped', substr(strip_tags((string) $out), 0, 600));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
