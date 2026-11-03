Sentora installer files for Ubuntu 26.04
========================================

This is a private build of Sentora 2.0.2 for Ubuntu 26.04 (PHP 8.5, MySQL 8.4,
Apache 2.4, Postfix 3.10, Dovecot 2.4, BIND 9.20, ProFTPD 1.3.9). It is not the
upstream Sentora installer and does not download anything from GitHub or
sentora.org.

## Files

* `sentora_install-ai.sh` - the installer (kept next to these zips)
* `sentora-core-2_0_2.zip` - the panel, with phpMyAdmin 5.2.3 and Roundcube 1.7.5
* `sentora-installers-master.zip` - this folder: the `preconf/` configuration files
* `sentora-php-8.1-ubuntu26.04-amd64.tar.gz` ... `sentora-php-8.4-ubuntu26.04-amd64.tar.gz` -
  extra PHP versions (PHP-FPM) that domains can choose. Optional: a missing
  file just means that version is not installed.

Both zips are downloaded by the installer from
`http://hembackup1.hemsaw.local/installers/` (set at the top of the installer).

## Install

On a clean Ubuntu 26.04 server, as root:

```
bash sentora_install-ai.sh
```

or unattended:

```
bash sentora_install-ai.sh -t America/Chicago -d panel.example.com -i public
```

Passwords are saved in `/root/passwords.txt`.

## What gets installed

* Panel and webmail over HTTPS (port 80 redirects), self-signed certificate in
  `/etc/sentora/configs/ssl/` (see the README there to replace it)
* Mail: Postfix (25, 465, 587) and Dovecot (993, 995, 143/110 with STARTTLS,
  4190 ManageSieve), Roundcube webmail with filters and Out of Office
* DNS: BIND 9.20
* FTP: ProFTPD with required FTPS (explicit TLS on port 21)
* Webalizer statistics, built from `preconf/webalizer/` (not packaged on 26.04)
* PHP 8.5 for the panel and by default for every domain; PHP 8.1, 8.2, 8.3 and
  8.4 (PHP-FPM, in `/opt/sentora-php/`) that each domain or subdomain can choose
  in the Domains and Sub Domains modules. The admin's PHP Versions module turns
  each extra version on or off. PHP 8.1 gets no security fixes since 31 Dec
  2025 and PHP 8.2 none after 31 Dec 2026.

* File Manager (File Management): each user's own hosting folder in the
  browser (upload, edit, zip/unzip). Built on Tiny File Manager, bundled with
  all its libraries (no CDN), behind the panel login and confined to that
  user's folder. See modules/file_manager/app/VERSION.
* Visitor Stats (Advanced): per-domain visitor reports from Visitors 0.7,
  built from preconf/visitors/ (not packaged on 26.04), shown only to the
  domain's owner.
* Apache Status (Admin > Server Admin, administrators only): Pimped Apache
  Status 2.04.17 reading this server's own /server-status (mod_status).
* System Log Viewer (Admin > Server Admin, administrators only): follow the
  panel, Apache, mail, FTP, DNS, PHP-FPM and domain logs live in the browser.
* Webalizer and Visitor reports are kept in /var/sentora/stats/ (outside the
  web root) and shown only to the domain's owner.
* Repo Browser (Admin > Server Admin): add module repositories and install,
  update or remove add-on modules with zppy. Modules that ship with the panel
  are protected. Installing a module runs its code, so only add trusted
  repositories; the two default sentora.org repositories may be offline.

## Security layout

* The panel (with phpMyAdmin and webmail) runs as its own user, `sentora`,
  through the PHP-FPM pool `/etc/php/8.5/fpm/pool.d/sentora.conf`. Hosted
  sites run as `www-data`, so a site can not read the panel's database
  password, sessions or configs, change its code, or run `zsudo`.
* `/etc/sentora/panel` and `/etc/sentora/configs` belong to root. The panel
  writes only `etc/tmp`, plus `/var/lib/sentora` (sessions, uploads, cron
  jobs). Repo Browser and Module Admin queue module changes; the root daemon
  makes them within 5 minutes.
* Logins lock for 15 minutes after 5 wrong passwords for an account or 20 from
  one address. "Remember me" is a random token (stored hashed, replaced on use).
* `zsudo` runs a fixed list of reloads only, for root and the panel user.

## Upgrades

Upgrading an existing Sentora or ZPanel server is not supported. The installer
stops if it finds one.
