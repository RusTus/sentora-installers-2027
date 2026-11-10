<?php
// Fake PayPal and Stripe APIs for testing (php -S 127.0.0.1:PORT fake.php). State in env FAKE_STATE (JSON).
// Control: /control/<what>?... changes state the way a client paying (or PayPal/Stripe misbehaving) would.
$file = getenv('FAKE_STATE');
$st = is_file($file) ? json_decode(file_get_contents($file), true) : array();
$st += array('paypal' => array(), 'captures' => array(), 'stripe' => array(), 'pi' => array(), 'n' => 0,
    'keys' => array('paypal/sandbox' => 'SBCLIENTID01:SBSECRET0001', 'paypal/live' => 'LIVECLIENT01:LIVESECRET01'), 'calls' => array());
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$body = file_get_contents('php://input');
$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
$st['calls'][] = $_SERVER['REQUEST_METHOD'] . ' ' . $path;
$out = function ($code, $j) use (&$st, $file) {
    file_put_contents($file, json_encode($st));
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($j);
    exit;
};
$id = function ($p) use (&$st) {
    $st['n']++;
    return $p . strtoupper(substr(hash('sha256', $st['n'] . microtime()), 0, 17));
};

if (preg_match('#^/paypal/(live|sandbox)(/.*)$#', $path, $m)) {
    $mode = $m[1];
    $p = $m[2];
    if ($p === '/v1/oauth2/token') {
        return $auth === 'Basic ' . base64_encode($st['keys']['paypal/' . $mode]) ? $out(200, array('access_token' => 'TOK-' . $mode, 'expires_in' => 3600)) : $out(401, array('error' => 'invalid_client'));
    }
    if ($auth !== 'Bearer TOK-' . $mode) {
        $out(401, array('message' => 'no token'));
    }
    if ($p === '/v2/checkout/orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode($body, true);
        $oid = $id('');
        $st['paypal'][$oid] = array('id' => $oid, 'mode' => $mode, 'status' => 'CREATED', 'unit' => $b['purchase_units'][0], 'return' => $b['application_context']['return_url'],
            'cancel' => $b['application_context']['cancel_url']);
        $out(201, array('id' => $oid, 'status' => 'CREATED', 'links' => array(array('rel' => 'approve', 'href' => getenv('FAKE_BASE') . '/pages/paypal/checkoutnow?token=' . $oid))));
    }
    if (preg_match('#^/v2/checkout/orders/([A-Z0-9]+)(/capture)?$#', $p, $mm) && isset($st['paypal'][$mm[1]]) && $st['paypal'][$mm[1]]['mode'] === $mode) {
        $o = &$st['paypal'][$mm[1]];
        if (!empty($mm[2])) {
            if ($o['status'] !== 'APPROVED') {
                $out(422, array('message' => 'ORDER_NOT_APPROVED'));
            }
            $u = $o['unit'];
            $cid = $id('CAP');
            $st['captures'][$cid] = array('id' => $cid, 'status' => isset($o['capstatus']) ? $o['capstatus'] : 'COMPLETED',
                'amount' => isset($o['capamount']) ? $o['capamount'] : $u['amount'], 'custom_id' => isset($o['capcustom']) ? $o['capcustom'] : $u['custom_id']);
            $o['status'] = 'COMPLETED';
            $o['capture'] = $cid;
        }
        $r = array('id' => $o['id'], 'status' => $o['status'], 'purchase_units' => array(array('custom_id' => $o['unit']['custom_id'],
            'payments' => isset($o['capture']) ? array('captures' => array($st['captures'][$o['capture']])) : array())), 'payer' => array('email_address' => 'buyer@example.com'));
        $out(!empty($mm[2]) ? 201 : 200, $r);
    }
    if (preg_match('#^/v2/payments/captures/([A-Z0-9]+)$#', $p, $mm) && isset($st['captures'][$mm[1]])) {
        $out(200, $st['captures'][$mm[1]]);
    }
    $out(404, array('message' => 'RESOURCE_NOT_FOUND'));
}

if (preg_match('#^/stripe(/.*)$#', $path, $m)) {
    $p = $m[1];
    if (!preg_match('/^Bearer (sk|rk)_(test|live)_[A-Za-z0-9]+$/', $auth, $km) || strpos($auth, 'BAD') !== false) {
        $out(401, array('error' => array('message' => 'Invalid API Key provided')));
    }
    $live = $km[2] === 'live';
    if (empty($_SERVER['HTTP_STRIPE_VERSION'])) {
        $out(400, array('error' => array('message' => 'no version')));
    }
    if ($p === '/v1/checkout/sessions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        parse_str($body, $f);
        if (empty($f['line_items'][0]['price_data']['unit_amount']) || $f['mode'] !== 'payment' || strpos($f['success_url'], '{CHECKOUT_SESSION_ID}') === false) {
            $out(400, array('error' => array('message' => 'bad request: ' . $body)));
        }
        $sid = 'cs_' . ($live ? 'live' : 'test') . '_' . $id('a1');
        $st['stripe'][$sid] = array('id' => $sid, 'object' => 'checkout.session', 'livemode' => $live, 'status' => 'open', 'payment_status' => 'unpaid',
            'amount_total' => (int) $f['line_items'][0]['price_data']['unit_amount'], 'currency' => $f['line_items'][0]['price_data']['currency'],
            'client_reference_id' => $f['client_reference_id'], 'payment_intent' => null, 'customer_details' => null,
            'url' => getenv('FAKE_BASE') . '/pages/stripe/c/pay/' . $sid, 'success_url' => $f['success_url'], 'customer_email' => isset($f['customer_email']) ? $f['customer_email'] : '');
        $out(200, $st['stripe'][$sid]);
    }
    if ($p === '/v1/checkout/sessions' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $out(200, array('object' => 'list', 'data' => array()));
    }
    if (preg_match('#^/v1/checkout/sessions/(cs_\w+)$#', $p, $mm) && isset($st['stripe'][$mm[1]])) {
        $out(200, $st['stripe'][$mm[1]]);
    }
    if (preg_match('#^/v1/payment_intents/(pi_\w+)$#', $p, $mm) && isset($st['pi'][$mm[1]])) {
        $out(200, $st['pi'][$mm[1]]);
    }
    $out(404, array('error' => array('message' => 'No such resource')));
}

if (strpos($path, '/control/') === 0) {
    $what = substr($path, 9);
    if ($what === 'paypal') { // approve an order, optionally with a capture that misbehaves
        $o = &$st['paypal'][$q['o']];
        $o['status'] = isset($q['status']) ? $q['status'] : 'APPROVED';
        foreach (array('capstatus', 'capcustom') as $k) {
            if (isset($q[$k])) {
                $o[$k] = $q[$k];
            }
        }
        if (isset($q['capvalue'])) {
            $o['capamount'] = array('currency_code' => isset($q['capcur']) ? $q['capcur'] : $o['unit']['amount']['currency_code'], 'value' => $q['capvalue']);
        }
        $out(200, array('ok' => 1));
    }
    if ($what === 'capture') {
        $st['captures'][$q['c']]['status'] = $q['status'];
        $out(200, array('ok' => 1));
    }
    if ($what === 'stripe') { // a session: paid, unpaid (bank debit processing), expired; or changed fields
        $s = &$st['stripe'][$q['s']];
        if (isset($q['pay'])) {
            $s['status'] = $q['pay'] === 'expired' ? 'expired' : 'complete';
            if ($q['pay'] !== 'expired') {
                $pi = 'pi_' . $id('b2');
                $s['payment_intent'] = $pi;
                $s['payment_status'] = $q['pay'] === 'paid' ? 'paid' : 'unpaid';
                $s['customer_details'] = array('email' => 'card@example.com');
                $st['pi'][$pi] = array('id' => $pi, 'status' => $q['pay'] === 'paid' ? 'succeeded' : 'processing');
            }
        }
        foreach (array('amount_total', 'currency', 'client_reference_id') as $k) {
            if (isset($q[$k])) {
                $s[$k] = $k === 'amount_total' ? (int) $q[$k] : $q[$k];
            }
        }
        if (isset($q['livemode'])) {
            $s['livemode'] = $q['livemode'] === '1';
        }
        $out(200, array('ok' => 1, 'pi' => $s['payment_intent']));
    }
    if ($what === 'pi') {
        $st['pi'][$q['pi']]['status'] = $q['status'];
        $out(200, array('ok' => 1));
    }
    if ($what === 'state') {
        $out(200, $st);
    }
}
$out(404, array('message' => 'unknown ' . $path));
