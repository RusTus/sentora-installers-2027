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

## Upgrades

Upgrading an existing Sentora or ZPanel server is not supported. The installer
stops if it finds one.
