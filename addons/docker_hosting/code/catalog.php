<?php

/**
 * Docker Hosting's catalog: the apps a customer can start. Each is one container from its maker's
 * own image (full name with registry, so no registry is guessed), with the port it listens on
 * inside, the folders that hold its data (kept in volumes of their own), settings it needs, and the
 * least memory it runs with. {domain} in a setting is replaced by the container's address.
 *
 * Every app starts behind a setup password (the node's proxy asks for it) until its owner has set
 * it up and opens it to the public: the first visitor of a fresh Gitea, Nextcloud or Uptime Kuma
 * would otherwise become its administrator.
 *
 * Besides these, the panel's administrators can add apps of their own ("my-..." ids, table
 * x_dh_apps, dh::SaveApp): the same fields, the same checks as the node makes, and they run with
 * the same limits (rootless, no new privileges, only their one port on 127.0.0.1 behind Caddy).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-11
 */
class dh_catalog
{
    /**
     * The apps.
     *
     * @return array<string, array{name:string, desc:string, image:string, port:int, volumes:array<int,string>, env:array<string,string>, mem:int, minmem:int, cpu:int, disk:int, web:string}>
     *   mem/minmem: MB; cpu: hundredths of a CPU; disk: MB.
     */
    static function Apps()
    {
        return array_merge(self::BuiltIn(), self::Custom());
    }

    /** @var array|null The administrators' own apps, read once per request. */
    static $custom = null;

    /**
     * The administrators' own apps (marked 'custom' => true, 'by' => the account that added it).
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return array
     */
    static function Custom()
    {
        global $zdbh;
        if (self::$custom !== null) {
            return self::$custom;
        }
        dh::Ensure();
        $out = array();
        foreach ($zdbh->query("SELECT * FROM x_dh_apps ORDER BY ap_name_vc")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $env = json_decode((string) ctrl_secret::Decrypt((string) $r['ap_env_tx']), true);
            $vol = json_decode((string) $r['ap_volumes_tx'], true);
            $out[(string) $r['ap_id_vc']] = array(
                'name' => (string) $r['ap_name_vc'], 'desc' => (string) $r['ap_desc_vc'], 'image' => (string) $r['ap_image_vc'], 'port' => (int) $r['ap_port_in'],
                'volumes' => is_array($vol) ? array_values(array_filter($vol, 'is_string')) : array(), 'env' => is_array($env) ? $env : array(),
                'mem' => (int) $r['ap_mem_in'], 'minmem' => (int) $r['ap_minmem_in'], 'cpu' => (int) $r['ap_cpu_in'], 'disk' => (int) $r['ap_disk_in'],
                'web' => (string) $r['ap_web_vc'], 'custom' => true, 'by' => (int) $r['ap_by_fk'],
            );
        }
        return self::$custom = $out;
    }

    /**
     * The apps that come with the add-on.
     *
     * @return array
     */
    static function BuiltIn()
    {
        return array(
            'uptime-kuma' => array(
                'name' => 'Uptime Kuma',
                'desc' => 'Watches websites and services and tells you when one goes down.',
                'image' => 'docker.io/louislam/uptime-kuma:1',
                'port' => 3001,
                'volumes' => array('/app/data'),
                'env' => array(),
                'mem' => 256, 'minmem' => 128, 'cpu' => 50, 'disk' => 1024,
                'web' => 'https://github.com/louislam/uptime-kuma',
            ),
            'gitea' => array(
                'name' => 'Gitea',
                'desc' => 'Git hosting with issues, pull requests and a wiki (over HTTPS; no SSH).',
                'image' => 'docker.io/gitea/gitea:1',
                'port' => 3000,
                'volumes' => array('/data'),
                'env' => array('GITEA__server__DOMAIN' => '{domain}', 'GITEA__server__ROOT_URL' => 'https://{domain}/', 'GITEA__server__DISABLE_SSH' => 'true'),
                'mem' => 512, 'minmem' => 256, 'cpu' => 100, 'disk' => 5120,
                'web' => 'https://about.gitea.com',
            ),
            'vaultwarden' => array(
                'name' => 'Vaultwarden',
                'desc' => 'A password manager server that the Bitwarden apps can use.',
                'image' => 'docker.io/vaultwarden/server:latest',
                'port' => 80,
                'volumes' => array('/data'),
                'env' => array('DOMAIN' => 'https://{domain}'),
                'mem' => 256, 'minmem' => 128, 'cpu' => 50, 'disk' => 1024,
                'web' => 'https://github.com/dani-garcia/vaultwarden',
            ),
            'nextcloud' => array(
                'name' => 'Nextcloud',
                'desc' => 'Files, calendars and contacts in your own cloud (with its built-in SQLite database).',
                'image' => 'docker.io/library/nextcloud:stable-apache',
                'port' => 80,
                'volumes' => array('/var/www/html'),
                'env' => array('NEXTCLOUD_TRUSTED_DOMAINS' => '{domain}', 'OVERWRITEPROTOCOL' => 'https', 'OVERWRITEHOST' => '{domain}', 'TRUSTED_PROXIES' => '10.0.0.0/8 127.0.0.0/8 169.254.0.0/16'),
                'mem' => 1024, 'minmem' => 512, 'cpu' => 100, 'disk' => 10240,
                'web' => 'https://nextcloud.com',
            ),
        );
    }

    /**
     * One app, or null.
     *
     * @param string $id
     * @return array|null
     */
    static function App($id)
    {
        $a = self::Apps();
        return is_string($id) && isset($a[$id]) ? $a[$id] : null;
    }
}
