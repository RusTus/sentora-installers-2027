# Panel Update on Windows (install-test.yml): the panel's own page asks GitHub (Check now), then updates the server
# (Update now, with zadmin's password), as an administrator would. The page asks the service SentoraZsudo, which starts
# the tasks 'Sentora update check' and 'Sentora update' (sentora-update.ps1 as SYSTEM); the page shows the steps the
# update writes in the panel's state folder (data\sentora\update). An update stops SentoraZsudo and Apache while it
# works, so it must run apart from them, and the page must say how it ended once the panel answers again.
$ErrorActionPreference = 'Stop'
$Root = 'C:\zpanel'
$Upd = "$Root\data\sentora\update"
function Ok($t) { Write-Host "ok   $t" }

foreach ($t in @('Sentora update check', 'Sentora update')) {
    if (-not (Get-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue)) { throw "the task '$t' is not there" }
}
Ok "the tasks 'Sentora update check' (every hour) and 'Sentora update' (for the panel) are there"
# only root's jobs write there; Apache's account reads
$acl = (icacls $Upd) -join ' '
if ($acl -notmatch 'Apache2:\(OI\)\(CI\)\(RX\)' -or $acl -match 'Apache2:\(OI\)\(CI\)\((M|F|W)') { throw "the update folder's rights: $acl" }
Ok 'data\sentora\update: Apache may read it, not write it'

$pw = (Get-Content "$Root\configs\panel\zadmin-password.txt" -Raw).Trim()
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
function Page($url, $body) {
    if ($body) { return Invoke-WebRequest "http://127.0.0.1$url" -WebSession $s -Method Post -Body $body -UseBasicParsing -TimeoutSec 60 }
    return Invoke-WebRequest "http://127.0.0.1$url" -WebSession $s -UseBasicParsing -TimeoutSec 60
}
function Token($html) { if ($html -match 'name="csfr_token" value="([^"]+)"') { return $Matches[1] }; throw 'no form token on the page' }
$p = Page '/' $null
$p = Page '/' @{ inUsername = 'zadmin'; inPassword = $pw; inSessionSecurity = 'on'; csfr_token = (Token $p.Content); sublogin2 = 'LogIn' }
if ($p.Content -notmatch '\?module=packages') { throw 'zadmin could not log in' }

$p = Page '/?module=panel_update' $null
if ($p.Content -match 'Unable to find requested module|Fatal error|Uncaught|Warning:' -or $p.Content -notmatch 'Update the panel') { throw 'the Panel Update page does not load' }
if ($p.Content -match 'systemctl|sentora-update --rollback') { throw 'the page shows Linux commands on Windows' }
if ($p.Content -notmatch [regex]::Escape("$Root\bin\sentora-update.ps1")) { throw 'the page does not name sentora-update.ps1' }
Ok 'the Panel Update page loads (no Linux commands; it names sentora-update.ps1)'

# --- Check now: the page asks, the task answers within a minute
Remove-Item "$Upd\available" -ErrorAction SilentlyContinue
$p = Page '/?module=panel_update&action=CheckNow' @{ csfr_token = (Token $p.Content) }
$a = ''
for ($i = 0; $i -lt 45 -and $a -eq ''; $i++) { Start-Sleep 2; $a = "$(Get-Content "$Upd\available" -Raw -ErrorAction SilentlyContinue)".Trim() }
if ($a -notmatch '^\d+ available 2027\.\d+ \d{4}-\d{2}-\d{2} RusTus/sentora-installers-2027$') { throw "Check now: the answer is '$a'" }
if (-not (Test-Path "$Root\logs\update-available.txt")) { throw 'Check now: logs\update-available.txt was not written' }
$new = ($a -split ' ')[2]
$p = Page '/?module=panel_update' $null
if ($p.Content -notmatch 'Update available' -or $p.Content -notmatch [regex]::Escape($new)) { throw 'the page does not say an update is available' }
Ok "Check now: the page asked, the task answered ($a), the page says Sentora $new is available"

# --- a wrong password changes nothing
$p = Page '/?module=panel_update&action=UpdatePanel' @{ csfr_token = (Token $p.Content); inPassword = 'not-the-password' }
Start-Sleep 5
if (Test-Path "$Upd\status") { throw 'a wrong password started an update' }
Ok 'Update now with a wrong password: nothing started'

# --- Update now: the page asks, the task updates; the page shows its steps while it answers
$asked = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$p = Page '/?module=panel_update' $null
$p = Page '/?module=panel_update&action=UpdatePanel' @{ csfr_token = (Token $p.Content); inPassword = $pw }
$seen = @{}
$end = ''
for ($i = 0; $i -lt 300 -and $end -eq ''; $i++) {
    Start-Sleep 6
    $st = "$(Get-Content "$Upd\status" -Raw -ErrorAction SilentlyContinue)"
    if ($st -match '(?m)^(done|fail)\t') { $end = $Matches[1] }
    try {
        $j = (Invoke-WebRequest 'http://127.0.0.1/modules/panel_update/code/status.php' -WebSession $s -UseBasicParsing -TimeoutSec 15).Content | ConvertFrom-Json
        if ($j.run -match 'Download the release') { $seen['page'] = $true }
    } catch { $seen['down'] = $true }
}
Write-Host '---- the update''s status file'
Get-Content "$Upd\status" -ErrorAction SilentlyContinue
if ($end -eq '') { throw 'the update did not finish within 30 minutes' }
if ($st -notmatch "(?m)^start\tupdate\t(\d+)\tpanel" -or [int64]$Matches[1] -lt $asked - 5) { throw 'the status file does not say the panel started it' }
if ($end -ne 'done' -or $st -notmatch "(?m)^done\tok\t") { throw "the update did not end well: $(($st -split "`n" | Where-Object { $_ -match '^fail' }) -join ' ')" }
if (([regex]::Matches($st, "(?m)^end\tok\t")).Count -ne 5) { throw 'not every step of the update says ok' }
if (-not $seen['page']) { throw 'the page never showed the update''s steps while it ran' }
Ok "Update now: started from the page, run by the task 'Sentora update', 5 steps ok, the page showed them while it answered$(if ($seen['down']) { ' (and was away for a while, as expected)' })"
if ("$(Get-Content "$Upd\installed" -Raw -ErrorAction SilentlyContinue)" -notmatch "^sha256:[0-9a-f]{64} $([regex]::Escape($new)), \d{4}-\d{2}-\d{2} \d{2}:\d{2}") { throw "data\sentora\update\installed: $(Get-Content "$Upd\installed" -Raw -ErrorAction SilentlyContinue)" }
if ("$(Get-Content "$Upd\available" -Raw)".Trim() -notmatch '^\d+ current$') { throw 'the answer was not set to current after the update' }
Ok "the update recorded Sentora $new as installed and up to date"
