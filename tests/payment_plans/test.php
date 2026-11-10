<?php
// Payment Plans 102: the whole cycle against the stand-in panel and the fake PayPal and Stripe.
// Run from run.sh (it starts the fake services and the panel's web server, PP_FAKE and PP_WEB).
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
    $env = 'PP_REQ=' . escapeshellarg(json_encode(array('me' => $me, 'action' => $action, 'post' => $post, 'get' => $get)));
    $out = shell_exec($env . ' php ' . escapeshellarg($T . '/run_req.php') . ' 2>&1');
    $j = json_decode((string) $out, true);
    if (!is_array($j)) {
        check(false, "page request ($action) ran without PHP problems", (string) $out);
        return array('msg' => array(), 'go' => '', 'html' => '', 'text' => '');
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
function fake($what, $q = array())
{
    $ctx = stream_context_create(array('http' => array('ignore_errors' => true)));
    return json_decode(file_get_contents(getenv('PP_FAKE') . '/control/' . $what . '?' . http_build_query($q), false, $ctx), true);
}
function web($url, $post = false)
{
    $c = curl_init($url);
    curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false));
    if ($post) {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, '');
    }
    $r = curl_exec($c);
    $h = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return array($code, substr($r, 0, $h), substr($r, $h));
}
function row($sql, $a = array())
{
    global $zdbh;
    $q = $zdbh->prepare($sql);
    $q->execute($a);
    return $q->fetch(PDO::FETCH_ASSOC);
}
function events($like)
{
    global $zdbh;
    $q = $zdbh->prepare("SELECT ev FROM t_events WHERE ev LIKE :l");
    $q->execute(array(':l' => $like));
    return $q->fetchAll(PDO::FETCH_COLUMN);
}
function lastinv($acc)
{
    return row("SELECT * FROM x_pp_invoices WHERE in_acc_fk = :a ORDER BY in_id_pk DESC LIMIT 1", array(':a' => $acc));
}
function unthrottle($id)
{
    global $zdbh;
    $zdbh->exec("UPDATE x_pp_invoices SET in_order_in = in_order_in - 30 WHERE in_id_pk = " . (int) $id);
}
function sid($r)
{
    return basename((string) parse_url((string) $r['go'], PHP_URL_PATH));
}
function query_of($url)
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    return $q;
}

// ---------------------------------------------------------------- upgrade from 101
section('Upgrade from version 101: PayPal keys move, nothing else changes');
$db = dirname(getenv('PP_DB')) . '/upgrade.sqlite';
@unlink($db);
$old = json_decode(shell_exec('PP_DB=' . escapeshellarg($db) . ' php ' . escapeshellarg($T . '/old_setup.php') . ' 2>&1'), true);
check(is_array($old) && $old['ps_clientid_vc'] === 'LIVECLIENT01' && strpos($old['ps_secret_tx'], 'enc1:') === 0, 'version 101 stored its PayPal keys (encrypted)', json_encode($old));
$code = '
chdir("' . $T . '/root"); require "' . $T . '/root/dryden/loader.inc.php"; require "' . $T . '/root/modules/payment_plans/code/pp.php";
pp::Ensure();
$s = $zdbh->query("SELECT * FROM x_pp_sellers")->fetch(PDO::FETCH_ASSOC);
$i = $zdbh->query("SELECT * FROM x_pp_invoices")->fetch(PDO::FETCH_ASSOC);
echo json_encode(array("s" => $s, "i" => $i, "live" => pp_pay::Keys(5, "paypal", "live"), "sb" => pp_pay::Keys(5, "paypal", "sandbox"), "ready" => pp_pay::Ready(pp::Seller(5))));';
$up = json_decode(shell_exec('PP_DB=' . escapeshellarg($db) . ' php -r ' . escapeshellarg($code) . ' 2>&1'), true);
check(is_array($up), 'the upgrade ran', json_encode($up));
check($up['s']['ps_proc_vc'] === 'paypal', 'the seller keeps PayPal as its processor');
check($up['live'] === array('clientid' => 'LIVECLIENT01', 'secret' => 'LIVESECRET01'), 'live keys moved and still decrypt', json_encode($up['live']));
check($up['sb'] === array('clientid' => 'SBCLIENTID01', 'secret' => 'SBSECRET0001'), 'sandbox keys moved and still decrypt', json_encode($up['sb']));
check($up['s']['ps_clientid_vc'] === '' && $up['s']['ps_secret_tx'] === null && $up['s']['ps_sbsecret_tx'] === null, 'the old copies of the keys are cleared');
check($up['ready'] === true, 'live PayPal is ready after the upgrade');
check($up['i']['in_proc_vc'] === 'paypal' && $up['i']['in_order_vc'] === 'OLDORDER123', 'an open PayPal order is still known as PayPal\'s');
check((int) $up['s']['ps_on_in'] === 1, 'a seller that was billing before the switch existed stays on');
check((int) $up['s']['ps_auto_in'] === 1 && (int) $up['s']['ps_autofrom_in'] > time() - 60, 'automatic plans start from the upgrade (existing clients are not billed by surprise)');
$again = shell_exec('PP_DB=' . escapeshellarg($db) . ' php -r ' . escapeshellarg($code) . ' 2>&1');
check(json_decode($again, true)['live'] === $up['live'], 'running the upgrade again changes nothing');

// ---------------------------------------------------------------- the stand-in panel
$db = getenv('PP_DB');
chdir($T . '/root');
require $T . '/root/dryden/loader.inc.php';
require $T . '/root/modules/payment_plans/code/pp.php';
pp::Ensure();
$now = time();
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_email_vc, ac_reseller_fk, ac_package_fk, ac_group_fk, ac_created_ts) VALUES
    (1, 'zadmin', 'admin@example.com', 1, 1, 1, 1000),
    (2, 'res1', 'res1@example.com', 1, 1, 2, 2000),
    (3, 'admin2', 'a2@example.com', 1, 1, 1, 3000),
    (10, 'c_old', 'old@example.com', 2, 2, 3, 5000)");
$zdbh->exec("INSERT INTO x_packages (pk_id_pk, pk_name_vc, pk_reseller_fk) VALUES (1, 'AdminPkg', 1), (2, 'Basic', 2), (3, 'Pro', 2), (4, 'Free', 2)");

$settings = array('inName' => 'Res One Hosting', 'inAddress' => "1 Main St\nTown", 'inEmail' => 'billing@res1.example', 'inCurrency' => 'USD', 'inTaxLabel' => 'VAT',
    'inTaxRate' => '20', 'inPrefix' => 'R1-', 'inNext' => '1', 'inLead' => '7', 'inGrace' => '3', 'inSuspend' => '1', 'inTest' => '1', 'inProc' => 'paypal',
    'inAuto' => '1', 'inUsual' => 'month');

section('Your packages: each one priced on its own page (before any settings)');
$zdbh->exec("INSERT INTO x_quotas (qt_package_fk, qt_domains_in, qt_subdomains_in, qt_mailboxes_in, qt_mysql_in, qt_diskspace_bi, qt_bandwidth_bi) VALUES (2, 5, -1, 10, 3, " . (2000 * 1024000) . ", 0)");
$r = req(2);
check(strpos($r['html'], 'Your packages') !== false && strpos($r['html'], 'package=2') !== false && strpos($r['html'], 'package=3') !== false && strpos($r['html'], 'package=1"') === false,
    'a new seller sees its own packages at once (not the administrator\'s), each one a link', $r['html']);
check(strpos($r['html'], 'Your packages') < strpos($r['html'], 'Your details and payments'), 'the packages come before the settings');
$r = req(2, '', array(), array('package' => '2'));
check(strpos($r['html'], '<h3>Basic</h3>') !== false && strpos($r['html'], 'Domains</span><b>5</b>') !== false && strpos($r['html'], 'Sub-domains</span><b>Unlimited</b>') !== false
    && strpos($r['html'], 'Disk space</span><b>2,000 MB</b>') !== false && strpos($r['html'], 'Monthly bandwidth</span><b>Unlimited</b>') !== false, 'the package\'s page shows what it includes, as Package Manager made it', $r['html']);
$r = req(2, 'Prices', array('inMonth4' => '3.50', 'inYear4' => ''), array('package' => '4'));
check(strpos($r['text'], 'The price of Free is saved') !== false && pp::Prices(2)[4] === array(350, 0), 'its price is saved from its page (even before the settings)', $r['text']);
check(strpos($r['html'], 'value="3.50"') !== false, 'and the page shows it');
$r = req(2, 'Prices', array('inMonth4' => 'abc'), array('package' => '4'));
check(strpos($r['text'], 'is not an amount') !== false && pp::Prices(2)[4] === array(350, 0), 'a price that is not an amount is refused', $r['text']);
$r = req(2, 'Prices', array('inMonth1' => '1.00'), array('package' => '1'));
check(!isset(pp::Prices(2)[1]) && !isset(pp::Prices(1)[1]), 'a package that is not the seller\'s can not be priced');
check(strpos(req(2, '', array(), array('package' => '1'))['html'], 'No such package of yours') !== false, 'nor opened');
$zdbh->exec("UPDATE x_pp_prices SET pr_month_in = 0 WHERE pr_package_fk = 4");
$r = req(2);
check(strpos($r['html'], 'No price yet: set it') !== false, 'packages without a price say so');
check(strpos($r['html'], 'Payment Plans is off') !== false, 'a new seller starts with Payment Plans off');
$r = req(2, 'OnOff', array('inOn' => '1'));
check(strpos($r['text'], 'Save Your details and payments first') !== false && pp::Seller(2)['new'] ?? false, 'it can not be turned on before the details are saved', $r['text']);

section('Seller settings: choosing PayPal, keys checked');
$r = req(2, 'Settings', $settings + array('inK_paypal_sandbox_clientid' => 'SBCLIENTID01', 'inK_paypal_sandbox_secret' => 'WRONGSECRET'));
check(strpos($r['text'], 'PayPal refused') !== false, 'a wrong secret is reported by PayPal', $r['text']);
$r = req(2, 'Settings', $settings + array('inK_paypal_sandbox_clientid' => 'SBCLIENTID01', 'inK_paypal_sandbox_secret' => 'SBSECRET0001'));
check(strpos($r['text'], 'PayPal accepts your keys (test mode') !== false, 'the right keys are accepted', $r['text']);
check((int) pp::Seller(2)['ps_on_in'] === 0 && strpos(req(2)['html'], 'Payment Plans is off') !== false, 'saving the settings does not turn billing on by itself');
$r = req(2, 'OnOff', array('inOn' => '1'));
check(strpos($r['text'], 'Payment Plans is on') !== false && (int) pp::Seller(2)['ps_on_in'] === 1, 'Turn on', $r['text']);
$stored = row("SELECT pk_data_tx FROM x_pp_keys WHERE pk_seller_fk = 2 AND pk_proc_vc = 'paypal' AND pk_mode_vc = 'sandbox'");
check($stored && strpos($stored['pk_data_tx'], 'SBSECRET0001') === false && strpos($stored['pk_data_tx'], 'enc1:') !== false, 'the secret is stored encrypted, never in plain text', json_encode($stored));
$r = req(2, 'Settings', $settings + array('inK_paypal_sandbox_clientid' => 'ANOTHERCLIENT'));
check(strpos($r['text'], 'Give the secret that belongs to the new PayPal keys') !== false, 'a new client ID without its secret is refused', $r['text']);
$r = req(2, 'Settings', $settings + array('inK_stripe_sandbox_secret' => 'sk_live_abcdefghijklmnop'));
check(strpos($r['text'], 'That is not a Secret key for Stripe (test mode)') !== false, 'a live Stripe key in the test field is refused', $r['text']);
$r = req(2, 'Settings', array('inProc' => 'bitcoin') + $settings);
check(strpos($r['text'], 'Choose how your clients pay') !== false, 'an unknown processor is refused', $r['text']);
$r = req(2, 'Settings', $settings);
check(strpos($r['text'], 'error') === false && pp_pay::Ready(pp::Seller(2)), 'saving again with empty secrets keeps the keys', $r['text']);
$r = req(2, '', array(), array());
check(strpos($r['html'], 'PayPal keys') !== false && strpos($r['html'], 'Stripe keys') !== false && strpos($r['html'], 'SBSECRET0001') === false,
    'the page offers both processors and never shows a secret');

section('Prices');
$r = req(2, 'Prices', array('inMonth2' => '10.00', 'inYear2' => '100', 'inMonth3' => '', 'inYear3' => '99,00', 'inMonth4' => '', 'inYear4' => ''));
check(pp::Prices(2) === array(2 => array(1000, 10000), 3 => array(0, 9900), 4 => array(0, 0)), 'prices saved in cents', json_encode(pp::Prices(2)));
$r = req(2, 'Prices', array('inMonth3' => '7.00', 'inYear3' => '99.00'), array('package' => '3'));
check(pp::Prices(2) === array(2 => array(1000, 10000), 3 => array(700, 9900), 4 => array(0, 0)), 'saving one package\'s page changes no other package', json_encode(pp::Prices(2)));
req(2, 'Prices', array('inMonth3' => '', 'inYear3' => '99.00'), array('package' => '3'));
$r = req(2, '', array(), array('package' => '2'));
check(strpos($r['html'], 'before VAT (20%)') !== false, 'the price is shown before tax, with the rate');
check(strpos(req(11, '', array(), array('package' => '2'))['html'], 'value="10.00"') === false, 'a client can not open the seller\'s package page');

section('Automatic billing waits for live mode; plans started by hand while testing');
$s2 = pp::Seller(2);
$t = (int) $s2['ps_autofrom_in'];
check($t > $now - 60, 'automatic billing counts from the seller\'s first save');
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_email_vc, ac_reseller_fk, ac_package_fk, ac_group_fk, ac_created_ts) VALUES
    (11, 'c_new', 'new@example.com', 2, 2, 3, " . ($t + 1) . "),
    (12, 'c_pro', 'pro@example.com', 2, 3, 3, " . ($t + 2) . "),
    (13, 'c_free', 'free@example.com', 2, 4, 3, " . ($t + 3) . "),
    (14, 'c_gone', 'gone@example.com', 2, 2, 3, " . ($t + 4) . ")");
$zdbh->exec("UPDATE x_accounts SET ac_deleted_ts = 1 WHERE ac_id_pk = 14");
$out = daemon();
check(pp::Plan(11) === null && pp::Plan(12) === null, 'in test mode, new clients are not billed by themselves (real clients would get TEST invoices)', $out);
$r = req(2, 'Plan', array('inClient' => '11', 'inPeriod' => 'month', 'inPaidUntil' => date('Y-m-d')));
$r = req(2, 'Plan', array('inClient' => '12', 'inPeriod' => 'year', 'inPaidUntil' => date('Y-m-d')));
$r = req(2, 'Plan', array('inClient' => '13', 'inPeriod' => 'month', 'inPaidUntil' => date('Y-m-d')));
check(strpos($r['text'], 'has no monthly price') !== false && pp::Plan(13) === null, 'a package without a price can not be billed', $r['text']);
daemon();
$p11 = pp::Plan(11);
$p12 = pp::Plan(12);
check($p11 && $p11['pl_period_vc'] === 'month' && (int) $p11['pl_seller_fk'] === 2 && (int) $p11['pl_realuntil_in'] === (int) $p11['pl_paiduntil_in'], 'c_new is billed monthly by its reseller');
check($p12 && $p12['pl_period_vc'] === 'year', 'c_pro is billed yearly');
$i11 = lastinv(11);
check($i11 && $i11['in_number_vc'] === 'TEST-00001' && (int) $i11['in_total_in'] === 1200 && (int) $i11['in_tax_in'] === 200, 'c_new\'s first invoice comes at once: TEST-00001, 10.00 + 20% VAT = 12.00', json_encode($i11));
check((int) $i11['in_due_in'] <= time(), 'it is due at once');
$i12 = lastinv(12);
check($i12 && (int) $i12['in_total_in'] === 11880, 'c_pro\'s first invoice: 99.00 + 20% = 118.80');
check(count(events('email:new@example.com:Res One Hosting: TEST: Invoice TEST-00001: 12.00 USD:%within 3 days, or your hosting is switched off%pay.php?i=%')) === 1, 'c_new gets the invoice by email, with the grace days and a pay link');
check(count(events('email:new@example.com:Res One Hosting: TEST: Invoice TEST-00001 is due:%')) === 0, 'and no second "is due" email at the same time');
check(count(events('notice:pp-invoice-' . $i11['in_id_pk'] . ':info:%')) === 1, 'and a notice in the panel');
daemon();
check((int) row("SELECT COUNT(*) n FROM x_pp_invoices WHERE in_acc_fk = 11")['n'] === 1, 'the next daemon run makes no second invoice');
$r = req(2, 'InvoiceNow', array('inClient' => '11'));
check(strpos($r['text'], 'An invoice is open already') !== false, '"Invoice now" while one is open makes none', $r['text']);

section('Existing clients: billing them all at once');
$r = req(2);
check(strpos($r['html'], '1 client(s) on a priced package are not billed yet') !== false, 'the page says 1 client is not billed yet (c_old; c_free has no price)');
$r = req(2, 'StartAll', array('inPaidUntil' => date('Y-m-d', $now + 30 * 86400)));
check(strpos($r['text'], '1 client(s) are billed from') !== false, 'Start billing them', $r['text']);
$p10 = pp::Plan(10);
check($p10 && $p10['pl_period_vc'] === 'month', 'c_old is billed monthly from the date given');
daemon();
check(lastinv(10) === false, 'c_old gets no invoice yet (paid until in 30 days, invoices 7 days ahead)');
$zdbh->exec("UPDATE x_pp_plans SET pl_paiduntil_in = " . ($now + 3 * 86400) . " WHERE pl_acc_fk = 10");
daemon();
check(lastinv(10) && lastinv(10)['in_number_vc'] === 'TEST-00003', 'within the 7 days, c_old\'s invoice is made');
$r = req(2, 'StartAll', array('inPaidUntil' => 'yesterday'));
check(strpos($r['text'], 'Give the date') !== false, 'a wrong date is refused');

section('Paying with PayPal (panel page)');
$r = req(11, 'Pay', array('inId' => (string) $i11['in_id_pk']));
check(strpos($r['go'], getenv('PP_FAKE') . '/pages/paypal/') === 0, 'the client is sent to PayPal\'s page', json_encode($r));
$order = query_of($r['go'])['token'];
$i = lastinv(11);
check($i['in_order_vc'] === $order && $i['in_proc_vc'] === 'paypal' && $i['in_ordermode_vc'] === 'sandbox', 'the order is noted on the invoice (PayPal, test mode)');
$r = req(11, '', array(), array('pp' => 'return', 'i' => (string) $i11['in_id_pk'], 'token' => $order));
check(strpos($r['text'], 'was not taken') !== false && lastinv(11)['in_status_vc'] === 'open', 'coming back without paying pays nothing', $r['text']);
fake('paypal', array('o' => $order));
$r = req(12, '', array(), array('pp' => 'return', 'i' => (string) $i11['in_id_pk'], 'token' => $order));
check(strpos($r['text'], 'not for one of your invoices') !== false && lastinv(11)['in_status_vc'] === 'open', 'another client can not settle it', $r['text']);
$r = req(11, '', array(), array('pp' => 'return', 'i' => (string) $i11['in_id_pk'], 'token' => 'SOMEOTHERORDER'));
check(strpos($r['text'], 'not for one of your invoices') !== false, 'another order\'s reference is refused', $r['text']);
$r = req(11, '', array(), array('pp' => 'return', 'i' => (string) $i11['in_id_pk'], 'token' => $order));
$i = lastinv(11);
check(strpos($r['text'], 'Thank you: invoice TEST-00001 is paid') !== false && $i['in_status_vc'] === 'paid' && $i['in_method_vc'] === 'PayPal'
    && strpos($i['in_capture_vc'], 'CAP') === 0, 'approved at PayPal: captured, paid', $r['text']);
check((int) pp::Plan(11)['pl_paiduntil_in'] === (int) $i['in_to_in'] && (int) $i['in_to_in'] > $now + 27 * 86400, 'c_new is paid a month further');
check(count(events('withdraw:pp-invoice-' . $i11['in_id_pk'])) >= 1, 'its notice is taken away');
$r = req(11, '', array(), array('pp' => 'return', 'i' => (string) $i11['in_id_pk'], 'token' => $order));
check(strpos($r['text'], 'is paid. Thank you') !== false && (int) pp::Plan(11)['pl_paiduntil_in'] === (int) $i['in_to_in'], 'coming back twice pays nothing twice');

section('PayPal: what does not count');
$r = req(12, 'Pay', array('inId' => (string) $i12['in_id_pk']));
$order = query_of($r['go'])['token'];
fake('paypal', array('o' => $order, 'capvalue' => '1.00'));
$r = req(12, '', array(), array('pp' => 'return', 'i' => (string) $i12['in_id_pk'], 'token' => $order));
check(strpos($r['text'], 'is not the invoice\'s amount: not counted') !== false && lastinv(12)['in_status_vc'] === 'open', 'a capture of another amount is not counted', $r['text']);
unthrottle($i12['in_id_pk']);
$r = req(12, 'Pay', array('inId' => (string) $i12['in_id_pk']));
$order = query_of($r['go'])['token'];
fake('paypal', array('o' => $order, 'capcustom' => '999'));
$r = req(12, '', array(), array('pp' => 'return', 'i' => (string) $i12['in_id_pk'], 'token' => $order));
check(strpos($r['text'], 'not for this invoice') !== false && lastinv(12)['in_status_vc'] === 'open', 'a capture naming another invoice is not counted', $r['text']);
unthrottle($i12['in_id_pk']);
$r = req(12, 'Pay', array('inId' => (string) $i12['in_id_pk']));
$order = query_of($r['go'])['token'];
fake('paypal', array('o' => $order, 'capstatus' => 'PENDING'));
$r = req(12, '', array(), array('pp' => 'return', 'i' => (string) $i12['in_id_pk'], 'token' => $order));
check(strpos($r['text'], 'being checked') !== false && lastinv(12)['in_status_vc'] === 'pending', 'a pending capture leaves the invoice pending', $r['text']);
fake('capture', array('c' => lastinv(12)['in_capture_vc'], 'status' => 'COMPLETED'));
daemon();
check(lastinv(12)['in_status_vc'] === 'paid', 'the daemon settles it once PayPal completes it');

section('PayPal: the way back of a payment made before the upgrade');
$i10 = lastinv(10);
$r = req(10, 'Pay', array('inId' => (string) $i10['in_id_pk']));
$order = query_of($r['go'])['token'];
fake('paypal', array('o' => $order));
$r = req(10, '', array(), array('paypal' => 'return', 'token' => $order));
check(strpos($r['text'], 'Thank you: invoice TEST-00003 is paid') !== false, '?paypal=return&token= still works', $r['text']);

section('Choosing Stripe instead');
$st = array('inProc' => 'stripe') + $settings;
$r = req(2, 'Settings', $st);
check(strpos($r['text'], 'Give your Stripe keys for test mode') !== false, 'choosing Stripe without keys says so', $r['text']);
$r = req(2, 'Settings', $st + array('inK_stripe_sandbox_secret' => 'sk_test_BADKEY12345678'));
check(strpos($r['text'], 'Stripe refused the secret key') !== false, 'a key Stripe refuses is reported', $r['text']);
$r = req(2, 'Settings', $st + array('inK_stripe_sandbox_secret' => 'sk_test_GoodKey12345678'));
check(strpos($r['text'], 'Stripe accepts your keys (test mode') !== false, 'Stripe accepts the test key', $r['text']);
check(pp_pay::Ready(pp::Seller(2), 'paypal', 'sandbox'), 'the PayPal keys are kept for switching back');
$r = req(2, 'TestKeys');
check(strpos($r['text'], 'Stripe accepts your keys') !== false, 'Test Stripe keys button', $r['text']);

section('Paying with Stripe (the pay link, no login)');
$zdbh->exec("UPDATE x_pp_plans SET pl_paiduntil_in = " . ($now + 86400) . " WHERE pl_acc_fk = 11");
daemon();
$inv = lastinv(11);
check($inv['in_status_vc'] === 'open' && $inv['in_number_vc'] === 'TEST-00004', 'c_new\'s next invoice is made');
$link = str_replace('https://panel.example', getenv('PP_WEB'), pp::PayLink($inv));
list($code, $h, $b) = web($link);
check($code === 200 && strpos($b, 'Pay with Stripe') !== false && strpos($b, '12.00 USD') !== false, 'the pay link shows the invoice and "Pay with Stripe"', $code . ' ' . $b);
check(stripos($h, 'Content-Security-Policy:') !== false && strpos($h, getenv('PP_FAKE') . '/pages/stripe') !== false && stripos($h, 'Referrer-Policy: no-referrer') !== false,
    'its page may lead only to the panel and the processors\' pages, and sends no referrer', $h);
list($code) = web(preg_replace('/k=[0-9a-f]+/', 'k=' . str_repeat('0', 40), $link));
check($code === 404, 'a wrong key opens nothing');
list($code, $h) = web($link, true);
check($code === 302 && preg_match('#Location: (' . preg_quote(getenv('PP_FAKE'), '#') . '/pages/stripe/c/pay/(cs_test_\w+))#', $h, $m), 'paying sends the client to Stripe\'s checkout', $h);
$sid = $m[2];
$state = fake('state');
check($state['stripe'][$sid]['amount_total'] === 1200 && $state['stripe'][$sid]['currency'] === 'usd' && $state['stripe'][$sid]['client_reference_id'] === (string) $inv['in_id_pk']
    && $state['stripe'][$sid]['customer_email'] === 'new@example.com', 'Stripe was asked for exactly 12.00 USD for this invoice', json_encode($state['stripe'][$sid]));
$back = str_replace('{CHECKOUT_SESSION_ID}', $sid, $state['stripe'][$sid]['success_url']);
list($code, $h, $b) = web($back);
check(strpos($b, 'The payment was not taken') !== false && lastinv(11)['in_status_vc'] === 'open', 'back without paying: nothing paid', $b);
fake('stripe', array('s' => $sid, 'pay' => 'paid'));
list($code, $h, $b) = web(str_replace($sid, 'cs_test_someoneelse1234567', $back));
check(lastinv(11)['in_status_vc'] === 'open', 'another session ID in the address settles nothing');
list($code, $h, $b) = web($back);
$inv = lastinv(11);
check(strpos($b, 'Thank you: the invoice is paid') !== false && $inv['in_status_vc'] === 'paid' && $inv['in_method_vc'] === 'Stripe' && strpos($inv['in_capture_vc'], 'pi_') === 0,
    'paid at Stripe: the invoice is paid (Stripe, payment pi_...)', $b);

section('Stripe: what does not count');
$cases = array(
    'another amount' => array(array('pay' => 'paid', 'amount_total' => 100), 'not the invoice\'s amount: not counted'),
    'another currency' => array(array('pay' => 'paid', 'currency' => 'eur'), 'not the invoice\'s amount: not counted'),
    'another invoice' => array(array('pay' => 'paid', 'client_reference_id' => '999'), 'not for this invoice'),
    'a live payment on a test invoice' => array(array('pay' => 'paid', 'livemode' => '1'), 'not in test mode: not counted'),
);
foreach ($cases as $what => $c) {
    $res = req(2, 'InvoiceNow', array('inClient' => '11'));
    $inv = lastinv(11);
    $r = req(11, 'Pay', array('inId' => (string) $inv['in_id_pk']));
    $sid = basename(parse_url($r['go'], PHP_URL_PATH));
    fake('stripe', array('s' => $sid) + $c[0]);
    $r = req(11, '', array(), array('pp' => 'return', 'i' => (string) $inv['in_id_pk'], 'session_id' => $sid));
    check(strpos($r['text'], $c[1]) !== false && lastinv(11)['in_status_vc'] === 'open', 'Stripe, ' . $what . ': not counted', $res['text'] . ' | ' . $r['text']);
    req(2, 'Invoice', array('inId' => (string) $inv['in_id_pk'], 'inVoid' => '1'));
}

section('Stripe: one invoice is never paid twice');
req(2, 'InvoiceNow', array('inClient' => '11'));
$inv = lastinv(11);
$r = req(11, 'Pay', array('inId' => (string) $inv['in_id_pk']));
$a = sid($r);
$r = req(11, 'Pay', array('inId' => (string) $inv['in_id_pk']));
check($r['go'] === '' && strpos($r['text'], 'wait a few seconds') !== false, 'clicking Pay again at once starts no second payment', $r['text']);
unthrottle($inv['in_id_pk']);
$r = req(11, 'Pay', array('inId' => (string) $inv['in_id_pk']));
$b = sid($r);
$state = fake('state');
check($b !== $a && strpos($b, 'cs_test_') === 0 && $state['stripe'][$a]['status'] === 'expired', 'Pay again later: the first checkout page is closed at Stripe before a new one opens', json_encode($state['stripe'][$a]['status']));
check(in_array('closed', array_column(pp::History((int) $inv['in_id_pk']), 'lg_what_vc'), true), 'the closing is in the invoice\'s history');
$res = fake('stripe', array('s' => $a, 'pay' => 'paid'));
check(isset($res['error']) && fake('state')['stripe'][$a]['status'] === 'expired', 'the old tab can not take money any more', json_encode($res));
fake('stripe', array('s' => $b, 'pay' => 'paid'));
unthrottle($inv['in_id_pk']);
$r = req(11, 'Pay', array('inId' => (string) $inv['in_id_pk']));
check($r['go'] === '' && isset($r['msg'][0]) && $r['msg'][0][0] === 'ok' && strpos($r['text'], 'paid already') !== false && lastinv(11)['in_status_vc'] === 'paid',
    'paid in the other tab, then Pay again: the payment is found, nothing is paid twice', $r['text']);
req(2, 'InvoiceNow', array('inClient' => '11'));
$inv = lastinv(11);
$c = sid(req(11, 'Pay', array('inId' => (string) $inv['in_id_pk'])));
$r = req(2, 'Invoice', array('inId' => (string) $inv['in_id_pk'], 'inVoid' => '1'));
check(lastinv(11)['in_status_vc'] === 'void' && fake('state')['stripe'][$c]['status'] === 'expired', 'voiding an invoice closes its open checkout page (no money for a void invoice)', $r['text']);
req(2, 'InvoiceNow', array('inClient' => '11'));
$inv = lastinv(11);
$d = sid(req(11, 'Pay', array('inId' => (string) $inv['in_id_pk'])));
fake('stripe', array('s' => $d, 'pay' => 'paid'));
$r = req(2, 'Invoice', array('inId' => (string) $inv['in_id_pk'], 'inVoid' => '1'));
check(strpos($r['text'], 'was just paid: it is not voided') !== false && lastinv(11)['in_status_vc'] === 'paid', 'voiding an invoice the client just paid: it is kept, paid', $r['text']);

section('Stripe: a payment that takes time, and one that expires');
req(2, 'InvoiceNow', array('inClient' => '11'));
$inv = lastinv(11);
$r = req(11, 'Pay', array('inId' => (string) $inv['in_id_pk']));
$sid = basename(parse_url($r['go'], PHP_URL_PATH));
$pi = fake('stripe', array('s' => $sid, 'pay' => 'unpaid'))['pi'];
$r = req(11, '', array(), array('pp' => 'return', 'i' => (string) $inv['in_id_pk'], 'session_id' => $sid));
check(strpos($r['text'], 'being checked') !== false && lastinv(11)['in_status_vc'] === 'pending', 'a bank debit still processing: pending', $r['text']);
daemon();
check(lastinv(11)['in_status_vc'] === 'pending', 'still processing: the daemon leaves it pending');
fake('pi', array('pi' => $pi, 'status' => 'succeeded'));
daemon();
check(lastinv(11)['in_status_vc'] === 'paid', 'when it succeeds, the daemon marks it paid');
req(2, 'InvoiceNow', array('inClient' => '11'));
$inv = lastinv(11);
$r = req(11, 'Pay', array('inId' => (string) $inv['in_id_pk']));
$sid = basename(parse_url($r['go'], PHP_URL_PATH));
$pi = fake('stripe', array('s' => $sid, 'pay' => 'unpaid'))['pi'];
req(11, '', array(), array('pp' => 'return', 'i' => (string) $inv['in_id_pk'], 'session_id' => $sid));
fake('pi', array('pi' => $pi, 'status' => 'requires_payment_method'));
daemon();
$i = lastinv(11);
check($i['in_status_vc'] === 'open' && $i['in_order_vc'] === null, 'a bank debit that fails: the invoice is open to be paid again');
$r = req(11, 'Pay', array('inId' => (string) $inv['in_id_pk']));
$sid = basename(parse_url($r['go'], PHP_URL_PATH));
fake('stripe', array('s' => $sid, 'pay' => 'expired'));
$zdbh->exec("UPDATE x_pp_invoices SET in_order_in = in_order_in - 300 WHERE in_id_pk = " . (int) $inv['in_id_pk']);
daemon();
$i = lastinv(11);
check($i['in_status_vc'] === 'open' && $i['in_order_vc'] === null, 'a checkout page that expired: the invoice is open to be paid again');
$r = req(11, 'Pay', array('inId' => (string) $inv['in_id_pk']));
$sid = basename(parse_url($r['go'], PHP_URL_PATH));
fake('stripe', array('s' => $sid, 'pay' => 'paid'));
$zdbh->exec("UPDATE x_pp_invoices SET in_order_in = in_order_in - 300 WHERE in_id_pk = " . (int) $inv['in_id_pk']);
daemon();
check(lastinv(11)['in_status_vc'] === 'paid', 'a client who paid and never came back: the daemon finds it paid');

section('Unpaid: switched off, then on again');
req(2, 'InvoiceNow', array('inClient' => '11'));
$inv = lastinv(11);
$zdbh->exec("UPDATE x_pp_sellers SET ps_grace_in = 0 WHERE ps_acc_fk = 2");
$zdbh->exec("UPDATE x_pp_invoices SET in_due_in = " . (time() - 60) . " WHERE in_id_pk = " . (int) $inv['in_id_pk']);
$r = req(2, 'TestTools', array('inWhat' => 'run'));
check((int) row("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = 11")['ac_enabled_in'] === 1, 'with 0 grace days, nobody is switched off within a day of the invoice being made', $r['text']);
req(2, 'TestTools', array('inWhat' => 'overdue', 'inId' => (string) $inv['in_id_pk']));
$r = req(2, 'TestTools', array('inWhat' => 'run'));
$a = row("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = 11");
check(strpos($r['text'], 'switched off') !== false && (int) $a['ac_enabled_in'] === 0 && ws_suspend::Reason(11) === pp::REASON . $inv['in_number_vc'], 'past the grace days: c_new is switched off, for this invoice', $r['text']);
check(count(events('hook:OnAfterDisableClient')) >= 1, 'the panel\'s switch-off hooks ran');
$link = str_replace('https://panel.example', getenv('PP_WEB'), pp::PayLink($inv));
list($code, $h) = web($link, true);
preg_match('#/pages/stripe/c/pay/(cs_test_\w+)#', $h, $m);
fake('stripe', array('s' => $m[1], 'pay' => 'paid'));
$state = fake('state');
list($code, $h, $b) = web(str_replace('{CHECKOUT_SESSION_ID}', $m[1], $state['stripe'][$m[1]]['success_url']));
$a = row("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = 11");
check(strpos($b, 'the invoice is paid') !== false && (int) $a['ac_enabled_in'] === 1 && ws_suspend::Reason(11) === '', 'paid through the pay link (no login): switched on again', $b);
ws_suspend::Set(12, 'Abuse', 1);
$zdbh->exec("UPDATE x_accounts SET ac_enabled_in = 0 WHERE ac_id_pk = 12");
$zdbh->exec("UPDATE x_pp_plans SET pl_suspended_in = 1 WHERE pl_acc_fk = 12");
req(2, 'InvoiceNow', array('inClient' => '12'));
$manual12 = (int) lastinv(12)['in_id_pk'];
req(2, 'Invoice', array('inId' => (string) $manual12, 'inMethod' => 'Bank transfer'));
$zdbh->exec("UPDATE x_pp_sellers SET ps_grace_in = 3 WHERE ps_acc_fk = 2");
check(lastinv(12)['in_status_vc'] === 'paid' && (int) row("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = 12")['ac_enabled_in'] === 0, 'paying never switches on a client switched off for another reason');

section('Stopped plans, removing keys, going live');
req(2, 'Plan', array('inClient' => '11', 'inStop' => '1'));
daemon();
check((int) pp::Plan(11)['pl_active_in'] === 0, 'a plan the seller stopped is not started again automatically');
$r = req(2, 'Settings', $st + array('inKDrop_paypal_sandbox' => '1'));
check(!pp_pay::Ready(pp::Seller(2), 'paypal', 'sandbox') && pp_pay::Ready(pp::Seller(2)), 'Remove these keys takes PayPal\'s away, Stripe\'s stay', $r['text']);
// before going live: c_old switched off for a TEST invoice; c_era made while testing
req(2, 'InvoiceNow', array('inClient' => '10'));
req(2, 'TestTools', array('inWhat' => 'overdue', 'inId' => (string) lastinv(10)['in_id_pk']));
req(2, 'TestTools', array('inWhat' => 'run'));
check((int) row("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = 10")['ac_enabled_in'] === 0, 'while testing, c_old is switched off for a TEST invoice');
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_email_vc, ac_reseller_fk, ac_package_fk, ac_group_fk, ac_created_ts) VALUES (16, 'c_era', 'era@example.com', 2, 2, 3, " . (time() - 5) . ")");
check((int) pp::Plan(12)['pl_paiduntil_in'] > time() + 300 * 86400, 'c_pro "paid" a year ahead with test money');
$live = array('inTest' => '0') + $st;
$r = req(2, 'Settings', $live);
check(strpos($r['text'], 'Give your Stripe keys for live') !== false, 'going live without live keys says so', $r['text']);
check(preg_match('/Live: (\d+) plan\(s\) moved by test payments are back to the date really paid until, and 1 client\(s\) switched off for a test invoice are on again/', $r['text'], $mm) && (int) $mm[1] >= 2,
    'going live undoes the testing: plans back to the date really paid until, the TEST switch-off lifted', $r['text']);
foreach (array(10, 11, 12) as $u) {
    $p = pp::Plan($u);
    check((int) $p['pl_paiduntil_in'] === (int) $p['pl_realuntil_in'], 'account ' . $u . ': test payments bought no real time', json_encode($p));
}
check((int) row("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = 10")['ac_enabled_in'] === 1 && ws_suspend::Reason(10) === '', 'c_old is on again');
check(ws_suspend::Reason(12) === 'Abuse', 'c_pro, switched off for another reason, stays off');
$r = req(2, 'Settings', $live + array('inK_stripe_live_secret' => 'sk_live_LiveKey12345678'));
check(strpos($r['text'], 'Stripe accepts your keys (live)') !== false, 'live key accepted', $r['text']);
$test = lastinv(12);
check(!pp::CanPay(pp::Seller(2), array('in_test_in' => 1)), 'once live, a leftover TEST invoice can not be paid');
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_email_vc, ac_reseller_fk, ac_package_fk, ac_group_fk, ac_created_ts) VALUES (15, 'c_live', 'live@example.com', 2, 2, 3, " . time() . ")");
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_email_vc, ac_reseller_fk, ac_package_fk, ac_group_fk, ac_created_ts) VALUES
    (17, 'c_adm', 'adm@example.com', 2, 2, 1, " . time() . "), (18, 'c_gone2', 'g2@example.com', 2, 2, 3, " . time() . ")");
$zdbh->exec("UPDATE x_accounts SET ac_deleted_ts = 1 WHERE ac_id_pk = 18");
daemon();
$i15 = lastinv(15);
check($i15 && strpos($i15['in_number_vc'], 'R1-0000') === 0 && (int) $i15['in_test_in'] === 0 && (int) pp::Plan(15)['pl_seller_fk'] === 2, 'live: a new client is billed by itself, a real invoice at once', json_encode($i15));
check(lastinv(12) && lastinv(12)['in_number_vc'] !== '' && (int) lastinv(12)['in_test_in'] === 0, 'c_pro, who "paid" only with test money, gets a real invoice');
check(pp::Plan(16) === null && isset(pp::Unplanned(2)[16]), 'c_era (made while testing) is not billed by surprise: it waits for "Start billing them"');
check(pp::Plan(17) === null, 'an administrator is never billed');
check(pp::Plan(18) === null, 'a deleted client is not billed');
$r = req(15, 'Pay', array('inId' => (string) $i15['in_id_pk']));
$sid = basename(parse_url($r['go'], PHP_URL_PATH));
check(strpos($sid, 'cs_live_') === 0, 'a real invoice is paid with the live key', $r['go']);
fake('stripe', array('s' => $sid, 'pay' => 'paid'));
$r = req(15, '', array(), array('pp' => 'return', 'i' => (string) $i15['in_id_pk'], 'session_id' => $sid));
check(lastinv(15)['in_status_vc'] === 'paid', 'and counts', $r['text']);
$r = req(15, 'Pay', array('inId' => (string) $i15['in_id_pk']));
check(strpos($r['text'], 'not open') !== false, 'a paid invoice can not be paid again', $r['text']);

section('The administrator bills a reseller; amounts in other currencies');
$adm = array('inName' => 'Server Co', 'inProc' => '', 'inTest' => '0', 'inCurrency' => 'HUF', 'inTaxRate' => '') + $settings;
$r = req(1, 'Settings', $adm);
check(strpos($r['text'], 'Saved: live mode') !== false, 'an administrator with no online payment (marks invoices paid)', $r['text']);
req(1, 'OnOff', array('inOn' => '1'));
req(1, 'Prices', array('inMonth1' => '5000'));
$r = req(1);
check(strpos($r['html'], '1 client(s) on a priced package are not billed yet') !== false, 'res1 can be billed; admin2 (an administrator) is never counted', $r['html']);
req(1, 'StartAll', array('inPaidUntil' => date('Y-m-d')));
daemon();
$i2 = lastinv(2);
check($i2 && (int) $i2['in_total_in'] === 5000 && $i2['in_currency_vc'] === 'HUF', 'res1 is invoiced 5000 HUF by the administrator');
$r = req(2, '', array(), array('tab' => 'billing'));
check(strpos($r['html'], 'Pay as your provider tells you') !== false, 'res1 sees its own invoice (no online payment offered)');
check(pp_proc_stripe::Factor('HUF') === 100 && pp_proc_stripe::Factor('JPY') === 1 && pp_proc_stripe::Factor('USD') === 1, 'Stripe amounts: HUF in hundredths, JPY in yen, USD in cents');

section('Pages draw without PHP problems');
foreach (array(array(2, array()), array(2, array('tab' => 'billing')), array(11, array()), array(15, array()), array(2, array('invoice' => (string) $i15['in_id_pk'])),
    array(15, array('invoice' => (string) $i15['in_id_pk'])), array(10, array('invoice' => (string) $i15['in_id_pk']))) as $p) {
    $r = req($p[0], '', array(), $p[1]);
    check($r['html'] !== '', 'account ' . $p[0] . ' ' . json_encode($p[1]));
}
check(strpos(req(10, '', array(), array('invoice' => (string) $i15['in_id_pk']))['html'], 'No such invoice of yours') !== false, 'another client\'s invoice is not shown');

section('Figures and history');
$f = pp::Stats(2);
check($f['paid']['USD']['month'] === 1200 && $f['paid']['USD']['monthcount'] === 1, 'received this month: 12.00 USD (R1-00001; TEST invoices never count)', json_encode($f));
check($f['recurring'] === 3390 && $f['plans'] === 3, 'coming in each month: 12.00 + 12.00 + 118.80/12 = 33.90 USD from 3 active plans (the stopped one not counted)', json_encode($f));
check($f['open']['USD']['total'] === 11880 && $f['open']['USD']['count'] === 1, 'waiting to be paid: c_pro\'s real invoice, 118.80 USD', json_encode($f['open']));
$fa = pp::Stats(1);
check($fa['currency'] === 'HUF' && $fa['open']['HUF']['total'] === 5000 && $fa['recurring'] === 5000, 'the administrator: 5000 HUF waiting, 5000 HUF a month', json_encode($fa));
$r = req(2);
check(strpos($r['html'], 'Received this month</span><b>12.00 USD</b>') !== false && strpos($r['html'], '33.90 USD') !== false, 'the seller\'s page shows the figures at the top');
$r = req(11);
check(strpos($r['html'], 'pp-stats') === false, 'a client sees no figures');
$h = array_column(pp::History(1), 'lg_what_vc');
check(array_slice($h, 0, 1) === array('made') && in_array('started', $h, true) && end($h) === 'paid', 'TEST-00001\'s history: made, payment started, paid', json_encode($h));
$paid = array_values(array_filter(pp::History(1), function ($x) { return $x['lg_what_vc'] === 'paid'; }))[0];
check($paid['lg_note_vc'] === 'PayPal, buyer@example.com' && strpos($paid['lg_ref_vc'], 'CAP') === 0 && (int) $paid['lg_amount_in'] === 1200, 'the payment is noted with PayPal\'s payment ID, payer and amount', json_encode($paid));
$h2 = pp::History(2);
$ref = array_values(array_filter($h2, function ($x) { return $x['lg_what_vc'] === 'refused'; }));
check(count($ref) === 2 && $ref[0]['lg_note_vc'] === 'paid 1.00 USD' && $ref[1]['lg_note_vc'] === 'for invoice ID 999', 'refused payments are noted (the wrong amount, the wrong invoice)', json_encode($ref));
check(in_array('pending', array_column($h2, 'lg_what_vc'), true), 'a pending payment is noted');
$h12 = pp::History($manual12);
check(end($h12)['lg_what_vc'] === 'paid' && end($h12)['lg_note_vc'] === 'Bank transfer' && (int) end($h12)['lg_by_fk'] === 2, 'marked paid by hand: noted with who did it');
$off = row("SELECT COUNT(*) n FROM x_pp_log WHERE lg_acc_fk = 11 AND lg_what_vc IN ('off', 'on')");
check((int) $off['n'] === 2, 'switching off and on again is noted');
check((int) row("SELECT COUNT(*) n FROM x_pp_log WHERE lg_what_vc = 'void'")['n'] === 5, 'voided invoices are noted');
$r = req(12, '', array(), array('invoice' => (string) $manual12));
$r2 = req(2, '', array(), array('invoice' => (string) $manual12));
check(strpos($r['html'], 'Bank transfer') !== false && strpos($r['html'], 'by res1') === false && strpos($r2['html'], 'by res1') !== false,
    'the history shows who marked it paid to the seller only (a client never sees its seller\'s user name)');
$r = req(2, '', array(), array('invoice' => '2'));
check(strpos($r['html'], 'Payment not counted') !== false && strpos($r['html'], 'paid 1.00 USD') !== false, 'the invoice page shows its history');
$before = (int) row("SELECT COUNT(*) n FROM x_pp_log")['n'];
pp::Log(lastinv(11), 'refused', 'same', 'X1');
pp::Log(lastinv(11), 'refused', 'same', 'X1');
check((int) row("SELECT COUNT(*) n FROM x_pp_log")['n'] === $before + 1, 'the same step asked about again is noted once');
$n = req(2, 'TestTools', array('inWhat' => 'delete'));
check((int) row("SELECT COUNT(*) n FROM x_pp_log l JOIN x_pp_invoices i ON i.in_id_pk = l.lg_inv_fk WHERE i.in_test_in = 1")['n'] === 0
    && (int) row("SELECT COUNT(*) n FROM x_pp_log WHERE lg_inv_fk = " . (int) $i15['in_id_pk'])['n'] > 0, 'deleting test invoices takes their history too; real invoices keep theirs', $n['text']);

section('No key for stored passwords');
putenv('PP_NOKEY=1');
$r = req(2, 'Settings', $live + array('inK_paypal_live_clientid' => 'NEWCLIENTID9', 'inK_paypal_live_secret' => 'NEWSECRET999'));
putenv('PP_NOKEY');
check(strpos($r['text'], 'could not be stored') !== false && !pp_pay::Ready(pp::Seller(2), 'paypal', 'live'), 'a secret that can not be encrypted is not stored at all', $r['text']);

section('Payment Plans off: the payment check is skipped');
$overdue = function ($id) use ($zdbh) {
    $zdbh->exec("UPDATE x_pp_invoices SET in_due_in = " . (time() - 10 * 86400) . ", in_created_in = " . (time() - 11 * 86400) . ", in_reminded_in = 0 WHERE in_id_pk = " . (int) $id);
};
req(2, 'InvoiceNow', array('inClient' => '10'));
$i10 = lastinv(10);
$overdue($i10['in_id_pk']);
daemon();
check((int) row("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = 10")['ac_enabled_in'] === 0, '(c_old switched off for an unpaid invoice)');
$r = req(2, 'OnOff', array('inOn' => '0'));
check(strpos($r['text'], 'Payment Plans is off') !== false && strpos($r['text'], '1 client(s) switched off for an unpaid invoice are on again') !== false
    && (int) row("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = 10")['ac_enabled_in'] === 1, 'Turn off: clients switched off for an unpaid invoice are on again', $r['text']);
check(ws_suspend::Reason(12) === 'Abuse', 'a client switched off for another reason stays off');
req(2, 'Plan', array('inClient' => '16', 'inPeriod' => 'month', 'inPaidUntil' => date('Y-m-d')));
$n = (int) row("SELECT COUNT(*) n FROM x_pp_invoices")['n'];
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_email_vc, ac_reseller_fk, ac_package_fk, ac_group_fk, ac_created_ts) VALUES (19, 'c_off', 'off@example.com', 2, 2, 3, " . time() . ")");
$zdbh->exec("UPDATE x_pp_plans SET pl_suspended_in = 0 WHERE pl_acc_fk = 10");
$overdue($i10['in_id_pk']);
daemon();
check((int) row("SELECT COUNT(*) n FROM x_pp_invoices")['n'] === $n && pp::Plan(19) === null, 'while off: no invoices (c_era is due), no automatic plans (c_off is new)');
check((int) row("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = 10")['ac_enabled_in'] === 1, 'while off: nobody is switched off (c_old\'s invoice is overdue)');
$r = req(2, 'InvoiceNow', array('inClient' => '16'));
check(strpos($r['text'], 'Payment Plans is off') !== false, '"Invoice now" says it is off', $r['text']);
sleep(2);
req(2, 'OnOff', array('inOn' => '1'));
daemon();
check(pp::Plan(19) === null, 'turning on again bills by themselves only clients made from then on (c_off came before)');
check((int) row("SELECT COUNT(*) n FROM x_pp_invoices")['n'] > $n, 'and billing runs again (c_era\'s invoice is made)');

section('A client moved to another account');
$zdbh->exec("UPDATE x_accounts SET ac_reseller_fk = 1 WHERE ac_id_pk = 15");
$zdbh->exec("UPDATE x_pp_plans SET pl_paiduntil_in = " . time() . " WHERE pl_acc_fk = 15");
$n = (int) row("SELECT COUNT(*) n FROM x_pp_invoices WHERE in_acc_fk = 15")['n'];
$out = daemon();
check((int) pp::Plan(15)['pl_active_in'] === 0 && (int) row("SELECT COUNT(*) n FROM x_pp_invoices WHERE in_acc_fk = 15")['n'] === $n && strpos($out, 'belongs to another account now') !== false,
    'its old seller\'s plan stops (no invoice, no error every 5 minutes)', $out);
$out = daemon();
check(strpos($out, 'account 15') === false, 'and is not mentioned again', $out);

section('The daemon\'s hook can not be run from a browser');
$before = (int) row("SELECT COUNT(*) n FROM x_pp_invoices")['n'];
list($code, $h, $b) = web(getenv('PP_WEB') . '/modules/payment_plans/hooks/OnDaemonRun.hook.php');
check(trim($b) === '' && (int) row("SELECT COUNT(*) n FROM x_pp_invoices")['n'] === $before, 'asking for it does nothing', $code . ' ' . $b);

section('Removing the module');
$out = shell_exec('php -r ' . escapeshellarg('chdir("' . $T . '/root"); require "dryden/loader.inc.php"; include "modules/payment_plans/deploy/remove.run";') . ' 2>&1');
check((int) row("SELECT COUNT(*) n FROM x_pp_keys")['n'] === 0 && (int) row("SELECT COUNT(*) n FROM x_pp_invoices")['n'] > 0 && (int) row("SELECT COUNT(*) n FROM x_pp_log")['n'] > 0,
    'the payment keys are deleted; invoices and their history stay', $out);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
