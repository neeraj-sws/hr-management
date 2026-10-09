"""Notices when the employee is away from the computer: idle, screen locked, or asleep.

Only *when* the mouse/keyboard was last used is read (Windows GetLastInputInfo),
never what was typed or clicked. The employee decides on return whether the
away time counts as a break. Optional screen capture (should_capture /
capture_screen_jpeg) runs only when HR enabled it and the employee acknowledged it.
"""

from __future__ import annotations

import ctypes
import sys
from dataclasses import dataclass
from datetime import datetime, timedelta

SLEEP_GAP_SECONDS = 90  # polls are 5 s apart; a much larger gap means the computer was asleep


@dataclass(frozen=True)
class AwayPeriod:
    start: datetime
    end: datetime
    reason: str  # "idle" | "locked" | "asleep"

    @property
    def minutes(self) -> int:
        return int((self.end - self.start).total_seconds() // 60)


def seconds_since_input() -> float:
    """Seconds since the last mouse/keyboard input anywhere in this Windows session (0 elsewhere)."""
    if sys.platform != "win32":
        return 0.0

    class LASTINPUTINFO(ctypes.Structure):
        _fields_ = [("cbSize", ctypes.c_uint), ("dwTime", ctypes.c_uint)]

    info = LASTINPUTINFO()
    info.cbSize = ctypes.sizeof(LASTINPUTINFO)
    if not ctypes.windll.user32.GetLastInputInfo(ctypes.byref(info)):
        return 0.0
    now = ctypes.windll.kernel32.GetTickCount() & 0xFFFFFFFF
    return ((now - info.dwTime) & 0xFFFFFFFF) / 1000.0


# Friendly names for common executables; anything else falls back to the .exe base name.
_APP_NAMES = {
    "chrome": "Google Chrome", "msedge": "Microsoft Edge", "firefox": "Firefox", "brave": "Brave",
    "code": "VS Code", "devenv": "Visual Studio", "pycharm64": "PyCharm", "phpstorm64": "PhpStorm", "idea64": "IntelliJ IDEA",
    "winword": "Microsoft Word", "excel": "Microsoft Excel", "powerpnt": "Microsoft PowerPoint", "outlook": "Microsoft Outlook",
    "onenote": "OneNote", "teams": "Microsoft Teams", "ms-teams": "Microsoft Teams", "slack": "Slack", "zoom": "Zoom",
    "whatsapp": "WhatsApp", "telegram": "Telegram", "notepad": "Notepad", "explorer": "File Explorer",
    "acrobat": "Adobe Acrobat", "acrord32": "Adobe Reader", "photoshop": "Adobe Photoshop", "cmd": "Command Prompt",
    "powershell": "PowerShell", "windowsterminal": "Windows Terminal", "postman": "Postman", "figma": "Figma",
}


def foreground_app() -> str | None:
    """
    The application that owns the foreground window, as a display name (e.g. "Google
    Chrome"). Only the executable name is read - never the window title, so no
    document names, e-mail subjects or other content are seen. None off Windows,
    on the lock screen, or when it cannot be determined.
    """
    if sys.platform != "win32":
        return None
    try:
        user32, kernel32 = ctypes.windll.user32, ctypes.windll.kernel32
        hwnd = user32.GetForegroundWindow()
        if not hwnd:
            return None
        pid = ctypes.c_uint(0)
        user32.GetWindowThreadProcessId(hwnd, ctypes.byref(pid))
        if not pid.value:
            return None
        PROCESS_QUERY_LIMITED_INFORMATION = 0x1000
        handle = kernel32.OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, False, pid.value)
        if not handle:
            return None
        try:
            size = ctypes.c_uint(32768)
            buf = ctypes.create_unicode_buffer(size.value)
            if not kernel32.QueryFullProcessImageNameW(handle, 0, buf, ctypes.byref(size)):
                return None
            exe = buf.value
        finally:
            kernel32.CloseHandle(handle)
    except Exception:
        return None
    if not exe:
        return None
    base = exe.replace("\\", "/").split("/")[-1]
    stem = base[:-4] if base.lower().endswith(".exe") else base
    key = stem.lower()
    return _APP_NAMES.get(key, stem[:1].upper() + stem[1:] if stem else None)


def foreground_window_title() -> str | None:
    """
    The title bar text of the foreground window (for a browser this is the
    active tab). Recorded only when an administrator has turned window-title
    tracking on for the employee; it can contain content such as a site name,
    document name or e-mail subject, so it is a separate, more sensitive opt-in.
    None off Windows, on the lock screen, or when empty.
    """
    if sys.platform != "win32":
        return None
    try:
        user32 = ctypes.windll.user32
        hwnd = user32.GetForegroundWindow()
        if not hwnd:
            return None
        length = user32.GetWindowTextLengthW(hwnd)
        if length <= 0:
            return None
        buf = ctypes.create_unicode_buffer(length + 1)
        user32.GetWindowTextW(hwnd, buf, length + 1)
        title = buf.value.strip()
        return title[:255] or None
    except Exception:
        return None


def is_locked() -> bool:
    """True while the lock screen (or another secure desktop) is shown."""
    if sys.platform != "win32":
        return False
    desktop = ctypes.windll.user32.OpenInputDesktop(0, False, 0x0100)  # DESKTOP_SWITCHDESKTOP
    if not desktop:
        return True
    ctypes.windll.user32.CloseDesktop(desktop)
    return False


class AwayTracker:
    """Fed every few seconds; returns an AwayPeriod once the person is back after at least `threshold_minutes`."""

    def __init__(self, threshold_minutes: int):
        self.threshold = timedelta(minutes=max(0, threshold_minutes))
        self.last_poll: datetime | None = None
        self.locked_since: datetime | None = None
        self.idle_since: datetime | None = None

    def reset(self) -> None:
        self.last_poll = self.locked_since = self.idle_since = None

    def feed(self, now: datetime, idle_seconds: float, locked: bool) -> AwayPeriod | None:
        if self.threshold <= timedelta(0):
            return None
        previous, self.last_poll = self.last_poll, now
        if previous and (now - previous).total_seconds() > SLEEP_GAP_SECONDS:
            start = self.locked_since or self.idle_since or previous
            self.locked_since = self.idle_since = None
            return self._finish(start, now, "asleep")

        if locked:
            self.locked_since = self.locked_since or (self.idle_since or now)
            return None
        if self.locked_since:
            start, self.locked_since, self.idle_since = self.locked_since, None, None
            return self._finish(start, now, "locked")

        if idle_seconds >= self.threshold.total_seconds():
            self.idle_since = self.idle_since or now - timedelta(seconds=idle_seconds)
            return None
        if self.idle_since:
            start, self.idle_since = self.idle_since, None
            return self._finish(start, now - timedelta(seconds=idle_seconds), "idle")
        return None

    def _finish(self, start: datetime, end: datetime, reason: str) -> AwayPeriod | None:
        return AwayPeriod(start, end, reason) if end - start >= self.threshold else None


def should_capture(now: datetime, last_capture: datetime | None, policy: dict | None, status: str | None,
                   idle_seconds: float, locked: bool) -> bool:
    """Screen-capture rules (the server checks them again): on and acknowledged, checked in and
    working (never on break or after check-out), inside the work window, not locked, recently in use
    when "active only" is set, and at most once per interval."""
    if not policy or not policy.get("enabled") or not policy.get("acknowledged") or status != "working" or locked:
        return False
    if policy.get("active_only", True) and idle_seconds >= 120:
        return False
    start, end = policy.get("window_start"), policy.get("window_end")
    if start and end:
        hm = now.strftime("%H:%M")
        if not ((start <= hm <= end) if start <= end else (hm >= start or hm <= end)):
            return False
    if last_capture is None:
        return False  # the first capture comes one interval after work starts
    return (now - last_capture).total_seconds() >= int(policy.get("interval_minutes", 10)) * 60


def capture_screen_jpeg(max_width: int = 1600, quality: int = 60) -> bytes:
    """All monitors as one JPEG, scaled down. Only called when should_capture() allows it."""
    import io

    from PIL import ImageGrab

    image = ImageGrab.grab(all_screens=True).convert("RGB")
    if image.width > max_width:
        image = image.resize((max_width, int(image.height * max_width / image.width)))
    buffer = io.BytesIO()
    image.save(buffer, "JPEG", quality=quality, optimize=True)
    return buffer.getvalue()
