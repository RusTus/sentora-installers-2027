# Sentora for Ubuntu 26.04 — Change Log

Released 2026-11-04

## Overview

This build turns Sentora 2.0.2 (with the master installers) into a panel for Ubuntu 26.04 only, on PHP 8.5, MySQL 8.4 and Apache 2.4, and rebuilds its security from the ground up. Every hosted account now runs as its own Linux user, the panel runs as its own user, and every module was checked for cross-account access, injection and script-injection flaws.

It is delivered as seven files, served from `http://hembackup1.hemsaw.local/installers/`:

| File | What it is |
| --- | --- |
| `sentora_install-ai.sh` | The installer (Ubuntu 26.04 only) |
| `sentora-core-2_0_2.zip` | The panel: core, modules, bundled apps (phpMyAdmin, Roundcube, Matomo and others) |
| `sentora-installers-master.zip` | Service configs, SQL, build sources (Webalizer, Visitors, zsudo), fail2ban, SOGo templates |
| `sentora-php-8.1` to `8.4-ubuntu26.04-amd64.tar.gz` | Extra PHP versions built for 26.04 (four files) |

A fresh install is required: folder ownership, users and Apache's setup are created by the installer and cannot be converted from an older server.

## Platform and installer

The installer targets Ubuntu 26.04 only and refuses any other system. Everything comes from Ubuntu's own packages except the parts Ubuntu does not ship, which are bundled or built from source.

| Component | Original | Now |
| --- | --- | --- |
| Operating system | Ubuntu 16.04 to 22.04, CentOS, Debian | Ubuntu 26.04 only |
| PHP | 7.x through mod_php | 8.5 (Ubuntu) through PHP-FPM; extra versions 8.1 to 8.4 per domain |
| Database | MySQL 5.x or MariaDB | MySQL 8.4 |
| Web server | Apache 2.4, prefork engine, mod_php | Apache 2.4.66, event engine, HTTP/2, no mod_php |
| Mail | Postfix, Dovecot 2.2 | Postfix 3.10, Dovecot 2.4, SOGo 5.12 |
| DNS | BIND 9 | BIND 9.20 |
| FTP | ProFTPD, plain FTP | ProFTPD 1.3.9 with FTPS required |

**Installer behaviour**

- Downloads the core and installer zips and PHP bundles from `hembackup1`, with three retries and archive checks.
- Waits up to 30 minutes for Ubuntu's automatic updates to release the package lock, instead of failing.
- Checks the clock: turns on time sync, and corrects the clock from the download server when it is off by more than 5 minutes (apt rejects package lists from a clock that is behind).
- Stops with a clear message if the compiler or other build tools did not install.
- Detects the public IP from four services and validates it, falling back to the local IP.
- Creates a self-signed TLS certificate (RSA 3072, 10 years) used by the panel, mail and FTP until Let's Encrypt replaces it.
- Writes every generated password to `/root/passwords.txt` (mode 600).
- Passes `shellcheck` with no warnings.

## Security architecture

In the original, the panel and every hosted site ran PHP as the same user (`www-data`), the panel folder was world-writable, and a setuid helper ran any command as root, so any site could take over the server. Each layer below closes part of that.

| Layer | Original | Now |
| --- | --- | --- |
| Panel | Ran as `www-data` with every site | Own user `sentora`, own PHP-FPM pool; sessions and uploads in `/var/lib/sentora` (private) |
| Hosted accounts | All sites as `www-data` | Each account as its own user `web<id>`, own PHP-FPM pool per PHP version it uses |
| Account folders | World-readable and writable | Owned by the account; access lists give the panel read-write and Apache read-only, nobody else anything |
| Temp and sessions | Shared `/var/sentora/temp` | Each account's own `tmp` folder |
| Panel code and configs | `chmod -R 777` | Owned by root, nothing writable by anyone else |
| Secrets | `cnf/db.php` readable by every site | Database password, encryption key, webmail and phpMyAdmin configs readable by root and the panel only |
| zsudo | Ran any command as root, for anyone | Fixed list of reloads, executable by root and the panel only |
| Module installs | Panel user wrote module code the root daemon ran | Queued by the panel, made by the root daemon after re-checking |
| FTP and cron | Ran as `www-data` | Run as the account's own user |

**Login and browser protection**

- Login lockout: 5 wrong passwords for an account, or 20 from one address, within 15 minutes locks logins for 15 minutes.
- "Remember me" is a random token stored hashed and replaced on each use (it used to store the password hash in a cookie).
- Optional two-factor login (6-digit authenticator codes), switched on in Sentora Config and set up per account in My Account.
- Session cookies are `HttpOnly`, `SameSite=Lax` and `Secure` on HTTPS; session ids are refused unless the server made them, and renewed at login.
- Password-reset keys, CSRF tokens and salts come from a cryptographic random generator; reset keys work once, for one hour.
- A content security policy, frame protection and `nosniff` on every panel page.

**Server-wide**

- fail2ban blocks an address for an hour after 5 failures in 10 minutes on SSH, SMTP login, IMAP/POP3, webmail, SOGo, FTP and the panel (custom filters for Dovecot 2.4, ProFTPD and the panel).
- The logs fail2ban acts on cannot be written by hosted sites, so a forged line cannot get an address blocked.

## Security fixes in the original code

Each fix below was reproduced as an attack on the test system before the change and refused after it.

| Area | Flaw | Severity | Fix |
| --- | --- | --- | --- |
| zsudo | Any local user or site could run any command as root | Critical | Fixed allowlist of reloads; root and panel only |
| Panel files | World-writable panel code run by the root daemon | Critical | Root-owned code; panel as its own user |
| Domains, subdomains, parked domains, databases, MySQL users, mailboxes, aliases, distribution lists | Any account could delete or change another account's items by sending its id | High | Shared ownership check before every action (14 actions, 9 modules) |
| Edit pages | `?other=<id>` showed another account's details | Medium | Loaders return only the account's own records |
| DNS Manager | Records could be added to or viewed on another account's domain | High | Domain ownership checked on display, save and default records |
| Manage Clients, Packages | A reseller could create administrators, and edit or delete other resellers' clients and packages | High | Only offered groups (no Administrators for non-admins), own clients and packages only |
| Backups | Full site and database backups built in the web folder with guessable names and left there; MySQL root password on the command line | High | Private work folder, random names, password in a 0600 option file, strict login checks |
| Cron Manager | Timing field wrote any line into the crontab; owner taken from the form; `../` paths | High | Validated timing and path, owner from the session, jobs run as the account |
| Theme Manager | Theme name used as an include path, so an account could run its own PHP in the panel | High | Only installed theme folders accepted, checked wherever read |
| Sencrypt | Domain from the form used in file paths; panel certificate actions open to any account; a CSRF check commented out | High | Own domains only, panel certificate admin-only, CSRF restored |
| Password reset | Guessable keys that never expired; non-constant-time compare | Medium | Cryptographic keys, one hour, single use |
| Language setting | Saved unchecked and used in an SQL column name | Medium | Two-letter codes only, prepared query |
| DNS Config | SQL injection in "reset domain" | Medium | Prepared query |
| Module Admin | Module upload had no CSRF check; uploaded files written by the panel user | High | CSRF token; queued for the root daemon, which refuses archives with `../` paths |
| Several pages | Script injection through cron descriptions, FAQ text, group names, domain names, Domain Logs lines | Medium | Escaped output |
| Webalizer Stats | Reports in the web folder, readable by anyone who guessed the path | Medium | Reports outside the web root, owner-only viewer |

## Mail, webmail and groupware

Mail now uses TLS on every service with one certificate, and adds SOGo groupware with ActiveSync for phones and Outlook.

| Service | Ports | Change |
| --- | --- | --- |
| Postfix 3.10 (SMTP) | 25, 465, 587 | STARTTLS on 25 (offered, not required); 465 and 587 require TLS and login; config updated for Postfix 3.10 |
| Dovecot 2.4 (IMAP, POP3) | 143, 993, 110, 995 | Config rewritten for 2.4; passwords only over TLS; each mailbox's Dovecot home kept beside the mail, so phones no longer show internal folders |
| ManageSieve | 4190 | TLS; Sieve out-of-office replaces the old vacation script |
| Roundcube 1.7.5 (webmail) | 443 | New database from 1.7's schema; password change, Sieve filters and out-of-office |
| SOGo 5.12 groupware | 443 | Webmail, calendars, contacts (CalDAV/CardDAV), ActiveSync at `/Microsoft-Server-ActiveSync` |
| ProFTPD 1.3.9 (FTPS) | 21 | TLS required |

**Certificates.** All services share the self-signed certificate from the installer. Once Sencrypt issues the panel's Let's Encrypt certificate, mail and FTP switch to it within 5 minutes.

**SOGo**

- Users log in with their mailbox address and password from the panel, through a read-only view of the mailboxes.
- 21 settings in Admin > Mail Config (ActiveSync on or off, language, time zone, message size, filters, out-of-office, forwarding, reminders, login blocking, workers, ActiveSync intervals, nightly backup). The root daemon checks each value, writes them to `sogo.conf` and restarts SOGo.
- The Webmail page shows a "Launch Groupware" button and the phone setup details.

## New and rebuilt modules

Eight modules were added to the core. Most started from older ZPanel add-ons and were rebuilt, because the originals were unmaintained or open to attack.

| Module | Who | Built on | What changed from the source |
| --- | --- | --- | --- |
| PHP Versions | Admin | PHP 8.1 to 8.4 built from source | New: choose a PHP version per domain or subdomain; versions can be switched off; security end dates shown |
| Repo Browser | Admin | ZPanel Repo Browser | Rewritten: command and SQL injection, script injection, CSRF and unauthenticated access fixed; panel modules protected; installs queued for the root daemon |
| File Manager | All | Tiny File Manager 2.6 + fixes (replaced KFM) | KFM did not run on PHP 8 and had command injection; replaced, with the panel login, confinement to the account's folder, local assets and four script-injection fixes in Tiny File Manager |
| Visitor Stats | All | Visitors 0.7 (built from source) | Reports outside the web root, owner-only viewer, sandboxed; link-escaping patch to Visitors; setuid binary removed |
| Apache Status | Admin | Pimped Apache Status 2.04.17 | Original had no login, fetched any URL, included any file; now admin-only with checked parameters, a fixed server list and local libraries |
| System Log Viewer | Admin | ZPanel LogViewer (rebuilt) | Original made logs public and ran unauthenticated; rebuilt to follow logs live by id, never by path |
| Matomo | All | Matomo 5.14.2 | New: see Statistics |
| Kerf theme | All | New | Optional theme; Sentora_Default stays the default |

The Webmail page also gained the SOGo button and ActiveSync setup details, and Mail Config the SOGo settings.

## Statistics

Three statistics tools now run side by side, all limited to the account's own domains, and the panel's own domain reports under the main administrator (zadmin).

| Tool | Data | Updated | Where reports live |
| --- | --- | --- | --- |
| Webalizer 2.23 (built from source, 12 patches incl. a GCC 15 fix) | Access logs | Hourly | `/var/sentora/stats/webalizer`, shown by an owner-only viewer |
| Visitors 0.7 (built from source) | Access logs | Hourly | `/var/sentora/stats/visitors`, shown sandboxed to the owner |
| Matomo 5.14.2 | Access logs, or tracking code per domain | Hourly import and report build | Matomo database `sentora_matomo` |

**Webalizer and Visitor Stats**

- Domains with no traffic yet are skipped instead of reporting failures.
- Existing Webalizer reports move automatically from the old public folder on the next hourly run, keeping their history.

**Matomo**

- Set up unattended by the installer through Matomo's own installer steps; super user `sentora_admin`.
- Every 5 minutes the daemon creates a Matomo site per domain and subdomain (plus the panel domain for zadmin) and a view-only Matomo user per account, through a small Sentora plugin (`sentora:sync`).
- Every hour it imports only the new lines of each domain's access log (rotated logs restart from the top) and builds the reports. The import token is never on a command line.
- The panel page lists the account's own domains and opens the full Matomo interface with no second login. Each domain can switch to tracking code, with the snippet shown.
- Matomo's internet features, adverts and "what's new" pop-ups are switched off, and reports are built by the hourly job rather than by visitors.

## PHP 8.5 compatibility and bug fixes

The whole core now lints clean on PHP 8.5, and every panel page loads with no PHP warnings or errors as an administrator and as a client.

**PHP 8.5 compatibility**

- Template loops, module lists and controllers updated for PHP 8's stricter types and removed functions.
- Undefined variables, `count()` on null, array access on false and wrong static declarations fixed across Domains, DNS, MySQL, Services, Packages, Module Admin, Manage Clients, Backups and Domain Logs.

**Bug fixes**

- Apache: the port 80 to 443 redirect no longer catches `/server-status`, so Apache Status works on HTTPS panels; port 80 always listened on; a wrong port variable fixed.
- Services page: wrong port variable removed; DNS, SMTP, submission, IMAP(S) and POP3(S) checks added.
- AutoIP: tries several address services and validates the result.
- BIND: options removed in BIND 9.16+ dropped; its AppArmor profile disabled so zone files can be written; config checked before start.
- Sencrypt: a crash in the daily renewal fixed; the panel certificate is synced to mail and FTP within 5 minutes.
- `zppy`: a blank line in the package list broke installs.
- Dashboard: the Gravatar request replaced with a local icon.
- Backup page: crash for an account without a website folder.
- Custom vhost entries written for mod_php (`php_value`, `php_admin_flag`) are kept but commented out, so they cannot stop Apache.

## Removed from the original

These were removed because they no longer worked on Ubuntu 26.04, contacted outside servers, or were replaced by something safer.

| Removed | Why | Replaced by |
| --- | --- | --- |
| Snuffleupagus / Suhosin | Not available for PHP 8.5 | Per-account users and open_basedir |
| Smarty, ZPanel upgrade path | Unused, unmaintained | Nothing |
| Sentora.org Updates and News modules, daily usage report | Contacted sentora.org | Nothing (updates come with new bundles) |
| `vacation.pl` auto-reply | Unmaintained script | Sieve out-of-office in Roundcube and SOGo |
| mod_php for hosted sites | Every site ran as the same user | Per-account PHP-FPM pools |
| KFM file manager | Did not run on PHP 8; command injection | Tiny File Manager |
| Shared `www-data` PHP pools for extra versions | Shared user | Per-account pools per version |
| Shared `www-data` crontab for jobs | Shared user | `/etc/cron.d/sentora-accounts`, one user per account |
| `uninstall/` folder in the installers | Not maintained | Nothing |
| `/etc/sudoers` line for zsudo | Pointless (user `apache` does not exist on Ubuntu) | Nothing |

## Bundled software and maintenance

Ubuntu's updates cover PHP 8.5, Apache, MySQL, Postfix, Dovecot, BIND, ProFTPD, SOGo and fail2ban. The software below is bundled or built, so its security releases are applied by replacing the bundled copy.

| Software | Version | Where | Security fixes until |
| --- | --- | --- | --- |
| phpMyAdmin | 5.2.3 | `etc/apps/phpmyadmin` | Ongoing releases |
| Roundcube | 1.7.5 | `etc/apps/webmail` | Ongoing releases |
| Matomo | 5.14.2 + Sentora plugin | `etc/apps/matomo` | Ongoing releases |
| Tiny File Manager | 2.6 + fixes | `modules/file_manager/app` | Ongoing releases |
| Pimped Apache Status | 2.04.17 | `modules/apache_status/app` | Ongoing releases |
| PHP | 8.4.27 | `/opt/sentora-php/8.4` | 31 Dec 2028 |
| PHP | 8.3.36 | `/opt/sentora-php/8.3` | 31 Dec 2027 |
| PHP | 8.2.35 | `/opt/sentora-php/8.2` | 31 Dec 2026 |
| PHP | 8.1.34 | `/opt/sentora-php/8.1` | Ended 31 Dec 2025 |
| Webalizer | 2.23 | built at install | Final release |
| Visitors | 0.7 | built at install | Final release |

- When replacing Tiny File Manager or Pimped Apache Status, reapply the Sentora changes listed in each module's `VERSION` file.
- When replacing Matomo, copy `plugins/SentoraSync` into the new version, then run `console core:update --yes` as the panel user.
- Rebuild an extra PHP version with `build-sentora-php.sh` on a spare 26.04 machine, then `update-sentora-php.sh` on each server.
- Move sites off PHP 8.1 now, and off 8.2 before the end of 2026.
