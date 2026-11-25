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
foreach ($m in @('packages', 'manage_clients', 'mysql_databases', 'mysql_users', 'my_account', 'sentoraconfig', 'faqs', 'client_notices', 'domains', 'sub_domains', 'parked_domains', 'sencrypt', 'dns_manager', 'dns_admin', 'ftp_management', 'mailboxes', 'cron', 'aliases', 'distlists', 'webmail', 'services', 'user_logviewer', 'phpmyadmin', 'backupmgr')) {
    $c = (Page "/?module=$m" $null).Content
    if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw "module $m does not load" }
}
Ok 'the modules ready for Windows load (packages, clients, MySQL, my account, settings, FAQs, notices, domains, subdomains, parked domains, Sencrypt, DNS Manager, DNS Config, FTP Accounts, Mailboxes, Cron Manager, Aliases, Distribution Lists, Webmail, Services, Domain Logs, phpMyAdmin, Backup Manager)'
foreach ($m in @('apache_admin', 'forwarders', 'php_versions')) {
    if ((Page "/?module=$m" $null).Content -notmatch 'Unable to find requested module') { throw "module $m is not kept out on Windows" }
}
$front = (Page '/' $null).Content
if ($front -match '\?module=(apache_admin|forwarders)\b') { throw "the page offers a module not ready for Windows: $($Matches[0])" }
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
$parkedSaid = ([regex]::Matches($p.Content, '(?s)class="alert[^"]*"[^>]*>(.{0,300}?)</') | ForEach-Object { ($_.Groups[1].Value -replace '<[^>]+>', ' ').Trim() }) -join ' / '
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
for ($i = 0; $i -lt 40; $i++) { $pk = Site $park '/'; if ($pk -ne '' -and $pk -notmatch 'inUsername|Your Sentora account is active') { break }; Start-Sleep 3 }
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
