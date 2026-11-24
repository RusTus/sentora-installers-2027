# PHP per hosting account (installer\phpaccount.ps1), as the panel will use it: two accounts, alice and bob, each a
# website; then alice's PHP tries what it must not (the probe's attacks, tests\php-isolation-probe.ps1, with the guard
# always on). -Check only checks that the accounts made before still work (after a repair or an update).
param([string]$Root = 'C:\zpanel', [switch]$Check)
$ErrorActionPreference = 'Stop'
$fails = 0
function Ok($t) { Write-Host "ok   $t" }
function Bad($t) { Write-Host "FAIL $t"; $script:fails++ }
function Get-Site($name, $path) {
    $ErrorActionPreference = 'Continue'
    $o = & curl.exe -s --max-time 60 -H "Host: $name.test" "http://127.0.0.1$path" 2>&1
    if ($LASTEXITCODE -ne 0) { "ERROR curl $LASTEXITCODE $o" } else { ($o -join "`n") }
}
function Get-Code($name, $path) { $ErrorActionPreference = 'Continue'; (& curl.exe -s -o NUL -w '%{http_code}' --max-time 60 -H "Host: $name.test" "http://127.0.0.1$path" 2>&1) -join '' }
$tool = "$Root\bin\phpaccount.ps1"
$fw = $Root -replace '\\', '/'
$acc = @('alice', 'bob')

if (-not $Check) {
    foreach ($n in $acc) {
        $o = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $tool add $n -NoRestart 2>&1
        if ($LASTEXITCODE -ne 0) { throw "phpaccount.ps1 add ${n}: $o" }
        Write-Host "   $($o -join ' ')"
    }
    # their websites (what the panel's vhost writer will do): a name each, the folder in the account's hostdata
    $vh = "$Root\configs\apache\httpd-vhosts.conf"
    foreach ($n in $acc) {
        Add-Content -Path $vh -Encoding ASCII -Value "`r`n<VirtualHost *:80>`r`n    ServerName $n.test`r`n    DocumentRoot `"$fw/hostdata/$n/public_html`"`r`n</VirtualHost>`r`n"
    }
    # a PHP file in hostdata of no account
    New-Item -ItemType Directory -Force -Path "$Root\hostdata\nobody\public_html" | Out-Null
    Set-Content "$Root\hostdata\nobody\public_html\x.php" '<?php echo "ran";' -Encoding ASCII
    Add-Content -Path $vh -Encoding ASCII -Value "`r`n<VirtualHost *:80>`r`n    ServerName nobody.test`r`n    DocumentRoot `"$fw/hostdata/nobody/public_html`"`r`n</VirtualHost>`r`n"
    Restart-Service Apache2 -Force
    Start-Sleep 3
}

$who = @'
<?php echo 'mysqli=', (extension_loaded('mysqli') ? 'yes' : 'no'), '|key=', (isset($_SERVER['SENTORA_KEY']) || isset($_ENV['SENTORA_KEY']) ? 'seen' : 'hidden'), '|getenv=', (getenv('SENTORA_KEY') ? 'seen' : 'hidden'), '|whoami=', trim((string) shell_exec('whoami')), '|pid=', getmypid(), '|sapi=', PHP_SAPI, '|ini=', php_ini_loaded_file(), '|prepend=', ini_get('auto_prepend_file'), '|basedir=', ini_get('open_basedir');
'@
$slow = @'
<?php $t = microtime(true); sleep(2); echo 'slept ', round(microtime(true) - $t, 2);
'@
$try = @'
<?php
// what alice's PHP may do; each answer is name=yes|no
header('Content-Type: text/plain');
$R = 'ROOT';
function t($name, $ok) { echo $name, '=', ($ok ? 'yes' : 'no'), "\n"; }
// through PHP (open_basedir and the folder rights both)
t('php-read-own', @file_get_contents("$R\\hostdata\\alice\\secret.txt") !== false);
t('php-read-bob', @file_get_contents("$R\\hostdata\\bob\\secret.txt") !== false);
t('php-read-mysqlpw', @file_get_contents("$R\\configs\\mysql\\root-password.txt") !== false);
// through the shell (Windows' folder rights only: open_basedir does not reach a program PHP starts)
$sh = function ($c) { return trim((string) shell_exec($c . ' 2>&1')); };
t('os-read-own', strpos($sh("type \"$R\\hostdata\\alice\\secret.txt\""), 'the secret of alice') !== false);
t('os-read-bob', strpos($sh("type \"$R\\hostdata\\bob\\secret.txt\""), 'the secret of bob') !== false);
t('os-list-bob', strpos($sh("dir /b \"$R\\hostdata\\bob\""), 'secret.txt') !== false);
t('os-read-mysqlpw', preg_match('/^\S{12,}$/', $sh("type \"$R\\configs\\mysql\\root-password.txt\"")) === 1);
t('os-read-bob-ini', strpos($sh("type \"$R\\configs\\php\\bob.ini\""), 'open_basedir') !== false);
t('os-read-httpdconf', strpos($sh("type \"$R\\configs\\apache\\httpd.conf\""), 'ServerRoot') !== false);
t('os-read-panel', stripos($sh("dir /b \"$R\\panel\""), 'index') !== false);
t('os-write-own', strpos($sh("echo x> \"$R\\hostdata\\alice\\tmp\\w.txt\" && type \"$R\\hostdata\\alice\\tmp\\w.txt\""), 'x') === 0);
t('os-write-bob', strpos($sh("echo x> \"$R\\hostdata\\bob\\w.txt\" && echo written"), 'written') !== false);
t('os-write-phpdir', strpos($sh("echo x> \"$R\\bin\\php\\w.txt\" && echo written"), 'written') !== false);
t('os-write-windir', strpos($sh("echo x> \"%SystemRoot%\\w.txt\" && echo written"), 'written') !== false);
t('os-write-zsudo-queue', strpos($sh("echo x> \"$R\\data\\sentora\\zsudo\\queue\\w\" && echo written"), 'written') !== false);
t('os-stop-apache', stripos($sh('sc.exe stop Apache2'), 'STOP_PENDING') !== false);
t('os-stop-bob-php', stripos($sh('sc.exe stop SentoraPHP-bob'), 'STOP_PENDING') !== false);
t('os-kill-bob-php', stripos($sh('taskkill /F /FI "SERVICES eq SentoraPHP-bob"'), 'SUCCESS') !== false || stripos($sh('taskkill /F /IM php-cgi.exe /FI "USERNAME ne NT SERVICE\\SentoraPHP-alice"'), 'SUCCESS') !== false);
t('os-admin', stripos($sh('net session'), 'denied') === false);
echo 'whoami=', $sh('whoami'), "\n";
if (isset($_GET['junction'])) { echo 'junction=', $sh("mklink /J \"$R\\hostdata\\alice\\public_html\\tobob\" \"$R\\hostdata\\bob\\public_html\""), "\n"; }
echo 'panel-listing=', str_replace(array("\r", "\n"), ' ', substr($sh("dir /b \"$R\\panel\""), 0, 200)), "\n";
'@ -replace 'ROOT', ($Root -replace '\\', '\\')
$fcgi = @'
<?php
// a FastCGI request straight to another PHP service's port: does it run that account's script for us?
header('Content-Type: text/plain');
function rec($type, $body) { return chr(1) . chr($type) . pack('n', 1) . pack('n', strlen($body)) . chr(0) . chr(0) . $body; }
function nv($n, $v) { $f = function ($s) { $l = strlen($s); return $l < 128 ? chr($l) : pack('N', $l | 0x80000000); }; return $f($n) . $f($v) . $n . $v; }
$port = (int) $_GET['port']; $file = isset($_GET['file']) ? (string) $_GET['file'] : '';
if (isset($_GET['plant'])) {
    // a script of ours where bob's PHP could read it (folders every account may write), then asked to run there
    $file = '';
    foreach (array(getenv('SystemRoot') . '\\Temp', getenv('ProgramData'), getenv('PUBLIC'), 'C:\\Users\\Default') as $d) {
        $f = $d . '\\sentora-probe-' . getmypid() . '.php';
        shell_exec('echo ^<?php echo "planted ran as ", trim(shell_exec("whoami")), " ini=", php_ini_loaded_file(), " prepend=", ini_get("auto_prepend_file"), " basedir=", ini_get("open_basedir");> "' . $f . '" 2>NUL');
        if (trim((string) shell_exec('type "' . $f . '" 2>NUL')) !== '') { $file = str_replace('\\', '/', $f); echo "planted-in=$d "; break; }
    }
    if ($file === '') { echo 'planted-in=nowhere (no shared folder alice can write)'; exit; }
}
$s = @fsockopen('127.0.0.1', $port, $en, $es, 3);
if (!$s) { echo "connect=no ($en $es)"; exit; }
$p = '';
$extra = isset($_GET['pv']) ? array('PHP_VALUE' => "auto_prepend_file=none\nopen_basedir=none", 'PHP_ADMIN_VALUE' => "auto_prepend_file=none\nopen_basedir=none") : array();
foreach ($extra + array('SCRIPT_FILENAME' => $file, 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/who.php', 'QUERY_STRING' => '', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'GATEWAY_INTERFACE' => 'CGI/1.1', 'REDIRECT_STATUS' => '200', 'SERVER_SOFTWARE' => 'x', 'REMOTE_ADDR' => '127.0.0.1') as $k => $v) { $p .= nv($k, $v); }
fwrite($s, rec(1, pack('n', 1) . chr(0) . str_repeat("\0", 5)) . rec(4, $p) . rec(4, '') . rec(5, ''));
stream_set_timeout($s, 5);
$out = '';
while (!feof($s)) {
    $h = fread($s, 8); if (strlen($h) < 8) { break; }
    $a = unpack('Cv/Ct/nid/nlen/Cpad/Cr', $h);
    $b = ''; while (strlen($b) < $a['len'] && !feof($s)) { $b .= fread($s, $a['len'] - strlen($b)); }
    if ($a['pad']) { fread($s, $a['pad']); }
    if ($a['t'] == 6) { $out .= $b; }
    if ($a['t'] == 3) { break; }
}
echo 'connect=yes answer=', substr(preg_replace('/\s+/', ' ', $out), 0, 300);
'@

if (-not $Check) {
    foreach ($n in $acc) {
        Set-Content "$Root\hostdata\$n\public_html\who.php" $who -Encoding ASCII
        Set-Content "$Root\hostdata\$n\public_html\slow.php" $slow -Encoding ASCII
        Set-Content "$Root\hostdata\$n\public_html\secret.txt" "the secret of $n" -Encoding ASCII
        Set-Content "$Root\hostdata\$n\secret.txt" "the secret of $n" -Encoding ASCII
    }
    Set-Content "$Root\hostdata\alice\public_html\try.php" $try -Encoding ASCII
    Set-Content "$Root\hostdata\alice\public_html\fcgi.php" $fcgi -Encoding ASCII
    Set-Content "$Root\hostdata\alice\public_html\info.php" '<?php phpinfo();' -Encoding ASCII
}

# --- each site runs as its own account, with its extensions, the key hidden from it
foreach ($n in $acc) {
    $w = Get-Site $n '/who.php'
    if ($w -match "whoami=nt service\\sentoraphp-$n\|") { Ok "$n.test runs PHP as NT SERVICE\SentoraPHP-$n" } else { Bad "$n.test: $w" }
    if ($w -match 'mysqli=yes') { Ok "$n's PHP has its extensions (mysqli)" } else { Bad "$n's PHP has no mysqli: $w" }
    if ($w -match 'key=hidden') { Ok "$n's own scripts do not see its key in `$_SERVER or `$_ENV" } else { Bad "$n's scripts see the key: $w" }
    if ($w -match 'getenv=seen') { Write-Host "note $n's scripts can still read their own key with getenv('SENTORA_KEY') (it opens only $n's own PHP)" }
    if ($w -match [regex]::Escape("basedir=$Root\hostdata\$n\;")) { Ok "$n's PHP is fenced to its folder" } else { Bad "$n's open_basedir: $w" }
}
if ($Check) {
    if ($fails) { throw "$fails check(s) failed" }
    return
}
$i = Get-Site 'alice' '/info.php'
if ($i -match '[0-9a-f]{64}') { Bad "phpinfo() shows a key" } else { Ok "phpinfo() does not show the key" }
$c = Get-Code 'nobody' '/x.php'
$t = Get-Site 'nobody' '/x.php'
if ($c -eq '403' -and $t -notmatch 'echo') { Ok "a PHP file of no account is refused (403), not run nor shown" } else { Bad "a PHP file of no account: $c $t" }

# --- what alice's PHP may do
$ans = @{}
(Get-Site 'alice' '/try.php') -split "`n" | ForEach-Object { if ($_ -match '^([a-z-]+)=(.*)$') { $ans[$Matches[1]] = $Matches[2].Trim() } }
$want = [ordered]@{
    'php-read-own' = 'yes'; 'php-read-bob' = 'no'; 'php-read-mysqlpw' = 'no'
    'os-read-own' = 'yes'; 'os-read-bob' = 'no'; 'os-list-bob' = 'no'; 'os-read-mysqlpw' = 'no'; 'os-read-bob-ini' = 'no'
    'os-read-httpdconf' = 'no'; 'os-read-panel' = 'no'; 'os-write-own' = 'yes'; 'os-write-bob' = 'no'; 'os-write-phpdir' = 'no'
    'os-write-windir' = 'no'; 'os-write-zsudo-queue' = 'no'; 'os-stop-apache' = 'no'; 'os-stop-bob-php' = 'no'
    'os-kill-bob-php' = 'no'; 'os-admin' = 'no'
}
$bad = @($want.Keys | Where-Object { $ans[$_] -ne $want[$_] })
if ($bad) { Bad "alice's PHP: $(($bad | ForEach-Object { "$_=$($ans[$_]) (should be $($want[$_]))" }) -join ', ')" }
else { Ok "alice's PHP can not read, list or write bob's files, read the MySQL password, bob's php.ini, httpd.conf or the panel, write PHP's or Windows' folders or zsudo's queue, stop Apache or bob's PHP, kill bob's php-cgi, or act as an administrator" }
$w = Get-Site 'bob' '/who.php'
if ($w -match 'sentoraphp-bob') { Ok "bob.test still answers after alice's tries" } else { Bad "bob.test after alice's tries: $w" }

# --- alice's PHP straight at bob's PHP port
$bp = (Get-Content "$Root\configs\php\accounts\bob.json" -Raw | ConvertFrom-Json).base
$bobWho = "$fw/hostdata/bob/public_html/who.php"
foreach ($q in @("file=$bobWho", "file=$bobWho&pv=1", 'plant=1', 'plant=1&pv=1')) {
    $r = Get-Site 'alice' "/fcgi.php?port=$bp&$q"
    if ($r -match 'sentoraphp-bob|planted ran') { Bad "straight at bob's port ($q): bob's PHP ran it as bob ($r)" } else { Ok "straight at bob's port ($q): refused ($(($r -replace '\s+', ' ').Substring(0, [Math]::Min(90, $r.Length))))" }
}

# --- through Apache: a site's .htaccess and a link into another account's folder
New-Item -ItemType Directory -Force -Path "$Root\hostdata\alice\public_html\asapache" | Out-Null
Set-Content "$Root\hostdata\alice\public_html\asapache\.htaccess" "SetHandler application/x-httpd-php`r`n" -Encoding ASCII
Set-Content "$Root\hostdata\alice\public_html\asapache\w.txt" '<?php echo "ran as ", get_current_user(), " ", getenv("USERNAME");' -Encoding ASCII
$r = Get-Site 'alice' '/asapache/w.txt'
if ($r -match '^ran as') { Bad "a .htaccess made Apache run a file with the panel's own PHP: $r" } else { Ok "a .htaccess can not make Apache run a file with the panel's own PHP ($(Get-Code 'alice' '/asapache/w.txt'))" }
New-Item -ItemType Directory -Force -Path "$Root\hostdata\alice\public_html\ssi" | Out-Null
Set-Content "$Root\hostdata\alice\public_html\ssi\.htaccess" "Options +Includes`r`nAddOutputFilter INCLUDES .html`r`n" -Encoding ASCII
Set-Content "$Root\hostdata\alice\public_html\ssi\t.html" '<!--#exec cmd="whoami" --><!--#include virtual="/secret.txt" -->x' -Encoding ASCII
$r = Get-Site 'alice' '/ssi/t.html'
if ($r -match 'nt service') { Bad "server-side includes ran a command: $r" } else { Ok "a .htaccess can not turn on server-side includes ($(Get-Code 'alice' '/ssi/t.html'))" }
# (alice's PHP makes a junction to bob's website folder; can Apache, which reads every site, be led through it?)
$j = Get-Site 'alice' '/try.php?junction=1'
$r = Get-Site 'alice' '/tobob/secret.txt'
if ($r -match 'the secret of bob') { Bad "a junction alice made leads Apache into bob's folder: $r" } else { Ok "a junction alice made does not lead Apache into bob's folder ($(Get-Code 'alice' '/tobob/secret.txt'); made: $(Test-Path "$Root\hostdata\alice\public_html\tobob"))" }
Set-Content "$Root\hostdata\alice\public_html\.htaccess" "Options +FollowSymLinks`r`n" -Encoding ASCII
$c = Get-Code 'alice' '/tobob/secret.txt'
$r = Get-Site 'alice' '/tobob/secret.txt'
if ($r -match 'the secret of bob') { Bad "with Options +FollowSymLinks in alice's .htaccess, the junction leads Apache into bob's folder" } else { Ok "with Options +FollowSymLinks in alice's .htaccess the junction still does not lead in ($c)" }
Remove-Item "$Root\hostdata\alice\public_html\.htaccess" -Force

# --- three at once; a php-cgi that dies comes back
$t = Measure-Command {
    $ps = 1..3 | ForEach-Object { Start-Process curl.exe -ArgumentList '-s', '--max-time', '60', '-H', '"Host: alice.test"', '-o', 'NUL', 'http://127.0.0.1/slow.php' -NoNewWindow -PassThru }
    $ps | Wait-Process
}
if ($t.TotalSeconds -lt 4) { Ok "three 2-second pages at once on one account: $([math]::Round($t.TotalSeconds, 1)) s" } else { Bad "three 2-second pages at once took $([math]::Round($t.TotalSeconds, 1)) s" }
Get-CimInstance Win32_Process -Filter "Name='php-cgi.exe'" | Where-Object { (Invoke-CimMethod -InputObject $_ -MethodName GetOwner).User -eq 'SentoraPHP-alice' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
Start-Sleep 3
if ((Get-Site 'alice' '/who.php') -match 'sentoraphp-alice') { Ok "alice's PHP is back after its php-cgi were killed" } else { Bad "alice's PHP did not come back" }

# --- list, remove
$l = (& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $tool list 2>&1) -join ' '
if ($l -match 'alice' -and $l -match 'bob' -and $l -match 'Running') { Ok "phpaccount.ps1 list: $($l -replace '\s+', ' ')" } else { Bad "phpaccount.ps1 list: $l" }
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $tool add carol 2>&1 | Out-Null
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $tool remove carol 2>&1 | Out-Null
if (-not (Get-Service 'SentoraPHP-carol' -ErrorAction SilentlyContinue) -and -not (Test-Path "$Root\configs\apache\php-accounts\carol.conf") -and (Test-Path "$Root\hostdata\carol")) { Ok 'phpaccount.ps1 remove: the service and settings go, the files stay' } else { Bad 'phpaccount.ps1 remove left something or took the files' }
foreach ($n in $acc) { if ((Get-Site $n '/who.php') -match "sentoraphp-$n") { Ok "$n.test still works after another account came and went" } else { Bad "$n.test after carol" } }

if ($fails) { throw "$fails check(s) failed" }
