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
    """Create the Startup shortcut: the installed SWS HR.exe --startup, or pythonw -m sws_desktop --startup from source."""
    target = shortcut_path()
    target.parent.mkdir(parents=True, exist_ok=True)
    frozen = getattr(sys, "frozen", False)  # installed .exe built with PyInstaller
    workdir = Path(sys.executable).parent if frozen else Path(__file__).resolve().parent.parent
    exe = sys.executable if frozen else _windowless_python()
    arguments = STARTUP_FLAG if frozen else f"-m sws_desktop {STARTUP_FLAG}"
    quote = lambda value: str(value).replace("'", "''")  # noqa: E731 - PowerShell single-quote escaping
    script = (
        "$s = (New-Object -ComObject WScript.Shell).CreateShortcut('{lnk}'); "
        "$s.TargetPath = '{exe}'; $s.Arguments = '{args}'; "
        "$s.WorkingDirectory = '{cwd}'; $s.Description = 'SWS HR work timer'; $s.Save()"
    ).format(lnk=quote(target), exe=quote(exe), args=quote(arguments), cwd=quote(workdir))
    subprocess.run(
        ["powershell", "-NoProfile", "-NonInteractive", "-Command", script],
        check=True, capture_output=True, timeout=30, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
    )


def disable() -> None:
    shortcut_path().unlink(missing_ok=True)
