"""Sentora Elk node: the setup script (PowerShell) for one domain controller's Winlogbeat.

winsetup.py NODE-ADDRESS DC-ADDRESS NODE-CA.crt DC.crt DC.key > sentora-dc-ADDRESS.ps1

The script finds winlogbeat.exe (C:\\winlogbeat, or -Folder), writes the certificates (the key readable by SYSTEM and
Administrators only) and winlogbeat.yml for this node, installs and starts the Winlogbeat service, tests the connection
and shows which of the audit settings the dashboards need are off. Written by RusTus, 2026-11-21. GPL-3.0.
"""
import ipaddress, sys

node, dc, ca_f, crt_f, key_f = sys.argv[1:6]
ipaddress.ip_address(node), ipaddress.ip_address(dc)
ca, crt, key = (open(f).read().strip() for f in (ca_f, crt_f, key_f))
for pem in (ca, crt, key):
    assert pem.startswith("-----BEGIN ") and "'@" not in pem

# The events the dashboards use (Winlogbeat takes at most 22 ids and ranges in one list)
IDS = "4624, 4625, 4740, 4767, 4720-4726, 4738, 4781, 4728-4729, 4732-4733, 4756-4757, 4768, 4771, 4776, 1102, 4719, 5136"

YML = r"""# Sentora Elk node: Winlogbeat on this domain controller (%(dc)s), sending to the Elk node %(node)s.
# Made by sentora-elk-node windows %(dc)s; run that again (and its new script) to change it.
winlogbeat.event_logs:
  - name: Security
    ignore_older: 72h
    event_id: %(ids)s
    processors:
      - drop_event:
          when:
            or:
              # (computer accounts, names ending in $: machines, not people)
              - regexp:
                  winlog.event_data.TargetUserName: '\$$'
              # (logons: only at the keyboard, unlock, remote desktop and cached; network logons are every file share)
              - and:
                  - equals.winlog.event_id: "4624"
                  - not:
                      or:
                        - equals.winlog.event_data.LogonType: "2"
                        - equals.winlog.event_data.LogonType: "7"
                        - equals.winlog.event_data.LogonType: "10"
                        - equals.winlog.event_data.LogonType: "11"
              # (NTLM checks: only the failed ones)
              - and:
                  - equals.winlog.event_id: "4776"
                  - equals.winlog.event_data.Status: "0x0"
processors:
  - add_locale: ~
  - drop_fields:
      fields: ["event.original", "winlog.api", "winlog.keywords", "winlog.opcode", "winlog.process", "winlog.activity_id", "winlog.task"]
      ignore_missing: true
output.logstash:
  hosts: ["%(node)s:6518"]
  ssl.enabled: true
  ssl.verification_mode: full
  ssl.supported_protocols: [TLSv1.2, TLSv1.3]
  ssl.certificate_authorities: ['SENTORA_DIR\sentora-elk-ca.crt']
  ssl.certificate: 'SENTORA_DIR\sentora-dc.crt'
  ssl.key: 'SENTORA_DIR\sentora-dc.key'
setup.ilm.enabled: false
setup.template.enabled: false
logging.level: warning
logging.to_files: true
logging.files:
  path: 'C:\ProgramData\winlogbeat\logs'
  keepfiles: 7
""" % {"dc": dc, "node": node, "ids": IDS}

PS = r"""# Sentora Elk node: set up Winlogbeat on this domain controller (%(dc)s) to send its security events to %(node)s.
# Run as an administrator:  powershell -ExecutionPolicy Bypass -File .\sentora-dc-%(dc)s.ps1 [-Folder 'C:\winlogbeat']
# It holds this domain controller's private key: delete this file once it ran.
param([string]$Folder = 'C:\winlogbeat')
$ErrorActionPreference = 'Stop'
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this as an administrator (right-click PowerShell > Run as administrator).' }
$exe = Get-ChildItem -Path $Folder -Filter winlogbeat.exe -Recurse -Depth 2 -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $exe) { throw "winlogbeat.exe is not in $Folder. Unzip Winlogbeat (elastic.co/downloads/beats/winlogbeat, the Windows ZIP) there, or give -Folder." }
$dir = $exe.DirectoryName
$ver = (& $exe.FullName version) -join ' '
Write-Host "Winlogbeat: $ver in $dir"
if ($ver -match 'version (\d+)\.' -and [int]$Matches[1] -lt 8) { throw 'This Winlogbeat is older than 8: take 8 or newer (best the same as the Elk node, 9).' }

if (Get-Service winlogbeat -ErrorAction SilentlyContinue) { Stop-Service winlogbeat -ErrorAction SilentlyContinue }

# the certificates: the node's authority (to check the node), this domain controller's own certificate and key
Set-Content -Path "$dir\sentora-elk-ca.crt" -Encoding ascii -Value @'
%(ca)s
'@
Set-Content -Path "$dir\sentora-dc.crt" -Encoding ascii -Value @'
%(crt)s
'@
Set-Content -Path "$dir\sentora-dc.key" -Encoding ascii -Value @'
%(key)s
'@
& icacls.exe "$dir\sentora-dc.key" /inheritance:r /grant:r '*S-1-5-18:R' '*S-1-5-32-544:R' | Out-Null

$yml = @'
%(yml)s
'@
Set-Content -Path "$dir\winlogbeat.yml" -Encoding ascii -Value $yml.Replace('SENTORA_DIR', $dir)

# the service (Winlogbeat's own script in its folder), started with the settings above
# (its script replaces a service set up before, so the service runs this folder with these settings)
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$dir\install-service-winlogbeat.ps1" | Out-Null
Set-Service winlogbeat -StartupType Automatic
& $exe.FullName test config -c "$dir\winlogbeat.yml" --path.home $dir
& $exe.FullName test output -c "$dir\winlogbeat.yml" --path.home $dir
Start-Service winlogbeat
Write-Host ''
Write-Host "Winlogbeat sends this domain controller's security events to %(node)s (service: $((Get-Service winlogbeat).Status))."

# the audit settings the dashboards need (set them in the Default Domain Controllers Policy if one is off)
Write-Host ''
Write-Host 'Audit settings (Computer Configuration > Policies > Windows Settings > Security Settings > Advanced Audit Policy):'
$need = @('Credential Validation', 'Kerberos Authentication Service', 'User Account Management',
          'Security Group Management', 'Logon', 'Account Lockout', 'Audit Policy Change', 'Directory Service Changes')
$all = & auditpol.exe /get /category:* /r | ConvertFrom-Csv
foreach ($n in $need) {
    $row = $all | Where-Object { $_.Subcategory -eq $n } | Select-Object -First 1
    $set = if ($row) { $row.'Inclusion Setting' } else { '?' }
    $ok = $set -match 'Success'
    Write-Host ('  {0,-36} {1}{2}' -f $n, $set, $(if ($ok) { '' } else { '   <- turn on Success and Failure' }))
}
Write-Host ''
Write-Host "Done. On the Elk node: sentora-elk-node sources. Delete this file now (it holds this DC's key)."
""" % {"dc": dc, "node": node, "ca": ca, "crt": crt, "key": key, "yml": YML.strip()}
sys.stdout.write(PS)
