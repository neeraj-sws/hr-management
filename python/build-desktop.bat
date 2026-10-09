@echo off
rem Builds the Windows desktop app:
rem   dist\SWS HR\SWS HR.exe            (PyInstaller, no Python needed on the employee's PC)
rem   dist\SWS-HR-Setup-<version>.exe   (only when Inno Setup 6 is installed: per-user installer)
rem Then prints the SHA-256 to put in the server's .env as DESKTOP_SHA256.
cd /d "%~dp0"
if not exist .build-venv\Scripts\python.exe python -m venv .build-venv || goto :error
.build-venv\Scripts\python -m pip install -q -r requirements.txt "pyinstaller>=6,<7" || goto :error
for /f "usebackq delims=" %%v in (`.build-venv\Scripts\python -c "from sws_desktop.version import VERSION; print(VERSION)"`) do set VERSION=%%v
.build-venv\Scripts\pyinstaller --noconfirm --clean --windowed --name "SWS HR" --paths . --hidden-import pystray._win32 sws_desktop_entry.py || goto :error
echo Built dist\SWS HR\SWS HR.exe (version %VERSION%)

set ISCC=%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe
if not exist "%ISCC%" set ISCC=%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe
if not exist "%ISCC%" (
  echo Inno Setup 6 not found - skipping the installer. Install it from https://jrsoftware.org/isdl.php to build SWS-HR-Setup.exe.
  goto :eof
)
"%ISCC%" /DAppVersion=%VERSION% installer\sws-hr.iss || goto :error
powershell -NoProfile -Command "'DESKTOP_LATEST_VERSION=%VERSION%'; 'DESKTOP_SHA256=' + (Get-FileHash 'dist\SWS-HR-Setup-%VERSION%.exe' -Algorithm SHA256).Hash.ToLower()"
goto :eof

:error
echo Build failed.
exit /b 1
