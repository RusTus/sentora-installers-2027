# Sentora-2027 installer files

Service configuration, database setup and build sources used by the Sentora-2027 installers for **Ubuntu 26.04**, **Debian 13**, **AlmaLinux 10**, **Rocky Linux 10**, **Red Hat Enterprise Linux 10**, **Fedora 45** and **Windows** (WSL).

**Website:** [sentorareloaded2027.github.io](https://sentorareloaded2027.github.io): screenshots, the install command for every system, and the Windows setup.

[![Website](https://img.shields.io/badge/website-sentorareloaded2027.github.io-1f7fae)](https://sentorareloaded2027.github.io)

![Ubuntu](https://img.shields.io/badge/Ubuntu-26.04-E95420?logo=ubuntu&logoColor=white)
![Debian](https://img.shields.io/badge/Debian-13-A81D33?logo=debian&logoColor=white)
![AlmaLinux](https://img.shields.io/badge/AlmaLinux-10-0F4266?logo=almalinux&logoColor=white)
![Rocky Linux](https://img.shields.io/badge/Rocky_Linux-10-10B981?logo=rockylinux&logoColor=white)
![RHEL](https://img.shields.io/badge/RHEL-10-EE0000?logo=redhat&logoColor=white)
![Fedora](https://img.shields.io/badge/Fedora-45-51A2DA?logo=fedora&logoColor=white)
![Windows](https://img.shields.io/badge/Windows-11_%2F_Server_2025-0078D4?logo=windows&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-GPLv3-blue)

This repository ([RusTus/sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027), branch `master2027`) becomes `sentora-installers-2027.zip`. The panel itself is in [RusTus/sentora-core-2027](https://github.com/RusTus/sentora-core-2027). The installer (`sentora2027-ub26.04` for Ubuntu 26.04, `sentora2027-deb13` for Debian 13, `sentora2027-alma10` for AlmaLinux 10) downloads it with the panel and copies these files into place. It isn't run on its own.

> [!NOTE]
> This is a private build of Sentora-2027 for Ubuntu 26.04 (PHP 8.5, MySQL 8.4, Apache 2.4, Postfix 3.10, Dovecot 2.4, BIND 9.20, ProFTPD 1.3.9) and Debian 13 (Debian's own packages: PHP 8.4, MariaDB 11.8, Apache 2.4, Postfix 3.10, Dovecot 2.4, BIND 9.20, ProFTPD 1.3.8). It downloads from these GitHub releases only, not from sentora.org.

## Release files

The installer downloads from the GitHub releases of both repositories with the tag set by `SENTORA_RELEASE_TAG` at its top: `current`. A new version replaces the files on the `current` release in both repositories.

| Release | File | What it is |
| --- | --- | --- |
| [sentora-installers-2027](https://github.com/RusTus/sentora-installers-2027/releases) | `sentora2027-ub26.04` | The installer for Ubuntu 26.04 |
| | `sentora2027-deb13` | The installer for Debian 13 |
| | `sentora2027-alma10` | The installer for AlmaLinux 10, Rocky Linux 10 and Red Hat Enterprise Linux 10 |
| | `sentora2027-fedora` | The installer for Fedora 45 |
| | `sentora2027-wsl.ps1` | Sentora on Windows (WSL 2): a PowerShell script that sets up its own Linux system |
| | `sentora2027-install` | One install command for all of them: picks the right installer |
| | `sentora-sogo-alma10-amd64.tar.gz` | SOGo for AlmaLinux 10 (built by **Actions > SOGo bundle**) |
| | `sentora-installers-2027.zip` | This repository |
| | `sentora-php-8.2-ubuntu26.04-amd64.tar.gz` to `8.4` | Optional extra PHP versions for Ubuntu 26.04 (a missing file means that version is not installed) |
| | `sentora-php-8.2-debian13-amd64.tar.gz`, `8.3`, `8.5`, `7.1` | The same for Debian 13 (built by **Actions > PHP bundles**) |
| | `sentora-php-8.2-alma10-amd64.tar.gz`, `8.4`, `8.5`, `7.1` | The same for AlmaLinux 10 (built by **Actions > PHP bundles**, system alma10) |
| [sentora-core-2027](https://github.com/RusTus/sentora-core-2027/releases) | `sentora-core-2027.zip` | The panel, with phpMyAdmin 5.2 and Roundcube 1.7 |

Every download is checked against the SHA-256 GitHub lists for it. To download from your own web server instead, put all the files in one folder and set `SENTORA_DOWNLOAD_URL` at the top of the installer.

To build this repository's zip from a clone:

```bash
git archive --prefix=sentora-installers-2027/ -o sentora-installers-2027.zip master2027
```

To publish a new version, push to `master2027` and run **Actions > Release > Run workflow**. It first installs Sentora from `master2027` of both repositories on Ubuntu 26.04, Debian 13, AlmaLinux 10, Rocky Linux 10 and Fedora 45 (Actions > Install test; nothing is published unless all pass) and builds `Sentora2027Setup.exe` on a Windows machine. It builds `sentora-installers-2027.zip`, every installer (`sentora2027-install`, `-ub26.04`, `-deb13`, `-alma10`, `-fedora`, `-wsl.ps1`) from `master2027`, replaces the files of the same names on the `current` release and prints their SHA-256 in the job log. It also takes `sentora-migrate-export`, `sentora-smoke-test` and `sentora-web-probe` from the core and `sentora-tls-check.sh` from here, uploads only the files that changed, rewrites the release page (title `Sentora-<VERSION>`, What's new since the previous version and this README), and makes one release per version (tag `v<VERSION>`) with What's new, the whole change log and a copy of every file. The Debian 13 PHP bundles are built and put on the release by **Actions > PHP bundles > Run workflow** (in a Debian 13 container); the Ubuntu 26.04 bundles and `testssl_sh-3_2_5.zip` are still replaced by hand. The core zip has the same workflow in [sentora-core-2027](https://github.com/RusTus/sentora-core-2027).

## Installing

The same command on every supported system (Ubuntu 26.04, Debian 13, AlmaLinux 10, Rocky Linux 10, Red Hat
Enterprise Linux 10, Fedora 45), on a
clean server, as root. It finds out which system this is, downloads that system's installer below
(checked against its SHA-256) and runs it; `--check` only downloads and checks it.

```bash
wget -O sentora2027-install https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora2027-install
bash sentora2027-install
```

On Windows 11 (22H2 or newer) or Windows Server 2025, in PowerShell as administrator. It sets up WSL and its own Linux system (Debian 13 or Ubuntu 26.04) named Sentora, runs the installer there, opens Sentora's ports in the Windows firewall, starts it with Windows, and asks whether Windows Defender should leave Sentora's disk image alone (the administrator decides). `-Status`, `-Backup <folder>` and `-Uninstall` do the rest. SELinux and AppArmor do not run in WSL, and Windows updates restart the PC: for production hosting a Linux server is the better home.

```powershell
Invoke-WebRequest -OutFile sentora2027-wsl.ps1 https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora2027-wsl.ps1
powershell -ExecutionPolicy Bypass -File .\sentora2027-wsl.ps1
```

Or the setup program: [Sentora2027Setup.exe](https://github.com/RusTus/sentora-installers-2027/releases/download/current/Sentora2027Setup.exe) (built from `windows/sentora2027-setup.iss` by **Actions > Windows setup** on every release). It runs the same script, registers **Sentora 2027** in Settings > Apps and the Start menu, updates in place when a newer one runs, and uninstalls everything from Settings > Apps (backups stay). Both ways leave Sentora Status, Backup and Uninstall in Sentora's folder, a Sentora Panel shortcut on every desktop and a tray icon (green while the panel answers). It is not signed: SmartScreen shows "Windows protected your PC" once (More info > Run anyway).

Or Docker: the ready-made image `ghcr.io/rustus/sentora2027` (Debian 13 inside; `docker/` in this repository) installs Sentora the first time a container starts, so every container gets its own passwords. It runs without `--privileged`, and is built, installed, checked and smoke-tested by **Actions > Docker image** on every release, and published only when it passes. The command and what each option is for: the core's README, [In Docker](https://github.com/RusTus/sentora-core-2027#in-docker).

Or each system's own installer. On a clean Ubuntu 26.04 server, as root:

```bash
wget -O sentora2027-ub26.04 https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora2027-ub26.04
bash sentora2027-ub26.04
```

On a clean Debian 13 server (a minimal install with only the SSH server is fine), as root. A minimal Debian has no wget yet:

```bash
apt-get update && apt-get install -y wget
```

```bash
wget -O sentora2027-deb13 https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora2027-deb13
bash sentora2027-deb13
```

On Debian everything comes from Debian's own packages (PHP 8.4, MariaDB, Apache, Postfix, Dovecot, BIND, SOGo), so `apt-get upgrade` and Debian's daily security updates keep them current. The panel calls MariaDB "MySQL", as it is compatible with it.

On a clean AlmaLinux 10 (or Rocky Linux 10) server, as root:

```bash
dnf install -y wget
```

```bash
wget -O sentora2027-alma10 https://github.com/RusTus/sentora-installers-2027/releases/download/current/sentora2027-alma10
bash sentora2027-alma10
```

On Fedora 45, `sentora2027-fedora`: everything from Fedora's own packages (PHP 8.5, MariaDB 12, Apache, Postfix, Dovecot 2.4, BIND 9.18, ProFTPD, SpamAssassin, ClamAV), SELinux on, and the extra PHP versions and SOGo built for Fedora 45 on GitHub. Fedora has a new release every six months: each one is added when the install test passes on it.

On Red Hat Enterprise Linux 10 the same installer works on a registered server (`subscription-manager register`; Red Hat's free developer subscription works too): it switches on CodeReady Builder and adds EPEL (its release package from Fedora, installed only when Fedora's EPEL key signed it).

On AlmaLinux everything comes from AlmaLinux's own packages and EPEL (PHP 8.3, MariaDB 10.11, Apache, Postfix, Dovecot 2.3, BIND 9.18, ProFTPD, SpamAssassin, ClamAV, fail2ban), kept current by `dnf upgrade` and dnf-automatic's daily security updates. SELinux stays on (enforcing): the installer labels Sentora's folders and switches on what the services need; firewalld, when it runs, gets Sentora's ports. SOGo (groupware and ActiveSync) comes from Sentora's own build (AlmaLinux has no SOGo package): **Actions > SOGo bundle** builds it from SOGo's free sources into `/opt/sentora-sogo` (see `preconf/sogo-build/README`), and the installer downloads it, checked against its SHA-256.

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
- `zsudo` runs a fixed list of actions only, for root and the panel user. Its source is in the core (`bin/zsudo.c`): the installer builds it, and `sentora-update` builds it again when an update changes it.
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
| `preconf/php-build/` | Scripts to build and update the extra PHP versions |
| `sentora-repo/` | The add-on module repository (QuickFig, Apps, Payment Plans, PHP-Adminer, Webalizer Stats, Visitor Stats, Log Forwarder), read by Repo Browser from GitHub; see its [README](sentora-repo/README.md) |
| `docker-node/` | Docker Hosting's node installer (`sentora2027-docker-node`, every supported system) and its agent |
| `backup-node/` | The Sentora backup node installer (`sentora2027-backup-node`) and its gate, for the panel's off-server backup |
| `feedback-server/` | The feedback server (PHP and SQLite; upload its `public_html/` as a website): panels' daily customer-feedback reports, the Sentora-2027 add-ons' download counts and their ratings and comments from Repo Browser, and a statistics page; see its [README](feedback-server/README.md) |
| `feedback-endpoint.txt` | Where panels send that report: the statistics server's address, read by every panel once a day (none: nothing is sent) |

## License

GNU General Public License v3. See [LICENSE.md](LICENSE.md). Changes from Sentora 2.0.2 are listed in the [change log](CHANGELOG.md). Report problems in the [core repository's issues](https://github.com/RusTus/sentora-core-2027/issues).
