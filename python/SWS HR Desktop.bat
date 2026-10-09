@echo off
rem Starts the SWS HR desktop app (needs Python 3.10+ with the "requests" package).
cd /d "%~dp0"
python -m pip install -q -r requirements.txt
start "" pythonw -m sws_desktop
