<?php

/**
 * Payment Plans: for an administrator or reseller ("selling"), how its clients pay (the processor it
 * chose and its keys) and its invoice details, the prices of its packages, its clients' plans and
 * its invoices; for an account with a plan ("billing"), its invoices with "Pay". Every amount comes
 * from the panel's tables (pp), and a payment counts only when the processor confirms it to the
 * panel (pp_pay).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
require_once dirname(__FILE__) . '/pp.php';
require_once dirname(__FILE__) . '/payments.php';
require_once dirname(__FILE__) . '/daemon.php';

class module_controller extends ctrl_module
{
    /** @var array<int, array{0:string, 1:string}> Messages: [type ('ok'|'error'), text]. */
    static $msg = array();

    /** @var string The processor's page to send the client to (the panel's own rules let a form lead only to the panel: a link does). */
    static $go = '';

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
     * Translate, put words in for %s, escape.
     *
     * @param string $s
     * @param array $args
     * @return string
     */
    static function t($s, array $args = array())
    {
        $text = ui_language::translate($s);
        foreach ($args as $a) {
            $text = preg_replace('/%s/', str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $a), $text, 1);
        }
        return self::h($text);
    }

    /**
     * A message for the page.
     *
     * @param string $type ok or error
     * @param string $text English.
     * @param array $args
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
     * The logged-in account.
     *
     * @return array
     */
    static function Me()
    {
        return ctrl_users::GetUserDetail();
    }

    /**
     * A posted field.
     *
     * @param string $n
     * @return string
     */
    static function Post($n)
    {
        return isset($_POST[$n]) && is_string($_POST[$n]) ? $_POST[$n] : '';
    }

    /**
     * A plain line of text (no control or invisible formatting characters, no < >), or false.
     *
     * @param string $s
     * @param int $max
     * @param bool $lines Allow line breaks.
     * @return string|false
     */
    static function Text($s, $max, $lines = false)
    {
        $s = trim(str_replace("\r", '', (string) $s));
        $bad = $lines ? '/[\x00-\x09\x0B-\x1F\x7F<>\p{Cf}]/u' : '/[\x00-\x1F\x7F<>\p{Cf}]/u';
        return mb_check_encoding($s, 'UTF-8') && mb_strlen($s) <= $max && !preg_match($bad, $s) ? $s : false;
    }

    /**
     * The seller (the logged-in account, when it may sell).
     *
     * @return int 0 when not.
     */
    static function SellerMe()
    {
        $me = self::Me();
        return pp::CanSell($me) ? (int) $me['userid'] : 0;
    }

    // ----- selling -----

    /**
     * Save the seller's settings, and the keys of its processors.
     *
     * @return void
     */
    static function doSettings()
    {
        runtime_csfr::Protect();
        $seller = self::SellerMe();
        if (!$seller) {
            return;
        }
        $v = array();
        $v['name'] = self::Text(self::Post('inName'), 120);
        $v['address'] = self::Text(self::Post('inAddress'), 500, true);
        $v['email'] = trim(self::Post('inEmail'));
        $v['currency'] = strtoupper(trim(self::Post('inCurrency')));
        $v['taxlabel'] = self::Text(self::Post('inTaxLabel'), 30);
        $rate = str_replace(',', '.', trim(self::Post('inTaxRate')));
        $v['prefix'] = trim(self::Post('inPrefix'));
        $v['next'] = trim(self::Post('inNext'));
        $v['lead'] = trim(self::Post('inLead'));
        $v['grace'] = trim(self::Post('inGrace'));
        $v['suspend'] = self::Post('inSuspend') === '1';
        $v['mode'] = self::Post('inTest') === '1' ? 'sandbox' : 'live';
        $v['proc'] = self::Post('inProc');
        $v['auto'] = self::Post('inAuto') === '1';
        $v['period'] = self::Post('inUsual') === 'year' ? 'year' : 'month';
        if ($v['name'] === false || $v['name'] === '' || $v['address'] === false || $v['taxlabel'] === false) {
            self::Say('error', 'Give your business name (and address) as plain text: it shows on every invoice.');
            return;
        }
        if ($v['email'] !== '' && (strlen($v['email']) > 200 || !filter_var($v['email'], FILTER_VALIDATE_EMAIL))) {
            self::Say('error', 'The billing email address is not valid.');
            return;
        }
        if ($v['proc'] !== '' && pp_pay::Get($v['proc']) === '') {
            self::Say('error', 'Choose how your clients pay.');
            return;
        }
        $procClass = pp_pay::Get($v['proc']);
        if (!isset(pp::Currencies()[$v['currency']]) || ($procClass !== '' && !in_array($v['currency'], $procClass::Currencies(), true))) {
            self::Say('error', 'Choose one of the currencies %s takes.', array($procClass !== '' ? $procClass::Name() : 'the panel'));
            return;
        }
        if ($rate === '') {
            $rate = '0';
        }
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $rate) || (float) $rate > 50) {
            self::Say('error', 'The tax rate is a percentage from 0 to 50 (such as 20 or 7.5).');
            return;
        }
        $v['taxrate'] = (int) round((float) $rate * 100);
        if (!preg_match('#^[A-Za-z0-9/_-]{0,20}$#', $v['prefix']) || !ctype_digit($v['next']) || (int) $v['next'] < 1 || (int) $v['next'] > 999999999) {
            self::Say('error', 'The invoice prefix has up to 20 letters, digits, dashes or slashes, and the next number is a whole number.');
            return;
        }
        if (!ctype_digit($v['lead']) || (int) $v['lead'] > 60 || !ctype_digit($v['grace']) || (int) $v['grace'] > 60) {
            self::Say('error', 'Days are whole numbers from 0 to 60.');
            return;
        }
        // every processor's keys, test and live: checked before anything is saved
        $keys = array();
        foreach (pp_pay::Processors() as $proc => $c) {
            foreach (array('sandbox', 'live') as $mode) {
                $stored = pp_pay::Stored($seller, $proc, $mode);
                $vals = array();
                $newPublic = false;
                $emptySecret = false;
                foreach ($c::Fields() as $field => $def) {
                    $n = 'inK_' . $proc . '_' . $mode . '_' . $field;
                    $val = trim(self::Post($n));
                    if ($val !== '' && !preg_match($def['pattern'][$mode], $val)) {
                        self::Say('error', 'That is not a %s for %s (%s).', array($def['label'], $c::Name(), $mode === 'live' ? 'live' : 'test mode'));
                        return;
                    }
                    if (!empty($def['secret'])) {
                        $vals[$field] = $val === '' ? null : $val;
                        $emptySecret = $emptySecret || $val === '';
                    } else {
                        // (a field the form did not send keeps its value; one sent empty takes it away)
                        $vals[$field] = $val === '' && !isset($_POST[$n]) ? null : $val;
                        $newPublic = $newPublic || ($val !== '' && $val !== (isset($stored[$field]) ? $stored[$field] : ''));
                    }
                }
                // a new client ID needs its own secret
                if ($newPublic && $emptySecret) {
                    self::Say('error', 'Give the secret that belongs to the new %s keys (%s).', array($c::Name(), $mode === 'live' ? 'live' : 'test mode'));
                    return;
                }
                $keys[] = array($proc, $mode, $vals, self::Post('inKDrop_' . $proc . '_' . $mode) === '1');
            }
        }
        $old = pp::Seller($seller);
        foreach ($keys as $k) {
            if (!pp_pay::SaveKeys($seller, $k[0], $k[1], $k[2], $k[3])) {
                self::Say('error', 'The secret could not be stored: this server has no key for stored passwords (cnf/security.php).');
                return;
            }
        }
        pp::SaveSeller($seller, $v);
        $s = pp::Seller($seller);
        if (empty($old['new']) && $v['currency'] !== (string) $old['ps_currency_vc'] && array_filter(pp::Prices($seller), function ($p) { return $p[0] > 0 || $p[1] > 0; })) {
            self::Say('error', 'Your package prices are now in %s: check them in Your packages.', array($v['currency']));
        }
        // leaving test mode: what testing did to real clients is undone
        if ($v['mode'] === 'live' && empty($old['new']) && (string) $old['ps_mode_vc'] !== 'live') {
            list($back, $on) = pp::GoLive($seller);
            self::Say('ok', 'Live: %s plan(s) moved by test payments are back to the date really paid until, and %s client(s) switched off for a test invoice are on again. Automatic billing counts new clients from now.', array($back, $on));
        }
        $name = pp_pay::Name($v['proc']);
        self::Say('ok', $v['mode'] === 'live' ? 'Saved: live mode, real payments%s.' : 'Saved: test mode is on (no real money, TEST- invoices%s).',
            array($name !== '' ? ', ' . $name : ''));
        if ($v['proc'] === '') {
            return;
        }
        if (!pp_pay::Ready($s)) {
            self::Say('error', 'Give your %s keys for %s, or your clients can not pay online.', array($name, $v['mode'] === 'live' ? 'live' : 'test mode'));
            return;
        }
        // the keys now in use, checked with the processor when new or when the processor or mode changed
        $given = false;
        foreach ($keys as $k) {
            $given = $given || ($k[0] === $v['proc'] && $k[1] === $v['mode'] && array_filter($k[2], function ($x) { return $x !== null && $x !== ''; }));
        }
        if ($given || $v['mode'] !== (string) $old['ps_mode_vc'] || $v['proc'] !== (string) $old['ps_proc_vc']) {
            $why = pp_pay::Check($s);
            self::Say($why === '' ? 'ok' : 'error', $why === '' ? '%s accepts your keys (%s).' : $why, array($name, $v['mode'] === 'live' ? 'live' : 'test mode: test payments only'));
        }
    }

    /**
     * Test mode's tools: run the seller's billing now, make a test invoice overdue, delete the test
     * invoices.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doTestTools()
    {
        global $zdbh;
        runtime_csfr::Protect();
        $seller = self::SellerMe();
        $s = $seller ? pp::Seller($seller) : null;
        if (!$s || !empty($s['new'])) {
            return;
        }
        $what = self::Post('inWhat');
        if ($what === 'delete') {
            self::Say('ok', '%s test invoice(s) deleted; the TEST- numbers start again at 1, and plans moved by test payments are back to the date really paid until.', array(pp::DeleteTests($seller)));
            return;
        }
        if (!pp::Testing($s)) {
            self::Say('error', 'These tools are for test mode only.');
            return;
        }
        if ($what === 'run') {
            $log = pp_daemon::Run($seller);
            self::Say('ok', $log ? 'Billing ran: %s' : 'Billing ran: nothing was due.', array(implode('; ', str_replace('Payment Plans: ', '', $log))));
            return;
        }
        if ($what === 'overdue') {
            $inv = ctype_digit(self::Post('inId')) ? pp::Invoice((int) self::Post('inId')) : null;
            if ($inv && (int) $inv['in_seller_fk'] === $seller && (int) $inv['in_test_in'] && (string) $inv['in_status_vc'] === 'open') {
                // due long enough ago for the grace days to be over; reminded again from the start
                // (made a day before that too: nobody is switched off within a day of the invoice)
                $zdbh->prepare("UPDATE x_pp_invoices SET in_due_in = :d, in_created_in = :c, in_reminded_in = 0 WHERE in_id_pk = :i AND in_test_in = 1")
                    ->execute(array(':d' => time() - ((int) $s['ps_grace_in'] + 1) * 86400, ':c' => time() - ((int) $s['ps_grace_in'] + 2) * 86400, ':i' => (int) $inv['in_id_pk']));
                self::Say('ok', '%s is now overdue past the grace days: "Run billing now" reminds the client and switches them off.', array($inv['in_number_vc']));
            }
        }
    }

    /**
     * Test the keys of the processor and mode in use.
     *
     * @return void
     */
    static function doTestKeys()
    {
        runtime_csfr::Protect();
        $seller = self::SellerMe();
        if (!$seller) {
            return;
        }
        $s = pp::Seller($seller);
        $why = pp_pay::Check($s);
        self::Say($why === '' ? 'ok' : 'error', $why === '' ? '%s accepts your keys (%s).' : $why, array(pp_pay::Name((string) $s['ps_proc_vc']), pp::Testing($s) ? 'test mode: test payments only' : 'live'));
    }

    /**
     * Save the prices of the seller's packages.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doPrices()
    {
        global $zdbh;
        runtime_csfr::Protect();
        $seller = self::SellerMe();
        if (!$seller) {
            return;
        }
        $s = pp::Seller($seller);
        $cur = (string) $s['ps_currency_vc'];
        $rows = array();
        // (only the packages the form sent: a package's own page sends its own)
        foreach (pp::Packages($seller) as $id => $name) {
            if (!isset($_POST['inMonth' . $id]) && !isset($_POST['inYear' . $id])) {
                continue;
            }
            $m = pp::Parse(self::Post('inMonth' . $id), $cur);
            $y = pp::Parse(self::Post('inYear' . $id), $cur);
            if ($m === false || $y === false) {
                self::Say('error', 'The price of %s is not an amount (such as 9.95).', array($name));
                return;
            }
            $rows[$id] = array($m, $y);
        }
        foreach ($rows as $id => $p) {
            $zdbh->prepare("REPLACE INTO x_pp_prices (pr_seller_fk, pr_package_fk, pr_month_in, pr_year_in) VALUES (:s, :p, :m, :y)")
                ->execute(array(':s' => $seller, ':p' => $id, ':m' => $p[0], ':y' => $p[1]));
        }
        if (!$rows) {
            return;
        }
        self::Say('ok', count($rows) === 1 ? 'The price of %s is saved; it applies from each client\'s next invoice.' : 'The prices are saved; they apply from each client\'s next invoice.',
            array(pp::Packages($seller)[key($rows)]));
    }

    /**
     * Start, change or stop a client's plan.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doPlan()
    {
        global $zdbh;
        runtime_csfr::Protect();
        $seller = self::SellerMe();
        $uid = ctype_digit(self::Post('inClient')) ? (int) self::Post('inClient') : 0;
        $clients = $seller ? pp::Clients($seller) : array();
        if (!isset($clients[$uid])) {
            return;
        }
        if ($clients[$uid]['admin']) {
            self::Say('error', 'Administrators are not billed.');
            return;
        }
        $plan = pp::Plan($uid);
        if (self::Post('inStop') === '1') {
            if ($plan && (int) $plan['pl_seller_fk'] === $seller) {
                $zdbh->prepare("UPDATE x_pp_plans SET pl_active_in = 0 WHERE pl_acc_fk = :u")->execute(array(':u' => $uid));
                self::Say('ok', 'The plan of %s is stopped: no more invoices are made (open ones stay until you mark them paid or void them).', array($clients[$uid]['username']));
            }
            return;
        }
        $period = self::Post('inPeriod') === 'year' ? 'year' : 'month';
        $date = DateTime::createFromFormat('!Y-m-d', self::Post('inPaidUntil'));
        if (!$date || $date->format('Y-m-d') !== self::Post('inPaidUntil') || $date->getTimestamp() > time() + 5 * 366 * 86400 || $date->getTimestamp() < time() - 366 * 86400) {
            self::Say('error', 'Give the date the client has paid until (today, if they have paid nothing yet).');
            return;
        }
        $prices = pp::Prices($seller);
        $p = isset($prices[$clients[$uid]['package']]) ? $prices[$clients[$uid]['package']] : array(0, 0);
        if ($p[$period === 'year' ? 1 : 0] <= 0) {
            self::Say('error', 'The package %s has no %s price yet (Your packages).', array($clients[$uid]['packagename'], $period === 'year' ? 'yearly' : 'monthly'));
            return;
        }
        pp::StartPlan($seller, $uid, $period, $date->getTimestamp());
        self::Say('ok', '%s pays %s from %s: the panel makes the invoice %s days before, and reminds them.', array($clients[$uid]['username'], $period === 'year' ? 'yearly' : 'monthly',
            ui_language::Date('j M Y', $date->getTimestamp()), (int) pp::Seller($seller)['ps_lead_in']));
    }

    /**
     * Turn Payment Plans on or off for the seller's clients.
     *
     * @return void
     */
    static function doOnOff()
    {
        runtime_csfr::Protect();
        $seller = self::SellerMe();
        $s = $seller ? pp::Seller($seller) : null;
        if (!$s) {
            return;
        }
        $on = self::Post('inOn') === '1';
        if ($on && !empty($s['new'])) {
            self::Say('error', 'Save Your details and payments first (they go on every invoice), then turn Payment Plans on.');
            return;
        }
        if (!empty($s['new'])) {
            return;
        }
        $n = pp::SetOn($seller, $on);
        self::Say('ok', $on ? 'Payment Plans is on: your clients with a plan are invoiced, reminded and, when unpaid past the grace days, switched off.'
            : 'Payment Plans is off: no invoices, reminders or switching off. %s client(s) switched off for an unpaid invoice are on again.', array($n));
    }

    /**
     * Start billing every client on a priced package that is not billed yet (made before automatic
     * plans, or moved to this seller), each for the seller's usual period.
     *
     * @return void
     */
    static function doStartAll()
    {
        runtime_csfr::Protect();
        $seller = self::SellerMe();
        $s = $seller ? pp::Seller($seller) : null;
        if (!$s || !empty($s['new'])) {
            return;
        }
        $date = DateTime::createFromFormat('!Y-m-d', self::Post('inPaidUntil'));
        if (!$date || $date->format('Y-m-d') !== self::Post('inPaidUntil') || $date->getTimestamp() > time() + 5 * 366 * 86400 || $date->getTimestamp() < time() - 366 * 86400) {
            self::Say('error', 'Give the date the clients have paid until (today, if they have paid nothing yet).');
            return;
        }
        $prices = pp::Prices($seller);
        $n = 0;
        foreach (pp::Unplanned($seller) as $uid => $c) {
            $period = pp::PickPeriod($prices, $c[0], (string) $s['ps_period_vc']);
            if ($period !== '') {
                pp::StartPlan($seller, $uid, $period, $date->getTimestamp());
                $n++;
            }
        }
        self::Say('ok', '%s client(s) are billed from %s: each one\'s invoice comes %s days before.', array($n, ui_language::Date('j M Y', $date->getTimestamp()), (int) $s['ps_lead_in']));
    }

    /**
     * Make a client's next invoice now.
     *
     * @return void
     */
    static function doInvoiceNow()
    {
        runtime_csfr::Protect();
        $seller = self::SellerMe();
        $uid = ctype_digit(self::Post('inClient')) ? (int) self::Post('inClient') : 0;
        $clients = $seller ? pp::Clients($seller) : array();
        $plan = isset($clients[$uid]) ? pp::Plan($uid) : null;
        if (!$plan || (int) $plan['pl_seller_fk'] !== $seller) {
            return;
        }
        if (!(int) pp::Seller($seller)['ps_on_in']) {
            self::Say('error', 'Payment Plans is off: turn it on first (top of the page).');
            return;
        }
        $r = pp::MakeInvoice($uid);
        if (is_array($r)) {
            self::Say('ok', 'Invoice %s for %s is made and sent to %s.', array($r['in_number_vc'], pp::Show((int) $r['in_total_in'], (string) $r['in_currency_vc']), $clients[$uid]['username']));
        } else {
            self::Say('error', $r);
        }
    }

    /**
     * Mark one of the seller's invoices paid (paid another way), or void it.
     *
     * @return void
     */
    static function doInvoice()
    {
        global $zdbh;
        runtime_csfr::Protect();
        $seller = self::SellerMe();
        $inv = ctype_digit(self::Post('inId')) ? pp::Invoice((int) self::Post('inId')) : null;
        if (!$seller || !$inv || (int) $inv['in_seller_fk'] !== $seller || !in_array((string) $inv['in_status_vc'], array('open', 'pending'), true)) {
            return;
        }
        if (self::Post('inVoid') === '1') {
            // a payment of it still open is closed first (it must not take money for a void invoice);
            // one that went through after all keeps the invoice
            if ((string) $inv['in_order_vc'] !== '') {
                $r = pp_pay::Settle($inv);
                if ($r === 'paid' || $r === 'pending') {
                    self::Say('error', $r === 'paid' ? 'Invoice %s was just paid: it is not voided.' : 'A payment of invoice %s is being checked: void it once it is settled.', array($inv['in_number_vc']));
                    return;
                }
                $inv = pp::Invoice((int) $inv['in_id_pk']);
                if (!pp_pay::Abandon($inv)) {
                    self::Say('error', 'The payment page of invoice %s could not be closed: try again in a minute.', array($inv['in_number_vc']));
                    return;
                }
                // (paid at the very moment it was closed?)
                $r = pp_pay::Settle($inv);
                if ($r === 'paid' || $r === 'pending') {
                    self::Say('error', $r === 'paid' ? 'Invoice %s was just paid: it is not voided.' : 'A payment of invoice %s is being checked: void it once it is settled.', array($inv['in_number_vc']));
                    return;
                }
            }
            $zdbh->prepare("UPDATE x_pp_invoices SET in_status_vc = 'void' WHERE in_id_pk = :i AND in_status_vc IN ('open', 'pending')")->execute(array(':i' => (int) $inv['in_id_pk']));
            try {
                ctrl_notices::Withdraw('pp-invoice-' . (int) $inv['in_id_pk'], 0);
            } catch (Exception $e) {
            }
            // switched off for this invoice: on again
            $plan = pp::Plan((int) $inv['in_acc_fk']);
            if ($plan && (int) $plan['pl_suspended_in'] && (string) ws_suspend::Reason((int) $inv['in_acc_fk']) === pp::REASON . $inv['in_number_vc']) {
                pp::SwitchOn((int) $inv['in_acc_fk']);
            }
            pp::Log($inv, 'void', '', '', null, $seller);
            self::Say('ok', 'Invoice %s is void.', array($inv['in_number_vc']));
            return;
        }
        $method = self::Text(self::Post('inMethod'), 60);
        if ($method === false || $method === '') {
            self::Say('error', 'Say how it was paid (such as bank transfer).');
            return;
        }
        if (pp::MarkPaid((int) $inv['in_id_pk'], $method, '', '', $seller)) {
            self::Say('ok', 'Invoice %s is marked paid (%s).', array($inv['in_number_vc'], $method));
        }
    }

    // ----- billing (the client) -----

    /**
     * Pay an invoice online: the panel makes the payment (the invoice's amount) with the seller's
     * processor and sends the client to its page.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function doPay()
    {
        global $zdbh;
        runtime_csfr::Protect();
        $me = self::Me();
        $inv = ctype_digit(self::Post('inId')) ? pp::Invoice((int) self::Post('inId')) : null;
        if (!$inv || (int) $inv['in_acc_fk'] !== (int) $me['userid'] || (string) $inv['in_status_vc'] !== 'open') {
            self::Say('error', 'That invoice is not open (it may be paid already).');
            return;
        }
        $s = pp::Seller((int) $inv['in_seller_fk']);
        $mode = pp::InvoiceMode($inv);
        if (!pp::CanPay($s, $inv)) {
            self::Say('error', $mode === 'live' ? 'Your provider does not take online payments here yet: pay as they tell you.' : 'This test invoice can only be paid while your provider is in test mode.');
            return;
        }
        // (Create looks at the invoice's last payment first, and closes it before making a new one)
        $back = self::Here() . '/?module=payment_plans&pp=%s&i=' . (int) $inv['in_id_pk'];
        $go = pp_pay::Create($inv, $s, sprintf($back, 'return'), sprintf($back, 'cancel'));
        if (!preg_match('#^https?://#', $go)) {
            self::Say(pp_pay::$found !== '' ? 'ok' : 'error', $go);
            return;
        }
        self::$go = $go;
    }

    /**
     * The panel's address as the client uses it now (for the processor's way back).
     *
     * @return string
     */
    static function Here()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        if (!preg_match('/^[A-Za-z0-9.-]{1,253}(:\d{1,5})?$/', $host)) {
            return pp::PanelUrl((int) self::Me()['userid']);
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        return ($https ? 'https://' : 'http://') . $host;
    }

    /**
     * Back from the processor: settle the client's own invoice, from the payment it names.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Returned()
    {
        global $zdbh;
        static $done = false;
        // (?paypal=return&token=: the way back of a payment made before version 102)
        $how = isset($_GET['pp']) && is_string($_GET['pp']) ? $_GET['pp'] : (isset($_GET['paypal']) && is_string($_GET['paypal']) ? $_GET['paypal'] : '');
        if ($done || $how === '') {
            return;
        }
        $done = true;
        if ($how === 'cancel') {
            self::Say('error', 'The payment was cancelled: nothing was paid.');
            return;
        }
        if ($how !== 'return') {
            return;
        }
        pp::Ensure();
        $me = (int) self::Me()['userid'];
        if (isset($_GET['i']) && is_string($_GET['i']) && ctype_digit($_GET['i'])) {
            $inv = pp::Invoice((int) $_GET['i']);
        } else {
            $order = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
            $q = $zdbh->prepare("SELECT * FROM x_pp_invoices WHERE in_order_vc = :o AND in_acc_fk = :u");
            $q->execute(array(':o' => preg_match(pp_pay::REF, $order) ? $order : '-', ':u' => $me));
            $inv = $q->fetch(PDO::FETCH_ASSOC);
        }
        if (!$inv || (int) $inv['in_acc_fk'] !== $me) {
            self::Say('error', 'That payment is not for one of your invoices.');
            return;
        }
        if ((string) $inv['in_status_vc'] === 'paid') {
            self::Say('ok', 'Invoice %s is paid. Thank you.', array($inv['in_number_vc']));
            return;
        }
        $ref = pp_pay::ReturnRef($inv, $_GET);
        if ($ref === '' || $ref !== (string) $inv['in_order_vc']) {
            self::Say('error', 'That payment is not for one of your invoices.');
            return;
        }
        $r = pp_pay::Settle($inv);
        if ($r === 'paid') {
            self::Say('ok', 'Thank you: invoice %s is paid.', array($inv['in_number_vc']));
        } elseif ($r === 'pending') {
            self::Say('ok', 'Your payment for invoice %s is being checked: it shows paid when it completes.', array($inv['in_number_vc']));
        } elseif ($r === 'open') {
            self::Say('error', 'The payment for invoice %s was not taken: try again.', array($inv['in_number_vc']));
        } else {
            self::Say('error', $r);
        }
    }

    // ----- the page -----

    /**
     * <@ Page @>.
     *
     * @return string HTML.
     */
    static function getPage()
    {
        static $page = null;
        if ($page !== null) {
            return $page;
        }
        self::Returned();
        return $page = self::PageHtml();
    }

    /**
     * The page.
     *
     * @return string
     */
    static function PageHtml()
    {
        $me = self::Me();
        $out = '';
        foreach (self::$msg as $m) {
            $out .= '<div class="pp-msg ' . ($m[0] === 'ok' ? 'is-ok' : 'is-bad') . '" role="' . ($m[0] === 'ok' ? 'status' : 'alert') . '">' . self::h($m[1]) . '</div>';
        }
        if (self::$go !== '') {
            $u = self::h(self::$go);
            $inv = ctype_digit(self::Post('inId')) ? pp::Invoice((int) self::Post('inId')) : null;
            $name = $inv ? pp_pay::Name((string) $inv['in_proc_vc']) : '';
            $name = $name !== '' ? $name : ui_language::translate('the payment page');
            return $out . '<div class="pp-card pp-going"><h3>' . self::t('Taking you to %s…', array($name)) . '</h3><p><a class="btn btn-primary pp-' . self::h($inv ? $inv['in_proc_vc'] : '') . '" id="pp-go" href="' . $u . '" rel="noreferrer">'
                . self::t('Continue to %s', array($name)) . '</a></p><p class="pp-muted">' . self::t('You pay on %s\'s own pages, then come back here.', array($name)) . '</p></div>'
                . '<script>setTimeout(function () { var a = document.getElementById("pp-go"); if (a) { window.location.href = a.href; } }, 300);</script>';
        }
        if (isset($_GET['invoice']) && ctype_digit((string) $_GET['invoice'])) {
            return $out . self::InvoiceView((int) $_GET['invoice']);
        }
        $billed = pp::Plan((int) $me['userid']) !== null || count(pp::Invoices('acc', (int) $me['userid'], 1)) > 0;
        $sell = pp::CanSell($me);
        $tab = isset($_GET['tab']) && $_GET['tab'] === 'billing' ? 'billing' : ($sell ? 'selling' : 'billing');
        if ($sell && $billed) {
            $out .= '<div class="pp-tabs">' . '<a class="' . ($tab === 'selling' ? 'on' : '') . '" href="./?module=payment_plans">' . self::t('Billing my clients') . '</a>'
                . '<a class="' . ($tab === 'billing' ? 'on' : '') . '" href="./?module=payment_plans&amp;tab=billing">' . self::t('My own invoices') . '</a></div>';
        }
        if ($tab === 'selling' && $sell) {
            if (isset($_GET['package']) && is_string($_GET['package']) && ctype_digit($_GET['package'])) {
                return $out . self::PackageView((int) $me['userid'], (int) $_GET['package']);
            }
            return $out . self::Selling((int) $me['userid']);
        }
        return $out . self::Billing((int) $me['userid']);
    }

    /**
     * A small form with one button.
     *
     * @param string $action
     * @param string $label
     * @param array $fields
     * @param string $class
     * @param string $ask
     * @return string
     */
    static function Button($action, $label, array $fields, $class = 'btn-default', $ask = '')
    {
        $f = '<form class="pp-inline" method="post" action="./?module=payment_plans&amp;action=' . $action . '"' . ($ask !== '' ? ' onsubmit="return confirm(this.dataset.ask)" data-ask="' . self::t($ask) . '"' : '') . '>' . runtime_csfr::Token();
        foreach ($fields as $k => $v) {
            $f .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        }
        return $f . '<button class="btn btn-sm ' . $class . '" type="submit">' . self::t($label) . '</button></form>';
    }

    /**
     * An invoice's state, as a pill.
     *
     * @param array $inv
     * @return string
     */
    static function State(array $inv)
    {
        return ((int) ($inv['in_test_in'] ?? 0) ? '<span class="pp-pill is-test">TEST</span> ' : '') . self::StateOnly($inv);
    }

    /**
     * An invoice's state alone.
     *
     * @param array $inv
     * @return string
     */
    static function StateOnly(array $inv)
    {
        $st = (string) $inv['in_status_vc'];
        // overdue once its due day is over
        if ($st === 'open' && date('Ymd') > date('Ymd', (int) $inv['in_due_in'])) {
            return '<span class="pp-pill is-bad">' . self::t('Overdue') . '</span>';
        }
        $map = array('open' => array('Open', 'is-warn'), 'pending' => array('Being checked', 'is-warn'), 'paid' => array('Paid', 'is-ok'), 'void' => array('Void', ''));
        $p = isset($map[$st]) ? $map[$st] : array($st, '');
        $more = '';
        // what happened to a paid invoice's payment since (pp_pay::Reversals)
        if ($st === 'paid' && (string) ($inv['in_refund_vc'] ?? '') !== '') {
            $more .= ' <span class="pp-pill is-warn">' . self::t((string) $inv['in_refund_vc'] === 'full' ? 'Refunded' : 'Partly refunded') . '</span>';
        }
        $d = (string) ($inv['in_dispute_vc'] ?? '');
        if ($st === 'paid' && $d !== '') {
            $more .= ' <span class="pp-pill ' . ($d === 'won' ? 'is-ok' : 'is-bad') . '">' . self::t(array('open' => 'Chargeback open', 'won' => 'Chargeback won', 'lost' => 'Charged back')[$d]) . '</span>';
        }
        return '<span class="pp-pill ' . $p[1] . '">' . self::t($p[0]) . '</span>' . $more;
    }

    /**
     * The seller's view.
     *
     * @param int $seller
     * @return string
     */
    static function Selling($seller)
    {
        $s = pp::Seller($seller);
        $cur = (string) $s['ps_currency_vc'];
        $ready = pp_pay::Ready($s);
        $name = pp_pay::Name((string) $s['ps_proc_vc']);
        $testing = pp::Testing($s);
        $out = '';
        if ($testing && empty($s['new'])) {
            $out .= '<div class="pp-testbanner"><b>' . self::t('Test mode') . '</b> ' . self::t('Payments go to your payment service\'s test mode (no real money) and new invoices are numbered TEST-. Untick Test mode in your settings to take real payments.') . '</div>';
        }
        if (!empty($s['new'])) {
            $out .= '<div class="pp-card pp-intro"><h3>' . self::t('Bill your clients through the panel') . '</h3><p>' . self::t('Put a price on your packages, give each client a plan (monthly or yearly), and the panel invoices them before each period, reminds them, and switches them off when an invoice stays unpaid past your grace days. They pay online (PayPal or Stripe, your choice) straight into your own account; paying switches them on again. New clients on a priced package are billed by themselves. Start by giving your packages their price (click one below), then your details and how your clients pay.') . '</p></div>';
        }
        // on or off, at the top
        $on = (int) $s['ps_on_in'] === 1;
        $out .= '<div class="pp-switch ' . ($on ? 'is-on' : 'is-off') . '"><div><b>' . self::t($on ? 'Payment Plans is on' : 'Payment Plans is off') . '</b><span>'
            . self::t($on ? 'Your clients with a plan are invoiced, reminded and, when unpaid past your grace days, switched off.'
                : (!empty($s['new']) ? 'Nothing is billed yet. Price your packages and save your details, then turn it on.' : 'No invoices, reminders or switching off: the payment check is skipped. Open invoices can still be paid.'))
            . '</span></div>' . self::Button('OnOff', $on ? 'Turn off' : 'Turn on', array('inOn' => $on ? '0' : '1'), $on ? 'btn-default' : 'btn-primary',
                $on ? 'Turn Payment Plans off? No invoices, reminders or switching off until you turn it on again; clients switched off for an unpaid invoice are switched on.' : '') . '</div>';
        if (empty($s['new'])) {
            $out .= self::Figures($seller);
        }
        // settings
        $cOpts = '';
        foreach (pp::Currencies() as $c => $d) {
            $cOpts .= '<option' . ($c === $cur ? ' selected' : '') . '>' . $c . '</option>';
        }
        $f = function ($label, $input, $help = '') {
            return '<label class="pp-field"><span>' . self::t($label) . '</span>' . $input . ($help !== '' ? '<small>' . self::t($help) . '</small>' : '') . '</label>';
        };
        // the payment services, each with its keys for test mode and live
        $pOpts = '<option value="">' . self::t('None') . '</option>';
        $procs = '';
        foreach (pp_pay::Processors() as $proc => $c) {
            $pOpts .= '<option value="' . self::h($proc) . '"' . ((string) $s['ps_proc_vc'] === $proc ? ' selected' : '') . '>' . self::h($c::Name()) . '</option>';
            $set = array();
            $rows = '';
            foreach (array('sandbox' => 'Test mode', 'live' => 'Live') as $mode => $modeName) {
                $stored = pp_pay::Stored($seller, $proc, $mode);
                $has = pp_pay::Keys($seller, $proc, $mode) !== null;
                $set[] = '<span class="pp-pill' . ($has ? ' is-ok' : '') . '">' . self::t($modeName) . ': ' . self::t($has ? 'set' : 'not set') . '</span>';
                $rows .= '<div class="pp-keymode"><b>' . self::t($modeName) . '</b><div class="pp-row">';
                foreach ($c::Fields() as $field => $def) {
                    $n = 'inK_' . $proc . '_' . $mode . '_' . $field;
                    $help = isset($def['help'][$mode]) ? $def['help'][$mode] : '';
                    $rows .= !empty($def['secret'])
                        ? $f($def['label'], '<input class="form-control" type="password" name="' . $n . '" maxlength="260" placeholder="' . (isset($stored[$field]) ? self::t('kept') : '') . '" autocomplete="new-password">', $help)
                        : $f($def['label'], '<input class="form-control" name="' . $n . '" maxlength="260" value="' . self::h(isset($stored[$field]) ? $stored[$field] : '') . '">', $help);
                }
                $rows .= '</div>' . ($stored ? '<label class="pp-check"><input type="checkbox" name="inKDrop_' . self::h($proc . '_' . $mode) . '" value="1"> ' . self::t('Remove these keys') . '</label>' : '') . '</div>';
            }
            $procs .= '<details class="pp-proc"' . ((string) $s['ps_proc_vc'] === $proc ? ' open' : '') . '><summary><b>' . self::t('%s keys', array($c::Name())) . '</b> ' . implode(' ', $set) . '</summary>'
                . '<p class="pp-muted pp-keyhelp">' . self::t($c::Help()) . ' ' . self::t('Secrets are kept encrypted; leave a secret empty to keep it.') . '</p>' . $rows . '</details>';
        }
        $settings = '<div class="pp-card" id="pp-settings"><h3>' . self::t('Your details and payments') . '</h3><form method="post" action="./?module=payment_plans&amp;action=Settings" autocomplete="off">' . runtime_csfr::Token()
            . '<div class="pp-grid"><fieldset><legend>' . self::t('On your invoices') . '</legend>'
            . $f('Business name', '<input class="form-control" name="inName" maxlength="120" required value="' . self::h($s['ps_name_vc']) . '">')
            . $f('Address', '<textarea class="form-control" name="inAddress" rows="3" maxlength="500">' . self::h($s['ps_address_tx']) . '</textarea>', 'Also a tax or VAT number, if you have one.')
            . $f('Billing email', '<input class="form-control" type="email" name="inEmail" maxlength="200" value="' . self::h($s['ps_email_vc']) . '">', 'Clients\' replies to invoice emails go here.')
            . '<div class="pp-row">' . $f('Currency', '<select class="form-control" name="inCurrency">' . $cOpts . '</select>')
            . $f('Tax name', '<input class="form-control" name="inTaxLabel" maxlength="30" placeholder="VAT" value="' . self::h($s['ps_taxlabel_vc']) . '">')
            . $f('Tax rate %', '<input class="form-control" name="inTaxRate" maxlength="5" value="' . self::h(rtrim(rtrim(sprintf('%.2f', (int) $s['ps_taxrate_in'] / 100), '0'), '.')) . '">') . '</div>'
            . '<div class="pp-row">' . $f('Invoice prefix', '<input class="form-control" name="inPrefix" maxlength="20" value="' . self::h($s['ps_prefix_vc']) . '">')
            . $f('Next number', '<input class="form-control" name="inNext" maxlength="9" value="' . (int) $s['ps_next_in'] . '">') . '</div>'
            . '</fieldset><fieldset><legend>' . self::t('Billing') . '</legend>'
            . '<label class="pp-check"><input type="checkbox" name="inAuto" value="1"' . ((int) $s['ps_auto_in'] ? ' checked' : '') . '> <span>' . self::t('Bill new clients automatically: a client you make on a priced package gets its plan by itself, and its first invoice at once (once you are live: never in test mode)') . '</span></label>'
            . '<div class="pp-row">' . $f('Usual period', '<select class="form-control" name="inUsual"><option value="month">' . self::t('Monthly') . '</option><option value="year"' . ((string) $s['ps_period_vc'] === 'year' ? ' selected' : '') . '>' . self::t('Yearly') . '</option></select>', 'When the package has a price for it.')
            . $f('Invoice days ahead', '<input class="form-control" name="inLead" maxlength="2" value="' . (int) $s['ps_lead_in'] . '">')
            . $f('Grace days', '<input class="form-control" name="inGrace" maxlength="2" value="' . (int) $s['ps_grace_in'] . '">') . '</div>'
            . '<label class="pp-check"><input type="checkbox" name="inSuspend" value="1"' . ((int) $s['ps_suspend_in'] ? ' checked' : '') . '> ' . self::t('Switch a client off when an invoice is unpaid past the grace days (paying switches it on again)') . '</label>'
            . '<legend class="pp-legend2">' . self::t('How your clients pay') . '</legend>'
            . $f('Payment service', '<select class="form-control" name="inProc">' . $pOpts . '</select>', 'The money goes straight into your own account with it. None: you mark invoices paid yourself (bank transfer, cash).')
            . '<label class="pp-check pp-testbox"><input type="checkbox" name="inTest" value="1"' . ($testing ? ' checked' : '') . '> <span><b>' . self::t('Test mode') . '</b> '
            . self::t('The service\'s test mode (no real money) with your test keys, invoices numbered TEST- on their own, and test tools below. Untick it to take real payments.') . '</span></label>'
            . $procs
            . '</fieldset></div><div class="pp-foot"><span class="pp-muted">' . ($ready ? self::t('%s is set up (%s).', array($name, $testing ? 'test mode' : 'live')) : self::t('Without a payment service set up (for the mode in use), clients see their invoices and you mark them paid yourself.')) . '</span>'
            . '<button class="btn btn-primary" type="submit">' . self::t('Save') . '</button></div></form>'
            . ($ready ? '<div class="pp-foot2">' . self::Button('TestKeys', 'Test %s keys', array()) . '</div>' : '') . '</div>';
        $settings = str_replace(self::t('Test %s keys'), self::t('Test %s keys', array($name)), $settings);
        // the packages first: the seller's own, each with its price (a click opens its page)
        $packages = self::PackagesCard($seller, $s);
        if (!empty($s['new'])) {
            return $out . $packages . $settings;
        }
        $out .= $packages;
        $prices = pp::Prices($seller);
        // clients
        $rows = '';
        foreach (pp::Clients($seller) as $c) {
            if ($c['admin']) {
                continue;
            }
            $plan = pp::Plan($c['id']);
            $mine = $plan && (int) $plan['pl_seller_fk'] === $seller;
            $state = '<span class="pp-muted">' . self::t('No plan') . '</span>';
            $actions = '';
            if ($mine && (int) $plan['pl_active_in']) {
                $paid = (int) $plan['pl_paiduntil_in'];
                $state = ((int) $plan['pl_suspended_in'] ? '<span class="pp-pill is-bad">' . self::t('Switched off: unpaid') . '</span> ' : '')
                    . self::t($plan['pl_period_vc'] === 'year' ? 'Yearly' : 'Monthly') . ', ' . self::t('paid until %s', array(ui_language::Date('j M Y', $paid)));
                $actions .= self::Button('InvoiceNow', 'Invoice now', array('inClient' => $c['id'])) . self::Button('Plan', 'Stop', array('inClient' => $c['id'], 'inStop' => '1'), 'btn-default', 'Stop billing this client? Open invoices stay.');
            } elseif ($mine) {
                $state = '<span class="pp-muted">' . self::t('Stopped (was paid until %s)', array(ui_language::Date('j M Y', (int) $plan['pl_paiduntil_in']))) . '</span>';
            }
            $p = isset($prices[$c['package']]) ? $prices[$c['package']] : array(0, 0);
            $periods = ($p[0] > 0 ? '<option value="month">' . self::t('Monthly') . ' (' . self::h(pp::Show($p[0], $cur)) . ')</option>' : '')
                . ($p[1] > 0 ? '<option value="year"' . ($mine && $plan['pl_period_vc'] === 'year' ? ' selected' : '') . '>' . self::t('Yearly') . ' (' . self::h(pp::Show($p[1], $cur)) . ')</option>' : '');
            $start = $periods === '' ? '<span class="pp-muted">' . self::t('Give %s a price first', array($c['packagename'])) . '</span>'
                : '<form class="pp-inline pp-planform" method="post" action="./?module=payment_plans&amp;action=Plan">' . runtime_csfr::Token() . '<input type="hidden" name="inClient" value="' . $c['id'] . '">'
                . '<select class="form-control input-sm" name="inPeriod">' . $periods . '</select>'
                . '<input class="form-control input-sm" type="date" name="inPaidUntil" title="' . self::t('Paid until') . '" value="' . date('Y-m-d', $mine ? (int) $plan['pl_paiduntil_in'] : time()) . '">'
                . '<button class="btn btn-sm btn-primary" type="submit">' . self::t($mine && (int) $plan['pl_active_in'] ? 'Change' : 'Start plan') . '</button></form>';
            $rows .= '<tr><td><b>' . self::h($c['username']) . '</b>' . (!$c['enabled'] ? ' <span class="pp-pill">' . self::t('off') . '</span>' : '') . '<div class="pp-muted">' . self::h($c['packagename']) . '</div></td>'
                . '<td>' . $state . '</td><td>' . $start . '</td><td class="pp-actions">' . $actions . '</td></tr>';
        }
        $waiting = count(pp::Unplanned($seller));
        $bulk = $waiting > 0 ? '<form class="pp-bulk" method="post" action="./?module=payment_plans&amp;action=StartAll">' . runtime_csfr::Token()
            . '<span>' . self::t('%s client(s) on a priced package are not billed yet (made before automatic billing, or moved to you). Bill them all, %s, from the date they have paid until:', array($waiting, ui_language::translate((string) $s['ps_period_vc'] === 'year' ? 'yearly where the package has a yearly price' : 'monthly where the package has a monthly price')))
            . '</span> <input class="form-control input-sm" type="date" name="inPaidUntil" value="' . date('Y-m-d') . '"> <button class="btn btn-sm btn-primary" type="submit" onclick="return confirm(this.dataset.ask)" data-ask="' . self::t('Start billing these clients? Each one\'s first invoice comes before the date you gave.') . '">' . self::t('Start billing them') . '</button></form>' : '';
        $out .= '<div class="pp-card"><h3>' . self::t('Clients') . '</h3>' . $bulk
            . ($rows === '' ? '<p class="pp-muted">' . self::t('You have no clients yet.') . '</p>'
                : '<p class="pp-muted">' . self::t('Start or change a plan with the date the client has paid until: today for one who has paid nothing yet (their first invoice comes now), or the end of what they paid you before.') . ((int) $s['ps_auto_in'] ? ' ' . self::t('New clients on a priced package are billed by themselves.') : '') . '</p>'
                . '<table class="pp-table"><thead><tr><th>' . self::t('Client') . '</th><th>' . self::t('Plan') . '</th><th>' . self::t('Start or change') . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>') . '</div>';
        // invoices
        $rows = '';
        $client = pp::Clients($seller);
        foreach (pp::Invoices('seller', $seller, 100) as $inv) {
            $open = in_array((string) $inv['in_status_vc'], array('open', 'pending'), true);
            $act = '<a class="btn btn-sm btn-default" href="./?module=payment_plans&amp;invoice=' . (int) $inv['in_id_pk'] . '">' . self::t('View') . '</a>';
            if ($open) {
                $link = pp::PayLink($inv);
                $act .= ($link !== '' ? '<a class="btn btn-sm btn-default" href="' . self::h($link) . '" target="_blank" rel="noreferrer" title="' . self::t('Its own page, paid without logging in: also in the client\'s invoice emails. Copy it to send it yourself.') . '">' . self::t('Pay link') . '</a>' : '')
                    . '<form class="pp-inline" method="post" action="./?module=payment_plans&amp;action=Invoice">' . runtime_csfr::Token() . '<input type="hidden" name="inId" value="' . (int) $inv['in_id_pk'] . '">'
                    . '<input class="form-control input-sm pp-method" name="inMethod" maxlength="60" placeholder="' . self::t('paid by (bank transfer...)') . '"><button class="btn btn-sm btn-default" type="submit">' . self::t('Mark paid') . '</button></form>'
                    . self::Button('Invoice', 'Void', array('inId' => (int) $inv['in_id_pk'], 'inVoid' => '1'), 'btn-default', 'Void this invoice? It stays in the list as void, and its period is not paid.');
                if ((int) $inv['in_test_in'] && $testing && (string) $inv['in_status_vc'] === 'open') {
                    $act .= self::Button('TestTools', 'Make overdue', array('inWhat' => 'overdue', 'inId' => (int) $inv['in_id_pk']), 'btn-warning');
                }
            }
            $rows .= '<tr><td>' . self::h($inv['in_number_vc']) . '</td><td>' . self::h(isset($client[(int) $inv['in_acc_fk']]) ? $client[(int) $inv['in_acc_fk']]['username'] : '#' . $inv['in_acc_fk']) . '</td>'
                . '<td>' . self::h(pp::Show((int) $inv['in_total_in'], (string) $inv['in_currency_vc'])) . '</td><td>' . self::h(ui_language::Date('j M Y', (int) $inv['in_due_in'])) . '</td>'
                . '<td>' . self::State($inv) . ((string) $inv['in_status_vc'] === 'paid' ? ' <span class="pp-muted">' . self::h($inv['in_method_vc']) . '</span>' : '') . '</td><td class="pp-actions">' . $act . '</td></tr>';
        }
        $out .= '<div class="pp-card"><h3>' . self::t('Invoices') . '</h3>' . ($rows === '' ? '<p class="pp-muted">' . self::t('No invoices yet.') . '</p>'
            : '<table class="pp-table"><thead><tr><th>' . self::t('Number') . '</th><th>' . self::t('Client') . '</th><th>' . self::t('Total') . '</th><th>' . self::t('Due') . '</th><th>' . self::t('State') . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>') . '</div>';
        $tests = 0;
        foreach (pp::Invoices('seller', $seller, 500) as $inv) {
            $tests += (int) $inv['in_test_in'];
        }
        if ($testing || $tests > 0) {
            $out .= '<div class="pp-card pp-tools"><h3>' . self::t('Test tools') . '</h3><p class="pp-muted">' . self::t('Try the whole cycle without waiting: start a plan for a test client, "Invoice now", pay it as the client with a test buyer account (PayPal sandbox) or a test card (Stripe: 4242 4242 4242 4242); or use "Make overdue" on a TEST invoice, then "Run billing now" to see the reminder and the client switched off, and pay to switch them on again.') . '</p>'
                . ($testing ? self::Button('TestTools', 'Run billing now', array('inWhat' => 'run'), 'btn-primary') : '')
                . ($tests > 0 ? self::Button('TestTools', 'Delete test invoices (%s)', array('inWhat' => 'delete'), 'btn-default', 'Delete all TEST invoices? A client switched off for one is switched on again.') : '') . '</div>';
            $out = str_replace(self::t('Delete test invoices (%s)'), self::t('Delete test invoices (%s)', array($tests)), $out);
        }
        return $out . $settings;
    }

    /**
     * The seller's packages (made in Package Manager), each with its price and its clients; a click
     * opens the package's own page, where its price is set.
     *
     * @param int $seller
     * @param array $s
     * @return string
     */
    static function PackagesCard($seller, array $s)
    {
        $cur = (string) $s['ps_currency_vc'];
        $prices = pp::Prices($seller);
        $on = array();
        $billed = array();
        foreach (pp::Clients($seller) as $c) {
            if ($c['admin']) {
                continue;
            }
            $on[$c['package']] = (isset($on[$c['package']]) ? $on[$c['package']] : 0) + 1;
            $plan = pp::Plan($c['id']);
            if ($plan && (int) $plan['pl_seller_fk'] === (int) $seller && (int) $plan['pl_active_in']) {
                $billed[$c['package']] = (isset($billed[$c['package']]) ? $billed[$c['package']] : 0) + 1;
            }
        }
        $rows = '';
        foreach (pp::Packages($seller) as $id => $name) {
            $p = isset($prices[$id]) ? $prices[$id] : array(0, 0);
            $url = './?module=payment_plans&amp;package=' . (int) $id;
            $price = array();
            if ($p[0] > 0) {
                $price[] = '<span class="pp-nowrap">' . self::h(pp::Show($p[0], $cur)) . ' <span class="pp-muted">' . self::t('/ month') . '</span></span>';
            }
            if ($p[1] > 0) {
                $price[] = '<span class="pp-nowrap">' . self::h(pp::Show($p[1], $cur)) . ' <span class="pp-muted">' . self::t('/ year') . '</span></span>';
            }
            $n = isset($on[$id]) ? $on[$id] : 0;
            $rows .= '<tr class="pp-pkgrow"><td><a href="' . $url . '"><b>' . self::h($name) . '</b> <span class="pp-go">&rsaquo;</span></a></td>'
                . '<td>' . ($price ? implode('<br>', $price) : '<a href="' . $url . '"><span class="pp-pill is-warn">' . self::t('No price yet: set it') . '</span></a>') . '</td>'
                . '<td><span class="pp-nowrap">' . self::t('%s client(s)', array($n)) . '</span>' . ($n > 0 ? '<div class="pp-muted pp-nowrap">' . self::t('%s billed', array(isset($billed[$id]) ? $billed[$id] : 0)) . '</div>' : '') . '</td>'
                . '</tr>';
        }
        return '<div class="pp-card"><h3>' . self::t('Your packages') . '</h3>'
            . ($rows === '' ? '<p class="pp-muted">' . self::t('You have no packages yet: make them in Package Manager, then give each one its price here.') . '</p>'
                : '<p class="pp-muted">' . self::t('The packages you made in Package Manager. Click one to give it its price: what your clients on it pay, monthly and/or yearly.') . '</p>'
                . '<table class="pp-table"><thead><tr><th>' . self::t('Package') . '</th><th>' . self::t('Price') . '</th><th>' . self::t('Clients') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>') . '</div>';
    }

    /**
     * One package's own page: what it includes (as Package Manager made it), its price, and the
     * clients on it.
     *
     * @param int $seller
     * @param int $id
     * @return string
     */
    static function PackageView($seller, $id)
    {
        $back = '<p><a href="./?module=payment_plans">&larr; ' . self::t('All packages') . '</a></p>';
        $info = pp::PackageInfo($seller, $id);
        if ($info === null) {
            return $back . '<div class="pp-card"><p>' . self::t('No such package of yours.') . '</p></div>';
        }
        $s = pp::Seller($seller);
        $cur = (string) $s['ps_currency_vc'];
        $prices = pp::Prices($seller);
        $p = isset($prices[$id]) ? $prices[$id] : array(0, 0);
        $limits = '';
        foreach ($info['limits'] as $label => $l) {
            $unlimited = $l['size'] ? $l['value'] <= 0 : $l['value'] < 0;
            $limits .= '<div class="pp-limit"><span>' . self::t($label) . '</span><b>' . ($unlimited ? self::t('Unlimited') : self::h(number_format($l['value'])) . ($l['size'] ? ' MB' : '')) . '</b></div>';
        }
        $tax = (int) $s['ps_taxrate_in'] > 0 ? self::t('before %s (%s%)', array((string) $s['ps_taxlabel_vc'] !== '' ? $s['ps_taxlabel_vc'] : 'tax', rtrim(rtrim(number_format((int) $s['ps_taxrate_in'] / 100, 2, '.', ''), '0'), '.'))) : '';
        $field = function ($n, $label, $v) use ($cur) {
            return '<label class="pp-field"><span>' . self::t($label) . '</span><div class="pp-money"><input class="form-control" name="' . $n . '" inputmode="decimal" maxlength="12" value="' . ($v > 0 ? self::h(pp::Plain($v, $cur)) : '') . '" placeholder="' . self::t('not offered') . '"><em>' . self::h($cur) . '</em></div></label>';
        };
        $out = $back . '<div class="pp-card"><h3>' . self::h($info['name']) . '</h3>'
            . '<div class="pp-limits">' . $limits . '</div>'
            . '<p class="pp-muted">' . self::t('What the package includes is set in Package Manager; here you give it its price.') . '</p></div>'
            . '<div class="pp-card"><h3>' . self::t('Price') . ($tax !== '' ? ' <span class="pp-muted">' . $tax . '</span>' : '') . '</h3>'
            . '<form method="post" action="./?module=payment_plans&amp;package=' . (int) $id . '&amp;action=Prices">' . runtime_csfr::Token()
            . '<div class="pp-row">' . $field('inMonth' . (int) $id, 'Monthly', $p[0]) . $field('inYear' . (int) $id, 'Yearly', $p[1]) . '</div>'
            . '<div class="pp-foot"><span class="pp-muted">' . self::t('Leave one empty to not offer it. Clients already on this package pay the new price from their next invoice.')
            . ((int) $s['ps_auto_in'] ? ' ' . self::t('New clients on it are billed by themselves (once you are live).') : '') . '</span>'
            . '<button class="btn btn-primary" type="submit">' . self::t('Save price') . '</button></div></form>'
            . (!empty($s['new']) ? '<p class="pp-muted">' . self::t('Prices are in %s until you choose your currency in Your details and payments.', array($cur)) . '</p>' : '') . '</div>';
        $rows = '';
        foreach (pp::Clients($seller) as $c) {
            if ($c['admin'] || (int) $c['package'] !== (int) $id) {
                continue;
            }
            $plan = pp::Plan($c['id']);
            $mine = $plan && (int) $plan['pl_seller_fk'] === (int) $seller;
            $state = $mine && (int) $plan['pl_active_in']
                ? self::t($plan['pl_period_vc'] === 'year' ? 'Yearly' : 'Monthly') . ', ' . self::t('paid until %s', array(ui_language::Date('j M Y', (int) $plan['pl_paiduntil_in'])))
                : '<span class="pp-muted">' . self::t($mine ? 'Stopped' : 'Not billed') . '</span>';
            $rows .= '<tr><td><b>' . self::h($c['username']) . '</b>' . (!$c['enabled'] ? ' <span class="pp-pill">' . self::t('off') . '</span>' : '') . '</td><td>' . $state . '</td></tr>';
        }
        return $out . '<div class="pp-card"><h3>' . self::t('Clients on this package') . '</h3>' . ($rows === '' ? '<p class="pp-muted">' . self::t('None yet.') . '</p>'
            : '<table class="pp-table"><tbody>' . $rows . '</tbody></table><p class="pp-muted">' . self::t('Start or change their plans under Clients, on the main page.') . '</p>') . '</div>';
    }

    /**
     * The seller's figures at a glance: received this month (big), last month, this year, what its
     * plans bring in a month, and what is waiting to be paid. Real invoices only.
     *
     * @param int $seller
     * @return string
     */
    static function Figures($seller)
    {
        $f = pp::Stats($seller);
        $cur = $f['currency'];
        // one amount in the seller's currency, then any other currency it was paid in
        $amount = function (array $by, $key) use ($cur) {
            $main = isset($by[$cur][$key]) ? $by[$cur][$key] : 0;
            $more = array();
            foreach ($by as $c => $v) {
                if ($c !== $cur && $v[$key] > 0) {
                    $more[] = '+ ' . pp::Show($v[$key], $c);
                }
            }
            return '<b>' . self::h(pp::Show($main, $cur)) . '</b>' . ($more ? '<em>' . self::h(implode(' ', $more)) . '</em>' : '');
        };
        $sum = function (array $by, $key) {
            $n = 0;
            foreach ($by as $v) {
                $n += $v[$key];
            }
            return $n;
        };
        $tax = isset($f['paid'][$cur]) ? $f['paid'][$cur]['yeartax'] : 0;
        $open = $sum($f['open'], 'count');
        $overdue = isset($f['open'][$cur]) ? $f['open'][$cur]['overdue'] : 0;
        return '<div class="pp-stats">'
            . '<div class="pp-stat is-big"><span>' . self::t('Received this month') . '</span>' . $amount($f['paid'], 'month')
            . '<small>' . self::t('%s payment(s); last month %s', array($sum($f['paid'], 'monthcount'), pp::Show(isset($f['paid'][$cur]) ? $f['paid'][$cur]['last'] : 0, $cur))) . '</small></div>'
            . '<div class="pp-stat"><span>' . self::t('This year') . '</span>' . $amount($f['paid'], 'year')
            . '<small>' . ($tax > 0 ? self::t('with %s in tax', array(pp::Show($tax, $cur))) : self::t('since 1 January')) . '</small></div>'
            . '<div class="pp-stat"><span>' . self::t('Coming in each month') . '</span><b>' . self::h(pp::Show($f['recurring'], $cur)) . '</b>'
            . '<small>' . self::t('from %s active plan(s), at today\'s prices (yearly ones as a twelfth)', array($f['plans'])) . '</small></div>'
            . '<div class="pp-stat"><span>' . self::t('Waiting to be paid') . '</span>' . $amount($f['open'], 'total')
            . '<small>' . self::t('%s invoice(s)', array($open)) . ($overdue > 0 ? ', <span class="pp-overdue">' . self::t('%s overdue', array(pp::Show($overdue, $cur))) . '</span>' : '') . '</small></div>'
            . '</div>';
    }

    /**
     * The billed account's view.
     *
     * @param int $uid
     * @return string
     */
    static function Billing($uid)
    {
        $plan = pp::Plan($uid);
        $invoices = pp::Invoices('acc', $uid, 100);
        if (!$plan && !$invoices) {
            return '<div class="pp-card"><p>' . self::t('You have no invoices: your hosting is not billed through the panel.') . '</p></div>';
        }
        $out = '';
        if ($plan && (int) $plan['pl_active_in']) {
            $s = pp::Seller((int) $plan['pl_seller_fk']);
            $out .= '<div class="pp-card"><h3>' . self::t('Your plan') . '</h3><p>' . self::t('%s, paid until %s.', array(ui_language::translate($plan['pl_period_vc'] === 'year' ? 'Yearly' : 'Monthly'),
                ui_language::Date('j M Y', (int) $plan['pl_paiduntil_in']))) . ' ' . self::t('From %s.', array($s['ps_name_vc'])) . '</p>'
                . ((int) $plan['pl_suspended_in'] ? '<div class="pp-msg is-bad">' . self::t('Your hosting is switched off because an invoice is unpaid. Paying it switches it on again within minutes.') . '</div>' : '') . '</div>';
        }
        $openRows = '';
        $rows = '';
        foreach ($invoices as $inv) {
            $view = '<a class="btn btn-sm btn-default" href="./?module=payment_plans&amp;invoice=' . (int) $inv['in_id_pk'] . '&amp;tab=billing">' . self::t('View') . '</a>';
            $line = '<td>' . self::h($inv['in_number_vc']) . '</td><td>' . self::h(ui_language::Date('j M Y', (int) $inv['in_from_in'])) . ' – ' . self::h(ui_language::Date('j M Y', (int) $inv['in_to_in'])) . '</td>'
                . '<td>' . self::h(pp::Show((int) $inv['in_total_in'], (string) $inv['in_currency_vc'])) . '</td><td>' . self::h(ui_language::Date('j M Y', (int) $inv['in_due_in'])) . '</td><td>' . self::State($inv) . '</td>';
            if ((string) $inv['in_status_vc'] === 'open') {
                $seller = pp::Seller((int) $inv['in_seller_fk']);
                $ready = pp::CanPay($seller, $inv);
                $payWith = pp_pay::Name((string) $seller['ps_proc_vc']);
                $openRows .= '<tr>' . $line . '<td class="pp-actions">' . $view . ($ready ? str_replace(self::t('Pay with %s'), self::t('Pay with %s', array($payWith)), self::Button('Pay', 'Pay with %s', array('inId' => (int) $inv['in_id_pk']), 'btn-primary pp-' . self::h((string) $seller['ps_proc_vc']))) : '<span class="pp-muted">' . self::t('Pay as your provider tells you.') . '</span>') . '</td></tr>';
            } else {
                $rows .= '<tr>' . $line . '<td class="pp-actions">' . $view . '</td></tr>';
            }
        }
        $head = '<thead><tr><th>' . self::t('Number') . '</th><th>' . self::t('Period') . '</th><th>' . self::t('Total') . '</th><th>' . self::t('Due') . '</th><th>' . self::t('State') . '</th><th></th></tr></thead>';
        if ($openRows !== '') {
            $out .= '<div class="pp-card"><h3>' . self::t('To pay') . '</h3><table class="pp-table">' . $head . '<tbody>' . $openRows . '</tbody></table><p class="pp-muted">' . self::t('You pay on the payment service\'s own pages (cards too); your card never reaches this server.') . '</p></div>';
        }
        if ($rows !== '') {
            $out .= '<div class="pp-card"><h3>' . self::t('Earlier invoices') . '</h3><table class="pp-table">' . $head . '<tbody>' . $rows . '</tbody></table></div>';
        }
        return $out;
    }

    /**
     * One invoice, printable: its buyer and its seller only.
     *
     * @param int $id
     * @return string
     */
    static function InvoiceView($id)
    {
        $me = (int) self::Me()['userid'];
        $inv = pp::Invoice($id);
        if (!$inv || ((int) $inv['in_acc_fk'] !== $me && (int) $inv['in_seller_fk'] !== $me)) {
            return '<div class="pp-card"><p>' . self::t('No such invoice of yours.') . '</p></div>';
        }
        $cur = (string) $inv['in_currency_vc'];
        $back = (int) $inv['in_acc_fk'] === $me ? './?module=payment_plans&amp;tab=billing' : './?module=payment_plans';
        $line = function ($a, $b, $strong = false) {
            return '<tr' . ($strong ? ' class="pp-total"' : '') . '><td>' . $a . '</td><td>' . $b . '</td></tr>';
        };
        $tax = (int) $inv['in_taxrate_in'] > 0 ? $line(self::h(($inv['in_taxlabel_vc'] !== '' ? $inv['in_taxlabel_vc'] : ui_language::translate('Tax')) . ' ' . rtrim(rtrim(sprintf('%.2f', (int) $inv['in_taxrate_in'] / 100), '0'), '.') . '%'),
            self::h(pp::Show((int) $inv['in_tax_in'], $cur))) : '';
        return '<div class="pp-head pp-noprint"><a class="btn btn-default btn-sm" href="' . $back . '">' . self::t('Back') . '</a><button class="btn btn-default btn-sm" type="button" onclick="window.print()">' . self::t('Print') . '</button></div>'
            . '<div class="pp-card pp-invoice">' . ((int) $inv['in_test_in'] ? '<div class="pp-testbanner">' . self::t('Test invoice: made in test mode, paid (if at all) in the payment service\'s test mode. No real money.') . '</div>' : '') . '<div class="pp-inv-top"><div><h2>' . self::t('Invoice') . ' ' . self::h($inv['in_number_vc']) . '</h2>' . self::State($inv) . '</div>'
            . '<div class="pp-addr">' . nl2br(self::h($inv['in_seller_tx'])) . '</div></div>'
            . '<div class="pp-inv-mid"><div><b>' . self::t('To') . '</b><div class="pp-addr">' . nl2br(self::h($inv['in_buyer_tx'])) . '</div></div>'
            . '<div><b>' . self::t('Date') . '</b> ' . self::h(ui_language::Date('j M Y', (int) $inv['in_created_in'])) . '<br><b>' . self::t('Due') . '</b> ' . self::h(ui_language::Date('j M Y', (int) $inv['in_due_in']))
            . ((string) $inv['in_status_vc'] === 'paid' ? '<br><b>' . self::t('Paid') . '</b> ' . self::h(ui_language::Date('j M Y', (int) $inv['in_paid_in'])) . ' (' . self::h($inv['in_method_vc']) . ')' : '') . '</div></div>'
            . '<table class="pp-table pp-lines"><tbody>'
            . $line(self::t('Hosting: %s, %s', array($inv['in_package_vc'], ui_language::translate($inv['in_period_vc'] === 'year' ? 'yearly' : 'monthly'))) . '<div class="pp-muted">' . self::h(ui_language::Date('j M Y', (int) $inv['in_from_in'])) . ' – ' . self::h(ui_language::Date('j M Y', (int) $inv['in_to_in'])) . '</div>',
                self::h(pp::Show((int) $inv['in_net_in'], $cur)))
            . $tax . $line(self::t('Total'), self::h(pp::Show((int) $inv['in_total_in'], $cur)), true) . '</tbody></table>'
            . self::HistoryHtml((int) $inv['in_id_pk'], (string) $inv['in_currency_vc'], (int) $inv['in_seller_fk'] === $me)
            . ((string) $inv['in_capture_vc'] !== '' ? '<p class="pp-muted">' . self::t('%s payment %s', array($inv['in_method_vc'] !== '' ? $inv['in_method_vc'] : pp_pay::Name((string) $inv['in_proc_vc']), $inv['in_capture_vc'])) . '</p>' : '') . '</div>';
    }

    /**
     * An invoice's history (every step, as the panel noted it).
     *
     * @param int $id
     * @param string $cur
     * @param bool $seller The seller looks (it sees who did what; a client does not see its seller's user names).
     * @return string
     */
    static function HistoryHtml($id, $cur, $seller)
    {
        $words = array('made' => 'Invoice made', 'started' => 'Payment started', 'closed' => 'Payment page closed', 'pending' => 'Payment being checked', 'paid' => 'Paid',
            'failed' => 'Payment not completed', 'refused' => 'Payment not counted', 'void' => 'Voided', 'off' => 'Hosting switched off', 'on' => 'Hosting switched on',
            'refunded' => 'Refunded', 'partrefund' => 'Partly refunded', 'dispute' => 'Chargeback opened', 'disputewon' => 'Chargeback won', 'disputelost' => 'Charged back');
        $rows = '';
        foreach (pp::History($id) as $h) {
            $what = isset($words[$h['lg_what_vc']]) ? $words[$h['lg_what_vc']] : $h['lg_what_vc'];
            $detail = array();
            if ((string) $h['lg_note_vc'] !== '') {
                $detail[] = self::h($h['lg_note_vc']);
            }
            if ($h['lg_amount_in'] !== null && in_array($h['lg_what_vc'], array('paid', 'pending', 'refused', 'started', 'refunded', 'partrefund'), true)) {
                $detail[] = self::h(pp::Show((int) $h['lg_amount_in'], (string) $h['lg_currency_vc'] !== '' ? (string) $h['lg_currency_vc'] : $cur));
            }
            if ((string) $h['lg_ref_vc'] !== '') {
                $detail[] = '<code>' . self::h($h['lg_ref_vc']) . '</code>';
            }
            if ($seller && (int) $h['lg_by_fk'] > 0) {
                $u = ctrl_users::GetUserDetail((int) $h['lg_by_fk']);
                $detail[] = self::t('by %s', array($u['username'] !== '' ? $u['username'] : '#' . (int) $h['lg_by_fk']));
            }
            $rows .= '<tr class="pp-h-' . self::h($h['lg_what_vc']) . '"><td>' . self::h(ui_language::Date('j M Y H:i', (int) $h['lg_time_in'])) . '</td><td><b>' . self::t($what) . '</b></td><td>' . implode(' · ', $detail) . '</td></tr>';
        }
        return $rows === '' ? '' : '<div class="pp-history"><h4>' . self::t('History') . '</h4><table class="pp-table">' . $rows . '</table></div>';
    }

    /**
     * The page's title: the module's name for sellers (administrators, resellers); "Billing" for a
     * client, who sees only its own invoices and payments here.
     *
     * @return string
     */
    static function getTitle()
    {
        $me = self::Me();
        return is_array($me) && pp::CanSell($me) ? self::h(self::getModuleName()) : self::t('Billing');
    }

    /**
     * The line under the title: what the module does, for sellers; for a client, what it finds here.
     *
     * @return string
     */
    static function getIntro()
    {
        $me = self::Me();
        return is_array($me) && pp::CanSell($me) ? self::getModuleDesc()
            : self::t('Your hosting invoices: what is due and when, paying online, and every invoice you paid.');
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
