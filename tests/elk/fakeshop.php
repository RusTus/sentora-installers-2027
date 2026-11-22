<?php
// The Elk node test: a small Magento-looking store on shop.test (a database of the owner's, its tables, app/etc/env.php,
// bin/magento), with customers' e-mail and street addresses in it that must never reach the node.
chdir("/etc/sentora/panel"); require "dryden/loader.inc.php"; require "cnf/db.php"; require "inc/dbc.inc.php";
global $zdbh;
// (the test panel has no websites: shop.test is made for zadmin when missing)
if (!$zdbh->query("SELECT COUNT(*) FROM x_vhosts WHERE vh_name_vc = 'shop.test' AND vh_deleted_ts IS NULL")->fetchColumn()) {
    $a = $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc = 'zadmin'")->fetchColumn();
    $zdbh->prepare("INSERT INTO x_vhosts (vh_acc_fk, vh_name_vc, vh_directory_vc, vh_type_in, vh_active_in, vh_created_ts) VALUES (:a, 'shop.test', '/shop_test/', 1, 1, :t)")->execute(array(':a' => $a, ':t' => time()));
}
$v = $zdbh->query("SELECT v.vh_acc_fk, a.ac_user_vc, v.vh_directory_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk WHERE v.vh_name_vc = 'shop.test' AND v.vh_deleted_ts IS NULL")->fetch(PDO::FETCH_NUM);
list($uid, $user, $sub) = $v;
$db = $user . '_magento';
$zdbh->exec("CREATE DATABASE IF NOT EXISTS `$db`");
if (!$zdbh->query("SELECT COUNT(*) FROM x_mysql_databases WHERE my_name_vc = '$db' AND my_deleted_ts IS NULL")->fetchColumn()) {
    $zdbh->prepare("INSERT INTO x_mysql_databases (my_acc_fk, my_name_vc, my_created_ts) VALUES (:u, :d, :t)")->execute(array(':u' => $uid, ':d' => $db, ':t' => time()));
}
$p = 'mg_';
$zdbh->exec("CREATE TABLE IF NOT EXISTS `$db`.`{$p}sales_order` (entity_id INT PRIMARY KEY, increment_id VARCHAR(32), state VARCHAR(32), status VARCHAR(32), base_grand_total DECIMAL(20,4), grand_total DECIMAL(20,4), order_currency_code VARCHAR(3), base_currency_code VARCHAR(3), total_qty_ordered DECIMAL(12,4), total_item_count INT, customer_is_guest TINYINT, store_id INT, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, customer_email VARCHAR(255), customer_firstname VARCHAR(255))");
$zdbh->exec("CREATE TABLE IF NOT EXISTS `$db`.`{$p}sales_order_address` (entity_id INT PRIMARY KEY, parent_id INT, address_type VARCHAR(16), country_id VARCHAR(2), region VARCHAR(255), street VARCHAR(255), email VARCHAR(255))");
$zdbh->exec("CREATE TABLE IF NOT EXISTS `$db`.`{$p}sales_order_item` (item_id INT PRIMARY KEY, order_id INT, parent_item_id INT NULL, sku VARCHAR(64), name VARCHAR(255), qty_ordered DECIMAL(12,4), base_row_total DECIMAL(20,4))");
$zdbh->exec("CREATE TABLE IF NOT EXISTS `$db`.`{$p}search_query` (query_id INT PRIMARY KEY, query_text VARCHAR(255), num_results INT, popularity INT, store_id INT, updated_at TIMESTAMP NULL)");
foreach (array('sales_order', 'sales_order_address', 'sales_order_item', 'search_query') as $t) { $zdbh->exec("DELETE FROM `$db`.`$p$t`"); }
$c = array('US' => 'Ohio', 'CA' => 'Ontario', 'GB' => 'London', 'DE' => 'Bavaria');
$sku = array(array('SAW-001', 'Cold saw blade 14 in'), array('SAW-220', 'Band saw blade'), array('CLMP-7', 'Vise clamp'), array('OIL-1', 'Cutting oil 1 gal'));
for ($i = 1; $i <= 40; $i++) {
    $t = gmdate('Y-m-d H:i:s', time() - rand(0, 20 * 86400)); $cc = array_rand($c);
    $st = $i % 13 == 0 ? array('canceled', 'canceled') : ($i % 4 == 0 ? array('processing', 'processing') : array('complete', 'complete'));
    $tot = rand(4000, 90000) / 100;
    $zdbh->exec("INSERT INTO `$db`.`{$p}sales_order` VALUES ($i, '0000000" . (100 + $i) . "', '{$st[0]}', '{$st[1]}', $tot, $tot, 'USD', 'USD', 2, 2, " . ($i % 3 == 0 ? 1 : 0) . ", 1, '$t', '$t', 'buyer$i@example.com', 'Buyer')");
    $zdbh->exec("INSERT INTO `$db`.`{$p}sales_order_address` VALUES ($i, $i, 'billing', '$cc', '{$c[$cc]}', '1 Main St', 'buyer$i@example.com')");
    $k = $sku[$i % 4];
    $zdbh->exec("INSERT INTO `$db`.`{$p}sales_order_item` VALUES ($i, $i, NULL, '{$k[0]}', '{$k[1]}', " . rand(1, 3) . ", $tot)");
}
$terms = array(array('band saw blade', 14, 52), array('cold saw', 6, 40), array('hemsaw parts', 0, 17), array('coolant pump', 0, 9), array('vise', 3, 22),
    // (a bot probe and an old address of the store, as the owner's Magento has them: sorted as noise and old by the node)
    array('wp admin', 0, 18), array('by model blade', 4, 11205));
foreach ($terms as $n => $x) { $zdbh->exec("INSERT INTO `$db`.`{$p}search_query` VALUES (" . ($n + 1) . ", '{$x[0]}', {$x[1]}, {$x[2]}, 1, '" . gmdate('Y-m-d H:i:s', time() - 3600) . "')"); }
$dir = rtrim(ctrl_options::GetSystemOption('hosted_dir'), '/') . "/$user/public_html/" . trim($sub, '/');
@mkdir("$dir/app/etc", 0755, true); @mkdir("$dir/bin", 0755, true); touch("$dir/bin/magento");
file_put_contents("$dir/app/etc/env.php", "<?php\nreturn [\n    'db' => [\n        'table_prefix' => '$p',\n        'connection' => [\n            'default' => [\n                'host' => 'localhost',\n                'dbname' => '$db',\n                'username' => 'magento',\n                'password' => 'not-used',\n                'active' => '1'\n            ]\n        ]\n    ]\n];\n");
echo "store: $dir, database $db\n";
