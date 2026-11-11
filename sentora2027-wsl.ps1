<#
.SYNOPSIS
    Sentora-2027 on Windows: the panel in its own Linux system (WSL 2), installed, started with Windows,
    reachable from the network, backed up and removed with one PowerShell script.

.DESCRIPTION
    Run it in PowerShell as administrator:
        powershell -ExecutionPolicy Bypass -File .\sentora2027-wsl.ps1
    It asks a few questions (each with a default), then:
      1. checks Windows (version, virtualisation, administrator) and WSL (installed, new enough)
      2. says which programs already use Sentora's ports on this PC (IIS, another mail or DNS server)
      3. sets WSL up for a server (.wslconfig: memory, processors, mirrored networking, no idle stop)
      4. installs Debian 13 or Ubuntu 26.04 as its own WSL system "Sentora", where you choose to keep it
      5. switches systemd on inside it, and runs Sentora's normal installer there (the same one as on a
         server: it picks the right installer and checks it against its SHA-256)
      6. opens Sentora's ports in the Windows firewall (only when the panel should be reachable from the
         network), adds the panel's name to this PC's hosts file, and a startup task so Sentora runs
         whenever Windows runs, also when nobody is logged in
      7. asks whether Windows Defender should leave Sentora's disk image alone (the administrator decides)
    Everything it adds to Windows is named "Sentora", so -Uninstall removes exactly that.

    Other commands:
      -Status                  is Sentora running, its address, and has Defender flagged its disk image
      -Backup <folder>         the whole Sentora system in one file (wsl --export); a Windows scheduled
                               task can run it nightly. Sentora's own backups (Admin > Backup Config) work too.
      -Uninstall               removes the Sentora WSL system and everything this script added to Windows
      -Shortcuts               (again) the double-click files, the desktop shortcut and the tray icon

    Note: SELinux and AppArmor do not run inside WSL, and Windows updates restart the PC (and Sentora
    with it). For production hosting a Linux server (or a virtual machine) is the better home.

.NOTES
    Sentora-2027, https://github.com/RusTus/sentora-installers-2027 (GPL-3.0)
    Written by RusTus, 2026-11-10
#>
[CmdletBinding()]
param(
    [ValidateSet('debian13', 'ubuntu26.04')] [string] $Distro,
    [string] $InstallPath,
    [int] $MemoryGB,
    [int] $Processors,
    [string] $PanelName,
    [string] $AdminEmail,
    [ValidateSet('Yes', 'No')] [string] $StartWithWindows,
    [ValidateSet('Network', 'ThisPC')] [string] $Reach,
    [ValidateSet('Yes', 'No')] [string] $DefenderExclusion,
    [switch] $Status,
    [string] $Backup,
    [switch] $Uninstall,
    [switch] $Shortcuts
)

$ErrorActionPreference = 'Stop'
$Name = 'Sentora'
$Release = 'https://github.com/RusTus/sentora-installers-2027/releases/download/current'
# Sentora's ports: web, mail (SMTP, submission, SMTPS, POP3/S, IMAP/S, ManageSieve), FTP and its passive
# range, DNS
$TcpPorts = @(80, 443, 25, 587, 465, 110, 995, 143, 993, 4190, 21, 53)
$UdpPorts = @(53)
$PassivePorts = '60000-65535'
# the Hyper-V firewall's id for WSL (mirrored networking passes through it too)
$WslVmCreatorId = '{40E0AC32-46A5-438A-A0B2-2B479E8F2E90}'
$HostsFile = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
$TaskName = 'Sentora (WSL)'

function Say([string] $Text) { Write-Host "  $Text" }
function Step([string] $Text) { Write-Host ""; Write-Host "-- $Text" -ForegroundColor Cyan }
function Fail([string] $Text) { Write-Host ""; Write-Host "  $Text" -ForegroundColor Red; exit 1 }
function Ask([string] $Question, [string] $Default) {
    $a = Read-Host "  $Question [$Default]"
    if ([string]::IsNullOrWhiteSpace($a)) { return $Default }
    return $a.Trim()
}
function AskChoice([string] $Question, [string[]] $Choices, [string] $Default) {
    while ($true) {
        # (no default: the question is asked until it is answered)
        $a = if ($Default) { Ask "$Question ($($Choices -join '/'))" $Default } else { (Read-Host "  $Question ($($Choices -join '/'))").Trim() }
        foreach ($c in $Choices) { if ($c -ieq $a) { return $c } }
        Say "Please answer one of: $($Choices -join ', ')"
    }
}
# wsl.exe writes UTF-16; read its output as text
function Wsl([string[]] $Arguments) {
    $old = [Console]::OutputEncoding
    try {
        [Console]::OutputEncoding = [System.Text.Encoding]::Unicode
        $out = & wsl.exe @Arguments 2>&1
        return ($out | Out-String)
    } finally { [Console]::OutputEncoding = $old }
}
function SentoraExists() { return (Wsl @('--list', '--quiet')) -match "(?m)^\s*$Name\s*$" }
# A command for root inside Sentora. Windows PowerShell 5.1 does not escape the quotes it passes to
# wsl.exe, and "wsl --" hands the command to Linux's shell to read a second time, so quotes and $ came out
# wrong. So the command travels as base64 (letters and digits only) into a file, and bash runs that file
# (started with --exec: no second reading; its input stays this console, for the installer's questions).
function InLinux([string] $Command) {
    $file = '/root/.sentora-wsl-command'
    $b64 = [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes("rm -f $file`n" + ($Command -replace "`r", '')))
    & wsl.exe -d $Name -u root --exec /bin/sh -c "echo $b64 | base64 -d > $file"
    & wsl.exe -d $Name -u root --exec /bin/bash -l $file
}
# the disk image of the Sentora WSL system (from the registry, where WSL keeps its systems)
function SentoraDiskImage() {
    $key = Get-ChildItem 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Lxss' -ErrorAction SilentlyContinue |
        Where-Object { (Get-ItemProperty $_.PSPath).DistributionName -eq $Name } | Select-Object -First 1
    if (-not $key) { return $null }
    $base = (Get-ItemProperty $key.PSPath).BasePath -replace '^\\\\\?\\', ''
    $vhdx = Join-Path $base 'ext4.vhdx'
    if (Test-Path $vhdx) { return $vhdx }
    return $null
}

# Everything in one place: a copy of this script in Sentora's folder, and a double-click file next to it
# for each command (each asks for administrator rights itself and keeps its window open to read)
$Launchers = [ordered]@{ 'Sentora Status.cmd' = '-Status'; 'Sentora Backup.cmd' = '-Backup \"%~dp0Backups\"'; 'Sentora Uninstall.cmd' = '-Uninstall' }
# The tray icon (written to Sentora's folder as sentora-tray.ps1, started at every user's logon): green when
# the panel answers on this PC (port 443), red when it does not. It only looks; it changes nothing.
$TrayScript = @'
param([string] $PanelName = 'localhost', [string] $Folder = $PSScriptRoot)
Add-Type -AssemblyName System.Windows.Forms, System.Drawing
$created = $false
$mutex = New-Object System.Threading.Mutex($true, 'Local\SentoraTray', [ref] $created)
if (-not $created) { exit }
function Dot([System.Drawing.Color] $Color) {
    $bmp = New-Object System.Drawing.Bitmap 16, 16
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.FillEllipse((New-Object System.Drawing.SolidBrush $Color), 2, 2, 12, 12)
    $g.DrawEllipse((New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(110, 0, 0, 0)), 1), 2, 2, 12, 12)
    $g.Dispose()
    return [System.Drawing.Icon]::FromHandle($bmp.GetHicon())
}
$script:green = Dot ([System.Drawing.Color]::FromArgb(46, 160, 67))
$script:red = Dot ([System.Drawing.Color]::FromArgb(207, 34, 46))
$script:tray = New-Object System.Windows.Forms.NotifyIcon
$script:panel = "https://$PanelName"
$script:folder = $Folder
$menu = New-Object System.Windows.Forms.ContextMenuStrip
$menu.Items.Add('Open the panel').add_Click({ Start-Process $script:panel })
[void] $menu.Items.Add('-')
foreach ($n in 'Status', 'Backup', 'Uninstall') {
    $item = $menu.Items.Add($n)
    $item.Tag = Join-Path $Folder "Sentora $n.cmd"
    $item.add_Click({ param($s) if (Test-Path -LiteralPath $s.Tag) { Start-Process -FilePath $s.Tag } })
}
[void] $menu.Items.Add('-')
$menu.Items.Add('Hide this icon (until the next logon)').add_Click({ $script:tray.Visible = $false; [System.Windows.Forms.Application]::Exit() })
$script:tray.ContextMenuStrip = $menu
# one click (left button) opens the panel's login page; the right button opens the menu
$script:tray.add_MouseClick({ param($s, $e) if ($e.Button -eq [System.Windows.Forms.MouseButtons]::Left) { Start-Process $script:panel } })
function PanelUp {
    $c = New-Object System.Net.Sockets.TcpClient
    try { return ($c.ConnectAsync('127.0.0.1', 443).Wait(1500) -and $c.Connected) } catch { return $false } finally { $c.Close() }
}
$script:last = $null
function Check {
    $up = PanelUp
    if ($up) { $script:tray.Icon = $script:green; $script:tray.Text = 'Sentora: the panel is up' }
    else { $script:tray.Icon = $script:red; $script:tray.Text = 'Sentora: the panel does not answer' }
    if ($null -ne $script:last -and $up -ne $script:last) {
        $msg = if ($up) { 'The panel is up again.' } else { 'The panel stopped answering.' }
        $script:tray.ShowBalloonTip(5000, 'Sentora', $msg, [System.Windows.Forms.ToolTipIcon]::Info)
    }
    $script:last = $up
}
$script:tray.Visible = $true
Check
$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 30000
$timer.add_Tick({ Check })
$timer.Start()
[System.Windows.Forms.Application]::Run()
$script:tray.Dispose()
'@
$TrayLink = Join-Path ([Environment]::GetFolderPath('CommonStartup')) 'Sentora tray.lnk'
function WriteTray([string] $Folder, [string] $Panel) {
    $file = Join-Path $Folder 'sentora-tray.ps1'
    Set-Content -LiteralPath $file -Value $TrayScript -Encoding UTF8
    # every user's logon starts it without a window (conhost --headless: no console flashing up)
    $ws = New-Object -ComObject WScript.Shell
    $lnk = $ws.CreateShortcut($TrayLink)
    $lnk.TargetPath = Join-Path $env:WINDIR 'System32\conhost.exe'
    $lnk.Arguments = "--headless powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$file`" -PanelName $Panel"
    $lnk.Save()
    # and now, for whoever is logged in: through Explorer, so it runs as that user, not as administrator
    Start-Process explorer.exe -ArgumentList "`"$TrayLink`""
}
function RemoveTray([string] $Folder) {
    Remove-Item -LiteralPath $TrayLink -ErrorAction SilentlyContinue
    Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like '*sentora-tray.ps1*' } | ForEach-Object { Invoke-CimMethod -InputObject $_ -MethodName Terminate | Out-Null }
    Remove-Item -LiteralPath (Join-Path $Folder 'sentora-tray.ps1') -ErrorAction SilentlyContinue
}

# the panel's login page on the desktop of every user of this PC (Windows' shared Public Desktop)
$PanelShortcut = Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) 'Sentora Panel.url'
function WritePanelShortcut([string] $Url) {
    Set-Content -LiteralPath $PanelShortcut -Value @('[InternetShortcut]', "URL=$Url") -Encoding ASCII
}
function WriteLaunchers([string] $Folder) {
    $copy = Join-Path $Folder 'sentora2027-wsl.ps1'
    if ($PSCommandPath -ne $copy) { Copy-Item -LiteralPath $PSCommandPath -Destination $copy -Force }
    foreach ($f in $Launchers.Keys) {
        $line = '@powershell -NoProfile -Command "Start-Process powershell -Verb RunAs -ArgumentList ''-NoExit -ExecutionPolicy Bypass -File \"%~dp0sentora2027-wsl.ps1\" ' + $Launchers[$f] + '''"'
        Set-Content -LiteralPath (Join-Path $Folder $f) -Value $line -Encoding ASCII
    }
}

# --- administrator ---------------------------------------------------------------------------------
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Fail "Run PowerShell as administrator (right-click > Run as administrator), then run this script again." }

# --- -Status -----------------------------------------------------------------------------------------
if ($Status) {
    Step "Sentora on this PC"
    if (-not (SentoraExists)) { Say "Sentora is not installed here."; exit 0 }
    $running = (Wsl @('--list', '--running', '--quiet')) -match "(?m)^\s*$Name\s*$"
    Say ("The Sentora system: " + $(if ($running) { 'running' } else { 'stopped (it starts with Windows, or: wsl -d Sentora)' }))
    if ($running) {
        $state = (InLinux 'systemctl is-system-running 2>/dev/null; hostname -I 2>/dev/null' | Out-String).Trim() -split "`n"
        Say "Its services: $($state[0])"
        if ($state.Count -gt 1) { Say "Its address: $($state[1].Trim())" }
    }
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Say ("Starts with Windows: " + $(if ($task) { 'yes' } else { 'no' }))
    $vhdx = SentoraDiskImage
    if ($vhdx) {
        Say "Disk image: $vhdx ($([math]::Round((Get-Item $vhdx).Length / 1GB, 1)) GB)"
        $pref = Get-MpPreference -ErrorAction SilentlyContinue
        if ($pref) {
            Say ("Windows Defender leaves it alone: " + $(if ($pref.ExclusionPath -contains $vhdx) { 'yes (excluded)' } else { 'no (it is scanned)' }))
            # Windows does not say much when Defender acts: its own history is checked here
            $hits = Get-MpThreatDetection -ErrorAction SilentlyContinue | Where-Object {
                ($_.Resources -join ' ') -match [regex]::Escape((Split-Path $vhdx -Parent)) }
            if ($hits) {
                Write-Host "  Windows Defender has acted on Sentora's files:" -ForegroundColor Yellow
                $hits | ForEach-Object { Say "  $($_.InitialDetectionTime)  $($_.ActionSuccess)  $($_.Resources -join ', ')" }
                Say "  (Windows Security > Virus & threat protection > Protection history: restore or allow there)"
            } else {
                Say "Windows Defender has not acted on Sentora's files."
            }
        }
    }
    exit 0
}

# --- -Backup -----------------------------------------------------------------------------------------
if ($Backup) {
    if (-not (SentoraExists)) { Fail "Sentora is not installed here." }
    if (-not (Test-Path $Backup)) { New-Item -ItemType Directory -Path $Backup | Out-Null }
    $file = Join-Path $Backup ("sentora-wsl-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.tar')
    Step "Backing up the whole Sentora system to $file"
    Say "(Sentora's services are stopped for a moment, so the copy is consistent)"
    & wsl.exe --terminate $Name | Out-Null
    & wsl.exe --export $Name $file
    if ($LASTEXITCODE -ne 0) { Fail "The backup did not work (wsl --export)." }
    Start-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Say "Done: $file ($([math]::Round((Get-Item $file).Length / 1GB, 1)) GB). To restore it: wsl --import Sentora <folder> `"$file`""
    exit 0
}

# --- -Shortcuts: the double-click files, the desktop shortcut and the tray icon for an installed Sentora ---
if ($Shortcuts) {
    if (-not (SentoraExists)) { Fail "Sentora is not installed here." }
    $vhdx = SentoraDiskImage
    $folder = if ($vhdx) { Split-Path $vhdx } else { Fail "Sentora's folder was not found." }
    $line = Get-Content $HostsFile -ErrorAction SilentlyContinue | Where-Object { $_ -match '#\s*Sentora$' } | Select-Object -First 1
    $panel = if ($line) { ($line -split '\s+')[1] } else { Ask "The panel's name" 'localhost' }
    WriteLaunchers $folder
    WritePanelShortcut "https://$panel"
    WriteTray $folder $panel
    Say "in ${folder}: Sentora Status, Sentora Backup, Sentora Uninstall; on every desktop: Sentora Panel; and the tray icon"
    exit 0
}

# --- -Uninstall --------------------------------------------------------------------------------------
if ($Uninstall) {
    Step "Removing Sentora from this PC"
    Write-Host "  This deletes the Sentora system with all its websites, mail and databases." -ForegroundColor Yellow
    if ((Ask "Type REMOVE to go on" "no") -cne 'REMOVE') { Say "Nothing was removed."; exit 0 }
    $vhdx = SentoraDiskImage
    $folder = if ($vhdx) { Split-Path $vhdx } else { Split-Path $PSCommandPath }
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    if (SentoraExists) { & wsl.exe --unregister $Name | Out-Null; Say "the Sentora WSL system: removed" }
    Get-NetFirewallRule -DisplayName 'Sentora *' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    Get-NetFirewallHyperVRule -ErrorAction SilentlyContinue | Where-Object { $_.Name -like 'Sentora-*' } |
        ForEach-Object { Remove-NetFirewallHyperVRule -Name $_.Name }
    Say "firewall rules named Sentora: removed"
    if ($vhdx) { Remove-MpPreference -ExclusionPath $vhdx -ErrorAction SilentlyContinue }
    if (Test-Path $HostsFile) {
        $lines = Get-Content $HostsFile | Where-Object { $_ -notmatch '#\s*Sentora$' }
        Set-Content -Path $HostsFile -Value $lines -Encoding ASCII
        Say "the panel's line in the hosts file: removed"
    }
    RemoveTray $folder
    Remove-Item -LiteralPath $PanelShortcut -ErrorAction SilentlyContinue
    Say "the Sentora Panel shortcut on the desktop: removed"
    # the double-click files and the script's copy in Sentora's folder (backups made there stay)
    foreach ($f in @($Launchers.Keys) + 'sentora2027-wsl.ps1') { Remove-Item -LiteralPath (Join-Path $folder $f) -ErrorAction SilentlyContinue }
    if ((Test-Path $folder) -and -not (Get-ChildItem -LiteralPath $folder -Force)) { Remove-Item -LiteralPath $folder }
    Say "Sentora's double-click files in ${folder}: removed (a Backups folder there stays)"
    Say "The startup task and the Defender exclusion (if any) are removed too. WSL itself stays installed;"
    Say "the settings this script put in $env:USERPROFILE\.wslconfig stay (a copy from before: .wslconfig.sentora-orig)."
    exit 0
}

# --- install -------------------------------------------------------------------------------------------
Write-Host ""
Write-Host "  Sentora-2027 for Windows (WSL 2)  -  Installer" -ForegroundColor White -BackgroundColor DarkBlue
Say "SELinux and AppArmor do not run inside WSL, and Windows updates restart the PC (and Sentora with it):"
Say "for production hosting a Linux server or a virtual machine is the better home."

Step "Checking Windows"
$build = [int](Get-CimInstance Win32_OperatingSystem).BuildNumber
# mirrored networking (the panel answers on this PC's own address) needs Windows 11 22H2 or Windows Server 2025
if ($build -lt 22621) { Fail "Windows build $build is too old: Windows 11 (22H2 or newer) or Windows Server 2025 is needed." }
Say "Windows build ${build}: OK"
$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
$hv = (Get-CimInstance Win32_ComputerSystem).HypervisorPresent
if (-not $hv -and -not $cpu.VirtualizationFirmwareEnabled) {
    Fail "Virtualisation is switched off in this PC's firmware (BIOS/UEFI): switch it on (Intel VT-x or AMD-V), then run this again."
}
Say "virtualisation: on"

Step "Checking WSL"
& wsl.exe --status *> $null
if ($LASTEXITCODE -ne 0) {
    Say "WSL is not installed: installing it (Windows may ask for a restart; then run this script again)"
    & wsl.exe --install --no-distribution
    Fail "WSL was installed. Restart Windows, then run this script again."
}
& wsl.exe --update | Out-Null
$wslVersion = ((Wsl @('--version')) -split "`n" | Select-Object -First 1) -replace '[^0-9.]', ''
Say "WSL $wslVersion"
if ([version]($wslVersion -replace '^(\d+\.\d+\.\d+).*', '$1') -lt [version]'2.4.4') {
    Fail "WSL $wslVersion is too old (2.4.4 or newer is needed): run 'wsl --update', then this script again."
}
if (SentoraExists) { Fail "A WSL system named Sentora is already installed (remove it first: this script with -Uninstall)." }

Step "Questions (Enter keeps the answer in brackets)"
if (-not $Distro) { $Distro = AskChoice "Which Linux" @('debian13', 'ubuntu26.04') 'debian13' }
if (-not $InstallPath) {
    $drive = (Get-PSDrive -PSProvider FileSystem | Where-Object { $_.Free -gt 0 } | Sort-Object Free -Descending | Select-Object -First 1).Root
    $InstallPath = Ask "Where to keep Sentora (its disk image grows with websites and mail)" (Join-Path $drive 'Sentora')
}
$totalGB = [math]::Floor((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB)
if (-not $MemoryGB) { $MemoryGB = [int](Ask "Memory for Sentora in GB (this PC has $totalGB; ClamAV needs 2 or more)" ([math]::Max(4, [math]::Floor($totalGB / 2)))) }
$cores = [Environment]::ProcessorCount
if (-not $Processors) { $Processors = [int](Ask "Processors for Sentora (this PC has $cores)" ([math]::Max(2, [math]::Floor($cores / 2)))) }
if (-not $PanelName) { $PanelName = Ask "The panel's name (a host name, e.g. panel.example.com)" ("panel." + ($env:USERDNSDOMAIN, 'example.com' | Where-Object { $_ } | Select-Object -First 1).ToLower()) }
if ($PanelName -notmatch '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$') { Fail "'$PanelName' is not a host name." }
if (-not $AdminEmail) { $AdminEmail = Ask "The administrator's email address" "admin@$(($PanelName -split '\.', 2)[1])" }
if (-not $StartWithWindows) { $StartWithWindows = AskChoice "Start Sentora with Windows, also when nobody is logged in" @('Yes', 'No') 'Yes' }
if (-not $Reach) { $Reach = AskChoice "Reachable from the network, or from this PC only" @('Network', 'ThisPC') 'Network' }
if (-not $DefenderExclusion) {
    Write-Host ""
    Say "Windows Defender and Sentora's disk image:"
    Say "  Everything Sentora keeps (websites, mail, databases) is inside one disk image file. Defender can"
    Say "  not look at the files inside it, only at the image as a whole; if it ever flags or locks that file,"
    Say "  the whole server stops, and Windows does not always say so clearly (this script's -Status checks it)."
    Say "  Leaving the image alone (only that one file) avoids that; Sentora's own ClamAV still checks mail."
    Say "  Scanning it keeps Windows' usual protection, at that risk and a little speed."
    $DefenderExclusion = AskChoice "Should Windows Defender leave Sentora's disk image alone (your decision)" @('Yes', 'No') ''
}

Step "Programs already using Sentora's ports on this PC"
$busy = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $TcpPorts -contains $_.LocalPort } |
    Sort-Object LocalPort -Unique
if ($busy) {
    foreach ($b in $busy) {
        $p = Get-Process -Id $b.OwningProcess -ErrorAction SilentlyContinue
        Write-Host ("  port {0}: {1}" -f $b.LocalPort, $(if ($p) { $p.ProcessName } else { "process $($b.OwningProcess)" })) -ForegroundColor Yellow
    }
    Say "Sentora can not use a port another program has (stop that program, e.g. IIS for 80/443, or its service)."
    if ((AskChoice "Go on anyway" @('Yes', 'No') 'No') -ne 'Yes') { Fail "Nothing was changed." }
} else {
    Say "none: all free"
}

Step "WSL settings for a server ($env:USERPROFILE\.wslconfig)"
$cfg = Join-Path $env:USERPROFILE '.wslconfig'
if ((Test-Path $cfg) -and -not (Test-Path "$cfg.sentora-orig")) { Copy-Item $cfg "$cfg.sentora-orig" }
$want = [ordered]@{
    'memory'           = "${MemoryGB}GB"
    'processors'       = "$Processors"
    'networkingMode'   = 'mirrored'
    'vmIdleTimeout'    = '-1'
}
$text = if (Test-Path $cfg) { Get-Content $cfg -Raw } else { '' }
if ($text -notmatch '(?m)^\[wsl2\]') { $text = "[wsl2]`r`n" + $text }
foreach ($k in $want.Keys) {
    if ($text -match "(?m)^\s*$k\s*=") { $text = $text -replace "(?m)^\s*$k\s*=.*$", "$k=$($want[$k])" }
    else { $text = $text -replace '(?m)^\[wsl2\]\s*$', "[wsl2]`r`n$k=$($want[$k])" }
}
if ($text -notmatch '(?m)^\[experimental\]') { $text += "`r`n[experimental]`r`nhostAddressLoopback=true`r`n" }
elseif ($text -notmatch '(?m)^\s*hostAddressLoopback\s*=') { $text = $text -replace '(?m)^\[experimental\]\s*$', "[experimental]`r`nhostAddressLoopback=true" }
Set-Content -Path $cfg -Value $text -Encoding ASCII
Say "memory $MemoryGB GB, $Processors processors, mirrored networking, no stop when idle (a copy from before: .wslconfig.sentora-orig)"
& wsl.exe --shutdown

Step "Installing $Distro as the WSL system Sentora (in $InstallPath)"
$online = Wsl @('--list', '--online')
$image = if ($Distro -eq 'debian13') { 'Debian' } else { 'Ubuntu-26.04' }
if ($online -notmatch "(?m)^\s*$([regex]::Escape($image))\s") { Fail "WSL does not offer $image here (wsl --list --online)." }
New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null
& wsl.exe --install $image --name $Name --location $InstallPath --no-launch
if ($LASTEXITCODE -ne 0 -or -not (SentoraExists)) { Fail "WSL could not install $image (wsl --install $image --name $Name)." }
$os = (InLinux '. /etc/os-release; echo "$ID $VERSION_ID"' | Out-String).Trim()
Say "inside: $os"
if (($Distro -eq 'debian13' -and $os -notmatch '^debian 13') -or ($Distro -eq 'ubuntu26.04' -and $os -notmatch '^ubuntu 26\.04')) {
    Fail "WSL installed '$os', not the $Distro Sentora is made for: remove it (wsl --unregister Sentora) and try again later."
}

Step "systemd inside Sentora (its services need it)"
InLinux "printf '[boot]\nsystemd=true\n\n[user]\ndefault=root\n\n[network]\nhostname=$PanelName\ngenerateHosts=true\n' > /etc/wsl.conf"
& wsl.exe --terminate $Name | Out-Null
InLinux 'for i in $(seq 1 60); do s=$(systemctl is-system-running 2>/dev/null); case "$s" in running|degraded) echo "systemd: $s"; exit 0 ;; esac; sleep 2; done; echo "systemd did not start"; exit 1'
if ($LASTEXITCODE -ne 0) { Fail "systemd did not start inside Sentora (wsl -d Sentora, then: systemctl status)." }

Step "Sentora's installer (inside Linux; it asks the rest of its questions there)"
# the installer takes the panel's name and address together: the address the internet sees (as a server's
# install suggests) when Sentora is reached from the network, this PC's own address when only from here
$ipArg = if ($Reach -eq 'Network') { 'public' } else { 'local' }
$tz = (Get-TimeZone).Id
# (Windows' time zone as Linux names it: PowerShell 7 knows the conversion; otherwise the installer asks)
$tzArg = ''
if ($PSVersionTable.PSVersion.Major -ge 7) {
    $out = $null
    if ([System.TimeZoneInfo]::TryConvertWindowsIdToIanaId($tz, [ref]$out)) { $tzArg = "-t $out" }
}
InLinux "apt-get -qq update >/dev/null && apt-get -qq -y install wget ca-certificates >/dev/null; cd /root && wget -q -O sentora2027-install $Release/sentora2027-install && ADMIN_EMAIL='$AdminEmail' bash sentora2027-install -d $PanelName -i $ipArg $tzArg"
# finished = the panel's settings are there and the installer removed its "still installing" mark (it
# does that at its very end; a Ctrl+C or a failed step leaves it)
$installed = (InLinux 'test -f /etc/sentora/panel/cnf/db.php && test ! -e /etc/sentora/.installing && echo yes' | Out-String).Trim()
if ($installed -ne 'yes') {
    Fail "Sentora's installer did not finish (stopped, or a step failed; its log is in /root inside Sentora: wsl -d Sentora). Start again from a clean state: this script with -Uninstall, then run it again."
}
# (the installer may have restarted Linux at its end: it starts again here)
& wsl.exe --terminate $Name | Out-Null

Step "Windows: firewall, hosts file, startup, Defender"
if ($Reach -eq 'Network') {
    New-NetFirewallRule -DisplayName 'Sentora TCP' -Direction Inbound -Protocol TCP -LocalPort $TcpPorts -Action Allow -Profile Any | Out-Null
    New-NetFirewallRule -DisplayName 'Sentora UDP' -Direction Inbound -Protocol UDP -LocalPort $UdpPorts -Action Allow -Profile Any | Out-Null
    New-NetFirewallRule -DisplayName 'Sentora FTP passive' -Direction Inbound -Protocol TCP -LocalPort $PassivePorts -Action Allow -Profile Any | Out-Null
    # mirrored networking passes through the Hyper-V firewall too
    $i = 0
    foreach ($p in $TcpPorts + @($PassivePorts)) {
        $i++; New-NetFirewallHyperVRule -Name "Sentora-tcp-$i" -DisplayName "Sentora TCP $p" -Direction Inbound -VMCreatorId $WslVmCreatorId -Protocol TCP -LocalPorts $p -Action Allow | Out-Null
    }
    New-NetFirewallHyperVRule -Name 'Sentora-udp-53' -DisplayName 'Sentora UDP 53' -Direction Inbound -VMCreatorId $WslVmCreatorId -Protocol UDP -LocalPorts 53 -Action Allow | Out-Null
    Say "firewall: Sentora's ports open (rules named Sentora)"
} else {
    Say "firewall: unchanged (Sentora answers on this PC only)"
}
$lines = @(Get-Content $HostsFile | Where-Object { $_ -notmatch '#\s*Sentora$' }) + "127.0.0.1 $PanelName # Sentora"
Set-Content -Path $HostsFile -Value $lines -Encoding ASCII
Say "hosts file: $PanelName points at this PC"
if ($StartWithWindows -eq 'Yes') {
    # a WSL system runs while a program in it is attached: the task keeps one attached from startup on
    $action = New-ScheduledTaskAction -Execute 'wsl.exe' -Argument "-d $Name -u root --exec /bin/sleep infinity"
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    Start-ScheduledTask -TaskName $TaskName
    Say "startup: Sentora starts with Windows (task '$TaskName')"
} else {
    & wsl.exe -d $Name -u root --exec /bin/true
    Say "startup: not with Windows (start it with: wsl -d Sentora)"
}
$vhdx = SentoraDiskImage
if ($DefenderExclusion -eq 'Yes' -and $vhdx) {
    Add-MpPreference -ExclusionPath $vhdx
    Say "Windows Defender: leaves only $vhdx alone"
} else {
    Say "Windows Defender: unchanged (it scans Sentora's disk image; -Status shows whether it ever acted on it)"
}

WriteLaunchers $InstallPath
WritePanelShortcut "https://$PanelName"
WriteTray $InstallPath $PanelName

# the last thing on screen: how to log in (the installer's own summary above has scrolled away by now)
$zpw = (InLinux "sed -n 's/^zadmin Password *: *//p' /root/passwords.txt" | Out-String).Trim()
Write-Host ""
Write-Host "  Sentora is ready                                                      " -ForegroundColor White -BackgroundColor DarkGreen
Write-Host ""
Write-Host ("  {0,-12}" -f 'Panel') -NoNewline; Write-Host "https://$PanelName" -ForegroundColor White
Write-Host ("  {0,-12}" -f 'Username') -NoNewline; Write-Host 'zadmin' -ForegroundColor White
Write-Host ("  {0,-12}" -f 'Password') -NoNewline; Write-Host $zpw -ForegroundColor Yellow
Say ""
Say "All the passwords this install made (MySQL, Matomo):  wsl -d Sentora -- cat /root/passwords.txt"
Say "Check it:   wsl -d Sentora -- sentora-post-install-check    then    wsl -d Sentora -- sentora-smoke-test"
Say "On every user's desktop: Sentora Panel (the login page)"
Say "In the taskbar's tray: a dot, green while the panel answers (right-click: Status, Backup, Uninstall)"
Say "In ${InstallPath}:  Sentora Status, Sentora Backup, Sentora Uninstall (double-click)"
Say "(No restart needed: Sentora already runs, and starts again with Windows.)"
Start-Process "https://$PanelName"
