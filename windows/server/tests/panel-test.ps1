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
foreach ($m in @('packages', 'manage_clients', 'mysql_databases', 'mysql_users', 'my_account', 'sentoraconfig', 'faqs', 'client_notices', 'domains', 'sub_domains', 'parked_domains', 'sencrypt')) {
    $c = (Page "/?module=$m" $null).Content
    if ($c -match 'Unable to find requested module|Fatal error|Uncaught|Warning:') { throw "module $m does not load" }
}
Ok 'the modules ready for Windows load (packages, clients, MySQL, my account, settings, FAQs, notices, domains, subdomains, parked domains, Sencrypt)'
foreach ($m in @('apache_admin', 'dns_manager', 'mailboxes', 'ftp_management', 'cron', 'backupmgr', 'php_versions')) {
    if ((Page "/?module=$m" $null).Content -notmatch 'Unable to find requested module') { throw "module $m is not kept out on Windows" }
}
$front = (Page '/' $null).Content
if ($front -match '\?module=(apache_admin|dns_manager|mailboxes|cron)\b') { throw "the page offers a module not ready for Windows: $($Matches[0])" }
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
$pk = Site $park '/'
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
