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
check(strpos($r['html'], 'Version ' . $vm[1]) !== false, 'and the version of its agent (' . $vm[1] . ')');
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
check(strpos($r['html'], '>running<') !== false, 'shown running');
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
$r = req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'public'));
agent('once');
$s = sites();
check(strpos($s, 'kuma.example.com {') !== false && substr_count($s, 'basic_auth') === 1, 'open to the public: no setup password for it (the other still has one)', $s);
$r = req(3);
check(strpos($r['html'], 'Close again') !== false, 'it can be closed again');
req(3, 'Act', array('inId' => (string) $c1['dc_id_pk'], 'inWhat' => 'private'));
agent('once');
check(substr_count(sites(), 'basic_auth') === 2, 'closed again: behind a (new) setup password');
$r = req(3, 'Limits', array('inId' => (string) $c1['dc_id_pk'], 'inCpu' => '1', 'inMem' => '900', 'inDisk' => '2'));
check(strpos($r['text'], 'more than your share') !== false, 'limits over the share are refused');
$r = req(3, 'Limits', array('inId' => (string) $c1['dc_id_pk'], 'inCpu' => '0.75', 'inMem' => '512', 'inDisk' => '2'));
check(strpos($r['text'], 'ok:') !== false, 'limits within the share', $r['text']);
agent('once');
check(strpos((string) @file_get_contents(getenv('FAKE_DIR') . '/podman/sdh3/c/sdh-' . $c1['dc_id_pk'] . '/updates'), 'update --cpus 0.75 --memory 512m --memory-swap 512m') !== false, 'podman update with the new limits');

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
check(strpos($r['html'], 'update available') !== false && strpos($r['html'], "name=\"inWhat\" value=\"update\"") !== false, 'the owner sees "update available" and an Update button');
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
$zdbh->prepare("UPDATE x_dh_containers SET dc_update_in = 1, dc_state_vc = 'running', dc_note_vc = '' WHERE dc_id_pk = :c")->execute(array(':c' => $c1['dc_id_pk']));
$d = shell_exec('DH_TEST_HOUR=14 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'automatic updates') === false, 'not in the day');
$d = shell_exec('DH_TEST_HOUR=3 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'updating kuma.example.com (automatic updates)') !== false, 'at night it updates by itself', (string) $d);
$o = agent('once');
check(strpos($o, 'update container ' . $c1['dc_id_pk'] . ': ok') !== false && trim(file_get_contents($cdir . '/imageid')) === 'id-kuma-3', 'updated by itself', $o);
agent('once');

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
$r = req(1, '', array(), array('tab' => 'nodes'));
check(strpos($r['html'], '7 updates waiting, 2 of them security fixes') !== false && strpos($r['html'], 'A restart is needed') !== false, 'the page shows the waiting updates and that a restart is needed');
check(strpos($r['html'], 'Needs a look') !== false && strpos($r['html'], 'has 2 security updates waiting') !== false, 'and lists it under "Needs a look"');
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
req(1, 'NodeAct', array('inId' => '1', 'inWhat' => 'autosec-on'));
$d = shell_exec('DH_TEST_HOUR=3 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'installs its security updates') !== false, 'with automatic on, they install at night', (string) $d);
$d = shell_exec('DH_TEST_HOUR=3 php ' . escapeshellarg($T . '/run_daemon.php') . ' 2>&1');
check(strpos((string) $d, 'installs its security updates') === false, 'once a night');
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
check($n2['node'] === 0 && strlen($n2['claim']) === 64 && strpos(json_encode($n2), substr($nodekey, 4, 9)) === false, 'node.json has only the key\'s hash, not the key');
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
// the node has a container the panel does not know: it goes
$st = json_decode(file_get_contents(getenv('SNODE_STATE') . '/state.json'), true);
$st['containers']['999'] = array('user' => 'sdh3', 'domain' => 'ghost.example.com', 'hostport' => 20099, 'public' => 1, 'auth' => '', 'volumes' => 0);
file_put_contents(getenv('SNODE_STATE') . '/state.json', json_encode($st));
$o = agent('once');
check(strpos($o, 'delete container 999: ok') !== false && strpos(sites(), 'ghost') === false, 'a container the panel does not know is deleted', $o);
// the account is deleted: its containers go
$zdbh->exec("UPDATE x_accounts SET ac_deleted_ts = " . time() . " WHERE ac_id_pk = 3");
$d = daemon();
check(strpos($d, 'of a deleted account is being deleted') !== false, 'containers of a deleted account are deleted', $d);
agent('once');
agent('once');
check((int) val("SELECT COUNT(*) FROM x_dh_containers WHERE dc_deleted_in IS NULL") === 0 && (int) val("SELECT COUNT(*) FROM x_dh_grants WHERE gr_acc_fk = 3") === 0, 'nothing left of it; its share gone');
$r = req(2, 'Grant', array('inAcc' => '4', 'inOff' => '1'));
check(strpos($r['text'], 'taken back') !== false, 'a share not in use can be taken back');

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

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
