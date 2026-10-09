# SWS HR — Windows desktop app

A small Windows app for employees: sign in, check in / break / resume / check out with a live work clock, start / pause / complete tasks, and keep working offline.

## Run

Double-click **`SWS HR Desktop.bat`**, or:

```
pip install -r requirements.txt
python -m sws_desktop
```

Enter the server address (e.g. `http://localhost:8020` or your company URL), email and password.

## Open automatically when Windows starts

Tick **Open when Windows starts** in the app header. The app adds a shortcut (`SWS HR.lnk`) to your own Windows Startup folder; untick it to remove the shortcut. Nothing else on the computer is changed.

When the app is opened this way and you have not checked in yet today, it asks **"Check in now?"**. It never checks you in by itself. Only one SWS HR window can run at a time, so two timers never run side by side.

## Away from the computer

While you are checked in and working, the app notices when you come back after being away for a while (no keyboard/mouse use, screen locked, or computer asleep). It asks **"Count this time as a break?"** — Yes records it as a break (and splits any running task timer), No keeps it as working time. The number of minutes is set by HR under Settings → Work hours (0 turns it off). Only the *time* of your last input is read, never what you typed.

## How it works

- The login token is encrypted with Windows DPAPI for the current Windows user (`%APPDATA%\SWSHR\user_token.bin`).
- If the server is unreachable, attendance and task actions are saved in `%APPDATA%\SWSHR\offline_queue.db` with the time they happened and sent through `/api/sync/actions` when the server is back (every 30 s or **Sync now**). Each action has a unique id, so a retry never creates a duplicate; a task that changed on the server comes back as a conflict instead of being overwritten.
- While checked in, the app sends a heartbeat every 30 s.
- Privacy: the app never records keystrokes, passwords, webcam, microphone or clipboard data. Screenshots are taken only if HR turns on Settings > Screen capture **and** you acknowledge the notice in the app; only while checked in and working, never during breaks or after check-out. You can see your own screenshots on the website (Monitoring > Screenshots).

## Device-agent library

`api_client.py` and `device_token.py` are the older device-registration client for attendance devices (`/api/attendance/device/register`, `/api/attendance/sync`).

## Tests

```
python -m unittest test_desktop test_client
```

Live end-to-end test against a running server (uses a real employee account):

```
set SWS_API_URL=http://localhost:8010
set SWS_TEST_EMAIL=employee2@example.com
set SWS_TEST_PASSWORD=...
python -m unittest -v test_desktop.LiveServerTests
```
