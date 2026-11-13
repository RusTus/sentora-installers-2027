<?php

/**
 * Payment Plans, the panel's side: an account that has clients (an administrator or a reseller,
 * "the seller") prices its packages, gives its clients a plan (a package, monthly or yearly), and
 * the panel invoices them ahead of each period; clients pay with the processor the seller chose
 * (PayPal, Stripe: pp_pay) into the seller's own account, or the seller marks an invoice paid. A
 * new client on a priced package gets its plan by itself (AutoPlans). An invoice not paid within
 * the seller's grace days switches the client off; paying switches it on again.
 *
 * Money is kept in the currency's smallest unit (cents) as integers, and every amount comes from
 * these tables: never from a browser.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class pp
{
    /** What the panel writes as the reason it switched a client off (ws_suspend), so it only lifts its own. */
    const REASON = 'Not paid: invoice ';

    /** Largest amount of one invoice (in the currency's main unit). */
    const MAX_AMOUNT = 1000000;

    /**
     * The currencies PayPal takes, with their decimals.
     *
     * @return array<string, int>
     */
    static function Currencies()
    {
        return array('USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CAD' => 2, 'AUD' => 2, 'NZD' => 2, 'CHF' => 2, 'SEK' => 2, 'NOK' => 2, 'DKK' => 2,
            'PLN' => 2, 'CZK' => 2, 'HUF' => 0, 'JPY' => 0, 'TWD' => 0, 'HKD' => 2, 'SGD' => 2, 'MXN' => 2, 'BRL' => 2, 'ILS' => 2,
            'MYR' => 2, 'PHP' => 2, 'THB' => 2);
    }

    /**
     * Create the tables if missing (once per request).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return void
     */
    static function Ensure()
    {
        global $zdbh;
        static $done = false;
        if ($done) {
            return;
        }
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_pp_sellers (
            ps_acc_fk INT NOT NULL PRIMARY KEY, ps_name_vc VARCHAR(120) NOT NULL DEFAULT '', ps_address_tx TEXT NULL, ps_email_vc VARCHAR(200) NOT NULL DEFAULT '',
            ps_currency_vc CHAR(3) NOT NULL DEFAULT 'USD', ps_taxlabel_vc VARCHAR(30) NOT NULL DEFAULT '', ps_taxrate_in INT NOT NULL DEFAULT 0,
            ps_prefix_vc VARCHAR(20) NOT NULL DEFAULT 'INV-', ps_next_in INT NOT NULL DEFAULT 1, ps_lead_in INT NOT NULL DEFAULT 7, ps_grace_in INT NOT NULL DEFAULT 7,
            ps_suspend_in TINYINT NOT NULL DEFAULT 1, ps_mode_vc VARCHAR(10) NOT NULL DEFAULT 'sandbox', ps_clientid_vc VARCHAR(200) NOT NULL DEFAULT '',
            ps_secret_tx TEXT NULL, ps_updated_in INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_pp_prices (
            pr_seller_fk INT NOT NULL, pr_package_fk INT NOT NULL, pr_month_in BIGINT NOT NULL DEFAULT 0, pr_year_in BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (pr_seller_fk, pr_package_fk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_pp_plans (
            pl_acc_fk INT NOT NULL PRIMARY KEY, pl_seller_fk INT NOT NULL, pl_period_vc VARCHAR(5) NOT NULL DEFAULT 'month', pl_paiduntil_in INT NOT NULL,
            pl_active_in TINYINT NOT NULL DEFAULT 1, pl_suspended_in TINYINT NOT NULL DEFAULT 0, pl_created_in INT NOT NULL, KEY pl_seller (pl_seller_fk))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_pp_invoices (
            in_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, in_seller_fk INT NOT NULL, in_acc_fk INT NOT NULL, in_number_vc VARCHAR(40) NOT NULL,
            in_package_vc VARCHAR(120) NOT NULL DEFAULT '', in_period_vc VARCHAR(5) NOT NULL, in_from_in INT NOT NULL, in_to_in INT NOT NULL,
            in_net_in BIGINT NOT NULL, in_taxlabel_vc VARCHAR(30) NOT NULL DEFAULT '', in_taxrate_in INT NOT NULL DEFAULT 0, in_tax_in BIGINT NOT NULL DEFAULT 0,
            in_total_in BIGINT NOT NULL, in_currency_vc CHAR(3) NOT NULL, in_seller_tx TEXT NULL, in_buyer_tx TEXT NULL,
            in_status_vc VARCHAR(10) NOT NULL DEFAULT 'open', in_due_in INT NOT NULL, in_created_in INT NOT NULL, in_paid_in INT NULL,
            in_method_vc VARCHAR(60) NOT NULL DEFAULT '', in_order_vc VARCHAR(255) NULL, in_ordermode_vc VARCHAR(10) NULL, in_order_in INT NULL,
            in_capture_vc VARCHAR(255) NULL, in_payer_vc VARCHAR(200) NOT NULL DEFAULT '', in_reminded_in TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY in_number (in_seller_fk, in_number_vc), UNIQUE KEY in_capture (in_capture_vc), KEY in_acc (in_acc_fk), KEY in_order (in_order_vc))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // version 101: test mode (sandbox keys of their own, TEST- invoices with their own numbers)
        self::AddColumn('x_pp_invoices', 'in_test_in', 'TINYINT NOT NULL DEFAULT 0');
        self::AddColumn('x_pp_sellers', 'ps_testnext_in', 'INT NOT NULL DEFAULT 1');
        if (self::AddColumn('x_pp_sellers', 'ps_sbclientid_vc', "VARCHAR(200) NOT NULL DEFAULT ''")) {
            self::AddColumn('x_pp_sellers', 'ps_sbsecret_tx', 'TEXT NULL');
            // what version 100 did in sandbox mode was testing: its keys are sandbox keys, its invoices test invoices
            $zdbh->exec("UPDATE x_pp_invoices i JOIN x_pp_sellers s ON s.ps_acc_fk = i.in_seller_fk SET i.in_test_in = 1 WHERE s.ps_mode_vc = 'sandbox'");
            $zdbh->exec("UPDATE x_pp_sellers SET ps_sbclientid_vc = ps_clientid_vc, ps_sbsecret_tx = ps_secret_tx, ps_clientid_vc = '', ps_secret_tx = ''
                WHERE ps_mode_vc = 'sandbox' AND ps_clientid_vc <> ''");
        }
        // version 102: several processors (the seller's choice; keys of their own in x_pp_keys) and automatic plans
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_pp_keys (
            pk_seller_fk INT NOT NULL, pk_proc_vc VARCHAR(20) NOT NULL, pk_mode_vc VARCHAR(10) NOT NULL, pk_data_tx TEXT NULL, pk_updated_in INT NOT NULL DEFAULT 0,
            PRIMARY KEY (pk_seller_fk, pk_proc_vc, pk_mode_vc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::AddColumn('x_pp_invoices', 'in_proc_vc', "VARCHAR(20) NOT NULL DEFAULT ''");
        self::AddColumn('x_pp_sellers', 'ps_auto_in', 'TINYINT NOT NULL DEFAULT 1');
        self::AddColumn('x_pp_sellers', 'ps_autofrom_in', 'INT NOT NULL DEFAULT 0');
        self::AddColumn('x_pp_sellers', 'ps_period_vc', "VARCHAR(5) NOT NULL DEFAULT 'month'");
        // Payment Plans on or off, per seller (off: no invoices, reminders, automatic plans or switching
        // off); a seller that was billing before this switch existed stays on
        if (self::AddColumn('x_pp_sellers', 'ps_on_in', 'TINYINT NOT NULL DEFAULT 0')) {
            $zdbh->exec("UPDATE x_pp_sellers SET ps_on_in = 1");
        }
        // the date a plan is really paid until (a test payment, no real money, moves only pl_paiduntil_in)
        if (self::AddColumn('x_pp_plans', 'pl_realuntil_in', 'INT NOT NULL DEFAULT 0')) {
            $zdbh->exec("UPDATE x_pp_plans SET pl_realuntil_in = pl_paiduntil_in");
        }
        // version 103: every step of every invoice, kept (made, payment started, paid, refused, voided, switched off...)
        $zdbh->exec("CREATE TABLE IF NOT EXISTS x_pp_log (
            lg_id_pk INT NOT NULL AUTO_INCREMENT PRIMARY KEY, lg_time_in INT NOT NULL, lg_seller_fk INT NOT NULL, lg_acc_fk INT NOT NULL, lg_inv_fk INT NOT NULL DEFAULT 0,
            lg_what_vc VARCHAR(20) NOT NULL, lg_amount_in BIGINT NULL, lg_currency_vc CHAR(3) NOT NULL DEFAULT '', lg_proc_vc VARCHAR(20) NOT NULL DEFAULT '',
            lg_ref_vc VARCHAR(255) NOT NULL DEFAULT '', lg_note_vc VARCHAR(255) NOT NULL DEFAULT '', lg_by_fk INT NOT NULL DEFAULT 0,
            KEY lg_inv (lg_inv_fk), KEY lg_seller (lg_seller_fk, lg_time_in)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (self::AddColumn('x_pp_sellers', 'ps_proc_vc', "VARCHAR(20) NOT NULL DEFAULT ''")) {
            // Stripe's references are longer than PayPal's
            $zdbh->exec("ALTER TABLE x_pp_invoices MODIFY in_order_vc VARCHAR(255) NULL, MODIFY in_capture_vc VARCHAR(255) NULL");
            // until now every seller took PayPal: its keys move to x_pp_keys (the secrets as they are, still encrypted)
            $zdbh->exec("UPDATE x_pp_sellers SET ps_proc_vc = 'paypal'");
            $zdbh->exec("UPDATE x_pp_invoices SET in_proc_vc = 'paypal' WHERE in_order_vc IS NOT NULL");
            $add = $zdbh->prepare("REPLACE INTO x_pp_keys (pk_seller_fk, pk_proc_vc, pk_mode_vc, pk_data_tx, pk_updated_in) VALUES (:s, 'paypal', :m, :d, :t)");
            foreach ($zdbh->query("SELECT ps_acc_fk, ps_clientid_vc, ps_secret_tx, ps_sbclientid_vc, ps_sbsecret_tx FROM x_pp_sellers")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                foreach (array('live' => array($r['ps_clientid_vc'], $r['ps_secret_tx']), 'sandbox' => array($r['ps_sbclientid_vc'], $r['ps_sbsecret_tx'])) as $m => $k) {
                    $d = array_filter(array('clientid' => (string) $k[0], 'secret' => (string) $k[1]), 'strlen');
                    if ($d) {
                        $add->execute(array(':s' => (int) $r['ps_acc_fk'], ':m' => $m, ':d' => json_encode($d), ':t' => time()));
                    }
                }
            }
            // (kept in one place only)
            $zdbh->exec("UPDATE x_pp_sellers SET ps_clientid_vc = '', ps_secret_tx = NULL, ps_sbclientid_vc = '', ps_sbsecret_tx = NULL");
            // automatic plans for the clients made from now on (the clients they have already are theirs to start)
            $zdbh->prepare("UPDATE x_pp_sellers SET ps_autofrom_in = :t")->execute(array(':t' => time()));
        }
        // version 105: refunds and chargebacks of paid invoices (looked at once a day: pp_pay::Reversals)
        self::AddColumn('x_pp_invoices', 'in_refund_vc', "VARCHAR(10) NOT NULL DEFAULT ''");
        self::AddColumn('x_pp_invoices', 'in_refunded_in', 'BIGINT NULL');
        self::AddColumn('x_pp_invoices', 'in_dispute_vc', "VARCHAR(10) NOT NULL DEFAULT ''");
        self::AddColumn('x_pp_invoices', 'in_checked_in', 'INT NOT NULL DEFAULT 0');
        $done = true;
    }

    /**
     * Tell a seller about one of its invoices (a refund or a chargeback found at its processor): a
     * notice in the panel and an email to its billing address (else its account's).
     *
     * @param array $inv
     * @param string $title
     * @param string $body
     * @return void
     */
    static function TellSeller(array $inv, $title, $body)
    {
        $s = self::Seller((int) $inv['in_seller_fk']);
        $u = ctrl_users::GetUserDetail((int) $inv['in_seller_fk']);
        if ((int) ($inv['in_test_in'] ?? 0)) {
            $title = 'TEST: ' . $title;
            $body .= ' (A test invoice: no real money.)';
        }
        try {
            ctrl_notices::Send(array('scope' => 'user', 'targets' => array((int) $inv['in_seller_fk']), 'type' => 'warning', 'from' => 0, 'source' => 'payment_plans',
                'key' => 'pp-reversal-' . (int) $inv['in_id_pk'], 'closable' => 'login', 'title' => $title, 'body' => $body));
        } catch (Exception $e) {
        }
        $to = filter_var((string) $s['ps_email_vc'], FILTER_VALIDATE_EMAIL) ? (string) $s['ps_email_vc'] : (string) ($u['email'] ?? '');
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            try {
                $m = new sys_email();
                $m->Subject = 'Payment Plans: ' . $title;
                $m->Body = "Hello " . (string) ($u['username'] ?? '') . ",\n\n" . $body . "\n\nIt is noted on the invoice (Payment Plans > the invoice > History). Nothing was changed for the client: what happens now is yours to decide.\n\n"
                    . self::PanelUrl((int) $inv['in_seller_fk']) . "\n";
                $m->AddAddress($to);
                $m->SendEmail();
            } catch (Exception $e) {
            }
        }
    }

    /**
     * Add a column when missing.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $table
     * @param string $col
     * @param string $def
     * @return bool Whether it was added now.
     */
    static function AddColumn($table, $col, $def)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $q->execute(array(':t' => $table, ':c' => $col));
        if ((int) $q->fetchColumn() > 0) {
            return false;
        }
        $zdbh->exec("ALTER TABLE `" . $table . "` ADD `" . $col . "` " . $def);
        return true;
    }

    // ----- money -----

    /**
     * An amount typed (12.50, 12,50) in the currency's smallest unit, or false.
     *
     * @param string $text
     * @param string $currency
     * @return int|false
     */
    static function Parse($text, $currency)
    {
        $d = self::Decimals($currency);
        $t = str_replace(array(' ', ','), array('', '.'), trim((string) $text));
        if ($t === '') {
            return 0;
        }
        if (!preg_match('/^\d{1,7}(\.\d{1,' . max(1, $d) . '})?$/', $t) || ($d === 0 && strpos($t, '.') !== false)) {
            return false;
        }
        $parts = explode('.', $t . '.');
        $minor = (int) $parts[0] * (10 ** $d) + ($d > 0 ? (int) str_pad($parts[1], $d, '0') : 0);
        return $minor > self::MAX_AMOUNT * (10 ** $d) ? false : $minor;
    }

    /**
     * Decimals of a currency.
     *
     * @param string $currency
     * @return int
     */
    static function Decimals($currency)
    {
        $c = self::Currencies();
        return isset($c[$currency]) ? $c[$currency] : 2;
    }

    /**
     * An amount as PayPal and the forms take it: 12.50 (no thousands separator).
     *
     * @param int $minor
     * @param string $currency
     * @return string
     */
    static function Plain($minor, $currency)
    {
        $d = self::Decimals($currency);
        return $d === 0 ? (string) (int) $minor : sprintf('%d.%0' . $d . 'd', intdiv((int) $minor, 10 ** $d), (int) $minor % (10 ** $d));
    }

    /**
     * An amount to read: 1,234.50 USD.
     *
     * @param int $minor
     * @param string $currency
     * @return string
     */
    static function Show($minor, $currency)
    {
        $d = self::Decimals($currency);
        return number_format($minor / (10 ** $d), $d, '.', ',') . ' ' . $currency;
    }

    /**
     * Tax on a net amount (rate in hundredths of a percent: 2000 = 20%), rounded half up.
     *
     * @param int $net
     * @param int $rate
     * @return int
     */
    static function Tax($net, $rate)
    {
        return intdiv((int) $net * (int) $rate + 5000, 10000);
    }

    // ----- who is who -----

    /**
     * May the account bill clients (administrators and resellers)?
     *
     * @param array $u ctrl_users::GetUserDetail()
     * @return bool
     */
    static function CanSell(array $u)
    {
        return in_array((int) $u['usergroupid'], array(1, 2), true);
    }

    /**
     * The account's direct clients (not deleted, not itself).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @return array<int, array> By account ID: id, username, email, fullname, package, packagename, enabled, admin, created.
     */
    static function Clients($seller)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT a.ac_id_pk, a.ac_user_vc, a.ac_email_vc, a.ac_package_fk, a.ac_enabled_in, a.ac_group_fk, a.ac_created_ts, p.pk_name_vc, u.ud_fullname_vc
            FROM x_accounts a LEFT JOIN x_packages p ON p.pk_id_pk = a.ac_package_fk LEFT JOIN x_profiles u ON u.ud_user_fk = a.ac_id_pk
            WHERE a.ac_reseller_fk = :s AND a.ac_id_pk <> :s2 AND a.ac_deleted_ts IS NULL ORDER BY a.ac_user_vc");
        $q->execute(array(':s' => (int) $seller, ':s2' => (int) $seller));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['ac_id_pk']] = array('id' => (int) $r['ac_id_pk'], 'username' => (string) $r['ac_user_vc'], 'email' => (string) $r['ac_email_vc'],
                'fullname' => (string) $r['ud_fullname_vc'], 'package' => (int) $r['ac_package_fk'], 'packagename' => (string) $r['pk_name_vc'],
                'enabled' => (int) $r['ac_enabled_in'] === 1, 'admin' => (int) $r['ac_group_fk'] === 1, 'created' => (int) $r['ac_created_ts']);
        }
        return $out;
    }

    /**
     * The account's own packages (the ones it gives its clients).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @return array<int, string> ID => name.
     */
    static function Packages($seller)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_reseller_fk = :s AND pk_deleted_ts IS NULL ORDER BY pk_name_vc");
        $q->execute(array(':s' => (int) $seller));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['pk_id_pk']] = (string) $r['pk_name_vc'];
        }
        return $out;
    }

    /**
     * One of the seller's own packages as Package Manager made it (read only: Payment Plans never
     * changes a package): its name and what it includes.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @param int $id
     * @return array|null name, and limits: label => a count (-1: no limit) or MB (0: no limit), with
     *                    'size' => true for the sizes; null when it is not this seller's package.
     */
    static function PackageInfo($seller, $id)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT p.pk_name_vc, q.* FROM x_packages p LEFT JOIN x_quotas q ON q.qt_package_fk = p.pk_id_pk
            WHERE p.pk_id_pk = :id AND p.pk_reseller_fk = :s AND p.pk_deleted_ts IS NULL");
        $q->execute(array(':id' => (int) $id, ':s' => (int) $seller));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $limits = array();
        foreach (array('Domains' => 'qt_domains_in', 'Sub-domains' => 'qt_subdomains_in', 'Parked domains' => 'qt_parkeddomains_in', 'Mailboxes' => 'qt_mailboxes_in',
            'Forwarders' => 'qt_fowarders_in', 'Distribution lists' => 'qt_distlists_in', 'FTP accounts' => 'qt_ftpaccounts_in', 'MySQL databases' => 'qt_mysql_in') as $label => $col) {
            $limits[$label] = array('value' => isset($r[$col]) ? (int) $r[$col] : 0, 'size' => false);
        }
        // (Package Manager keeps the sizes in bytes of 1024000: MB as it shows them)
        foreach (array('Disk space' => 'qt_diskspace_bi', 'Monthly bandwidth' => 'qt_bandwidth_bi') as $label => $col) {
            $limits[$label] = array('value' => isset($r[$col]) ? (int) round((float) $r[$col] / 1024000) : 0, 'size' => true);
        }
        return array('name' => (string) $r['pk_name_vc'], 'limits' => $limits);
    }

    /**
     * Who bills the account: the account above it (ac_reseller_fk), when that one has billing set up.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @return int 0 when nobody.
     */
    static function SellerOf($uid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_reseller_fk FROM x_accounts WHERE ac_id_pk = :u AND ac_deleted_ts IS NULL");
        $q->execute(array(':u' => (int) $uid));
        $s = (int) $q->fetchColumn();
        return $s > 0 && $s !== (int) $uid ? $s : 0;
    }

    // ----- sellers -----

    /**
     * A seller's settings (defaults when none yet).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @return array
     */
    static function Seller($seller)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_pp_sellers WHERE ps_acc_fk = :s");
        $q->execute(array(':s' => (int) $seller));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : array('ps_acc_fk' => (int) $seller, 'ps_name_vc' => '', 'ps_address_tx' => '', 'ps_email_vc' => '', 'ps_currency_vc' => 'USD',
            'ps_taxlabel_vc' => '', 'ps_taxrate_in' => 0, 'ps_prefix_vc' => 'INV-', 'ps_next_in' => 1, 'ps_lead_in' => 7, 'ps_grace_in' => 7,
            'ps_suspend_in' => 1, 'ps_mode_vc' => 'sandbox', 'ps_updated_in' => 0, 'ps_testnext_in' => 1, 'ps_proc_vc' => '',
            'ps_auto_in' => 1, 'ps_autofrom_in' => 0, 'ps_period_vc' => 'month', 'ps_on_in' => 0, 'new' => true);
    }

    /**
     * Is the seller in test mode (the processor's test mode, TEST- invoices)?
     *
     * @param array $s
     * @return bool
     */
    static function Testing(array $s)
    {
        return (string) $s['ps_mode_vc'] !== 'live';
    }

    /**
     * Can the invoice be paid online now? A real invoice with the seller's live keys; a TEST invoice
     * only while its seller is in test mode (test keys), never once it has gone live.
     *
     * @param array $s The seller.
     * @param array $inv
     * @return bool
     */
    static function CanPay(array $s, array $inv)
    {
        $mode = self::InvoiceMode($inv);
        return ($mode === 'live' || self::Testing($s)) && pp_pay::Ready($s, null, $mode);
    }

    /**
     * The mode an invoice is paid in: a test invoice only ever in test mode ('sandbox'), a real one
     * only live.
     *
     * @param array $inv
     * @return string
     */
    static function InvoiceMode(array $inv)
    {
        return (int) ($inv['in_test_in'] ?? 0) ? 'sandbox' : 'live';
    }

    /**
     * Save a seller's settings (checked by the caller; the processor's keys are saved apart, by
     * pp_pay::SaveKeys). Turning automatic plans on starts them for the clients made from now on.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @param array $v
     * @return void
     */
    static function SaveSeller($seller, array $v)
    {
        global $zdbh;
        self::Ensure();
        $old = self::Seller($seller);
        $auto = !empty($v['auto']);
        // automatic plans count new clients from when they are turned on, or from going live
        $live = $v['mode'] === 'live' && (!empty($old['new']) || (string) $old['ps_mode_vc'] !== 'live');
        $from = $auto && (!empty($old['new']) || !(int) $old['ps_auto_in'] || $live) ? time() : (int) $old['ps_autofrom_in'];
        $zdbh->prepare("REPLACE INTO x_pp_sellers (ps_acc_fk, ps_name_vc, ps_address_tx, ps_email_vc, ps_currency_vc, ps_taxlabel_vc, ps_taxrate_in, ps_prefix_vc,
            ps_next_in, ps_lead_in, ps_grace_in, ps_suspend_in, ps_mode_vc, ps_updated_in, ps_testnext_in, ps_proc_vc, ps_auto_in, ps_autofrom_in, ps_period_vc, ps_on_in)
            VALUES (:a, :n, :ad, :e, :c, :tl, :tr, :p, :nx, :l, :g, :su, :m, :t, :tn, :pr, :au, :af, :pe, :on)")
            ->execute(array(':a' => (int) $seller, ':n' => $v['name'], ':ad' => $v['address'], ':e' => $v['email'], ':c' => $v['currency'], ':tl' => $v['taxlabel'],
                ':tr' => (int) $v['taxrate'], ':p' => $v['prefix'], ':nx' => max((int) $old['ps_next_in'], (int) $v['next']), ':l' => (int) $v['lead'],
                ':g' => (int) $v['grace'], ':su' => $v['suspend'] ? 1 : 0, ':m' => $v['mode'], ':t' => time(), ':tn' => max(1, (int) $old['ps_testnext_in']),
                ':pr' => (string) $v['proc'], ':au' => $auto ? 1 : 0, ':af' => $from, ':pe' => $v['period'] === 'year' ? 'year' : 'month', ':on' => (int) $old['ps_on_in'] ? 1 : 0));
    }

    // ----- prices and plans -----

    /**
     * A seller's prices: package => array(month, year) in the smallest unit (0: not offered).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @return array<int, array{0:int, 1:int}>
     */
    static function Prices($seller)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT pr_package_fk, pr_month_in, pr_year_in FROM x_pp_prices WHERE pr_seller_fk = :s");
        $q->execute(array(':s' => (int) $seller));
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['pr_package_fk']] = array((int) $r['pr_month_in'], (int) $r['pr_year_in']);
        }
        return $out;
    }

    /**
     * A client's plan, or null.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @return array|null
     */
    static function Plan($uid)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_pp_plans WHERE pl_acc_fk = :u");
        $q->execute(array(':u' => (int) $uid));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    /**
     * The period a client on a package is billed for: the one asked for when the package has a price
     * for it, else the other one; '' when the package has no price.
     *
     * @param array $prices From Prices().
     * @param int $package
     * @param string $want month or year
     * @return string
     */
    static function PickPeriod(array $prices, $package, $want)
    {
        $p = isset($prices[(int) $package]) ? $prices[(int) $package] : array(0, 0);
        $want = $want === 'year' ? 'year' : 'month';
        if ($p[$want === 'year' ? 1 : 0] > 0) {
            return $want;
        }
        return $p[$want === 'year' ? 0 : 1] > 0 ? ($want === 'year' ? 'month' : 'year') : '';
    }

    /**
     * Start (or change) a client's plan with a seller (checked by the caller).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @param int $uid
     * @param string $period month or year
     * @param int $paidUntil
     * @return void
     */
    static function StartPlan($seller, $uid, $period, $paidUntil)
    {
        global $zdbh;
        self::Ensure();
        $plan = self::Plan($uid);
        if ($plan && (int) $plan['pl_seller_fk'] === (int) $seller) {
            $zdbh->prepare("UPDATE x_pp_plans SET pl_period_vc = :p, pl_paiduntil_in = :d, pl_realuntil_in = :d2, pl_active_in = 1 WHERE pl_acc_fk = :u")
                ->execute(array(':p' => $period, ':d' => (int) $paidUntil, ':d2' => (int) $paidUntil, ':u' => (int) $uid));
            return;
        }
        $zdbh->prepare("REPLACE INTO x_pp_plans (pl_acc_fk, pl_seller_fk, pl_period_vc, pl_paiduntil_in, pl_realuntil_in, pl_active_in, pl_suspended_in, pl_created_in) VALUES (:u, :s, :p, :d, :d2, 1, 0, :t)")
            ->execute(array(':u' => (int) $uid, ':s' => (int) $seller, ':p' => $period, ':d' => (int) $paidUntil, ':d2' => (int) $paidUntil, ':t' => time()));
    }

    /**
     * A seller's clients that it does not bill (yet): no plan with this seller (a plan it stopped
     * counts as its choice), not an administrator, on a package that has a price.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @param int $since Only clients made at or after this time (0: all).
     * @return array<int, array{0:int, 1:int}> Account ID => array(package, made).
     */
    static function Unplanned($seller, $since = 0)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT a.ac_id_pk, a.ac_package_fk, a.ac_created_ts FROM x_accounts a LEFT JOIN x_pp_plans p ON p.pl_acc_fk = a.ac_id_pk
            WHERE a.ac_reseller_fk = :s AND a.ac_id_pk <> :s2 AND a.ac_deleted_ts IS NULL AND a.ac_group_fk <> 1
            AND (p.pl_acc_fk IS NULL OR p.pl_seller_fk <> :s3)" . ((int) $since > 0 ? " AND a.ac_created_ts >= " . (int) $since : '') . " ORDER BY a.ac_id_pk");
        $q->execute(array(':s' => (int) $seller, ':s2' => (int) $seller, ':s3' => (int) $seller));
        $prices = self::Prices($seller);
        $out = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = isset($prices[(int) $r['ac_package_fk']]) ? $prices[(int) $r['ac_package_fk']] : array(0, 0);
            if ($p[0] > 0 || $p[1] > 0) {
                $out[(int) $r['ac_id_pk']] = array((int) $r['ac_package_fk'], (int) $r['ac_created_ts']);
            }
        }
        return $out;
    }

    /**
     * Automatic plans (each daemon run, for sellers that are live: in test mode real clients would get
     * TEST invoices): a client a seller made since it turned automatic plans on or went live,
     * on one of its priced packages, is billed from the day it was made (its first invoice comes at
     * once), for the seller's usual period.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $only One seller only (0: all).
     * @return string[] What happened.
     */
    static function AutoPlans($only = 0)
    {
        global $zdbh;
        self::Ensure();
        $log = array();
        $q = $zdbh->query("SELECT s.ps_acc_fk, s.ps_autofrom_in, s.ps_period_vc FROM x_pp_sellers s JOIN x_accounts a ON a.ac_id_pk = s.ps_acc_fk AND a.ac_deleted_ts IS NULL
            WHERE s.ps_on_in = 1 AND s.ps_auto_in = 1 AND s.ps_autofrom_in > 0 AND s.ps_mode_vc = 'live'" . ((int) $only > 0 ? " AND s.ps_acc_fk = " . (int) $only : ''));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $seller = (int) $s['ps_acc_fk'];
            $prices = self::Prices($seller);
            foreach (self::Unplanned($seller, (int) $s['ps_autofrom_in']) as $uid => $c) {
                $period = self::PickPeriod($prices, $c[0], (string) $s['ps_period_vc']);
                if ($period === '') {
                    continue;
                }
                self::StartPlan($seller, $uid, $period, min(time(), max((int) $s['ps_autofrom_in'], $c[1])));
                $log[] = 'Payment Plans: account ' . $uid . ' billed ' . ($period === 'year' ? 'yearly' : 'monthly') . ' by account ' . $seller . ' (automatic plan)';
            }
        }
        return $log;
    }

    /**
     * The end of a period that starts at $from.
     *
     * @param int $from
     * @param string $period month or year
     * @return int
     */
    static function PeriodEnd($from, $period)
    {
        $d = new DateTime('@' . (int) $from);
        $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $day = (int) $d->format('j');
        $d->modify('first day of this month');
        $d->modify($period === 'year' ? '+1 year' : '+1 month');
        // the same day of the month (the 31st becomes the month's last day)
        $d->setDate((int) $d->format('Y'), (int) $d->format('n'), min($day, (int) $d->format('t')));
        return $d->getTimestamp();
    }

    // ----- invoices -----

    /**
     * An invoice.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return array|null
     */
    static function Invoice($id)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_pp_invoices WHERE in_id_pk = :i");
        $q->execute(array(':i' => (int) $id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    /**
     * Invoices of a client (as the buyer) or of a seller, newest first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param string $as acc or seller
     * @param int $id
     * @param int $limit
     * @return array<int, array>
     */
    static function Invoices($as, $id, $limit = 200)
    {
        global $zdbh;
        self::Ensure();
        $col = $as === 'seller' ? 'in_seller_fk' : 'in_acc_fk';
        $q = $zdbh->prepare("SELECT * FROM x_pp_invoices WHERE " . $col . " = :i ORDER BY in_id_pk DESC LIMIT " . (int) $limit);
        $q->execute(array(':i' => (int) $id));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Make the invoice of a client's next period (from its plan: the period after what is paid),
     * with the seller's current price and tax. Its number is the seller's next.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @return array|string The invoice, or why not.
     */
    static function MakeInvoice($uid)
    {
        global $zdbh;
        self::Ensure();
        $plan = self::Plan($uid);
        if (!$plan || !(int) $plan['pl_active_in']) {
            return 'There is no plan.';
        }
        $seller = (int) $plan['pl_seller_fk'];
        if (self::SellerOf($uid) !== $seller) {
            return 'The client belongs to another account now.';
        }
        $clients = self::Clients($seller);
        if (!isset($clients[(int) $uid])) {
            return 'Not a client of this account.';
        }
        $c = $clients[(int) $uid];
        $prices = self::Prices($seller);
        $period = (string) $plan['pl_period_vc'];
        $net = isset($prices[$c['package']]) ? (int) $prices[$c['package']][$period === 'year' ? 1 : 0] : 0;
        if ($net <= 0) {
            return 'The package ' . $c['packagename'] . ' has no ' . ($period === 'year' ? 'yearly' : 'monthly') . ' price.';
        }
        $s = self::Seller($seller);
        $from = (int) $plan['pl_paiduntil_in'];
        $to = self::PeriodEnd($from, $period);
        $tax = (int) $s['ps_taxrate_in'] > 0 ? self::Tax($net, (int) $s['ps_taxrate_in']) : 0;
        $sellerText = trim((string) $s['ps_name_vc'] . "\n" . (string) $s['ps_address_tx'] . ((string) $s['ps_email_vc'] !== '' ? "\n" . $s['ps_email_vc'] : ''));
        $buyerText = trim(($c['fullname'] !== '' ? $c['fullname'] . "\n" : '') . $c['username'] . ($c['email'] !== '' ? "\n" . $c['email'] : ''));
        // the number: the seller's next, taken in the same transaction
        $zdbh->beginTransaction();
        try {
            // (test mode: TEST- numbers of their own, so the real ones stay without gaps)
            $test = self::Testing($s);
            $n = $zdbh->prepare("SELECT ps_next_in, ps_testnext_in, ps_prefix_vc FROM x_pp_sellers WHERE ps_acc_fk = :s FOR UPDATE");
            $n->execute(array(':s' => $seller));
            $row = $n->fetch(PDO::FETCH_ASSOC);
            $n->closeCursor();
            // (checked under the seller's lock: the daemon and "Invoice now" at the same moment make one invoice)
            // (live: a TEST invoice left from testing does not hold up the real ones)
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_pp_invoices WHERE in_acc_fk = :u AND in_status_vc IN ('open', 'pending')" . ($test ? '' : ' AND in_test_in = 0'));
            $q->execute(array(':u' => (int) $uid));
            if ((int) $q->fetchColumn() > 0) {
                $zdbh->rollBack();
                return 'An invoice is open already.';
            }
            $next = $row ? (int) $row[$test ? 'ps_testnext_in' : 'ps_next_in'] : 1;
            $number = ($test ? 'TEST-' : ($row ? (string) $row['ps_prefix_vc'] : 'INV-')) . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $zdbh->prepare("UPDATE x_pp_sellers SET " . ($test ? 'ps_testnext_in' : 'ps_next_in') . " = :n WHERE ps_acc_fk = :s")->execute(array(':n' => $next + 1, ':s' => $seller));
            $zdbh->prepare("INSERT INTO x_pp_invoices (in_seller_fk, in_acc_fk, in_number_vc, in_package_vc, in_period_vc, in_from_in, in_to_in, in_net_in,
                in_taxlabel_vc, in_taxrate_in, in_tax_in, in_total_in, in_currency_vc, in_seller_tx, in_buyer_tx, in_status_vc, in_due_in, in_created_in, in_test_in)
                VALUES (:s, :u, :no, :pk, :pe, :f, :t, :net, :tl, :tr, :tax, :tot, :cur, :st, :bt, 'open', :due, :c, :test)")
                ->execute(array(':s' => $seller, ':u' => (int) $uid, ':no' => $number, ':pk' => $c['packagename'], ':pe' => $period, ':f' => $from, ':t' => $to,
                    ':net' => $net, ':tl' => (string) $s['ps_taxlabel_vc'], ':tr' => (int) $s['ps_taxrate_in'], ':tax' => $tax, ':tot' => $net + $tax,
                    ':cur' => (string) $s['ps_currency_vc'], ':st' => $sellerText, ':bt' => $buyerText, ':due' => max($from, time()), ':c' => time(), ':test' => $test ? 1 : 0));
            $id = (int) $zdbh->lastInsertId();
            $zdbh->commit();
        } catch (Exception $e) {
            $zdbh->rollBack();
            return 'The invoice could not be made.';
        }
        // an invoice due at once (a new client's first one): its email says it all, no reminder a minute later
        if ((int) $from <= time()) {
            $zdbh->prepare("UPDATE x_pp_invoices SET in_reminded_in = 1 WHERE in_id_pk = :i")->execute(array(':i' => $id));
        }
        $inv = self::Invoice($id);
        self::Log($inv, 'made', '', '', (int) $inv['in_total_in']);
        self::Tell($inv, 'new');
        return $inv;
    }

    /**
     * An invoice is paid (PayPal confirmed the capture, or the seller marked it): the plan is paid
     * a period further, and a client the panel switched off for it is switched on again. Only an
     * open or pending invoice: a second confirmation of the same payment changes nothing.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @param string $method PayPal, or what the seller wrote (bank transfer...).
     * @param string $capture PayPal's capture ID ('' when marked by the seller).
     * @param string $payer The payer's email, as the processor gives it.
     * @param int $by Who marked it (0: the panel, from the processor's answer).
     * @return bool Whether this call marked it paid.
     */
    static function MarkPaid($id, $method, $capture = '', $payer = '', $by = 0)
    {
        global $zdbh;
        self::Ensure();
        try {
            $q = $zdbh->prepare("UPDATE x_pp_invoices SET in_status_vc = 'paid', in_paid_in = :t, in_method_vc = :m, in_capture_vc = :c, in_payer_vc = :p
                WHERE in_id_pk = :i AND in_status_vc IN ('open', 'pending')");
            $q->execute(array(':t' => time(), ':m' => mb_substr((string) $method, 0, 60), ':c' => $capture !== '' ? $capture : null, ':p' => mb_substr((string) $payer, 0, 200), ':i' => (int) $id));
        } catch (PDOException $e) {
            return false; // that capture is recorded on another invoice already
        }
        if ($q->rowCount() !== 1) {
            return false;
        }
        $inv = self::Invoice($id);
        self::Log($inv, 'paid', mb_substr((string) $method . ((string) $payer !== '' ? ', ' . $payer : ''), 0, 255), (string) $capture, (int) $inv['in_total_in'], $by);
        $plan = self::Plan((int) $inv['in_acc_fk']);
        if ($plan && (int) $plan['pl_seller_fk'] === (int) $inv['in_seller_fk']) {
            // paid up to the end of the invoice's period (never back); a TEST invoice's payment (no real
            // money) does not count as really paid: going live puts the plan back (GoLive)
            $zdbh->prepare("UPDATE x_pp_plans SET pl_paiduntil_in = GREATEST(pl_paiduntil_in, :to)"
                . ((int) $inv['in_test_in'] ? '' : ", pl_realuntil_in = GREATEST(pl_realuntil_in, :to2)") . " WHERE pl_acc_fk = :u")
                ->execute(array(':to' => (int) $inv['in_to_in'], ':u' => (int) $inv['in_acc_fk']) + ((int) $inv['in_test_in'] ? array() : array(':to2' => (int) $inv['in_to_in'])));
            if ((int) $plan['pl_suspended_in']) {
                self::SwitchOn((int) $inv['in_acc_fk']);
            }
        }
        self::Tell($inv, 'paid');
        return true;
    }

    /**
     * Switch a client off for an unpaid invoice (never an administrator), noting the reason.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $inv
     * @return bool
     */
    static function SwitchOff(array $inv)
    {
        global $zdbh;
        $uid = (int) $inv['in_acc_fk'];
        $q = $zdbh->prepare("SELECT ac_group_fk, ac_enabled_in FROM x_accounts WHERE ac_id_pk = :u AND ac_deleted_ts IS NULL");
        $q->execute(array(':u' => $uid));
        $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!$a || (int) $a['ac_group_fk'] === 1) {
            return false;
        }
        $zdbh->prepare("UPDATE x_pp_plans SET pl_suspended_in = 1 WHERE pl_acc_fk = :u")->execute(array(':u' => $uid));
        if ((int) $a['ac_enabled_in'] === 1) {
            $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in = 0 WHERE ac_id_pk = :u")->execute(array(':u' => $uid));
            ws_suspend::Set($uid, self::REASON . $inv['in_number_vc'], (int) $inv['in_seller_fk']);
            self::Log($inv, 'off');
            self::Hooks('Disable');
        }
        return true;
    }

    /**
     * Switch a client on again: only when the panel switched it off for not paying (another reason,
     * set by its seller or an administrator, stays).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $uid
     * @return void
     */
    static function SwitchOn($uid)
    {
        global $zdbh;
        $zdbh->prepare("UPDATE x_pp_plans SET pl_suspended_in = 0 WHERE pl_acc_fk = :u")->execute(array(':u' => (int) $uid));
        $reason = (string) ws_suspend::Reason((int) $uid);
        $q = $zdbh->prepare("SELECT ac_enabled_in FROM x_accounts WHERE ac_id_pk = :u AND ac_deleted_ts IS NULL");
        $q->execute(array(':u' => (int) $uid));
        $on = $q->fetchColumn();
        if ($on !== false && (int) $on === 0 && strpos($reason, self::REASON) === 0) {
            $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in = 1 WHERE ac_id_pk = :u")->execute(array(':u' => (int) $uid));
            ws_suspend::Clear((int) $uid);
            $plan = self::Plan((int) $uid);
            self::Log(array('in_seller_fk' => $plan ? (int) $plan['pl_seller_fk'] : 0, 'in_acc_fk' => (int) $uid, 'in_id_pk' => 0), 'on', substr($reason, strlen(self::REASON)));
            self::Hooks('Enable');
        }
    }

    /**
     * The panel's OnAfterDisableClient / OnAfterEnableClient hooks (the websites' "switched off"
     * page, FTP), as Manage Clients runs them; in a PHP of their own, as some hook files can only be
     * read once per run.
     *
     * @param string $what Disable or Enable.
     * @return void
     */
    static function Hooks($what)
    {
        // on a page (one change per request), as Manage Clients does
        if (PHP_SAPI !== 'cli' && in_array($what, array('Disable', 'Enable'), true)) {
            runtime_hook::Execute('OnAfter' . $what . 'Client');
            return;
        }
        $root = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/');
        if (!is_file($root . '/dryden/loader.inc.php') || !in_array($what, array('Disable', 'Enable'), true)) {
            return;
        }
        $code = 'chdir(' . var_export($root, true) . '); require "dryden/loader.inc.php"; require "cnf/db.php"; require "inc/dbc.inc.php";'
            . ' runtime_hook::Execute("OnAfter' . $what . 'Client");';
        $php = PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        exec(escapeshellarg(is_executable($php) ? $php : PHP_BINDIR . '/php') . ' -r ' . escapeshellarg($code) . ' > /dev/null 2>&1');
    }

    /**
     * Tell the client about an invoice: a notice in the panel and an email (reply to the seller).
     *
     * @param array $inv
     * @param string $what new, due, suspended or paid
     * @return void
     */
    static function Tell(array $inv, $what)
    {
        $u = ctrl_users::GetUserDetail((int) $inv['in_acc_fk']);
        $s = self::Seller((int) $inv['in_seller_fk']);
        $total = self::Show((int) $inv['in_total_in'], (string) $inv['in_currency_vc']);
        $due = date('j M Y', (int) $inv['in_due_in']);
        $texts = array(
            'new' => array('info', 'Invoice ' . $inv['in_number_vc'] . ': ' . $total, 'Your invoice ' . $inv['in_number_vc'] . ' for ' . $total . ' is due on ' . $due . '. Pay it in Billing (Account Information > Payment Plans).'
                . ((int) $inv['in_reminded_in'] && (int) $s['ps_suspend_in'] ? ' Pay it within ' . (int) $s['ps_grace_in'] . ' days, or your hosting is switched off.' : '')),
            'due' => array('warning', 'Invoice ' . $inv['in_number_vc'] . ' is due', 'Your invoice ' . $inv['in_number_vc'] . ' for ' . $total . ' was due on ' . $due . '. Pay it in Billing within ' . (int) $s['ps_grace_in'] . ' days, or your hosting is switched off.'),
            'suspended' => array('outage', 'Your hosting is switched off', 'Invoice ' . $inv['in_number_vc'] . ' for ' . $total . ' is not paid, so your websites, mail and FTP are switched off. Paying it (the pay link in your email, no login needed) switches them on again within minutes.'),
            'paid' => array('info', 'Invoice ' . $inv['in_number_vc'] . ' is paid', 'Thank you: invoice ' . $inv['in_number_vc'] . ' for ' . $total . ' is paid.'),
        );
        if (!isset($texts[$what])) {
            return;
        }
        list($type, $title, $body) = $texts[$what];
        if ((int) ($inv['in_test_in'] ?? 0)) {
            $title = 'TEST: ' . $title;
            $body .= ' (A test invoice: no real money.)';
        }
        try {
            if ($what === 'paid') {
                ctrl_notices::Withdraw('pp-invoice-' . (int) $inv['in_id_pk'], 0);
            } else {
                ctrl_notices::Send(array('scope' => 'user', 'targets' => array((int) $inv['in_acc_fk']), 'type' => $type, 'from' => 0, 'source' => 'payment_plans',
                    'key' => 'pp-invoice-' . (int) $inv['in_id_pk'], 'closable' => $what === 'suspended' ? false : 'login', 'title' => $title, 'body' => $body));
            }
        } catch (Exception $e) {
        }
        if (!empty($u['email']) && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
            try {
                $m = new sys_email();
                $m->Subject = ((string) $s['ps_name_vc'] !== '' ? $s['ps_name_vc'] . ': ' : '') . $title;
                $link = $what !== 'paid' ? self::PayLink($inv) : '';
                $m->Body = "Hello " . $u['username'] . ",\n\n" . $body . "\n\n" . ($link !== '' ? "Pay it here (no login needed):\n" . $link . "\n\n" : '')
                    . self::PanelUrl((int) $inv['in_acc_fk']) . "\n\n" . (string) $s['ps_name_vc'] . "\n";
                $m->AddAddress($u['email']);
                if (filter_var((string) $s['ps_email_vc'], FILTER_VALIDATE_EMAIL)) {
                    $m->AddReplyTo((string) $s['ps_email_vc'], (string) $s['ps_name_vc']);
                }
                $m->SendEmail();
            } catch (Exception $e) {
            }
        }
    }

    /**
     * Delete a seller's test invoices (test mode: start again, or before going live). A client
     * switched off for one is switched on again, and a plan a test payment moved goes back to the
     * date it is really paid until.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @return int How many.
     */
    static function DeleteTests($seller)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_pp_invoices WHERE in_seller_fk = :s AND in_test_in = 1");
        $q->execute(array(':s' => (int) $seller));
        $n = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            try {
                ctrl_notices::Withdraw('pp-invoice-' . (int) $inv['in_id_pk'], 0);
            } catch (Exception $e) {
            }
            if ((string) ws_suspend::Reason((int) $inv['in_acc_fk']) === self::REASON . $inv['in_number_vc']) {
                self::SwitchOn((int) $inv['in_acc_fk']);
            }
            $zdbh->prepare("DELETE FROM x_pp_invoices WHERE in_id_pk = :i AND in_test_in = 1")->execute(array(':i' => (int) $inv['in_id_pk']));
            $zdbh->prepare("DELETE FROM x_pp_log WHERE lg_inv_fk = :i")->execute(array(':i' => (int) $inv['in_id_pk']));
            $n++;
        }
        $zdbh->prepare("UPDATE x_pp_sellers SET ps_testnext_in = 1 WHERE ps_acc_fk = :s")->execute(array(':s' => (int) $seller));
        self::RealDates($seller);
        return $n;
    }

    /**
     * Turn Payment Plans on or off for a seller's clients. Off: no invoices, reminders, automatic
     * plans or switching off (open invoices can still be paid), and the clients the panel switched
     * off for an unpaid invoice are switched on again. On: billing runs, and automatic plans count
     * new clients from now.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @param bool $on
     * @return int Clients switched on again (turning off).
     */
    static function SetOn($seller, $on)
    {
        global $zdbh;
        self::Ensure();
        $zdbh->prepare("UPDATE x_pp_sellers SET ps_on_in = :o" . ($on ? ', ps_autofrom_in = :t' : '') . " WHERE ps_acc_fk = :s")
            ->execute(array(':o' => $on ? 1 : 0, ':s' => (int) $seller) + ($on ? array(':t' => time()) : array()));
        if ($on) {
            return 0;
        }
        $n = 0;
        $q = $zdbh->prepare("SELECT pl_acc_fk FROM x_pp_plans WHERE pl_seller_fk = :s AND pl_suspended_in = 1");
        $q->execute(array(':s' => (int) $seller));
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            if (strpos((string) ws_suspend::Reason((int) $uid), self::REASON) === 0) {
                self::SwitchOn((int) $uid);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Plans moved by test payments (no real money) go back to the date they are really paid until.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @return int How many.
     */
    static function RealDates($seller)
    {
        global $zdbh;
        $q = $zdbh->prepare("UPDATE x_pp_plans SET pl_paiduntil_in = pl_realuntil_in WHERE pl_seller_fk = :s AND pl_paiduntil_in > pl_realuntil_in");
        $q->execute(array(':s' => (int) $seller));
        return $q->rowCount();
    }

    /**
     * A seller leaves test mode: what testing did to its clients is undone. Plans moved by test
     * payments go back to the date they are really paid until, and clients switched off for a TEST
     * invoice are switched on again (a test invoice never switches anyone off once live).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @return array{0:int, 1:int} Plans put back, clients switched on.
     */
    static function GoLive($seller)
    {
        global $zdbh;
        self::Ensure();
        $back = self::RealDates($seller);
        $on = 0;
        $q = $zdbh->prepare("SELECT pl_acc_fk FROM x_pp_plans WHERE pl_seller_fk = :s AND pl_suspended_in = 1");
        $q->execute(array(':s' => (int) $seller));
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            if (strpos((string) ws_suspend::Reason((int) $uid), self::REASON . 'TEST-') === 0) {
                self::SwitchOn((int) $uid);
                $on++;
            }
        }
        return array($back, $on);
    }

    // ----- history and figures -----

    /**
     * Note a step of an invoice in its history (x_pp_log, kept for the records). The same step (the
     * same payment, the same words) is noted once, however often it is looked at.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $inv The invoice (or in_seller_fk, in_acc_fk and in_id_pk 0, for an account).
     * @param string $what made, started, pending, paid, failed, refused, void, off, on
     * @param string $note
     * @param string $ref The processor's payment reference, or the payment's ID.
     * @param int|null $amount
     * @param int $by Who did it (0: the panel).
     * @return void
     */
    static function Log(array $inv, $what, $note = '', $ref = '', $amount = null, $by = 0)
    {
        global $zdbh;
        try {
            $id = (int) ($inv['in_id_pk'] ?? 0);
            $note = mb_substr((string) $note, 0, 255);
            if ($id > 0) {
                $q = $zdbh->prepare("SELECT COUNT(*) FROM x_pp_log WHERE lg_inv_fk = :i AND lg_what_vc = :w AND lg_ref_vc = :r AND lg_note_vc = :n");
                $q->execute(array(':i' => $id, ':w' => (string) $what, ':r' => mb_substr((string) $ref, 0, 255), ':n' => $note));
                $seen = (int) $q->fetchColumn();
                $q->closeCursor();
                if ($seen > 0) {
                    return;
                }
            }
            $zdbh->prepare("INSERT INTO x_pp_log (lg_time_in, lg_seller_fk, lg_acc_fk, lg_inv_fk, lg_what_vc, lg_amount_in, lg_currency_vc, lg_proc_vc, lg_ref_vc, lg_note_vc, lg_by_fk)
                VALUES (:t, :s, :a, :i, :w, :am, :c, :p, :r, :n, :b)")->execute(array(':t' => time(), ':s' => (int) ($inv['in_seller_fk'] ?? 0), ':a' => (int) ($inv['in_acc_fk'] ?? 0),
                ':i' => $id, ':w' => (string) $what, ':am' => $amount === null ? null : (int) $amount, ':c' => (string) ($inv['in_currency_vc'] ?? ''),
                ':p' => (string) ($inv['in_proc_vc'] ?? ''), ':r' => mb_substr((string) $ref, 0, 255), ':n' => $note, ':b' => (int) $by));
        } catch (Exception $e) {
            // (the history never stops a payment)
        }
    }

    /**
     * An invoice's history, oldest first.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $id
     * @return array<int, array>
     */
    static function History($id)
    {
        global $zdbh;
        self::Ensure();
        $q = $zdbh->prepare("SELECT * FROM x_pp_log WHERE lg_inv_fk = :i ORDER BY lg_id_pk");
        $q->execute(array(':i' => (int) $id));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A seller's figures, from its real invoices (TEST ones never count), per currency: received
     * this month, last month and this year (with the tax in it), waiting to be paid (and how much of
     * it is overdue); and what its active plans bring in a month at today's prices (a yearly plan
     * as a twelfth), with tax, in the seller's currency.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @param int|null $now
     * @return array{paid: array<string, array>, open: array<string, array>, recurring: int, plans: int, currency: string}
     */
    static function Stats($seller, $now = null)
    {
        global $zdbh;
        self::Ensure();
        $now = $now === null ? time() : (int) $now;
        $month = mktime(0, 0, 0, (int) date('n', $now), 1, (int) date('Y', $now));
        $last = mktime(0, 0, 0, (int) date('n', $now) - 1, 1, (int) date('Y', $now));
        $year = mktime(0, 0, 0, 1, 1, (int) date('Y', $now));
        $q = $zdbh->prepare("SELECT in_currency_vc cur,
            SUM(CASE WHEN in_paid_in >= :m THEN in_total_in - COALESCE(in_refunded_in, 0) ELSE 0 END) month, SUM(CASE WHEN in_paid_in >= :l AND in_paid_in < :m2 THEN in_total_in - COALESCE(in_refunded_in, 0) ELSE 0 END) last,
            SUM(CASE WHEN in_paid_in >= :y THEN in_total_in - COALESCE(in_refunded_in, 0) ELSE 0 END) year, SUM(CASE WHEN in_paid_in >= :y2 THEN in_tax_in ELSE 0 END) yeartax,
            SUM(CASE WHEN in_paid_in >= :m3 THEN 1 ELSE 0 END) monthcount
            FROM x_pp_invoices WHERE in_seller_fk = :s AND in_test_in = 0 AND in_status_vc = 'paid' AND in_paid_in >= :from GROUP BY in_currency_vc");
        $q->execute(array(':m' => $month, ':l' => $last, ':m2' => $month, ':y' => $year, ':y2' => $year, ':m3' => $month, ':s' => (int) $seller, ':from' => min($last, $year)));
        $paid = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $paid[(string) $r['cur']] = array('month' => (int) $r['month'], 'last' => (int) $r['last'], 'year' => (int) $r['year'], 'yeartax' => (int) $r['yeartax'], 'monthcount' => (int) $r['monthcount']);
        }
        $q = $zdbh->prepare("SELECT in_currency_vc cur, SUM(in_total_in) total, SUM(CASE WHEN in_due_in < :n THEN in_total_in ELSE 0 END) overdue, COUNT(*) n
            FROM x_pp_invoices WHERE in_seller_fk = :s AND in_test_in = 0 AND in_status_vc IN ('open', 'pending') GROUP BY in_currency_vc");
        $q->execute(array(':n' => $now - 86400, ':s' => (int) $seller));
        $open = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $open[(string) $r['cur']] = array('total' => (int) $r['total'], 'overdue' => (int) $r['overdue'], 'count' => (int) $r['n']);
        }
        // what the active plans bring in a month, at today's prices
        $s = self::Seller($seller);
        $prices = self::Prices($seller);
        $clients = self::Clients($seller);
        $q = $zdbh->prepare("SELECT pl_acc_fk, pl_period_vc FROM x_pp_plans WHERE pl_seller_fk = :s AND pl_active_in = 1");
        $q->execute(array(':s' => (int) $seller));
        $recurring = 0;
        $plans = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $c = isset($clients[(int) $p['pl_acc_fk']]) ? $clients[(int) $p['pl_acc_fk']] : null;
            $price = $c && isset($prices[$c['package']]) ? $prices[$c['package']][$p['pl_period_vc'] === 'year' ? 1 : 0] : 0;
            if ($price <= 0) {
                continue;
            }
            $price += (int) $s['ps_taxrate_in'] > 0 ? self::Tax($price, (int) $s['ps_taxrate_in']) : 0;
            $recurring += $p['pl_period_vc'] === 'year' ? intdiv($price + 6, 12) : $price;
            $plans++;
        }
        return array('paid' => $paid, 'open' => $open, 'recurring' => $recurring, 'plans' => $plans, 'currency' => (string) $s['ps_currency_vc']);
    }

    /**
     * The key of an invoice's pay link: from the server's own secret (cnf/security.php), the invoice
     * and its number, so it can be neither guessed nor moved to another invoice.
     *
     * @global array $security The server's secrets.
     * @param array $inv
     * @return string '' when this server has no secret.
     */
    static function PayKey(array $inv)
    {
        global $security;
        if (!isset($security['server_crypto_key'])) {
            $f = rtrim((string) ctrl_options::GetSystemOption('sentora_root'), '/') . '/cnf/security.php';
            if (is_file($f)) {
                require_once $f;
            }
        }
        $secret = isset($security['server_crypto_key']) ? (string) $security['server_crypto_key'] : '';
        if (strlen($secret) < 16 || $secret === 'GENERATED_SALT_HERE') {
            return '';
        }
        return substr(hash_hmac('sha256', 'payment plans pay link|' . (int) $inv['in_id_pk'] . '|' . $inv['in_number_vc'] . '|' . (int) $inv['in_acc_fk'], $secret), 0, 40);
    }

    /**
     * An invoice's pay link: its own page, opened without logging in (a client switched off for not
     * paying can not log in), where it can be paid and nothing else done.
     *
     * @param array $inv
     * @return string '' when there is none.
     */
    static function PayLink(array $inv)
    {
        $k = self::PayKey($inv);
        return $k === '' ? '' : rtrim(self::PanelUrl((int) $inv['in_acc_fk']), '/') . '/modules/payment_plans/code/pay.php?i=' . (int) $inv['in_id_pk'] . '&k=' . $k;
    }

    /**
     * The address of the panel a client uses (its reseller's own, else the server's).
     *
     * @param int $uid
     * @return string
     */
    static function PanelUrl($uid)
    {
        $u = class_exists('ctrl_branding') ? ctrl_branding::PanelUrlFor((int) $uid) : '';
        return $u !== '' ? $u : 'https://' . ctrl_options::GetSystemOption('sentora_domain');
    }
}
