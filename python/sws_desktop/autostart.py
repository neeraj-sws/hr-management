"""Open the desktop app when the user signs in to Windows (a shortcut in their Startup folder).

Per-user only: nothing is written outside %APPDATA%, and the employee turns it on or off in the app.
"""

from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

SHORTCUT_NAME = "SWS HR.lnk"
STARTUP_FLAG = "--startup"


def startup_dir() -> Path:
    override = os.environ.get("SWS_STARTUP_DIR")
    if override:
        return Path(override)
    return Path(os.environ.get("APPDATA", Path.home())) / "Microsoft" / "Windows" / "Start Menu" / "Programs" / "Startup"


def shortcut_path() -> Path:
    return startup_dir() / SHORTCUT_NAME


def is_enabled() -> bool:
    return shortcut_path().exists()


def _windowless_python() -> str:
    exe = Path(sys.executable)
    candidate = exe.with_name("pythonw.exe")
    return str(candidate if candidate.exists() else exe)


def enable() -> None:
    """Create the Startup shortcut: pythonw -m sws_desktop --startup, run from this project folder."""
    target = shortcut_path()
    target.parent.mkdir(parents=True, exist_ok=True)
    workdir = Path(__file__).resolve().parent.parent
    quote = lambda value: str(value).replace("'", "''")  # noqa: E731 - PowerShell single-quote escaping
    script = (
        "$s = (New-Object -ComObject WScript.Shell).CreateShortcut('{lnk}'); "
        "$s.TargetPath = '{exe}'; $s.Arguments = '-m sws_desktop {flag}'; "
        "$s.WorkingDirectory = '{cwd}'; $s.Description = 'SWS HR work timer'; $s.Save()"
    ).format(lnk=quote(target), exe=quote(_windowless_python()), flag=STARTUP_FLAG, cwd=quote(workdir))
    subprocess.run(
        ["powershell", "-NoProfile", "-NonInteractive", "-Command", script],
        check=True, capture_output=True, timeout=30, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
    )


def disable() -> None:
    shortcut_path().unlink(missing_ok=True)
