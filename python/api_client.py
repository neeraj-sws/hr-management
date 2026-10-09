"""HTTP client for attendance device registration and synchronization."""

import requests

from device_token import load_token, save_token


class ApiClient:
    def __init__(self, base_url, token="", timeout=10):
        self.base_url = base_url.rstrip("/")
        self.token = token or load_token()
        self.timeout = timeout

    def headers(self, token=None):
        headers = {"Accept": "application/json", "Content-Type": "application/json"}
        bearer = self.token if token is None else token
        if bearer:
            headers["Authorization"] = f"Bearer {bearer}"
        return headers

    def post(self, endpoint, payload, token=None):
        if not self.base_url:
            return False, "API URL is empty"

        try:
            response = requests.post(
                f"{self.base_url}/{endpoint.lstrip('/')}",
                json=payload,
                headers=self.headers(token),
                timeout=self.timeout,
            )
        except requests.RequestException as exc:
            return False, str(exc)

        try:
            body = response.json()
        except ValueError:
            body = response.text

        if 200 <= response.status_code < 300:
            return True, body

        return False, {"status": response.status_code, "body": body}

    def register_device(self, employee_code, device_id, computer_name, admin_token=None):
        success, result = self.post("/api/attendance/device/register", {
            "employee_code": employee_code,
            "device_id": device_id,
            "computer_name": computer_name,
            "app_version": "0.2.0",
        }, token=admin_token)

        if not success:
            return success, result

        device_token = result.get("token", "")
        if not device_token:
            return False, "Registration response did not contain a device token"

        save_token(device_token)
        self.token = device_token
        return True, {key: value for key, value in result.items() if key != "token"}

    def sync_attendance(self, payload):
        return self.post("/api/attendance/sync", payload)
