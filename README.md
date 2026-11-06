# Sentora-2027 installer files

Service configuration, database setup and build sources used by the Sentora-2027 installer for **Ubuntu 26.04**.

![Ubuntu](https://img.shields.io/badge/Ubuntu-26.04-E95420?logo=ubuntu&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-GPLv3-blue)

This repository ([RusTus/sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027), branch `master2027`) becomes `sentora-installers-2027.zip`. The panel itself is in [RusTus/sentora-core-2027](https://github.com/RusTus/sentora-core-2027). The installer (`sentora2027-ub26.04`) downloads it with the panel and copies these files into place. It isn't run on its own.

> [!NOTE]
> This is a private build of Sentora for Ubuntu 26.04 (PHP 8.5, MySQL 8.4, Apache 2.4, Postfix 3.10, Dovecot 2.4, BIND 9.20, ProFTPD 1.3.9). It downloads from these GitHub releases only, not from sentora.org.

## Release files

The installer downloads from the GitHub releases of both repositories, from the tag set by `SENTORA_RELEASE_TAG` at its top. Both releases use the same tag.

| Release | File | What it is |
| --- | --- | --- |
| [sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027/releases) | `sentora2027-ub26.04` | The installer |
| | `sentora-installers-2027.zip` | This repository |
| | `sentora-php-8.2-ubuntu26.04-amd64.tar.gz` to `8.4` | Optional extra PHP versions (a missing file means that version is not installed) |
| [sentora-core-2027](https://github.com/RusTus/sentora-core-2027/releases) | `sentora-core-2027.zip` | The panel, with phpMyAdmin 5.2 and Roundcube 1.7 |

Every download is checked against the SHA-256 GitHub lists for it. To download from your own web server instead, put all the files in one folder and set `SENTORA_DOWNLOAD_URL` at the top of the installer.

To build this repository's zip from a clone:

```bash
git archive --prefix=sentora-installers-2027/ -o sentora-installers-2027.zip master2027
```

## Installing

On a clean Ubuntu 26.04 server, as root:

```bash
wget https://github.com/RusTus/sentora-installers-2027/releases/download/2025.6/sentora2027-ub26.04
bash sentora2027-ub26.04
```

Or without questions:

```bash
bash sentora2027-ub26.04 -t America/Chicago -d panel.example.com -i public
```

Every generated password is shown at the end and saved in `/root/passwords.txt`.

> [!IMPORTANT]
> Upgrading an existing Sentora or ZPanel server isn't supported. The installer stops if it finds one. It also stops if the server's clock is more than 2 minutes off.

## What gets installed

| Service | Details |
| --- | --- |
| Panel and webmail | HTTPS only (port 80 redirects), self-signed certificate in `/etc/sentora/configs/ssl/` until Sencrypt issues a Let's Encrypt one |
| Mail | Postfix (25, 465, 587), Dovecot (993, 995, and 143/110 with STARTTLS, 4190 ManageSieve), Roundcube with filters and Out of Office, SpamAssassin and ClamAV |
| Groupware | SOGo 5.12 at `/SOGo`: webmail, calendars and contacts (CalDAV/CardDAV), ActiveSync for phones and Outlook |
| DNS | BIND 9.20 |
| FTP | ProFTPD with FTPS required (explicit TLS on port 21) |
| PHP | 8.5 for the panel and every domain by default; 8.2, 8.3 and 8.4 in `/opt/sentora-php/`, chosen per domain or subdomain |
| Statistics | Matomo 5 and GoAccess 1.12 (built at install from `preconf/goaccess`); Webalizer and Visitor Stats as add-on packages from the module repository |
| Protection | fail2ban for SSH, mail, webmail, FTP and the panel (`/etc/fail2ban/jail.d/sentora.conf`) |

> [!WARNING]
> PHP 8.2 gets no security fixes after 31 December 2026. Move sites to a newer version before then.

## Security layout

- The panel (with phpMyAdmin and webmail) runs as its own user, `sentora`, in the PHP-FPM pool `/etc/php/8.5/fpm/pool.d/sentora.conf`.
- Every hosting account runs its PHP as its own Linux user (`web<account id>`) in its own PHP-FPM pool. Its folder belongs to that user; access lists give the panel read-write and Apache read-only, and nobody else anything. FTP logins and cron jobs run as the account's user too.
- `/etc/sentora/panel` and `/etc/sentora/configs` belong to root. The panel writes only `etc/tmp` and `/var/lib/sentora`; the root daemon applies queued changes within 5 minutes.
- `zsudo` runs a fixed list of reloads only, for root and the panel user.
- Logins lock for 15 minutes after 5 wrong passwords for an account, or 20 from one address. Two-factor login is turned on in **Sentora Config**.

To unblock an address in fail2ban:

```bash
fail2ban-client set <jail> unbanip <address>
```

## Repository layout

| Path | Contents |
| --- | --- |
| `preconf/sentora-install/sql/` | The panel's database (`sentora_core.sql`) and the Postfix and ProFTPD databases |
| `preconf/apache/`, `postfix/`, `dovecot2/`, `bind/`, `proftpd/`, `sogo/` ... | Configuration templates for each service |
| `preconf/fail2ban/` | fail2ban jails and filters |
| `preconf/goaccess/` | GoAccess source, built at install |
| `preconf/bin/zsudo.c` | `zsudo` source, built at install |
| `preconf/php-build/` | Scripts to build and update the extra PHP versions |
| `sentora-repo/` | The add-on module repository (PHP-Adminer, Webalizer Stats, Visitor Stats), read by Repo Browser from GitHub; see its [README](sentora-repo/README.md) |

## License

GNU General Public License v3. See [LICENSE.md](LICENSE.md). Changes from Sentora 2.0.2 are listed in the [change log](CHANGELOG.md). Report problems in the [core repository's issues](https://github.com/RusTus/sentora-core-2027/issues).
