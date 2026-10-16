"""HTTP client for the SWS HR API used by the desktop app."""

from __future__ import annotations

import requests


class ApiError(Exception):
    """Non-2xx response, or status 0 when the server could not be reached."""

    def __init__(self, message: str, status: int, errors: dict | None = None):
        super().__init__(message)
        self.status = status
        self.errors = errors or {}

    @property
    def is_network(self) -> bool:
        return self.status == 0

    @property
    def first_message(self) -> str:
        for messages in self.errors.values():
            if messages:
                return messages[0]
        return str(self)


class HrClient:
    def __init__(self, base_url: str, token: str = "", timeout: float = 10, session: requests.Session | None = None):
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout
        self.session = session or requests.Session()

    def request(self, method: str, path: str, payload: dict | None = None):
        headers = {"Accept": "application/json"}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        try:
            response = self.session.request(method, f"{self.base_url}/api{path}", json=payload, headers=headers, timeout=self.timeout)
        except requests.RequestException as exc:
            raise ApiError(f"Cannot reach the SWS HR server ({exc.__class__.__name__}).", 0) from exc
        try:
            body = response.json() if response.content else None
        except ValueError:
            body = None
        if 200 <= response.status_code < 300:
            return body
        if response.status_code >= 500:
            # Server faults carry debug details (SQL, stack traces) that mean nothing to an employee.
            raise ApiError("The SWS HR server had a problem. Please try again in a minute, or tell your HR administrator.", response.status_code)
        message = body.get("message") if isinstance(body, dict) else None
        errors = body.get("errors") if isinstance(body, dict) else None
        raise ApiError(message or f"Request failed ({response.status_code})", response.status_code, errors)

    # Authentication -------------------------------------------------------
    def login(self, email: str, password: str, device_name: str = "SWS HR Desktop") -> str:
        body = self.request("POST", "/auth/token", {"email": email, "password": password, "device_name": device_name})
        self.token = body["token"]
        return self.token

    def logout(self) -> None:
        try:
            self.request("POST", "/auth/revoke", {})
        finally:
            self.token = ""

    def me(self) -> dict:
        return self.request("GET", "/me")["data"]

    # Attendance -----------------------------------------------------------
    ATTENDANCE = {"check_in": "/attendance/check-in", "break_start": "/attendance/break/start", "break_end": "/attendance/break/end", "check_out": "/attendance/check-out"}

    def today(self) -> dict | None:
        return self.request("GET", "/attendance/today")["data"]

    def attendance(self, action: str) -> dict:
        return self.request("POST", self.ATTENDANCE[action], {"source": "desktop"})["data"]

    def away(self, start: str, end: str, reason: str, as_work: bool = False) -> dict:
        """Records an answered away period (ISO-8601 times): as a break, or as_work when the employee was working (not idle)."""
        return self.request("POST", "/attendance/away", {"from": start, "to": end, "reason": reason, "as": "work" if as_work else "break"})["data"]

    def daily_status(self) -> dict:
        return self.request("GET", "/daily-status")

    def save_daily_status(self, plan: str, summary: str, blockers: str, notes: str, yesterday: str = "", pending: str = "", tomorrow_plan: str = "") -> dict:
        return self.request("POST", "/daily-status", {"yesterday": yesterday or None, "plan": plan or None, "summary": summary, "pending": pending or None, "tomorrow_plan": tomorrow_plan or None, "blockers": blockers or None, "notes": notes or None})["data"]

    def acknowledge_screen_capture(self) -> dict:
        return self.request("POST", "/monitoring/acknowledge", {})["data"]

    def upload_screenshot(self, image: bytes, captured_at: str) -> dict:
        headers = {"Accept": "application/json", "Authorization": f"Bearer {self.token}"}
        try:
            response = self.session.post(f"{self.base_url}/api/monitoring/screenshots", data={"captured_at": captured_at}, files={"image": ("screen.jpg", image, "image/jpeg")}, headers=headers, timeout=max(self.timeout, 60))
        except requests.RequestException as exc:
            raise ApiError(f"Cannot reach the SWS HR server ({exc.__class__.__name__}).", 0) from exc
        if 200 <= response.status_code < 300:
            return response.json()["data"]
        body = response.json() if response.content and response.headers.get("content-type", "").startswith("application/json") else {}
        raise ApiError(body.get("message") or f"Upload failed ({response.status_code})", response.status_code, body.get("errors"))

    def heartbeat(self, screen_active: int = 0, screen_idle: int = 0) -> dict | None:
        """Keeps the session alive and reports screen time (seconds in use / untouched) since the last call."""
        return self.request("POST", "/attendance/heartbeat", {"screen_active_seconds": screen_active, "screen_idle_seconds": screen_idle})["data"]

    def report_app_usage(self, apps: dict[str, int], titles: list[dict] | None = None) -> dict:
        """Reports application-usage totals (app name -> seconds) and, when enabled, active-window titles."""
        payload = {"apps": apps}
        if titles:
            payload["titles"] = titles
        return self.request("POST", "/monitoring/app-usage", payload)

    # Tasks ----------------------------------------------------------------
    def tasks(self, status: str = "open") -> list[dict]:
        return self.request("GET", f"/tasks?status={status}")["data"]

    def task(self, task_id: int, action: str) -> dict:
        return self.request("POST", f"/tasks/{task_id}/{action}", {})["data"]

    def create_task(self, title: str, priority: str = "medium", due_on: str | None = None) -> dict:
        """Employee adds a task for themselves (server forces assignee = self)."""
        payload = {"title": title, "priority": priority}
        if due_on:
            payload["due_on"] = due_on
        return self.request("POST", "/tasks", payload)["data"]

    # Misc -----------------------------------------------------------------
    def latest_desktop(self) -> dict | None:
        return self.request("GET", "/desktop/latest").get("data")

    def notifications(self) -> dict:
        return self.request("GET", "/notifications?unread=1")

    def sync(self, actions: list[dict]) -> list[dict]:
        return self.request("POST", "/sync/actions", {"actions": actions}).get("results", [])
