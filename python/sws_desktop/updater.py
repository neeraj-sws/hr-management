"""Desktop auto-update.

The server says which version is current (GET /api/desktop/latest, set by the
administrator in .env). When it is newer than this app, the employee is asked;
the installer is downloaded, its SHA-256 must match what the server published
(nothing runs otherwise), and it is started before this app closes.
"""

from __future__ import annotations

import hashlib
import os
import re
import subprocess
import tempfile
from pathlib import Path

import requests

from .version import VERSION


def _parts(version: str) -> tuple[int, ...]:
    return tuple(int(n) for n in re.findall(r"\d+", version)[:4]) or (0,)


def is_newer(candidate: str, current: str = VERSION) -> bool:
    return _parts(candidate) > _parts(current)


def available_update(latest: dict | None) -> dict | None:
    """The server's answer if it offers a newer, installable version; otherwise None."""
    if not latest or not latest.get("version") or not latest.get("url") or not latest.get("sha256"):
        return None
    if not str(latest["url"]).lower().startswith("https://") and not os.environ.get("SWS_ALLOW_HTTP_UPDATES"):
        return None  # never run an installer fetched over plain HTTP
    return latest if is_newer(str(latest["version"])) else None


def download(update: dict, session: requests.Session | None = None, folder: Path | None = None) -> Path:
    """Downloads the installer and checks its SHA-256; raises ValueError on a mismatch."""
    session = session or requests.Session()
    folder = folder or Path(tempfile.gettempdir())
    target = folder / f"SWS-HR-Setup-{re.sub(r'[^0-9A-Za-z.]', '', str(update['version']))}.exe"
    digest = hashlib.sha256()
    with session.get(update["url"], stream=True, timeout=60) as response:
        response.raise_for_status()
        with open(target, "wb") as handle:
            for chunk in response.iter_content(chunk_size=1 << 16):
                handle.write(chunk)
                digest.update(chunk)
    if digest.hexdigest().lower() != str(update["sha256"]).lower():
        target.unlink(missing_ok=True)
        raise ValueError("The downloaded update did not match the published checksum and was deleted.")
    return target


def run_installer(path: Path) -> None:
    """Starts the Inno Setup installer quietly; it closes and replaces this app."""
    subprocess.Popen([str(path), "/SILENT", "/SUPPRESSMSGBOXES", "/NORESTART"], close_fds=True)
