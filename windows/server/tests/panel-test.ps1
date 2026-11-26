# The Sentora panel on Windows (install-test.yml): log in as zadmin, the modules ready for Windows load and the others
# are kept out, "Run the daemon now" goes through zsudo (the service SentoraZsudo), and Apache runs as its own account,
# which can not read MySQL's password, change the panel's code or act as SYSTEM.
$ErrorActionPreference = 'Stop'
$Root = 'C:\zpanel'
function Ok($t) { Write-Host "ok   $t" }

$svc = Get-CimInstance Win32_Service -Filter "Name='Apache2' or Name='SentoraZsudo'"
$svc | Format-Table Name, State, StartName -AutoSize
if (($svc | Where-Object Name -eq 'Apache2').StartName -ne 'NT SERVICE\Apache2') { throw "Apache does not run as its own account: $(($svc | Where-Object Name -eq 'Apache2').StartName)" }
Ok 'Apache runs as NT SERVICE\Apache2 (not LocalSystem)'
if (($svc | Where-Object Name -eq 'SentoraZsudo').State -ne 'Running') { throw 'the service SentoraZsudo does not run' }
Ok 'SentoraZsudo runs (the root jobs, from a fixed list)'
if (-not (Get-ScheduledTask -TaskName 'Sentora daemon' -ErrorAction SilentlyContinue)) { throw "the task 'Sentora daemon' is not there" }
Ok "the task 'Sentora daemon' is there (every 5 minutes)"
$j = Get-Item C:\var\lib\sentora -Force
if ($j.LinkType -ne 'Junction' -or "$($j.Target)" -ne "$Root\data\sentora") { throw "the state folder's junction: $($j.LinkType) $($j.Target)" }
Ok 'state folder: C:\var\lib\sentora -> C:\zpanel\data\sentora'

# --- logging in
$pw = (Get-Content "$Root\configs\panel\zadmin-password.txt" -Raw).Trim()
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
function Page($url, $body) {
    if ($body) { return Invoke-WebRequest "http://127.0.0.1$url" -WebSession $s -Method Post -Body $body -UseBasicParsing -TimeoutSec 60 }
    return Invoke-WebRequest "http://127.0.0.1$url" -WebSession $s -UseBasicParsing -TimeoutSec 60
}
function Token($html) { if ($html -match 'name="csfr_token" value="([^"]+)"') { return $Matches[1] }; throw 'no form token on the page' }
$p = Page '/' $null
$p = Page '/' @{ inUsername = 'zadmin'; inPassword = 'wrong-password'; inSessionSecurity = 'on'; csfr_token = (Token $p.Content); sublogin2 = 'LogIn' }
if ($p.Content -match '\?module=packages') { throw 'a wrong password logged in' }
Ok 'a wrong password does not log in'
$p = Page '/' $null
$p = Page '/' @{ inUsername = 'zadmin'; inPassword = $pw; inSessionSecurity = 'on'; csfr_token = (Token $p.Content); sublogin2 = 'LogIn' }
if ($p.Content -notmatch '\?module=packages') { throw 'zadmin could not log in' }
Ok 'zadmin logs in'

# --- the modules: ready ones load, the others are kept out (page, menu, search list)
foreach ($m in @('packages', 'manage_clients', 'mysql_databases', 'mysql_users', 'my_account', 'sentoraconfig', 'faqs', 'client_notices', 'domains', 'sub_domains', 'parked_domains', 'sencrypt', 'dns_manager', 'dns_admin', 'ftp_management', 'mailboxes', 'cron', 'aliases', 'distlists', 'webmail', 'services', 'user_logviewer', 'phpmyadmin', 'backupmgr', 'protected_directories', 'panel_update', 'apache_admin', 'ftp_admin', 'moduleadmin', 'widget_admin', 'system_logviewer', 'server_monitor', 'site_health', 'forwarders', 'file_manager', 'backup_admin', 'mail_admin')) {
    $c = (Page "/?module=$m" $null).Content
    if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw "module $m does not load" }
}
Ok 'the modules ready for Windows load (packages, clients, MySQL, my account, settings, FAQs, notices, domains, subdomains, parked domains, Sencrypt, DNS Manager, DNS Config, FTP Accounts, Mailboxes, Cron Manager, Aliases, Distribution Lists, Webmail, Services, Domain Logs, phpMyAdmin, Backup Manager, Protected Directories, Panel Update, Apache Config, FTP Config, Module Admin, Widget Admin, System Log Viewer, Server Monitor, Site Health, Forwarders, File Manager, Backup Config)'
foreach ($m in @('php_versions')) {
    if ((Page "/?module=$m" $null).Content -notmatch 'Unable to find requested module') { throw "module $m is not kept out on Windows" }
}
$front = (Page '/' $null).Content
if ($front -match '\?module=(php_versions)\b') { throw "the page offers a module not ready for Windows: $($Matches[0])" }
Ok 'the modules not ready for Windows are kept out (their pages, the menu, the dashboard)'

# --- "Run the daemon now": the page asks zsudo, the service runs the daemon
$log = "$Root\logs\panel\daemon-panel-run.log"
Remove-Item $log -ErrorAction SilentlyContinue
$p = Page '/?module=sentoraconfig' $null
$p = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
if ($p.Content -notmatch 'The daemon is starting now') {
    [IO.File]::WriteAllText("$Root\logs\panel\test-rundaemon.html", $p.Content)
    $said = ([regex]::Matches($p.Content, '(?s)zannounce[a-z]*[^>]*>(.{0,400}?)</') | ForEach-Object { ($_.Groups[1].Value -replace '<[^>]+>', ' ').Trim() }) -join ' / '
    $direct = & cmd /c "`"$Root\panel\bin\zsudo.bat`" daemon-run 2>&1"
    $rc = $LASTEXITCODE
    # the same request from inside Apache (its account), with what each step sees
    $fw = $Root -replace '\\', '/'
    $why = "$Root\panel\ztest-$([guid]::NewGuid().ToString('N')).php"
    [IO.File]::WriteAllText($why, @"
<?php
foreach (array('C:/var', 'C:/var/lib', 'C:/var/lib/sentora', '/var/lib/sentora/zsudo/new', 'C:/var/lib/sentora/zsudo/queue', '$fw/data/sentora/zsudo/queue') as `$d) { echo `$d, '=', (is_dir(`$d) ? 'dir' : 'no'), ' '; }
`$z = '$fw/panel/bin/zsudo.bat';
echo 'exec=', (is_executable(`$z) ? 'yes' : 'no'), ' cwd=', getcwd(), ' ';
exec(escapeshellcmd(`$z) . ' daemon-run 2>&1', `$o, `$r);
echo 'rc=', `$r, ' out=', implode(' | ', `$o);
"@)
    try { $inside = (Invoke-WebRequest "http://127.0.0.1/$(Split-Path $why -Leaf)" -UseBasicParsing -TimeoutSec 30).Content } catch { $inside = "$_" } finally { Remove-Item $why -Force }
    throw "the page did not start the daemon (HTTP $($p.StatusCode), $($p.Content.Length) bytes; it said: '$said'). zsudo run here as Administrator: $direct (exit $rc). Inside Apache: $inside. The page is in logs\panel\test-rundaemon.html"
}
$done = $false
for ($i = 0; $i -lt 60 -and -not $done; $i++) { Start-Sleep 2; $done = (Test-Path $log) -and ((Get-Content $log -Raw) -match 'Daemon run complete') }
Get-Content "$Root\logs\panel\zsudo.log" -ErrorAction SilentlyContinue | Select-Object -Last 4
if (-not $done) { throw "the daemon did not run through zsudo: $(Get-Content $log -Raw -ErrorAction SilentlyContinue)" }
Ok '"Run the daemon now": asked by the page, run by SentoraZsudo, finished'
# a request that is not on the list is refused
$r = & cmd /c "`"$Root\panel\bin\zsudo.bat`" service sshd restart 2>&1"
if ($LASTEXITCODE -eq 0) { throw "zsudo took a request that is not on its list: $r" }
Ok "zsudo refuses what is not on its list ($r)"

# --- what Apache's account can not do (a one-off page, removed after)
$probe = "$Root\panel\ztest-$([guid]::NewGuid().ToString('N')).php"
[IO.File]::WriteAllText($probe, @'
<?php
$r = array();
$r[] = 'mysqlpw:' . (@file_get_contents('C:/zpanel/configs/mysql/root-password.txt') === false ? 'no' : 'YES');
$r[] = 'writecode:' . (@file_put_contents('C:/zpanel/panel/index.php', 'x', FILE_APPEND) === false ? 'no' : 'YES');
$r[] = 'writephpini:' . (@file_put_contents('C:/zpanel/bin/php/php.ini', "\n", FILE_APPEND) === false ? 'no' : 'YES');
$r[] = 'zadminpw:' . (@file_get_contents('C:/zpanel/configs/panel/zadmin-password.txt') === false ? 'no' : 'YES');
$r[] = 'queue:' . (is_writable('C:/var/lib/sentora/zsudo/queue') ? 'yes' : 'no');
$r[] = 'rootjobs:' . (@file_put_contents('C:/zpanel/data/sentora/accounts/x', 'x') === false ? 'no' : 'YES');
$r[] = 'phplist:' . (@file_put_contents('C:/zpanel/data/sentora/phpaccounts/wanted.txt', "x\n", FILE_APPEND) === false ? 'no' : 'YES');
$r[] = 'whoami:' . trim((string) @shell_exec('whoami'));
echo implode('|', $r);
'@)
try { $c = (Invoke-WebRequest "http://127.0.0.1/$(Split-Path $probe -Leaf)" -UseBasicParsing -TimeoutSec 30).Content } finally { Remove-Item $probe -Force }
"   Apache's account: $c"
foreach ($want in @('mysqlpw:no', 'writecode:no', 'writephpini:no', 'zadminpw:no', 'queue:yes', 'rootjobs:no', 'phplist:no')) {
    if ($c -notmatch [regex]::Escape($want)) { throw "Apache's account: wanted $want, got $c" }
}
if ($c -match 'whoami:nt authority\\system') { throw 'PHP in Apache runs as SYSTEM' }
Ok "Apache's account can not read MySQL's or zadmin's password, change the panel's code or php.ini, or write root's state (nor the list of PHP accounts); it can ask zsudo; it is not SYSTEM"

# --- a client made in the panel gets its own PHP (its Windows account); deleted, it goes (the files stay)
$cname = 'winphp' + (Get-Random -Minimum 1000 -Maximum 9999)
$p = Page '/?module=manage_clients&show=New' $null
$pkg = if ($p.Content -match '(?s)name="inNewPackage"[^>]*>.*?<option[^>]*value="(\d+)"') { $Matches[1] } else { throw 'no package to make a client with' }
$grp = if ($p.Content -match '(?s)name="inNewGroup"[^>]*>.*?<option[^>]*value="(\d+)"') { $Matches[1] } else { throw 'no group to make a client in' }
$p = Page '/?module=manage_clients&action=CreateClient' @{ csfr_token = (Token $p.Content); inNewUserName = $cname; inNewPackage = $pkg; inNewGroup = $grp; inNewFullName = 'Windows PHP test'; inNewEmailAddress = "$cname@example.test"; inNewPassword = 'Winphp-Test-1234' }
$p = Page '/?module=manage_clients' $null
if ($p.Content -notmatch $cname) { throw "the client $cname was not made" }
$ok = $false
for ($i = 0; $i -lt 60 -and -not $ok; $i++) { Start-Sleep 3; $ok = (Get-Service "SentoraPHP-$cname" -ErrorAction SilentlyContinue).Status -eq 'Running' }
if (-not $ok) { throw "the client $cname has no PHP of its own after 3 minutes (zsudo.log: $((Get-Content "$Root\logs\panel\zsudo.log" -Tail 6 -ErrorAction SilentlyContinue) -join ' / '))" }
if (-not (Test-Path "$Root\configs\apache\php-accounts\$cname.conf") -or -not (Test-Path "$Root\hostdata\$cname\public_html")) { throw "the client $cname's PHP is not complete" }
$acct = (Get-CimInstance Win32_Service -Filter "Name='SentoraPHP-$cname'").StartName
if ($acct -ne "NT SERVICE\SentoraPHP-$cname") { throw "its PHP runs as $acct" }
Ok "a client made in the panel ($cname) gets its own PHP: service SentoraPHP-$cname as $acct, its folder and Apache's part"
$id = if ($p.Content -match "show=Delete&amp;other=(\d+)`"[^>]*aria-label=`"[^`"]*$cname") { $Matches[1] } else { throw "no delete link for $cname" }
$p = Page "/?module=manage_clients&show=Delete&other=$id" $null
$p = Page '/?module=manage_clients&action=DeleteClient' @{ csfr_token = (Token $p.Content); inDelete = $id }
$gone = $false
for ($i = 0; $i -lt 60 -and -not $gone; $i++) { Start-Sleep 3; $gone = -not (Get-Service "SentoraPHP-$cname" -ErrorAction SilentlyContinue) -and -not (Test-Path "$Root\configs\apache\php-accounts\$cname.conf") }
if (-not $gone) { throw "the deleted client $cname still has its PHP after 3 minutes" }
if (Test-Path "$Root\configs\apache\php-accounts\$cname.conf") { throw "Apache still has the deleted client's part" }
if (-not (Test-Path "$Root\hostdata\$cname")) { throw "the deleted client's files went too" }
Ok "the client deleted in the panel: its PHP and Apache's part go, its files stay"
$z = (Get-Service 'SentoraPHP-zadmin' -ErrorAction SilentlyContinue).Status
if ($z -ne 'Running') { throw "zadmin has no PHP of its own ($z)" }
Ok 'zadmin has its own PHP too (every live account)'

# --- websites: a domain and a parked domain added in the panel are served by Apache; the domain's PHP runs as its
# account's own Windows account (sys_winvhosts writes them at the daemon run, SentoraZsudo restarts Apache gently)
$n = Get-Random -Minimum 1000 -Maximum 9999
$dom = "shop$n.example.net"; $park = "parked$n.org"
$p = Page '/?module=domains' $null
$p = Page '/?module=domains&action=CreateDomain' @{ csfr_token = (Token $p.Content); inDomain = $dom; inAutoHome = '1'; inDestination = '' }
$p = Page '/?module=parked_domains' $null
$p = Page '/?module=parked_domains&action=CreateParkedDomain' @{ csfr_token = (Token $p.Content); inDomain = $park }
$parkedSaid = ([regex]::Matches($p.Content, '(?s)class="alert[^"]*"[^>]*>(.*?)</div>') | ForEach-Object { ($_.Groups[1].Value -replace '<[^>]+>', ' ' -replace '&times;|\u00d7', '' -replace '\s+', ' ').Trim() }) -join ' / '
$p = Page '/?module=sentoraconfig' $null
$p = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
function Site($h, $path) { $ErrorActionPreference = 'Continue'; (& curl.exe -s --max-time 30 -H "Host: $h" "http://127.0.0.1$path" 2>&1) -join "`n" }
$ok = $false
for ($i = 0; $i -lt 60 -and -not $ok; $i++) { Start-Sleep 3; $ok = (Site $dom '/') -match 'Your Sentora account is active' }
if (-not $ok) { throw "the domain $dom is not served (sentora-vhosts.conf: $(Test-Path "$Root\configs\apache\sentora-vhosts.conf"); daemon log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -Tail 8 -ErrorAction SilentlyContinue) -join ' / '))" }
Ok "a domain added in the panel ($dom) is served: its folder and welcome page were made, Apache has its site"
if ((Site "www.$dom" '/') -notmatch 'Your Sentora account is active') { throw "www.$dom is not served" }
Ok "www.$dom too"
$folder = Get-ChildItem "$Root\hostdata\zadmin\public_html" -Directory | Where-Object Name -eq ($dom -replace '\.', '_')
Set-Content "$($folder.FullName)\who.php" '<?php echo trim((string) shell_exec("whoami"));' -Encoding ASCII
$w = Site $dom '/who.php'
if ($w -ne 'nt service\sentoraphp-zadmin') { throw "the domain's PHP runs as: $w" }
Ok "its PHP runs as the account's own Windows account ($w), not Apache's"
# (a daemon run that began before the parked domain was saved serves the domain first: the next run brings the parked one)
$pk = ''
for ($i = 0; $i -lt 60; $i++) {
    $pk = Site $park '/'; if ($pk -ne '' -and $pk -notmatch 'inUsername|Your Sentora account is active') { break }
    # (asked again now and then: a run that was already going when it was asked does not pick the parked domain up)
    if ($i -gt 0 -and $i % 10 -eq 0) { $q = Page '/?module=sentoraconfig' $null; $null = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $q.Content) } }
    Start-Sleep 3
}
if ($pk -match 'inUsername|Your Sentora account is active' -or $pk -eq '') {
    $inConf = Select-String -Path "$Root\configs\apache\sentora-vhosts.conf" -Pattern ([regex]::Escape($park)) -Context 0, 4 -ErrorAction SilentlyContinue | Out-String
    throw "the parked domain $park is not served (the page said: '$parkedSaid'; in sentora-vhosts.conf: $($inConf.Trim()); it answered: $(($pk -replace '\s+', ' ').Substring(0, [Math]::Min(160, $pk.Length))))"
}
Ok "a parked domain ($park) shows the parking page"
if ((Site "nothere$n.example.net" '/') -notmatch 'inUsername') { throw 'a name no site claims does not land on the panel' }
Ok 'a name no site claims still lands on the panel (httpd-vhosts.conf stays first)'

# --- HTTPS: a certificate made on the Sencrypt page gives the domain its HTTPS site (sys_winvhosts at the daemon run),
# plain HTTP sends visitors there except Let's Encrypt's check files; the key is where the account's own PHP can not read it
function Tls($h, $path) { $ErrorActionPreference = 'Continue'; (& curl.exe -sk --max-time 30 --resolve "${h}:443:127.0.0.1" "https://$h$path" 2>&1) -join "`n" }
if ((Tls "nothere$n.example.net" '/') -notmatch 'inUsername') { throw 'HTTPS: a name no site claims does not land on the panel' }
Ok 'HTTPS: the panel answers on 443 with the server certificate (names no site claims)'
$p = Page '/?module=sencrypt' $null
$p = Page '/?module=sencrypt&ShowPanel=third-party&action=Makenew' @{ csfr_token = (Token $p.Content); inDomain = $dom; inName = 'Test State'; inAddress = 'admin@example.test'; inCity = 'Test City'; inCountry = 'US'; inCompany = 'Test Company' }
$crt = "$Root\data\sentora\certs\zadmin\ssl\sencrypt\third_party\$dom\cert.pem"
$key = "$Root\data\sentora\certs\zadmin\ssl\sencrypt\third_party\$dom\private.pem"
if (-not (Test-Path $crt) -or -not (Test-Path $key)) {
    $said = ([regex]::Matches($p.Content, '(?s)class="alert[^"]*"[^>]*>(.{0,300}?)</') | ForEach-Object { ($_.Groups[1].Value -replace '<[^>]+>', ' ').Trim() }) -join ' / '
    throw "Sencrypt made no certificate for $dom (the page said: '$said'; certs: $((Get-ChildItem "$Root\data\sentora\certs" -Recurse -ErrorAction SilentlyContinue | ForEach-Object FullName) -join ', '))"
}
Ok "Sencrypt made a self-signed certificate for $dom (in the panel's certs folder, not the site's)"
$p = Page '/?module=sentoraconfig' $null
$p = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
$ok = $false
for ($i = 0; $i -lt 60 -and -not $ok; $i++) { Start-Sleep 3; $ok = (Tls $dom '/') -match 'Your Sentora account is active' }
if (-not $ok) { throw "HTTPS: $dom is not served (sentora-vhosts.conf: $((Select-String -Path "$Root\configs\apache\sentora-vhosts.conf" -Pattern 'HTTPS|SSLCert' | ForEach-Object Line) -join ' / '); daemon log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -Tail 8 -ErrorAction SilentlyContinue) -join ' / '))" }
# (Windows' curl uses Schannel, which does not show the certificate: .NET asks for it, with the name as SNI)
function CertSubject($h) {
    $c = New-Object Net.Sockets.TcpClient('127.0.0.1', 443)
    try {
        $ssl = New-Object Net.Security.SslStream($c.GetStream(), $false, ([Net.Security.RemoteCertificateValidationCallback] { $true }))
        $ssl.AuthenticateAsClient($h)
        return $ssl.RemoteCertificate.Subject
    } finally { $c.Close() }
}
$subj = CertSubject $dom
if ($subj -notmatch "CN=$([regex]::Escape($dom))(,|$)") { throw "HTTPS: $dom does not show its own certificate: $subj" }
Ok "HTTPS: $dom is served with its own certificate"
if ((Tls $dom '/who.php') -ne 'nt service\sentoraphp-zadmin') { throw "HTTPS: the domain's PHP runs as: $(Tls $dom '/who.php')" }
Ok 'HTTPS: its PHP is the account''s own, as on HTTP'
$ErrorActionPreference = 'Continue'
$r = (& curl.exe -s -o NUL --max-time 30 -w '%{http_code} %{redirect_url}' -H "Host: $dom" 'http://127.0.0.1/page?x=1' 2>&1) -join ''
$ErrorActionPreference = 'Stop'
if ($r -ne "301 https://$dom/page?x=1") { throw "HTTP does not send visitors to HTTPS: $r" }
Ok "plain HTTP sends visitors to https://$dom/ (301)"
$acme = "$Root\data\sentora\acme\.well-known\acme-challenge"
Set-Content "$acme\test-token-$n" "token-$n" -Encoding ASCII
Set-Content "$acme\test-$n.php" '<?php echo 40 + 2;' -Encoding ASCII
if ((Site $dom "/.well-known/acme-challenge/test-token-$n").Trim() -ne "token-$n") { throw "Let's Encrypt's check file is not served on plain HTTP: $(Site $dom "/.well-known/acme-challenge/test-token-$n")" }
if ((Site $dom "/.well-known/acme-challenge/test-$n.php") -match '^\s*42\s*$') { throw 'a PHP file in the check folder was run' }
Remove-Item "$acme\test-token-$n", "$acme\test-$n.php" -Force
Ok "Let's Encrypt's check files are served on plain HTTP for every site (as files only, never run)"
Set-Content "$($folder.FullName)\key.php" ('<?php echo trim((string) shell_exec(''type "' + $key + '" 2>&1''));') -Encoding ASCII
$k = Tls $dom '/key.php'
Remove-Item "$($folder.FullName)\key.php" -Force
if ($k -match 'PRIVATE KEY') { throw "the account's own PHP can read the site's private key" }
Ok "the account's own PHP can not read the site's private key ($(($k -split "`n")[0]))"
# deleting the certificate on the Sencrypt page: its files go, and the domain is back on plain HTTP at the next daemon run
$p = Page '/?module=sencrypt' $null
$p = Page '/?module=sencrypt&action=TPDelete' @{ csfr_token = (Token $p.Content); inName = $dom }
if (Test-Path $key) { throw "deleting the certificate on the Sencrypt page left its key ($key)" }
$p = Page '/?module=sentoraconfig' $null
$p = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
$ok = $false
for ($i = 0; $i -lt 60 -and -not $ok; $i++) { Start-Sleep 3; $ok = (Site $dom '/') -match 'Your Sentora account is active' }
if (-not $ok) { throw "after deleting its certificate, $dom is not back on plain HTTP: $(Site $dom '/')" }
if ((CertSubject $dom) -match "CN=$([regex]::Escape($dom))(,|$)") { throw "after deleting its certificate, $dom still shows it" }
Ok "a certificate deleted on the Sencrypt page: its files go, $dom is back on plain HTTP"

# --- DNS: the default records made on the DNS Manager page are answered by CoreDNS (the daemon writes db.DOMAIN, marked as
# the panel's; CoreDNS reads it by itself); a zone the administrator made with dnszone.ps1 is never touched
$zones = "$Root\configs\dns\zones"
$adminZones = @{}
Get-ChildItem $zones -Filter 'db.*' | ForEach-Object { $adminZones[$_.Name] = (Get-FileHash $_.FullName).Hash }
$my = (Get-ChildItem "$Root\bin" -Recurse -Filter mysql.exe | Select-Object -First 1).FullName
$env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
$vid = (& $my --protocol=TCP -h 127.0.0.1 -u root -N -e "SELECT vh_id_pk FROM sentora_core.x_vhosts WHERE vh_name_vc='$dom' AND vh_deleted_ts IS NULL") -join ''
$sip = (& $my --protocol=TCP -h 127.0.0.1 -u root -N -e "SELECT so_value_tx FROM sentora_core.x_settings WHERE so_name_vc='server_ip'") -join ''
Remove-Item Env:\MYSQL_PWD
if ($vid -notmatch '^\d+$') { throw "no domain id for $dom ($vid)" }
$p = Page '/?module=dns_manager' $null
$p = Page '/?module=dns_manager&action=CreateDefaultRecords' @{ csfr_token = (Token $p.Content); inDomain = $vid; inUserID = '1' }
$p = Page '/?module=sentoraconfig' $null
$p = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
$a = $null
for ($i = 0; $i -lt 40 -and -not $a; $i++) {
    Start-Sleep 3
    try { $a = @(Resolve-DnsName $dom -Server 127.0.0.1 -Type A -DnsOnly -ErrorAction Stop | Where-Object Type -eq 'A') } catch { $a = $null }
}
if (-not $a) { throw "CoreDNS does not answer for $dom (zone file: $(Test-Path "$zones\db.$dom"); daemon log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -Tail 6 -ErrorAction SilentlyContinue) -join ' / '))" }
if ($sip -and $a[0].IPAddress -ne $sip) { throw "$dom answers $($a[0].IPAddress), not the server's address $sip" }
Ok "DNS: the default records made on the DNS Manager page are answered by CoreDNS ($dom A $($a[0].IPAddress))"
$mx = @(Resolve-DnsName $dom -Server 127.0.0.1 -Type MX -DnsOnly -ErrorAction SilentlyContinue | Where-Object Type -eq 'MX')
if (-not $mx) { throw "DNS: $dom has no MX record" }
Ok "DNS: and its MX ($($mx[0].NameExchange))"
if ((Get-Content "$zones\db.$dom" -TotalCount 1) -notmatch '^; Made by the Sentora panel') { throw "the zone file of $dom is not marked as the panel's" }
foreach ($k in $adminZones.Keys) {
    if (-not (Test-Path "$zones\$k") -or (Get-FileHash "$zones\$k").Hash -ne $adminZones[$k]) { throw "the panel changed or removed a zone it did not make: $k" }
}
Ok "DNS: the zones made with dnszone.ps1 are left as they are ($($adminZones.Keys -join ', '))"
$before = (Get-Process coredns -ErrorAction Stop).StartTime
$p = Page '/?module=dns_admin' $null
$p = Page '/?module=dns_admin&action=UpdateService' @{ csfr_token = (Token $p.Content); inReloadService = '1' }
# (it is restarting: a moment before it runs again)
$after = $null
for ($i = 0; $i -lt 20 -and -not ($after -and $after -gt $before); $i++) { $after = (Get-Process coredns -ErrorAction SilentlyContinue).StartTime; if (-not ($after -and $after -gt $before)) { Start-Sleep 1 } }
if (-not $after -or $after -le $before) { throw "DNS Config's reload button did not restart CoreDNS (before $before, after $after)" }
$back = $null
for ($i = 0; $i -lt 30 -and -not $back; $i++) { $back = Resolve-DnsName $dom -Server 127.0.0.1 -Type A -DnsOnly -ErrorAction SilentlyContinue; if (-not $back) { Start-Sleep 1 } }
if (-not $back) { throw "after the restart CoreDNS does not answer for $dom" }
Ok 'DNS Config: its reload button restarts CoreDNS through SentoraZsudo, and the zones are answered again'

# --- FTP: an account made on the FTP Accounts page becomes an SFTPGo user at the daemon run (sys_winftp, through SFTPGo's
# API as SYSTEM), logs in over FTPS with its password and lands in its own folder; deleted on the page, it goes
if (Get-Service SFTPGo -ErrorAction SilentlyContinue) {
    $fu = "ftp$n"; $fpw = "Ftp-Test-$n-Aa9"; $fname = "zadmin_$fu"
    $p = Page '/?module=ftp_management' $null
    $p = Page '/?module=ftp_management&action=CreateFTP' @{ csfr_token = (Token $p.Content); inFTPUsername = $fu; inPassword = $fpw; inAccess = 'RW'; inAutoHome = '1'; inDestination = ''; inDomainDestination = '' }
    $p = Page '/?module=sentoraconfig' $null
    $p = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
    function FtpTry($user, $pass, $extra) { $ErrorActionPreference = 'Continue'; $o = (& curl.exe -s -S --max-time 30 --ssl-reqd -k -u "${user}:$pass" @extra 2>&1) -join "`n"; return @($LASTEXITCODE, $o) }
    Set-Content "$env:TEMP\ftp-up-$n.txt" "uploaded-$n" -Encoding ASCII
    $r = $null
    for ($i = 0; $i -lt 40; $i++) { Start-Sleep 3; $r = FtpTry $fname $fpw @('-T', "$env:TEMP\ftp-up-$n.txt", 'ftp://127.0.0.1/'); if ($r[0] -eq 0) { break } }
    if ($r[0] -ne 0) { throw "FTP: $fname can not log in and upload (curl $($r[0]): $($r[1]); daemon log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String 'FTP' | Select-Object -Last 4) -join ' / '))" }
    $landed = Get-ChildItem "$Root\hostdata\zadmin" -Recurse -Filter "ftp-up-$n.txt" -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $landed -or $landed.DirectoryName -notlike "$Root\hostdata\zadmin\*") { throw "FTP: the upload did not land in the account's folder ($($landed.FullName))" }
    Ok "FTP: an account made on the page logs in over FTPS (TLS required) and its upload lands in $($landed.DirectoryName)"
    $r = FtpTry $fname 'Wrong-Pass-123' @('ftp://127.0.0.1/')
    if ($r[0] -eq 0) { throw 'FTP: a wrong password logged in' }
    $ErrorActionPreference = 'Continue'; $plain = (& curl.exe -s --max-time 15 -u "${fname}:$fpw" 'ftp://127.0.0.1/' 2>&1) -join ' '; $plainRc = $LASTEXITCODE; $ErrorActionPreference = 'Stop'
    if ($plainRc -eq 0) { throw 'FTP: a login without TLS was let in' }
    Ok 'FTP: a wrong password and a login without TLS are refused'
    $env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
    $fid = (& $my --protocol=TCP -h 127.0.0.1 -u root -N -e "SELECT ft_id_pk FROM sentora_core.x_ftpaccounts WHERE ft_user_vc='$fname' AND ft_deleted_ts IS NULL") -join ''
    Remove-Item Env:\MYSQL_PWD
    $p = Page '/?module=ftp_management' $null
    $p = Page '/?module=ftp_management&action=DeleteFTP' @{ csfr_token = (Token $p.Content); inDelete = $fid }
    $p = Page '/?module=sentoraconfig' $null
    $p = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
    for ($i = 0; $i -lt 40; $i++) { Start-Sleep 3; $r = FtpTry $fname $fpw @('ftp://127.0.0.1/'); if ($r[0] -ne 0) { break } }
    if ($r[0] -eq 0) { throw "FTP: $fname still logs in after it was deleted on the page" }
    if (-not (Test-Path $landed.FullName)) { throw 'FTP: deleting the account removed its files' }
    Ok 'FTP: deleted on the page, it can no longer log in; its files stay'
}

# --- Webmail opens Roundcube at /webmail (over HTTPS); the domain's logs are there to read
$p = Page '/?module=webmail' $null
if ($p.Content -notmatch 'href="/webmail/"') { throw 'the Webmail page does not open /webmail/' }
Ok 'Webmail: the page opens Roundcube at /webmail'
$p = Page '/?module=user_logviewer' $null
if ($p.Content -notmatch [regex]::Escape($dom)) { throw "the Domain Logs page does not list $dom" }
Ok "Domain Logs: $dom is listed"
$pm = Page '/etc/apps/phpmyadmin/' $null
if ($pm.Content -notmatch 'pma_username') { throw "phpMyAdmin does not show its login: $(($pm.Content -replace '<[^>]+>', ' ' -replace '\s+', ' ').Substring(0, [Math]::Min(300, $pm.Content.Length)))" }
$ErrorActionPreference = 'Continue'; $setup = (& curl.exe -s -o NUL -w '%{http_code}' 'http://127.0.0.1/etc/apps/phpmyadmin/setup/' 2>&1) -join ''; $ErrorActionPreference = 'Stop'
if ($setup -eq '200') { throw "phpMyAdmin's setup folder is reachable" }
Ok "phpMyAdmin: its login page shows (cookie login), its setup folder is gone ($setup)"

# --- Backup Manager: "Back up now" makes a zip of the account's site in its backups folder (the worker as SYSTEM, through
# SentoraZsudo); the page downloads and deletes it
$bdir = "$Root\hostdata\zadmin\backups"
$before = @(Get-ChildItem $bdir -Filter '*.zip' -ErrorAction SilentlyContinue).Count
$p = Page '/?module=backupmgr' $null
$p = Page '/?module=backupmgr&action=BackupNow' @{ csfr_token = (Token $p.Content) }
$zip = $null
for ($i = 0; $i -lt 60 -and -not $zip; $i++) { Start-Sleep 3; $zip = Get-ChildItem $bdir -Filter '*.zip' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime | Select-Object -Last 1; if ($zip -and @(Get-ChildItem $bdir -Filter '*.zip').Count -le $before) { $zip = $null } }
if (-not $zip) { throw "Backup Manager made no backup in $bdir (zsudo log: $((Get-Content "$Root\logs\panel\zsudo.log" -Tail 4 -ErrorAction SilentlyContinue) -join ' / '))" }
Start-Sleep 3
Add-Type -AssemblyName System.IO.Compression.FileSystem
$entries = [IO.Compression.ZipFile]::OpenRead($zip.FullName); $names = @($entries.Entries | ForEach-Object FullName); $entries.Dispose()
if (-not ($names -match "^public_html/$([regex]::Escape($folder.Name))/who\.php$")) { throw "the backup does not hold the site's files: $($names[0..5] -join ', ')" }
Ok "Backup Manager: Back up now made $($zip.Name) with the site's files ($($names.Count) entries)"
$ErrorActionPreference = 'Continue'
$dl = (& curl.exe -s -o NUL -w '%{http_code} %{size_download}' -b ("PHPSESSID=" + ($s.Cookies.GetCookies('http://127.0.0.1/') | Where-Object Name -eq 'PHPSESSID').Value) "http://127.0.0.1/modules/backupmgr/code/downloadbackup.php?id=1&file=$($zip.Name)" 2>&1) -join ''
$ErrorActionPreference = 'Stop'
if ($dl -notmatch '^200 [1-9]') {
    $why = @(Get-ChildItem "$Root\logs\apache\error.log", "$Root\logs\apache\panel-error.log", "$Root\logs\php\*.log" -ErrorAction SilentlyContinue | ForEach-Object { Get-Content $_.FullName -Tail 4 }) -join ' / '
    throw "the backup does not download from the page: $dl (logs: $why)"
}
Ok "Backup Manager: it downloads from the page ($dl)"
$bn = [IO.Path]::GetFileNameWithoutExtension($zip.Name)
$p = Page '/?module=backupmgr' $null
$p = Page '/?module=backupmgr&action=DeleteBackup' @{ csfr_token = (Token $p.Content); "inDelete_$bn" = '1'; "inDelete_$($zip.Name)" = '1' }
if (Test-Path $zip.FullName) { throw 'Backup Manager could not delete the backup' }
Ok 'Backup Manager: it deletes from the page'

# --- Cron: a task made in Cron Manager runs at its minute (the task 'Sentora cron', bin\sentora-cron.php) as the account's
# own Windows account through its own PHP, never as SYSTEM; its output is the log Cron Manager shows
if (-not (Get-ScheduledTask -TaskName 'Sentora cron' -ErrorAction SilentlyContinue)) { throw "the task 'Sentora cron' is not there" }
Set-Content "$($folder.FullName)\cronjob.php" '<?php echo "cron ran as ", trim((string) shell_exec("whoami")), "\n";' -Encoding ASCII
$p = Page '/?module=cron' $null
$timing = if ($p.Content -match 'value="\* \* \* \* \*"') { '* * * * *' } else { '*/5 * * * *' }
$p = Page '/?module=cron&action=CreateCron' @{ csfr_token = (Token $p.Content); inScript = "$($folder.Name)/cronjob.php"; inTiming = $timing; inDescription = 'Windows test' }
$env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
$cid = (& $my --protocol=TCP -h 127.0.0.1 -u root -N -e "SELECT ct_id_pk FROM sentora_core.x_cronjobs WHERE ct_fullpath_vc LIKE '%/cronjob.php' AND ct_deleted_ts IS NULL ORDER BY ct_id_pk DESC LIMIT 1") -join ''
Remove-Item Env:\MYSQL_PWD
if ($cid -notmatch '^\d+$') {
    $said = ([regex]::Matches($p.Content, '(?s)class="[^"]*(alert|msg)[^"]*"[^>]*>(.{0,300}?)</') | ForEach-Object { ($_.Groups[2].Value -replace '<[^>]+>', ' ').Trim() }) -join ' / '
    throw "Cron Manager did not save the task ($said)"
}
$log = "$Root\hostdata\zadmin\tmp\cron-$cid.log"
$wait = if ($timing -eq '* * * * *') { 150 } else { 420 }
for ($i = 0; $i -lt $wait / 5 -and -not ((Test-Path $log) -and ((Get-Content $log -Raw) -match 'Finished')); $i++) { Start-Sleep 5 }
if (-not (Test-Path $log)) { throw "the cron task $cid did not run within $wait seconds (task 'Sentora cron': $((Get-ScheduledTaskInfo -TaskName 'Sentora cron').LastTaskResult))" }
$c = Get-Content $log -Raw
if ($c -notmatch 'cron ran as nt service\\sentoraphp-zadmin') { throw "the cron task did not run as the account's own PHP: $c" }
Ok "Cron: a task made in Cron Manager ran at its minute as nt service\sentoraphp-zadmin, its log in tmp\cron-$cid.log"
$p = Page '/?module=cron' $null
if ($p.Content -notmatch 'cron ran as|Finished|ran') { Write-Host '   (note: the Cron Manager page does not show the run yet)' }
$p = Page '/?module=cron&action=DeleteCron' @{ csfr_token = (Token $p.Content); inJob = $cid }
Remove-Item "$($folder.FullName)\cronjob.php" -Force

# --- Protected Directories: a folder protected on the page asks for a login (the daemon writes its .htaccess block and
# password file on Windows); its user gets in with the password; unprotected, it is open again
$p = Page '/?module=protected_directories' $null
$p = Page '/?module=protected_directories&action=CreateProtection' @{ csfr_token = (Token $p.Content); inPath = $folder.Name; inMessage = 'Windows test' }
$env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
$pid2 = (& $my --protocol=TCP -h 127.0.0.1 -u root -N -e "SELECT x_htpasswd_file_id FROM sentora_core.x_htpasswd_file WHERE x_htpasswd_file_target LIKE '%/$($folder.Name)' AND x_htpasswd_file_deleted IS NULL ORDER BY x_htpasswd_file_id DESC LIMIT 1") -join ''
Remove-Item Env:\MYSQL_PWD
if ($pid2 -notmatch '^\d+$') {
    $said = ([regex]::Matches($p.Content, '(?s)zannounce[a-z]*[^>]*>(.{0,300}?)</') | ForEach-Object { ($_.Groups[1].Value -replace '<[^>]+>', ' ').Trim() }) -join ' / '
    throw "Protected Directories did not save the protection of $($folder.Name) (the page said: '$said')"
}
$p = Page "/?module=protected_directories&control=Edit&id=$pid2" $null
$p = Page "/?module=protected_directories&control=Edit&id=$pid2&action=AddUser" @{ csfr_token = (Token $p.Content); inId = $pid2; inUser = 'guest'; inPassword = "Guest-Pass-$n-Aa9" }
$p = Page '/?module=sentoraconfig' $null
$null = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
function AuthCode($cred) { $ErrorActionPreference = 'Continue'; $a = @('-s', '-o', 'NUL', '-w', '%{http_code}', '--max-time', '20', '-H', "Host: $dom"); if ($cred) { $a += @('-u', $cred) }; (& curl.exe @a 'http://127.0.0.1/' 2>&1) -join '' }
$c = ''
for ($i = 0; $i -lt 40 -and $c -ne '401'; $i++) { Start-Sleep 3; $c = AuthCode $null }
if ($c -ne '401') { throw "Protected Directories: $dom does not ask for a login ($c; .htaccess: $(Get-Content "$($folder.FullName)\.htaccess" -Raw -ErrorAction SilentlyContinue))" }
if ((AuthCode "guest:Wrong-Pass-1") -ne '401') { throw 'Protected Directories: a wrong password got in' }
$c = ''
for ($i = 0; $i -lt 20 -and $c -ne '200'; $i++) { $c = AuthCode "guest:Guest-Pass-$n-Aa9"; if ($c -ne '200') { Start-Sleep 3 } }
if ($c -ne '200') { throw "Protected Directories: its user does not get in ($c)" }
Ok "Protected Directories: $dom asks for a login, a wrong password is refused, its user gets in"
$p = Page '/?module=protected_directories' $null
$p = Page '/?module=protected_directories&action=DeleteProtection' @{ csfr_token = (Token $p.Content); inId = $pid2 }
$p = Page '/?module=sentoraconfig' $null
$null = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
$c = ''
for ($i = 0; $i -lt 40 -and $c -ne '200'; $i++) { Start-Sleep 3; $c = AuthCode $null }
if ($c -ne '200') { throw "Protected Directories: unprotected, $dom still answers $c" }
Ok 'Protected Directories: unprotected on the page, the folder is open again'

# --- "Site not found falls back to panel login" (panel_fallback) switched off: names no site claims get Not Found, the
# server's own addresses still open the panel; on again: they open the panel
function SetFallback($v) {
    $env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
    & $my --protocol=TCP -h 127.0.0.1 -u root -e "UPDATE sentora_core.x_settings SET so_value_tx='$v' WHERE so_name_vc='panel_fallback'; UPDATE sentora_core.x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed';" | Out-Null
    Remove-Item Env:\MYSQL_PWD
    $p = Page '/?module=sentoraconfig' $null
    $null = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
}
function Code($h) { $ErrorActionPreference = 'Continue'; (& curl.exe -s -o NUL -w '%{http_code}' --max-time 20 -H "Host: $h" 'http://127.0.0.1/' 2>&1) -join '' }
SetFallback 'false'
$c = ''
for ($i = 0; $i -lt 40 -and $c -ne '404'; $i++) { Start-Sleep 3; $c = Code "nothere$n.example.net" }
if ($c -ne '404') { throw "panel_fallback off: a name no site claims still gets $c (sentora-fallback.conf: $(Get-Content "$Root\configs\apache\sentora-fallback.conf" -Raw -ErrorAction SilentlyContinue))" }
if ((Site '127.0.0.1' '/') -notmatch 'inUsername') { throw 'panel_fallback off: the server''s own address no longer opens the panel' }
if ((Site $dom '/') -notmatch 'Your Sentora account is active') { throw "panel_fallback off: $dom is no longer served" }
Ok 'Site not found falls back to panel login, off: unknown names get 404, 127.0.0.1 still opens the panel, the sites are served'
SetFallback 'true'
$c = ''
for ($i = 0; $i -lt 40 -and $c -ne '200'; $i++) { Start-Sleep 3; $c = Code "nothere$n.example.net" }
if ((Site "nothere$n.example.net" '/') -notmatch 'inUsername') { throw 'panel_fallback on again: a name no site claims does not open the panel' }
Ok 'on again: names no site claims open the panel'

$late = @()
# --- File Manager: run by the account's own PHP at /sentora-fm/NAME/ (the panel sends a signed one-minute pass); it lists
# the account's folder and serves its files; without the pass, with a forged or a used one it refuses; the panel's own
# PHP does not run it on Windows
try {
$fmFile = Get-ChildItem $folder.FullName -File | Select-Object -First 1
$r = Page '/modules/file_manager/code/open.php' $null
if ($r.Content -notmatch 'public_html' -or $r.BaseResponse.ResponseUri.AbsolutePath -notmatch '^/sentora-fm/zadmin/') { throw "the File Manager did not open (ended at $($r.BaseResponse.ResponseUri), $($r.Content.Length) bytes: $(($r.Content -replace '<[^>]+>', ' ' -replace '\s+', ' ').Substring(0, [Math]::Min(300, ($r.Content -replace '<[^>]+>', ' ' -replace '\s+', ' ').Length))))" }
$raw = Page "/sentora-fm/zadmin/raw.php?f=$([uri]::EscapeDataString("public_html/$($folder.Name)/$($fmFile.Name)"))" $null
if ($raw.RawContentLength -ne $fmFile.Length) { throw "the File Manager did not serve $($fmFile.Name) ($($raw.RawContentLength) of $($fmFile.Length) bytes)" }
Ok "File Manager: opened from the panel as the account's own PHP (/sentora-fm/zadmin/), lists its folder and serves its files"
function FmCode($url) { $ErrorActionPreference = 'Continue'; (& curl.exe -s -o NUL -w '%{http_code}' --max-time 20 "http://127.0.0.1$url" 2>&1) -join '' }
$c1 = FmCode '/sentora-fm/zadmin/index.php'
$c2 = FmCode '/sentora-fm/zadmin/index.php?t=eyJ1IjoiemFkbWluIiwiZXhwIjo5OTk5OTk5OTk5LCJuIjoiMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAifQ.0000000000000000000000000000000000000000000000000000000000000000'
$c3 = FmCode '/modules/file_manager/app/index.php'
if ($c1 -ne '403' -or $c2 -ne '403' -or $c3 -ne '403') { throw "the File Manager let in a stranger: without a pass $c1, a forged pass $c2, the panel's own copy $c3 (403 each expected)" }
# a pass used once does not work again
$hop = Invoke-WebRequest 'http://127.0.0.1/modules/file_manager/code/open.php' -WebSession $s -MaximumRedirection 0 -UseBasicParsing -ErrorAction SilentlyContinue
$pass = "$($hop.Headers.Location)"
$first = FmCode $pass; $again = FmCode $pass
if ($first -ne '302' -or $again -ne '403') { throw "a File Manager pass: first use $first (302 expected), second use $again (403 expected)" }
Ok 'File Manager: refuses without the pass, with a forged one, a pass used twice, and the panel''s own copy (403 each)'
} catch { $late += "$_" }

# --- Module Admin: every module listed, the ones not ready for Windows marked, no add-on upload (add-ons are Linux's)
$c = (Page '/?module=moduleadmin' $null).Content
if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw 'the Module Admin page does not load' }
if ($c -notmatch 'not on Windows yet' -or $c -match 'name="modulefile"') { throw 'Module Admin does not mark the modules not ready for Windows, or offers the add-on upload' }
Ok 'Module Admin: the modules not ready for Windows are marked; no add-on upload on Windows'

# --- Widget Admin: a widget uploaded on the page is checked and installed by the daemon (SYSTEM) into the panel
$wd = Join-Path $env:TEMP "widget$n"
New-Item -ItemType Directory -Force -Path $wd | Out-Null
[IO.File]::WriteAllText("$wd\widget.xml", "<?xml version=`"1.0`" encoding=`"UTF-8`"?>`n<widget><id>wintest$n</id><name>Windows test</name><description>A test widget.</description><version>1.0</version><author>test</author><default>false</default><groups>administrators</groups></widget>`n")
[IO.File]::WriteAllText("$wd\widget.php", "<?php`nclass dashboard_widget_wintest$n`n{`n    static function Render(`$user)`n    {`n        return '<p>Windows widget</p>';`n    }`n}`n")
Compress-Archive -Path "$wd\widget.xml", "$wd\widget.php" -DestinationPath "$wd.zip" -Force
$p = Page '/?module=widget_admin' $null
if ($p.Content -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw 'the Widget Admin page does not load' }
$ck = ($s.Cookies.GetCookies('http://127.0.0.1') | ForEach-Object { "$($_.Name)=$($_.Value)" }) -join '; '
$up = & curl.exe -s --max-time 60 -H "Cookie: $ck" -F "csfr_token=$(Token $p.Content)" -F "inWidgetFile=@$wd.zip;type=application/zip" 'http://127.0.0.1/?module=widget_admin&action=UploadWidget' 2>&1 | Out-String
if ($up -notmatch 'is queued') { throw "Widget Admin: the upload was not queued: $(([regex]::Matches($up, '(?s)zannounce[a-z]*[^>]*>(.{0,300}?)</') | ForEach-Object { $_.Groups[1].Value }) -join ' / ')" }
$p = Page '/?module=sentoraconfig' $null
$null = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
$wf = "$Root\panel\etc\dashboard-widgets\wintest$n\widget.php"
for ($i = 0; $i -lt 110 -and -not (Test-Path $wf); $i++) { Start-Sleep 3 }
if (-not (Test-Path $wf)) {
    $dl = (Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String -Pattern 'Widget|widget_admin|Fatal|Warning' | Select-Object -Last 8) -join ' | '
    throw "Widget Admin: the widget was not installed. Results: $(Get-Content C:\var\lib\sentora\widget-results.json -Raw -ErrorAction SilentlyContinue) Queue: $((Get-ChildItem C:\var\lib\sentora\tmp -Filter 'widget-*' -ErrorAction SilentlyContinue).Name -join ', ') Daemon: $dl"
}
Ok 'Widget Admin: a widget uploaded on the page was checked and installed by the daemon'
# (the upload went with a copy of the session's cookie, and the panel renews the session on such a request: log in again)
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$p = Page '/' $null
$p = Page '/' @{ inUsername = 'zadmin'; inPassword = $pw; inSessionSecurity = 'on'; csfr_token = (Token $p.Content); sublogin2 = 'LogIn' }
if ($p.Content -notmatch '\?module=packages') { throw 'zadmin could not log in again after the widget upload' }

# --- FTP Config: SFTPGo's name and passive ports from the settings, no ProFTPD settings or text
try {
$c = (Page '/?module=ftp_admin' $null).Content
if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw 'the FTP Config page does not load' }
$fa = if ($c -match '(?s)(<div class="fa" id="fa">.*)$') { $Matches[1] } else { '' }
$bad = @()
if ($fa -eq '') { $bad += 'no FTP Config part on the page' }
if ($fa -match 'ProFTPD') { $bad += 'it names ProFTPD' }
if ($fa -match '60000-65535') { $bad += 'ProFTPD''s passive ports' }
if ($fa -match 'name="ftp_db"') { $bad += 'ProFTPD''s database setting' }
if ($fa -notmatch 'SFTPGo') { $bad += 'SFTPGo is not named' }
if ($fa -notmatch '50000-50100') { $bad += 'not SFTPGo''s passive ports' }
if ($bad) { $tx = ($fa -replace '(?s)<script.*?</script>', ' ' -replace '<[^>]+>', ' ' -replace '\s+', ' ').Trim(); throw "the FTP Config page does not show SFTPGo's settings: $($bad -join ', '). It shows: $($tx.Substring(0, [Math]::Min(700, $tx.Length)))" }
Ok 'FTP Config: the page shows SFTPGo, its passive ports (50000-50100) and no ProFTPD settings'
} catch { $late += "$_" }

# --- Apache Config: a domain's own lines (saved on its page) and the lines for every website go into the Windows sites;
# a line Apache refuses switches off only that domain's lines (the page says so), the rest stays live
try {
$p = Page '/?module=apache_admin' $null
if ($p.Content -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw 'the Apache Config page does not load' }
if ($p.Content -match '/etc/hosts|PHP processes per account') { throw 'the Apache Config page shows Linux-only settings on Windows' }
function Loc($h, $path) { $ErrorActionPreference = 'Continue'; (& curl.exe -s -o NUL -w '%{http_code} %{redirect_url}' --max-time 20 -H "Host: $h" "http://127.0.0.1$path" 2>&1) -join '' }
function SaveVhost($custom) {
    $p = Page "/?module=apache_admin&show=Vhost&id=$vid" $null
    if ($p.Content -match 'Allow FollowSymLinks|SSL settings') { throw 'the domain page shows Linux-only settings on Windows' }
    $null = Page "/?module=apache_admin&show=Vhost&id=$vid&action=SaveVhost" @{ csfr_token = (Token $p.Content); vh_id_pk = $vid; vh_enabled_in = '1'; vh_custom_tx = $custom }
    $p = Page '/?module=sentoraconfig' $null
    $null = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
}
SaveVhost 'Redirect 302 /sentora-own https://example.com/own'
$env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
& $my --protocol=TCP -h 127.0.0.1 -u root -e "UPDATE sentora_core.x_settings SET so_value_tx='Redirect 302 /sentora-every https://example.com/every' WHERE so_name_vc='global_vhcustom';" | Out-Null
Remove-Item Env:\MYSQL_PWD
$c = ''
for ($i = 0; $i -lt 40 -and $c -notmatch '^302 https://example.com/own'; $i++) { Start-Sleep 3; $c = Loc $dom '/sentora-own' }
if ($c -notmatch '^302 https://example.com/own') { throw "Apache Config: the domain's own lines are not in its site ($c)" }
Ok "Apache Config: $dom's own lines (saved on its page) are in its site"
$c = Loc $dom '/sentora-every'
if ($c -notmatch '^302 https://example.com/every') { throw "Apache Config: the lines for every website are not in $dom's site ($c)" }
Ok 'Apache Config: the lines for every website are in each site'
SaveVhost 'ThisIsNotAnApacheDirective on'
$off = ''
for ($i = 0; $i -lt 40 -and $off -eq ''; $i++) {
    Start-Sleep 3
    $env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
    $off = (& $my --protocol=TCP -h 127.0.0.1 -u root -N -e "SELECT so_value_tx FROM sentora_core.x_settings WHERE so_name_vc='apache_custom_problems'") -join ''
    Remove-Item Env:\MYSQL_PWD
    if ($off -notmatch "vh:$($vid):custom") { $off = '' }
}
if ($off -eq '') { throw "Apache Config: a line Apache refuses was not switched off: $(Get-Content "$Root\logs\panel\daemon-panel-run.log" -Raw -ErrorAction SilentlyContinue)" }
if ((Site $dom '/') -notmatch 'Your Sentora account is active') { throw "Apache Config: $dom is no longer served after a refused line" }
if ((Loc $dom '/sentora-every') -notmatch '^302 ') { throw 'Apache Config: the lines for every website went away with the refused line' }
if ((Page '/?module=apache_admin' $null).Content -notmatch 'Apache did not take some settings') { throw 'Apache Config: the page does not say a line was switched off' }
Ok 'Apache Config: a line Apache refuses is switched off (the page says so); the site and everything else stay live'
SaveVhost ''
$env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
& $my --protocol=TCP -h 127.0.0.1 -u root -e "UPDATE sentora_core.x_settings SET so_value_tx='' WHERE so_name_vc='global_vhcustom'; UPDATE sentora_core.x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed';" | Out-Null
Remove-Item Env:\MYSQL_PWD
} catch { $late += "$_" }

# --- Server Monitor: the live figures come from the service SentoraStats (SYSTEM) through the page's own endpoint
try {
if ((Get-Service SentoraStats -ErrorAction SilentlyContinue).Status -ne 'Running') { throw 'the service SentoraStats does not run' }
$c = (Page '/?module=server_monitor' $null).Content
if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw 'the Server Monitor page does not load' }
if ($c -match 'id="sm-hard"') { throw 'Server Monitor shows the Linux Hardening card on Windows' }
$j = $null; $said = ''
for ($i = 0; $i -lt 20 -and -not $j; $i++) {
    try { $raw = (Page '/modules/server_monitor/code/stats.php?info=1' $null).Content; $said = $raw.Substring(0, [Math]::Min(300, $raw.Length)); $j = $raw | ConvertFrom-Json }
    catch {
        $said = "$($_.Exception.Message)"
        try { $rs = $_.Exception.Response; $said += ' ' + [int]$rs.StatusCode + ' ' + (New-Object IO.StreamReader($rs.GetResponseStream())).ReadToEnd() } catch { }
        Start-Sleep 3
    }
}
if (-not $j) {
    $wf = 'C:\zpanel\data\sentora\monitor\winstats.json'
    $head = "$(Get-Content $wf -Raw -ErrorAction SilentlyContinue)"; $head = $head.Substring(0, [Math]::Min(300, $head.Length))
    throw "Server Monitor: no figures. The page answered: $said. winstats.json ($((Get-Item $wf -ErrorAction SilentlyContinue).LastWriteTime)): $head. Its rights: $((icacls $wf) -join ' '). Its log: $(Get-Content "$Root\logs\panel\winstats-service.log" -Raw -ErrorAction SilentlyContinue)"
}
function SmMissing($j) {
    $m = @()
    if (@($j.cpu.cpu).Count -ne 8) { $m += 'cpu' }
    if ([int64]$j.mem.total -le 0) { $m += 'memory' }
    if (-not (@($j.fs) | Where-Object { $_.mount -eq 'C:' -and [int64]$_.total -gt 0 })) { $m += "disk C: (fs: $(($j.fs | ConvertTo-Json -Compress)))" }
    if (@($j.procs).Count -lt 10) { $m += "processes ($(@($j.procs).Count))" }
    if ($j.services.Apache -ne $true -or $j.services.MySQL -ne $true) { $m += "services ($(($j.services | ConvertTo-Json -Compress)))" }
    if (-not $j.info.os) { $m += 'system name' }
    return $m
}
$miss = SmMissing $j
for ($i = 0; $i -lt 5 -and $miss; $i++) { Start-Sleep 4; try { $j = (Page '/modules/server_monitor/code/stats.php?info=1' $null).Content | ConvertFrom-Json; $miss = SmMissing $j } catch { } }
if ($miss) { throw "Server Monitor: the figures are not complete (5 samples): $($miss -join '; ')" }
Ok "Server Monitor: live figures from SentoraStats (CPU, $([math]::Round($j.mem.total / 1GB, 1)) GB memory, disk C:, $(@($j.procs).Count) processes; Apache and MySQL running; $($j.info.os))"
# Apache's account may read the figures, never write them
$acl = (icacls C:\zpanel\data\sentora\monitor) -join ' '
if ($acl -match 'Apache2:\(OI\)\(CI\)\((M|F|W)') { throw "the monitor folder lets Apache write: $acl" }
} catch { $late += "$_" }

# --- System Log Viewer: the Windows logs from the log_dir setting (the panel's, the sites', Apache's), none of Linux's
try {
$c = (Page '/?module=system_logviewer' $null).Content
if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw 'the System Log Viewer page does not load' }
if ($c -notmatch [regex]::Escape("$dom-access.log") -or $c -notmatch 'php_errors\.log|error\.log') {
    $names = ([regex]::Matches($c, 'lv-item-name">([^<]+)<') | ForEach-Object { $_.Groups[1].Value }) -join ', '
    $why = "$Root\panel\ztest-$([guid]::NewGuid().ToString('N')).php"
    [IO.File]::WriteAllText($why, '<?php foreach (array("C:/zpanel/logs", "C:/zpanel/logs/apache", "C:/zpanel/logs/domains") as $d) { echo $d, " dir=", (is_dir($d) ? 1 : 0), " read=", (is_readable($d) ? 1 : 0), " link=", (is_link($d) ? 1 : 0), " scan=", implode("|", (array) @scandir($d)), " glob=", implode("|", (array) glob($d . "/*")), "; "; }')
    try { $inside = (Invoke-WebRequest "http://127.0.0.1/$(Split-Path $why -Leaf)" -UseBasicParsing -TimeoutSec 30).Content } catch { $inside = "$_" } finally { Remove-Item $why -Force }
    $shown = 'no module part'
    if ($c -match '(?s)id="zmodule_header_[^"]*"(.*)$') { $tx = ($Matches[1] -replace '(?s)<script.*?</script>', ' ' -replace '<[^>]+>', ' ' -replace '\s+', ' ').Trim(); $shown = $tx.Substring(0, [Math]::Min(900, $tx.Length)) }
    throw "System Log Viewer does not list the Windows logs (a site's access log, Apache's or PHP's). Listed: '$names'. The page shows: $shown. Inside Apache: $inside"
}
Ok "System Log Viewer: lists the Windows logs ($dom's access log, Apache's and PHP's)"
} catch { $late += "$_" }

# --- Site Health: the daemon (SYSTEM) checks each site and reads its logs; the client's page shows the site answering
try {
$f = 'C:\zpanel\data\sentora\sitehealth'
# (its check runs with the daemon's hourly jobs: the next run does them)
$env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
& $my --protocol=TCP -h 127.0.0.1 -u root -e "UPDATE sentora_core.x_settings SET so_value_tx='0' WHERE so_name_vc='daemon_hourrun';" | Out-Null
Remove-Item Env:\MYSQL_PWD
for ($i = 0; $i -lt 40 -and -not (Get-ChildItem $f -Filter 'acct-*.json' -ErrorAction SilentlyContinue); $i++) {
    if ($i % 10 -eq 0) { $p = Page '/?module=sentoraconfig' $null; $null = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) } }
    Start-Sleep 3
}
$c = (Page '/?module=site_health' $null).Content
if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw 'the Site Health page does not load' }
if ($c -notmatch [regex]::Escape($dom) -or $c -notmatch 'Answers') {
    $dl = (Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String -Pattern 'Site Health' | Select-Object -Last 4) -join ' | '
    throw "Site Health: $dom is not shown as checked (files: $((Get-ChildItem $f -ErrorAction SilentlyContinue).Name -join ', '); daemon: $dl)"
}
Ok "Site Health: $dom was checked by the daemon and its page shows it answering"
} catch { $late += "$_" }

# --- Backup Config: the off-server backup to a folder (the page's form, Test connection, Back up now: the databases, the
# settings, the websites and the mailboxes there; Stalwart runs again after), and the daily backup of every account
try {
$c = (Page '/?module=backup_admin' $null).Content
if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw 'the Backup Config page does not load' }
if ($c -match 'data-tab="restore"|Another SSH server|A Sentora backup node') { throw 'Backup Config shows Linux-only parts on Windows (Restore, SSH, backup node)' }
$ob = 'C:\sentora-offsite-test'
$obState = 'C:\zpanel\data\sentora\offsite-backup.json'
function ObState { try { Get-Content $obState -Raw -ErrorAction Stop | ConvertFrom-Json } catch { $null } }
$p = Page '/?module=backup_admin&action=SaveOffsite' @{ csfr_token = (Token $c); inDest = 'folder'; inOffsiteEnabled = '1'; inOffsitePath = $ob; inOffsiteTime = '23:59'; inOffsiteKeep = '3'; inOffsiteMail = '1' }
$st = $null
for ($i = 0; $i -lt 40; $i++) { Start-Sleep 3; $st = ObState; if ($st -and $st.check_time -and $st.check_ok) { break } }
if (-not ($st -and $st.check_ok)) { throw "Backup Config: the folder's test did not pass (state: $($st | ConvertTo-Json -Compress); log: $(Get-Content "$Root\logs\panel\offsite-backup.log" -Raw -ErrorAction SilentlyContinue))" }
Ok "Backup Config: a folder saved on the page passes its test ($($st.check_message))"
$t0 = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$p = Page '/?module=backup_admin' $null
$p = Page '/?module=backup_admin&action=OffsiteNow' @{ csfr_token = (Token $p.Content) }
for ($i = 0; $i -lt 100; $i++) { Start-Sleep 3; $st = ObState; if ($st -and [int64]$st.last_run -ge $t0) { break } }
# (the night is named by the panel's own time zone: the newest one there)
$night = (Get-ChildItem (Join-Path $ob $env:COMPUTERNAME) -Directory -ErrorAction SilentlyContinue | Where-Object Name -match '^20\d\d-\d\d-\d\d$' | Sort-Object Name | Select-Object -Last 1).FullName
if (-not $night) { $night = Join-Path $ob "$env:COMPUTERNAME\no-night" }
$miss = @()
foreach ($f in @("$night\databases\sentora_core.sql.gz", "$night\config\configs.zip", "$night\manifest.json", "$ob\$env:COMPUTERNAME\files\hostdata\zadmin\public_html", "$ob\$env:COMPUTERNAME\files\mail")) { if (-not (Test-Path $f)) { $miss += $f } }
if (-not ($st -and $st.result -eq 'ok') -or $miss) { throw "Backup Config: Back up now did not make the copy (state: $($st | ConvertTo-Json -Compress); missing: $($miss -join ', '); log: $(Get-Content "$Root\logs\panel\offsite-backup.log" -Raw -ErrorAction SilentlyContinue); PHP: $((Get-Content "$Root\logs\php\php_errors.log" -Tail 6 -ErrorAction SilentlyContinue) -join ' / '); running: $((Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='mysqldump.exe' OR Name='robocopy.exe'" | ForEach-Object { $_.Name + ':' + $_.CommandLine }) -join ' | '))" }
if ((Get-Service Stalwart).Status -ne 'Running') { throw 'Backup Config: Stalwart did not start again after the mailboxes were copied' }
$gz = [IO.Compression.GZipStream]::new([IO.File]::OpenRead("$night\databases\sentora_core.sql.gz"), [IO.Compression.CompressionMode]::Decompress)
$head = (New-Object IO.StreamReader($gz)).ReadToEnd(); $gz.Close()
if ($head -notmatch 'CREATE TABLE `x_accounts`') { throw 'Backup Config: the panel database dump holds no x_accounts table' }
Ok "Backup Config: Back up now copied the databases, the settings, the websites and the mailboxes to $ob ($($st.message)); Stalwart runs again"
# the daily backup of every account (Client backups tab), at the daemon's next daily run
$p = Page '/?module=backup_admin' $null
$p = Page '/?module=backup_admin&action=UpdateConfig' @{ csfr_token = (Token $p.Content); schedule_bu = 'true'; purge_bu = 'true'; purge_date = '30'; disk_bu = 'true'; inSaveSystem = '1' }
$before = @(Get-ChildItem "$Root\hostdata\zadmin\backups" -Filter '*.zip' -ErrorAction SilentlyContinue).Count
$env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
& $my --protocol=TCP -h 127.0.0.1 -u root -e "UPDATE sentora_core.x_settings SET so_value_tx='0' WHERE so_name_vc='daemon_dayrun';" | Out-Null
Remove-Item Env:\MYSQL_PWD
$p = Page '/?module=sentoraconfig' $null
$null = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) }
$after = $before
for ($i = 0; $i -lt 60 -and $after -le $before; $i++) { Start-Sleep 3; $after = @(Get-ChildItem "$Root\hostdata\zadmin\backups" -Filter '*.zip' -ErrorAction SilentlyContinue).Count }
if ($after -le $before) { throw "Backup Config: the daily backup of every account made no backup for zadmin (daemon: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String 'Backup' | Select-Object -Last 4) -join ' / '))" }
Ok 'Backup Config: the daily backup of every account made a backup for zadmin'
} catch { $late += "$_" }

if ($late) { throw ($late -join ' || ') }

# --- Mailboxes: a mailbox made on the Mailboxes page becomes a Stalwart account at the daemon run (sys_winmail, through
# Stalwart's API as SYSTEM) and logs in over IMAPS with its password; a new password works, the old one no longer;
# switched off it can not log in; deleted on the page, it goes
if (Get-Service Stalwart -ErrorAction SilentlyContinue) {
    function ImapLogin($user, $pass) {
        $c = New-Object Net.Sockets.TcpClient('127.0.0.1', 993)
        try {
            $ssl = New-Object Net.Security.SslStream($c.GetStream(), $false, ([Net.Security.RemoteCertificateValidationCallback] { $true }))
            $ssl.AuthenticateAsClient('localhost')
            $rd = New-Object IO.StreamReader($ssl); $wr = New-Object IO.StreamWriter($ssl); $wr.NewLine = "`r`n"; $wr.AutoFlush = $true
            $null = $rd.ReadLine()
            $wr.WriteLine('a1 LOGIN "' + $user + '" "' + $pass + '"')
            do { $l = $rd.ReadLine() } while ($l -and $l -notmatch '^a1 ')
            return [bool]($l -match '^a1 OK')
        } finally { $c.Close() }
    }
    # (a message to an address on this server, the way mail from outside arrives: port 25, no login)
    function MailTo($to, $subject) {
        # (as another mail server would: a real domain in EHLO, STARTTLS when offered; .NET's own sender says only the
        # computer's name, which Stalwart rightly refuses)
        $py = "import smtplib,ssl,sys`ns=smtplib.SMTP('127.0.0.1',25,local_hostname='outside.example',timeout=30)`ns.ehlo()`nif s.has_extn('starttls'):`n    s.starttls(context=ssl._create_unverified_context()); s.ehlo()`ns.sendmail('tester@outside.example',[sys.argv[1]],'From: tester@outside.example\r\nTo: '+sys.argv[1]+'\r\nSubject: '+sys.argv[2]+'\r\n\r\nWindows panel test\r\n')`ns.quit()"
        $ErrorActionPreference = 'Continue'
        $o = (& python -c $py $to $subject 2>&1) -join ' '
        $ErrorActionPreference = 'Stop'
        if ($LASTEXITCODE -ne 0) { throw "sending to $to failed: $o" }
    }
    # (whether a mailbox has a message with this subject, in its inbox or its junk folder)
    function ImapHas($user, $pass, $subject) {
        $c = New-Object Net.Sockets.TcpClient('127.0.0.1', 993)
        try {
            $ssl = New-Object Net.Security.SslStream($c.GetStream(), $false, ([Net.Security.RemoteCertificateValidationCallback] { $true }))
            $ssl.AuthenticateAsClient('localhost')
            $rd = New-Object IO.StreamReader($ssl); $wr = New-Object IO.StreamWriter($ssl); $wr.NewLine = "`r`n"; $wr.AutoFlush = $true
            $null = $rd.ReadLine(); $n2 = 0
            function Cmd($line) { $script:n2++; $tag = "t$script:n2"; $wr.WriteLine("$tag $line"); $all = @(); do { $l = $rd.ReadLine(); $all += $l } while ($l -and $l -notmatch "^$tag "); return ,$all }
            $null = Cmd ('LOGIN "' + $user + '" "' + $pass + '"')
            foreach ($box in @('INBOX', '"Junk Mail"')) {
                $null = Cmd "SELECT $box"
                $s = Cmd ('SEARCH SUBJECT "' + $subject + '"')
                if (($s | Where-Object { $_ -match '^\* SEARCH \d' })) { return $true }
            }
            return $false
        } finally { $c.Close() }
    }
    function MailRun { $p = Page '/?module=sentoraconfig' $null; $null = Page '/?module=sentoraconfig&action=RunDaemon' @{ csfr_token = (Token $p.Content) } }
    $box = "box$n"; $addr = "$box@$dom"; $mpw = "Mail-Test-$n-Aa9"; $mpw2 = "Mail-New-$n-Bb8"
    $p = Page '/?module=mailboxes' $null
    $p = Page '/?module=mailboxes&action=AddMailbox' @{ csfr_token = (Token $p.Content); inAddress = $box; inDomain = $dom; inPassword = $mpw }
    MailRun
    $ok = $false
    for ($i = 0; $i -lt 40 -and -not $ok; $i++) { Start-Sleep 3; try { $ok = ImapLogin $addr $mpw } catch { $ok = $false } }
    if (-not $ok) { throw "Mail: $addr can not log in over IMAPS (daemon log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String 'Mail' | Select-Object -Last 4) -join ' / '))" }
    if (ImapLogin $addr 'Wrong-Pass-123') { throw 'Mail: a wrong password logged in' }
    Ok "Mail: a mailbox made on the Mailboxes page ($addr) logs in over IMAPS; a wrong password does not"
    # an alias of it, and a distribution list with it and alice@example.test (made with mailuser.ps1)
    $al = "al$n"; $lst = "list$n"
    $p = Page '/?module=aliases' $null
    $p = Page '/?module=aliases&action=CreateAlias' @{ csfr_token = (Token $p.Content); inAddress = $al; inDomain = $dom; inDestination = $addr; inCreate = '1' }
    $p = Page '/?module=distlists' $null
    $p = Page '/?module=distlists&action=AddDistList' @{ csfr_token = (Token $p.Content); inAddress = $lst; inDomain = $dom; inCreate = '1' }
    $env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
    $dlid = (& $my --protocol=TCP -h 127.0.0.1 -u root -N -e "SELECT dl_id_pk FROM sentora_core.x_distlists WHERE dl_address_vc='$lst@$dom' AND dl_deleted_ts IS NULL") -join ''
    Remove-Item Env:\MYSQL_PWD
    if ($dlid -notmatch '^\d+$') { throw "Distribution Lists did not make $lst@$dom" }
    foreach ($member in @($addr, 'alice@example.test')) {
        $p = Page "/?module=distlists&show=Edit&other=$dlid" $null
        $p = Page "/?module=distlists&show=Edit&other=$dlid&action=UpdateDistList" @{ csfr_token = (Token $p.Content); inDLID = $dlid; inAdd = '1'; inAddAddress = $member }
    }
    MailRun
    Start-Sleep 5
    $sa = "alias test $n"; $sl = "list test $n"
    MailTo "$al@$dom" $sa
    MailTo "$lst@$dom" $sl
    $ok = $false
    for ($i = 0; $i -lt 30 -and -not $ok; $i++) { Start-Sleep 3; try { $ok = (ImapHas $addr $mpw $sa) } catch { $ok = $false } }
    if (-not $ok) { throw "Mail: a message to the alias $al@$dom did not reach $addr (daemon log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String 'Mail' | Select-Object -Last 4) -join ' / '))" }
    Ok "Mail: an alias made on the Aliases page ($al@$dom) delivers to its mailbox"
    $ok = $false
    for ($i = 0; $i -lt 30 -and -not $ok; $i++) { Start-Sleep 3; try { $ok = (ImapHas $addr $mpw $sl) -and (ImapHas 'alice@example.test' 'Alice-Pass-1234' $sl) } catch { $ok = $false } }
    if (-not $ok) { throw "Mail: a message to the list $lst@$dom did not reach both members (daemon log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String 'Mail' | Select-Object -Last 4) -join ' / '))" }
    Ok "Mail: a distribution list made on its page ($lst@$dom) delivers to both its members"
    # forwarders: to alice@example.test with a copy kept (a Sieve script on the mailbox), then also to
    # bob@example.test (two addresses go through a list of the panel's: Stalwart redirects once per script)
    $p = Page '/?module=forwarders' $null
    $p = Page '/?module=forwarders&action=CreateForwarder' @{ csfr_token = (Token $p.Content); inAddress = $addr; inDestinationName = 'alice'; inDestinationDomain = 'example.test'; inKeepMessage = '1' }
    MailRun
    Start-Sleep 8
    $sf = "forward test $n"
    MailTo $addr $sf
    $ok = $false
    for ($i = 0; $i -lt 30 -and -not $ok; $i++) { Start-Sleep 3; try { $ok = (ImapHas $addr $mpw $sf) -and (ImapHas 'alice@example.test' 'Alice-Pass-1234' $sf) } catch { $ok = $false } }
    if (-not $ok) { throw "Mail: a forwarder from $addr to alice@example.test (copy kept) did not deliver to both (daemon log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String 'Mail' | Select-Object -Last 4) -join ' / '))" }
    Ok "Mail: a forwarder made on the Forwarders page ($addr to alice@example.test, copy kept) delivers to both"
    $p = Page '/?module=forwarders' $null
    $p = Page '/?module=forwarders&action=CreateForwarder' @{ csfr_token = (Token $p.Content); inAddress = $addr; inDestinationName = 'bob'; inDestinationDomain = 'example.test'; inKeepMessage = '1' }
    MailRun
    Start-Sleep 8
    $sf2 = "forward two test $n"
    MailTo $addr $sf2
    $ok = $false
    for ($i = 0; $i -lt 30 -and -not $ok; $i++) { Start-Sleep 3; try { $ok = (ImapHas $addr $mpw $sf2) -and (ImapHas 'alice@example.test' 'Alice-Pass-1234' $sf2) -and (ImapHas 'bob@example.test' 'Bob-Pass-5678' $sf2) } catch { $ok = $false } }
    if (-not $ok) { throw "Mail: forwarders from $addr to two addresses did not deliver to all three (daemon log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String 'Mail' | Select-Object -Last 4) -join ' / '))" }
    Ok "Mail: forwarders to two addresses (alice and bob, copy kept) deliver to all three"
    # --- Mail Config: the Windows settings (webmail, ActiveSync, Stalwart's limits) and no SOGo; saved, the daemon
    # writes the webmail's file, closes ActiveSync's address (and opens it again), and sets Stalwart's largest message and
    # login block; a mailbox's size is its Stalwart disk quota
    function Stal($method, $argsJson) {
        $adm = Get-Content "$Root\configs\stalwart\admin.txt" -Raw
        $su = if ($adm -match '(?m)^user=(.+)$') { $Matches[1].Trim() } else { '' }; $sp = if ($adm -match '(?m)^password=(.+)$') { $Matches[1].Trim() } else { '' }
        $ErrorActionPreference = 'Continue'
        $ss = ((& curl.exe -sk --max-time 20 -u "${su}:$sp" 'https://127.0.0.1:8443/jmap/session' 2>&1) -join '') | ConvertFrom-Json
        $acct = $ss.primaryAccounts.'urn:stalwart:jmap'
        $f = Join-Path $env:TEMP "stal-$n.json"
        [IO.File]::WriteAllText($f, ('{"using":["urn:ietf:params:jmap:core","urn:stalwart:jmap"],"methodCalls":[["' + $method + '",' + $argsJson.Replace('"ACCT"', '"' + $acct + '"') + ',"c0"]]}'))
        $r = (& curl.exe -sk --max-time 20 -u "${su}:$sp" -H 'Content-Type: application/json' --data-binary "@$f" 'https://127.0.0.1:8443/jmap/' 2>&1) -join ''
        $ErrorActionPreference = 'Stop'
        return $r
    }
    function Eas { $ErrorActionPreference = 'Continue'; (& curl.exe -sk -o NUL -w '%{http_code}' -X OPTIONS -u alice@example.test:wrong-password --max-time 20 https://127.0.0.1/Microsoft-Server-ActiveSync 2>&1) -join '' }
    function MailCfg($vals) {
        $p = Page '/?module=mail_admin' $null
        $b = @{ csfr_token = (Token $p.Content) }; foreach ($k in $vals.Keys) { $b[$k] = $vals[$k] }
        $p = Page '/?module=mail_admin&action=UpdateConfig' $b
        if ($p.Content -match 'Fatal error|Uncaught|Warning:') { throw 'Mail Config: saving failed' }
        MailRun
    }
    $c = (Page '/?module=mail_admin' $null).Content
    if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw 'the Mail Config page does not load' }
    $bad = @()
    foreach ($w in @('name="webmail_language"', 'name="webmail_timezone"', 'name="mail_activesync"', 'name="mail_max_message_mb"', 'name="mail_ban_logins"', 'name="max_mail_size"')) { if ($c -notmatch [regex]::Escape($w)) { $bad += "no $w" } }
    if ($c -match 'SOGo|name="sogo_') { $bad += 'it shows SOGo' }
    if ($c -notmatch 'IMAP') { $bad += 'no mail services box' }
    if ($bad) { throw "Mail Config: the page is not the Windows one: $($bad -join ', ')" }
    Ok 'Mail Config: the page shows the webmail, ActiveSync and mail server settings and the services, no SOGo'
    $env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
    $qmb = [int]((& $my --protocol=TCP -h 127.0.0.1 -u root -N -e "SELECT quota FROM sentora_postfix.mailbox WHERE username='$addr'") -join '')
    Remove-Item Env:\MYSQL_PWD
    $r = Stal 'x:Account/get' '{"accountId":"ACCT","properties":["emailAddress","quotas"]}'
    $got = (($r | ConvertFrom-Json).methodResponses[0][1].list | Where-Object { $_.emailAddress -eq $addr }).quotas.maxDiskQuota
    if ($qmb -le 0 -or [int64]$got -ne [int64]$qmb * 1048576) { throw "Mail Config: $addr's size in Stalwart is '$got', the panel's is $qmb MB ($($r.Substring(0, [Math]::Min(400, $r.Length))))" }
    Ok "Mail Config: a mailbox's size ($qmb MB) is its Stalwart disk quota"
    if ((Eas) -ne '401') { throw "Mail Config: ActiveSync does not answer before the change ($(Eas))" }
    MailCfg @{ webmail_language = 'de_DE'; webmail_refresh = '120'; mail_activesync = 'false'; mail_max_message_mb = '20'; mail_ban_logins = '500' }
    $rcp = "$Root\configs\stalwart\roundcube-panel.inc.php"
    $ok = $false
    for ($i = 0; $i -lt 40 -and -not $ok; $i++) { Start-Sleep 3; $t = Get-Content $rcp -Raw -ErrorAction SilentlyContinue; $ok = ($t -match "'de_DE'") -and ($t -match "refresh_interval'\] = 120") -and ((Eas) -eq '403') }
    if (-not $ok) { throw "Mail Config: the daemon did not apply the webmail settings and ActiveSync off ($rcp has: $t; ActiveSync: $(Eas); log: $((Get-Content "$Root\logs\panel\daemon-panel-run.log" -ErrorAction SilentlyContinue | Select-String 'Mail Config' | Select-Object -Last 4) -join ' / '))" }
    Ok 'Mail Config: saved, the webmail gets its language and new-mail check, and ActiveSync''s address is closed (403)'
    $r1 = Stal 'x:MtaStageData/get' '{"accountId":"ACCT","properties":["maxMessageSize"]}'
    $r2 = Stal 'x:Security/get' '{"accountId":"ACCT","properties":["authBanRate"]}'
    if ($r1 -notmatch '"else":"20971520"' -or $r2 -notmatch '"count":500') { throw "Mail Config: Stalwart did not get its new limits: $r1 / $r2" }
    Ok 'Mail Config: Stalwart takes messages up to 20 MB and blocks after 500 wrong passwords (its own settings, read back)'
    if ((Get-Service Apache2).Status -ne 'Running') { throw 'Mail Config: Apache does not run after the change' }
    MailCfg @{ webmail_language = 'auto'; webmail_refresh = '60'; mail_activesync = 'true'; mail_max_message_mb = '100'; mail_ban_logins = '100' }
    $ok = $false
    for ($i = 0; $i -lt 40 -and -not $ok; $i++) { Start-Sleep 3; $ok = (Eas) -eq '401' }
    if (-not $ok) { throw "Mail Config: ActiveSync switched on again does not answer ($(Eas))" }
    Ok 'Mail Config: ActiveSync switched on again answers (401 for a wrong password)'
    $env:MYSQL_PWD = (Get-Content "$Root\configs\mysql\root-password.txt" -Raw).Trim()
    $mid = (& $my --protocol=TCP -h 127.0.0.1 -u root -N -e "SELECT mb_id_pk FROM sentora_core.x_mailboxes WHERE mb_address_vc='$addr' AND mb_deleted_ts IS NULL") -join ''
    Remove-Item Env:\MYSQL_PWD
    $p = Page "/?module=mailboxes&show=Edit&other=$mid" $null
    $p = Page "/?module=mailboxes&show=Edit&other=$mid&action=UpdateMailbox" @{ csfr_token = (Token $p.Content); inSave = $mid; inPassword = $mpw2; inEnabled = '1' }
    MailRun
    $ok = $false
    for ($i = 0; $i -lt 40 -and -not $ok; $i++) { Start-Sleep 3; try { $ok = (ImapLogin $addr $mpw2) -and -not (ImapLogin $addr $mpw) } catch { $ok = $false } }
    if (-not $ok) { throw "Mail: the new password of $addr does not work (or the old one still does)" }
    Ok 'Mail: a password changed on the page works at once after the daemon run, the old one no longer'
    $p = Page "/?module=mailboxes&show=Edit&other=$mid" $null
    $p = Page "/?module=mailboxes&show=Edit&other=$mid&action=UpdateMailbox" @{ csfr_token = (Token $p.Content); inSave = $mid; inPassword = '' }
    MailRun
    $off = $false
    for ($i = 0; $i -lt 40 -and -not $off; $i++) { Start-Sleep 3; try { $off = -not (ImapLogin $addr $mpw2) } catch { $off = $false } }
    if (-not $off) { throw "Mail: $addr switched off on the page still logs in" }
    Ok 'Mail: switched off on the page, it can not log in'
    $p = Page '/?module=mailboxes' $null
    $p = Page '/?module=mailboxes&action=ConfirmDeleteMailbox' @{ csfr_token = (Token $p.Content); inDelete = $mid }
    MailRun
    Start-Sleep 5
    $left = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$Root\bin\mailuser.ps1" list 2>&1 | Out-String
    if ($left -match [regex]::Escape($addr)) { throw "Mail: $addr is still in Stalwart after it was deleted on the page" }
    if ($left -notmatch 'alice@example\.test') { throw "Mail: a mailbox made with mailuser.ps1 (alice@example.test) went: $left" }
    Ok 'Mail: deleted on the page, it goes from Stalwart; mailboxes made with mailuser.ps1 stay'
}
