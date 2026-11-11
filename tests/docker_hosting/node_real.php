<?php
// Driver for .github/scripts/node-test.sh: the stand-in panel's side of a real node test.
//   node_real.php init                  install the add-on, accounts, a domain, shares; add a node: prints "<key> <code>"
//   node_real.php online                prints 1 when the node called within the last 2 minutes
//   node_real.php deploy <app> <name>   client1 starts an app at <name>.example.test: prints its ID
//   node_real.php state <id>            prints its state and note
//   node_real.php setup <id>            prints its setup password
//   node_real.php act <id> <what>       start, stop, restart, public, private, delete, logs
//   node_real.php limits <id> <cpu> <mem> <disk GB>
//   node_real.php logs <id>             prints the last logs the node sent
//   node_real.php log                   prints the add-on's log
set_error_handler(function ($no, $str, $file, $line) {
    fwrite(STDERR, "PHP problem: $str in $file:$line\n");
    exit(3);
});
$T = __DIR__;
chdir($T . '/root');
require $T . '/root/dryden/loader.inc.php';
require $T . '/root/modules/docker_hosting/code/dh.php';
$GLOBALS['DH_ME'] = 1;
$a = $argv;
switch ($a[1]) {
    case 'init':
        $zdbh->exec("INSERT INTO x_groups (ug_id_pk) VALUES (1), (2), (3)");
        $zdbh->exec("INSERT INTO x_modules (mo_folder_vc, mo_name_vc, mo_enabled_en) VALUES ('docker_hosting', 'Docker Hosting', 'true')");
        $zdbh->exec("INSERT INTO x_settings (so_name_vc, so_value_tx) VALUES ('dns_hasupdates', '')");
        $zdbh->exec("INSERT INTO x_accounts (ac_id_pk, ac_user_vc, ac_reseller_fk, ac_group_fk) VALUES (1, 'zadmin', 1, 1), (2, 'res1', 1, 2), (3, 'client1', 2, 3)");
        $zdbh->exec("INSERT INTO x_vhosts (vh_id_pk, vh_acc_fk, vh_name_vc) VALUES (10, 3, 'example.test')");
        $zdbh->exec("INSERT INTO x_dns (dn_acc_fk, dn_name_vc, dn_vhost_fk, dn_type_vc, dn_host_vc, dn_ttl_in, dn_target_vc) VALUES (3, 'example.test', 10, 'A', '@', 3600, '192.0.2.1')");
        dh::Ensure();
        $all = array_keys(dh_catalog::Apps());
        dh::SetGrant(1, 2, array('count' => 5, 'cpu' => 400, 'mem' => 4096, 'disk' => 20480, 'apps' => $all)) || die(dh::$why . "\n");
        dh::SetGrant(2, 3, array('count' => 3, 'cpu' => 200, 'mem' => 2048, 'disk' => 10240, 'apps' => $all)) || die(dh::$why . "\n");
        $code = dh::AddNode('node1', 1);
        echo dh::PanelKey() . ' ' . $code . "\n";
        break;
    case 'online':
        $n = dh::Node(1);
        echo $n && dh::Online($n) ? "1\n" : "0\n";
        break;
    case 'deploy':
        $app = dh_catalog::App($a[2]);
        $id = dh::Deploy(3, 3, array('app' => $a[2], 'label' => '', 'cpu' => $app['cpu'], 'mem' => $app['mem'], 'disk' => 2048, 'vhost' => 10, 'name' => $a[3], 'dns' => true));
        echo $id ? $id . "\n" : 'refused: ' . dh::$why . "\n";
        break;
    case 'state':
        $c = dh::Container((int) $a[2]);
        echo $c ? $c['dc_state_vc'] . ' ' . $c['dc_used_in'] . 'MB ' . $c['dc_note_vc'] . "\n" : "deleted\n";
        break;
    case 'setup':
        echo dh::SetupPassword(dh::Container((int) $a[2])) . "\n";
        break;
    case 'act':
        echo dh::Act(3, 3, (int) $a[2], $a[3]) ? "ok\n" : 'refused: ' . dh::$why . "\n";
        break;
    case 'limits':
        echo dh::Limits(3, 3, (int) $a[2], (int) $a[3], (int) $a[4], (int) $a[5] * 1024) ? "ok\n" : 'refused: ' . dh::$why . "\n";
        break;
    case 'logs':
        echo dh::Logs((int) $a[2])[0] . "\n";
        break;
    case 'log':
        foreach ($zdbh->query("SELECT lg_time_in, lg_what_vc, lg_note_vc FROM x_dh_log ORDER BY lg_id_pk")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo date('H:i:s', $r['lg_time_in']) . ' ' . $r['lg_what_vc'] . ': ' . $r['lg_note_vc'] . "\n";
        }
        foreach ($zdbh->query("SELECT jb_id_pk, jb_action_vc, jb_state_vc, jb_result_tx FROM x_dh_jobs ORDER BY jb_id_pk")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo 'job ' . $r['jb_id_pk'] . ' ' . $r['jb_action_vc'] . ' ' . $r['jb_state_vc'] . ': ' . substr((string) $r['jb_result_tx'], 0, 300) . "\n";
        }
        break;
}
