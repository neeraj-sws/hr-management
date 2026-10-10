"""Local app data: %APPDATA%\\SWSHR (server URL, DPAPI-encrypted token, offline queue)."""

from __future__ import annotations

import json
import os
from pathlib import Path

from device_token import protect, unprotect

APP_DIR = Path(os.environ.get("SWS_DESKTOP_HOME") or Path(os.environ.get("APPDATA", Path.home())) / "SWSHR")
SETTINGS_FILE = APP_DIR / "settings.json"
TOKEN_FILE = APP_DIR / "user_token.bin"
QUEUE_FILE = APP_DIR / "offline_queue.db"
DEFAULT_SERVER = "https://hrms.sohamsolution.com"


def ensure_dir() -> Path:
    APP_DIR.mkdir(parents=True, exist_ok=True)
    return APP_DIR


def load_settings() -> dict:
    try:
        return json.loads(SETTINGS_FILE.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {"server": DEFAULT_SERVER}


def save_settings(settings: dict) -> None:
    ensure_dir()
    SETTINGS_FILE.write_text(json.dumps(settings, indent=2), encoding="utf-8")


def load_token() -> str:
    if not TOKEN_FILE.exists():
        return ""
    try:
        return unprotect(TOKEN_FILE.read_bytes()).decode("utf-8")
    except OSError:
        return ""


def save_token(token: str) -> None:
    ensure_dir()
    TOKEN_FILE.write_bytes(protect(token.encode("utf-8")))


def clear_token() -> None:
    TOKEN_FILE.unlink(missing_ok=True)
