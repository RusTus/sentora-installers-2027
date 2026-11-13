<?php

/**
 * PayPal (its REST API, Orders v2) with the seller's own app keys: the money goes straight to the
 * seller's PayPal account. The client pays on PayPal's own pages (cards too); the panel then
 * captures the order itself.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09 (a processor of its own since 2026-11-10)
 */
class pp_proc_paypal implements pp_processor
{
    /** @var array<string, string> The API's addresses: live, and test mode (PayPal's sandbox, no real money). */
    static $api = array('live' => 'https://api-m.paypal.com', 'sandbox' => 'https://api-m.sandbox.paypal.com');

    /** @var string[] PayPal's payment pages. */
    static $pages = array('https://www.paypal.com', 'https://www.sandbox.paypal.com');

    /** @var string The last error. */
    static $error = '';

    static function Name()
    {
        return 'PayPal';
    }

    static function Fields()
    {
        $p = array('sandbox' => '/^[A-Za-z0-9_-]{10,200}$/', 'live' => '/^[A-Za-z0-9_-]{10,200}$/');
        return array(
            'clientid' => array('label' => 'Client ID', 'pattern' => $p, 'secret' => false),
            'secret' => array('label' => 'Secret', 'pattern' => $p, 'secret' => true),
        );
    }

    static function Help()
    {
        return 'Your app\'s Client ID and Secret, from developer.paypal.com > Apps & Credentials: one app for the sandbox (test mode), one for live.';
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
     * A request to PayPal.
     *
     * @param string $mode
     * @param string $method
     * @param string $path
     * @param array $headers
     * @param string|null $body
     * @return array{0:int, 1:array|null}
     */
    static function Http($mode, $method, $path, array $headers, $body = null)
    {
        list($code, $j, $err) = pp_pay::Http(self::$api[$mode === 'live' ? 'live' : 'sandbox'] . $path, $method, $headers, $body);
        if ($err !== '') {
            self::$error = 'PayPal could not be reached (' . $err . ').';
        }
        return array($code, $j);
    }

    /**
     * An access token for the seller's app (kept for the request).
     *
     * @param array $keys
     * @param string $mode
     * @return string '' on failure.
     */
    static function Token(array $keys, $mode)
    {
        static $cache = array();
        $key = hash('sha256', $mode . '|' . $keys['clientid'] . '|' . $keys['secret']);
        if (isset($cache[$key]) && $cache[$key][1] > time()) {
            return $cache[$key][0];
        }
        list($code, $j) = self::Http($mode, 'POST', '/v1/oauth2/token', array('Accept: application/json', 'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($keys['clientid'] . ':' . $keys['secret'])), 'grant_type=client_credentials');
        if ($code !== 200 || !isset($j['access_token'])) {
            self::$error = $code === 401 ? 'PayPal refused the app\'s client ID or secret (' . ($mode === 'live' ? 'live' : 'sandbox') . ').'
                : (self::$error !== '' ? self::$error : 'PayPal gave no access (HTTP ' . $code . ').');
            return '';
        }
        $cache[$key] = array((string) $j['access_token'], time() + max(60, (int) ($j['expires_in'] ?? 300) - 60));
        return $cache[$key][0];
    }

    /**
     * A JSON request with the seller's token.
     *
     * @param array $keys
     * @param string $mode
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @param string $requestId PayPal-Request-Id: the same request twice is done once.
     * @return array{0:int, 1:array|null}
     */
    static function Api(array $keys, $mode, $method, $path, $body = null, $requestId = '')
    {
        $t = self::Token($keys, $mode);
        if ($t === '') {
            return array(0, null);
        }
        $h = array('Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $t, 'Prefer: return=representation');
        if ($requestId !== '') {
            $h[] = 'PayPal-Request-Id: ' . $requestId;
        }
        return self::Http($mode, $method, $path, $h, $body === null ? ($method === 'POST' ? '{}' : null) : json_encode($body));
    }

    static function Check(array $keys, $mode)
    {
        self::$error = '';
        return self::Token($keys, $mode) !== '' ? '' : self::$error;
    }

    static function Create(array $inv, array $keys, $mode, $brand, $return, $cancel, $email)
    {
        self::$error = '';
        $cur = (string) $inv['in_currency_vc'];
        $body = array('intent' => 'CAPTURE', 'purchase_units' => array(array(
            'reference_id' => 'inv' . (int) $inv['in_id_pk'], 'custom_id' => (string) (int) $inv['in_id_pk'],
            'invoice_id' => mb_substr((string) $inv['in_number_vc'] . '-' . (int) $inv['in_id_pk'], 0, 127),
            'description' => mb_substr('Hosting ' . $inv['in_package_vc'] . ', invoice ' . $inv['in_number_vc'], 0, 127),
            'amount' => array('currency_code' => $cur, 'value' => pp::Plain((int) $inv['in_total_in'], $cur)))),
            'application_context' => array('brand_name' => mb_substr((string) $brand !== '' ? (string) $brand : 'Hosting', 0, 127),
                'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING', 'return_url' => $return, 'cancel_url' => $cancel));
        list($code, $j) = self::Api($keys, $mode, 'POST', '/v2/checkout/orders', $body, 'pp-order-' . (int) $inv['in_id_pk'] . '-' . bin2hex(random_bytes(6)));
        if (($code !== 201 && $code !== 200) || !isset($j['id']) || !preg_match('/^[A-Z0-9]{5,40}$/', (string) $j['id'])) {
            return self::$error !== '' ? self::$error : 'PayPal did not make the payment (' . (isset($j['message']) ? $j['message'] : 'HTTP ' . $code) . ').';
        }
        foreach ((array) ($j['links'] ?? array()) as $l) {
            if (isset($l['rel'], $l['href']) && in_array($l['rel'], array('approve', 'payer-action'), true)) {
                return array((string) $j['id'], (string) $l['href']);
            }
        }
        return 'PayPal gave no page to pay on.';
    }

    static function Abandon(array $inv, array $keys, $mode)
    {
        // an order takes money only when the panel captures it (Settle, which is asked first)
        return true;
    }

    static function ReturnRef(array $get)
    {
        return isset($get['token']) && is_string($get['token']) ? $get['token'] : '';
    }

    static function Settle(array $inv, array $keys, $mode)
    {
        self::$error = '';
        $order = (string) $inv['in_order_vc'];
        // a capture that was pending: ask about it
        if ((string) $inv['in_status_vc'] === 'pending' && preg_match('/^[A-Z0-9]{5,40}$/', (string) $inv['in_capture_vc'])) {
            list($code, $j) = self::Api($keys, $mode, 'GET', '/v2/payments/captures/' . $inv['in_capture_vc']);
            if ($code !== 200 || !is_array($j)) {
                return self::$error !== '' ? self::$error : 'PayPal did not answer (HTTP ' . $code . ').';
            }
            return self::FromCapture($inv, $j, '');
        }
        list($code, $j) = self::Api($keys, $mode, 'GET', '/v2/checkout/orders/' . $order);
        if ($code !== 200 || !is_array($j)) {
            return self::$error !== '' ? self::$error : 'PayPal did not answer (HTTP ' . $code . ').';
        }
        if (($j['status'] ?? '') === 'APPROVED') {
            list($code, $j) = self::Api($keys, $mode, 'POST', '/v2/checkout/orders/' . $order . '/capture', null, 'pp-capture-' . (int) $inv['in_id_pk'] . '-' . $order);
            if (($code !== 201 && $code !== 200) || !is_array($j)) {
                return self::$error !== '' ? self::$error : 'PayPal did not take the payment (' . (isset($j['message']) ? $j['message'] : 'HTTP ' . $code) . ').';
            }
        }
        if (($j['id'] ?? '') !== $order) {
            return 'PayPal answered about another order.';
        }
        $status = (string) ($j['status'] ?? '');
        if ($status === 'VOIDED') {
            return array('state' => 'failed', 'ref' => $order, 'why' => 'the order was voided');
        }
        if ($status !== 'COMPLETED') {
            return array('state' => 'open', 'ref' => $order);
        }
        $unit = $j['purchase_units'][0] ?? array();
        $cap = $unit['payments']['captures'][0] ?? null;
        if (!is_array($cap)) {
            return 'PayPal\'s order has no payment.';
        }
        // the order names its invoice (custom_id); a capture may not repeat it
        if (!isset($cap['custom_id']) && isset($unit['custom_id'])) {
            $cap['custom_id'] = (string) $unit['custom_id'];
        }
        return self::FromCapture($inv, $cap, (string) ($j['payer']['email_address'] ?? ''));
    }

    /**
     * A capture as pp_pay::Apply takes it.
     *
     * @param array $inv
     * @param array $cap
     * @param string $payer
     * @return array
     */
    static function FromCapture(array $inv, array $cap, $payer)
    {
        $cur = (string) ($cap['amount']['currency_code'] ?? '');
        $amount = pp::Parse((string) ($cap['amount']['value'] ?? ''), $cur);
        $status = (string) ($cap['status'] ?? '');
        $state = $status === 'COMPLETED' ? 'paid' : ($status === 'PENDING' ? 'pending' : 'failed');
        // The order (or the pending capture) was made for this invoice and found by the ID kept on
        // it, so it is this invoice's; when PayPal also names an invoice, it must be this one.
        return array('state' => $state, 'ref' => (string) $inv['in_order_vc'], 'payment' => (string) ($cap['id'] ?? ''),
            'amount' => is_int($amount) && (string) ($cap['amount']['value'] ?? '') !== '' ? $amount : null, 'currency' => $cur,
            'invoice' => isset($cap['custom_id']) ? (string) $cap['custom_id'] : (string) (int) $inv['in_id_pk'], 'payer' => $payer, 'why' => strtolower($status));
    }

    static function Reversals(array $inv, array $keys, $mode)
    {
        self::$error = '';
        $cap = (string) $inv['in_capture_vc'];
        if (!preg_match('/^[A-Z0-9]{5,40}$/', $cap)) {
            return 'There is no PayPal payment to look at.';
        }
        list($code, $j) = self::Api($keys, $mode, 'GET', '/v2/payments/captures/' . $cap);
        if ($code !== 200 || !is_array($j)) {
            return self::$error !== '' ? self::$error : 'PayPal did not answer (HTTP ' . $code . ').';
        }
        if ((string) ($j['id'] ?? '') !== $cap) {
            return 'PayPal answered about another payment.';
        }
        $status = (string) ($j['status'] ?? '');
        // (PayPal names the state, not the sum refunded: a full refund is the invoice's total)
        $refund = $status === 'REFUNDED' ? 'full' : ($status === 'PARTIALLY_REFUNDED' ? 'partial' : '');
        $refunded = $refund === 'full' ? (int) $inv['in_total_in'] : null;
        // chargebacks: PayPal's disputes (an app without the disputes feature gets none; a payment
        // taken back by the card's bank also shows as REVERSED)
        $dispute = $status === 'REVERSED' ? 'lost' : '';
        list($code, $d) = self::Api($keys, $mode, 'GET', '/v1/customer/disputes?disputed_transaction_id=' . rawurlencode($cap));
        if ($code !== 200 && $dispute === '') {
            // (could not look: what is known about a dispute stays as it is)
            $dispute = null;
        } elseif ($code === 200 && is_array($d) && !empty($d['items']) && is_array($d['items'][0] ?? null)) {
            $it = $d['items'][0];
            $st = (string) ($it['status'] ?? '');
            if ($st !== 'RESOLVED') {
                $dispute = 'open';
            } else {
                $out = (string) ($it['dispute_outcome']['outcome_code'] ?? '');
                if ($out === '' && preg_match('/^[A-Z0-9-]{5,60}$/', (string) ($it['dispute_id'] ?? ''))) {
                    list($c2, $one) = self::Api($keys, $mode, 'GET', '/v1/customer/disputes/' . $it['dispute_id']);
                    $out = $c2 === 200 && is_array($one) ? (string) ($one['dispute_outcome']['outcome_code'] ?? '') : '';
                }
                $dispute = $out === 'RESOLVED_SELLER_FAVOUR' || $out === 'CANCELED_BY_BUYER' ? 'won' : ($out !== '' ? 'lost' : ((string) $dispute !== '' ? $dispute : 'open'));
            }
        }
        return array('refund' => $refund, 'refunded' => $refunded, 'dispute' => $dispute);
    }
}

