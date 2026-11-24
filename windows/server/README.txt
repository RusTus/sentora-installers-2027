Sentora-2027 for Windows Server (2022/2025), natively in C:\zpanel (the same panel as on Linux).
Download: sentora-windows-server.zip from https://github.com/RusTus/sentora-installers-2027/releases/tag/current

install-zpanel-stack.ps1: Apache 2.4.71, PHP 8.5.13, MySQL 8.4.13, FTP (SFTPGo) and mail (Stalwart, Roundcube webmail,
ActiveSync for phones) into C:\zpanel (Windows Server 2022/2025).
Services: Apache2, MySQL, SFTPGo, Stalwart, ClamAV, FreshClam, CoreDNS. An install with the older ZPanelApache / ZPanelMySQL names is moved over; data kept.

Two installers in this zip, the same file but for one line:
  install-zpanel-stack.ps1   everything: Apache, PHP, MySQL, FTP, mail, DNS and the Sentora panel (asks about each part)
  install-web-stack.ps1      Apache, PHP and MySQL only (asks about nothing else; -WithFtp, -WithMail, -WithDns still add one)

1. Extract this zip, for example to C:\install.
2. Open PowerShell as Administrator, then:
     cd C:\install
     Unblock-File .\install-zpanel-stack.ps1
     powershell -ExecutionPolicy Bypass -File .\install-zpanel-stack.ps1
   It asks about each part; Enter means Yes:
     FTP and SFTP (SFTPGo: FTP with TLS on 21, SFTP on 2022, users in MySQL)
     Mail (Stalwart: SMTP 25/587/465, IMAP 143/993, POP3 995, calendars, contacts; Roundcube; ActiveSync) and the mail domain
     ClamAV: every message checked for viruses (needs about 1.5 GB of memory)
     DNS (CoreDNS: answers for your own domains only)
     The Sentora panel (C:\zpanel\panel; each hosting account's PHP then runs as its own Windows account)
     MySQL without strict mode (as MySQL 5)
     Foreign keys to columns that are not unique (as MySQL 5; MySQL 8.4 error 6125)
     Open Windows Firewall for them (their ports only)
     PHP time zone (America/Chicago)
   -Unattended asks nothing and takes every Yes. A switch answers its question: -WithFtp:$false, -WithMail:$false, -WithClamAV:$false, -WithDns:$false,
   -MailDomain example.com, -RelaxedSqlMode:$false, -LegacyForeignKeys:$false, -NoFirewall, -TimeZone "America/New_York".
3. Put your app in C:\zpanel\panel. Logs: C:\zpanel\logs. MySQL root password: C:\zpanel\configs\mysql\root-password.txt

FTP users:   C:\zpanel\bin\ftpuser.ps1 add USER PASSWORD     (also: remove USER, list)
Mailboxes:   C:\zpanel\bin\mailuser.ps1 add USER@DOMAIN PASSWORD   (also: password, remove, list, domains)
Webmail:     https://SERVER/webmail
PHP per account (with the panel; the panel does this itself for its clients):
             C:\zpanel\bin\phpaccount.ps1 add NAME   (also: remove NAME, list, repair)
DNS zones:   C:\zpanel\bin\dnszone.ps1 add DOMAIN [IP]   (also: record DOMAIN add NAME TYPE VALUE, record DOMAIN remove NAME TYPE, list, show)
Phones:      an Exchange (ActiveSync) account: server SERVER, user name the full address
HTTPS:       webmail, ActiveSync and calendars only over HTTPS. The certificate in C:\zpanel\configs\ssl is made at install
             (phones ask once to trust it); put a real one there with the same names, then Restart-Service Apache2, Stalwart

Run it again to repair: databases, passwords, FTP users, mailboxes, DNS zones and httpd-vhosts.conf are kept.
-Uninstall removes the services and keeps the data.
