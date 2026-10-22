"""Tests for the desktop app's API client and offline queue.

Unit tests run offline. The live test runs only when SWS_API_URL,
SWS_TEST_EMAIL and SWS_TEST_PASSWORD point at a running SWS HR server.
"""

import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

import requests

from sws_desktop.client import ApiError, HrClient
from sws_desktop.offline import OfflineQueue


class ClientTests(unittest.TestCase):
    def test_network_failure_becomes_status_zero(self):
        session = Mock()
        session.request.side_effect = requests.ConnectionError("down")
        with self.assertRaises(ApiError) as caught:
            HrClient("http://x", "t", session=session).today()
        self.assertTrue(caught.exception.is_network)

    def test_validation_errors_are_exposed(self):
        response = Mock(status_code=422, content=b"{}", json=lambda: {"message": "bad", "errors": {"email": ["These credentials do not match our records."]}})
        session = Mock(request=Mock(return_value=response))
        with self.assertRaises(ApiError) as caught:
            HrClient("http://x", session=session).login("a@b.c", "x")
        self.assertEqual(caught.exception.status, 422)
        self.assertEqual(caught.exception.first_message, "These credentials do not match our records.")

    def test_server_errors_hide_debug_details(self):
        response = Mock(status_code=500, content=b"{}", json=lambda: {"message": "SQLSTATE[HY000] [2002] connection refused"})
        session = Mock(request=Mock(return_value=response))
        with self.assertRaises(ApiError) as caught:
            HrClient("http://x", "t", session=session).today()
        self.assertEqual(caught.exception.status, 500)
        self.assertNotIn("SQLSTATE", caught.exception.first_message)

    def test_autostart_shortcut_is_created_and_removed(self):
        from sws_desktop import autostart
        with tempfile.TemporaryDirectory() as folder:
            os.environ["SWS_STARTUP_DIR"] = folder
            try:
                self.assertFalse(autostart.is_enabled())
                autostart.enable()
                self.assertTrue(autostart.is_enabled())
                self.assertEqual(autostart.shortcut_path().suffix, ".lnk")
                autostart.disable()
                self.assertFalse(autostart.is_enabled())
            finally:
                del os.environ["SWS_STARTUP_DIR"]

    def test_only_one_window_can_run(self):
        from sws_desktop.app import _claim_single_instance
        first = _claim_single_instance()
        if first is None:
            self.skipTest('SWS HR desktop is open on this computer, which already holds the lock')
        try:
            self.assertIsNone(_claim_single_instance())
        finally:
            first.close()

    def test_away_tracker_reports_idle_lock_and_sleep(self):
        from datetime import datetime, timedelta
        from sws_desktop.presence import AwayTracker
        t0 = datetime(2026, 10, 8, 10, 0)
        tracker = AwayTracker(10)
        found = []

        def poll(seconds_from, seconds_to, idle_at, locked=False):
            # Real polling cadence: every 5 seconds.
            for second in range(seconds_from, seconds_to, 5):
                period = tracker.feed(t0 + timedelta(seconds=second), idle_at(second), locked)
                if period:
                    found.append(period)

        poll(0, 60, lambda s: 1)                                  # working
        poll(60, 965, lambda s: s - 60)                           # 15 min without input
        poll(965, 1000, lambda s: 1)                              # back
        poll(1000, 1300, lambda s: s - 1000)                      # 5 min idle: ignored
        poll(1300, 1400, lambda s: 1)
        poll(1400, 2600, lambda s: 0, locked=True)                # 20 min locked
        poll(2600, 2700, lambda s: 1)
        self.assertEqual([(p.reason, p.minutes) for p in found], [("idle", 15), ("locked", 20)])
        self.assertEqual(found[0].start, t0 + timedelta(seconds=60))
        sleeper = AwayTracker(10)
        sleeper.feed(t0, 1, False)
        period = sleeper.feed(t0 + timedelta(hours=1), 1, False)
        self.assertEqual((period.reason, period.minutes), ("asleep", 60))
        self.assertIsNone(AwayTracker(0).feed(t0, 9999, False), "0 minutes disables prompts")

    def test_screen_capture_rules_follow_the_work_session(self):
        from datetime import datetime, timedelta
        from sws_desktop.presence import should_capture
        on = {"enabled": True, "acknowledged": True, "interval_minutes": 10, "active_only": True}
        t = datetime(2026, 10, 7, 9, 40)
        last = t - timedelta(minutes=10)
        self.assertTrue(should_capture(t, last, on, "working", 5, False))
        self.assertFalse(should_capture(t, last, {**on, "enabled": False}, "working", 5, False), "off by default")
        self.assertFalse(should_capture(t, last, {**on, "acknowledged": False}, "working", 5, False), "needs acknowledgement")
        self.assertFalse(should_capture(t, last, on, "on_break", 5, False), "never on break")
        self.assertFalse(should_capture(t, last, on, "completed", 5, False), "never after check-out")
        self.assertFalse(should_capture(t, last, on, None, 5, False), "never before check-in")
        self.assertFalse(should_capture(t, last, on, "working", 600, False), "idle + active-only")
        self.assertTrue(should_capture(t, last, {**on, "active_only": False}, "working", 600, False))
        self.assertFalse(should_capture(t, last, on, "working", 5, True), "locked")
        self.assertFalse(should_capture(t, t - timedelta(minutes=5), on, "working", 5, False), "interval")
        self.assertFalse(should_capture(t, None, on, "working", 5, False), "first capture waits one interval")
        self.assertFalse(should_capture(t, last, {**on, "window_start": "10:00", "window_end": "18:00"}, "working", 5, False), "outside window")

    def test_app_module_imports(self):
        import sws_desktop.app  # noqa: F401 - catches syntax errors in the GUI module

    def test_break_time_is_shown_live(self):
        from datetime import datetime, timedelta, timezone
        from sws_desktop.app import DesktopApp

        app = DesktopApp.__new__(DesktopApp)  # no window needed for the text
        app.session_fetched_at = datetime.now(timezone.utc)
        self.assertEqual(app._break_text(None), "")
        self.assertEqual(app._break_text({"status": "working", "break_seconds": 0}), "☕ No break taken yet today")
        self.assertEqual(app._break_text({"status": "working", "break_seconds": 2712}), "☕ Break today 00:45:12")
        started = (datetime.now(timezone.utc) - timedelta(minutes=12, seconds=30)).isoformat()
        text = app._break_text({"status": "on_break", "break_seconds": 2712, "break_started_at": started})
        self.assertIn("On break for 00:12:3", text)
        self.assertIn("Break today 00:57:4", text)
        # Older servers send no break_started_at: the last break-start event is used.
        text = app._break_text({"status": "on_break", "break_seconds": 0, "events": [{"event_type": "break_start", "event_time": started}]})
        self.assertIn("On break for 00:12:3", text)

    def test_each_sync_takes_the_servers_worked_time(self):
        from datetime import datetime, timedelta, timezone
        from sws_desktop.app import DesktopApp

        app = DesktopApp.__new__(DesktopApp)
        app.session = {"id": 5, "status": "working", "check_out": None, "worked_seconds": 26074, "screen_active_seconds": 10}
        app.session_fetched_at = datetime.now(timezone.utc) - timedelta(seconds=60)
        app._set_session = Mock()
        # The server took idle time off: its worked time is lower than what the clock had counted on to.
        app._sync_session({"id": 5, "status": "working", "check_out": None, "worked_seconds": 26052, "screen_active_seconds": 40})
        self.assertEqual(app.session["worked_seconds"], 26052)
        self.assertEqual(app.session["screen_active_seconds"], 40)
        self.assertLess((datetime.now(timezone.utc) - app.session_fetched_at).total_seconds(), 2, "The clock counts on from now")
        app._set_session.assert_not_called()
        # Checked out elsewhere (website / admin): the screen is redrawn.
        app._sync_session({"id": 5, "status": "completed", "check_out": "2026-10-21T13:30:00Z", "worked_seconds": 26100})
        app._set_session.assert_called_once()

    def test_total_time_since_check_in_is_shown(self):
        from datetime import datetime, timedelta, timezone
        from sws_desktop.app import DesktopApp

        app = DesktopApp.__new__(DesktopApp)
        app.session_fetched_at = datetime.now(timezone.utc)
        self.assertEqual(app._total_text(None), "")
        done = {"status": "completed", "check_in": "2026-10-21T04:38:00Z", "check_out": "2026-10-21T13:25:45Z", "break_seconds": 5398, "idle_counted_seconds": 215}
        self.assertEqual(app._total_text(done), "Total time 08:47:45 since check-in  (includes break 01:29:58 · idle 00:03:35)")
        start = (datetime.now(timezone.utc) - timedelta(hours=2)).isoformat()
        self.assertIn("Total time 02:00:0", app._total_text({"status": "working", "check_in": start, "break_seconds": 0}))

    def test_meeting_detection_rules(self):
        from sws_desktop import presence

        # Microphone / camera: started and not stopped = in use now.
        self.assertTrue(presence._device_in_use(133450000000000000, 0))
        self.assertFalse(presence._device_in_use(133450000000000000, 133450000100000000))
        self.assertFalse(presence._device_in_use(0, 0))
        # Meeting windows, not the app's chat/home window.
        self.assertTrue(presence.is_meeting_window("Zoom", "Zoom Meeting"))
        self.assertFalse(presence.is_meeting_window("Zoom", "Zoom Workplace"))
        self.assertTrue(presence.is_meeting_window("Microsoft Teams", "Daily stand-up | Meeting | Microsoft Teams"))
        self.assertFalse(presence.is_meeting_window("Microsoft Teams", "Chat | Risha Gangrade | Microsoft Teams"))
        self.assertTrue(presence.is_meeting_window("Google Chrome", "Meet - abc-defg-hij - Google Chrome"))
        self.assertTrue(presence.is_meeting_window("Microsoft Edge", "Google Meet and 2 more pages - Work - Microsoft Edge"))
        self.assertFalse(presence.is_meeting_window("Google Chrome", "Inbox (3) - Gmail - Google Chrome"))
        self.assertFalse(presence.is_meeting_window(None, None))

    def test_meeting_time_is_counted_apart_from_idle_and_never_asks_about_away(self):
        from datetime import datetime, timedelta
        from unittest.mock import patch
        from sws_desktop import presence
        from sws_desktop.app import DesktopApp

        app = DesktopApp.__new__(DesktopApp)
        app.clock_label = Mock(winfo_exists=Mock(return_value=True))
        app.root = Mock()
        app.session = {"status": "working"}
        app.screen_active = app.screen_idle = app.screen_meeting = 0.0
        app.app_usage_on = False
        app.window_titles_on = False
        app.capture_policy = None
        app.last_capture = None
        app.away_prompt_open = False
        app.away_tracker = presence.AwayTracker(1)
        app._ask_about_away = Mock()
        app.last_presence_poll = datetime.now().astimezone() - timedelta(seconds=5)
        # 20 minutes without mouse/keyboard, but in a Teams call.
        with patch.object(presence, "seconds_since_input", return_value=1200.0), patch.object(presence, "is_locked", return_value=False), \
                patch.object(presence, "meeting_reason", return_value="microphone in use"):
            app._watch_presence()
        self.assertGreater(app.screen_meeting, 4)
        self.assertEqual(app.screen_idle, 0.0)
        self.assertEqual(app.meeting_reason, "microphone in use")
        app._ask_about_away.assert_not_called()
        # Same, without a meeting: idle.
        app.last_presence_poll = datetime.now().astimezone() - timedelta(seconds=5)
        with patch.object(presence, "seconds_since_input", return_value=1200.0), patch.object(presence, "is_locked", return_value=False), \
                patch.object(presence, "meeting_reason", return_value=None):
            app._watch_presence()
        self.assertGreater(app.screen_idle, 4)

    def test_bearer_token_and_desktop_source_are_sent(self):
        response = Mock(status_code=201, content=b"{}", json=lambda: {"data": {"status": "working"}})
        session = Mock(request=Mock(return_value=response))
        HrClient("http://x/", "secret", session=session).attendance("check_in")
        args, kwargs = session.request.call_args
        self.assertEqual(args, ("POST", "http://x/api/attendance/check-in"))
        self.assertEqual(kwargs["headers"]["Authorization"], "Bearer secret")
        self.assertEqual(kwargs["json"], {"source": "desktop"})


class QueueTests(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.queue = OfflineQueue(Path(self.dir.name) / "q.db")

    def tearDown(self):
        self.dir.cleanup()

    def test_same_action_id_is_stored_once(self):
        self.queue.enqueue("task.start", "start", entity_id=1, base_version=1, action_id="a1")
        self.queue.enqueue("task.start", "start", entity_id=1, base_version=1, action_id="a1")
        self.assertEqual(len(self.queue.items()), 1)

    def test_flush_removes_accepted_and_marks_conflicts(self):
        self.queue.enqueue_attendance("check_in", "Check in")
        self.queue.enqueue("task.pause", "pause", entity_id=7, base_version=3, action_id="stale")
        accepted_id = self.queue.items()[0]["action_id"]
        client = Mock()
        client.sync.return_value = [{"action_id": accepted_id, "status": 200, "success": True}, {"action_id": "stale", "status": 409, "conflict": True, "message": "Task changed"}]
        self.assertEqual(self.queue.flush(client), 1)
        sent = client.sync.call_args[0][0]
        self.assertIn("occurred_at", sent[0]["payload"])
        self.assertEqual(sent[1]["base_version"], 3)
        remaining = self.queue.items()
        self.assertEqual([(i["action_id"], i["status"]) for i in remaining], [("stale", "conflict")])

    def test_flush_keeps_everything_while_offline(self):
        self.queue.enqueue_attendance("check_in", "Check in")
        client = Mock()
        client.sync.side_effect = ApiError("down", 0)
        self.assertEqual(self.queue.flush(client), 0)
        self.assertEqual(self.queue.counts()["queued"], 1)


@unittest.skipUnless(os.environ.get("SWS_API_URL") and os.environ.get("SWS_TEST_EMAIL"), "live server not configured")
class LiveServerTests(unittest.TestCase):
    """Runs a full day against a real server: login, check-in, break, offline replay, task timer, check-out."""

    def test_full_day_with_offline_replay(self):
        client = HrClient(os.environ["SWS_API_URL"])
        client.login(os.environ["SWS_TEST_EMAIL"], os.environ["SWS_TEST_PASSWORD"])
        self.assertIsNotNone(client.me()["employee"])
        if client.today() is None:
            self.assertEqual(client.attendance("check_in")["status"], "working")
        session = client.today()
        if session["status"] == "on_break":
            client.attendance("break_end")
        if not client.today()["check_out"]:
            self.assertEqual(client.attendance("break_start")["status"], "on_break")
            with tempfile.TemporaryDirectory() as directory:
                queue = OfflineQueue(Path(directory) / "q.db")
                action_id = queue.enqueue_attendance("break_end", "Resume work")
                sent = json.loads(queue.items()[0]["payload"])
                self.assertEqual(queue.flush(client), 1)
                # Replaying the identical action is acknowledged without creating anything new.
                results = client.sync([{"action_id": action_id, "type": "attendance.break_end", "payload": sent}])
                self.assertEqual((results[0]["status"], results[0]["replayed"]), (200, True))
            self.assertEqual(client.today()["status"], "working")
        tasks = client.tasks()
        if tasks:
            task = tasks[0]
            if not task["timer_running_since"]:
                self.assertEqual(client.task(task["id"], "start")["status"], "in_progress")
            self.assertIsNone(client.task(task["id"], "pause")["timer_running_since"])
        if not client.today()["check_out"]:
            self.assertEqual(client.attendance("check_out")["status"], "completed")
        client.logout()
        with self.assertRaises(ApiError) as caught:
            client.me()
        self.assertEqual(caught.exception.status, 401)


class UpdaterTests(unittest.TestCase):
    def test_only_newer_https_releases_with_a_checksum_are_offered(self):
        from sws_desktop import updater
        good = {"version": "99.0.0", "url": "https://example.com/setup.exe", "sha256": "a" * 64}
        self.assertEqual(updater.available_update(good), good)
        self.assertIsNone(updater.available_update(None))
        self.assertIsNone(updater.available_update({**good, "version": "0.9"}))
        self.assertIsNone(updater.available_update({**good, "sha256": ""}))
        self.assertIsNone(updater.available_update({**good, "url": "http://example.com/setup.exe"}))
        self.assertTrue(updater.is_newer("1.10.0", "1.9.3"))
        self.assertFalse(updater.is_newer("1.1.0", "1.1.0"))

    def test_download_keeps_the_file_only_when_the_checksum_matches(self):
        import hashlib
        from sws_desktop import updater
        body = b"installer-bytes"
        response = Mock(iter_content=lambda chunk_size: [body], raise_for_status=Mock())
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        session = Mock(get=Mock(return_value=response))
        with tempfile.TemporaryDirectory() as folder:
            update = {"version": "2.0.0", "url": "https://example.com/s.exe", "sha256": hashlib.sha256(body).hexdigest()}
            path = updater.download(update, session=session, folder=Path(folder))
            self.assertEqual(path.read_bytes(), body)
            with self.assertRaises(ValueError):
                updater.download({**update, "sha256": "0" * 64}, session=session, folder=Path(folder))
            self.assertFalse((Path(folder) / "SWS-HR-Setup-2.0.0.exe").exists(), "A tampered download is deleted")


if __name__ == "__main__":
    unittest.main()
