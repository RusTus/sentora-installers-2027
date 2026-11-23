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
.PARAMETER NoFirewall
    Do not open Apache's port in Windows Firewall (MySQL is never opened: it listens on 127.0.0.1 only).
.PARAMETER Uninstall
    Stop and remove the two services and the firewall rule. Folders, databases and logs stay.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\install-zpanel-stack.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\install-zpanel-stack.ps1 -RelaxedSqlMode -TimeZone "America/New_York"
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
    [switch]$NoFirewall,
    [switch]$Uninstall
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # (Invoke-WebRequest is many times slower while it draws its bar)

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
    Expand-Archive -Path $zip -DestinationPath $stage -Force
    $from = if ($top) { Join-Path $stage $top } else { $stage }
    if (-not (Test-Path $from)) { Fail "$zip does not hold the folder $top." }
    if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
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
    foreach ($n in @($FirewallRule, $OldFirewall)) { Get-NetFirewallRule -DisplayName $n -ErrorAction SilentlyContinue | Remove-NetFirewallRule }
    Ok 'firewall rule removed'
    Note "The folders under $Root are still there; delete them yourself once you no longer need the databases."
    return
}

foreach ($d in @($Root, $Bin, $Cfg, (Join-Path $Cfg 'apache'), (Join-Path $Cfg 'mysql'), $Logs, (Join-Path $Logs 'apache'), (Join-Path $Logs 'mysql'),
                 (Join-Path $Logs 'php'), (Join-Path $Root 'panel'), (Join-Path $Root 'hostdata'), (Join-Path $Root 'data'), $Tmp, $Dl,
                 (Join-Path $Tmp 'uploads'), (Join-Path $Tmp 'sessions'))) {
    if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null }
}
Start-Transcript -Path (Join-Path $Logs ('install-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')) | Out-Null

try {
    Write-Host "Apache, PHP and MySQL for $Root  (Windows $([Environment]::OSVersion.Version), PowerShell $($PSVersionTable.PSVersion))"

    # (a port already taken by something else would keep Apache from starting)
    $busy = Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $p = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue; $p -and $p.ProcessName -ne 'httpd' }
    if ($busy) { Fail "Port $HttpPort is already used by $((Get-Process -Id $busy[0].OwningProcess).ProcessName) (process $($busy[0].OwningProcess)). Stop it (IIS: Stop-Service W3SVC) or use -HttpPort." }

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
    Write-Utf8NoBom (Join-Path $PhpDir 'php.ini') $ini
    $phpExe = Join-Path $PhpDir 'php.exe'
    $v = (Invoke-Native $phpExe @('-n', '-r', 'echo PHP_VERSION;')) -join ''
    $mods = (Invoke-Native $phpExe @('-c', (Join-Path $PhpDir 'php.ini'), '-m')) -join ' '
    $missing = $exts | Where-Object { $mods -notmatch ('(?i)\b' + [regex]::Escape($_) + '\b') }
    if ($missing) { Fail "PHP $v did not load: $($missing -join ', ')" }
    Ok "PHP $v with $($exts -join ', ')"

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
    Say 'Apache 2.4 settings (C:\zpanel\configs\apache\httpd.conf)'
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
<Directory "`${ZROOT}/hostdata">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

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

Include "`${ZROOT}/configs/apache/httpd-vhosts.conf"
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

    Write-Host ''
    Write-Host 'Done.' -ForegroundColor Green
    Write-Host "  Web root:        $(Join-Path $Root 'panel')   (put your app's index.php here)"
    Write-Host "  Sites:           $(Join-Path $Root 'hostdata')"
    Write-Host "  Logs:            $Logs  (apache\access.log, apache\error.log, php\php_errors.log, mysql\mysql_error.log)"
    Write-Host "  Apache settings: $conf  (your own sites: $vhosts)"
    Write-Host "  PHP settings:    $(Join-Path $PhpDir 'php.ini')"
    Write-Host "  MySQL:           127.0.0.1:3306, user root, password in $pwFile"
    Write-Host "  Services:        $ApacheService, $MySQLService  (restart one: Restart-Service $ApacheService)"
} finally {
    Stop-Transcript | Out-Null
}
