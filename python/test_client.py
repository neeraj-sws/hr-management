import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

import api_client
import device_token


class DeviceTokenTests(unittest.TestCase):
    def test_token_is_encrypted_and_round_trips_with_current_windows_user(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "device_token.bin"
            with patch.object(device_token, "TOKEN_FILE", path):
                device_token.save_token("test-device-token")
                self.assertNotEqual(path.read_bytes(), b"test-device-token")
                self.assertEqual(device_token.load_token(), "test-device-token")
                device_token.delete_token()
                self.assertEqual(device_token.load_token(), "")


class ApiClientTests(unittest.TestCase):
    @patch("api_client.requests.post")
    def test_sync_sends_bearer_token_and_json_payload(self, post):
        post.return_value = Mock(status_code=200, json=lambda: {"success": True})
        client = api_client.ApiClient("https://hr.example.test", token="secret-device-token")

        success, body = client.sync_attendance({"session": {}})

        self.assertTrue(success)
        self.assertEqual(body, {"success": True})
        self.assertEqual(post.call_args.kwargs["headers"]["Authorization"], "Bearer secret-device-token")
        self.assertEqual(post.call_args.kwargs["json"], {"session": {}})

    @patch("api_client.save_token")
    @patch("api_client.requests.post")
    def test_registration_saves_and_uses_returned_device_token(self, post, save_token):
        post.return_value = Mock(status_code=201, json=lambda: {
            "success": True,
            "employee_id": "EMP-1",
            "device_id": "PC-1",
            "token": "new-device-token",
        })
        client = api_client.ApiClient("https://hr.example.test")

        success, result = client.register_device("EMP-1", "PC-1", "Workstation", admin_token="admin-token")

        self.assertTrue(success)
        self.assertEqual(result["device_id"], "PC-1")
        self.assertNotIn("token", result)
        self.assertEqual(client.token, "new-device-token")
        save_token.assert_called_once_with("new-device-token")


if __name__ == "__main__":
    unittest.main()
