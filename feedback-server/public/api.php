<?php
/**
 * Sentora-2027 feedback server: where panels send their daily report (POST, JSON).
 *
 * Written by RusTus, 2026-11-09
 */
require __DIR__ . '/../lib.php';
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('{"error":"POST a report"}');
}
$raw = file_get_contents('php://input', false, null, 0, 32769);
if (!is_string($raw) || strlen($raw) > 32768) {
    http_response_code(413);
    exit('{"error":"too large"}');
}
$r = fb_clean(json_decode($raw, true));
if ($r === null) {
    http_response_code(400);
    exit('{"error":"not a report"}');
}
$c = fb_config();
$ip = fb_remote_ip();
$db = fb_db();
$q = $db->prepare('SELECT COUNT(*) FROM reports WHERE remote_ip = ? AND at > ?');
$q->execute(array($ip, time() - 3600));
if ((int) $q->fetchColumn() >= (int) $c['per_hour']) {
    http_response_code(429);
    exit('{"error":"too many"}');
}
$now = time();
// where in the world: the address the report came from, else the panel's own (when public)
$country = fb_country($ip);
if ($country === '' && $r['public_ip'] !== '') {
    $country = fb_country($r['public_ip']);
}
$json = json_encode($r);
$db->beginTransaction();
$db->prepare('INSERT INTO reports (install, at, remote_ip, report) VALUES (?, ?, ?, ?)')->execute(array($r['id'], $now, $ip, $json));
$db->prepare('INSERT INTO installs (id, first_seen, last_seen, reports, remote_ip, panel, os, arch, virt, cpu, cores, memory, disk, public_ip, internal_ip, report, country, load1, accounts, bandwidth)
    VALUES (:id, :t, :t, 1, :ip, :panel, :os, :arch, :virt, :cpu, :cores, :mem, :disk, :pub, :int, :rep, :cc, :load, :acc, :bw)
    ON CONFLICT(id) DO UPDATE SET last_seen = :t, reports = reports + 1, remote_ip = :ip, panel = :panel, os = :os, arch = :arch, virt = :virt, cpu = :cpu,
    cores = :cores, memory = :mem, disk = :disk, public_ip = :pub, internal_ip = :int, report = :rep, country = :cc, load1 = :load, accounts = :acc, bandwidth = :bw')
    ->execute(array(':id' => $r['id'], ':t' => $now, ':ip' => $ip, ':panel' => $r['panel'], ':os' => $r['os'], ':arch' => $r['arch'], ':virt' => $r['virt'],
        ':cpu' => $r['cpu'], ':cores' => $r['cores'], ':mem' => $r['memory'], ':disk' => $r['disk'], ':pub' => $r['public_ip'], ':int' => $r['internal_ip'], ':rep' => $json,
        ':cc' => $country, ':load' => $r['load'][1], ':acc' => max(0, $r['counts']['accounts']), ':bw' => $r['bandwidth_month']));
// a server's oldest reports go (the newest ones stay)
$db->prepare('DELETE FROM reports WHERE install = ? AND n NOT IN (SELECT n FROM reports WHERE install = ? ORDER BY n DESC LIMIT ?)')->execute(array($r['id'], $r['id'], (int) $c['keep']));
$db->commit();
http_response_code(201);
echo '{"ok":true}';
