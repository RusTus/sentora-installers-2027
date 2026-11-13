<?php

/**
 * Payment Plans' pay link: one invoice, paid online without logging in (a client switched off
 * for not paying can not log in to the panel). The link's key comes from the server's own secret
 * (pp::PayKey), so it opens that invoice only; the page shows the invoice's essentials and lets it
 * be paid, nothing else. As on the Billing page, the payment counts only when the seller's
 * processor (PayPal, Stripe) confirms it to the panel (pp_pay::Settle).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
chdir(dirname(__FILE__) . '/../../../');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once dirname(__FILE__) . '/pp.php';
require_once dirname(__FILE__) . '/payments.php';

/**
 * Escape for HTML.
 *
 * @param mixed $s
 * @return string
 */
function pp_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// where a form may lead: here, and the processors' payment pages
$ppTo = array_merge(array("'self'"), pp_pay::Hosts());
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action " . implode(' ', $ppTo) . "; base-uri 'none'; frame-ancestors 'none'");
// the link's key must not travel on to PayPal or anywhere else
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$id = isset($_GET['i']) && is_string($_GET['i']) && ctype_digit($_GET['i']) ? (int) $_GET['i'] : 0;
$key = isset($_GET['k']) && is_string($_GET['k']) ? $_GET['k'] : '';
$inv = $id > 0 && preg_match('/^[0-9a-f]{40}$/', $key) ? pp::Invoice($id) : null;
if (!$inv || !hash_equals(pp::PayKey($inv), $key) || (string) $inv['in_status_vc'] === 'void') {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Not found</title><p style="font-family:sans-serif">This pay link is not valid (any more).</p>';
    exit;
}
$s = pp::Seller((int) $inv['in_seller_fk']);
$mode = pp::InvoiceMode($inv);
$host = isset($_SERVER['HTTP_HOST']) && preg_match('/^[A-Za-z0-9.-]{1,253}(:\d{1,5})?$/', (string) $_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
$self = ($host !== '' ? ($https ? 'https://' : 'http://') . $host : rtrim(pp::PanelUrl((int) $inv['in_acc_fk']), '/')) . '/modules/payment_plans/code/pay.php?i=' . $id . '&k=' . $key;
$msg = array();

$name = pp_pay::Name((string) $s['ps_proc_vc']);
// paying: the panel makes the payment (the invoice's amount) and sends the client to the processor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) $inv['in_status_vc'] === 'open') {
    if (!pp::CanPay($s, $inv)) {
        $msg[] = array('bad', $mode === 'live' ? 'Online payment is not set up for this invoice: pay as your provider tells you.' : 'This test invoice can only be paid while your provider is in test mode.');
    } else {
        // (Create looks at the invoice's last payment first, and closes it before making a new one)
        $go = pp_pay::Create($inv, $s, $self . '&r=1', $self . '&c=1');
        if (preg_match('#^https?://#', $go)) {
            header('Location: ' . $go);
            exit;
        }
        $msg[] = array(pp_pay::$found !== '' ? 'ok' : 'bad', $go);
        $inv = pp::Invoice($id);
    }
}
// back from the processor: this invoice's own payment only
if (isset($_GET['c'])) {
    $msg[] = array('bad', 'The payment was cancelled: nothing was paid.');
} elseif (isset($_GET['r']) && (string) $inv['in_status_vc'] !== 'paid' && pp_pay::ReturnRef($inv, $_GET) !== '' && pp_pay::ReturnRef($inv, $_GET) === (string) $inv['in_order_vc']) {
    $r = pp_pay::Settle($inv);
    $msg[] = $r === 'paid' ? array('ok', 'Thank you: the invoice is paid.')
        : ($r === 'pending' ? array('ok', 'Your payment is being checked: the invoice shows paid when it completes.')
        : array('bad', $r === 'open' ? 'The payment was not taken: try again.' : $r));
    $inv = pp::Invoice($id);
}
$cur = (string) $inv['in_currency_vc'];
$state = (string) $inv['in_status_vc'];
$plan = pp::Plan((int) $inv['in_acc_fk']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Invoice <?php echo pp_h($inv['in_number_vc']); ?></title>
<style>
:root { color-scheme: light dark; --ink: #1f2933; --muted: #6b7785; --line: #e6e9ee; --card: #fff; --bg: #f4f6f9; --ok: #1b7a50; --oks: #e3f5ec; --bad: #b42318; --bads: #fdecea; --warn: #8f5b00; --warns: #fff4d6; }
@media (prefers-color-scheme: dark) { :root { --ink: #e6e9ee; --muted: #9aa5b1; --line: #2f3a46; --card: #1c242d; --bg: #11171d; --oks: #12372a; --bads: #3d1714; --warns: #3a2c0b; --ok: #6fd3a2; --bad: #ff8a80; --warn: #f2c35b; } }
body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
main { max-width: 520px; margin: 40px auto; padding: 0 16px; }
.card { background: var(--card); border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 24px; }
h1 { font-size: 20px; margin: 0 0 4px; } .muted { color: var(--muted); font-size: 13px; }
table { width: 100%; border-collapse: collapse; margin: 18px 0; } td { padding: 8px 0; border-bottom: 1px solid var(--line); } td:last-child { text-align: right; }
.total td { font-weight: 700; font-size: 17px; border: 0; }
.msg { padding: 10px 12px; border-radius: 6px; margin: 0 0 16px; } .ok { background: var(--oks); color: var(--ok); } .bad { background: var(--bads); color: var(--bad); }
.test { background: var(--warns); color: var(--warn); border: 1px dashed var(--warn); padding: 8px 12px; border-radius: 6px; margin: 0 0 16px; font-size: 13px; }
.state { display: inline-block; font-size: 12px; font-weight: 700; padding: 2px 10px; border-radius: 999px; background: var(--line); }
.state.paid { background: var(--oks); color: var(--ok); } .state.open { background: var(--warns); color: var(--warn); }
button { width: 100%; padding: 12px; border: 0; border-radius: 8px; background: #2f6fb0; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
button.paypal { background: #ffc439; color: #111; } button.stripe { background: #635bff; color: #fff; }
</style></head><body><main>
<?php foreach ($msg as $m) { echo '<div class="msg ' . $m[0] . '">' . pp_h($m[1]) . '</div>'; } ?>
<div class="card">
<?php if ((int) $inv['in_test_in']) { echo '<div class="test">Test invoice: paid (if at all) in the payment service\'s test mode. No real money.</div>'; } ?>
<h1>Invoice <?php echo pp_h($inv['in_number_vc']); ?></h1>
<div class="muted"><?php echo pp_h((string) $s['ps_name_vc']); ?></div>
<table>
<tr><td>Hosting: <?php echo pp_h($inv['in_package_vc']); ?>, <?php echo $inv['in_period_vc'] === 'year' ? 'yearly' : 'monthly'; ?><div class="muted"><?php echo pp_h(date('j M Y', (int) $inv['in_from_in']) . ' – ' . date('j M Y', (int) $inv['in_to_in'])); ?></div></td><td><?php echo pp_h(pp::Show((int) $inv['in_net_in'], $cur)); ?></td></tr>
<?php if ((int) $inv['in_taxrate_in'] > 0) { echo '<tr><td>' . pp_h(($inv['in_taxlabel_vc'] !== '' ? $inv['in_taxlabel_vc'] : 'Tax') . ' ' . rtrim(rtrim(sprintf('%.2f', (int) $inv['in_taxrate_in'] / 100), '0'), '.') . '%') . '</td><td>' . pp_h(pp::Show((int) $inv['in_tax_in'], $cur)) . '</td></tr>'; } ?>
<tr class="total"><td>Total</td><td><?php echo pp_h(pp::Show((int) $inv['in_total_in'], $cur)); ?></td></tr>
</table>
<p>Due <?php echo pp_h(date('j M Y', (int) $inv['in_due_in'])); ?> &nbsp; <span class="state <?php echo pp_h($state); ?>"><?php echo pp_h($state === 'paid' ? 'Paid' : ($state === 'pending' ? 'Being checked' : 'Open')); ?></span></p>
<?php if ($state === 'open' && pp::CanPay($s, $inv)) { ?>
<form method="post" action="<?php echo pp_h($self); ?>"><button type="submit" class="<?php echo pp_h((string) $s['ps_proc_vc']); ?>">Pay with <?php echo pp_h($name); ?></button></form>
<p class="muted">You pay on <?php echo pp_h($name); ?>'s own pages (cards too); your card never reaches this server.</p>
<?php } elseif ($state === 'open') { ?>
<p class="muted">Pay as your provider tells you.</p>
<?php } elseif ($state === 'paid') { ?>
<p class="muted">Paid <?php echo pp_h(date('j M Y', (int) $inv['in_paid_in'])); ?>. <?php echo $plan && (int) $plan['pl_suspended_in'] === 0 ? 'Your hosting is on.' : ''; ?></p>
<?php } ?>
</div></main></body></html>
