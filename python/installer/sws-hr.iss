; Per-user installer for the SWS HR desktop app (no administrator rights needed).
; Built by ..\build-desktop.bat:  ISCC /DAppVersion=1.1.0 installer\sws-hr.iss
#ifndef AppVersion
  #define AppVersion "1.0.0"
#endif

[Setup]
AppId={{6E7C2F4A-9B1D-4C55-8E0A-5B2D1F3A7C11}
AppName=SWS HR
AppVersion={#AppVersion}
AppPublisher=SWS
DefaultDirName={localappdata}\Programs\SWS HR
DefaultGroupName=SWS HR
PrivilegesRequired=lowest
DisableProgramGroupPage=yes
OutputDir=..\dist
OutputBaseFilename=SWS-HR-Setup-{#AppVersion}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
SetupIconFile=..\sws_desktop\assets\icon.ico
UninstallDisplayIcon={app}\SWS HR.exe
; Updates close the running app first and start it again afterwards.
CloseApplications=force
RestartApplications=no

[Files]
Source: "..\dist\SWS HR\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{userprograms}\SWS HR"; Filename: "{app}\SWS HR.exe"
Name: "{userdesktop}\SWS HR"; Filename: "{app}\SWS HR.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; Flags: unchecked

[Run]
Filename: "{app}\SWS HR.exe"; Description: "Open SWS HR"; Flags: nowait postinstall
