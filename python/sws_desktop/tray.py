"""System-tray icon: closing the window keeps SWS HR running (timer, heartbeat, offline sync)."""

from __future__ import annotations

import threading
from typing import Callable


def _icon_image():
    from PIL import Image, ImageDraw

    image = Image.new("RGBA", (64, 64), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle((2, 2, 62, 62), radius=14, fill=(20, 40, 75, 255))
    draw.text((15, 20), "SW", fill=(255, 255, 255, 255))
    return image


class Tray:
    """Runs pystray on its own thread; menu callbacks are handed back to Tk via `schedule`."""

    def __init__(self, schedule: Callable[[Callable[[], None]], None], on_open: Callable[[], None], on_quit: Callable[[], None]):
        self._schedule = schedule
        self._on_open = on_open
        self._on_quit = on_quit
        self._icon = None

    def start(self) -> bool:
        try:
            import pystray
        except ImportError:
            return False
        menu = pystray.Menu(
            pystray.MenuItem("Open SWS HR", lambda: self._schedule(self._on_open), default=True),
            pystray.MenuItem("Quit", lambda: self._schedule(self._on_quit)),
        )
        self._icon = pystray.Icon("sws-hr", _icon_image(), "SWS HR", menu)
        threading.Thread(target=self._icon.run, daemon=True).start()
        return True

    def notify(self, message: str) -> None:
        try:
            if self._icon:
                self._icon.notify(message, "SWS HR")
        except Exception:  # noqa: BLE001 - notifications are best effort
            pass

    def set_title(self, title: str) -> None:
        if self._icon:
            self._icon.title = title

    # The tooltip always says when screen capture is on ("SWS HR - Working · screen capture ON").
    _status = ""
    _capture = False

    def set_status(self, status: str) -> None:
        self._status = status
        self._refresh_title()

    def set_capture(self, on: bool) -> None:
        self._capture = on
        self._refresh_title()

    def _refresh_title(self) -> None:
        parts = ["SWS HR"] + ([f"- {self._status}"] if self._status else []) + (["· screen capture ON"] if self._capture else [])
        self.set_title(" ".join(parts))

    def stop(self) -> None:
        if self._icon:
            self._icon.stop()
