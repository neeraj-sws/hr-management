"""SQLite offline queue replayed through POST /api/sync/actions.

Each action has a unique action_id, so a retry never creates a duplicate on
the server. Attendance actions carry occurred_at (the moment the button was
pressed); task actions carry the last seen server_version so stale changes
come back as a conflict instead of overwriting newer data.
"""

from __future__ import annotations

import json
import sqlite3
import threading
import uuid
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path

from .client import ApiError, HrClient

SCHEMA = """
CREATE TABLE IF NOT EXISTS actions (
    action_id TEXT PRIMARY KEY,
    type TEXT NOT NULL,
    entity_id INTEGER,
    base_version INTEGER,
    payload TEXT NOT NULL,
    label TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    queued_at TEXT NOT NULL
)
"""


class OfflineQueue:
    def __init__(self, path: Path | str):
        self.path = str(path)
        self._lock = threading.Lock()
        with self._connect() as db:
            db.execute(SCHEMA)

    @contextmanager
    def _connect(self):
        """Open, commit and always close (an open handle locks the file on Windows)."""
        connection = sqlite3.connect(self.path)
        connection.row_factory = sqlite3.Row
        try:
            yield connection
            connection.commit()
        finally:
            connection.close()

    def enqueue(self, action_type: str, label: str, payload: dict | None = None, entity_id: int | None = None, base_version: int | None = None, action_id: str | None = None) -> str:
        action_id = action_id or str(uuid.uuid4())
        with self._lock, self._connect() as db:
            db.execute(
                "INSERT OR IGNORE INTO actions (action_id, type, entity_id, base_version, payload, label, queued_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                (action_id, action_type, entity_id, base_version, json.dumps(payload or {}), label, datetime.now(timezone.utc).isoformat()),
            )
        return action_id

    def enqueue_attendance(self, action: str, label: str) -> str:
        return self.enqueue(f"attendance.{action}", label, {"occurred_at": datetime.now(timezone.utc).isoformat()})

    def items(self) -> list[dict]:
        with self._connect() as db:
            return [dict(row) for row in db.execute("SELECT * FROM actions ORDER BY queued_at, rowid")]

    def counts(self) -> dict:
        counts = {"queued": 0, "conflict": 0, "failed": 0}
        for item in self.items():
            counts[item["status"]] = counts.get(item["status"], 0) + 1
        return counts

    def retry(self, action_id: str) -> None:
        """Puts an action that needs attention back in the queue, to be sent with the next sync."""
        with self._lock, self._connect() as db:
            db.execute("UPDATE actions SET status = 'queued', last_error = NULL WHERE action_id = ?", (action_id,))

    def attention(self) -> list[dict]:
        """Actions the server refused (conflict or failed): shown to the employee to retry or discard."""
        return [item for item in self.items() if item["status"] in ("conflict", "failed")]

    def discard(self, action_id: str) -> None:
        with self._lock, self._connect() as db:
            db.execute("DELETE FROM actions WHERE action_id = ?", (action_id,))

    def flush(self, client: HrClient) -> int:
        """Send queued actions in order; returns how many the server accepted."""
        with self._lock:
            pending = [item for item in self.items() if item["status"] == "queued"][:50]
            if not pending:
                return 0
            body = []
            for item in pending:
                action = {"action_id": item["action_id"], "type": item["type"], "payload": json.loads(item["payload"])}
                if item["entity_id"] is not None:
                    action["entity_id"] = item["entity_id"]
                if item["base_version"] is not None:
                    action["base_version"] = item["base_version"]
                body.append(action)
            try:
                results = client.sync(body)
            except ApiError as error:
                if error.is_network:
                    return 0
                if error.status == 422:
                    self._mark([i["action_id"] for i in pending], "failed", error.first_message)
                    return 0
                raise
            accepted = 0
            with self._connect() as db:
                for result in results:
                    status = int(result.get("status", 500))
                    if status < 300 and result.get("success", True):
                        db.execute("DELETE FROM actions WHERE action_id = ?", (result["action_id"],))
                        accepted += 1
                        continue
                    errors = result.get("errors") or {}
                    message = result.get("message") or next((v[0] for v in errors.values() if v), "Rejected by server")
                    new_status = "conflict" if status == 409 or result.get("conflict") else "failed" if status in (401, 403, 404, 422) else "queued"
                    db.execute("UPDATE actions SET status = ?, attempts = attempts + 1, last_error = ? WHERE action_id = ?", (new_status, message, result["action_id"]))
            return accepted

    def _mark(self, ids: list[str], status: str, message: str) -> None:
        with self._connect() as db:
            db.executemany("UPDATE actions SET status = ?, attempts = attempts + 1, last_error = ? WHERE action_id = ?", [(status, message, i) for i in ids])
