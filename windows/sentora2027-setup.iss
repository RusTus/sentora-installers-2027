; Sentora 2027 for Windows: Sentora2027Setup.exe, built with Inno Setup by .github/workflows/windows-setup.yml
; (every release; Actions > Windows setup builds it alone). It is only a friendly front for the same
; PowerShell script as "powershell -File sentora2027-wsl.ps1": it puts the script in Sentora's folder
; (C:\Sentora), runs it in its own window (where it asks its questions), and registers Sentora 2027 in
; Settings > Apps and the Start menu. Uninstalling there runs the script's -Uninstall.
; Not signed: Windows SmartScreen shows "Windows protected your PC" once (More info > Run anyway).

#ifndef AppVersion
  #define AppVersion "2027"
#endif
#define AppName "Sentora 2027"

[Setup]
AppId={{6E2B7C41-0D5A-4C2F-9B8E-2027C3E7A915}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} ({#AppVersion})
AppPublisher=RusTus
AppPublisherURL=https://github.com/RusTus/sentora-installers-2027
AppSupportURL=https://github.com/RusTus/sentora-installers-2027
DefaultDirName={sd}\Sentora
DirExistsWarning=no
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
; Windows 11 22H2 (build 22621) or newer, or Windows Server 2025: mirrored networking needs it
MinVersion=10.0.22621
LicenseFile=..\LICENSE
OutputDir=output
OutputBaseFilename=Sentora2027Setup
SetupIconFile=sentora.ico
UninstallDisplayIcon={app}\sentora.ico
UninstallDisplayName={#AppName}
WizardStyle=modern
WizardSmallImageFile=sentora-small.bmp
Compression=lzma2
SolidCompression=yes

[Messages]
FinishedLabel=Sentora 2027 is set up.%n%nIts own window showed the panel's address and login. The desktop has a Sentora Panel shortcut, and the Start menu has Sentora Status and Sentora Backup.%n%nIf that window asked you to restart Windows (the first time WSL is installed), restart, then run Sentora2027Setup.exe again.

[Files]
Source: "..\sentora2027-wsl.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "sentora.ico"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{group}\Sentora Status"; Filename: "{app}\Sentora Status.cmd"; IconFilename: "{app}\sentora.ico"
Name: "{group}\Sentora Backup"; Filename: "{app}\Sentora Backup.cmd"; IconFilename: "{app}\sentora.ico"
Name: "{group}\Uninstall Sentora 2027"; Filename: "{uninstallexe}"

[Run]
; the script in its own window: it checks Windows and WSL, asks its questions, installs, and waits for
; Enter at the end so its summary (the panel's address and login) can be read; run again on a PC where
; Sentora is installed, it sets up the shortcuts again
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\sentora2027-wsl.ps1"" -InstallPath ""{app}"" -FromSetup"; StatusMsg: "Installing Sentora in its own window (it asks a few questions there)..."; Flags: waituntilterminated

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\sentora2027-wsl.ps1"" -Uninstall -Yes"; RunOnceId: "RemoveSentora"; Flags: waituntilterminated

[Code]
// Uninstalling deletes the websites, mail and databases: say so plainly, and allow a way back
function InitializeUninstall(): Boolean;
begin
  Result := MsgBox('This removes Sentora 2027 from this PC, with all its websites, mail and databases.' + #13#10#13#10 +
    'If you need them, make a backup first (Start menu: Sentora Backup); backups in C:\Sentora\Backups are kept.' + #13#10#13#10 +
    'Remove Sentora 2027 now?', mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES;
end;
