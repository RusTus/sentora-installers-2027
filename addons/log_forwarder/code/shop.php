<?php

/**
 * Log Forwarder's Elk node: an online store on this panel (Magento 2), for the node's "Online store" board. The
 * administrator picks the website on the page; every daemon run then reads, from the store's own database through the
 * panel's connection, what changed since the run before, and sends it over the same pinned connection as the logs:
 *   - orders (kind shop-order): number, status, totals and currency, how many items, the billing country and region,
 *     guest or customer; never names, e-mail addresses, street addresses or payment details;
 *   - their products (kind shop-item): SKU, name, quantity, line total;
 *   - Magento's list of searches (kind shop-search): the words, how many results they found, how often searched.
 * The store's settings file (app/etc/env.php) is read as text, never run; its database must be one of the website
 * owner's own databases on this server (x_mysql_databases), or nothing is read. The first run sends the last year,
 * 500 orders a run, and the node keeps one copy of each order (an order whose status changes is sent again).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-21
 */
class elk_shop
{
    const S_SITE = 'elk_shop';
    const STATE = 'shop.json';
    const BATCH = 500;

    /**
     * The websites with a Magento 2 store found in their folder: name => array(account, folder, env.php).
     *
     * @return array<string, array{user: string, uid: int, dir: string, env: string}>
     */
    static function Stores()
    {
        global $zdbh;
        $out = array();
        $hosted = rtrim((string) ctrl_options::GetSystemOption('hosted_dir'), '/');
        if ($hosted === '') {
            return $out;
        }
        $q = $zdbh->query("SELECT LOWER(v.vh_name_vc), a.ac_user_vc, a.ac_id_pk, v.vh_directory_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
            WHERE v.vh_deleted_ts IS NULL AND a.ac_deleted_ts IS NULL AND v.vh_type_in IN (1, 2) ORDER BY v.vh_name_vc");
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
            list($name, $user, $uid, $sub) = array((string) $r[0], (string) $r[1], (int) $r[2], trim((string) $r[3], '/'));
            if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $name) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $user) || strpos($sub, '..') !== false) {
                continue;
            }
            $home = realpath($hosted . '/' . $user . '/public_html');
            $dir = realpath($hosted . '/' . $user . '/public_html/' . $sub);
            if ($home === false || $dir === false || strpos($dir . '/', $home . '/') !== 0) {
                continue;
            }
            // (Magento's own folder is the website's folder, or the one above it when the website shows pub/)
            foreach (array($dir, dirname($dir)) as $root) {
                $env = $root . '/app/etc/env.php';
                if (strpos($root . '/', $home . '/') === 0 && is_file($env) && !is_link($env) && is_file($root . '/bin/magento')) {
                    $out[$name] = array('user' => $user, 'uid' => $uid, 'dir' => $root, 'env' => $env);
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * The store's database and table prefix, from its env.php read as text (null when not found or not the owner's).
     *
     * @param array $store From Stores().
     * @return array{db: string, prefix: string}|null
     */
    static function Database(array $store)
    {
        global $zdbh;
        $php = (string) @file_get_contents($store['env'], false, null, 0, 262144);
        if (!preg_match("/'connection'\\s*=>\\s*(?:array\\s*\\(|\\[)\\s*'default'\\s*=>\\s*(?:array\\s*\\(|\\[)(.{0,2000}?)(?:\\)|\\])/s", $php, $m)) {
            return null;
        }
        $conn = $m[1];
        $get = function ($key, $text) {
            return preg_match("/'" . $key . "'\\s*=>\\s*'([^'\\\\]*)'/", $text, $x) ? $x[1] : null;
        };
        $db = $get('dbname', $conn);
        $host = (string) $get('host', $conn);
        $prefix = preg_match("/'table_prefix'\\s*=>\\s*'([^'\\\\]*)'/", $php, $x) ? $x[1] : '';
        if ($db === null || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $db) || !preg_match('/^[A-Za-z0-9_]{0,30}$/', $prefix)
            || !in_array(strtolower($host), array('', 'localhost', '127.0.0.1', '::1', 'localhost:3306', '127.0.0.1:3306'), true)) {
            return null;
        }
        // the website owner's own database, and no one else's
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_databases WHERE my_name_vc = :d AND my_acc_fk = :u AND my_deleted_ts IS NULL");
        $q->execute(array(':d' => $db, ':u' => $store['uid']));
        return (int) $q->fetchColumn() > 0 ? array('db' => $db, 'prefix' => $prefix) : null;
    }

    /**
     * Root, every daemon run: send what changed in the chosen store.
     *
     * @return string '' or what it did, for the daemon log.
     */
    static function Run()
    {
        global $zdbh;
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return '';
        }
        $site = elk::Setting(self::S_SITE);
        $file = elk::STATE_DIR . '/' . self::STATE;
        $st = json_decode((string) @file_get_contents($file), true);
        $st = is_array($st) && isset($st['site']) && $st['site'] === $site ? $st : array('site' => $site);
        if ($site === '' || !elk::Paired()) {
            return '';
        }
        $stores = self::Stores();
        // (once, after Log Forwarder 213: everything sent again, so the node sorts the old searches and the order lines carry
        //  their order number; the node replaces what it has, nothing is counted twice)
        if ((int) ($st['resent'] ?? 0) < 213) {
            $st['orders_since'] = ''; $st['search_since'] = ''; $st['orders_edge'] = array(); $st['search_edge'] = array(); $st['orders'] = 0; $st['searches'] = 0;
        }
        $out = array('site' => $site, 'time' => time(), 'orders_since' => $st['orders_since'] ?? '', 'search_since' => $st['search_since'] ?? '',
            'orders' => (int) ($st['orders'] ?? 0), 'searches' => (int) ($st['searches'] ?? 0), 'resent' => 213);
        $db = isset($stores[$site]) ? self::Database($stores[$site]) : null;
        if ($db === null) {
            $out['error'] = isset($stores[$site]) ? 'its database was not found among the owner\'s databases' : 'no Magento store found in its folder';
            elk::WriteFile($file, json_encode($out), 0640, 'sentora');
            return 'store: ' . $site . ': ' . $out['error'] . '.';
        }
        $t = function ($name) use ($db) {
            return '`' . $db['db'] . '`.`' . $db['prefix'] . $name . '`';
        };
        $lines = array();
        try {
            // orders changed since the last run (the first run: the last year), oldest first
            $since = $out['orders_since'] !== '' ? $out['orders_since'] : gmdate('Y-m-d H:i:s', time() - 365 * 86400);
            $q = $zdbh->prepare("SELECT o.entity_id, o.increment_id, o.state, o.status, o.base_grand_total, o.grand_total, o.order_currency_code, o.base_currency_code,
                    o.total_qty_ordered, o.total_item_count, o.customer_is_guest, o.store_id, o.created_at, o.updated_at, a.country_id, a.region
                FROM " . $t('sales_order') . " o LEFT JOIN " . $t('sales_order_address') . " a ON a.parent_id = o.entity_id AND a.address_type = 'billing'
                WHERE o.updated_at >= :s ORDER BY o.updated_at, o.entity_id LIMIT " . self::BATCH);
            $q->execute(array(':s' => $since));
            $orders = $q->fetchAll(PDO::FETCH_ASSOC);
            $ids = array();
            $edge = isset($st['orders_edge']) && is_array($st['orders_edge']) ? $st['orders_edge'] : array();
            $out['orders_edge'] = $edge;
            foreach ($orders as $o) {
                // (orders at the last run's exact time were sent then: only new ones at that time go)
                if ((string) $o['updated_at'] === $since && in_array((int) $o['entity_id'], $edge, true)) {
                    continue;
                }
                if ((string) $o['updated_at'] !== $out['orders_since']) {
                    $out['orders_edge'] = array();
                }
                $out['orders_edge'][] = (int) $o['entity_id'];
                $ids[] = (int) $o['entity_id'];
                $lines[] = array('shop-order', $o['created_at'], array('id' => (int) $o['entity_id'], 'number' => (string) $o['increment_id'],
                    'state' => (string) $o['state'], 'status' => (string) $o['status'], 'total' => (float) $o['grand_total'], 'base_total' => (float) $o['base_grand_total'],
                    'currency' => (string) $o['order_currency_code'], 'base_currency' => (string) $o['base_currency_code'], 'qty' => (float) $o['total_qty_ordered'],
                    'items' => (int) $o['total_item_count'], 'guest' => (bool) $o['customer_is_guest'], 'store' => (int) $o['store_id'],
                    'country' => (string) $o['country_id'], 'region' => mb_substr((string) $o['region'], 0, 80, 'UTF-8'), 'updated' => (string) $o['updated_at']));
                $out['orders_since'] = (string) $o['updated_at'];
            }
            if ($ids) {
                $q = $zdbh->query("SELECT i.item_id, i.order_id, i.sku, i.name, i.qty_ordered, i.base_row_total, o.created_at, o.status, o.increment_id
                    FROM " . $t('sales_order_item') . " i JOIN " . $t('sales_order') . " o ON o.entity_id = i.order_id
                    WHERE i.parent_item_id IS NULL AND i.order_id IN (" . implode(',', $ids) . ")");
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $i) {
                    $lines[] = array('shop-item', $i['created_at'], array('id' => (int) $i['item_id'], 'order' => (int) $i['order_id'], 'number' => (string) $i['increment_id'],
                        'sku' => mb_substr((string) $i['sku'], 0, 64, 'UTF-8'), 'name' => mb_substr((string) $i['name'], 0, 200, 'UTF-8'),
                        'qty' => (float) $i['qty_ordered'], 'total' => (float) $i['base_row_total'], 'status' => (string) $i['status']));
                }
            }
            // Magento's search list: the searches changed since the last run
            $since = $out['search_since'] !== '' ? $out['search_since'] : '1970-01-01 00:00:00';
            $q = $zdbh->prepare("SELECT query_id, query_text, num_results, popularity, store_id, updated_at FROM " . $t('search_query') . "
                WHERE updated_at >= :s ORDER BY updated_at, query_id LIMIT 2000");
            $q->execute(array(':s' => $since));
            $sedge = isset($st['search_edge']) && is_array($st['search_edge']) ? $st['search_edge'] : array();
            $out['search_edge'] = $sedge;
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
                if ((string) $s['updated_at'] === $since && in_array((int) $s['query_id'], $sedge, true)) {
                    continue;
                }
                if ((string) $s['updated_at'] !== $out['search_since']) {
                    $out['search_edge'] = array();
                }
                $out['search_edge'][] = (int) $s['query_id'];
                $lines[] = array('shop-search', $s['updated_at'], array('id' => (int) $s['query_id'], 'term' => mb_substr(mb_strtolower((string) $s['query_text'], 'UTF-8'), 0, 100, 'UTF-8'),
                    'results' => (int) $s['num_results'], 'popularity' => (int) $s['popularity'], 'store' => (int) $s['store_id']));
                $out['search_since'] = (string) $s['updated_at'];
            }
        } catch (Exception $e) {
            $out['error'] = 'the store\'s tables could not be read (' . mb_substr($e->getMessage(), 0, 120, 'UTF-8') . ')';
            elk::WriteFile($file, json_encode($out), 0640, 'sentora');
            return 'store: ' . $site . ': ' . $out['error'] . '.';
        }
        if (!$lines) {
            unset($out['error']);
            elk::WriteFile($file, json_encode($out), 0640, 'sentora');
            return '';
        }
        if (!self::Send($site, $lines)) {
            // (not sent: the same changes are read again next run)
            $st['error'] = 'the node did not answer';
            $st['time'] = time();
            elk::WriteFile($file, json_encode($st), 0640, 'sentora');
            return 'store: the node did not answer.';
        }
        $n = array_count_values(array_map(function ($l) { return $l[0]; }, $lines));
        $out['orders'] += $n['shop-order'] ?? 0;
        $out['searches'] += $n['shop-search'] ?? 0;
        unset($out['error']);
        elk::WriteFile($file, json_encode($out), 0640, 'sentora');
        return 'store ' . $site . ': ' . ($n['shop-order'] ?? 0) . ' order(s), ' . ($n['shop-search'] ?? 0) . ' search(es) sent.';
    }

    /**
     * Send the lines to the node (one connection).
     *
     * @param string $site
     * @param array $lines array(kind, time (the store's UTC), fields)
     * @return bool
     */
    static function Send($site, array $lines)
    {
        $node = elk::Node();
        $ctx = stream_context_create(array('ssl' => array('local_cert' => elk::DIR . '/panel.crt', 'local_pk' => elk::DIR . '/panel.key',
            'cafile' => elk::DIR . '/node-ca.crt', 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => elk::NODE_NAME, 'allow_self_signed' => false)));
        $host = filter_var($node['addr'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $node['addr'] . ']' : $node['addr'];
        $c = @stream_socket_client('tls://' . $host . ':' . elk::PORT, $no, $str, 10, STREAM_CLIENT_CONNECT, $ctx);
        if ($c === false) {
            return false;
        }
        $panel = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ctrl_options::GetSystemOption('sentora_domain'));
        foreach ($lines as $l) {
            $ts = strtotime($l[1] . ' UTC');
            $line = json_encode(array('@timestamp' => gmdate('Y-m-d\TH:i:s\Z', $ts ?: time()), 'sentora_host' => $panel, 'sentora_log' => $l[0],
                'sentora_domain' => $site, 'message' => json_encode($l[2], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            if (@fwrite($c, $line) === false) {
                fclose($c);
                return false;
            }
        }
        // (Logstash reads what it was sent only while the connection is open: a close right after a long write loses
        // the end. The node never answers on 6514, so this waits until it has had time to read, then closes.)
        @fflush($c);
        stream_set_timeout($c, 1 + (int) ceil(count($lines) / 400));
        @fread($c, 1);
        fclose($c);
        return true;
    }

    /**
     * For the page: what the daemon last did.
     *
     * @return array
     */
    static function State()
    {
        $j = json_decode((string) @file_get_contents(elk::STATE_DIR . '/' . self::STATE), true);
        return is_array($j) ? $j : array();
    }
}
