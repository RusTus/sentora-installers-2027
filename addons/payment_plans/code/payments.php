<?php

/**
 * Payment Plans' payment processors. Each seller picks the one its clients pay with (PayPal,
 * Stripe...) and keeps its own keys for it, so the money goes straight into the seller's own
 * account; a processor only makes the payment page and reports what happened. Whether a payment
 * counts is decided here, the same way for every processor: only when the processor itself answers
 * that it is paid, for exactly the invoice's amount and currency, on the payment the panel made
 * for that invoice. A browser coming back saying "paid" proves nothing.
 *
 * A processor is a class in code/processors/ implementing pp_processor, listed in Processors().
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
require_once dirname(__FILE__) . '/processors/processor.php';
require_once dirname(__FILE__) . '/processors/paypal.php';
require_once dirname(__FILE__) . '/processors/stripe.php';

class pp_pay
{
    /** What a processor's payment reference looks like (PayPal order IDs, Stripe session IDs...). */
    const REF = '/^[A-Za-z0-9_-]{5,255}$/';

    /** @var string What Create found about the invoice's last payment instead of making one: paid, pending, or ''. */
    static $found = '';

    /**
     * The processors: ID => class.
     *
     * @return array<string, string>
     */
    static function Processors()
    {
        return array('paypal' => 'pp_proc_paypal', 'stripe' => 'pp_proc_stripe');
    }

    /**
     * A processor's class, or '' when there is no such processor.
     *
     * @param string $id
     * @return string
     */
    static function Get($id)
    {
        $p = self::Processors();
        return isset($p[(string) $id]) ? $p[(string) $id] : '';
    }

    /**
     * A processor's name to show (PayPal, Stripe), or '' when there is none.
     *
     * @param string $id
     * @return string
     */
    static function Name($id)
    {
        $c = self::Get($id);
        return $c !== '' ? $c::Name() : '';
    }

    /**
     * Every address a payment page may lead to (the pay link's Content-Security-Policy).
     *
     * @return string[]
     */
    static function Hosts()
    {
        $out = array();
        foreach (self::Processors() as $c) {
            foreach ($c::Hosts() as $h) {
                $out[$h] = true;
            }
        }
        return array_keys($out);
    }

    // ----- the sellers' keys -----

    /**
     * A seller's stored keys for a processor and mode, as stored (secret fields encrypted).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @param string $proc
     * @param string $mode live or sandbox (test mode)
     * @return array<string, string>
     */
    static function Stored($seller, $proc, $mode)
    {
        global $zdbh;
        pp::Ensure();
        $q = $zdbh->prepare("SELECT pk_data_tx FROM x_pp_keys WHERE pk_seller_fk = :s AND pk_proc_vc = :p AND pk_mode_vc = :m");
        $q->execute(array(':s' => (int) $seller, ':p' => (string) $proc, ':m' => $mode === 'live' ? 'live' : 'sandbox'));
        $j = json_decode((string) $q->fetchColumn(), true);
        return is_array($j) ? array_map('strval', $j) : array();
    }

    /**
     * A seller's keys for a processor and mode, ready to use (secrets decrypted), or null when a
     * field is missing or a secret can not be read.
     *
     * @param int $seller
     * @param string $proc
     * @param string $mode
     * @return array<string, string>|null
     */
    static function Keys($seller, $proc, $mode)
    {
        $c = self::Get($proc);
        if ($c === '') {
            return null;
        }
        $stored = self::Stored($seller, $proc, $mode);
        $out = array();
        foreach ($c::Fields() as $f => $def) {
            $v = isset($stored[$f]) ? $stored[$f] : '';
            if (!empty($def['secret'])) {
                $v = $v === '' ? false : ctrl_secret::Decrypt($v);
            }
            if (!is_string($v) || $v === '') {
                return null;
            }
            $out[$f] = $v;
        }
        return $out;
    }

    /**
     * Has the seller keys for the processor and mode (the seller's own when not given)?
     *
     * @param array $s pp::Seller()
     * @param string|null $proc
     * @param string|null $mode
     * @return bool
     */
    static function Ready(array $s, $proc = null, $mode = null)
    {
        $proc = $proc === null ? (string) $s['ps_proc_vc'] : $proc;
        $mode = $mode === null ? (pp::Testing($s) ? 'sandbox' : 'live') : $mode;
        return self::Keys((int) $s['ps_acc_fk'], $proc, $mode) !== null;
    }

    /**
     * Save a seller's keys for a processor and mode: public fields as given ('' takes one away), a
     * secret only when one is given (null keeps it), kept encrypted. Checked by the caller.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param int $seller
     * @param string $proc
     * @param string $mode
     * @param array<string, string|null> $values
     * @param bool $drop Take every key of this processor and mode away.
     * @return bool False when a secret could not be encrypted (no server key).
     */
    static function SaveKeys($seller, $proc, $mode, array $values, $drop = false)
    {
        global $zdbh;
        $c = self::Get($proc);
        if ($c === '') {
            return false;
        }
        $mode = $mode === 'live' ? 'live' : 'sandbox';
        $old = self::Stored($seller, $proc, $mode);
        $new = array();
        foreach ($c::Fields() as $f => $def) {
            $v = array_key_exists($f, $values) ? $values[$f] : null;
            if ($drop) {
                $v = '';
            } elseif (!empty($def['secret']) && $v !== null && $v !== '') {
                $v = ctrl_secret::Encrypt($v);
                if ($v === '') {
                    return false;
                }
            }
            $v = $v === null ? (isset($old[$f]) ? $old[$f] : '') : (string) $v;
            if ($v !== '') {
                $new[$f] = $v;
            }
        }
        if (!$new) {
            $zdbh->prepare("DELETE FROM x_pp_keys WHERE pk_seller_fk = :s AND pk_proc_vc = :p AND pk_mode_vc = :m")
                ->execute(array(':s' => (int) $seller, ':p' => $proc, ':m' => $mode));
            return true;
        }
        $zdbh->prepare("REPLACE INTO x_pp_keys (pk_seller_fk, pk_proc_vc, pk_mode_vc, pk_data_tx, pk_updated_in) VALUES (:s, :p, :m, :d, :t)")
            ->execute(array(':s' => (int) $seller, ':p' => $proc, ':m' => $mode, ':d' => json_encode($new), ':t' => time()));
        return true;
    }

    /**
     * Check the seller's keys of the processor and mode in use with the processor itself.
     *
     * @param array $s
     * @return string '' when the processor accepts them, else why not.
     */
    static function Check(array $s)
    {
        $proc = (string) $s['ps_proc_vc'];
        $mode = pp::Testing($s) ? 'sandbox' : 'live';
        $c = self::Get($proc);
        $k = $c !== '' ? self::Keys((int) $s['ps_acc_fk'], $proc, $mode) : null;
        if ($k === null) {
            return $c === '' ? 'Choose how your clients pay first.' : self::Missing($proc, $mode);
        }
        return $c::Check($k, $mode);
    }

    /**
     * Why there is no payment: the keys are not there.
     *
     * @param string $proc
     * @param string $mode
     * @return string
     */
    static function Missing($proc, $mode)
    {
        return self::Name($proc) . ' (' . ($mode === 'live' ? 'live' : 'test mode') . ') is not set up for this seller.';
    }

    // ----- paying -----

    /**
     * Make the payment of an invoice with the seller's processor (its amount and currency from the
     * invoice, never from the browser; a TEST invoice in the processor's test mode, a real one live)
     * and note it on the invoice.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $inv
     * @param array $s The seller.
     * @param string $return Where the processor sends the client back.
     * @param string $cancel Where it sends a client who cancels.
     * @return string The address of the processor's payment page, or why not (then not an https:// address;
     *                $found says when the last payment turned out paid or pending).
     */
    static function Create(array $inv, array $s, $return, $cancel)
    {
        global $zdbh;
        self::$found = '';
        // The invoice's last payment first: paid after all (the client paid and did not come back)?
        // Then it is closed before a new one is made, so the client can never pay twice (a Stripe
        // checkout page left open in another tab takes money by itself).
        if (preg_match(self::REF, (string) $inv['in_order_vc'])) {
            if ((int) $inv['in_order_in'] > time() - 10) {
                return 'A payment was started a moment ago: wait a few seconds, then try again.';
            }
            $r = self::Settle($inv);
            if ($r === 'paid' || $r === 'pending') {
                self::$found = $r;
                return $r === 'paid' ? 'This invoice is paid already. Thank you.' : 'Your last payment for this invoice is being checked: the invoice shows paid when it completes.';
            }
            $inv = pp::Invoice((int) $inv['in_id_pk']);
            if ((string) $inv['in_status_vc'] !== 'open') {
                return 'That invoice is not open any more.';
            }
            if (preg_match(self::REF, (string) $inv['in_order_vc'])) {
                if (!self::Abandon($inv)) {
                    return 'Your last payment for this invoice could not be closed: try again in a minute.';
                }
                // (paid at the very moment it was closed?)
                $r = self::Settle($inv);
                if ($r === 'paid' || $r === 'pending') {
                    self::$found = $r;
                    return $r === 'paid' ? 'This invoice is paid already. Thank you.' : 'Your last payment for this invoice is being checked: the invoice shows paid when it completes.';
                }
                $inv = pp::Invoice((int) $inv['in_id_pk']);
            }
        }
        $proc = (string) $s['ps_proc_vc'];
        $mode = pp::InvoiceMode($inv);
        $c = self::Get($proc);
        $k = $c !== '' ? self::Keys((int) $s['ps_acc_fk'], $proc, $mode) : null;
        if ($k === null) {
            return $c === '' ? 'Your provider takes no online payments here: pay as they tell you.' : self::Missing($proc, $mode);
        }
        if (!in_array((string) $inv['in_currency_vc'], $c::Currencies(), true)) {
            return $c::Name() . ' does not take ' . $inv['in_currency_vc'] . '.';
        }
        $u = ctrl_users::GetUserDetail((int) $inv['in_acc_fk']);
        $email = isset($u['email']) && filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL) ? (string) $u['email'] : '';
        $r = $c::Create($inv, $k, $mode, (string) $s['ps_name_vc'], $return, $cancel, $email);
        if (!is_array($r)) {
            return (string) $r;
        }
        if (!preg_match(self::REF, (string) $r[0]) || !self::Allowed((string) $r[1], $c)) {
            return $c::Name() . ' gave no page to pay on.';
        }
        $zdbh->prepare("UPDATE x_pp_invoices SET in_order_vc = :o, in_ordermode_vc = :m, in_order_in = :t, in_proc_vc = :p WHERE in_id_pk = :i AND in_status_vc = 'open'")
            ->execute(array(':o' => $r[0], ':m' => $mode, ':t' => time(), ':p' => $proc, ':i' => (int) $inv['in_id_pk']));
        pp::Log(array('in_proc_vc' => $proc) + $inv, 'started', $c::Name() . ($mode === 'live' ? '' : ', test mode'), (string) $r[0], (int) $inv['in_total_in']);
        return (string) $r[1];
    }

    /**
     * Close an invoice's last payment (a new one is made, or the invoice is voided).
     *
     * @param array $inv
     * @return bool True when it can not take money any more (or there is none).
     */
    static function Abandon(array $inv)
    {
        $ref = (string) $inv['in_order_vc'];
        $c = self::Get((string) $inv['in_proc_vc'] !== '' ? (string) $inv['in_proc_vc'] : 'paypal');
        if (!preg_match(self::REF, $ref) || $c === '') {
            return true;
        }
        $mode = pp::InvoiceMode($inv);
        $k = self::Keys((int) $inv['in_seller_fk'], (string) $inv['in_proc_vc'] !== '' ? (string) $inv['in_proc_vc'] : 'paypal', $mode);
        // (without the keys nothing can be asked: the seller took them away)
        if ($k === null || $c::Abandon($inv, $k, $mode)) {
            pp::Log($inv, 'closed', '', $ref);
            return true;
        }
        return false;
    }

    /**
     * May the client be sent to this address (one of the processor's own pages)?
     *
     * @param string $url
     * @param string $c The processor's class.
     * @return bool
     */
    static function Allowed($url, $c)
    {
        foreach ($c::Hosts() as $h) {
            if (strpos($url, rtrim($h, '/') . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The payment reference a client coming back carries, for the invoice's processor.
     *
     * @param array $inv
     * @param array $get $_GET
     * @return string '' when none.
     */
    static function ReturnRef(array $inv, array $get)
    {
        $c = self::Get((string) $inv['in_proc_vc']);
        $r = $c !== '' ? (string) $c::ReturnRef($get) : '';
        return preg_match(self::REF, $r) ? $r : '';
    }

    /**
     * Ask the processor about an invoice's payment (finishing it when the client approved it) and
     * settle the invoice from the answer.
     *
     * @param array $inv
     * @return string paid, pending, open (not finished yet) or why not.
     */
    static function Settle(array $inv)
    {
        $ref = (string) $inv['in_order_vc'];
        if (!preg_match(self::REF, $ref) || !in_array((string) $inv['in_status_vc'], array('open', 'pending'), true)) {
            return 'There is no payment to look at.';
        }
        $proc = (string) $inv['in_proc_vc'] !== '' ? (string) $inv['in_proc_vc'] : 'paypal';
        $c = self::Get($proc);
        // a test invoice only ever in test mode, a real one only live, and only as its payment was made
        $mode = pp::InvoiceMode($inv);
        if ($c === '' || (string) $inv['in_ordermode_vc'] !== $mode) {
            return 'This payment can not be checked.';
        }
        $k = self::Keys((int) $inv['in_seller_fk'], $proc, $mode);
        if ($k === null) {
            return self::Missing($proc, $mode);
        }
        $r = $c::Settle($inv, $k, $mode);
        return is_array($r) ? self::Apply($inv, $r, $c::Name()) : (string) $r;
    }

    /**
     * Settle an invoice from a processor's answer: paid only when the processor says so for this
     * invoice's own payment, its exact amount and currency.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $inv
     * @param array $r state (paid, pending, open, failed), ref, payment, amount (smallest unit), currency, invoice, payer
     * @param string $name The processor's name.
     * @return string
     */
    static function Apply(array $inv, array $r, $name)
    {
        global $zdbh;
        if ((string) ($r['ref'] ?? '') !== (string) $inv['in_order_vc']) {
            return $name . ' answered about another payment.';
        }
        $state = (string) ($r['state'] ?? '');
        if ($state === 'open') {
            return 'open';
        }
        if ($state === 'failed') {
            // declined, expired, cancelled, refunded...: the invoice is open to be paid again
            $zdbh->prepare("UPDATE x_pp_invoices SET in_status_vc = 'open', in_order_vc = NULL, in_capture_vc = NULL WHERE in_id_pk = :i AND in_status_vc IN ('open', 'pending')")
                ->execute(array(':i' => (int) $inv['in_id_pk']));
            pp::Log($inv, 'failed', (string) ($r['why'] ?? ''), (string) $inv['in_order_vc']);
            return $name . ' did not complete the payment' . (!empty($r['why']) ? ' (' . $r['why'] . ')' : '') . '.';
        }
        if ($state !== 'paid' && $state !== 'pending') {
            return $name . ' gave an answer the panel does not know.';
        }
        if ((string) ($r['invoice'] ?? '') !== (string) (int) $inv['in_id_pk']) {
            pp::Log($inv, 'refused', 'for invoice ID ' . mb_substr((string) ($r['invoice'] ?? ''), 0, 40), (string) $inv['in_order_vc']);
            return $name . '\'s payment is not for this invoice.';
        }
        $amount = $r['amount'] ?? null;
        if (!is_int($amount) || $amount !== (int) $inv['in_total_in'] || (string) ($r['currency'] ?? '') !== (string) $inv['in_currency_vc']) {
            $shown = is_int($amount) ? pp::Plain($amount, (string) ($r['currency'] ?? $inv['in_currency_vc'])) : '?';
            pp::Log($inv, 'refused', 'paid ' . $shown . ' ' . mb_substr((string) ($r['currency'] ?? '?'), 0, 3), (string) $inv['in_order_vc'], is_int($amount) ? $amount : null);
            return $name . '\'s payment (' . $shown . ' ' . ($r['currency'] ?? '?') . ') is not the invoice\'s amount: not counted.';
        }
        $pay = (string) ($r['payment'] ?? '');
        if (!preg_match(self::REF, $pay)) {
            return $name . '\'s payment has no ID.';
        }
        if ($state === 'paid') {
            return pp::MarkPaid((int) $inv['in_id_pk'], $name, $pay, (string) ($r['payer'] ?? '')) ? 'paid' : 'This payment is recorded already.';
        }
        try {
            $zdbh->prepare("UPDATE x_pp_invoices SET in_status_vc = 'pending', in_capture_vc = :c WHERE in_id_pk = :i AND in_status_vc IN ('open', 'pending')")
                ->execute(array(':c' => $pay, ':i' => (int) $inv['in_id_pk']));
        } catch (PDOException $e) {
            return 'This payment is recorded already.';
        }
        pp::Log($inv, 'pending', '', $pay, $amount);
        return 'pending';
    }

    /**
     * Ask the processor whether a paid invoice's payment was refunded or charged back since, and note
     * what changed: on the invoice, in its history, and to the seller (a notice and an email). The
     * client's hosting is not touched: a refund is often the seller's own doing, and what follows a
     * chargeback is the seller's to decide.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @param array $inv
     * @return string What changed ('' when nothing), or why it could not be looked at.
     */
    static function Reversals(array $inv)
    {
        global $zdbh;
        if ((string) $inv['in_status_vc'] !== 'paid' || !preg_match(self::REF, (string) $inv['in_capture_vc'])) {
            return '';
        }
        $proc = (string) $inv['in_proc_vc'] !== '' ? (string) $inv['in_proc_vc'] : 'paypal';
        $c = self::Get($proc);
        $mode = pp::InvoiceMode($inv);
        $zdbh->prepare("UPDATE x_pp_invoices SET in_checked_in = :t WHERE in_id_pk = :i")->execute(array(':t' => time(), ':i' => (int) $inv['in_id_pk']));
        if ($c === '' || (string) $inv['in_ordermode_vc'] !== $mode) {
            return 'This payment can not be checked.';
        }
        $k = self::Keys((int) $inv['in_seller_fk'], $proc, $mode);
        if ($k === null) {
            return self::Missing($proc, $mode);
        }
        $r = $c::Reversals($inv, $k, $mode);
        if (!is_array($r)) {
            return (string) $r;
        }
        $refund = in_array($r['refund'] ?? '', array('', 'partial', 'full'), true) ? (string) $r['refund'] : '';
        $dispute = array_key_exists('dispute', $r) && $r['dispute'] === null ? (string) $inv['in_dispute_vc']
            : (in_array($r['dispute'] ?? '', array('', 'open', 'won', 'lost'), true) ? (string) $r['dispute'] : '');
        $total = (int) $inv['in_total_in'];
        $amt = $r['refunded'] ?? null;
        $amt = is_int($amt) && $amt >= 0 && $amt <= $total ? $amt : ($refund === 'full' ? $total : null);
        $old = array((string) $inv['in_refund_vc'], $inv['in_refunded_in'] === null ? null : (int) $inv['in_refunded_in'], (string) $inv['in_dispute_vc']);
        if ($old === array($refund, $amt, $dispute) || ($refund === '' && $dispute === '' && $old[0] === '' && $old[2] === '')) {
            return '';
        }
        $zdbh->prepare("UPDATE x_pp_invoices SET in_refund_vc = :r, in_refunded_in = :a, in_dispute_vc = :d WHERE in_id_pk = :i")
            ->execute(array(':r' => $refund, ':a' => $amt, ':d' => $dispute, ':i' => (int) $inv['in_id_pk']));
        $name = $c::Name();
        $cur = (string) $inv['in_currency_vc'];
        $who = ctrl_users::GetUserDetail((int) $inv['in_acc_fk']);
        $client = (string) ($who['username'] ?? ('#' . (int) $inv['in_acc_fk']));
        $said = array();
        if ($refund !== $old[0] || ($refund !== '' && $amt !== $old[1])) {
            if ($refund === 'full') {
                pp::Log($inv, 'refunded', 'in full at ' . $name, (string) $inv['in_capture_vc'], $amt);
                $said[] = 'Invoice ' . $inv['in_number_vc'] . ' (' . $client . ', ' . pp::Show($total, $cur) . ') was refunded in full at ' . $name . '.';
            } elseif ($refund === 'partial') {
                pp::Log($inv, 'partrefund', 'in part at ' . $name . ($amt !== null ? ': ' . pp::Plain($amt, $cur) : ''), (string) $inv['in_capture_vc'], $amt);
                $said[] = 'Invoice ' . $inv['in_number_vc'] . ' (' . $client . ', ' . pp::Show($total, $cur) . ') was refunded in part at ' . $name . ($amt !== null ? ' (' . pp::Show($amt, $cur) . ')' : '') . '.';
            }
        }
        if ($dispute !== $old[2] && $dispute !== '') {
            $what = array('open' => array('dispute', 'opened a chargeback (a dispute) for'), 'won' => array('disputewon', 'decided the chargeback in your favour for'),
                'lost' => array('disputelost', 'took the money back (a chargeback) for'));
            pp::Log($inv, $what[$dispute][0], $name, (string) $inv['in_capture_vc']);
            $said[] = ($dispute === 'open' ? 'The client\'s bank or ' . $name . ' ' : $name . ' ') . $what[$dispute][1] . ' invoice ' . $inv['in_number_vc'] . ' (' . $client . ', ' . pp::Show($total, $cur) . ').'
                . ($dispute === 'open' ? ' Answer it at ' . $name . ' before its deadline.' : '');
        }
        if ($said) {
            pp::TellSeller($inv, $dispute !== '' && $dispute !== $old[2] ? 'Chargeback on invoice ' . $inv['in_number_vc'] : 'Refund on invoice ' . $inv['in_number_vc'], implode(' ', $said));
        }
        return implode(' ', $said);
    }

    /**
     * An HTTPS request to a processor (no redirects followed; plain http only to a local test
     * server the code itself names).
     *
     * @param string $url
     * @param string $method
     * @param array $headers
     * @param string|null $body
     * @return array{0:int, 1:array|null, 2:string} HTTP status, the JSON answer, and why it failed.
     */
    static function Http($url, $method, array $headers, $body = null)
    {
        $c = curl_init($url);
        curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (strpos($url, 'http://127.0.0.1') === 0 ? CURLPROTO_HTTP : 0)));
        if ($body !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        $err = $out === false ? curl_error($c) : '';
        $j = is_string($out) ? json_decode($out, true) : null;
        return array($code, is_array($j) ? $j : null, $err);
    }
}
