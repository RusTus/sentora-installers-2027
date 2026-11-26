<#
.SYNOPSIS
    Apache 2.4, PHP 8.5 and MySQL 8.4 for Windows Server, in the old ZPanel folders under C:\zpanel.

.DESCRIPTION
    Downloads the official Windows builds (each file checked against its SHA-256 before it is used), puts them in
    C:\zpanel\bin\apache, \bin\php and \bin\mysql, writes their settings to C:\zpanel\configs, sends every log to
    C:\zpanel\logs and installs Apache and MySQL as Windows services that start with Windows.

        C:\zpanel\panel        the web root (your app's index.php goes here)
        C:\zpanel\hostdata     the websites' folders (as in ZPanel)
        C:\zpanel\logs         Apache's access and error logs, PHP's errors, MySQL's error log, this installer's log
        C:\zpanel\configs      httpd.conf, httpd-vhosts.conf, php.ini (in bin\php), my.ini, the MySQL root password
        C:\zpanel\data\mysql   MySQL's databases (kept apart from the program, so a newer MySQL can replace it)
        C:\zpanel\temp         PHP's uploads and sessions, the downloads

    Run it again to repair: it keeps the databases, the password, httpd-vhosts.conf and your files in panel and hostdata.

    Two files, the same installer: install-zpanel-stack.ps1 asks about every part (FTP, mail, DNS, the Sentora panel),
    Yes by default; install-web-stack.ps1 installs Apache, PHP and MySQL only and asks about nothing else (a switch,
    such as -WithFtp, still adds a part).

.PARAMETER Root
    The top folder. Default C:\zpanel.
.PARAMETER HttpPort
    Apache's port. Default 80.
.PARAMETER ServerName
    Apache's ServerName. Default this computer's name.
.PARAMETER TimeZone
    PHP's date.timezone. Default America/Chicago.
.PARAMETER MySQLRootPassword
    The MySQL root password, as a SecureString (-MySQLRootPassword (Read-Host -AsSecureString)). Default: a new random one, saved in C:\zpanel\configs\mysql\root-password.txt (only
    Administrators and SYSTEM can read it). On a repair the saved one is kept.
.PARAMETER RelaxedSqlMode
    MySQL without strict mode (as MySQL 5 behaved by default): for an old app while it is being updated.
.PARAMETER LegacyForeignKeys
    Let a foreign key point at a column that is not unique (MySQL 5 allowed it; MySQL 8.4 refuses it with error 6125
    unless restrict_fk_on_non_standard_key is OFF). For an old app's database; written into my.ini on every run.
.PARAMETER WithFtp
    FTP and SFTP with SFTPGo (C:\zpanel\bin\sftpgo, service SFTPGo): users kept in MySQL (database and user sftpgo),
    FTP on 21 with TLS required (FTPS), SFTP on 2022, homes under C:\zpanel\hostdata, its admin pages and REST API
    on 127.0.0.1:8080 only. C:\zpanel\bin\ftpuser.ps1 adds, removes and lists FTP users.
.PARAMETER NoFirewall
    Do not open Apache's port in Windows Firewall (MySQL is never opened: it listens on 127.0.0.1 only).
.PARAMETER WithMail
    Mail: Stalwart (SMTP, IMAP, POP3, calendars and contacts), Roundcube webmail on https://SERVER/webmail and
    ActiveSync for phones (Z-Push) on https://SERVER/Microsoft-Server-ActiveSync. Mailboxes: C:\zpanel\bin\mailuser.ps1.
.PARAMETER WithClamAV
    With mail: every message is checked by ClamAV on its way in or out; one with a virus is refused. ClamAV keeps its
    virus list in memory (about 1.5 GB) and updates it 12 times a day.
.PARAMETER WithDns
    DNS: CoreDNS answers for your own domains from zone files in C:\zpanel\configs\dns\zones (the same format as
    BIND's), never for other names. Zones and records: C:\zpanel\bin\dnszone.ps1.
.PARAMETER MailDomain
    The first mail domain (more can be added with mailuser.ps1). Default: this computer's DNS domain, else asked.
.PARAMETER HttpsPort
    Apache's HTTPS port for webmail, ActiveSync and the site (with mail). Default 443.
.PARAMETER Unattended
    Ask nothing: every question takes its default (Yes). Without it the installer asks about each part, Yes by default
    (press Enter); a switch given on the command line is not asked again.
.PARAMETER Uninstall
    Stop and remove the two services and the firewall rule. Folders, databases and logs stay.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\install-zpanel-stack.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\install-zpanel-stack.ps1
    (asks about each part, Yes by default)
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\install-zpanel-stack.ps1 -Unattended -TimeZone "America/New_York"
#>
[CmdletBinding()]
param(
    [string]$Root = 'C:\zpanel',
    [ValidateRange(1, 65535)][int]$HttpPort = 80,
    [string]$ServerName = $env:COMPUTERNAME,
    [string]$TimeZone = 'America/Chicago',
    [SecureString]$MySQLRootPassword,
    [switch]$RelaxedSqlMode,
    [switch]$LegacyForeignKeys,
    [switch]$WithFtp,
    [switch]$WithMail,
    [switch]$WithClamAV,
    [switch]$WithDns,
    [switch]$WithPanel,
    # (other PHP versions for the hosting accounts' domains, next to the panel's own 8.5: '' for none)
    [string]$PhpVersions = '8.4,8.3',
    [string]$PanelSource,
    [string]$PanelSqlSource,
    [string]$MailDomain,
    [ValidateRange(1, 65535)][int]$HttpsPort = 443,
    [switch]$NoFirewall,
    [switch]$Unattended,
    [switch]$Uninstall
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # (Invoke-WebRequest is many times slower while it draws its bar)
$clamSkipped = $false
# (the release workflow writes the version here; a copy straight from the repository says 'dev')
$InstallerVersion = 'dev'
# (install-web-stack.ps1 is this same file with 'stack' here: Apache, PHP and MySQL only, nothing else asked about,
# though a switch still adds a part; the install test checks the two files differ in this line only)
$Edition = 'stack'

# ------------------------------------------------------------------------------------------------------------------
# What is installed: the exact files, and their SHA-256 (checked 2026-11-22 against each project's own checksum)
# ------------------------------------------------------------------------------------------------------------------
$Pkg = @{
    Apache = @{ Name = 'Apache 2.4.71 (Apache Lounge, VS18, x64)'
                Url  = 'https://www.apachelounge.com/download/VS18/binaries/httpd-2.4.71-261112-Win64-VS18.zip'
                Sha  = '44961A929DFF16B767B3ADBCEA55760A942A00239B2891DC55806BE34029E1FC'; Top = 'Apache24' }
    Php    = @{ Name = 'PHP 8.5.13 (Thread Safe, VS17, x64)'
                Url  = 'https://windows.php.net/downloads/releases/php-8.5.13-Win32-vs17-x64.zip'
                Sha  = '792FE1553895EE98F9BC3C9D50C539910F7D0066676A6523207CA91429D4D475'; Top = '' }
    MySQL  = @{ Name = 'MySQL 8.4.13 LTS (x64)'
                Url  = 'https://cdn.mysql.com/Downloads/MySQL-8.4/mysql-8.4.13-winx64.zip'
                # (once a newer 8.4 is out, this one moves to MySQL's archive)
                More = @('https://cdn.mysql.com/archives/mysql-8.4/mysql-8.4.13-winx64.zip', 'https://dev.mysql.com/get/Downloads/MySQL-8.4/mysql-8.4.13-winx64.zip')
                Sha  = 'E9CD98B1D23F122987B1AFCA26AD942EFEC5891481103D48AE3CDE237FA3EA54'; Top = 'mysql-8.4.13-winx64' }
    # (-WithFtp only; SFTPGo publishes no checksum file: this is the SHA-256 of its official 2.7.6 installer)
    Sftpgo = @{ Name = 'SFTPGo 2.7.6 (FTP, FTPS, SFTP)'
                Url  = 'https://github.com/drakkan/sftpgo/releases/download/v2.7.6/sftpgo_v2.7.6_windows_x86_64.exe'
                Sha  = '63D29355DBCB7690BA1A2F58A4F8E6DB4320275C056E9D48141D258204BEC31B'; Top = '' }
    # (mail; checksums taken 2026-11-23 from the official files: these projects publish none for Windows)
    Stalwart = @{ Name = 'Stalwart 1.1.1 (mail server)'
                Url  = 'https://github.com/stalwartlabs/stalwart/releases/download/v1.1.1/stalwart-x86_64-pc-windows-msvc.zip'
                Sha  = '4275B825026E8093EFC998C12B130C95EC7BACE7FC60FE2C24B19FF9E3FC7E14'; Top = '' }
    Nssm   = @{ Name = 'NSSM 2.24 (runs Stalwart as a service)'
                Url  = 'https://nssm.cc/release/nssm-2.24.zip'
                More = @('https://web.archive.org/web/2024id_/https://nssm.cc/release/nssm-2.24.zip')
                Sha  = '727D1E42275C605E0F04ABA98095C38A8E1E46DEF453CDFFCE42869428AA6743'; Top = 'nssm-2.24\win64' }
    Roundcube = @{ Name = 'Roundcube 1.7.6 (webmail)'
                Url  = 'https://github.com/roundcube/roundcubemail/releases/download/1.7.6/roundcubemail-1.7.6-complete.tar.gz'
                Sha  = '0168085BF0BFEEB5D4ECA3B9730EFFBE610DBDB380A68567D292FEC51F09DB36'; Top = 'roundcubemail-1.7.6' }
    ZPush  = @{ Name = 'Z-Push 2.7.7 (ActiveSync)'
                Url  = 'https://github.com/Z-Hub/Z-Push/archive/refs/tags/2.7.7.tar.gz'
                Sha  = '36AFDA54D8AD5ED0C87E2E0648007425D73EC13F33C866A5E28A82771D285105'; Top = 'Z-Push-2.7.7\src' }
    Awl    = @{ Name = 'AWL 0.65 (for Z-Push calendars)'
                Url  = 'https://gitlab.com/davical-project/awl/-/archive/r0.65/awl-r0.65.tar.gz'
                Sha  = '76FEB587A6682580687D651F496BC9AA0D0858E409FC782C81735F3642CC1C59'; Top = 'awl-r0.65\inc' }
    ClamAV = @{ Name = 'ClamAV 1.5.5 (virus scanner)'
                Url  = 'https://github.com/Cisco-Talos/clamav/releases/download/clamav-1.5.5/clamav-1.5.5.win.x64.zip'
                More = @('https://www.clamav.net/downloads/production/clamav-1.5.5.win.x64.zip')
                Sha  = '90169F5E551010BFBD0208B50D9B2FA8988EF396CCCBD46B0CC8CAE03E22A4A4'; Top = 'clamav-1.5.5.win.x64' }
    CoreDns = @{ Name = 'CoreDNS 1.15.0 (DNS server)'
                Url  = 'https://github.com/coredns/coredns/releases/download/v1.15.0/coredns_1.15.0_windows_amd64.tgz'
                Sha  = '94D21843A17D13D32E8EB122B4410EF5C68C549A623CF9340896759252F940BD'; Top = '' }
    # (other PHP versions for the websites, chosen per domain on the Domains page; checksums from windows.php.net's list)
    Php84  = @{ Name = 'PHP 8.4.28 (Thread Safe, VS17, x64; for websites)'
                Url  = 'https://windows.php.net/downloads/releases/php-8.4.28-Win32-vs17-x64.zip'
                More = @('https://windows.php.net/downloads/releases/archives/php-8.4.28-Win32-vs17-x64.zip')
                Sha  = '59CCAA095D0F0C3FBB9F7E2BFD86713EBAC032D5783CC22BC8934E2285629999'; Top = '' }
    Php83  = @{ Name = 'PHP 8.3.37 (Thread Safe, VS16, x64; for websites)'
                Url  = 'https://windows.php.net/downloads/releases/php-8.3.37-Win32-vs16-x64.zip'
                More = @('https://windows.php.net/downloads/releases/archives/php-8.3.37-Win32-vs16-x64.zip')
                Sha  = '07984BDCFD4391888954C2E73B1CF2C0D403CFA5FD8CB32AD60DDCD854C6B7DE'; Top = '' }
    # (the panel's Matomo reads the websites' logs with Matomo's own importer, a Python script; the SHA-256 of python.org's file)
    Python = @{ Name = 'Python 3.13.9 (embeddable, for Matomo''s log importer)'
                Url  = 'https://www.python.org/ftp/python/3.13.9/python-3.13.9-embed-amd64.zip'
                Sha  = '91D828C2DA3A029B41699E918674A0CB379C02CF20DAB9C501306885F837402A'; Top = '' }
    PhpImap = @{ Name = 'php_imap 1.0.3 for PHP 8.5 (for Z-Push)'
                Url  = 'https://downloads.php.net/~windows/pecl/releases/imap/1.0.3/php_imap-1.0.3-8.5-ts-vs17-x64.zip'
                Sha  = 'FAC1258FFAB3425411770FDB0B1BFDD66F51EECBF22345AA259AEC4CA873C342'; Top = '' }
}
# (these two change with each release: the runtime is checked by Microsoft's signature, the CA list by curl's checksum)
$VcRedistUrl = 'https://aka.ms/vc14/vc_redist.x64.exe'
$CaBundleUrl = 'https://curl.se/ca/cacert.pem'

$ApacheService = 'Apache2'
$MySQLService  = 'MySQL'
$FirewallRule  = 'Apache HTTP Server (port 80)'
# (the names the first version of this installer used: removed when found, the data and settings stay)
$OldServices   = @('ZPanelApache', 'ZPanelMySQL')
$OldFirewall   = 'ZPanel Apache (HTTP)'
$FtpService    = 'SFTPGo'
$FtpFirewall   = 'SFTPGo FTP and SFTP (21, 2022, 50000-50100)'
$MailService   = 'Stalwart'
$MailFirewall  = 'Stalwart mail (25, 143, 465, 587, 993, 995)'
$HttpsFirewall = 'Apache HTTPS (webmail, ActiveSync)'   # (the name kept from before: HTTPS for the websites too)
$MailPorts     = @(25, 143, 465, 587, 993, 995)
$ClamService   = 'ClamAV'
$DnsService    = 'CoreDNS'
$DnsFirewall   = 'CoreDNS (DNS, port 53)'
$FreshService  = 'FreshClam'

$Bin  = Join-Path $Root 'bin'
$Cfg  = Join-Path $Root 'configs'
$Logs = Join-Path $Root 'logs'
$Tmp  = Join-Path $Root 'temp'
$Dl   = Join-Path $Tmp 'downloads'
$ApacheDir = Join-Path $Bin 'apache'
$PhpDir    = Join-Path $Bin 'php'
$MySQLDir  = Join-Path $Bin 'mysql'
$DataDir   = Join-Path $Root 'data\mysql'
$Fwd = { param($p) $p -replace '\\', '/' }   # Apache, PHP and MySQL want forward slashes
# (the password as text, only inside this script)
$RootPw = ''
if ($MySQLRootPassword) { $RootPw = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($MySQLRootPassword)) }

function Say($text)  { Write-Host ''; Write-Host "== $text" -ForegroundColor Cyan }
function Ok($text)   { Write-Host "   ok   $text" -ForegroundColor Green }
function Note($text) { Write-Host "   $text" }
function Fail($text) { Write-Host ''; Write-Host "STOPPED: $text" -ForegroundColor Red; throw $text }

function Invoke-Native([string]$exe, [string[]]$argv) {
    # A program's messages on its error output are text, not a failure: Windows PowerShell 5.1 would stop the script on
    # them under 'Stop' (MySQL and Apache write their normal progress there). The exit code is in $LASTEXITCODE.
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $out = & $exe @argv 2>&1 | ForEach-Object { "$_" } }
    finally { $ErrorActionPreference = $old }
    return $out
}

function Write-Utf8NoBom($path, $text) {
    # (Apache and MySQL read a byte-order mark as part of the first line)
    [System.IO.File]::WriteAllText($path, ($text -replace "`r?`n", "`r`n"), (New-Object System.Text.UTF8Encoding($false)))
}

function Get-Verified($pkg) {
    $file = Join-Path $Dl ([System.IO.Path]::GetFileName(([uri]$pkg.Url).AbsolutePath))
    if (Test-Path $file) {
        if ((Get-FileHash $file -Algorithm SHA256).Hash -eq $pkg.Sha) { Ok "$($pkg.Name): already downloaded, checksum matches"; return $file }
        Remove-Item $file -Force
    }
    Note "downloading $($pkg.Name) ..."
    $urls = @($pkg.Url)
    if ($pkg.ContainsKey('More')) { $urls += $pkg.More }
    $done = $false; $why = ''
    # (sites differ: MySQL's takes a browser-like client name for a bot, Apache Lounge redirects PowerShell's own with a
    #  308 that Windows PowerShell 5.1 does not follow; so each address is tried with both, then again)
    $agents = @('', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) zpanel-stack-installer')
    foreach ($u in $urls) {
        for ($try = 1; $try -le 2 -and -not $done; $try++) {
            foreach ($ua in $agents) {
                try {
                    if ($ua) { Invoke-WebRequest -Uri $u -OutFile $file -UseBasicParsing -TimeoutSec 600 -UserAgent $ua }
                    else { Invoke-WebRequest -Uri $u -OutFile $file -UseBasicParsing -TimeoutSec 600 }
                    $done = $true; break
                } catch {
                    $why = "$u : $($_.Exception.Message)"
                }
            }
            if (-not $done) { Start-Sleep -Seconds (5 * $try) }
        }
        if ($done) { break }
    }
    if (-not $done) { Fail "$($pkg.Name) could not be downloaded ($why)." }
    $got = (Get-FileHash $file -Algorithm SHA256).Hash
    if ($got -ne $pkg.Sha) { Remove-Item $file -Force; Fail "$($pkg.Name): the download's checksum is $got, not $($pkg.Sha). It was not used." }
    Ok "$($pkg.Name): downloaded, checksum matches"
    return $file
}

function Expand-Into($zip, $top, $dest) {
    # unpack to a fresh folder, then swap it in (the old program folder goes; settings live in $Cfg)
    $stage = Join-Path $Tmp ('unpack-' + [guid]::NewGuid().ToString('N'))
    if ($zip -match '\.(tar\.gz|tgz)$') {
        # (Windows' own tar.exe, there since Server 2019)
        New-Item -ItemType Directory -Path $stage | Out-Null
        $out = Invoke-Native (Join-Path $env:SystemRoot 'System32\tar.exe') @('-xzf', $zip, '-C', $stage)
        if ($LASTEXITCODE -ne 0) { Fail "Could not unpack $zip : $($out -join ' ')" }
    } else { Expand-Archive -Path $zip -DestinationPath $stage -Force }
    $from = if ($top) { Join-Path $stage $top } else { $stage }
    if (-not (Test-Path $from)) { Fail "$zip does not hold the folder $top." }
    if (Test-Path $dest) {
        # (a program still running from the folder keeps its files open: a service run by NSSM says "stopped" a moment
        #  before its program has exited)
        $full = (Resolve-Path $dest).Path.TrimEnd('\') + '\'
        for ($i = 0; $i -lt 30; $i++) {
            $left = @(Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path.StartsWith($full, [StringComparison]::OrdinalIgnoreCase) })
            if (-not $left) { break }
            if ($i -eq 10) { $left | Stop-Process -Force -ErrorAction SilentlyContinue }
            Start-Sleep -Milliseconds 500
        }
        Remove-Item $dest -Recurse -Force
    }
    Move-Item $from $dest
    if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
}

function Stop-AndRemoveService($name) {
    $s = Get-Service -Name $name -ErrorAction SilentlyContinue
    if ($s) {
        if ($s.Status -ne 'Stopped') { Stop-Service -Name $name -Force; $s.WaitForStatus('Stopped', '00:01:00') }
        Invoke-Native 'sc.exe' @('delete', $name) | Out-Null
        for ($i = 0; $i -lt 20 -and (Get-Service -Name $name -ErrorAction SilentlyContinue); $i++) { Start-Sleep -Milliseconds 500 }
    }
}

function New-Password {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}

function Protect-File($path) {
    # only Administrators and SYSTEM
    $acl = New-Object System.Security.AccessControl.FileSecurity
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($who in @('BUILTIN\Administrators', 'NT AUTHORITY\SYSTEM')) {
        $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule($who, 'FullControl', 'Allow')))
    }
    Set-Acl -Path $path -AclObject $acl
}

function Get-MainIPv4 {
    # the server's address on the network with the default gateway (the route with the lowest metric). Not with
    # Get-NetIPConfiguration: on some servers (an adapter listed twice) it stops with "Cannot convert System.Object[]".
    try {
        $r = Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -ErrorAction Stop | Sort-Object { $_.RouteMetric + $_.InterfaceMetric } | Select-Object -First 1
        if (-not $r) { return $null }
        return (Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $r.InterfaceIndex -ErrorAction Stop | Where-Object { $_.IPAddress -notlike '169.254.*' } | Select-Object -First 1).IPAddress
    } catch { return $null }
}

function Protect-Folder($path) {
    # only Administrators and SYSTEM, for everything inside too (the services run as SYSTEM)
    Invoke-Native 'icacls.exe' @($path, '/inheritance:r', '/grant:r', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F') | Out-Null
    if ($LASTEXITCODE -ne 0) { Fail "Could not lock $path to Administrators." }
}

# ------------------------------------------------------------------------------------------------------------------
# Checks
# ------------------------------------------------------------------------------------------------------------------
$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Fail 'Run this as Administrator (right-click PowerShell, Run as administrator).' }
if (-not [Environment]::Is64BitOperatingSystem) { Fail 'This needs 64-bit Windows.' }
if ($PSVersionTable.PSVersion.Major -lt 5) { Fail 'This needs PowerShell 5.1 or newer.' }
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

if ($Uninstall) {
    Say 'Removing the services (folders, databases and logs stay)'
    foreach ($n in @($ApacheService, $MySQLService) + $OldServices) { Stop-AndRemoveService $n }
    Ok "services $ApacheService and $MySQLService removed"
    Stop-AndRemoveService $FtpService
    Stop-AndRemoveService $MailService
    Stop-AndRemoveService $ClamService
    Stop-AndRemoveService $FreshService
    Stop-AndRemoveService $DnsService
    Unregister-ScheduledTask -TaskName 'Sentora update check' -Confirm:$false -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName 'Sentora update' -Confirm:$false -ErrorAction SilentlyContinue
    Stop-AndRemoveService 'SentoraZsudo'
    Stop-AndRemoveService 'SentoraStats'
    Get-Service -Name 'SentoraPHP-*' -ErrorAction SilentlyContinue | ForEach-Object { Stop-AndRemoveService $_.Name }
    Get-LocalGroup -Name 'SentoraPHP' -ErrorAction SilentlyContinue | Remove-LocalGroup
    Unregister-ScheduledTask -TaskName 'Sentora daemon' -Confirm:$false -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName 'Sentora cron' -Confirm:$false -ErrorAction SilentlyContinue
    foreach ($n in @($FirewallRule, $OldFirewall, $FtpFirewall, $MailFirewall, $HttpsFirewall, $DnsFirewall)) { Get-NetFirewallRule -DisplayName $n -ErrorAction SilentlyContinue | Remove-NetFirewallRule }
    Ok 'firewall rule removed'
    Note "The folders under $Root are still there; delete them yourself once you no longer need the databases."
    return
}

foreach ($d in @($Root, $Bin, $Cfg, (Join-Path $Cfg 'apache'), (Join-Path $Cfg 'mysql'), $Logs, (Join-Path $Logs 'apache'), (Join-Path $Logs 'mysql'),
                 (Join-Path $Logs 'php'), (Join-Path $Root 'panel'), (Join-Path $Root 'hostdata'), (Join-Path $Root 'data'), $Tmp, $Dl,
                 (Join-Path $Tmp 'uploads'), (Join-Path $Tmp 'sessions'), (Join-Path $Cfg 'apache\php-accounts'))) {
    if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null }
}
# ------------------------------------------------------------------------------------------------------------------
# The questions (Yes by default: Enter takes it). A switch on the command line answers its question; -Unattended, or
# no one at the keyboard, takes every default.
# ------------------------------------------------------------------------------------------------------------------
$quiet = $Unattended -or -not [Environment]::UserInteractive -or [Console]::IsInputRedirected
function Ask($question) {
    if ($quiet) { return $true }
    $a = Read-Host "   $question [Y/n]"
    return ($a -match '^\s*$' -or $a -match '^\s*[yY]')
}
if (-not $quiet) {
    Write-Host ''
    Write-Host 'A few questions (press Enter for Yes):' -ForegroundColor Cyan
}
if (-not $PSBoundParameters.ContainsKey('WithFtp'))           { $WithFtp = [switch]($Edition -ne 'stack' -and (Ask 'Install FTP and SFTP (SFTPGo: FTP with TLS on 21, SFTP on 2022, users in MySQL)?')) }
if (-not $PSBoundParameters.ContainsKey('WithMail'))          { $WithMail = [switch]($Edition -ne 'stack' -and (Ask 'Install mail (Stalwart: SMTP, IMAP, calendars and contacts; Roundcube webmail; ActiveSync for phones)?')) }
if ($WithMail -and -not $PSBoundParameters.ContainsKey('WithClamAV')) { $WithClamAV = [switch](Ask 'Check mail for viruses with ClamAV (it needs about 1.5 GB of memory)?') }
if (-not $WithMail) { $WithClamAV = [switch]$false }
$askedClamAV = [bool]$WithClamAV
if (-not $PSBoundParameters.ContainsKey('WithDns'))           { $WithDns = [switch]($Edition -ne 'stack' -and (Ask 'Install DNS (CoreDNS: answers for your own domains, from zone files like BIND''s)?')) }
if (-not $PSBoundParameters.ContainsKey('WithPanel'))         { $WithPanel = [switch]($Edition -ne 'stack' -and (Ask 'Install the Sentora panel in C:\zpanel\panel (clients, websites with HTTPS, DNS, FTP, mail, cron, backups)?')) }
if ($WithMail -and -not $MailDomain) {
    # (a domain-joined server: its DNS domain; else asked, or this computer's name under .local)
    $cs = Get-CimInstance Win32_ComputerSystem -ErrorAction SilentlyContinue
    $MailDomain = if ($cs -and $cs.PartOfDomain) { $cs.Domain } elseif ($ServerName -match '\.') { $ServerName -replace '^[^.]+\.', '' } else { "$($env:COMPUTERNAME).local" }
    if (-not $quiet) { $d = Read-Host "   Mail domain (the part after @) [$MailDomain]"; if ($d.Trim()) { $MailDomain = $d.Trim() } }
}
if ($WithMail) {
    $MailDomain = $MailDomain.ToLower()
    if ($MailDomain -notmatch '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$') { Fail "The mail domain '$MailDomain' is not a domain name (like example.com)." }
}
if (-not $PSBoundParameters.ContainsKey('RelaxedSqlMode'))    { $RelaxedSqlMode = [switch](Ask 'MySQL without strict mode, as MySQL 5 behaved (for an older app)?') }
if (-not $PSBoundParameters.ContainsKey('LegacyForeignKeys')) { $LegacyForeignKeys = [switch](Ask 'Allow foreign keys to columns that are not unique, as MySQL 5 did (MySQL 8.4 error 6125)?') }
if (-not $PSBoundParameters.ContainsKey('NoFirewall'))        { $NoFirewall = [switch](-not (Ask 'Open Windows Firewall for the web server, FTP and mail (their ports only)?')) }
if (-not $PSBoundParameters.ContainsKey('TimeZone') -and -not $quiet) {
    $tz = Read-Host "   PHP time zone [$TimeZone]"
    if ($tz -match '^[A-Za-z]+(/[A-Za-z0-9_+-]+){0,2}$') { $TimeZone = $tz }
}
Write-Host ''
Write-Host ("   Installing: Apache, PHP, MySQL" + $(if ($WithPanel) { ', the Sentora panel' }) + $(if ($WithFtp) { ', FTP (SFTPGo)' }) + $(if ($WithDns) { ', DNS (CoreDNS)' }) + $(if ($WithMail) { ", mail for $MailDomain (Stalwart, Roundcube, ActiveSync$(if ($WithClamAV) { ', ClamAV' }))" }) + "; MySQL " + $(if ($RelaxedSqlMode) { 'lenient (MySQL 5)' } else { 'strict' }) +
    $(if ($LegacyForeignKeys) { ', old-style foreign keys' }) + "; firewall " + $(if ($NoFirewall) { 'left as it is' } else { 'opened for them' }) + "; time zone $TimeZone")

Start-Transcript -Path (Join-Path $Logs ('install-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')) | Out-Null

try {
    Write-Host "Sentora-Windows $InstallerVersion installer: Apache, PHP and MySQL for $Root  (Windows $([Environment]::OSVersion.Version), PowerShell $($PSVersionTable.PSVersion))"

    # (a port already taken by something else would keep Apache from starting)
    $busy = Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $p = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue; $p -and $p.ProcessName -ne 'httpd' }
    if ($busy) { Fail "Port $HttpPort is already used by $((Get-Process -Id $busy[0].OwningProcess).ProcessName) (process $($busy[0].OwningProcess)). Stop it (IIS: Stop-Service W3SVC) or use -HttpPort." }
    if ($WithPanel -and -not $WithMail) {
        $b = Get-NetTCPConnection -LocalPort $HttpsPort -State Listen -ErrorAction SilentlyContinue |
            Where-Object { $p = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue; $p -and $p.ProcessName -ne 'httpd' }
        if ($b) { Fail "Port $HttpsPort (HTTPS) is already used by $((Get-Process -Id $b[0].OwningProcess).ProcessName). Stop it (IIS: Stop-Service W3SVC) or use -HttpsPort." }
    }
    if ($WithMail) {
        foreach ($port in @($HttpsPort) + $MailPorts) {
            $b = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue |
                Where-Object { $p = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue; $p -and @('httpd', 'stalwart') -notcontains $p.ProcessName }
            if ($b) { Fail "Port $port (mail or HTTPS) is already used by $((Get-Process -Id $b[0].OwningProcess).ProcessName). Stop it first (Exchange, hMailServer, IIS) or answer No to mail." }
        }
    }
    if ($WithDns) {
        # (Windows' own DNS Server, as on a domain controller, takes port 53 on every address: the two cannot share it.
        #  Something on one inside address only, such as Windows' connection sharing or Docker, is fine.)
        $winDns = Get-Service DNS -ErrorAction SilentlyContinue
        if ($winDns -and $winDns.Status -eq 'Running') { Fail "Windows' own DNS Server runs here (a domain controller?): answer No to DNS (-WithDns:`$false)." }
        $b = @(Get-NetUDPEndpoint -LocalPort 53 -ErrorAction SilentlyContinue) + @(Get-NetTCPConnection -LocalPort 53 -State Listen -ErrorAction SilentlyContinue) |
            Where-Object { @('0.0.0.0', '::') -contains $_.LocalAddress } |
            Where-Object { $p = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue; $p -and $p.ProcessName -ne 'coredns' }
        if ($b) { Fail "Port 53 (DNS) is already used by $((Get-Process -Id $b[0].OwningProcess).ProcessName). Stop it first or answer No to DNS." }
    }

    # --------------------------------------------------------------------------------------------------------------
    Say 'Downloading (each file checked before it is used)'
    $zips = @{}
    foreach ($k in @('Apache', 'Php', 'MySQL')) { $zips[$k] = Get-Verified $Pkg[$k] }

    $vc = Join-Path $Dl 'vc_redist.x64.exe'
    Invoke-WebRequest -Uri $VcRedistUrl -OutFile $vc -UseBasicParsing
    $sig = Get-AuthenticodeSignature $vc
    if ($sig.Status -ne 'Valid' -or $sig.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') { Fail "The Visual C++ runtime's signature is not Microsoft's ($($sig.Status)). It was not run." }
    Ok 'Visual C++ runtime: signed by Microsoft'

    $ca = Join-Path $Dl 'cacert.pem'
    Invoke-WebRequest -Uri $CaBundleUrl -OutFile $ca -UseBasicParsing
    # (saved, then read as text: curl.se serves it as a PEM file, which Windows PowerShell 5.1 hands back as bytes)
    Invoke-WebRequest -Uri ($CaBundleUrl + '.sha256') -OutFile "$ca.sha256" -UseBasicParsing
    $caSha = ((Get-Content "$ca.sha256" -Raw).Trim() -split '\s+')[0]
    if ((Get-FileHash $ca -Algorithm SHA256).Hash -ne $caSha.ToUpper()) { Fail 'The CA list from curl.se does not match its checksum.' }
    Ok "CA list for PHP's HTTPS (curl.se): checksum matches"

    # --------------------------------------------------------------------------------------------------------------
    Say 'Visual C++ runtime (Apache, PHP and MySQL need it)'
    $p = Start-Process -FilePath $vc -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    # (0 done, 1638 a newer one is already there, 3010 done, restart later)
    if (@(0, 1638, 3010) -notcontains $p.ExitCode) { Fail "The Visual C++ runtime did not install (exit code $($p.ExitCode))." }
    Ok "installed (exit code $($p.ExitCode))"

    # --------------------------------------------------------------------------------------------------------------
    Say 'Unpacking into the ZPanel folders'
    foreach ($n in $OldServices) {
        if (Get-Service -Name $n -ErrorAction SilentlyContinue) { Stop-AndRemoveService $n; Ok "old service $n removed (now $ApacheService and $MySQLService)" }
    }
    Get-NetFirewallRule -DisplayName $OldFirewall -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    Stop-AndRemoveService $ApacheService
    Stop-AndRemoveService $MySQLService
    # (the panel's root jobs and its daemon run PHP from the folder replaced below: stopped, a daemon run going is let
    # finish for up to a minute; the panel's part sets both up again)
    Get-ScheduledTask -TaskName 'Sentora daemon', 'Sentora cron' -ErrorAction SilentlyContinue | Disable-ScheduledTask | Out-Null
    Stop-Service -Name 'SentoraZsudo' -Force -ErrorAction SilentlyContinue
    Get-Service -Name 'SentoraPHP-*' -ErrorAction SilentlyContinue | Stop-Service -Force -ErrorAction SilentlyContinue
    for ($i = 0; $i -lt 120; $i++) {
        $left = @(Get-Process -Name php, php-cgi -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path.StartsWith($PhpDir + '\', [StringComparison]::OrdinalIgnoreCase) })
        if (-not $left) { break }
        if ($i -eq 119) { $left | Stop-Process -Force -ErrorAction SilentlyContinue; Start-Sleep 1 }
        Start-Sleep -Milliseconds 500
    }
    Expand-Into $zips.Apache $Pkg.Apache.Top $ApacheDir; Ok "Apache  -> $ApacheDir"
    Expand-Into $zips.Php    $Pkg.Php.Top    $PhpDir;    Ok "PHP     -> $PhpDir"
    Expand-Into $zips.MySQL  $Pkg.MySQL.Top  $MySQLDir;  Ok "MySQL   -> $MySQLDir"
    $sslDir = Join-Path $PhpDir 'extras\ssl'
    if (-not (Test-Path $sslDir)) { New-Item -ItemType Directory -Path $sslDir | Out-Null }
    Copy-Item $ca (Join-Path $sslDir 'cacert.pem') -Force

    # PHP and MySQL on the system PATH (for the command line: php, mysql, mysqldump)
    $path = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $parts = @($path -split ';' | Where-Object { $_ -ne '' })
    $add = @($PhpDir, (Join-Path $MySQLDir 'bin')) | Where-Object { $parts -notcontains $_ }
    if ($add) { [Environment]::SetEnvironmentVariable('Path', (($parts + $add) -join ';'), 'Machine'); Ok "PATH: added $($add -join ', ')" }
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine')

    # --------------------------------------------------------------------------------------------------------------
    Say 'PHP 8.5 settings (C:\zpanel\bin\php\php.ini)'
    $ini = Get-Content (Join-Path $PhpDir 'php.ini-production') -Raw
    $set = [ordered]@{
        'extension_dir'        = '"' + (& $Fwd (Join-Path $PhpDir 'ext')) + '"'
        'date.timezone'        = $TimeZone
        'memory_limit'         = '256M'
        'max_execution_time'   = '120'
        'max_input_vars'       = '5000'
        'post_max_size'        = '64M'
        'upload_max_filesize'  = '64M'
        'upload_tmp_dir'       = '"' + (& $Fwd (Join-Path $Tmp 'uploads')) + '"'
        'session.save_path'    = '"' + (& $Fwd (Join-Path $Tmp 'sessions')) + '"'
        'session.cookie_httponly' = '1'
        'session.use_strict_mode' = '1'
        'error_log'            = '"' + (& $Fwd (Join-Path $Logs 'php\php_errors.log')) + '"'
        'log_errors'           = 'On'
        'display_errors'       = 'Off'
        'expose_php'           = 'Off'
        'curl.cainfo'          = '"' + (& $Fwd (Join-Path $sslDir 'cacert.pem')) + '"'
        'openssl.cafile'       = '"' + (& $Fwd (Join-Path $sslDir 'cacert.pem')) + '"'
        'opcache.enable'       = '1'
        'opcache.memory_consumption' = '128'
    }
    foreach ($k in $set.Keys) {
        $pat = '(?m)^\s*;?\s*' + [regex]::Escape($k) + '\s*=.*$'
        $line = "$k = $($set[$k])"
        if ($ini -match $pat) { $ini = [regex]::Replace($ini, $pat, $line, 1) } else { $ini += "`r`n$line" }
    }
    $exts = @('curl', 'exif', 'fileinfo', 'gd', 'gettext', 'intl', 'mbstring', 'mysqli', 'openssl', 'pdo_mysql', 'soap', 'sodium', 'xsl', 'zip')
    foreach ($e in $exts) {
        if (-not (Test-Path (Join-Path $PhpDir "ext\php_$e.dll"))) { Note "($e is built into this PHP or not shipped: skipped)"; continue }
        $pat = '(?m)^\s*;\s*extension\s*=\s*' + [regex]::Escape($e) + '\s*$'
        if ($ini -match $pat) { $ini = [regex]::Replace($ini, $pat, "extension=$e", 1) }
        elseif ($ini -notmatch ('(?m)^\s*extension\s*=\s*' + [regex]::Escape($e) + '\s*$')) { $ini += "`r`nextension=$e" }
    }
    # (each extension once: a second line for one makes PHP warn "already loaded" on every start)
    $seenExt = @{}
    $ini = (($ini -split "`r?`n") | Where-Object {
        if ($_ -match '^\s*extension\s*=\s*"?([A-Za-z0-9_]+)"?\s*$') { $k = $Matches[1].ToLower(); if ($seenExt.ContainsKey($k)) { return $false }; $seenExt[$k] = $true }
        return $true
    }) -join "`r`n"
    Write-Utf8NoBom (Join-Path $PhpDir 'php.ini') $ini
    $phpExe = Join-Path $PhpDir 'php.exe'
    $v = (Invoke-Native $phpExe @('-n', '-r', 'echo PHP_VERSION;')) -join ''
    $mods = (Invoke-Native $phpExe @('-c', (Join-Path $PhpDir 'php.ini'), '-m')) -join ' '
    $missing = $exts | Where-Object { $mods -notmatch ('(?i)\b' + [regex]::Escape($_) + '\b') }
    if ($missing) { Fail "PHP $v did not load: $($missing -join ', ')" }
    Ok "PHP $v with $($exts -join ', ')"

    # the other PHP versions for the websites (bin\php-8.4, bin\php-8.3): the same settings, their own extension folder,
    # only the extensions each one ships; the hosting accounts' PHP runs them (phpaccount.ps1), never Apache
    $phpExtra = @()
    if ($Edition -ne 'stack') {
        foreach ($pv in @($PhpVersions.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ })) {
            $pk = 'Php' + ($pv -replace '\.', '')
            if ($pv -notmatch '^\d+\.\d+$' -or -not $Pkg.ContainsKey($pk)) { Note "(PHP $pv is not one this installer has: skipped)"; continue }
            $pvDir = Join-Path $Bin "php-$pv"
            $pvZip = Get-Verified $Pkg[$pk]
            $stamp = Join-Path $pvDir 'sentora-version.txt'
            if (-not (Test-Path $stamp) -or (Get-Content $stamp -Raw).Trim() -ne $Pkg[$pk].Sha) {
                Expand-Into $pvZip $Pkg[$pk].Top $pvDir
                Write-Utf8NoBom $stamp $Pkg[$pk].Sha
            }
            $pvIni = [regex]::Replace($ini, '(?m)^\s*extension_dir\s*=.*$', 'extension_dir = "' + (& $Fwd (Join-Path $pvDir 'ext')) + '"', 1)
            $pvIni = (($pvIni -split "`r?`n") | Where-Object {
                if ($_ -match '^\s*extension\s*=\s*"?([A-Za-z0-9_]+)"?\s*$') { return (Test-Path (Join-Path $pvDir "ext\php_$($Matches[1]).dll")) }
                return $true
            }) -join "`r`n"
            Write-Utf8NoBom (Join-Path $pvDir 'php.ini') $pvIni
            $pvV = (Invoke-Native (Join-Path $pvDir 'php.exe') @('-c', (Join-Path $pvDir 'php.ini'), '-r', 'echo PHP_VERSION;')) -join ''
            if ($LASTEXITCODE -ne 0 -or $pvV -notmatch ('^' + [regex]::Escape($pv) + '\.')) { Fail "PHP $pv does not run: $pvV" }
            $phpExtra += $pv
            Ok "PHP $pvV -> $pvDir (for websites that choose it)"
        }
    }

    # --------------------------------------------------------------------------------------------------------------
    Say 'MySQL 8.4 settings and databases'
    $myIni = Join-Path $Cfg 'mysql\my.ini'
    $mode = if ($RelaxedSqlMode) { 'NO_ENGINE_SUBSTITUTION' } else { 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' }
    Write-Utf8NoBom $myIni @"
# Made by install-zpanel-stack.ps1; run it again to rewrite this file (your databases are kept).
[mysqld]
basedir=$(& $Fwd $MySQLDir)
datadir=$(& $Fwd $DataDir)
port=3306
# (only this server: the app runs here; MySQL is never opened to the network)
bind-address=127.0.0.1
mysqlx=OFF
character-set-server=utf8mb4
collation-server=utf8mb4_0900_ai_ci
sql_mode=$mode
$(if ($LegacyForeignKeys) { "# (-LegacyForeignKeys: foreign keys may point at columns that are not unique, as in MySQL 5)`r`nrestrict_fk_on_non_standard_key=OFF" })
max_connections=200
innodb_buffer_pool_size=512M
log-error=$(& $Fwd (Join-Path $Logs 'mysql\mysql_error.log'))
secure-file-priv=$(& $Fwd (Join-Path $Tmp 'mysql-files'))

[client]
port=3306
default-character-set=utf8mb4
"@
    if (-not (Test-Path (Join-Path $Tmp 'mysql-files'))) { New-Item -ItemType Directory -Path (Join-Path $Tmp 'mysql-files') | Out-Null }
    $mysqld = Join-Path $MySQLDir 'bin\mysqld.exe'
    $mysql  = Join-Path $MySQLDir 'bin\mysql.exe'
    $pwFile = Join-Path $Cfg 'mysql\root-password.txt'
    $fresh = -not (Test-Path (Join-Path $DataDir 'mysql.ibd'))
    if ($fresh) {
        if (Test-Path $DataDir) { if (Get-ChildItem $DataDir -Force) { Fail "$DataDir is not empty but holds no MySQL databases: move it away first." } }
        else { New-Item -ItemType Directory -Path $DataDir | Out-Null }
        Invoke-Native $mysqld @("--defaults-file=$myIni", '--initialize-insecure', '--console') | Out-Null
        if (-not (Test-Path (Join-Path $DataDir 'mysql.ibd'))) { Fail "MySQL could not make its databases: see $(Join-Path $Logs 'mysql\mysql_error.log')" }
        Ok "new databases in $DataDir"
    } else { Ok "the databases in $DataDir are kept" }
    Invoke-Native $mysqld @('--install', $MySQLService, "--defaults-file=$myIni") | Out-Null
    Set-Service -Name $MySQLService -StartupType Automatic
    Start-Service $MySQLService
    (Get-Service $MySQLService).WaitForStatus('Running', '00:01:30')
    Ok "service $MySQLService runs (starts with Windows)"

    $ready = $false
    for ($i = 0; $i -lt 60 -and -not $ready; $i++) {
        $out = Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '--skip-password', '-e', 'SELECT 1')
        if ($LASTEXITCODE -eq 0 -or ($out -join ' ') -match 'Access denied') { $ready = $true } else { Start-Sleep 1 }
    }
    if ($fresh) {
        if (-not $RootPw) { $RootPw = New-Password }
        $q = "ALTER USER 'root'@'localhost' IDENTIFIED BY '" + ($RootPw -replace "\\", "\\\\" -replace "'", "''") + "'; FLUSH PRIVILEGES;"
        $out = Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '--skip-password', '-e', $q)
        if ($LASTEXITCODE -ne 0) { Fail "Could not set the MySQL root password: $($out -join ' ')" }
        Write-Utf8NoBom $pwFile $RootPw
        Protect-File $pwFile
        Ok "root password set, saved in $pwFile (Administrators only)"
    } elseif (-not $RootPw -and (Test-Path $pwFile)) {
        $RootPw = (Get-Content $pwFile -Raw).Trim()
    }
    if ($RootPw) {
        $env:MYSQL_PWD = $RootPw
        $ver = (Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-N', '-e', 'SELECT VERSION()') | Where-Object { $_ -match '^\d+\.\d+' }) -join ''
        Remove-Item Env:\MYSQL_PWD
        if ($LASTEXITCODE -ne 0) { Fail 'MySQL runs but the root password did not work.' }
        Ok "MySQL $ver answers on 127.0.0.1:3306"
    }

    # --------------------------------------------------------------------------------------------------------------
    if ($WithPanel -or $WithMail) {
        Say "HTTPS on port $HttpsPort (the server's certificate, configs\apache\ssl.conf)"
        # the server's certificate, for HTTPS, IMAP and SMTP alike (made once; replace both files with a real one later,
        # e.g. from Let's Encrypt, then: Restart-Service Apache2, Stalwart). The panel's websites get their own from
        # the panel's Sencrypt page; this one answers for the server's own names and for names no website claims.
        $sslCfg = Join-Path $Cfg 'ssl'
        if (-not (Test-Path $sslCfg)) { New-Item -ItemType Directory -Path $sslCfg | Out-Null }
        Protect-Folder $sslCfg
        $mailHost = if ($ServerName -match '\.') { $ServerName.ToLower() } elseif ($WithMail) { "mail.$MailDomain" } else { $ServerName.ToLower() }
        $srvCrt = Join-Path $sslCfg 'server.crt'; $srvKey = Join-Path $sslCfg 'server.key'
        if (-not (Test-Path $srvCrt) -or -not (Test-Path $srvKey)) {
            $names = @($mailHost, $ServerName.ToLower(), 'localhost') | Where-Object { $_ } | Select-Object -Unique
            $cnf = Join-Path $Tmp ('cert-' + [guid]::NewGuid().ToString('N') + '.cnf')
            Write-Utf8NoBom $cnf ("[req]`r`ndistinguished_name = dn`r`n[dn]`r`n[v3]`r`nbasicConstraints = CA:FALSE`r`nkeyUsage = digitalSignature, keyEncipherment`r`n" +
                "extendedKeyUsage = serverAuth`r`nsubjectAltName = " + (($names | ForEach-Object { "DNS:$_" }) -join ', ') + ", IP:127.0.0.1`r`n")
            $code = @'
$o = ['config' => $argv[3], 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3'];
$k = openssl_pkey_new($o); $r = openssl_csr_new(['commonName' => $argv[4]], $k, $o);
$x = openssl_csr_sign($r, null, $k, 825, $o, random_int(1, PHP_INT_MAX));
if (!$x || !openssl_x509_export_to_file($x, $argv[1]) || !openssl_pkey_export_to_file($k, $argv[2], null, $o)) { fwrite(STDERR, (string) openssl_error_string()); exit(1); }
echo 'ok';
'@
            $out = Invoke-Native $phpExe @('-c', (Join-Path $PhpDir 'php.ini'), '-r', $code, '--', $srvCrt, $srvKey, $cnf, $mailHost)
            Remove-Item $cnf -Force -ErrorAction SilentlyContinue
            if (($out -join '') -notmatch 'ok' -or -not (Test-Path $srvKey)) { Fail "Could not make the server certificate: $($out -join ' ')" }
            Ok "certificate for $($names -join ', ') (made here, valid 825 days; browsers ask to trust it until a real one replaces it)"
        } else { Ok "the certificate in $sslCfg is kept" }
        # HTTPS for everything on this server: the port, the TLS versions and ciphers (one place, as sys_tls on Linux);
        # with the panel and no mail, the panel answers names no website claims (with mail, mail.conf's site does)
        $defaultSite = if ($WithMail) { '' } else { @"

<VirtualHost *:$HttpsPort>
    ServerName ${ServerName}
    SSLEngine on
    SSLCertificateFile "$(& $Fwd $srvCrt)"
    SSLCertificateKeyFile "$(& $Fwd $srvKey)"
    DocumentRoot "`${ZROOT}/panel"
    ErrorLog "`${ZROOT}/logs/apache/ssl-error.log"
    CustomLog "`${ZROOT}/logs/apache/ssl-access.log" combined
</VirtualHost>
"@ }
        Write-Utf8NoBom (Join-Path $Cfg 'apache\ssl.conf') @"
# Made by install-zpanel-stack.ps1 (HTTPS); run it again to rewrite this file.
Listen $HttpsPort
SSLSessionCache "shmcb:`${ZROOT}/temp/ssl_scache(512000)"
SSLProtocol -all +TLSv1.2 +TLSv1.3
SSLHonorCipherOrder off
SSLCipherSuite ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384
$defaultSite
"@
        Ok "Apache: HTTPS on port $HttpsPort (configs\apache\ssl.conf)"
        # (an older mail.conf opened the port and set TLS itself: those lines are ssl.conf's now, or Apache would see the
        # port twice; the mail part below writes the rest of that file again)
        $oldMail = Join-Path $Cfg 'apache\mail.conf'
        if (Test-Path $oldMail) {
            $mc = [IO.File]::ReadAllText($oldMail)
            $mc2 = [regex]::Replace($mc, '(?m)^(Listen|SSLSessionCache|SSLProtocol|SSLHonorCipherOrder|SSLCipherSuite)\b[^\r\n]*\r?\n', '')
            if ($mc2 -ne $mc) { Write-Utf8NoBom $oldMail $mc2; Ok 'mail.conf: the port and TLS lines moved to ssl.conf' }
        }
    }

    Say 'Apache 2.4 settings (C:\zpanel\configs\apache\httpd.conf)'
    # hostdata: with the panel, each website's PHP runs as its account's own Windows account (phpaccount.ps1) and
    # nothing else may run there; without it (install-web-stack.ps1), PHP inside Apache as usual
    $hostdataBlock = if ($WithPanel) { @'
<Directory "${ZROOT}/hostdata">
    # (the websites: their PHP runs as each account's own Windows account, never as Apache's: phpaccount.ps1 and the
    # files in configs\apache\php-accounts; a PHP file of no account is refused, never run here nor shown as text;
    # a site's .htaccess can not turn on server-side includes, CGI or the panel's own PHP)
    Options -Indexes -Includes -ExecCGI +SymLinksIfOwnerMatch
    AllowOverride AuthConfig FileInfo Indexes Limit Options=Indexes,MultiViews,SymLinksIfOwnerMatch
    Require all granted
    php_admin_flag engine Off
    <FilesMatch "\.(php|phtml|phar)$">
        Require all denied
    </FilesMatch>
</Directory>
IncludeOptional "${ZROOT}/configs/apache/php-accounts/*.conf"
'@ } else { @'
<Directory "${ZROOT}/hostdata">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
'@ }
    $vhosts = Join-Path $Cfg 'apache\httpd-vhosts.conf'
    if (-not (Test-Path $vhosts)) {
        # the original vhost, as ZPanel had it: C:\zpanel\panel serves the HTML and PHP files; more sites go below it
        Write-Utf8NoBom $vhosts @"
# The virtual hosts (ZPanel kept them here). Written once by install-zpanel-stack.ps1, then yours: never replaced.
#
# The original vhost: every name and address on port $HttpPort that no other vhost below claims lands here,
# in $Root\panel (HTML and PHP).
<VirtualHost *:$HttpPort>
    ServerName ${ServerName}
    DocumentRoot "`${ZROOT}/panel"
    <Directory "`${ZROOT}/panel">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php index.html index.htm
    ErrorLog "`${ZROOT}/logs/apache/panel-error.log"
    CustomLog "`${ZROOT}/logs/apache/panel-access.log" combined
</VirtualHost>

# A site in hostdata, for later (copy, change the names, remove the #, then: Restart-Service $ApacheService):
#<VirtualHost *:$HttpPort>
#    ServerName www.example.com
#    ServerAlias example.com
#    DocumentRoot "`${ZROOT}/hostdata/zadmin/public_html/example_com"
#    ErrorLog "`${ZROOT}/logs/apache/example.com-error.log"
#    CustomLog "`${ZROOT}/logs/apache/example.com-access.log" combined
#</VirtualHost>
"@
        Ok "the original vhost (C:\zpanel\panel on port $HttpPort) in $vhosts"
    } else { Ok "$vhosts is kept as it is" }
    # PHP's extensions need the libraries beside php.exe (OpenSSL, libssh2, ICU...); a service does not see a PATH
    # changed after Windows started, so Apache loads them itself
    $loadFiles = (Get-ChildItem $PhpDir -Filter '*.dll' | Where-Object { $_.Name -notmatch '^php8apache2_4\.dll$|^php8phpdbg' } |
        Sort-Object { if ($_.Name -match '^php8ts') { 1 } else { 0 } }, Name |
        ForEach-Object { 'LoadFile "' + (& $Fwd $_.FullName) + '"' }) -join "`r`n"
    $conf = Join-Path $Cfg 'apache\httpd.conf'
    Write-Utf8NoBom $conf @"
# Made by install-zpanel-stack.ps1; run it again to rewrite this file. Your own sites go in httpd-vhosts.conf.
Define SRVROOT "$(& $Fwd $ApacheDir)"
Define ZROOT "$(& $Fwd $Root)"
ServerRoot "`${SRVROOT}"
Listen $HttpPort
ServerName ${ServerName}:$HttpPort
ServerAdmin admin@localhost

LoadModule access_compat_module modules/mod_access_compat.so
LoadModule actions_module modules/mod_actions.so
LoadModule alias_module modules/mod_alias.so
LoadModule allowmethods_module modules/mod_allowmethods.so
LoadModule auth_basic_module modules/mod_auth_basic.so
LoadModule authn_core_module modules/mod_authn_core.so
LoadModule authn_file_module modules/mod_authn_file.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_groupfile_module modules/mod_authz_groupfile.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule authz_user_module modules/mod_authz_user.so
LoadModule deflate_module modules/mod_deflate.so
LoadModule dir_module modules/mod_dir.so
LoadModule env_module modules/mod_env.so
LoadModule expires_module modules/mod_expires.so
LoadModule filter_module modules/mod_filter.so
LoadModule headers_module modules/mod_headers.so
LoadModule include_module modules/mod_include.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule mime_module modules/mod_mime.so
LoadModule negotiation_module modules/mod_negotiation.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule socache_shmcb_module modules/mod_socache_shmcb.so
LoadModule ssl_module modules/mod_ssl.so
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_http_module modules/mod_proxy_http.so
LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so
LoadModule proxy_balancer_module modules/mod_proxy_balancer.so
LoadModule slotmem_shm_module modules/mod_slotmem_shm.so
LoadModule lbmethod_byrequests_module modules/mod_lbmethod_byrequests.so

# PHP 8.5 inside Apache
$loadFiles
LoadModule php_module "$(& $Fwd (Join-Path $PhpDir 'php8apache2_4.dll'))"
PHPIniDir "$(& $Fwd $PhpDir)"
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>

<IfModule mpm_winnt_module>
    ThreadsPerChild 150
    MaxConnectionsPerChild 0
</IfModule>

ServerTokens Prod
ServerSignature Off
TraceEnable Off
Timeout 120
KeepAlive On
FileETag None

<Directory />
    AllowOverride None
    Require all denied
</Directory>

DocumentRoot "`${ZROOT}/panel"
<Directory "`${ZROOT}/panel">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
$hostdataBlock

DirectoryIndex index.php index.html index.htm
<Files ".ht*">
    Require all denied
</Files>

ErrorLog "`${ZROOT}/logs/apache/error.log"
LogLevel warn
LogFormat "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\" %D" combined
CustomLog "`${ZROOT}/logs/apache/access.log" combined

TypesConfig conf/mime.types
AddType application/x-compress .Z
AddType application/x-gzip .gz .tgz
AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript application/javascript application/json application/xml image/svg+xml

Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"

# HTTPS (the port, TLS, the site for names no website claims) and the webmail's site: before the other sites, so the
# first site on the HTTPS port is the server's own
IncludeOptional "`${ZROOT}/configs/apache/ssl.conf"
# "Site not found falls back to panel login" switched off in Sentora Config: the panel's daemon writes the first site here
IncludeOptional "`${ZROOT}/configs/apache/sentora-fallback.conf"
IncludeOptional "`${ZROOT}/configs/apache/mail.conf"
Include "`${ZROOT}/configs/apache/httpd-vhosts.conf"
# the panel's websites (its domains, subdomains and parked domains), written by the panel's daemon; after yours, so the
# first site in httpd-vhosts.conf stays the one for names no site claims
IncludeOptional "`${ZROOT}/configs/apache/sentora-vhosts.conf"
# the virus check Stalwart calls for each message (written by the ClamAV part)
IncludeOptional "`${ZROOT}/configs/apache/mailscan.conf"
"@
    $httpd = Join-Path $ApacheDir 'bin\httpd.exe'
    $test = Invoke-Native $httpd @('-t', '-f', $conf)
    if ($LASTEXITCODE -ne 0) { Fail "Apache refused the settings: $($test -join ' ')" }
    Ok 'Apache accepts the settings'

    $panelIndex = Join-Path $Root 'panel\index.php'
    if (-not (Get-ChildItem (Join-Path $Root 'panel') -Force)) {
        Write-Utf8NoBom $panelIndex "<?php`r`n// Placeholder from install-zpanel-stack.ps1: replace it with your app.`r`necho 'Apache and PHP ' . PHP_VERSION . ' are working.';`r`n"
        Ok 'a placeholder index.php in panel (the folder was empty)'
    }

    Invoke-Native $httpd @('-k', 'install', '-n', $ApacheService, '-f', $conf) | Out-Null
    if (-not (Get-Service $ApacheService -ErrorAction SilentlyContinue)) { Fail 'Apache could not be installed as a service.' }
    Set-Service -Name $ApacheService -StartupType Automatic
    # (Apache after MySQL, so the app finds its database at start)
    Invoke-Native 'sc.exe' @('config', $ApacheService, 'depend=', $MySQLService) | Out-Null
    Start-Service $ApacheService
    (Get-Service $ApacheService).WaitForStatus('Running', '00:01:00')
    Ok "service $ApacheService runs (starts with Windows, after MySQL)"

    if (-not $NoFirewall) {
        Get-NetFirewallRule -DisplayName $FirewallRule -ErrorAction SilentlyContinue | Remove-NetFirewallRule
        New-NetFirewallRule -DisplayName $FirewallRule -Direction Inbound -Action Allow -Protocol TCP -LocalPort $HttpPort -Program $httpd | Out-Null
        Ok "Windows Firewall: port $HttpPort open for Apache only (MySQL stays closed)"
        if ($WithPanel -or $WithMail) {
            Get-NetFirewallRule -DisplayName $HttpsFirewall -ErrorAction SilentlyContinue | Remove-NetFirewallRule
            New-NetFirewallRule -DisplayName $HttpsFirewall -Direction Inbound -Action Allow -Protocol TCP -LocalPort "$HttpsPort" -Program $httpd | Out-Null
            Ok "Windows Firewall: port $HttpsPort (HTTPS) open for Apache only"
        }
    }

    # --------------------------------------------------------------------------------------------------------------
    if ($WithFtp) {
        Say 'FTP and SFTP (SFTPGo): its users in MySQL, TLS required on FTP'
        if (-not $RootPw) { Fail "The MySQL root password is needed for the FTP database: it is not in $pwFile." }
        $ftpDir = Join-Path $Bin 'sftpgo'
        $ftpCfg = Join-Path $Cfg 'sftpgo'
        $ftpExe = Join-Path $ftpDir 'sftpgo.exe'
        foreach ($d in @($ftpCfg, (Join-Path $ftpCfg 'env.d'), (Join-Path $ftpCfg 'certs'), (Join-Path $Logs 'sftpgo'))) {
            if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null }
        }
        # (its passwords live here: Administrators and SYSTEM only)
        Protect-Folder $ftpCfg
        $setup = Get-Verified $Pkg.Sftpgo
        Stop-AndRemoveService $FtpService
        $p = Start-Process -FilePath $setup -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', '/SP-', ('/DIR="' + $ftpDir + '"') -Wait -PassThru
        if ($p.ExitCode -ne 0) { Fail "SFTPGo's installer ended with code $($p.ExitCode)." }
        if (-not (Test-Path $ftpExe)) { Fail "SFTPGo is not in $ftpDir after its installer." }
        # (its installer starts a service of its own and opens every port for it: replaced below by ours)
        Stop-AndRemoveService $FtpService
        Get-NetFirewallRule -DisplayName 'SFTPGo Service' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
        Ok "SFTPGo -> $ftpDir"

        # its database and MySQL user (only its own database), passwords kept from an earlier run
        $envFile = Join-Path $ftpCfg 'env.d\sftpgo.env'
        $old = @{}
        if (Test-Path $envFile) { foreach ($l in Get-Content $envFile) { if ($l -match '^([A-Z_]+)=(.*)$') { $old[$Matches[1]] = $Matches[2] } } }
        $dbPw = if ($old['SFTPGO_DATA_PROVIDER__PASSWORD']) { $old['SFTPGO_DATA_PROVIDER__PASSWORD'] } else { New-Password }
        $adminPw = if ($old['SFTPGO_DEFAULT_ADMIN_PASSWORD']) { $old['SFTPGO_DEFAULT_ADMIN_PASSWORD'] } else { New-Password }
        $q = "CREATE DATABASE IF NOT EXISTS sftpgo CHARACTER SET utf8mb4;"
        foreach ($h in @('localhost', '127.0.0.1')) {
            $q += " CREATE USER IF NOT EXISTS 'sftpgo'@'$h' IDENTIFIED BY '$dbPw'; ALTER USER 'sftpgo'@'$h' IDENTIFIED BY '$dbPw'; GRANT ALL PRIVILEGES ON sftpgo.* TO 'sftpgo'@'$h';"
        }
        $env:MYSQL_PWD = $RootPw
        $out = Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-e', $q)
        Remove-Item Env:\MYSQL_PWD
        if ($LASTEXITCODE -ne 0) { Fail "Could not make the FTP database: $($out -join ' ')" }
        Write-Utf8NoBom $envFile ("# Made by install-zpanel-stack.ps1: SFTPGo reads these at start. Administrators only.`r`n" +
            "SFTPGO_DATA_PROVIDER__PASSWORD=$dbPw`r`nSFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN=true`r`n" +
            "SFTPGO_DEFAULT_ADMIN_USERNAME=admin`r`nSFTPGO_DEFAULT_ADMIN_PASSWORD=$adminPw`r`n")
        Ok 'database sftpgo and its own MySQL user (only that database)'

        # a certificate for FTPS (made once; the server's name, valid 10 years)
        $crt = Join-Path $ftpCfg 'certs\ftp.crt'; $key = Join-Path $ftpCfg 'certs\ftp.key'
        if (-not (Test-Path $crt) -or -not (Test-Path $key)) {
            $code = @'
$o = ['config' => getenv('ZCNF'), 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$k = openssl_pkey_new($o); $r = openssl_csr_new(['commonName' => $argv[3]], $k, $o);
$x = openssl_csr_sign($r, null, $k, 3650, $o, random_int(1, PHP_INT_MAX));
if (!$x || !openssl_x509_export_to_file($x, $argv[1]) || !openssl_pkey_export_to_file($k, $argv[2], null, $o)) { fwrite(STDERR, (string) openssl_error_string()); exit(1); }
echo 'ok';
'@
            $env:ZCNF = Join-Path $PhpDir 'extras\ssl\openssl.cnf'
            $out = Invoke-Native $phpExe @('-c', (Join-Path $PhpDir 'php.ini'), '-r', $code, '--', $crt, $key, $ServerName)
            Remove-Item Env:\ZCNF
            if (($out -join '') -notmatch 'ok' -or -not (Test-Path $crt)) { Fail "Could not make the FTPS certificate: $($out -join ' ')" }
            Ok "FTPS certificate for $ServerName (made here, valid 10 years)"
        }

        # (its web pages, their files and the API description: where SFTPGo's own installer puts them)
        $ftpShare = & $Fwd (Join-Path $env:ProgramData 'SFTPGo')
        foreach ($d in @('templates', 'static', 'openapi')) { if (-not (Test-Path (Join-Path $env:ProgramData "SFTPGo\$d"))) { Fail "SFTPGo's $d folder is missing in $env:ProgramData\SFTPGo." } }
        $ftpConf = @"
{
  "smtp": { "templates_path": "$ftpShare/templates" },
  "data_provider": { "driver": "mysql", "name": "sftpgo", "host": "127.0.0.1", "port": 3306, "username": "sftpgo",
                     "users_base_dir": "$(& $Fwd (Join-Path $Root 'hostdata'))" },
  "ftpd": { "bindings": [ { "port": 21, "address": "", "tls_mode": 1, "min_tls_version": 12,
                            "certificate_file": "$(& $Fwd $crt)", "certificate_key_file": "$(& $Fwd $key)" } ],
            "passive_port_range": { "start": 50000, "end": 50100 } },
  "sftpd": { "bindings": [ { "port": 2022, "address": "" } ] },
  "httpd": { "templates_path": "$ftpShare/templates", "static_files_path": "$ftpShare/static", "openapi_path": "$ftpShare/openapi",
             "bindings": [ { "port": 8080, "address": "127.0.0.1", "enable_web_admin": true, "enable_web_client": true, "enable_rest_api": true } ] }
}
"@
        Write-Utf8NoBom (Join-Path $ftpCfg 'sftpgo.json') $ftpConf
        $out = Invoke-Native $ftpExe @('service', 'install', '-c', $ftpCfg, '-l', (Join-Path $Logs 'sftpgo\sftpgo.log'))
        if (-not (Get-Service $FtpService -ErrorAction SilentlyContinue)) { Fail "SFTPGo could not be installed as a service: $($out -join ' ')" }
        Set-Service -Name $FtpService -StartupType Automatic
        Invoke-Native 'sc.exe' @('config', $FtpService, 'depend=', $MySQLService) | Out-Null
        Start-Service $FtpService
        (Get-Service $FtpService).WaitForStatus('Running', '00:01:00')
        $up = $false
        for ($i = 0; $i -lt 45 -and -not $up; $i++) {
            try { $up = (Invoke-WebRequest 'http://127.0.0.1:8080/healthz' -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200 } catch { Start-Sleep 1 }
        }
        if (-not $up) { Fail "SFTPGo does not answer: see $(Join-Path $Logs 'sftpgo\sftpgo.log')" }
        $auth = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("admin:$adminPw"))
        try { $null = Invoke-RestMethod 'http://127.0.0.1:8080/api/v2/token' -Headers @{ Authorization = $auth } -TimeoutSec 15 }
        catch { Fail "SFTPGo runs but its admin login does not work: $($_.Exception.Message)" }
        Ok "service $FtpService runs (starts with Windows, after MySQL): FTP 21 with TLS required, SFTP 2022"
        Ok 'its admin pages: http://127.0.0.1:8080/web/admin on this server only (user admin, password in its env.d file)'

        # the panel's way in: add, remove and list FTP users through SFTPGo's REST API
        Write-Utf8NoBom (Join-Path $Bin 'ftpuser.ps1') @'
<#
  FTP users of SFTPGo (made by install-zpanel-stack.ps1). As Administrator:
    ftpuser.ps1 add USER PASSWORD [FOLDER]   FOLDER: under C:\zpanel\hostdata (default: USER\public_html)
    ftpuser.ps1 remove USER                  the user goes; the files stay
    ftpuser.ps1 list
#>
param([Parameter(Mandatory = $true)][ValidateSet('add', 'remove', 'list')][string]$Do, [string]$User, [string]$Password, [string]$Folder)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$envFile = Join-Path $root 'configs\sftpgo\env.d\sftpgo.env'
$pw = ((Get-Content $envFile) | Where-Object { $_ -like 'SFTPGO_DEFAULT_ADMIN_PASSWORD=*' }) -replace '^[^=]*=', ''
$basic = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("admin:$pw"))
$token = (Invoke-RestMethod 'http://127.0.0.1:8080/api/v2/token' -Headers @{ Authorization = $basic }).access_token
$h = @{ Authorization = "Bearer $token" }
$api = 'http://127.0.0.1:8080/api/v2/users'
switch ($Do) {
    'add' {
        if ($User -notmatch '^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$' -or -not $Password) { throw 'ftpuser.ps1 add USER PASSWORD [FOLDER]' }
        if (-not $Folder) { $Folder = "$User\public_html" }
        if ($Folder -match '\.\.|^[\\/]|:') { throw 'FOLDER is a folder under C:\zpanel\hostdata' }
        $userHome = Join-Path (Join-Path $root 'hostdata') $Folder
        if (-not (Test-Path $userHome)) { New-Item -ItemType Directory -Path $userHome | Out-Null }
        $body = @{ username = $User; password = $Password; status = 1; home_dir = $userHome; permissions = @{ '/' = @('*') } } | ConvertTo-Json -Depth 4
        Invoke-RestMethod $api -Method Post -Headers $h -ContentType 'application/json' -Body $body | Out-Null
        "FTP user $User added: $userHome"
    }
    'remove' { Invoke-RestMethod "$api/$User" -Method Delete -Headers $h | Out-Null; "FTP user $User removed (the files stay)" }
    'list' { (Invoke-RestMethod "$($api)?limit=500" -Headers $h) | Select-Object username, home_dir, status | Format-Table -AutoSize }
}
'@
        Ok "C:\zpanel\bin\ftpuser.ps1: add, remove and list FTP users"

        if (-not $NoFirewall) {
            Get-NetFirewallRule -DisplayName $FtpFirewall -ErrorAction SilentlyContinue | Remove-NetFirewallRule
            New-NetFirewallRule -DisplayName $FtpFirewall -Direction Inbound -Action Allow -Protocol TCP -LocalPort @('21', '2022', '50000-50100') -Program $ftpExe | Out-Null
            Ok 'Windows Firewall: 21, 2022 and the passive ports 50000-50100 open for SFTPGo only (its admin port stays closed)'
        }
    }

    # --------------------------------------------------------------------------------------------------------------
    if ($WithMail) {
        Say "Mail for $MailDomain (Stalwart), webmail (Roundcube) and ActiveSync (Z-Push)"
        if (-not $RootPw) { Fail "The MySQL root password is needed for the webmail database: it is not in $pwFile." }
        $mailDir  = Join-Path $Bin 'stalwart'
        $mailCfg  = Join-Path $Cfg 'stalwart'
        $mailData = Join-Path $Root 'data\mail'
        $mailLogs = Join-Path $Logs 'stalwart'
        $sslCfg   = Join-Path $Cfg 'ssl'
        $apps     = Join-Path $Root 'apps'
        foreach ($d in @($mailCfg, $mailData, $mailLogs, $sslCfg, $apps, (Join-Path $Logs 'roundcube'), (Join-Path $Logs 'z-push'),
                         (Join-Path $Root 'data\z-push'), (Join-Path $Tmp 'roundcube'))) {
            if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null }
        }
        # (passwords, keys and the mail itself: Administrators and SYSTEM only; Apache and Stalwart run as SYSTEM)
        foreach ($d in @($mailCfg, $mailData, $sslCfg, (Join-Path $Root 'data\z-push'))) { Protect-Folder $d }
        $mailHost = if ($ServerName -match '\.') { $ServerName.ToLower() } else { "mail.$MailDomain" }

        # (the server's certificate, made with HTTPS before Apache's settings: configs\ssl\server.crt and server.key)

        # --- Stalwart, its service runner and the downloads for webmail and ActiveSync
        $mz = @{}
        foreach ($k in @('Stalwart', 'Nssm', 'Roundcube', 'ZPush', 'Awl', 'PhpImap')) { $mz[$k] = Get-Verified $Pkg[$k] }
        # (the three services NSSM runs stop first: their programs and NSSM itself are replaced below)
        foreach ($n in @($MailService, $ClamService, $FreshService)) { Stop-AndRemoveService $n }
        Get-Process stalwart, clamd, freshclam, nssm -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$Bin*" } | Stop-Process -Force
        Expand-Into $mz.Stalwart $Pkg.Stalwart.Top $mailDir
        # NSSM in its own folder (it runs Stalwart, ClamAV and FreshClam)
        if (-not (Test-Path (Join-Path $Bin 'nssm\nssm.exe'))) { Expand-Into $mz.Nssm $Pkg.Nssm.Top (Join-Path $Bin 'nssm') }
        $stalwart = Join-Path $mailDir 'stalwart.exe'; $nssm = Join-Path $Bin 'nssm\nssm.exe'
        Ok "Stalwart $((Invoke-Native $stalwart @('--version')) -join '') -> $mailDir"

        # --- ClamAV: the virus check for mail (clamd on 127.0.0.1:3310, its list kept fresh by freshclam); Stalwart hands each
        # message to a small page on this server's Apache (only from this server, with a key), which asks clamd
        $hookKeyFile = Join-Path $mailCfg 'mailscan-key.txt'
        $hookUrl = "http://127.0.0.1:$HttpPort/_sentora/mailscan"
        $hookKey = ''
        if ($WithClamAV) {
            $clamDir = Join-Path $Bin 'clamav'; $clamDb = Join-Path $Root 'data\clamav'; $clamLogs = Join-Path $Logs 'clamav'
            foreach ($d in @($clamDb, $clamLogs)) { if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null } }
            $cz = Get-Verified $Pkg.ClamAV
            Expand-Into $cz $Pkg.ClamAV.Top $clamDir
            Get-ChildItem $clamDir -Recurse -Include '*.pdb', '*.lib' | Remove-Item -Force   # (debug files: 700 MB not needed)
            Remove-Item (Join-Path $clamDir 'UserManual'), (Join-Path $clamDir 'include') -Recurse -Force -ErrorAction SilentlyContinue
            $clamdConf = Join-Path $clamDir 'clamd.conf'; $freshConf = Join-Path $clamDir 'freshclam.conf'
            Write-Utf8NoBom $clamdConf @"
# Made by install-zpanel-stack.ps1; run it again to rewrite this file.
LogFile $(& $Fwd (Join-Path $clamLogs 'clamd.log'))
LogFileMaxSize 10M
LogRotate yes
LogTime yes
DatabaseDirectory $(& $Fwd $clamDb)
# (this server only: Stalwart's check, through Apache)
TCPSocket 3310
TCPAddr 127.0.0.1
MaxThreads 8
StreamMaxLength 64M
MaxFileSize 64M
MaxScanSize 200M
SelfCheck 600
# (the panel's SpamAV Admin changes these; ClamAV's own defaults)
MaxRecursion 17
MaxFiles 10000
ScanArchive yes
ScanPDF yes
ScanOLE2 yes
OLE2BlockMacros no
ScanHTML yes
PhishingSignatures yes
PhishingScanURLs yes
HeuristicAlerts yes
AlertEncrypted no
"@
            Write-Utf8NoBom $freshConf @"
# Made by install-zpanel-stack.ps1; run it again to rewrite this file.
DatabaseDirectory $(& $Fwd $clamDb)
UpdateLogFile $(& $Fwd (Join-Path $clamLogs 'freshclam.log'))
LogFileMaxSize 10M
LogRotate yes
LogTime yes
DatabaseMirror database.clamav.net
Checks 12
"@
            $fresh = Join-Path $clamDir 'freshclam.exe'
            if (-not ((Test-Path (Join-Path $clamDb 'main.cvd')) -or (Test-Path (Join-Path $clamDb 'main.cld')))) {
                Note 'downloading the virus list (about 250 MB, once) ...'
                # (three tries: a network still settling after a restart, or the download site refusing a new address for a
                #  moment, is often gone a minute later)
                for ($try = 1; $try -le 3; $try++) {
                    $out = Invoke-Native $fresh @("--config-file=$freshConf", '--stdout')
                    if ((Test-Path (Join-Path $clamDb 'main.cvd')) -or (Test-Path (Join-Path $clamDb 'main.cld'))) { break }
                    if ($try -lt 3) { Note "   the download did not work (try $try of 3); trying again in $(30 * $try) seconds ..."; Start-Sleep -Seconds (30 * $try) }
                }
            }
            if (-not ((Test-Path (Join-Path $clamDb 'main.cvd')) -or (Test-Path (Join-Path $clamDb 'main.cld')))) {
                # (the rest of the install goes on without the virus check: mail works, unchecked; a later run of this
                #  installer tries the download again and turns the check on once the list is there)
                $clamWhy = (($out | Where-Object { $_ -match 'ERROR|WARNING' } | Select-Object -First 3) -join ' ')
                Write-Host "   NOTE ClamAV's virus list did not download from database.clamav.net, so mail is not checked for viruses yet." -ForegroundColor Yellow
                Write-Host "        $clamWhy" -ForegroundColor Yellow
                Write-Host '        A firewall that filters or inspects HTTPS (FortiGate and the like) often stops it: allow database.clamav.net' -ForegroundColor Yellow
                Write-Host '        for this server, then run this installer again (a repair: it keeps everything) to turn the check on.' -ForegroundColor Yellow
                $WithClamAV = [switch]$false; $clamSkipped = $true
            }
        }
        if ($WithClamAV) {
            Ok "ClamAV $((Invoke-Native (Join-Path $clamDir 'clamd.exe') @('--version')) -join '') -> $clamDir, its virus list in $clamDb"
            foreach ($svc in @(@($ClamService, (Join-Path $clamDir 'clamd.exe'), "--foreground --config-file=$clamdConf", 'ClamAV virus scanner (for mail, 127.0.0.1:3310)'),
                               @($FreshService, $fresh, "--foreground --daemon --config-file=$freshConf", "ClamAV's virus list, updated 12 times a day"))) {
                Invoke-Native $nssm @('install', $svc[0], $svc[1]) | Out-Null
                if (-not (Get-Service $svc[0] -ErrorAction SilentlyContinue)) { Fail "$($svc[0]) could not be installed as a service." }
                foreach ($kv in @(@('AppParameters', $svc[2]), @('AppDirectory', $clamDir), @('Description', $svc[3]), @('Start', 'SERVICE_AUTO_START'),
                                  @('AppStdout', (Join-Path $clamLogs "$($svc[0].ToLower())-service.log")), @('AppStderr', (Join-Path $clamLogs "$($svc[0].ToLower())-service.log")),
                                  @('AppRotateFiles', '1'), @('AppRotateOnline', '1'), @('AppRotateBytes', '10485760'))) {
                    $null = Invoke-Native $nssm @('set', $svc[0], $kv[0], $kv[1])
                }
                Start-Service $svc[0]
            }
            # (clamd reads the whole list into memory first: a minute or two)
            $up = $false
            for ($i = 0; $i -lt 300 -and -not $up; $i++) {
                try { $c = New-Object Net.Sockets.TcpClient('127.0.0.1', 3310); $st = $c.GetStream(); $b = [Text.Encoding]::ASCII.GetBytes("zPING`0"); $st.Write($b, 0, $b.Length)
                      $buf = New-Object byte[] 16; $n = $st.Read($buf, 0, 16); $c.Close(); $up = ([Text.Encoding]::ASCII.GetString($buf, 0, $n) -match 'PONG') } catch { Start-Sleep 1 }
            }
            if (-not $up) { Fail "ClamAV does not answer on 127.0.0.1:3310: see $clamLogs" }
            Ok "services $ClamService and $FreshService run (start with Windows): clamd on 127.0.0.1:3310 only"

            # the page Stalwart calls (C:\zpanel\apps\mailscan), reachable from this server only and only with the key
            $hookKey = if (Test-Path $hookKeyFile) { (Get-Content $hookKeyFile -Raw).Trim() } else { '' }
            if ($hookKey -notmatch '^[A-Za-z0-9]{24}$') { $hookKey = New-Password; Write-Utf8NoBom $hookKeyFile $hookKey }
            $scanDir = Join-Path $apps 'mailscan'
            if (-not (Test-Path $scanDir)) { New-Item -ItemType Directory -Path $scanDir | Out-Null }
            Write-Utf8NoBom (Join-Path $scanDir 'scan.php') (@'
<?php
// Sentora-2027 for Windows: Stalwart's MTA hook (stage data) asks here about each message; ClamAV (clamd, 127.0.0.1:3310)
// answers. A virus: the message is refused (554 5.7.1). ClamAV not answering: Stalwart answers "try later" (the mail is
// not lost, the sending server tries again). Made by install-zpanel-stack.ps1. GPL-3.0.
header('Content-Type: application/json');
$key = trim((string) @file_get_contents('KEYFILE'));
if ($key === '' || !hash_equals($key, (string) ($_SERVER['HTTP_X_SENTORA_KEY'] ?? ''))) { http_response_code(403); exit; }
// the panel's SpamAV Admin (written by its daemon): scanning on or off, and ClamAV not answering = accept or try later
$opt = json_decode((string) @file_get_contents(__DIR__ . '/options.json'), true);
if (is_array($opt) && empty($opt['enabled'])) { echo '{"action":"accept"}'; exit; }
$fail = function () use ($opt) { if (is_array($opt) && ($opt['on_fail'] ?? '') === 'accept') { echo '{"action":"accept"}'; } else { http_response_code(503); } exit; };
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in) || !isset($in['message']['contents'])) { echo '{"action":"accept"}'; exit; }
$raw = '';
foreach ((array) ($in['message']['headers'] ?? []) as $h) {
    $v = (string) ($h[1] ?? '');
    $raw .= $h[0] . ':' . $v . (substr($v, -1) === "\n" ? '' : "\r\n");
}
$raw .= "\r\n" . $in['message']['contents'];
$s = @stream_socket_client('tcp://127.0.0.1:3310', $en, $es, 10);
if (!$s) { $fail(); }
stream_set_timeout($s, 120);
fwrite($s, "zINSTREAM\0");
for ($i = 0, $n = strlen($raw); $i < $n; $i += 65536) {
    $c = substr($raw, $i, 65536);
    fwrite($s, pack('N', strlen($c)) . $c);
}
fwrite($s, pack('N', 0));
// (the answer ends with a NUL byte; clamd may keep the connection open after it)
$r = '';
while (!feof($s) && strpos($r, "\0") === false) {
    $c = fread($s, 4096);
    if ($c === false || ($c === '' && stream_get_meta_data($s)['timed_out'])) { break; }
    $r .= $c;
}
fclose($s);
$r = trim($r, "\0\r\n ");
if (preg_match('/^stream: (.+) FOUND$/', $r, $m)) {
    $who = isset($in['envelope']['from']['address']) ? $in['envelope']['from']['address'] : '?';
    @file_put_contents('LOGFILE', date('c') . " refused: $m[1] from $who\r\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['action' => 'reject', 'response' => ['status' => 554, 'enhanced_status' => '5.7.1',
        'message' => 'Message refused: virus found (' . preg_replace('/[^\w.\-]/', '', $m[1]) . ')']]);
} elseif ($r === 'stream: OK') {
    echo '{"action":"accept"}';
} else {
    $fail();   // (size limit, error: as SpamAV Admin says, try later by default)
}
'@).Replace('KEYFILE', (& $Fwd $hookKeyFile)).Replace('LOGFILE', (& $Fwd (Join-Path $clamLogs 'mail-refused.log')))
            Write-Utf8NoBom (Join-Path $Cfg 'apache\mailscan.conf') @"
# Made by install-zpanel-stack.ps1 (ClamAV); run it again to rewrite this file.
# Stalwart's virus check: on every site and port, but from this server only (and Stalwart sends a key)
Alias /_sentora/mailscan "`${ZROOT}/apps/mailscan/scan.php"
<Directory "`${ZROOT}/apps/mailscan">
    Require ip 127.0.0.1 ::1
</Directory>
"@
            $test = Invoke-Native $httpd @('-t', '-f', $conf)
            if ($LASTEXITCODE -ne 0) { Fail "Apache refused the virus check settings: $($test -join ' ')" }
            Restart-Service $ApacheService
            (Get-Service $ApacheService).WaitForStatus('Running', '00:01:00')
            $probe = $null
            try { $probe = Invoke-WebRequest $hookUrl -Method Post -Headers @{ 'X-Sentora-Key' = $hookKey } -Body '{}' -ContentType 'application/json' -UseBasicParsing -TimeoutSec 30 } catch { }
            if (-not $probe -or $probe.Content -notmatch 'accept') { Fail "The virus check page does not answer at $hookUrl" }
            Ok "the virus check page answers ($hookUrl, this server only, with its key)"
        } else {
            Remove-Item (Join-Path $Cfg 'apache\mailscan.conf') -Force -ErrorAction SilentlyContinue
        }

        # its own settings file names only where its database is (RocksDB in C:\zpanel\data\mail); all else lives in it
        $mailConf = Join-Path $mailCfg 'config.json'
        Write-Utf8NoBom $mailConf ('{"@type":"RocksDb","path":"' + (& $Fwd $mailData) + '/"}')

        # the settings themselves go in through its API, with it in recovery mode: only that one port open (8091, an
        # address the firewall keeps closed), no mail ports yet, a one-time password for this run only
        Add-Type -TypeDefinition @'
using System.Net; using System.Net.Security; using System.Security.Cryptography.X509Certificates;
public static class ZLoopTls {
    // the server's own certificate is trusted on 127.0.0.1 only (Stalwart's admin API on this server)
    public static bool Check(object s, X509Certificate c, X509Chain ch, SslPolicyErrors e) {
        if (e == SslPolicyErrors.None) return true;
        HttpWebRequest r = s as HttpWebRequest; return r != null && r.RequestUri.IsLoopback;
    }
    public static RemoteCertificateValidationCallback Callback { get { return Check; } }
}
'@ -ErrorAction SilentlyContinue
        [Net.ServicePointManager]::ServerCertificateValidationCallback = [ZLoopTls]::Callback
        function Invoke-Stalwart($base, $user, $pw, [object[]]$calls) {
            $auth = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("${user}:$pw"))
            $sess = Invoke-RestMethod "$base/jmap/session" -Headers @{ Authorization = $auth } -TimeoutSec 30
            $acct = $sess.primaryAccounts.'urn:stalwart:jmap'
            $i = 0
            $mc = foreach ($c in $calls) { $a = $c.a; $a['accountId'] = $acct; '["' + $c.m + '",' + ($a | ConvertTo-Json -Depth 20 -Compress) + ',"c' + ($i++) + '"]' }
            $body = '{"using":["urn:ietf:params:jmap:core","urn:stalwart:jmap"],"methodCalls":[' + (@($mc) -join ',') + ']}'
            $r = Invoke-RestMethod "$base/jmap/" -Method Post -Headers @{ Authorization = $auth } -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($body)) -TimeoutSec 60
            foreach ($x in $r.methodResponses) {
                # (a field Stalwart leaves out is read as nothing: this script runs in strict mode)
                $bad = @('notCreated', 'notUpdated', 'notDestroyed') | ForEach-Object { $x[1].PSObject.Properties[$_] } |
                    Where-Object { $_ -and $_.Value -and @($_.Value.PSObject.Properties).Count }
                if ($x[0] -eq 'error' -or $bad) { Fail "Stalwart refused $($x[0]): $(($x[1] | ConvertTo-Json -Depth 8 -Compress))" }
            }
            return ,@($r.methodResponses)   # (the comma: a list of one answer stays a list)
        }
        function Get-List($base, $user, $pw, $what, $props) {
            $r = Invoke-Stalwart $base $user $pw @(@{ m = "$what/get"; a = @{ properties = $props } })
            return @($r[0][1].list)
        }

        $adminFile = Join-Path $mailCfg 'admin.txt'
        $adminUser = "admin@$MailDomain"; $adminPw = ''
        if (Test-Path $adminFile) {
            foreach ($l in Get-Content $adminFile) { if ($l -match '^user=(.+)$') { $adminUser = $Matches[1] } elseif ($l -match '^password=(.+)$') { $adminPw = $Matches[1] } }
        }
        $setupPw = New-Password
        $env:STALWART_RECOVERY_MODE = '1'; $env:STALWART_RECOVERY_MODE_PORT = '8091'; $env:STALWART_RECOVERY_ADMIN = "setup:$setupPw"
        try {
            $rec = Start-Process -FilePath $stalwart -ArgumentList ('--config="' + $mailConf + '"') -WorkingDirectory $mailDir -WindowStyle Hidden -PassThru `
                -RedirectStandardOutput (Join-Path $mailLogs 'setup-out.log') -RedirectStandardError (Join-Path $mailLogs 'setup.log')
        } finally { Remove-Item Env:\STALWART_RECOVERY_MODE, Env:\STALWART_RECOVERY_MODE_PORT, Env:\STALWART_RECOVERY_ADMIN -ErrorAction SilentlyContinue }
        $rb = 'http://127.0.0.1:8091'
        try {
            $up = $false
            for ($i = 0; $i -lt 60 -and -not $up; $i++) {
                if ($rec.HasExited) { Fail "Stalwart stopped while starting: see $(Join-Path $mailLogs 'setup.log')" }
                try { $null = Invoke-RestMethod "$rb/jmap/session" -Headers @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("setup:$setupPw")) } -TimeoutSec 5; $up = $true } catch { Start-Sleep 1 }
            }
            if (-not $up) { Fail "Stalwart's setup port does not answer: see $(Join-Path $mailLogs 'setup.log')" }

            # the ports: mail to the world, its web side (calendars, contacts, admin) on this server only, for Apache
            $want = [ordered]@{
                smtp        = @{ bind = '0.0.0.0:25';     protocol = 'smtp'; implicitTls = $false }
                submission  = @{ bind = '0.0.0.0:587';    protocol = 'smtp'; implicitTls = $false }
                submissions = @{ bind = '0.0.0.0:465';    protocol = 'smtp'; implicitTls = $true }
                imap        = @{ bind = '0.0.0.0:143';    protocol = 'imap'; implicitTls = $false }
                imaps       = @{ bind = '0.0.0.0:993';    protocol = 'imap'; implicitTls = $true }
                pop3s       = @{ bind = '0.0.0.0:995';    protocol = 'pop3'; implicitTls = $true }
                https       = @{ bind = '127.0.0.1:8443'; protocol = 'http'; implicitTls = $true }
            }
            $have = Get-List $rb 'setup' $setupPw 'x:NetworkListener' @('name')
            $create = @{}; $update = @{}; $destroy = @()
            foreach ($n in $want.Keys) {
                $v = @{ bind = @{ ($want[$n].bind) = $true }; protocol = $want[$n].protocol; implicitTls = $want[$n].implicitTls }
                $h = $have | Where-Object { $_.name -eq $n } | Select-Object -First 1
                if ($h) { $update[$h.id] = $v } else { $v['name'] = $n; $create[$n] = $v }
            }
            # (Stalwart's own defaults that clash or are not used here: 8080 is SFTPGo's, ManageSieve stays closed)
            $destroy = @($have | Where-Object { @('http', 'sieve', 'pop3') -contains $_.name } | ForEach-Object { $_.id })
            $calls = @(@{ m = 'x:NetworkListener/set'; a = @{ create = $create; update = $update; destroy = $destroy } })

            # its log in C:\zpanel\logs\stalwart
            $tr = Get-List $rb 'setup' $setupPw 'x:Tracer' @('path')
            $tv = @{ path = (& $Fwd $mailLogs); prefix = 'stalwart.log'; rotate = 'daily'; ansi = $false; level = 'info'; enable = $true }
            if ($tr) { $calls += @{ m = 'x:Tracer/set'; a = @{ update = @{ ($tr[0].id) = $tv } } } }
            else { $tv['@type'] = 'Log'; $calls += @{ m = 'x:Tracer/set'; a = @{ create = @{ t = $tv } } } }

            # this server's own address is never blocked (webmail and ActiveSync sign in from it for every user)
            $ips = Get-List $rb 'setup' $setupPw 'x:AllowedIp' @('address')
            $ipc = @{}
            foreach ($a in @('127.0.0.1', '::1')) { if (-not ($ips | Where-Object { $_.address -eq $a -or $_.address -like "$a/*" })) { $ipc["ip$($ipc.Count)"] = @{ address = $a; reason = 'this server: webmail and ActiveSync' } } }
            if ($ipc.Count) { $calls += @{ m = 'x:AllowedIp/set'; a = @{ create = $ipc } } }

            # the certificate files above
            $certs = Get-List $rb 'setup' $setupPw 'x:Certificate' @('certificate')
            $mine = $certs | Where-Object { $_.PSObject.Properties['certificate'] -and $_.certificate.PSObject.Properties['filePath'] -and $_.certificate.filePath -eq (& $Fwd $srvCrt) } | Select-Object -First 1
            if (-not $mine) {
                $calls += @{ m = 'x:Certificate/set'; a = @{ create = @{ crt = @{
                    certificate = @{ '@type' = 'File'; filePath = (& $Fwd $srvCrt) }; privateKey = @{ '@type' = 'File'; filePath = (& $Fwd $srvKey) } } } } }
            }
            # the mail domain
            $doms = Get-List $rb 'setup' $setupPw 'x:Domain' @('name')
            $dom = $doms | Where-Object { $_.name -eq $MailDomain } | Select-Object -First 1
            if (-not $dom) { $calls += @{ m = 'x:Domain/set'; a = @{ create = @{ dom = @{ name = $MailDomain } } } } }
            # the virus check (ClamAV) for every message, or none
            $hooks = Get-List $rb 'setup' $setupPw 'x:MtaHook' @('url')
            $myHook = $hooks | Where-Object { $_.url -like '*/_sentora/mailscan' } | Select-Object -First 1
            if ($WithClamAV) {
                $hv = @{ url = $hookUrl; stages = @{ data = $true }; httpHeaders = @{ 'X-Sentora-Key' = $hookKey }; timeout = 120000; tempFailOnError = $true; enable = @{ else = 'true' } }
                if ($myHook) { $calls += @{ m = 'x:MtaHook/set'; a = @{ update = @{ ($myHook.id) = $hv } } } }
                else { $calls += @{ m = 'x:MtaHook/set'; a = @{ create = @{ hook = $hv } } } }
            } elseif ($myHook) { $calls += @{ m = 'x:MtaHook/set'; a = @{ destroy = @($myHook.id) } } }
            $res = Invoke-Stalwart $rb 'setup' $setupPw $calls
            $certId = if ($mine) { $mine.id } else { ($res | Where-Object { $_[0] -eq 'x:Certificate/set' })[1].created.crt.id }
            $domId  = if ($dom) { $dom.id } else { ($res | Where-Object { $_[0] -eq 'x:Domain/set' })[1].created.dom.id }

            # the panel's administrator in Stalwart (its password in admin.txt, Administrators only)
            $accts = Get-List $rb 'setup' $setupPw 'x:Account' @('name', 'emailAddress')
            $adm = $accts | Where-Object { $_.emailAddress -eq $adminUser } | Select-Object -First 1
            $calls = @(@{ m = 'x:SystemSettings/set'; a = @{ update = @{ singleton = @{ defaultHostname = $mailHost; defaultDomainId = $domId; defaultCertificateId = $certId } } } })
            if (-not $adm) {
                $adminUser = "admin@$MailDomain"; $adminPw = New-Password
                $calls += @{ m = 'x:Account/set'; a = @{ create = @{ adm = @{ '@type' = 'User'; name = 'admin'; domainId = $domId; roles = @{ '@type' = 'Admin' }
                                                                          credentials = @{ '0' = @{ '@type' = 'Password'; secret = $adminPw } } } } } }
            } elseif (-not $adminPw) {
                $adminPw = New-Password
                $calls += @{ m = 'x:Account/set'; a = @{ update = @{ ($adm.id) = @{ credentials = @{ '0' = @{ '@type' = 'Password'; secret = $adminPw } } } } } }
            }
            $null = Invoke-Stalwart $rb 'setup' $setupPw $calls
            Write-Utf8NoBom $adminFile ("# Stalwart's administrator (made by install-zpanel-stack.ps1; used by mailuser.ps1). Administrators only.`r`nuser=$adminUser`r`npassword=$adminPw`r`n")
            Ok "Stalwart set up: domain $MailDomain, host name $mailHost, ports 25, 587, 465, 143, 993, 995; admin $adminUser$(if ($WithClamAV) { '; every message checked by ClamAV' })"
        } finally {
            if (-not $rec.HasExited) { Stop-Process -Id $rec.Id -Force; $rec.WaitForExit(15000) | Out-Null }
        }

        # its service (Stalwart has no Windows service mode of its own: NSSM runs it, as Stalwart's guide does)
        # (the folder has no spaces under C:\zpanel; NSSM hands the line to Stalwart as it is)
        Invoke-Native $nssm @('install', $MailService, $stalwart, "--config=$mailConf") | Out-Null
        if (-not (Get-Service $MailService -ErrorAction SilentlyContinue)) { Fail 'Stalwart could not be installed as a service.' }
        foreach ($kv in @(@('AppDirectory', $mailDir), @('DisplayName', 'Stalwart mail server'), @('Description', 'Mail (SMTP, IMAP, POP3, CalDAV, CardDAV) for Sentora, run by NSSM'),
                          @('Start', 'SERVICE_AUTO_START'), @('AppStdout', (Join-Path $mailLogs 'service.log')), @('AppStderr', (Join-Path $mailLogs 'service.log')),
                          @('AppRotateFiles', '1'), @('AppRotateOnline', '1'), @('AppRotateBytes', '10485760'), @('AppStopMethodSkip', '0'))) {
            $null = Invoke-Native $nssm @('set', $MailService, $kv[0], $kv[1])
        }
        Start-Service $MailService
        (Get-Service $MailService).WaitForStatus('Running', '00:01:00')
        $up = $false
        for ($i = 0; $i -lt 60 -and -not $up; $i++) {
            try { $null = Invoke-Stalwart 'https://127.0.0.1:8443' $adminUser $adminPw @(@{ m = 'x:Domain/get'; a = @{ properties = @('name') } }); $up = $true } catch { Start-Sleep 1 }
        }
        if (-not $up) { Fail "Stalwart runs but does not answer on 127.0.0.1:8443: see $mailLogs" }
        $open = $MailPorts | Where-Object { -not (Get-NetTCPConnection -LocalPort $_ -State Listen -ErrorAction SilentlyContinue) }
        if ($open) { Fail "Stalwart is not listening on port(s) $($open -join ', '): see $mailLogs" }
        Ok "service $MailService runs (starts with Windows): its admin pages on https://127.0.0.1:8443/admin (this server only)"

        # --- webmail: Roundcube in C:\zpanel\apps\roundcube, its database in MySQL
        $rc = Join-Path $apps 'roundcube'
        $rcCfgFile = Join-Path $mailCfg 'roundcube.inc.php'
        $rcPanelFile = Join-Path $mailCfg 'roundcube-panel.inc.php'
        $rcOld = if (Test-Path $rcCfgFile) { Get-Content $rcCfgFile -Raw } else { '' }
        $rcDbPw  = if ($rcOld -match "mysql://roundcube:([A-Za-z0-9]+)@") { $Matches[1] } else { New-Password }
        $rcDes   = if ($rcOld -match "'des_key'\] = '([A-Za-z0-9]+)'") { $Matches[1] } else { New-Password }
        Expand-Into $mz.Roundcube $Pkg.Roundcube.Top $rc
        Remove-Item (Join-Path $rc 'installer') -Recurse -Force -ErrorAction SilentlyContinue
        Write-Utf8NoBom $rcCfgFile @"
<?php
// Roundcube settings, made by install-zpanel-stack.ps1 (run it again to rewrite; the passwords are kept). Administrators only.
`$config = [];
`$config['db_dsnw'] = 'mysql://roundcube:$rcDbPw@127.0.0.1/roundcube';
`$config['des_key'] = '$rcDes';
// Stalwart on this server (its own certificate, trusted for 127.0.0.1 only)
`$config['imap_host'] = 'ssl://127.0.0.1:993';
`$config['smtp_host'] = 'ssl://127.0.0.1:465';
`$config['smtp_user'] = '%u';
`$config['smtp_pass'] = '%p';
`$config['imap_conn_options'] = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
`$config['smtp_conn_options'] = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
`$config['username_domain'] = '$MailDomain';
`$config['mail_domain'] = '$MailDomain';
`$config['product_name'] = 'Webmail';
`$config['support_url'] = '';
`$config['skin'] = 'elastic';
`$config['plugins'] = ['archive', 'zipdownload'];
`$config['temp_dir'] = '$(& $Fwd (Join-Path $Tmp 'roundcube'))/';
`$config['log_dir'] = '$(& $Fwd (Join-Path $Logs 'roundcube'))/';
`$config['use_https'] = true;
`$config['ip_check'] = true;
`$config['login_rate_limit'] = 3;
`$config['enable_installer'] = false;
`$config['drafts_mbox'] = 'Drafts';
`$config['junk_mbox'] = 'Junk Mail';
`$config['sent_mbox'] = 'Sent Items';
`$config['trash_mbox'] = 'Deleted Items';
// the panel's Admin > Mail Config (language, time zone, new-mail check, largest message, log out): written by its daemon
if (is_readable('$(& $Fwd $rcPanelFile)')) { include '$(& $Fwd $rcPanelFile)'; }
"@
        if (-not (Test-Path $rcPanelFile)) { Write-Utf8NoBom $rcPanelFile "<?php`r`n// Made by the panel's daemon from Admin > Mail Config: change the values there (this file is written again).`r`n" }
        Write-Utf8NoBom (Join-Path $rc 'config\config.inc.php') ("<?php`r`n// install-zpanel-stack.ps1: the settings are in C:\zpanel\configs\stalwart (Administrators only)`r`nrequire '" + (& $Fwd $rcCfgFile) + "';`r`n")
        $q = "CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        foreach ($h in @('localhost', '127.0.0.1')) {
            $q += " CREATE USER IF NOT EXISTS 'roundcube'@'$h' IDENTIFIED BY '$rcDbPw'; ALTER USER 'roundcube'@'$h' IDENTIFIED BY '$rcDbPw'; GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'$h';"
        }
        $env:MYSQL_PWD = $RootPw
        try {
            $out = Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-e', $q)
            if ($LASTEXITCODE -ne 0) { Fail "Could not make the webmail database: $($out -join ' ')" }
            $n = (Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-N', '-e', "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='roundcube' AND table_name='system'")) -join ''
            if ($n.Trim() -eq '0') {
                $out = Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', 'roundcube', '-e', ('source ' + (& $Fwd (Join-Path $rc 'SQL\mysql.initial.sql'))))
                if ($LASTEXITCODE -ne 0) { Fail "Could not fill the webmail database: $($out -join ' ')" }
                Ok 'database roundcube made, with its own MySQL user (only that database)'
            } else {
                $out = Invoke-Native $phpExe @('-c', (Join-Path $PhpDir 'php.ini'), (Join-Path $rc 'bin\updatedb.sh'), '--package=roundcube', ('--dir=' + (Join-Path $rc 'SQL')))
                if ($LASTEXITCODE -ne 0) { Fail "Could not bring the webmail database up to date: $($out -join ' ')" }
                Ok 'database roundcube kept (brought up to date)'
            }
        } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
        Ok "Roundcube $($Pkg.Roundcube.Name -replace '^Roundcube (\S+).*', '$1') -> $rc"

        # --- ActiveSync: Z-Push in C:\zpanel\apps\z-push, PHP's imap extension, mail from IMAP, calendars from CalDAV,
        # contacts from CardDAV (all Stalwart)
        $imapStage = Join-Path $Tmp 'php-imap'
        Expand-Into $mz.PhpImap $Pkg.PhpImap.Top $imapStage
        Copy-Item (Join-Path $imapStage 'php_imap.dll') (Join-Path $PhpDir 'ext\php_imap.dll') -Force
        Remove-Item $imapStage -Recurse -Force
        $phpIni = Join-Path $PhpDir 'php.ini'
        $ini = Get-Content $phpIni -Raw
        if ($ini -notmatch '(?m)^\s*extension\s*=\s*imap\s*$') { Write-Utf8NoBom $phpIni ($ini.TrimEnd() + "`r`n; (ActiveSync: Z-Push reads mail over IMAP)`r`nextension=imap`r`n") }
        $mods = (Invoke-Native $phpExe @('-c', $phpIni, '-m')) -join ' '
        if ($mods -notmatch '(?i)\bimap\b') { Fail 'PHP did not load the imap extension (php_imap.dll).' }
        Ok 'PHP: imap extension (PECL imap 1.0.3) loaded'

        $zp = Join-Path $apps 'z-push'
        Expand-Into $mz.ZPush $Pkg.ZPush.Top $zp
        New-Item -ItemType Directory -Path (Join-Path $zp 'awl') -Force | Out-Null
        Expand-Into $mz.Awl $Pkg.Awl.Top (Join-Path $zp 'awl\inc')
        Write-Utf8NoBom (Join-Path $zp 'zpush-sentora.php') @'
<?php
/*
 * Sentora-2027 for Windows: loaded first by Z-Push's config.php (install-zpanel-stack.ps1 puts the line there). GPL-3.0.
 * - The AWL library (DAViCal's, needed by the CalDAV backend) is in Z-Push's own awl\inc, not /usr/share/awl/inc.
 * - IpcFileProvider: Z-Push's shared data between requests kept in files, for Windows (and any system) where PHP has
 *   neither System V semaphores (sem_get) nor WinCache. A mutex is an exclusive flock() on a lock file; each value is a
 *   serialized file written whole under a lock.
 */
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . DIRECTORY_SEPARATOR . 'awl' . DIRECTORY_SEPARATOR . 'inc');

class IpcFileProvider implements IIpcProvider {
    private $dir;
    private $type;
    private $lock = null;

    public function __construct($type, $allocate, $class, $serverKey) {
        $this->type = (string) $type;
        $this->dir = rtrim(STATE_DIR, '/\\') . DIRECTORY_SEPARATOR . 'ipc';
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new FatalMisconfigurationException(sprintf("IpcFileProvider: can not create '%s'", $this->dir));
        }
    }

    private function path($id) {
        // one file per (type, id); hashed so device ids and user names never reach the file system as names
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $this->type . ':' . $id) . ($id === 'lock' ? '.lock' : '.dat');
    }

    public function ReInitIPC() {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.dat') ?: array() as $f) {
            @unlink($f);
        }
        return true;
    }

    public function Clean() {
        return false;
    }

    public function IsActive() {
        return is_dir($this->dir) && is_writable($this->dir);
    }

    public function BlockMutex() {
        if ($this->lock !== null) {
            return true;
        }
        $h = @fopen($this->path('lock'), 'c');
        if ($h === false || !flock($h, LOCK_EX)) {
            if ($h) fclose($h);
            ZLog::Write(LOGLEVEL_INFO, sprintf("IpcFileProvider->BlockMutex(): could not lock '%s'", $this->type));
            return false;
        }
        $this->lock = $h;
        return true;
    }

    public function ReleaseMutex() {
        if ($this->lock === null) {
            return false;
        }
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
        $this->lock = null;
        return true;
    }

    public function HasData($id = 2) {
        return is_file($this->path($id));
    }

    public function GetData($id = 2) {
        $h = @fopen($this->path($id), 'r');
        if ($h === false) {
            return null;
        }
        flock($h, LOCK_SH);
        $s = stream_get_contents($h);
        flock($h, LOCK_UN);
        fclose($h);
        if ($s === false || $s === '') {
            return null;
        }
        $v = @unserialize($s);
        return ($v === false && $s !== serialize(false)) ? null : $v;
    }

    public function SetData($data, $id = 2) {
        $h = @fopen($this->path($id), 'c');
        if ($h === false || !flock($h, LOCK_EX)) {
            if ($h) fclose($h);
            ZLog::Write(LOGLEVEL_INFO, sprintf("IpcFileProvider->SetData(): could not write '%s:%s'", $this->type, $id));
            return false;
        }
        ftruncate($h, 0);
        rewind($h);
        $ok = fwrite($h, serialize($data)) !== false;
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
        return $ok;
    }
}
'@
        # which backend serves which kind of folder
        $comb = Join-Path $zp 'backend\combined\config.php'
        $cs = Get-Content $comb -Raw
        $cs = [regex]::Replace($cs, '(?s)class BackendCombinedConfig \{.*$', @'
class BackendCombinedConfig {
    // Sentora-2027 for Windows: mail over IMAP, calendars and tasks over CalDAV, contacts over CardDAV (all from Stalwart)
    public static function GetBackendCombinedConfig() {
        return array(
            'backends' => array(
                'i' => array('name' => 'BackendIMAP'),
                'c' => array('name' => 'BackendCalDAV'),
                'd' => array('name' => 'BackendCardDAV'),
            ),
            'delimiter' => '/',
            'folderbackend' => array(
                SYNC_FOLDER_TYPE_INBOX => 'i',
                SYNC_FOLDER_TYPE_DRAFTS => 'i',
                SYNC_FOLDER_TYPE_WASTEBASKET => 'i',
                SYNC_FOLDER_TYPE_SENTMAIL => 'i',
                SYNC_FOLDER_TYPE_OUTBOX => 'i',
                SYNC_FOLDER_TYPE_TASK => 'c',
                SYNC_FOLDER_TYPE_APPOINTMENT => 'c',
                SYNC_FOLDER_TYPE_CONTACT => 'd',
                SYNC_FOLDER_TYPE_NOTE => 'i',
                SYNC_FOLDER_TYPE_JOURNAL => 'i',
                SYNC_FOLDER_TYPE_OTHER => 'i',
                SYNC_FOLDER_TYPE_USER_MAIL => 'i',
                SYNC_FOLDER_TYPE_USER_APPOINTMENT => 'c',
                SYNC_FOLDER_TYPE_USER_CONTACT => 'd',
                SYNC_FOLDER_TYPE_USER_TASK => 'c',
                SYNC_FOLDER_TYPE_USER_JOURNAL => 'i',
                SYNC_FOLDER_TYPE_USER_NOTE => 'i',
                SYNC_FOLDER_TYPE_UNKNOWN => 'i',
            ),
            'rootcreatefolderbackend' => 'i',
        );
    }
}
'@)
        Write-Utf8NoBom $comb $cs
        # the settings: each define('NAME', ...) line set to ours; {const}: one of Z-Push's own constants; $null: turned off
        $zset = [ordered]@{
            'config.php' = [ordered]@{ TIMEZONE = $TimeZone; USE_FULLEMAIL_FOR_LOGIN = $true; STATE_DIR = ((& $Fwd (Join-Path $Root 'data\z-push')) + '/')
                LOGFILEDIR = ((& $Fwd (Join-Path $Logs 'z-push')) + '/'); LOGLEVEL = @{ const = 'LOGLEVEL_INFO' }; BACKEND_PROVIDER = 'BackendCombined'
                LOG_SYSLOG_FACILITY = @{ const = 'LOG_USER' }   # (LOG_LOCAL0, its default, does not exist in PHP on Windows)
                IPC_PROVIDER = 'IpcFileProvider' }
            'backend/imap/config.php' = [ordered]@{ IMAP_SERVER = '127.0.0.1'; IMAP_PORT = 993; IMAP_OPTIONS = '/ssl/novalidate-cert'
                IMAP_FOLDER_CONFIGURED = $true; IMAP_FOLDER_SENT = 'Sent Items'; IMAP_FOLDER_DRAFT = 'Drafts'; IMAP_FOLDER_TRASH = 'Deleted Items'
                IMAP_FOLDER_SPAM = 'Junk Mail'; IMAP_FOLDER_ARCHIVE = 'Archive'; IMAP_SMTP_METHOD = 'smtp'; IMAP_MEETING_USE_CALDAV = $true
                SYSTEM_MIME_TYPES_MAPPING = (& $Fwd (Join-Path $ApacheDir 'conf\mime.types')) }
            'backend/caldav/config.php' = [ordered]@{ CALDAV_SERVER = '127.0.0.1'; CALDAV_PORT = '8443'; CALDAV_PATH = '/dav/cal/%u/'; CALDAV_PERSONAL = 'default'; CALDAV_SUPPORTS_SYNC = $true }
            'backend/carddav/config.php' = [ordered]@{ CARDDAV_SERVER = '127.0.0.1'; CARDDAV_PORT = '8443'; CARDDAV_PATH = '/dav/card/%u/'
                CARDDAV_DEFAULT_PATH = '/dav/card/%u/default/'; CARDDAV_GAL_PATH = $null; CARDDAV_SUPPORTS_SYNC = $true }
            smtp = [ordered]@{ host = 'ssl://127.0.0.1'; port = 465; auth = $true; username = 'imap_username'; password = 'imap_password'
                verify_peer = $false; verify_peer_name = $false; allow_self_signed = $true }
            require = (& $Fwd (Join-Path $zp 'zpush-sentora.php'))
        }
        $zjson = Join-Path $Tmp 'zpush-settings.json'
        Write-Utf8NoBom $zjson ($zset | ConvertTo-Json -Depth 6)
        $zsetup = Join-Path $Tmp 'zpush-setup.php'
        Write-Utf8NoBom $zsetup @'
<?php
// Sets Z-Push's settings: rewrites define('NAME', value) lines (install-zpanel-stack.ps1). php zpush-setup.php <z-push> <settings.json>
$base = rtrim($argv[1], '/\\');
$set = json_decode(file_get_contents($argv[2]), true);
if (!is_array($set)) { fwrite(STDERR, "settings: not JSON\n"); exit(2); }
$fail = 0;
foreach ($set as $file => $values) {
    if (!is_array($values) || !preg_match('#^[a-z0-9/_.-]+\.php$#i', $file) || strpos($file, '..') !== false) continue;
    $p = $base . '/' . $file;
    $s = file_get_contents($p);
    if ($s === false) { fwrite(STDERR, "missing $p\n"); $fail = 1; continue; }
    foreach ($values as $name => $v) {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) { fwrite(STDERR, "bad name $name\n"); $fail = 1; continue; }
        $re = '/^(\s*)define\(\s*[\'"]' . $name . '[\'"]\s*,.*?\);[ \t]*$/m';
        $val = (is_array($v) && isset($v['const']) && preg_match('/^[A-Z][A-Z0-9_]*$/', $v['const'])) ? $v['const'] : var_export($v, true);
        $line = $v === null ? "// define('$name'): off (Sentora)" : "define('$name', " . $val . ');';
        $n = 0;
        $s = preg_replace_callback($re, function ($m) use ($line) { return $m[1] . $line; }, $s, 1, $n);
        if ($n !== 1 && $v !== null) { fwrite(STDERR, "$file: no define('$name')\n"); $fail = 1; }
    }
    file_put_contents($p, $s);
}
if (isset($set['smtp']) && is_array($set['smtp'])) {
    $p = $base . '/backend/imap/config.php';
    $s = file_get_contents($p);
    $line = '$imap_smtp_params = ' . str_replace("\n", ' ', var_export($set['smtp'], true)) . '; // Sentora';
    $n = 0;
    $s = preg_replace_callback('/^\$imap_smtp_params = .*;.*$/m', function ($m) use ($line) { return $line; }, $s, 1, $n);
    if ($n !== 1) { fwrite(STDERR, "imap config: no \$imap_smtp_params line\n"); $fail = 1; }
    file_put_contents($p, $s);
}
if (!empty($set['require'])) {
    $p = $base . '/config.php';
    $s = file_get_contents($p);
    $s = preg_replace('/^require_once .*; \/\/ Sentora\r?\n/m', '', $s);
    $s = preg_replace('/^<\?php\r?\n/', "<?php\nrequire_once " . var_export($set['require'], true) . "; // Sentora\n", $s, 1);
    file_put_contents($p, $s);
}
echo $fail ? "failed\n" : "ok\n";
exit($fail);
'@
        $out = Invoke-Native $phpExe @('-c', $phpIni, $zsetup, $zp, $zjson)
        Remove-Item $zsetup, $zjson -Force -ErrorAction SilentlyContinue
        if ($LASTEXITCODE -ne 0) { Fail "Could not set Z-Push's settings: $($out -join ' ')" }
        Ok "Z-Push $($Pkg.ZPush.Name -replace '^Z-Push (\S+).*', '$1') -> $zp (mail: IMAP, calendars and tasks: CalDAV, contacts: CardDAV)"

        # --- Apache: HTTPS on $HttpsPort for the site, webmail and ActiveSync; calendars and contacts passed on to Stalwart;
        # on plain HTTP these addresses only send the visitor to HTTPS (passwords never cross the network in the clear)
        Write-Utf8NoBom (Join-Path $Cfg 'apache\mail.conf') @"
# Made by install-zpanel-stack.ps1 (mail); run it again to rewrite this file. (The port and TLS: ssl.conf.)

<LocationMatch "(?i)^/(webmail|Microsoft-Server-ActiveSync|dav/|\.well-known/(caldav|carddav))">
    <If "%{HTTPS} != 'on'">
        Redirect permanent "https://%{SERVER_NAME}$(if ($HttpsPort -ne 443) { ":$HttpsPort" })%{REQUEST_URI}"
    </If>
</LocationMatch>

<VirtualHost *:$HttpsPort>
    ServerName $mailHost
    ServerAlias ${ServerName}
    SSLEngine on
    SSLCertificateFile "$(& $Fwd $srvCrt)"
    SSLCertificateKeyFile "$(& $Fwd $srvKey)"
    Header always set Strict-Transport-Security "max-age=15552000"

    # the site, as on port ${HttpPort}
    DocumentRoot "`${ZROOT}/panel"
    ErrorLog "`${ZROOT}/logs/apache/ssl-error.log"
    CustomLog "`${ZROOT}/logs/apache/ssl-access.log" combined

    # webmail
    Alias /webmail "`${ZROOT}/apps/roundcube/public_html"
    <Directory "`${ZROOT}/apps/roundcube/public_html">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # ActiveSync (phones, Outlook): only Z-Push's index.php is reachable, never its settings
    Alias /Microsoft-Server-ActiveSync "`${ZROOT}/apps/z-push/index.php"
    <Directory "`${ZROOT}/apps/z-push">
        Require all denied
        <Files "index.php">
            Require all granted
        </Files>
    </Directory>
    # (the panel's Admin > Mail Config: ActiveSync on or off, written by its daemon)
    IncludeOptional "`${ZROOT}/configs/apache/mail-panel.conf"

    # calendars and contacts (CalDAV, CardDAV) from Stalwart on this server
    SSLProxyEngine on
    SSLProxyVerify none
    SSLProxyCheckPeerName off
    SSLProxyCheckPeerExpire off
    ProxyPreserveHost On
    ProxyPass /dav/ https://127.0.0.1:8443/dav/
    ProxyPassReverse /dav/ https://127.0.0.1:8443/dav/
    ProxyPass /.well-known/caldav https://127.0.0.1:8443/.well-known/caldav
    ProxyPass /.well-known/carddav https://127.0.0.1:8443/.well-known/carddav
</VirtualHost>
"@
        $test = Invoke-Native $httpd @('-t', '-f', $conf)
        if ($LASTEXITCODE -ne 0) { Fail "Apache refused the HTTPS settings: $($test -join ' ')" }
        Restart-Service $ApacheService
        (Get-Service $ApacheService).WaitForStatus('Running', '00:01:00')
        Ok "Apache: HTTPS on port $HttpsPort (webmail: https://$mailHost/webmail, ActiveSync: https://$mailHost/Microsoft-Server-ActiveSync)"

        # --- mailboxes: the panel's way in, through Stalwart's API
        Write-Utf8NoBom (Join-Path $Bin 'mailuser.ps1') @'
<#
  Mailboxes in Stalwart (made by install-zpanel-stack.ps1). As Administrator:
    mailuser.ps1 add USER@DOMAIN PASSWORD     a mailbox (its domain is added when new)
    mailuser.ps1 password USER@DOMAIN PASSWORD
    mailuser.ps1 remove USER@DOMAIN           the mailbox and its mail go
    mailuser.ps1 list
    mailuser.ps1 domains
#>
param([Parameter(Mandatory = $true)][ValidateSet('add', 'password', 'remove', 'list', 'domains')][string]$Do, [string]$Address, [string]$Password)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$u = ''; $p = ''
foreach ($l in Get-Content (Join-Path $root 'configs\stalwart\admin.txt')) { if ($l -match '^user=(.+)$') { $u = $Matches[1] } elseif ($l -match '^password=(.+)$') { $p = $Matches[1] } }
Add-Type -TypeDefinition @"
using System.Net; using System.Net.Security; using System.Security.Cryptography.X509Certificates;
public static class ZLoopTls {
    public static bool Check(object s, X509Certificate c, X509Chain ch, SslPolicyErrors e) {
        if (e == SslPolicyErrors.None) return true;
        HttpWebRequest r = s as HttpWebRequest; return r != null && r.RequestUri.IsLoopback;
    }
    public static RemoteCertificateValidationCallback Callback { get { return Check; } }
}
"@ -ErrorAction SilentlyContinue
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
[Net.ServicePointManager]::ServerCertificateValidationCallback = [ZLoopTls]::Callback
$base = 'https://127.0.0.1:8443'
$auth = @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("${u}:$p")) }
$acct = (Invoke-RestMethod "$base/jmap/session" -Headers $auth).primaryAccounts.'urn:stalwart:jmap'
function Call([object[]]$calls) {
    $i = 0
    $mc = foreach ($c in $calls) { $a = $c.a; $a['accountId'] = $acct; '["' + $c.m + '",' + ($a | ConvertTo-Json -Depth 20 -Compress) + ',"c' + ($i++) + '"]' }
    $body = '{"using":["urn:ietf:params:jmap:core","urn:stalwart:jmap"],"methodCalls":[' + (@($mc) -join ',') + ']}'
    $r = Invoke-RestMethod "$base/jmap/" -Method Post -Headers $auth -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($body))
    foreach ($x in $r.methodResponses) {
        $bad = @('notCreated', 'notUpdated', 'notDestroyed') | ForEach-Object { $x[1].PSObject.Properties[$_] } |
            Where-Object { $_ -and $_.Value -and @($_.Value.PSObject.Properties).Count }
        if ($x[0] -eq 'error' -or $bad) { throw "Stalwart refused $($x[0]): $(($x[1] | ConvertTo-Json -Depth 8 -Compress))" }
    }
    return ,@($r.methodResponses)
}
function Find-Account($addr) {
    @((Call @(@{ m = 'x:Account/get'; a = @{ properties = @('name', 'emailAddress') } }))[0][1].list) | Where-Object { $_.emailAddress -eq $addr } | Select-Object -First 1
}
if (@('add', 'password', 'remove') -contains $Do) {
    $Address = "$Address".ToLower()
    if ($Address -notmatch '^([a-z0-9][a-z0-9._-]{0,63})@(([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62})$') { throw 'An address like user@example.com' }
    $local = $Matches[1]; $domain = $Matches[2]
}
if (@('add', 'password') -contains $Do -and "$Password".Length -lt 8) { throw 'A password of 8 characters or more' }
switch ($Do) {
    'add' {
        if (Find-Account $Address) { throw "$Address is already there (mailuser.ps1 password to change its password)" }
        $d = @((Call @(@{ m = 'x:Domain/get'; a = @{ properties = @('name') } }))[0][1].list) | Where-Object { $_.name -eq $domain } | Select-Object -First 1
        if (-not $d) { $d = @{ id = (Call @(@{ m = 'x:Domain/set'; a = @{ create = @{ d = @{ name = $domain } } } }))[0][1].created.d.id }; "domain $domain added" }
        $null = Call @(@{ m = 'x:Account/set'; a = @{ create = @{ u = @{ '@type' = 'User'; name = $local; domainId = $d.id; credentials = @{ '0' = @{ '@type' = 'Password'; secret = $Password } } } } } })
        "mailbox $Address added"
    }
    'password' {
        $a = Find-Account $Address; if (-not $a) { throw "No mailbox $Address" }
        $null = Call @(@{ m = 'x:Account/set'; a = @{ update = @{ ($a.id) = @{ credentials = @{ '0' = @{ '@type' = 'Password'; secret = $Password } } } } } })
        "password of $Address changed"
    }
    'remove' {
        $a = Find-Account $Address; if (-not $a) { throw "No mailbox $Address" }
        $null = Call @(@{ m = 'x:Account/set'; a = @{ destroy = @($a.id) } })
        "mailbox $Address removed"
    }
    'list' { @((Call @(@{ m = 'x:Account/get'; a = @{ properties = @('emailAddress', 'roles', 'usedDiskQuota') } }))[0][1].list) | Select-Object emailAddress, @{ n = 'role'; e = { $_.roles.'@type' } }, usedDiskQuota | Format-Table -AutoSize }
    'domains' { @((Call @(@{ m = 'x:Domain/get'; a = @{ properties = @('name') } }))[0][1].list) | Select-Object name | Format-Table -AutoSize }
}
'@
        Ok 'C:\zpanel\bin\mailuser.ps1: add, remove and list mailboxes, change a password'

        if (-not $NoFirewall) {
            Get-NetFirewallRule -DisplayName $MailFirewall -ErrorAction SilentlyContinue | Remove-NetFirewallRule
            New-NetFirewallRule -DisplayName $MailFirewall -Direction Inbound -Action Allow -Protocol TCP -LocalPort ($MailPorts | ForEach-Object { "$_" }) -Program $stalwart | Out-Null
            Ok "Windows Firewall: $($MailPorts -join ', ') open for Stalwart (its admin port and 8091 stay closed)"
        }
    }

    # --------------------------------------------------------------------------------------------------------------
    if ($WithDns) {
        Say 'DNS (CoreDNS): your own domains, from zone files'
        $dnsDir = Join-Path $Bin 'coredns'; $dnsCfg = Join-Path $Cfg 'dns'; $zones = Join-Path $dnsCfg 'zones'; $dnsLogs = Join-Path $Logs 'coredns'
        foreach ($d in @($dnsCfg, $zones, $dnsLogs)) { if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null } }
        $nssm = Join-Path $Bin 'nssm\nssm.exe'
        if (-not (Test-Path $nssm)) { Expand-Into (Get-Verified $Pkg.Nssm) $Pkg.Nssm.Top (Join-Path $Bin 'nssm') }
        $dz = Get-Verified $Pkg.CoreDns
        Stop-AndRemoveService $DnsService
        Expand-Into $dz $Pkg.CoreDns.Top $dnsDir
        $coredns = Join-Path $dnsDir 'coredns.exe'
        Ok "$((Invoke-Native $coredns @('-version') | Select-Object -First 1) -join '') -> $dnsDir"
        # every file db.DOMAIN in the zones folder is a zone (looked at every 15 seconds: a raised serial is picked up);
        # no forwarding: names that are not ours are refused, so it never serves as an open resolver
        Write-Utf8NoBom (Join-Path $dnsCfg 'Corefile') @"
# Made by install-zpanel-stack.ps1; run it again to rewrite this file. Zones: $zones\db.DOMAIN (dnszone.ps1).
. {
    auto {
        directory $(& $Fwd $zones) db\.(.*) {1}
        reload 15s
    }
    any
    errors
}
"@
        # the server's own address for new zones: the one on the network with the default gateway
        $myIp = (Get-MainIPv4)
        if (-not $myIp) { $myIp = '127.0.0.1' }
        Write-Utf8NoBom (Join-Path $Bin 'dnszone.ps1') @'
<#
  DNS zones for CoreDNS (made by install-zpanel-stack.ps1). Zone files: C:\zpanel\configs\dns\zones\db.DOMAIN (BIND's format;
  edit one by hand if you like, then raise its serial: dnszone.ps1 serial DOMAIN). As Administrator:
    dnszone.ps1 add DOMAIN [IP]                    a zone with the usual records (@, www, mail, ftp, MX, SPF), at IP
    dnszone.ps1 remove DOMAIN
    dnszone.ps1 list
    dnszone.ps1 show DOMAIN
    dnszone.ps1 record DOMAIN add NAME TYPE VALUE   TYPE: A, AAAA, CNAME, MX (VALUE "10 host"), TXT, NS, SRV, CAA
    dnszone.ps1 record DOMAIN remove NAME TYPE [VALUE]
    dnszone.ps1 serial DOMAIN                      after a change by hand
  CoreDNS picks up a change within 15 seconds.
#>
param([Parameter(Mandatory = $true)][ValidateSet('add', 'remove', 'list', 'show', 'record', 'serial')][string]$Do,
      [string]$Domain, [string]$A1, [string]$A2, [string]$A3, [string]$A4)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$zones = Join-Path $root 'configs\dns\zones'
$Domain = "$Domain".ToLower().TrimEnd('.')
function Need-Domain { if ($Domain -notmatch '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$') { throw 'A domain name like example.com' } }
function Zone-File { Join-Path $zones "db.$Domain" }
function New-Serial($old) {
    $today = [long]((Get-Date).ToString('yyyyMMdd') + '01')
    if ($old -ge $today) { return $old + 1 } else { return $today }
}
function Save-Zone($lines) {
    # the serial goes up with every change (CoreDNS reloads a zone when its serial is higher)
    $text = ($lines -join "`r`n")
    $m = [regex]::Match($text, '(?m)^(@\s+\d*\s*IN\s+SOA\s+\S+\s+\S+\s+\(?\s*)(\d+)')
    if (-not $m.Success) { throw "No SOA line in $(Zone-File)" }
    $text = $text.Substring(0, $m.Groups[2].Index) + (New-Serial ([long]$m.Groups[2].Value)) + $text.Substring($m.Groups[2].Index + $m.Groups[2].Length)
    $tmp = (Zone-File) + '.new'
    [IO.File]::WriteAllText($tmp, $text.TrimEnd() + "`r`n", (New-Object Text.UTF8Encoding($false)))
    Move-Item $tmp (Zone-File) -Force
}
$types = @{
    A     = '^(25[0-5]|2[0-4]\d|1?\d?\d)(\.(25[0-5]|2[0-4]\d|1?\d?\d)){3}$'
    AAAA  = '^[0-9a-fA-F:]{2,39}$'
    CNAME = '^([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.)*[a-z0-9_-]+\.?$|^@$'
    NS    = '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*\.?$'
    MX    = '^\d{1,5} ([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9-]+\.?$|^\d{1,5} @$'
    TXT   = '^[^"\r\n\\]{1,2000}$'
    SRV   = '^\d{1,5} \d{1,5} \d{1,5} ([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9-]+\.?$'
    CAA   = '^\d{1,3} (issue|issuewild|iodef) "[^"\r\n]{1,255}"$'
}
switch ($Do) {
    'list' { Get-ChildItem $zones -Filter 'db.*' | Where-Object { $_.Name -notlike '*.new' } | ForEach-Object { $_.Name.Substring(3) }; return }
    'show' { Need-Domain; Get-Content (Zone-File); return }
    'add' {
        Need-Domain
        if (Test-Path (Zone-File)) { throw "The zone $Domain is already there" }
        $ip = if ($A1) { $A1 } else { 'SERVERIP' }
        if ($ip -notmatch $types.A) { throw 'IP: an IPv4 address' }
        $serial = (Get-Date).ToString('yyyyMMdd') + '01'
        $lines = @("; $Domain (dnszone.ps1). The serial goes up with each change.", "`$ORIGIN $Domain.", '$TTL 3600',
            "@ 3600 IN SOA ns1.$Domain. hostmaster.$Domain. ( $serial 3600 900 1209600 300 )",
            "@ IN NS ns1.$Domain.", "@ IN NS ns2.$Domain.", "ns1 IN A $ip", "ns2 IN A $ip",
            "@ IN A $ip", "www IN CNAME @", "ftp IN CNAME @", "mail IN A $ip", "@ IN MX 10 mail.$Domain.", '@ IN TXT "v=spf1 mx a ~all"')
        [IO.File]::WriteAllText((Zone-File), ($lines -join "`r`n") + "`r`n", (New-Object Text.UTF8Encoding($false)))
        "zone $Domain added (at $ip)"
    }
    'remove' { Need-Domain; if (-not (Test-Path (Zone-File))) { throw "No zone $Domain" }; Remove-Item (Zone-File); "zone $Domain removed" }
    'serial' { Need-Domain; Save-Zone (Get-Content (Zone-File)); "serial of $Domain raised" }
    'record' {
        Need-Domain
        if (-not (Test-Path (Zone-File))) { throw "No zone $Domain" }
        $name = "$A2".ToLower(); $type = "$A3".ToUpper(); $value = "$A4"
        if ($name -notmatch '^(@|\*|(\*\.)?[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?)*)$') { throw 'NAME: @, www, mail, _dmarc, *.' }
        if (-not $types.ContainsKey($type)) { throw "TYPE: $($types.Keys -join ', ')" }
        $lines = @(Get-Content (Zone-File))
        if ($A1 -eq 'add') {
            if ($value -notmatch $types[$type]) { throw "VALUE is not a $type value" }
            if ($type -eq 'TXT') { $value = '"' + $value + '"' }
            $lines += "$name IN $type $value"
            Save-Zone $lines; "added: $name $type $value"
        } elseif ($A1 -eq 'remove') {
            $keep = @(); $gone = 0
            foreach ($l in $lines) {
                $f = $l -split '\s+', 4
                if ($f.Count -ge 3 -and $f[0].ToLower() -eq $name -and (($f[1] -eq 'IN' -and $f[2] -eq $type) -or ($f.Count -ge 4 -and $f[2] -eq 'IN' -and $f[3] -like "$type *")) -and
                    (-not $value -or $l -like "*$value*")) { $gone++ } else { $keep += $l }
            }
            if (-not $gone) { throw "No $type record $name" }
            Save-Zone $keep; "removed: $gone record(s) $name $type"
        } else { throw 'dnszone.ps1 record DOMAIN add|remove NAME TYPE [VALUE]' }
    }
}
'@.Replace('SERVERIP', $myIp)
        Ok "C:\zpanel\bin\dnszone.ps1: add, remove and list zones, add and remove records (new zones point at $myIp)"
        if ($WithMail -and -not (Test-Path (Join-Path $zones "db.$MailDomain"))) {
            $out = Invoke-Native 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $Bin 'dnszone.ps1'), 'add', $MailDomain)
            if ($LASTEXITCODE -ne 0) { Fail "Could not make the zone $MailDomain : $($out -join ' ')" }
            Ok "zone $MailDomain (for mail: MX, SPF) at $myIp"
        }
        Invoke-Native $nssm @('install', $DnsService, $coredns) | Out-Null
        if (-not (Get-Service $DnsService -ErrorAction SilentlyContinue)) { Fail 'CoreDNS could not be installed as a service.' }
        foreach ($kv in @(@('AppParameters', ('-conf ' + (Join-Path $dnsCfg 'Corefile'))), @('AppDirectory', $dnsDir), @('Description', 'DNS for Sentora: your own zones only (C:\zpanel\configs\dns\zones)'),
                          @('Start', 'SERVICE_AUTO_START'), @('AppStdout', (Join-Path $dnsLogs 'coredns.log')), @('AppStderr', (Join-Path $dnsLogs 'coredns.log')),
                          @('AppRotateFiles', '1'), @('AppRotateOnline', '1'), @('AppRotateBytes', '10485760'))) {
            $null = Invoke-Native $nssm @('set', $DnsService, $kv[0], $kv[1])
        }
        Start-Service $DnsService
        (Get-Service $DnsService).WaitForStatus('Running', '00:01:00')
        $ans = $null
        for ($i = 0; $i -lt 30 -and -not $ans; $i++) {
            $z = @(Get-ChildItem $zones -Filter 'db.*' | Where-Object { $_.Name -notlike '*.new' } | Select-Object -First 1)
            $q = if ($z) { $z[0].Name.Substring(3) } else { 'localhost' }
            try { $ans = Resolve-DnsName $q -Server 127.0.0.1 -Type SOA -DnsOnly -ErrorAction Stop } catch { if (-not $z) { $ans = $true } else { Start-Sleep 1 } }
        }
        if (-not $ans) { Fail "CoreDNS does not answer on port 53: see $dnsLogs" }
        Ok "service $DnsService runs (starts with Windows): answers for $(@(Get-ChildItem $zones -Filter 'db.*').Count) zone(s), refuses every other name"
        if (-not $NoFirewall) {
            Get-NetFirewallRule -DisplayName $DnsFirewall -ErrorAction SilentlyContinue | Remove-NetFirewallRule
            New-NetFirewallRule -DisplayName $DnsFirewall -Direction Inbound -Action Allow -Protocol UDP -LocalPort 53 -Program $coredns | Out-Null
            New-NetFirewallRule -DisplayName $DnsFirewall -Direction Inbound -Action Allow -Protocol TCP -LocalPort 53 -Program $coredns | Out-Null
            Ok 'Windows Firewall: port 53 (UDP and TCP) open for CoreDNS'
        }
    }

    # --------------------------------------------------------------------------------------------------------------
    if ($WithPanel) {
        Say 'The Sentora panel (C:\zpanel\panel)'
        # The same Sentora-2027 panel as on Linux (RusTus/sentora-core-2027), natively on Windows as ZPanel ran. On
        # Windows only the modules ready for it run (their module.xml says <windows>yes</windows>); the others follow
        # as each gets its Windows way of doing its job. Its paths, programs and service names are settings.
        $panelDir = Join-Path $Root 'panel'
        $phpExe = Join-Path $PhpDir 'php.exe'
        $mysql = Join-Path $MySQLDir 'bin\mysql.exe'
        $panelCfg = Join-Path $Cfg 'panel'
        $panelLogs = Join-Path $Logs 'panel'
        $stateDir = Join-Path $Root 'data\sentora'
        foreach ($d in @($panelCfg, $panelLogs, $stateDir, (Join-Path $stateDir 'tmp'), (Join-Path $stateDir 'zsudo'), (Join-Path $stateDir 'zsudo\new'), (Join-Path $stateDir 'zsudo\queue'))) {
            if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null }
        }
        # (SpamAV Admin's file scan moves malware found in the websites here: Administrators and SYSTEM only)
        $quarantine = Join-Path $Root 'data\quarantine'
        if (-not (Test-Path $quarantine)) { New-Item -ItemType Directory -Path $quarantine | Out-Null }
        Protect-Folder $quarantine
        $src = Join-Path $Tmp 'panel-src'
        Remove-Item $src -Recurse -Force -ErrorAction SilentlyContinue
        New-Item -ItemType Directory -Path $src | Out-Null
        # (a folder or zip given with -PanelSource / -PanelSqlSource, else the current release from GitHub over HTTPS)
        function Get-PanelSource($given, $repo, $name) {
            if ($given -and (Test-Path $given -PathType Container)) { return (Resolve-Path $given).Path }
            $zip = $given
            if (-not $zip) {
                # the release "current" of the repository; the file must match the SHA-256 GitHub keeps for it
                $rel = Invoke-RestMethod "https://api.github.com/repos/$repo/releases/tags/current" -Headers @{ 'User-Agent' = 'sentora-windows-install'; Accept = 'application/vnd.github+json' } -UseBasicParsing -TimeoutSec 60
                $a = @($rel.assets) | Where-Object { $_.name -eq $name } | Select-Object -First 1
                if (-not $a) { Fail "$repo's current release has no $name." }
                if ("$($a.digest)" -notmatch '^sha256:([0-9a-fA-F]{64})$') { Fail "$repo's current release gives no SHA-256 for ${name}: it is not used." }
                $sha = $Matches[1].ToUpper()
                $zip = Join-Path $Dl $name
                Invoke-WebRequest -Uri $a.browser_download_url -OutFile $zip -UseBasicParsing -TimeoutSec 900
                if ((Get-FileHash $zip -Algorithm SHA256).Hash -ne $sha) { Remove-Item $zip -Force; Fail "$name from $repo does not match its SHA-256: it is not used." }
                Ok "$name ($($rel.name)): downloaded, checksum matches"
            }
            $out = Join-Path $src ([IO.Path]::GetFileNameWithoutExtension($name))
            Expand-Archive -Path $zip -DestinationPath $out -Force
            return $out
        }
        $core = Get-PanelSource $PanelSource 'RusTus/sentora-core-2027' 'sentora-core-2027.zip'
        $top = Get-ChildItem $core -Recurse -Filter 'index.php' -ErrorAction SilentlyContinue | Where-Object { Test-Path (Join-Path $_.DirectoryName 'dryden\loader.inc.php') } | Select-Object -First 1
        if (-not $top) { Fail 'The panel files have no index.php beside dryden\loader.inc.php.' }
        $top = $top.DirectoryName
        # what was in panel before that is not the panel (the stack's placeholder, your own app): kept, never deleted
        if ((Get-ChildItem $panelDir -Force) -and -not (Test-Path (Join-Path $panelDir 'dryden'))) {
            $before = Join-Path $Root ('backups\panel-before-sentora-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
            New-Item -ItemType Directory -Force -Path $before | Out-Null
            Get-ChildItem $panelDir -Force | Move-Item -Destination $before
            Ok "what was in panel before is kept in $before"
        }
        # (a repair keeps the panel's own database settings and key)
        $keep = @{}
        foreach ($f in @('cnf\db.php', 'cnf\security.php')) { $p = Join-Path $panelDir $f; if (Test-Path $p) { $keep[$f] = [IO.File]::ReadAllBytes($p) } }
        Invoke-Native 'robocopy.exe' @($top, $panelDir, '/MIR', '/XD', '.git', '/NFL', '/NDL', '/NJH', '/NJS', '/NP') | Out-Null
        if ($LASTEXITCODE -ge 8) { Fail "The panel files could not be copied (robocopy $LASTEXITCODE)." }
        foreach ($f in $keep.Keys) { [IO.File]::WriteAllBytes((Join-Path $panelDir $f), $keep[$f]) }
        $panelVersion = (Get-Content (Join-Path $panelDir 'VERSION') -Raw -ErrorAction SilentlyContinue)
        Ok "panel files: Sentora-$("$panelVersion".Trim())"

        # its state folder: \var\lib\sentora on this drive, a junction to C:\zpanel\data\sentora (the paths the panel names work as they are)
        $drive = [IO.Path]::GetPathRoot($Root)
        $link = Join-Path $drive 'var\lib\sentora'
        if (-not (Test-Path (Join-Path $drive 'var\lib'))) { New-Item -ItemType Directory -Force -Path (Join-Path $drive 'var\lib') | Out-Null }
        $li = Get-Item $link -Force -ErrorAction SilentlyContinue
        if ($li -and ($li.LinkType -ne 'Junction' -or "$($li.Target)" -ne $stateDir)) { Fail "$link is there but is not the panel's junction to $stateDir. Move it away, then run this again." }
        if (-not $li) { Invoke-Native 'cmd.exe' @('/c', 'mklink', '/J', $link, $stateDir) | Out-Null }
        if (-not (Test-Path (Join-Path $link 'zsudo'))) { Fail "The junction $link -> $stateDir does not work." }
        Protect-Folder (Join-Path $drive 'var')
        Ok "state folder $stateDir (as $link)"

        # its database (the same SQL as on Linux), once; a repair keeps it
        $env:MYSQL_PWD = $RootPw
        try {
            $have = (Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-N', '-e', "SHOW DATABASES LIKE 'sentora_core'")) -join ''
            if ($have -ne 'sentora_core') {
                $inst = Get-PanelSource $PanelSqlSource 'RusTus/sentora-installers-2027' 'sentora-installers-2027.zip'
                $sql = Get-ChildItem $inst -Recurse -Filter 'sentora_core.sql' | Where-Object { $_.FullName -match 'sentora-install\\sql' } | Select-Object -First 1
                if (-not $sql) { Fail 'The installers have no preconf\sentora-install\sql\sentora_core.sql.' }
                $out = Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-e', ('source ' + (& $Fwd $sql.FullName)))
                if ($LASTEXITCODE -ne 0) { Fail "The panel's database could not be made: $($out -join ' ')" }
                Ok 'database sentora_core made'
            } else { Ok 'database sentora_core kept' }
            # the mail database the panel's Mailboxes page writes (as on Linux); the daemon brings Stalwart in line with it
            $haveMailDb = (Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-N', '-e', "SHOW DATABASES LIKE 'sentora_postfix'")) -join ''
            if ($haveMailDb -ne 'sentora_postfix') {
                $inst = Get-PanelSource $PanelSqlSource 'RusTus/sentora-installers-2027' 'sentora-installers-2027.zip'
                $msql = Get-ChildItem $inst -Recurse -Filter 'sentora_postfix.sql' | Where-Object { $_.FullName -match 'sentora-install\\sql' } | Select-Object -First 1
                if (-not $msql) { Fail 'The installers have no preconf\sentora-install\sql\sentora_postfix.sql.' }
                $out = Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-e', ('source ' + (& $Fwd $msql.FullName)))
                if ($LASTEXITCODE -ne 0) { Fail "The mail database could not be made: $($out -join ' ')" }
                Ok 'database sentora_postfix made (the panel''s mailboxes; Stalwart follows it)'
            } else { Ok 'database sentora_postfix kept' }
            # where things are on Windows (the settings the panel reads instead of Linux's)
            $fwdRoot = & $Fwd $Root
            $settings = [ordered]@{
                sentora_root = "$fwdRoot/panel/"; hosted_dir = "$fwdRoot/hostdata/"; temp_dir = "$fwdRoot/temp/"; upload_temp_dir = "$fwdRoot/temp/uploads/"
                openbase_temp = "$fwdRoot/temp/"; log_dir = "$fwdRoot/logs/"; logfile = "$fwdRoot/logs/panel/sentora.log"; static_dir = "$fwdRoot/panel/etc/static/"
                parking_path = "$fwdRoot/panel/etc/static/parking/"; apache_vhost = "$fwdRoot/configs/apache/sentora-vhosts.conf"; apache_budir = "$fwdRoot/backups/"
                php_exer = (& $Fwd $phpExe); daemon_exer = "$fwdRoot/panel/bin/daemon.php"; zsudo = "$fwdRoot/panel/bin/zsudo.bat"
                httpd_exe = (& $Fwd (Join-Path $ApacheDir 'bin\httpd.exe')); apache_sn = $ApacheService; apache_port = "$HttpPort"; sentora_port = "$HttpPort"
                mysqldump_exe = (& $Fwd (Join-Path $MySQLDir 'bin\mysqldump.exe')); sentora_domain = $ServerName; root_drive = $drive.Replace('\', '/')
                bind_service = $DnsService; zone_dir = "$fwdRoot/configs/dns/zones/"; ftp_service = $FtpService
            }
            # the server's address, for the DNS records the panel makes (the one on the network with the default gateway)
            $ipNow = (Get-MainIPv4)
            if ($ipNow) { $settings['server_ip'] = $ipNow }
            # FTP: SFTPGo's driver; the daemon (SYSTEM) reads SFTPGo's administrator from its settings file
            $settings['ftp_php'] = 'sftpgo.php'; $settings['ftp_api'] = 'http://127.0.0.1:8080/api/v2'
            $settings['ftp_env'] = "$fwdRoot/configs/sftpgo/env.d/sftpgo.env"; $settings['ftp_passive_ports'] = '50000-50100'
            # the services' names (Server Monitor, its alerts)
            $settings['db_service'] = $MySQLService; $settings['mail_service'] = $MailService; $settings['clamd_service'] = $ClamService; $settings['freshclam_service'] = $FreshService
            # mail: Stalwart's API on this server, and its administrator (read by the daemon only)
            $settings['mail_api'] = 'https://127.0.0.1:8443'; $settings['mail_admin'] = "$fwdRoot/configs/stalwart/admin.txt"
            # SpamAV Admin: ClamAV's files and programs, and the virus check's options file (its daemon writes them)
            $clamFwd = "$fwdRoot/bin/clamav"
            $settings['clamd_conf'] = "$clamFwd/clamd.conf"; $settings['freshclam_conf'] = "$clamFwd/freshclam.conf"; $settings['clamd_exe'] = "$clamFwd/clamd.exe"
            $settings['freshclam_exe'] = "$clamFwd/freshclam.exe"; $settings['clamav_db_dir'] = "$fwdRoot/data/clamav"; $settings['mailscan_options'] = "$fwdRoot/apps/mailscan/options.json"
            $settings['filescan_quarantine'] = "$fwdRoot/data/quarantine"
            $settings['python_exe'] = "$fwdRoot/bin/python/python.exe"
            # the other PHP versions the Domains page offers (the installer's; an administrator may narrow the list)
            $settings['php_versions_enabled'] = ($phpExtra -join ',')
            # Mail Config: the files its daemon writes (the webmail's settings, ActiveSync's switch in the mail site)
            $settings['webmail_panel_conf'] = "$fwdRoot/configs/stalwart/roundcube-panel.inc.php"; $settings['mail_apache_conf'] = "$fwdRoot/configs/apache/mail-panel.conf"
            $q = ''
            foreach ($k in $settings.Keys) {
                $v = "$($settings[$k])".Replace('\', '\\').Replace("'", "''")
                # (a setting Linux does not have is added, hidden: root's commands and paths are changed over SSH only)
                $q += "INSERT INTO sentora_core.x_settings (so_name_vc, so_value_tx, so_usereditable_en) SELECT '$k', '$v', 'false' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sentora_core.x_settings WHERE so_name_vc = '$k');"
                $q += "UPDATE sentora_core.x_settings SET so_value_tx = '$v' WHERE so_name_vc = '$k';"
            }
            $out = Invoke-Native $mysql @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-e', $q)
            if ($LASTEXITCODE -ne 0) { Fail "The panel's settings could not be written: $($out -join ' ')" }
            Ok "settings for Windows ($($settings.Count): folders, programs, service names)"
        } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }

        # its database login (the panel makes the clients' databases and users, as on Linux: MySQL's root), readable by
        # Administrators, SYSTEM and Apache's own account only (set below)
        $dbPhp = Join-Path $panelDir 'cnf\db.php'
        if (-not $keep.ContainsKey('cnf\db.php')) {
            Write-Utf8NoBom $dbPhp ("<?php`r`n// The panel's database login (install-zpanel-stack.ps1).`r`n`$host = '127.0.0.1';`r`n`$dbname = 'sentora_core';`r`n`$user = 'root';`r`n`$pass = '" + $RootPw.Replace('\', '\\').Replace("'", "\'") + "';`r`n")
        }
        # zadmin: a new password the first time (kept in C:\zpanel\configs\panel, Administrators only)
        $zpw = Join-Path $panelCfg 'zadmin-password.txt'
        if (-not (Test-Path $zpw)) {
            $newPw = New-Password
            Push-Location $panelDir
            try { $out = Invoke-Native $phpExe @('-c', (Join-Path $PhpDir 'php.ini'), 'bin\setzadmin', '--set', $newPw) } finally { Pop-Location }
            if ($LASTEXITCODE -ne 0 -or ($out -join ' ') -notmatch 'updated') { Fail "zadmin's password could not be set: $($out -join ' ')" }
            Write-Utf8NoBom $zpw $newPw
            Ok 'zadmin has a new password (in C:\zpanel\configs\panel\zadmin-password.txt)'
        } else { Ok 'zadmin keeps its password' }
        Protect-Folder $panelCfg

        # phpMyAdmin (shipped in the panel's etc\apps\phpmyadmin): its settings as on Linux (preconf/phpmyadmin), the
        # database on 127.0.0.1, its compiled templates and login log where the websites can not write, no setup folder;
        # its cookie secret made once (kept in configs\panel)
        $pma = Join-Path $panelDir 'etc\apps\phpmyadmin'
        if (Test-Path $pma) {
            $pmaSecretFile = Join-Path $panelCfg 'phpmyadmin-secret.txt'
            if (-not (Test-Path $pmaSecretFile)) { Write-Utf8NoBom $pmaSecretFile (New-Password + New-Password) }
            $pmaSecret = (Get-Content $pmaSecretFile -Raw).Trim()
            $pmaTmp = Join-Path $Root 'data\sentora\tmp\phpmyadmin'
            if (-not (Test-Path $pmaTmp)) { New-Item -ItemType Directory -Path $pmaTmp | Out-Null }
            Write-Utf8NoBom (Join-Path $pma 'config.inc.php') ("<?php`r`n// Made by install-zpanel-stack.ps1 (as preconf/phpmyadmin on Linux); run it again to rewrite this file.`r`n" +
                "`$cfg['blowfish_secret'] = '$pmaSecret';`r`n`$i = 1;`r`n`$cfg['Servers'][`$i]['auth_type'] = 'cookie';`r`n" +
                "`$cfg['Servers'][`$i]['host'] = '127.0.0.1';`r`n`$cfg['Servers'][`$i]['compress'] = false;`r`n`$cfg['Servers'][`$i]['AllowNoPassword'] = false;`r`n" +
                "`$cfg['Servers'][`$i]['hide_db'] = '^(information_schema|sys|performance_schema)`$';`r`n" +
                "`$cfg['AuthLog'] = '" + (& $Fwd (Join-Path $Logs 'panel\phpmyadmin-auth.log')) + "';`r`n" +
                "`$cfg['TempDir'] = '" + (& $Fwd $pmaTmp) + "/';`r`n`$cfg['UploadDir'] = '';`r`n`$cfg['SaveDir'] = '';`r`n" +
                "`$cfg['ShowCreateDb'] = false;`r`n`$cfg['ShowChgPassword'] = false;`r`n`$cfg['AllowUserDropDatabase'] = false;`r`n" +
                "`$cfg['PmaNoRelation_DisableWarning'] = true;`r`n`$cfg['ShowServerInfo'] = false;`r`n`$cfg['VersionCheck'] = false;`r`n`$cfg['SendErrorReports'] = 'never';`r`n")
            Remove-Item (Join-Path $pma 'setup') -Recurse -Force -ErrorAction SilentlyContinue
            Ok 'phpMyAdmin: its settings (cookie login, 127.0.0.1, temporary files outside the websites), no setup folder'
        }

        # the root jobs the panel may ask for (bin\zsudo.php: a fixed list), done by the service SentoraZsudo as SYSTEM
        $nssm = Join-Path $Bin 'nssm\nssm.exe'
        if (-not (Test-Path $nssm)) { Expand-Into (Get-Verified $Pkg.Nssm) $Pkg.Nssm.Top (Join-Path $Bin 'nssm') }
        $zsvc = 'SentoraZsudo'
        Stop-AndRemoveService $zsvc
        Invoke-Native $nssm @('install', $zsvc, $phpExe) | Out-Null
        Invoke-Native $nssm @('set', $zsvc, 'AppParameters', ('-c "' + (Join-Path $PhpDir 'php.ini') + '" -q "' + (Join-Path $panelDir 'bin\zsudo.php') + '" --run-queue')) | Out-Null
        Invoke-Native $nssm @('set', $zsvc, 'AppDirectory', $panelDir) | Out-Null
        Invoke-Native $nssm @('set', $zsvc, 'DisplayName', 'Sentora root jobs (zsudo)') | Out-Null
        Invoke-Native $nssm @('set', $zsvc, 'Description', 'Runs the jobs the Sentora panel asks for, from a fixed list only (bin\zsudo.php)') | Out-Null
        Invoke-Native $nssm @('set', $zsvc, 'AppStdout', (Join-Path $panelLogs 'zsudo-service.log')) | Out-Null
        Invoke-Native $nssm @('set', $zsvc, 'AppStderr', (Join-Path $panelLogs 'zsudo-service.log')) | Out-Null
        Invoke-Native $nssm @('set', $zsvc, 'Start', 'SERVICE_AUTO_START') | Out-Null
        Start-Service $zsvc
        Ok "service $zsvc (the panel's root jobs, from a fixed list)"
        # Server Monitor's figures (CPU, memory, disks, network, processes, services) every 3 seconds, as SYSTEM, in the
        # state folder's monitor\ (Apache's account reads them; the panel's PHP gets no access to Windows' counters)
        $ssvc = 'SentoraStats'
        Stop-AndRemoveService $ssvc
        $psExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
        Invoke-Native $nssm @('install', $ssvc, $psExe) | Out-Null
        Invoke-Native $nssm @('set', $ssvc, 'AppParameters', ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + (Join-Path $panelDir 'bin\sentora-winstats.ps1') + '" -Out "' + (Join-Path $stateDir 'monitor\winstats.json') + '"')) | Out-Null
        Invoke-Native $nssm @('set', $ssvc, 'DisplayName', 'Sentora server figures (Server Monitor)') | Out-Null
        Invoke-Native $nssm @('set', $ssvc, 'Description', 'Writes the figures Server Monitor shows every 3 seconds (bin\sentora-winstats.ps1)') | Out-Null
        Invoke-Native $nssm @('set', $ssvc, 'AppStderr', (Join-Path $panelLogs 'winstats-service.log')) | Out-Null
        Invoke-Native $nssm @('set', $ssvc, 'Start', 'SERVICE_AUTO_START') | Out-Null
        Start-Service $ssvc
        Ok "service $ssvc (Server Monitor's figures)"

        # the daemon every 5 minutes, as the Linux cron job (SYSTEM)
        $daemonAction = New-ScheduledTaskAction -Execute $phpExe -Argument ('-c "' + (Join-Path $PhpDir 'php.ini') + '" -q "' + (Join-Path $panelDir 'bin\daemon.php') + '"') -WorkingDirectory $panelDir
        $every5 = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 5)
        $null = Register-ScheduledTask -TaskName 'Sentora daemon' -Action $daemonAction -Trigger $every5 -User 'SYSTEM' -RunLevel Highest `
            -Settings (New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1)) -Description 'The Sentora panel''s daemon (bin\daemon.php), every 5 minutes' -Force
        Push-Location $panelDir
        try { $out = Invoke-Native $phpExe @('-c', (Join-Path $PhpDir 'php.ini'), '-q', 'bin\daemon.php') } finally { Pop-Location }
        if (($out -join "`n") -notmatch 'Daemon run complete') { Note "the first daemon run did not finish: $(($out | Select-Object -Last 5) -join ' ')" } else { Ok "task 'Sentora daemon' every 5 minutes (first run done)" }
        # the clients' cron jobs (Cron Manager), every minute as SYSTEM: each due job runs as its account, through the
        # account's own PHP (bin\sentora-cron.php), never as SYSTEM
        $cronAction = New-ScheduledTaskAction -Execute $phpExe -Argument ('-c "' + (Join-Path $PhpDir 'php.ini') + '" -q "' + (Join-Path $panelDir 'bin\sentora-cron.php') + '"') -WorkingDirectory $panelDir
        $every1 = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 1)
        $null = Register-ScheduledTask -TaskName 'Sentora cron' -Action $cronAction -Trigger $every1 -User 'SYSTEM' -RunLevel Highest `
            -Settings (New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5)) -Description 'The Sentora clients'' cron jobs (bin\sentora-cron.php), every minute; each runs as its account' -Force
        Ok "task 'Sentora cron' every minute (the clients' cron jobs, each as its own account)"
    }

    # --------------------------------------------------------------------------------------------------------------
    Say "Apache's own account (not LocalSystem)"
    # ZPanel ran Apache as LocalSystem: a hole in any site, the webmail or the panel then had the whole server. Apache
    # runs as its own service account (NT SERVICE\Apache2): it reads its programs, the sites and its settings, and
    # writes only its logs, temp files and the folders below; what needs more goes to SentoraZsudo (a fixed list).
    $acct = "NT SERVICE\$ApacheService"
    # C:\zpanel itself: Administrators and SYSTEM only (it had C:\'s rights, which let any signed-in account change it)
    $out = Invoke-Native 'icacls.exe' @($Root, '/inheritance:r', '/grant:r', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F')
    if ($LASTEXITCODE -ne 0) { Fail "Could not lock $Root to Administrators: $($out -join ' ')" }
    function Grant-Apache($path, $rights) {
        if (-not (Test-Path $path)) { return }
        $inherit = if (Test-Path $path -PathType Container) { '(OI)(CI)' } else { '' }
        $o = Invoke-Native 'icacls.exe' @($path, '/grant', "${acct}:$inherit$rights")
        if ($LASTEXITCODE -ne 0) { Fail "Could not let Apache's account use ${path}: $($o -join ' ')" }
    }
    $apps = Join-Path $Root 'apps'
    # the folders above the ones it uses: it may list them (Apache resolves every folder on its paths), only the
    # folder itself, nothing inside comes with it
    $varDir = Join-Path ([IO.Path]::GetPathRoot($Root)) 'var'
    foreach ($p in @($Root, $Bin, $Cfg, $Logs, (Join-Path $Root 'data'), $varDir, (Join-Path $varDir 'lib'))) {
        if (-not (Test-Path $p)) { continue }
        $o = Invoke-Native 'icacls.exe' @($p, '/grant', "${acct}:(RX)")
        if ($LASTEXITCODE -ne 0) { Fail "Could not let Apache's account see ${p}: $($o -join ' ')" }
    }
    # the junction \var\lib\sentora itself (its own entry, /L: the link, not the folder it points to), so the panel's
    # /var/lib/sentora paths lead Apache to the state folder; what is inside keeps the rights set below
    $jn = Join-Path $varDir 'lib\sentora'
    if (Test-Path $jn) {
        $o = Invoke-Native 'icacls.exe' @($jn, '/grant', "${acct}:(RX)", '/L')
        if ($LASTEXITCODE -ne 0) { Fail "Could not let Apache's account use the link ${jn}: $($o -join ' ')" }
    }
    # read (and run its own programs)
    foreach ($p in @($ApacheDir, $PhpDir, (Join-Path $Root 'panel'), (Join-Path $Root 'hostdata'), $apps, (Join-Path $Cfg 'apache'), (Join-Path $Cfg 'ssl'),
                     (Join-Path $Cfg 'stalwart\mailscan-key.txt'), (Join-Path $Cfg 'stalwart\roundcube.inc.php'), (Join-Path $Cfg 'stalwart\roundcube-panel.inc.php'))) { Grant-Apache $p 'RX' }
    # write: its logs, temp files, sessions and the webmail's and ActiveSync's own data
    foreach ($p in @((Join-Path $ApacheDir 'logs'), (Join-Path $Logs 'apache'), (Join-Path $Logs 'php'), (Join-Path $Logs 'roundcube'), (Join-Path $Logs 'z-push'),
                     (Join-Path $Logs 'panel'), $Tmp, (Join-Path $Root 'data\z-push'),
                     (Join-Path $Root 'panel\etc\apps\matomo\tmp'), (Join-Path $Root 'panel\etc\apps\matomo\config'))) { Grant-Apache $p 'M' }
    # the websites' logs (the panel's daemon makes a folder per account in it)
    if ($WithPanel) {
        $dl = Join-Path $Logs 'domains'
        if (-not (Test-Path $dl)) { New-Item -ItemType Directory -Path $dl | Out-Null }
        Grant-Apache $dl 'M'
    }
    # the panel's state folder: the panel writes its logs and requests there (zsudo's queue too); the folders only
    # root jobs write stay Administrators' and SYSTEM's, as on Linux (sys_statedir)
    $state = Join-Path $Root 'data\sentora'
    if (Test-Path $state) {
        Grant-Apache $state 'M'
        foreach ($d in @('accounts', 'clientftp', 'goaccess', 'matomo', 'sitehealth', 'monitor', 'phpaccounts', 'update')) {
            $p = Join-Path $state $d
            if (-not (Test-Path $p)) { New-Item -ItemType Directory -Path $p | Out-Null }
            Protect-Folder $p
        }
        # (sentora-update.ps1's progress and answers, which the Panel Update page reads; Server Monitor's figures)
        Grant-Apache (Join-Path $state 'update') 'RX'
        Grant-Apache (Join-Path $state 'monitor') 'RX'
        Grant-Apache (Join-Path $state 'sitehealth') 'RX'
        # the websites' certificates and keys (the panel's Sencrypt page writes them, Apache reads them; never the
        # accounts' own PHP), and Let's Encrypt's check files, which every site serves (plain files only)
        $certs = Join-Path $state 'certs'
        if (-not (Test-Path $certs)) { New-Item -ItemType Directory -Path $certs | Out-Null }
        Protect-Folder $certs
        Grant-Apache $certs 'M'
        $acme = Join-Path $state 'acme\.well-known\acme-challenge'
        if (-not (Test-Path $acme)) { New-Item -ItemType Directory -Path $acme | Out-Null }
    }
    $cur = (Get-CimInstance Win32_Service -Filter "Name='$ApacheService'").StartName
    if ($cur -ne $acct) {
        # (a service runs as its own NT SERVICE account only with its service SID on: "unrestricted")
        $out = Invoke-Native 'sc.exe' @('sidtype', $ApacheService, 'unrestricted')
        if ($LASTEXITCODE -ne 0) { Fail "Apache's service SID could not be turned on: $($out -join ' ')" }
        $out = Invoke-Native 'sc.exe' @('config', $ApacheService, 'obj=', $acct)
        if ($LASTEXITCODE -ne 0) { Fail "Apache's service could not be given its own account: $($out -join ' ')" }
    }
    $since = Get-Date
    try {
        Restart-Service $ApacheService -Force
        (Get-Service $ApacheService).WaitForStatus('Running', '00:01:00')
    } catch {
        # (why Windows would not start it: the Service Control Manager's and Apache's own words)
        Get-WinEvent -FilterHashtable @{ LogName = 'System', 'Application'; StartTime = $since } -ErrorAction SilentlyContinue |
            Where-Object { $_.ProviderName -match 'Service Control Manager|Apache' } | Select-Object -First 8 |
            ForEach-Object { Write-Host "   event $($_.Id) ($($_.ProviderName)): $($_.Message)" }
        Fail "Apache did not start as $acct (the events above say why; its error log: $(Join-Path $Logs 'apache\error.log'))."
    }
    Ok "$ApacheService runs as $acct (reads its programs and sites; writes its logs, temp files and data only)"

    # --------------------------------------------------------------------------------------------------------------
    # Matomo web analytics (the panel's etc\apps\matomo), as on Linux: set up once with Matomo's own setup pages (the
    # same steps as in a browser), then the panel's plugin and settings; the daemon then makes a Matomo site per domain
    # and a view-only Matomo user per account, and imports the websites' logs every hour with Python
    $matomo = Join-Path $Root 'panel\etc\apps\matomo'
    if ($WithPanel -and (Test-Path (Join-Path $matomo 'index.php'))) {
        Say 'Matomo web analytics (etc\apps\matomo)'
        $pyDir = Join-Path $Bin 'python'
        if (-not (Test-Path (Join-Path $pyDir 'python.exe'))) {
            $pz = Get-Verified $Pkg.Python
            Expand-Into $pz $Pkg.Python.Top $pyDir
        }
        Ok "Python $((Invoke-Native (Join-Path $pyDir 'python.exe') @('--version')) -join '') -> $pyDir (Matomo's log importer)"
        if (-not (Test-Path (Join-Path $matomo 'config\config.ini.php'))) {
            foreach ($d in @('tmp', 'config')) { $md = Join-Path $matomo $d; if (-not (Test-Path $md)) { New-Item -ItemType Directory -Path $md | Out-Null }; Grant-Apache $md 'M' }
            $mPw = New-Password; $mAdminPw = New-Password
            $q = "CREATE DATABASE IF NOT EXISTS sentora_matomo CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
            foreach ($h in @('localhost', '127.0.0.1')) {
                $q += " CREATE USER IF NOT EXISTS 'matomo'@'$h' IDENTIFIED BY '$mPw'; ALTER USER 'matomo'@'$h' IDENTIFIED BY '$mPw'; GRANT ALL PRIVILEGES ON sentora_matomo.* TO 'matomo'@'$h';"
            }
            $env:MYSQL_PWD = $RootPw
            try { $out = Invoke-Native (Join-Path $MySQLDir 'bin\mysql.exe') @('--protocol=TCP', '-h', '127.0.0.1', '-u', 'root', '-e', $q) } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
            if ($LASTEXITCODE -ne 0) { Fail "Matomo's database could not be made: $($out -join ' ')" }
            $mi = "http://127.0.0.1:$HttpPort/etc/apps/matomo/index.php"
            $jar = Join-Path $Tmp 'matomo-setup.cookies'
            Remove-Item $jar -Force -ErrorAction SilentlyContinue
            $steps = @(
                @($mi, @()), @("$mi`?action=systemCheck", @()),
                @("$mi`?action=databaseSetup", @('--data-urlencode', "password=$mPw", '-d', 'host=127.0.0.1&username=matomo&dbname=sentora_matomo&tables_prefix=matomo_&adapter=PDO\MYSQL&schema=Mysql&submit=Next')),
                @("$mi`?action=tablesCreation&module=Installation", @()),
                @("$mi`?action=setupSuperUser&module=Installation", @('--data-urlencode', "password=$mAdminPw", '--data-urlencode', "password_bis=$mAdminPw", '-d', "login=sentora_admin&email=postmaster@$ServerName&subscribe_newsletter_piwikorg=0&subscribe_newsletter_professionalservices=0&submit=Next")),
                @("$mi`?action=firstWebsiteSetup&module=Installation", @('-d', "siteName=$ServerName&url=http://$ServerName&timezone=UTC&ecommerce=0&submit=Next")),
                @("$mi`?action=trackingCode&module=Installation&site_idSite=1&site_name=$ServerName", @()),
                @("$mi`?action=finished&module=Installation&site_idSite=1&site_name=$ServerName", @('-d', 'do_not_track=1&anonymise_ip=1&submit=Continue'))
            )
            $said = @()
            foreach ($st in $steps) {
                $code = (Invoke-Native 'curl.exe' (@('-s', '-o', 'NUL', '-w', '%{http_code}', '-c', $jar, '-b', $jar, '-H', "Host: $ServerName", '--max-time', '300') + $st[1] + @($st[0]))) -join ''
                $said += "$code $(($st[0] -replace '^.*index\.php', '') -replace '&.*$', '')"
            }
            Remove-Item $jar -Force -ErrorAction SilentlyContinue
            if (-not (Test-Path (Join-Path $matomo 'config\config.ini.php'))) { Fail "Matomo's setup pages did not finish (each page's answer: $($said -join ', '))." }
            Write-Utf8NoBom (Join-Path $Cfg 'panel\matomo-admin-password.txt') "sentora_admin`r`n$mAdminPw"
            Ok "Matomo set up (its own pages: $($said -join ', ')); its super user sentora_admin, the password in configs\panel\matomo-admin-password.txt"
        } else { Ok 'Matomo was set up before (configs kept)' }
        # the panel's plugin and settings (each time: a repair puts them back), as SYSTEM with the panel's PHP
        Push-Location $matomo
        try {
            foreach ($c in @(@('plugin:activate', 'SentoraSync'), @('config:set', '--section=General', '--key=enable_framed_pages', '--value=1'),
                             @('config:set', '--section=General', '--key=enable_browser_archiving_triggering', '--value=0'),
                             @('config:set', '--section=General', '--key=enable_load_data_infile', '--value=0'),
                             @('config:set', '--section=General', '--key=enable_internet_features', '--value=0'),
                             @('plugin:deactivate', 'ProfessionalServices', 'Marketplace', 'Tour', 'Feedback'))) {
                $out = Invoke-Native $phpExe (@('-c', (Join-Path $PhpDir 'php.ini'), 'console') + $c)
                if ($LASTEXITCODE -ne 0 -and $c[0] -ne 'plugin:deactivate') { Write-Host "   NOTE Matomo's step 'console $($c -join ' ')' did not work: $(($out | Select-Object -Last 2) -join ' ')" -ForegroundColor Yellow }
            }
        } finally { Pop-Location }
        Ok "Matomo: the panel's plugin (SentoraSync) on, its settings for the panel (in a frame, reports built hourly, no calls home)"
    }

    if ($WithPanel) {
    # --------------------------------------------------------------------------------------------------------------
    Say "PHP per hosting account (each account's PHP as its own Windows account)"
    # Each hosting account's PHP runs as its own service account NT SERVICE\SentoraPHP-NAME, only in hostdata\NAME,
    # never as Apache's (step 2 of the panel on Windows, proven by the PHP per account probe). Accounts are made with
    # bin\phpaccount.ps1 (the panel will ask it through SentoraZsudo); this part puts the pieces in place and rebuilds
    # every account from its saved settings (keys kept), as after a PHP update.
    $nssm = Join-Path $Bin 'nssm\nssm.exe'
    if (-not (Test-Path $nssm)) { Expand-Into (Get-Verified $Pkg.Nssm) $Pkg.Nssm.Top (Join-Path $Bin 'nssm') }
    $phpCfg = Join-Path $Cfg 'php'
    foreach ($d in @($phpCfg, (Join-Path $phpCfg 'accounts'), (Join-Path $Cfg 'apache\php-accounts'))) { if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null } }
    Protect-Folder $phpCfg
    # the launcher each account's service runs: keeps its php-cgi running (php-cgi on Windows answers one request at a
    # time), each with the account's fences on its command line
    Write-Utf8NoBom (Join-Path $Bin 'sentora-php-pool.php') @'
<?php
// sentora-php-pool.php <pool.json> [name=value ...] (made by install-zpanel-stack.ps1; run by each account's service
// SentoraPHP-NAME as php.exe -n): keeps one php-cgi FastCGI process per port running, on 127.0.0.1 only, and starts
// again any that stops. pool.json (phpaccount.ps1) lists each PHP version the account's sites use: its php-cgi.exe,
// its php.ini and its ports. The name=value settings (the account's fences) go on each php-cgi's command line as -d,
// so they hold even if php.ini could not be read; each value in quotes (-d is read like a php.ini line).
// (an older service's form: <php.ini> <port,port,...> [name=value ...], with this PHP's own php-cgi)
if (PHP_SAPI !== 'cli' || $argc < 2) { exit(2); }
$groups = array();
$rest = array();
if (substr($argv[1], -5) === '.json') {
    foreach ((array) json_decode((string) @file_get_contents($argv[1]), true) as $g) {
        if (isset($g['cgi'], $g['ini'], $g['ports']) && is_file($g['cgi'])) { $groups[] = array($g['cgi'], $g['ini'], array_map('intval', (array) $g['ports'])); }
    }
    $rest = array_slice($argv, 2);
} elseif ($argc >= 3) {
    $groups[] = array(dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe', $argv[1], array_map('intval', explode(',', $argv[2])));
    $rest = array_slice($argv, 3);
}
if (!$groups) { exit(2); }
$extra = array();
foreach ($rest as $a) {
    $eq = strpos($a, '=');
    if ($eq > 0 && strpos($a, '"') === false) { $extra[] = '-d'; $extra[] = substr($a, 0, $eq) . '="' . substr($a, $eq + 1) . '"'; }
}
putenv('PHP_FCGI_MAX_REQUESTS=0');
$run = array();
while (true) {
    foreach ($groups as $g) {
        foreach ($g[2] as $p) {
            if ($p < 1024 || $p > 65535) { continue; }
            if (!isset($run[$p]) || !proc_get_status($run[$p])['running']) {
                $run[$p] = proc_open(array_merge(array($g[0], '-b', "127.0.0.1:$p", '-c', $g[1]), $extra), array(), $pipes, null, null, array('bypass_shell' => true));
            }
        }
    }
    sleep(1);
}
'@
    Write-Utf8NoBom (Join-Path $Bin 'phpaccount.ps1') @'
<#
  PHP per hosting account (made by install-zpanel-stack.ps1). Each account's PHP runs as its own Windows account
  (NT SERVICE\SentoraPHP-NAME), only in C:\zpanel\hostdata\NAME; Apache passes it the PHP of every site in that folder,
  with the account's key (its guard refuses a request without it: another account's PHP can not ask it to run scripts).
  As Administrator:
    phpaccount.ps1 add NAME [-Processes 3]   its folder, service, php.ini, guard, key and Apache's part; Apache restarts
    phpaccount.ps1 remove NAME               the service and its settings go; the files in hostdata\NAME stay
    phpaccount.ps1 list
    phpaccount.ps1 repair                    every account again from its saved settings (keys kept): after a PHP update
    phpaccount.ps1 sync                      the panel's accounts in line with the list its daemon wrote (only root's
                                             jobs may write it: data\sentora\phpaccounts\wanted.txt); the panel asks
                                             for it through SentoraZsudo. Accounts added by hand are left alone.
  Other PHP versions (bin\php-8.4, bin\php-8.3: the installer's): wanted.txt names them after an account ("shop 8.4,8.3")
  when its domains use them; the account's one service then runs their php-cgi too, each on ports of its own, and
  Apache's part has a pool for each (php84-NAME), which the panel's sites file names for those domains.
#>
param([Parameter(Mandatory = $true)][ValidateSet('add', 'remove', 'list', 'repair', 'sync')][string]$Do, [string]$Name,
      [ValidateRange(1, 16)][int]$Processes = 3, [switch]$NoRestart)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$php = Join-Path $root 'bin\php'
$nssm = Join-Path $root 'bin\nssm\nssm.exe'
$pool = Join-Path $root 'bin\sentora-php-pool.php'
$cfg = Join-Path $root 'configs\php'
$saved = Join-Path $cfg 'accounts'
$apacheCfg = Join-Path $root 'configs\apache\php-accounts'
$fw = $root -replace '\\', '/'
$group = 'SentoraPHP'
# (ports: 16 per account from 20000, one php-cgi on each in use)
$portBase = 20000; $portBlock = 16
function Native($exe, $argv) { $ErrorActionPreference = 'Continue'; $o = & $exe @argv 2>&1; if ($LASTEXITCODE -ne 0) { throw "$exe $($argv -join ' '): $($o -join ' ')" }; $o }
function Version-Dir($v) { if ($v -match '^\d+\.\d+$') { $d = Join-Path $root ("bin\php-$v"); if (Test-Path (Join-Path $d 'php-cgi.exe')) { return $d } }; return $null }
function Get-Versions($a) { if ($a.PSObject.Properties['versions']) { return @($a.versions | Where-Object { Version-Dir $_ }) }; return @() }
function Free-Base($skip) {
    $used = @(Get-Saved | ForEach-Object { $_.base; if ($_.PSObject.Properties['extra']) { $_.extra.PSObject.Properties | ForEach-Object { $_.Value } } }) + $skip
    for ($i = 0; $i -lt 2000; $i++) {
        $b = $portBase + $i * $portBlock
        if ($used -notcontains $b -and -not (Get-NetTCPConnection -LocalPort ($b..($b + $portBlock - 1)) -ErrorAction SilentlyContinue)) { return $b }
    }
    throw 'no free ports for another account'
}
function Get-Saved { @(Get-ChildItem $saved -Filter '*.json' -ErrorAction SilentlyContinue | ForEach-Object { Get-Content $_.FullName -Raw | ConvertFrom-Json }) }
# (the panel's user names: letters, digits and -, 1-63, not starting or ending with -; lower case here)
function Test-Name($n) { if ($n -cnotmatch '^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$') { throw "NAME: a-z, 0-9 and -, at most 63, not starting or ending with - ($n)" } }
function New-Account($n, $procs, $byPanel) {
    $used = @(Get-Saved | ForEach-Object { $_.base })
    $base = 0
    for ($i = 0; $i -lt 2000 -and -not $base; $i++) {
        $b = $portBase + $i * $portBlock
        if ($used -notcontains $b -and -not (Get-NetTCPConnection -LocalPort ($b..($b + $portBlock - 1)) -ErrorAction SilentlyContinue)) { $base = $b }
    }
    if (-not $base) { throw 'no free ports for another account' }
    $bytes = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $a = [pscustomobject]@{ name = $n; base = $base; processes = $procs; key = (-join ($bytes | ForEach-Object { $_.ToString('x2') })); panel = [bool]$byPanel }
    [IO.File]::WriteAllText((Join-Path $saved "$n.json"), ($a | ConvertTo-Json))
    $a
}
function Remove-Account($n) {
    $svc = "SentoraPHP-$n"
    # (Apache's part first, so nothing is sent to a PHP that is going)
    Remove-Item (Join-Path $apacheCfg "$n.conf") -Force -ErrorAction SilentlyContinue
    if (Get-Service $svc -ErrorAction SilentlyContinue) {
        Stop-Service $svc -Force -ErrorAction SilentlyContinue
        Native 'sc.exe' @('delete', $svc) | Out-Null
    }
    Remove-LocalGroupMember -Group $group -Member "NT SERVICE\$svc" -ErrorAction SilentlyContinue
    foreach ($f in @((Join-Path $apacheCfg "$n.conf"), (Join-Path $cfg "$n.ini"), (Join-Path $cfg "$n-guard.php"), (Join-Path $saved "$n.json"))) { Remove-Item $f -Force -ErrorAction SilentlyContinue }
    Remove-Item (Join-Path $cfg "$n-*.ini"), (Join-Path $cfg "$n.pool.json") -Force -ErrorAction SilentlyContinue
}

# The File Manager's code (from the panel's modules\file_manager\app), copied once a run to C:\zpanel\filemanager: the
# accounts' PHP reads it there, Apache serves its pictures and scripts; nobody writes in it but Administrators and SYSTEM.
$script:fmDone = $null
function Copy-FileManager {
    if ($script:fmDone -ne $null) { return $script:fmDone }
    $src = Join-Path $root 'panel\modules\file_manager\app'
    $dst = Join-Path $root 'filemanager'
    $script:fmDone = ''
    if (-not (Test-Path (Join-Path $src 'winaccount.inc.php'))) { return '' }
    $o = & robocopy.exe $src $dst /MIR /NFL /NDL /NJH /NJS /NP 2>&1
    if ($LASTEXITCODE -ge 8) { Write-Warning "the File Manager could not be copied (robocopy $LASTEXITCODE)"; return '' }
    Native 'icacls.exe' @($dst, '/inheritance:r', '/grant:r', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', "${group}:(OI)(CI)RX", 'NT SERVICE\Apache2:(OI)(CI)RX') | Out-Null
    $script:fmDone = $dst
    return $dst
}

function Build($a) {
    $n = $a.name; $svc = "SentoraPHP-$n"; $sid = "NT SERVICE\$svc"; $hd = Join-Path $root "hostdata\$n"
    foreach ($d in @($hd, "$hd\public_html", "$hd\tmp", "$hd\sessions")) { if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null } }
    $ports = @(0..($a.processes - 1) | ForEach-Object { $a.base + $_ })
    # its guard: run before every script (auto_prepend_file); refuses a request without the account's key, which only
    # Apache sends, then hides the key from the site's own scripts
    $guard = Join-Path $cfg "$n-guard.php"
    [IO.File]::WriteAllText($guard, "<?php`r`n// $n's PHP answers Apache only (made by phpaccount.ps1)`r`nif (!isset(`$_SERVER['SENTORA_KEY']) || !hash_equals('$($a.key)', (string) `$_SERVER['SENTORA_KEY'])) { header('Status: 403 Forbidden'); exit; }`r`nunset(`$_SERVER['SENTORA_KEY'], `$_ENV['SENTORA_KEY']);`r`n")
    # its php.ini: the server's, then the account's own folders (the last value of a name wins); the fences go on the
    # command line as well (sentora-php-pool.php)
    $ini = Join-Path $cfg "$n.ini"
    $base = [IO.File]::ReadAllText((Join-Path $php 'php.ini'))
    # (the panel's File Manager runs as the account's own PHP: a copy of its code in a folder of its own, which the
    # accounts may read; the panel's folder stays closed to them)
    $fmApp = Copy-FileManager
    $fence = if ($fmApp) { "$hd\;$guard;$fmApp" } else { "$hd\;$guard" }
    [IO.File]::WriteAllText($ini, $base + "`r`n; --- $n (phpaccount.ps1)`r`nopen_basedir = `"$fence`"`r`nauto_prepend_file = `"$guard`"`r`nerror_log = `"$hd\tmp\php-error.log`"`r`nupload_tmp_dir = `"$hd\tmp`"`r`nsys_temp_dir = `"$hd\tmp`"`r`nsession.save_path = `"$hd\sessions`"`r`ncgi.fix_pathinfo = 0`r`n")
    # the other PHP versions its domains use: each its own php.ini (that PHP's, then the same fences) and ports
    $tail = "`r`n; --- $n (phpaccount.ps1)`r`nopen_basedir = `"$fence`"`r`nauto_prepend_file = `"$guard`"`r`nerror_log = `"$hd\tmp\php-error.log`"`r`nupload_tmp_dir = `"$hd\tmp`"`r`nsys_temp_dir = `"$hd\tmp`"`r`nsession.save_path = `"$hd\sessions`"`r`ncgi.fix_pathinfo = 0`r`n"
    $spec = @(@{ cgi = (Join-Path $php 'php-cgi.exe'); ini = $ini; ports = $ports })
    $more = @()
    if (-not $a.PSObject.Properties['extra']) { $a | Add-Member -NotePropertyName extra -NotePropertyValue ([pscustomobject]@{}) }
    foreach ($v in (Get-Versions $a)) {
        $vd = Version-Dir $v; $vk = $v -replace '\.', ''
        if (-not $a.extra.PSObject.Properties[$v]) { $a.extra | Add-Member -NotePropertyName $v -NotePropertyValue (Free-Base (@($a.base) + @($a.extra.PSObject.Properties | ForEach-Object { $_.Value }))) }
        $vb = [int]$a.extra.$v
        $vports = @(0..($a.processes - 1) | ForEach-Object { $vb + $_ })
        $vini = Join-Path $cfg "$n-$vk.ini"
        [IO.File]::WriteAllText($vini, [IO.File]::ReadAllText((Join-Path $vd 'php.ini')) + $tail)
        Native 'icacls.exe' @($vini, '/inheritance:r', '/grant:r', '*S-1-5-18:F', '*S-1-5-32-544:F', "${sid}:R") | Out-Null
        $spec += @{ cgi = (Join-Path $vd 'php-cgi.exe'); ini = $vini; ports = $vports }
        $more += [pscustomobject]@{ key = $vk; ports = $vports }
    }
    # (the ports each version was given are kept, so a rebuild keeps them)
    [IO.File]::WriteAllText((Join-Path $saved "$n.json"), ($a | ConvertTo-Json -Depth 4))
    $specFile = Join-Path $cfg "$n.pool.json"
    [IO.File]::WriteAllText($specFile, (ConvertTo-Json -InputObject $spec -Depth 4))
    Native 'icacls.exe' @($specFile, '/inheritance:r', '/grant:r', '*S-1-5-18:F', '*S-1-5-32-544:F', "${sid}:R") | Out-Null
    $params = "-n `"$pool`" `"$specFile`" `"open_basedir=$fence`" `"auto_prepend_file=$guard`""
    if (Get-Service $svc -ErrorAction SilentlyContinue) { Stop-Service $svc -Force -ErrorAction SilentlyContinue }
    else { Native $nssm @('install', $svc, (Join-Path $php 'php.exe')) | Out-Null }
    Native $nssm @('set', $svc, 'Application', (Join-Path $php 'php.exe')) | Out-Null
    Native $nssm @('set', $svc, 'AppParameters', $params) | Out-Null
    Native $nssm @('set', $svc, 'AppDirectory', $hd) | Out-Null
    Native $nssm @('set', $svc, 'DisplayName', "Sentora PHP for $n") | Out-Null
    Native $nssm @('set', $svc, 'Description', "PHP of the hosting account $n, as its own account, only in $hd") | Out-Null
    Native $nssm @('set', $svc, 'Start', 'SERVICE_AUTO_START') | Out-Null
    Native $nssm @('set', $svc, 'AppExit', 'Default', 'Restart') | Out-Null
    Native $nssm @('set', $svc, 'AppRestartDelay', '1000') | Out-Null
    Native 'sc.exe' @('sidtype', $svc, 'unrestricted') | Out-Null
    Native 'sc.exe' @('config', $svc, 'obj=', $sid) | Out-Null
    $m = @(Get-LocalGroupMember -Group $group -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq $sid })
    if (-not $m) { Add-LocalGroupMember -Group $group -Member $sid }
    # (what it may use: its own folder changed, its php.ini and guard read; PHP and the launcher come with the group)
    Native 'icacls.exe' @($hd, '/grant', "${sid}:(OI)(CI)M") | Out-Null
    Native 'icacls.exe' @($ini, '/inheritance:r', '/grant:r', '*S-1-5-18:F', '*S-1-5-32-544:F', "${sid}:R") | Out-Null
    Native 'icacls.exe' @($guard, '/inheritance:r', '/grant:r', '*S-1-5-18:F', '*S-1-5-32-544:F', "${sid}:R") | Out-Null
    # Apache's part: every site in hostdata\NAME has its PHP done by NAME's (in turn), with NAME's key
    $members = ($ports | ForEach-Object { "    BalancerMember `"fcgi://127.0.0.1:$_`" retry=0" }) -join "`r`n"
    [IO.File]::WriteAllText((Join-Path $apacheCfg "$n.conf"), @"
# $n's PHP (NT SERVICE\$svc), made by phpaccount.ps1: run phpaccount.ps1 repair rather than changing it here
<Proxy "balancer://php-$n">
$members
    ProxySet lbmethod=byrequests timeout=300
</Proxy>
$(($more | ForEach-Object { "<Proxy `"balancer://php$($_.key)-$n`">`r`n" + (($_.ports | ForEach-Object { "    BalancerMember `"fcgi://127.0.0.1:$_`" retry=0" }) -join "`r`n") + "`r`n    ProxySet lbmethod=byrequests timeout=300`r`n</Proxy>" }) -join "`r`n")
<Directory "$fw/hostdata/$n">
    <FilesMatch "\.php$">
        Require all granted
        SetHandler "proxy:balancer://php-$n/"
    </FilesMatch>
    ProxyFCGIBackendType GENERIC
    ProxyFCGISetEnvIf "true" SCRIPT_FILENAME "%{DOCUMENT_ROOT}%{reqenv:SCRIPT_NAME}"
    ProxyFCGISetEnvIf "true" SENTORA_KEY "$($a.key)"
</Directory>
"@)
    if ($fmApp) {
        # the panel's File Manager at /sentora-fm/NAME/, run by NAME's own PHP with NAME's key (NAME's files only; the
        # panel sends a signed pass, app\winaccount.inc.php); its pictures and scripts are plain files
        $fmFw = $fmApp -replace '\\', '/'
        [IO.File]::AppendAllText((Join-Path $apacheCfg "$n.conf"), "`r`n" + @"
Alias "/sentora-fm/$n/" "$fmFw/"
<Directory "$fmFw">
    Options None
    AllowOverride None
    Require all granted
    # (its PHP files are never sent as they are: only the two pages below, run by the account's PHP)
    <FilesMatch "\.(php|json)$|^(VERSION|LICENSE)">
        Require all denied
    </FilesMatch>
</Directory>
<LocationMatch "^/sentora-fm/$n/(index|raw)\.php$">
    Require all granted
    SetEnvIf Request_URI "^/sentora-fm/$n/((index|raw)\.php)$" SENTORA_FM=`$1
    SetHandler "proxy:balancer://php-$n/"
    ProxyFCGIBackendType GENERIC
    ProxyFCGISetEnvIf "true" SCRIPT_FILENAME "$fmFw/%{reqenv:SENTORA_FM}"
    ProxyFCGISetEnvIf "true" SENTORA_KEY "$($a.key)"
</LocationMatch>
"@)
    }
    Start-Service $svc
}

function Restart-Apache {
    if ($NoRestart) { return }
    $httpd = Join-Path $root 'bin\apache\bin\httpd.exe'
    $conf = Join-Path $root 'configs\apache\httpd.conf'
    $t = cmd.exe /c "`"$httpd`" -t -f `"$conf`" 2>&1"
    if ($LASTEXITCODE -ne 0) { throw "Apache refuses its settings: $($t -join ' ')" }
    # (gently: the pages being served finish; a full restart only if Apache does not take the signal)
    $t = cmd.exe /c "`"$httpd`" -k restart -n Apache2 -f `"$conf`" 2>&1"
    if ($LASTEXITCODE -ne 0) { Restart-Service Apache2 -Force }
}

switch ($Do) {
    'add' {
        Test-Name $Name
        $file = Join-Path $saved "$Name.json"
        if (Test-Path $file) { $a = Get-Content $file -Raw | ConvertFrom-Json; $a.processes = $Processes; [IO.File]::WriteAllText($file, ($a | ConvertTo-Json)) }
        else { $a = New-Account $Name $Processes $false }
        Build $a
        Restart-Apache
        "PHP for $Name`: NT SERVICE\SentoraPHP-$Name, $($a.processes) php-cgi on 127.0.0.1:$($a.base)-$($a.base + $a.processes - 1), folder $root\hostdata\$Name"
    }
    'remove' {
        Test-Name $Name
        Remove-Account $Name
        Restart-Apache
        "PHP for $Name removed (the files in $root\hostdata\$Name stay)"
    }
    'list' {
        Get-Saved | ForEach-Object { [pscustomobject]@{ Account = $_.name; Ports = "$($_.base)-$($_.base + $_.processes - 1)"; Service = (Get-Service "SentoraPHP-$($_.name)" -ErrorAction SilentlyContinue).Status } } | Format-Table -AutoSize
    }
    'sync' {
        # the list the panel's daemon (SYSTEM) wrote where only root's jobs may write; a name that is not a valid one
        # is skipped, and only accounts the panel made are removed
        $list = Join-Path $root 'data\sentora\phpaccounts\wanted.txt'
        if (-not (Test-Path $list)) { throw "no list from the panel ($list)" }
        # (each line: NAME, then the other PHP versions its domains use, if any: "shop 8.4,8.3")
        $vers = @{}
        foreach ($l in (Get-Content $list)) {
            if ($l.Trim() -cmatch '^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\s+(\d+\.\d+(?:,\d+\.\d+)*))?$') {
                $vers[$Matches[1]] = @(if ($Matches[2]) { $Matches[2].Split(',') | Where-Object { Version-Dir $_ } | Sort-Object -Unique })
            }
        }
        $want = @($vers.Keys | Sort-Object)
        $have = @(Get-Saved)
        $changed = 0
        foreach ($n in $want) {
            $a = $have | Where-Object { $_.name -eq $n } | Select-Object -First 1
            $wantV = @($vers[$n])
            if (-not $a) { $a = New-Account $n $Processes $true; $a | Add-Member -NotePropertyName versions -NotePropertyValue $wantV -Force; Build $a; $changed++; continue }
            $haveV = @(Get-Versions $a)
            if (($haveV -join ',') -ne ($wantV -join ',')) {
                $a | Add-Member -NotePropertyName versions -NotePropertyValue $wantV -Force
                Build $a; $changed++
            } elseif (-not (Get-Service "SentoraPHP-$n" -ErrorAction SilentlyContinue) -or -not (Test-Path (Join-Path $apacheCfg "$n.conf"))) { Build $a; $changed++ }
        }
        foreach ($a in $have) {
            if ($a.panel -and $want -notcontains $a.name) { Remove-Account $a.name; $changed++ }
        }
        if ($changed) { Restart-Apache }
        "PHP per account: $($want.Count) wanted by the panel, $changed changed"
    }
    'repair' {
        $all = Get-Saved
        foreach ($a in $all) { Build $a }
        if ($all) { Restart-Apache }
        "PHP per account: $(@($all).Count) account(s) rebuilt"
    }
}
'@
    # the group every account's service is in: reads PHP, NSSM and the launcher; lists the folders above what PHP
    # reads (php-cgi looks at each folder on a path: without "list" it runs with no php.ini at all)
    if (-not (Get-LocalGroup -Name 'SentoraPHP' -ErrorAction SilentlyContinue)) { New-LocalGroup -Name 'SentoraPHP' -Description 'The PHP services of the Sentora hosting accounts' | Out-Null }
    foreach ($p in @(@($PhpDir, (Join-Path $Bin 'nssm')) + @($phpExtra | ForEach-Object { Join-Path $Bin "php-$_" }))) {
        $o = Invoke-Native 'icacls.exe' @($p, '/grant', 'SentoraPHP:(OI)(CI)RX')
        if ($LASTEXITCODE -ne 0) { Fail "Could not let the PHP accounts read ${p}: $($o -join ' ')" }
    }
    foreach ($p in @((Join-Path $Bin 'sentora-php-pool.php'), $Root, $Bin, $Cfg, $phpCfg, (Join-Path $Root 'hostdata'))) {
        $o = Invoke-Native 'icacls.exe' @($p, '/grant', 'SentoraPHP:(RX)')
        if ($LASTEXITCODE -ne 0) { Fail "Could not let the PHP accounts see ${p}: $($o -join ' ')" }
    }
    $out = Invoke-Native 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $Bin 'phpaccount.ps1'), 'repair')
    if ($LASTEXITCODE -ne 0) { Fail "The PHP accounts could not be rebuilt: $($out -join ' ')" }
    Ok "C:\zpanel\bin\phpaccount.ps1: add, remove and list hosting accounts' PHP ($(($out | Select-Object -Last 1)))"
    # (the panel's own accounts: the list its daemon wrote, when the panel is here)
    if (Test-Path (Join-Path $Root 'data\sentora\phpaccounts\wanted.txt')) {
        $out = Invoke-Native 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $Bin 'phpaccount.ps1'), 'sync')
        if ($LASTEXITCODE -ne 0) { Note "The panel's accounts could not be given their PHP yet (the daemon asks again): $($out -join ' ')" } else { Ok "$(($out | Select-Object -Last 1))" }
    }
    }

    # --------------------------------------------------------------------------------------------------------------
    Say 'Checking it all'
    $r = $null
    for ($i = 0; $i -lt 20 -and -not $r; $i++) {
        try { $r = Invoke-WebRequest -Uri "http://127.0.0.1:$HttpPort/" -UseBasicParsing -TimeoutSec 10 } catch { Start-Sleep 1 }
    }
    if (-not $r -or $r.StatusCode -ne 200) { Fail "Apache does not answer on port ${HttpPort}: see $(Join-Path $Logs 'apache\error.log')" }
    Ok "http://127.0.0.1:$HttpPort/ answers ($($r.StatusCode))"

    # PHP inside Apache: a one-off page that reports itself, then goes
    $probe = Join-Path $Root ('panel\zstack-check-' + [guid]::NewGuid().ToString('N') + '.php')
    Write-Utf8NoBom $probe @"
<?php
echo PHP_VERSION, '|', PHP_SAPI, '|', (extension_loaded('mysqli') ? 'mysqli' : 'no-mysqli'), '|', (extension_loaded('openssl') ? 'openssl' : 'no-openssl'), '|', (function_exists('opcache_get_status') ? 'opcache' : 'no-opcache');
"@
    try {
        $c = (Invoke-WebRequest -Uri ("http://127.0.0.1:$HttpPort/" + (Split-Path $probe -Leaf)) -UseBasicParsing -TimeoutSec 15).Content
    } finally { Remove-Item $probe -Force -ErrorAction SilentlyContinue }
    $f = $c -split '\|'
    if ($f.Count -lt 5 -or $f[1] -ne 'apache2handler' -or $f[2] -ne 'mysqli' -or $f[3] -ne 'openssl') { Fail "PHP inside Apache is not right: $c" }
    Ok "PHP $($f[0]) runs inside Apache with mysqli, openssl and $($f[4])"
    if ($WithPanel) {
        $pg = $null; try { $pg = Invoke-WebRequest -Uri "http://127.0.0.1:$HttpPort/" -UseBasicParsing -TimeoutSec 30 } catch { }
        if (-not $pg -or $pg.Content -notmatch 'name="inUsername"') { Fail "The Sentora panel's login page does not show: see $(Join-Path $Logs 'php\php_errors.log')" }
        Ok 'the Sentora panel shows its login page'
    }

    # --------------------------------------------------------------------------------------------------------------
    Say 'Updates (sentora-update.ps1) and the nightly check'
    # the answers of this install, so an update asks nothing and changes nothing you chose
    $choices = [ordered]@{ Root = $Root; ServerName = $ServerName; HttpPort = $HttpPort; HttpsPort = $HttpsPort; TimeZone = $TimeZone
        WithFtp = [bool]$WithFtp; WithMail = [bool]$WithMail; WithClamAV = $askedClamAV; WithDns = [bool]$WithDns; WithPanel = [bool]$WithPanel
        RelaxedSqlMode = [bool]$RelaxedSqlMode; LegacyForeignKeys = [bool]$LegacyForeignKeys; NoFirewall = [bool]$NoFirewall }
    if ($WithMail) { $choices['MailDomain'] = $MailDomain }
    Write-Utf8NoBom (Join-Path $Cfg 'install-choices.json') ($choices | ConvertTo-Json)
    if ($InstallerVersion -ne 'dev') { Write-Utf8NoBom (Join-Path $Cfg 'version.txt') $InstallerVersion }
    # (when the panel's code was put in place, for Panel Update; an update writes its own line after this)
    $instRec = Join-Path $Root 'data\sentora\update\installed'
    if ($WithPanel -and (Test-Path (Split-Path $instRec))) { Write-Utf8NoBom $instRec "- $InstallerVersion, $(Get-Date -Format 'yyyy-MM-dd HH:mm') by the installer`n" }
    $updCfg = Join-Path $Cfg 'update'
    if (-not (Test-Path $updCfg)) { New-Item -ItemType Directory -Path $updCfg | Out-Null }
    Protect-Folder $updCfg
    Write-Utf8NoBom (Join-Path $Bin 'sentora-update.ps1') @'
<#
  Sentora-Windows updates (made by install-zpanel-stack.ps1). As Administrator:
    sentora-update.ps1 -Check    only tells whether a newer release is out (the nightly check runs this; it writes
                                 C:\zpanel\logs\update-available.txt and an entry in Windows' Application log)
    sentora-update.ps1           updates: downloads the newest release, checks its checksum, keeps a copy of the settings,
                                 then runs its installer as a repair with the answers of your install (data, mailboxes,
                                 passwords and zones are kept, as with every repair)
    sentora-update.ps1 -Force    the same even when the version is not newer
  The releases are RusTus/sentora-installers-2027's (the file sentora-windows-server.zip on the release "current",
  checked against the SHA-256 GitHub keeps for it); no token is needed. One in
  C:\zpanel\configs\update\github-token.txt is still used when there (GitHub's limit on questions per hour is higher).
#>
param([switch]$Check, [switch]$Force, [switch]$FromPanel)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$repo = 'RusTus/sentora-installers-2027'
$cfg = Join-Path $root 'configs'
$tokenFile = Join-Path $cfg 'update\github-token.txt'
$note = Join-Path $root 'logs\update-available.txt'
$verFile = Join-Path $cfg 'version.txt'
# (what the panel's Panel Update page reads, in the same lines as Linux's sentora-update writes; only root's jobs write there)
$upd = Join-Path $root 'data\sentora\update'
function UnixNow { [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() }
function Plain($t) { ("$t" -replace '[\t\r\n]+', ' ').Trim() }
function Put($name, $text) { try { if (Test-Path $upd) { [IO.File]::WriteAllText((Join-Path $upd "$name.new"), $text); Move-Item (Join-Path $upd "$name.new") (Join-Path $upd $name) -Force } } catch { } }
function St($text) { try { if (Test-Path $upd) { [IO.File]::AppendAllText((Join-Path $upd 'status'), "$text`n") } } catch { } }
$script:stepNo = 0; $script:stepAt = 0
function Step($title) {
    if ($script:stepNo -gt 0) { St "end`tok`t$((UnixNow) - $script:stepAt)" }
    $script:stepNo++; $script:stepAt = UnixNow
    St "step`t$($script:stepNo)`t5`t$title"
    "---- $title"
}
$have = if (Test-Path $verFile) { (Get-Content $verFile -Raw).Trim() } else { 'unknown' }
$h = @{ 'User-Agent' = 'sentora-windows-update'; Accept = 'application/vnd.github+json' }
if (Test-Path $tokenFile) { $t = (Get-Content $tokenFile -Raw).Trim(); if ($t) { $h['Authorization'] = "Bearer $t" } }
try { $rel = Invoke-RestMethod "https://api.github.com/repos/$repo/releases/tags/current" -Headers $h -UseBasicParsing -TimeoutSec 60 }
catch {
    if ($Check) { Put 'available' "$(UnixNow) unknown`n" }
    $code = 0; try { $code = [int]$_.Exception.Response.StatusCode } catch { }
    if ($code -eq 404 -or $code -eq 401) { throw "GitHub does not show $repo's releases to this server (a private repository?): put a read-only token in $tokenFile (see the top of this file)." }
    throw "GitHub could not be asked: $($_.Exception.Message)"
}
if ("$($rel.name)" -notmatch '(2027\.\d+)') { throw "The current release's name holds no version: $($rel.name)" }
$new = $Matches[1]
function Num($v) { if ("$v" -match '^2027\.(\d+)$') { [int]$Matches[1] } else { -1 } }
$newer = (Num $new) -gt (Num $have)
$zipAsset = @($rel.assets) | Where-Object { $_.name -eq 'sentora-windows-server.zip' } | Select-Object -First 1
$published = if ($zipAsset -and $zipAsset.updated_at -is [datetime]) { $zipAsset.updated_at.ToString('yyyy-MM-dd') } elseif ($zipAsset -and "$($zipAsset.updated_at)" -match '^(\d{4}-\d{2}-\d{2})') { $Matches[1] } else { '-' }
Put 'available' ("$(UnixNow) " + $(if ($newer) { "available $new $published $repo" } else { 'current' }) + "`n")
if ($Check) {
    if ($newer) {
        $msg = "Sentora-Windows $new is out (this server has $have). To update, as Administrator: $root\bin\sentora-update.ps1"
        [IO.File]::WriteAllText($note, "$(Get-Date -Format s)  $msg`r`n")
        try {
            if (-not [Diagnostics.EventLog]::SourceExists('Sentora')) { New-EventLog -LogName Application -Source 'Sentora' }
            Write-EventLog -LogName Application -Source 'Sentora' -EventId 1001 -EntryType Information -Message $msg
        } catch { }
        $msg
    } else { Remove-Item $note -ErrorAction SilentlyContinue; "Up to date: $have (the newest release is $new)." }
    return
}
$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this as Administrator.' }
Put 'status' "start`tupdate`t$(UnixNow)`t$(if ($FromPanel) { 'panel' } else { 'console' })`n"
if (-not $newer -and -not $Force) {
    St "done`tsame`tAlready up to date: $have."
    "Up to date: $have (the newest release is $new). -Force runs it again anyway."; return
}
"Updating: $have -> $new"
$daemonTask = $null; $phpAccounts = @()
try {
Step 'Download the release and check its checksum'
$work = Join-Path $root ('temp\update-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $work | Out-Null
function Get-Asset($name) {
    # (the file must match the SHA-256 GitHub keeps for it)
    $a = @($rel.assets) | Where-Object { $_.name -eq $name } | Select-Object -First 1
    if (-not $a) { throw "The release has no $name" }
    if ("$($a.digest)" -notmatch '^sha256:([0-9a-fA-F]{64})$') { throw "The release gives no SHA-256 for ${name}: nothing was changed." }
    $sha = $Matches[1].ToUpper()
    $dh = $h.Clone(); $dh['Accept'] = 'application/octet-stream'
    $f = Join-Path $work $name
    Invoke-WebRequest $a.url -Headers $dh -OutFile $f -UseBasicParsing -TimeoutSec 600
    if ((Get-FileHash $f -Algorithm SHA256).Hash -ne $sha) { throw 'The download does not match its checksum: nothing was changed.' }
    return $f
}
$zip = Get-Asset 'sentora-windows-server.zip'
"ok   the download matches its checksum"
Expand-Archive $zip -DestinationPath $work -Force
St "info`tSentora-Windows $new, checksum matches"
Step 'Keep a copy of the settings'
# a copy of the settings first (a repair keeps them anyway; this one is for looking back)
$bk = Join-Path $root 'backups'
if (-not (Test-Path $bk)) { New-Item -ItemType Directory -Path $bk | Out-Null }
$copy = Join-Path $bk ("configs-before-$new-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
Compress-Archive -Path (Join-Path $cfg '*') -DestinationPath $copy
"ok   settings copied to $copy"
St "info`t$(Plain $copy)"
Step 'Stop the services'
# the services NSSM runs stop first, and their programs are waited for (an installer of 2027.2 or older replaced their
# folders while a program could still be exiting)
# (the panel's too: SentoraZsudo and the daemon's task run PHP from bin\php, which the installer replaces)
$daemonTask = Get-ScheduledTask -TaskName 'Sentora daemon', 'Sentora cron' -ErrorAction SilentlyContinue
if ($daemonTask) { $daemonTask | Disable-ScheduledTask | Out-Null }
foreach ($n in @('Stalwart', 'ClamAV', 'FreshClam', 'CoreDNS', 'SentoraZsudo')) { Stop-Service -Name $n -Force -ErrorAction SilentlyContinue }
$phpAccounts = @(Get-Service -Name 'SentoraPHP-*' -ErrorAction SilentlyContinue)
$phpAccounts | Stop-Service -Force -ErrorAction SilentlyContinue
$binDir = (Join-Path $root 'bin') + '\'
for ($i = 0; $i -lt 120; $i++) {
    # (php: a daemon run already going is let finish for up to a minute)
    $left = @(Get-Process -Name stalwart, clamd, freshclam, coredns, php, php-cgi -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path.StartsWith($binDir, [StringComparison]::OrdinalIgnoreCase) })
    if (-not $left) { break }
    if ($i -eq 10) { $left | Where-Object { $_.Name -ne 'php' } | Stop-Process -Force -ErrorAction SilentlyContinue }
    if ($i -eq 119) { $left | Stop-Process -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Milliseconds 500
}
"ok   services stopped"
Step 'Run the installer of the new release (a repair, with your answers)'
# the new installer, as a repair, with this server's answers
$cmd = "`$ErrorActionPreference = 'Stop'; & '" + (Join-Path $work 'install-zpanel-stack.ps1').Replace("'", "''") + "' -Unattended"
$ch = Join-Path $cfg 'install-choices.json'
# (only the answers the new installer knows: one saved by a newer installer than the release would stop it)
$known = @((Get-Command (Join-Path $work 'install-zpanel-stack.ps1')).Parameters.Keys)
if (Test-Path $ch) {
    foreach ($p in (Get-Content $ch -Raw | ConvertFrom-Json).PSObject.Properties) {
        if ($known -notcontains $p.Name) { continue }
        if ($p.Value -is [bool]) { $cmd += " -$($p.Name):`$$("$($p.Value)".ToLower())" }
        elseif ("$($p.Value)" -ne '') { $cmd += " -$($p.Name) '" + "$($p.Value)".Replace("'", "''") + "'" }
    }
}
$cmd += '; exit 0'
$enc = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($cmd))
$p = Start-Process powershell.exe -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $enc -Wait -PassThru -NoNewWindow
if ($p.ExitCode -ne 0) { St "end`tfail`t$((UnixNow) - $script:stepAt)"; $script:stepNo = 0; throw "The installer of $new stopped (exit code $($p.ExitCode)): see its log in $root\logs. The settings before it are in $copy." }
Step 'Start the services again'
# (the panel's root jobs and daemon again, also when the new installer left them as they were)
if (Get-Service -Name 'SentoraZsudo' -ErrorAction SilentlyContinue) { Start-Service -Name 'SentoraZsudo' -ErrorAction SilentlyContinue }
foreach ($pa in $phpAccounts) { if (Get-Service -Name $pa.Name -ErrorAction SilentlyContinue) { Start-Service -Name $pa.Name -ErrorAction SilentlyContinue } }
foreach ($tn in @('Sentora daemon', 'Sentora cron')) { if (Get-ScheduledTask -TaskName $tn -ErrorAction SilentlyContinue) { Enable-ScheduledTask -TaskName $tn | Out-Null } }
[IO.File]::WriteAllText($verFile, $new)
Remove-Item $note -ErrorAction SilentlyContinue
Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
$when = Get-Date -Format 'yyyy-MM-dd HH:mm'
Put 'installed' "$($zipAsset.digest) $new, $when`n"
Put 'available' "$(UnixNow) current`n"
St "end`tok`t$((UnixNow) - $script:stepAt)"
St "done`tok`tUpdated to Sentora-Windows $new.`t$new, $when"
"Updated to Sentora-Windows $new."
} catch {
    # (what stopped it, for the page; the panel's root jobs and daemon are started again whatever happened)
    $why = Plain $_.Exception.Message
    if ($script:stepNo -gt 0) { St "end`tfail`t$((UnixNow) - $script:stepAt)" }
    St "fail`t$why"
    if (Get-Service -Name 'SentoraZsudo' -ErrorAction SilentlyContinue) { Start-Service -Name 'SentoraZsudo' -ErrorAction SilentlyContinue }
    foreach ($pa in $phpAccounts) { Start-Service -Name $pa.Name -ErrorAction SilentlyContinue }
    foreach ($tn in @('Sentora daemon', 'Sentora cron')) { if (Get-ScheduledTask -TaskName $tn -ErrorAction SilentlyContinue) { Enable-ScheduledTask -TaskName $tn | Out-Null } }
    throw
}
'@
    $updAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $Bin 'sentora-update.ps1') + '" -Check')
    $hourly = New-ScheduledTaskTrigger -Once -At (Get-Date).Date.AddMinutes(17) -RepetitionInterval (New-TimeSpan -Hours 1)
    $null = Register-ScheduledTask -TaskName 'Sentora update check' -Action $updAction -Trigger $hourly `
        -User 'SYSTEM' -RunLevel Highest -Description 'Tells when a newer Sentora-Windows release is out, every hour (it does not update by itself)' -Force
    # the panel's Update now (Panel Update, through SentoraZsudo): a task of its own, as SYSTEM, apart from the services
    # the update stops; not made again while it runs (an update runs this installer from it)
    $runArgs = '-NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $Bin 'sentora-update.ps1') + '" -FromPanel'
    $ut = Get-ScheduledTask -TaskName 'Sentora update' -ErrorAction SilentlyContinue
    if (-not $ut -or "$($ut.Actions[0].Arguments)" -ne $runArgs) {
        $null = Register-ScheduledTask -TaskName 'Sentora update' -Action (New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $runArgs) -User 'SYSTEM' -RunLevel Highest `
            -Settings (New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)) `
            -Description 'Updates Sentora-Windows when the panel''s Panel Update asks (it has no schedule)' -Force
    }
    Ok "$(Join-Path $Bin 'sentora-update.ps1') (-Check tells, no switch updates); the hourly check only tells (logs\update-available.txt, Panel Update); task 'Sentora update' for the panel's Update now"
    if (-not (Test-Path (Join-Path $updCfg 'github-token.txt'))) { Note "(while the repository is private, updates need a read-only GitHub token in $updCfg\github-token.txt)" }

    Write-Host ''
    Write-Host 'Done.' -ForegroundColor Green
    if ($clamSkipped) { Write-Host "  (Not yet: ClamAV's virus check: its list did not download. Allow database.clamav.net, then run the installer again.)" -ForegroundColor Yellow }
    if ($WithPanel) {
        Write-Host "  Sentora panel:   http://$($ServerName):$HttpPort/  (user zadmin, password in $(Join-Path $Cfg 'panel\zadmin-password.txt'))"
        Write-Host "                   on Windows: clients, websites with HTTPS, DNS, FTP, mail, cron, backups, MySQL and phpMyAdmin; root jobs: service SentoraZsudo; daemon: task 'Sentora daemon'"
    } else { Write-Host "  Web root:        $(Join-Path $Root 'panel')   (put your app's index.php here)" }
    Write-Host "  Sites:           $(Join-Path $Root 'hostdata')"
    Write-Host "  Logs:            $Logs  (apache\access.log, apache\error.log, php\php_errors.log, mysql\mysql_error.log)"
    Write-Host "  Apache settings: $conf  (your own sites: $vhosts)"
    Write-Host "  PHP settings:    $(Join-Path $PhpDir 'php.ini')"
    Write-Host "  MySQL:           127.0.0.1:3306, user root, password in $pwFile"
    Write-Host "  Services:        $ApacheService, $MySQLService$(if ($WithFtp) { ", $FtpService" })$(if ($WithMail) { ", $MailService" })$(if ($WithClamAV) { ", $ClamService, $FreshService" })$(if ($WithDns) { ", $DnsService" })  (restart one: Restart-Service $ApacheService)"
    if ($WithFtp) { Write-Host "  FTP:             FTPS on 21 (TLS required), SFTP on 2022; users: C:\zpanel\bin\ftpuser.ps1 add USER PASSWORD" }
    Write-Host "  Updates:         C:\zpanel\bin\sentora-update.ps1 -Check (tells), sentora-update.ps1 (updates, keeps everything)"
    if ($WithDns) { Write-Host "  DNS:             CoreDNS on port 53, zones in $(Join-Path $Cfg 'dns\zones'); C:\zpanel\bin\dnszone.ps1 add DOMAIN [IP]" }
    if ($WithMail) {
        Write-Host "  Mail:            $MailDomain on $mailHost (SMTP 25/587/465, IMAP 143/993, POP3 995); mailboxes: C:\zpanel\bin\mailuser.ps1 add USER@$MailDomain PASSWORD"
        Write-Host "  Webmail:         https://$mailHost/webmail"
        Write-Host "  Phones:          ActiveSync (Exchange account), server $mailHost, the full address as user name"
        Write-Host "  Certificate:     $(Join-Path $Cfg 'ssl')\server.crt and server.key (made here: replace them with a real one, then Restart-Service $ApacheService, $MailService)"
    }
} finally {
    Stop-Transcript | Out-Null
}
