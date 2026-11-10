<?php
// Version 101 as it was: its tables, a seller with PayPal keys (live and sandbox) and an invoice with an order.
putenv('PP_OLD=1');
chdir(__DIR__ . '/root');
require __DIR__ . '/root/dryden/loader.inc.php';
require getenv('PP_OLD_DIR') . '/code/pp.php';
pp::Ensure();
$zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_reseller_fk, ac_group_fk, ac_created_ts) VALUES (5, 'oldres', 1, 2, 1000)");
pp::SaveSeller(5, array('name' => 'Old Co', 'address' => '', 'email' => '', 'currency' => 'USD', 'taxlabel' => '', 'taxrate' => 0, 'prefix' => 'INV-', 'next' => 1,
    'lead' => 7, 'grace' => 7, 'suspend' => true, 'mode' => 'live', 'clientid' => 'LIVECLIENT01', 'sbclientid' => 'SBCLIENTID01'), 'LIVESECRET01', 'SBSECRET0001');
$zdbh->exec("INSERT INTO x_pp_invoices (in_seller_fk, in_acc_fk, in_number_vc, in_period_vc, in_from_in, in_to_in, in_net_in, in_total_in, in_currency_vc, in_due_in, in_created_in, in_order_vc, in_ordermode_vc, in_order_in)
    VALUES (5, 6, 'INV-00001', 'month', 0, 0, 1000, 1000, 'USD', 0, 0, 'OLDORDER123', 'live', 0)");
$r = $zdbh->query("SELECT ps_clientid_vc, ps_secret_tx, ps_sbclientid_vc, ps_sbsecret_tx FROM x_pp_sellers")->fetch(PDO::FETCH_ASSOC);
echo json_encode($r);
