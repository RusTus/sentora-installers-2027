<?php

/**
 * Stripe (Checkout Sessions) with the seller's own secret key: the money goes straight to the
 * seller's Stripe account. The client pays on Stripe's own checkout page (cards, wallets and the
 * other ways the seller switched on in Stripe); the panel then asks Stripe how it went.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
class pp_proc_stripe implements pp_processor
{
    /** @var string The API's address (test mode is decided by the key: sk_test_ or sk_live_). */
    static $api = 'https://api.stripe.com';

    /** @var string[] Stripe's checkout pages. */
    static $pages = array('https://checkout.stripe.com');

    /** The API version the panel was written for (Stripe answers every request as that version). */
    const VERSION = '2024-06-20';

    static function Name()
    {
        return 'Stripe';
    }

    static function Fields()
    {
        return array(
            'secret' => array('label' => 'Secret key', 'secret' => true,
                'pattern' => array('sandbox' => '/^(sk|rk)_test_[A-Za-z0-9]{10,250}$/', 'live' => '/^(sk|rk)_live_[A-Za-z0-9]{10,250}$/'),
                'help' => array('sandbox' => 'sk_test_... (or a restricted key, rk_test_...)', 'live' => 'sk_live_... (or a restricted key, rk_live_...)')),
        );
    }

    static function Help()
    {
        return 'Your secret key, from dashboard.stripe.com > Developers > API keys: the test key (sk_test_) for test mode, the live key (sk_live_) for real payments. A restricted key needs Checkout Sessions and PaymentIntents (read and write).';
    }

    static function Hosts()
    {
        return self::$pages;
    }

    static function Currencies()
    {
        return array_keys(pp::Currencies());
    }

    /**
     * How many of the panel's smallest units make one of Stripe's (Stripe counts HUF and TWD in
     * hundredths; JPY in yen, as the panel does).
     *
     * @param string $cur
     * @return int
     */
    static function Factor($cur)
    {
        return (int) (10 ** (($cur === 'JPY' ? 0 : 2) - pp::Decimals($cur)));
    }

    /**
     * A request to Stripe.
     *
     * @param array $keys
     * @param string $method
     * @param string $path
     * @param array|null $form
     * @param string $idem Idempotency-Key: the same request twice is done once.
     * @return array{0:int, 1:array|null, 2:string} HTTP status, the answer, why it failed.
     */
    static function Api(array $keys, $method, $path, $form = null, $idem = '')
    {
        $h = array('Accept: application/json', 'Authorization: Bearer ' . $keys['secret'], 'Stripe-Version: ' . self::VERSION);
        if ($form !== null) {
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($idem !== '') {
            $h[] = 'Idempotency-Key: ' . $idem;
        }
        list($code, $j, $err) = pp_pay::Http(self::$api . $path, $method, $h, $form === null ? null : http_build_query($form, '', '&'));
        $why = $err !== '' ? 'Stripe could not be reached (' . $err . ').'
            : ($code === 401 ? 'Stripe refused the secret key.' : (isset($j['error']['message']) ? 'Stripe: ' . $j['error']['message'] : 'Stripe answered HTTP ' . $code . '.'));
        return array($code, $j, $why);
    }

    static function Check(array $keys, $mode)
    {
        list($code, $j, $why) = self::Api($keys, 'GET', '/v1/checkout/sessions?limit=1');
        return $code === 200 && is_array($j) ? '' : $why;
    }

    static function Create(array $inv, array $keys, $mode, $brand, $return, $cancel, $email)
    {
        $cur = (string) $inv['in_currency_vc'];
        $id = (string) (int) $inv['in_id_pk'];
        $what = mb_substr('Hosting ' . $inv['in_package_vc'] . ', invoice ' . $inv['in_number_vc'], 0, 250);
        $form = array(
            'mode' => 'payment',
            'client_reference_id' => $id,
            'success_url' => $return . (strpos($return, '?') === false ? '?' : '&') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancel,
            'line_items' => array(array('quantity' => 1, 'price_data' => array('currency' => strtolower($cur),
                'unit_amount' => (int) $inv['in_total_in'] * self::Factor($cur), 'product_data' => array('name' => $what)))),
            'metadata' => array('invoice_id' => $id, 'invoice_number' => (string) $inv['in_number_vc']),
            'payment_intent_data' => array('description' => $what, 'metadata' => array('invoice_id' => $id, 'invoice_number' => (string) $inv['in_number_vc'])),
        );
        if ($email !== '') {
            $form['customer_email'] = $email;
        }
        list($code, $j, $why) = self::Api($keys, 'POST', '/v1/checkout/sessions', $form, 'pp-session-' . $id . '-' . bin2hex(random_bytes(6)));
        if ($code !== 200 || !isset($j['id'], $j['url']) || !preg_match('/^cs_(test|live)_[A-Za-z0-9]{10,250}$/', (string) $j['id'])) {
            return $code === 200 ? 'Stripe gave no page to pay on.' : $why;
        }
        return array((string) $j['id'], (string) $j['url']);
    }

    static function Abandon(array $inv, array $keys, $mode)
    {
        // a checkout page takes the money itself: it is closed at Stripe (expired), or found closed
        // (expired, or complete: it took what it took, and Settle has looked at that)
        $ref = (string) $inv['in_order_vc'];
        if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]{10,250}$/', $ref)) {
            return true;
        }
        list($code, $j) = self::Api($keys, 'POST', '/v1/checkout/sessions/' . $ref . '/expire', array(), 'pp-expire-' . $ref);
        if ($code === 200 && (string) ($j['status'] ?? '') === 'expired') {
            return true;
        }
        list($code, $j) = self::Api($keys, 'GET', '/v1/checkout/sessions/' . $ref);
        return $code === 200 && (string) ($j['id'] ?? '') === $ref && in_array((string) ($j['status'] ?? ''), array('expired', 'complete'), true);
    }

    static function ReturnRef(array $get)
    {
        return isset($get['session_id']) && is_string($get['session_id']) ? $get['session_id'] : '';
    }

    static function Settle(array $inv, array $keys, $mode)
    {
        $ref = (string) $inv['in_order_vc'];
        if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]{10,250}$/', $ref)) {
            return 'There is no Stripe payment to look at.';
        }
        list($code, $j, $why) = self::Api($keys, 'GET', '/v1/checkout/sessions/' . $ref);
        if ($code !== 200 || !is_array($j)) {
            return $why;
        }
        if ((string) ($j['id'] ?? '') !== $ref) {
            return 'Stripe answered about another payment.';
        }
        // a live payment with live keys only, a test one with test keys only
        if (($j['livemode'] ?? null) !== ($mode === 'live')) {
            return 'Stripe\'s payment is not in ' . ($mode === 'live' ? 'live' : 'test') . ' mode: not counted.';
        }
        $status = (string) ($j['status'] ?? '');
        if ($status === 'open') {
            return array('state' => 'open', 'ref' => $ref);
        }
        if ($status === 'expired') {
            return array('state' => 'failed', 'ref' => $ref, 'why' => 'the payment page expired');
        }
        if ($status !== 'complete') {
            return 'Stripe gave an answer the panel does not know (' . $status . ').';
        }
        $pi = $j['payment_intent'] ?? '';
        $pi = is_array($pi) ? (string) ($pi['id'] ?? '') : (string) $pi;
        $paid = (string) ($j['payment_status'] ?? '');
        $state = $paid === 'paid' ? 'paid' : '';
        if ($state === '' && $paid === 'unpaid' && preg_match('/^pi_[A-Za-z0-9]{10,250}$/', $pi)) {
            // a payment that takes time (a bank debit...): how it stands now
            list($code, $p, $why) = self::Api($keys, 'GET', '/v1/payment_intents/' . $pi);
            if ($code !== 200 || !is_array($p) || (string) ($p['id'] ?? '') !== $pi) {
                return $code === 200 ? 'Stripe answered about another payment.' : $why;
            }
            $ps = (string) ($p['status'] ?? '');
            $state = $ps === 'succeeded' ? 'paid' : (in_array($ps, array('canceled', 'requires_payment_method'), true) ? 'failed' : 'pending');
        }
        if ($state === '') {
            return 'Stripe has not taken the payment (' . $paid . ').';
        }
        $cur = strtoupper((string) ($j['currency'] ?? ''));
        $total = $j['amount_total'] ?? null;
        $f = isset(pp::Currencies()[$cur]) ? self::Factor($cur) : 0;
        $amount = is_int($total) && $f > 0 && $total % $f === 0 ? intdiv($total, $f) : null;
        return array('state' => $state, 'ref' => $ref, 'payment' => $pi !== '' ? $pi : $ref, 'amount' => $amount, 'currency' => $cur,
            'invoice' => (string) ($j['client_reference_id'] ?? ''), 'payer' => (string) ($j['customer_details']['email'] ?? ''), 'why' => 'payment failed');
    }

    static function Reversals(array $inv, array $keys, $mode)
    {
        $pi = (string) $inv['in_capture_vc'];
        if (!preg_match('/^pi_[A-Za-z0-9]{10,250}$/', $pi)) {
            return 'There is no Stripe payment to look at.';
        }
        list($code, $p, $why) = self::Api($keys, 'GET', '/v1/payment_intents/' . $pi . '?expand%5B%5D=latest_charge');
        if ($code !== 200 || !is_array($p)) {
            return $why;
        }
        if ((string) ($p['id'] ?? '') !== $pi) {
            return 'Stripe answered about another payment.';
        }
        if (($p['livemode'] ?? ($mode === 'live')) !== ($mode === 'live')) {
            return 'Stripe\'s payment is not in ' . ($mode === 'live' ? 'live' : 'test') . ' mode.';
        }
        $ch = $p['latest_charge'] ?? null;
        if (!is_array($ch)) {
            return 'Stripe did not say how the payment stands.';
        }
        $cur = (string) $inv['in_currency_vc'];
        $f = self::Factor($cur);
        $ref = $ch['amount_refunded'] ?? 0;
        $refunded = is_int($ref) && $f > 0 ? intdiv($ref, $f) : null;
        $refund = !empty($ch['refunded']) ? 'full' : ((is_int($ref) && $ref > 0) ? 'partial' : '');
        $dispute = '';
        if (!empty($ch['disputed'])) {
            $dispute = 'open';
            list($code, $d) = self::Api($keys, 'GET', '/v1/disputes?payment_intent=' . rawurlencode($pi) . '&limit=1');
            $st = $code === 200 && is_array($d) ? (string) ($d['data'][0]['status'] ?? '') : '';
            if ($st === 'lost') {
                $dispute = 'lost';
            } elseif ($st === 'won' || $st === 'warning_closed') {
                $dispute = 'won';
            }
        }
        return array('refund' => $refund, 'refunded' => $refunded, 'dispute' => $dispute);
    }
}

