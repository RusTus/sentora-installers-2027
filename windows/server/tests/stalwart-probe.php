<?php
// Informational probe (not a check): what Stalwart's API offers for forwarding a mailbox, so the panel's Forwarders
// can be built on it. Run as Administrator: php stalwart-probe.php C:\zpanel\configs\stalwart\admin.txt alice@example.test
$cfg = (string) @file_get_contents($argv[1]);
$u = preg_match('/^user=(.+)$/m', $cfg, $m) ? trim($m[1]) : '';
$p = preg_match('/^password=(.+)$/m', $cfg, $m) ? trim($m[1]) : '';
$mbox = $argv[2];
function http($path, $user, $pass, $body = null) {
    $h = curl_init('https://127.0.0.1:8443' . $path);
    $o = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERPWD => $user . ':' . $pass, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0);
    if ($body !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $body; $o[CURLOPT_HTTPHEADER] = array('Content-Type: application/json'); }
    curl_setopt_array($h, $o);
    $raw = (string) curl_exec($h);
    $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    return array($code, $raw);
}
function show($t, $x) { echo "==== $t\n", substr(is_string($x) ? $x : json_encode($x, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0, 4000), "\n"; }
list($c, $s) = http('/jmap/session', $u, $p);
$sj = json_decode($s, true);
show("admin session ($c): capabilities", array_keys((array) ($sj['capabilities'] ?? array())));
show('admin session: accounts', $sj['accounts'] ?? null);
show('admin session: primaryAccounts', $sj['primaryAccounts'] ?? null);
$adm = $sj['primaryAccounts']['urn:stalwart:jmap'] ?? '';
$call = function ($using, $method, $args) use ($u, $p) {
    list($c, $r) = http('/jmap/', $u, $p, json_encode(array('using' => $using, 'methodCalls' => array(array($method, (object) $args, 'c0')))));
    return array($c, json_decode($r, true) ?: $r);
};
list($c, $r) = $call(array('urn:ietf:params:jmap:core', 'urn:stalwart:jmap'), 'x:Account/get', array('accountId' => $adm));
$acct = null;
foreach ((array) ($r['methodResponses'][0][1]['list'] ?? array()) as $a) {
    if (strtolower((string) ($a['emailAddress'] ?? '')) === strtolower($mbox)) { $acct = $a; }
}
show("x:Account/get ($c): the mailbox, every property", $acct);
foreach (array('x:SieveScript/get', 'x:Sieve/get', 'x:AccountSieve/get', 'x:Forward/get', 'x:Redirect/get', 'x:SystemSieveScript/get', 'x:MtaSieve/get') as $m) {
    list($c, $r) = $call(array('urn:ietf:params:jmap:core', 'urn:stalwart:jmap'), $m, array('accountId' => $adm));
    show("$m ($c)", $r['methodResponses'][0] ?? $r);
}
// the mailbox's own JMAP account, reached as the administrator
$id = (string) ($acct['id'] ?? '');
foreach (array($id, $mbox) as $aid) {
    list($c, $r) = $call(array('urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:sieve'), 'SieveScript/get', array('accountId' => $aid));
    show("SieveScript/get as the administrator for $aid ($c)", $r['methodResponses'][0] ?? $r);
}
// a master-user style login (user%admin)
list($c, $s2) = http('/jmap/session', $mbox . '%' . $u, $p);
show("session as $mbox%admin ($c)", array_keys((array) (json_decode($s2, true)['accounts'] ?? array())));
// the mailbox itself (its own password): what a user's session offers
if (isset($argv[3])) {
    list($c, $s3) = http('/jmap/session', $mbox, $argv[3]);
    $j3 = json_decode($s3, true);
    show("session as $mbox ($c): capabilities", array_keys((array) ($j3['capabilities'] ?? array())));
    show("session as $mbox: accounts", $j3['accounts'] ?? null);
}
