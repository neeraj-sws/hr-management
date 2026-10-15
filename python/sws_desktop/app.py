"""SWS HR desktop app (Windows): attendance, task timer and offline sync.

Network calls run on a worker thread; results come back to the Tk thread via
`root.after`, so the window never freezes while the server is slow or down.
No keystrokes, webcam, microphone or clipboard are recorded. Screenshots are taken
only if an administrator turns screen capture on for this employee (see presence.should_capture).
"""

from __future__ import annotations

import queue
import socket
import sys
import threading
import tkinter as tk
from datetime import datetime, timezone
from tkinter import messagebox, ttk

import webbrowser

from . import autostart, presence, settings
from .tray import Tray
from . import updater
from .version import VERSION
from .client import ApiError, HrClient
from .offline import OfflineQueue

NAVY, BLUE, CANVAS, INK, MUTED, GREEN, RED, AMBER = "#14284b", "#3461d1", "#f4f6fa", "#172033", "#6b778a", "#12804a", "#bd3446", "#9a6412"
INK2, LINE, HEADER, BLUE_SOFT, GREEN_SOFT, AMBER_SOFT, RED_SOFT, STRIPE = "#334258", "#e4e9f0", "#1b355f", "#ecf2ff", "#e6f6ee", "#fff4dc", "#ffedf0", "#f8fafd"
STATUS_TONE = {"working": (GREEN, GREEN_SOFT), "on_break": (AMBER, AMBER_SOFT), "completed": (INK2, "#eef1f6")}
SYNC_INTERVAL_MS = 30_000
PRESENCE_INTERVAL_MS = 5_000
SCREEN_IDLE_AFTER_SECONDS = 60  # no keyboard/mouse for a minute (or screen locked) counts as idle
AWAY_REASON = {"idle": "no keyboard or mouse use", "locked": "screen locked", "asleep": "computer asleep"}
STATUS_LABEL = {"working": "Working", "on_break": "On break", "completed": "Day completed"}
ATTENDANCE_LABEL = {"check_in": "Check in", "break_start": "Start break", "break_end": "Resume work", "check_out": "Check out"}


def parse_time(value: str | None) -> datetime | None:
    return datetime.fromisoformat(value.replace("Z", "+00:00")) if value else None


class DesktopApp:
    def __init__(self, root: tk.Tk, launched_at_startup: bool = False):
        self.root = root
        self.launched_at_startup = launched_at_startup
        self.check_in_prompted = False
        self.away_tracker = presence.AwayTracker(10)
        self.away_prompt_open = False
        self.screen_active = 0.0  # not yet sent to the server
        self.screen_idle = 0.0
        self.app_usage: dict[str, float] = {}  # app name -> seconds not yet sent
        self.app_usage_on = False
        self.window_titles: dict[tuple[str, str], float] = {}  # (app, title) -> seconds not yet sent
        self.window_titles_on = False
        self.desktop_attendance_allowed = True  # admin can turn desktop attendance off for an employee
        self.last_presence_poll: datetime | None = None
        self.capture_policy: dict | None = None
        self.last_capture: datetime | None = None
        self.prefs = settings.load_settings()
        settings.ensure_dir()
        self.client = HrClient(self.prefs.get("server", settings.DEFAULT_SERVER), settings.load_token())
        self.offline = OfflineQueue(settings.QUEUE_FILE)
        self.results: queue.Queue = queue.Queue()
        self.me: dict | None = None
        self.session: dict | None = None
        self.session_fetched_at = datetime.now(timezone.utc)
        self.tasks: list[dict] = []
        self.online = True

        root.title("SWS HR")
        root.geometry("760x640")
        root.minsize(640, 560)
        root.configure(bg=CANVAS)
        self._styles()
        self.container = tk.Frame(root, bg=CANVAS)
        self.container.pack(fill="both", expand=True)
        root.after(100, self._drain)
        self._start_tray()

        if self.client.token:
            self._show_main()
            self.refresh()
        else:
            self._show_login()

    # Plumbing ---------------------------------------------------------------
    def _styles(self):
        style = ttk.Style(self.root)
        style.theme_use("clam")
        style.configure(".", font=("Segoe UI", 10))
        style.configure("TButton", padding=(16, 9), font=("Segoe UI", 10), background="white", foreground=INK2, bordercolor="#d6dde8", relief="flat", focuscolor="")
        style.map("TButton", background=[("active", "#eef2f8"), ("disabled", "#f4f6fa")], foreground=[("disabled", "#94a0b2")], bordercolor=[("active", "#bcc9db")])
        style.configure("Primary.TButton", background=BLUE, foreground="white", bordercolor=BLUE)
        style.map("Primary.TButton", background=[("active", "#2a51b5"), ("disabled", "#9db3e8")], foreground=[("disabled", "white")])
        style.configure("Success.TButton", background=GREEN, foreground="white", bordercolor=GREEN)
        style.map("Success.TButton", background=[("active", "#0e6a3d")])
        style.configure("Danger.TButton", background="white", foreground=RED, bordercolor="#f0c3cb")
        style.map("Danger.TButton", background=[("active", RED_SOFT)])
        style.configure("Ghost.TButton", padding=(10, 6), background="white", foreground=BLUE, bordercolor="#cdd9ee")
        style.map("Ghost.TButton", background=[("active", BLUE_SOFT)])
        style.configure("Header.TButton", background=HEADER, foreground="#dce6f5", bordercolor=HEADER, padding=(13, 7), font=("Segoe UI", 9))
        style.map("Header.TButton", background=[("active", "#27477a")], foreground=[("active", "white")])
        style.configure("TCombobox", padding=5, arrowsize=14)
        style.configure("Treeview", rowheight=34, font=("Segoe UI", 10), borderwidth=0, fieldbackground="white", background="white")
        style.configure("Treeview.Heading", font=("Segoe UI", 8, "bold"), background="white", foreground=MUTED, relief="flat", padding=(6, 8))
        style.map("Treeview.Heading", background=[("active", "white")])
        style.map("Treeview", background=[("selected", BLUE_SOFT)], foreground=[("selected", NAVY)])

    def _run(self, work, done=None, failed=None):
        """Run `work` off the UI thread; call `done(result)` or `failed(error)` on it."""
        def target():
            try:
                self.results.put((done, work(), None))
            except Exception as error:  # noqa: BLE001 - delivered to the UI thread
                self.results.put((failed, None, error))
        threading.Thread(target=target, daemon=True).start()

    def _drain(self):
        while not self.results.empty():
            callback, value, error = self.results.get()
            if error is not None:
                if isinstance(error, ApiError) and error.status == 401:
                    self._expired()
                elif callback:
                    callback(error)
                else:
                    self._flash(error.first_message if isinstance(error, ApiError) else str(error), RED)
            elif callback:
                callback(value)
        self.root.after(100, self._drain)

    def _clear(self):
        for child in self.container.winfo_children():
            child.destroy()

    # Login -----------------------------------------------------------------
    def _show_login(self, message: str = ""):
        self._clear()
        card = tk.Frame(self.container, bg="white", padx=28, pady=24, highlightbackground="#e4e9f0", highlightthickness=1)
        card.place(relx=0.5, rely=0.5, anchor="center")
        tk.Label(card, text="SWS HR", font=("Segoe UI", 20, "bold"), fg=NAVY, bg="white").pack(anchor="w")
        tk.Label(card, text="HR & Work Management — sign in", fg=MUTED, bg="white").pack(anchor="w", pady=(0, 14))
        self.login_error = tk.Label(card, text=message, fg=RED, bg="white", wraplength=320, justify="left")
        self.login_error.pack(anchor="w")
        self.server_var = tk.StringVar(value=self.prefs.get("server", settings.DEFAULT_SERVER))
        self.email_var = tk.StringVar(value=self.prefs.get("email", ""))
        self.password_var = tk.StringVar()
        for label, var, show in (("Server", self.server_var, ""), ("Email or Employee ID", self.email_var, ""), ("Password", self.password_var, "•")):
            tk.Label(card, text=label, bg="white", fg=INK, font=("Segoe UI", 9, "bold")).pack(anchor="w", pady=(8, 2))
            entry = ttk.Entry(card, textvariable=var, width=40, show=show)
            entry.pack(anchor="w")
            if label == "Password":
                entry.bind("<Return>", lambda _e: self._login())
                show_var = tk.BooleanVar(value=False)
                tk.Checkbutton(card, text="Show password", variable=show_var, bg="white", fg=MUTED, activebackground="white",
                               command=lambda e=entry, v=show_var: e.config(show="" if v.get() else "•")).pack(anchor="w", pady=(4, 0))
        self.login_button = ttk.Button(card, text="Sign in", style="Primary.TButton", command=self._login)
        self.login_button.pack(fill="x", pady=(16, 0))
        forgot = tk.Label(card, text="Forgot your password?", fg=BLUE, bg="white", cursor="hand2", font=("Segoe UI", 9, "underline"))
        forgot.pack(pady=(10, 0))
        forgot.bind("<Button-1>", lambda _e: webbrowser.open(self.server_var.get().strip().rstrip("/") + "/forgot-password"))

    def _login(self):
        server, email, password = self.server_var.get().strip().rstrip("/"), self.email_var.get().strip(), self.password_var.get()
        if not (server and email and password):
            self.login_error.config(text="Enter the server address, email (or employee ID) and password.")
            return
        self.login_button.config(state="disabled", text="Signing in…")
        self.client = HrClient(server)

        def done(token):
            settings.save_token(token)
            self.prefs.update({"server": server, "email": email})
            settings.save_settings(self.prefs)
            self._show_main()
            self.refresh()

        def failed(error):
            self.login_button.config(state="normal", text="Sign in")
            if isinstance(error, ApiError) and error.status == 422:
                self.login_error.config(text="These credentials do not match our records, or the account is inactive.")
            else:
                self.login_error.config(text=error.first_message if isinstance(error, ApiError) else str(error))

        self._run(lambda: self.client.login(email, password), done, failed)

    def _expired(self):
        settings.clear_token()
        self.client.token = ""
        self._show_login("Your session has ended. Please sign in again.")

    def logout(self):
        if not messagebox.askyesno("Sign out", "Sign out of SWS HR on this computer?"):
            return
        self._run(self.client.logout, lambda _r: None, lambda _e: None)
        settings.clear_token()
        self._show_login()

    # Main window -----------------------------------------------------------
    def _show_main(self):
        self._clear()
        header = tk.Frame(self.container, bg=NAVY, padx=20, pady=14)
        header.pack(fill="x")
        brand = tk.Frame(header, bg=NAVY)
        brand.pack(side="left")
        self.avatar = tk.Label(brand, text="SW", font=("Segoe UI", 13, "bold"), fg=NAVY, bg="#5b8cff", width=3, height=1)
        self.avatar.pack(side="left", padx=(0, 12), ipady=6)
        names = tk.Frame(brand, bg=NAVY)
        names.pack(side="left")
        self.title_label = tk.Label(names, text="SWS HR", font=("Segoe UI", 14, "bold"), fg="white", bg=NAVY, anchor="w")
        self.title_label.pack(anchor="w")
        self.subtitle_label = tk.Label(names, text="HR & Work Management", font=("Segoe UI", 9), fg="#9db3d6", bg=NAVY, anchor="w")
        self.subtitle_label.pack(anchor="w")
        ttk.Button(header, text="Sign out", style="Header.TButton", command=self.logout).pack(side="right")
        ttk.Button(header, text="Refresh", style="Header.TButton", command=self.refresh).pack(side="right", padx=8)
        ttk.Button(header, text="Daily status", style="Header.TButton", command=self._open_daily_status).pack(side="right")
        self.autostart_var = tk.BooleanVar(value=autostart.is_enabled())
        tk.Checkbutton(header, text="Open at Windows start", variable=self.autostart_var, command=self._toggle_autostart,
                       bg=NAVY, fg="#dce6f5", selectcolor=NAVY, activebackground=NAVY, activeforeground="white", font=("Segoe UI", 9)).pack(side="right", padx=12)

        # Packed before the body so a small window shrinks the task list, never the status/error line.
        footer = tk.Frame(self.container, bg="#e9edf4", padx=16, pady=6)
        footer.pack(fill="x", side="bottom")
        self.flash_label = tk.Label(footer, text="", bg="#e9edf4", fg=INK, anchor="w")
        self.flash_label.pack(side="left")
        self.sync_label = tk.Label(footer, text="", bg="#e9edf4", fg=MUTED)
        self.sync_label.pack(side="right")
        ttk.Button(footer, text="Sync now", command=self.sync_now).pack(side="right", padx=8)

        body = tk.Frame(self.container, bg=CANVAS, padx=18, pady=16)
        body.pack(fill="both", expand=True)

        shell = tk.Frame(body, bg=LINE, padx=1, pady=1)   # 1px border ring
        shell.pack(fill="x")
        att = tk.Frame(shell, bg="white", padx=22, pady=20)
        att.pack(fill="x")
        left = tk.Frame(att, bg="white")
        left.grid(row=0, column=0, sticky="w")
        tk.Label(left, text="TODAY", fg=MUTED, bg="white", font=("Segoe UI", 8, "bold")).pack(anchor="w")
        self.clock_label = tk.Label(left, text="--:--:--", font=("Segoe UI", 34, "bold"), fg=NAVY, bg="white")
        self.clock_label.pack(anchor="w")
        self.status_pill = tk.Label(left, text="Not checked in", fg=MUTED, bg="#eef1f6", font=("Segoe UI", 9, "bold"), padx=10, pady=3)
        self.status_pill.pack(anchor="w", pady=(2, 0))
        self.session_label = tk.Label(left, text="", fg=INK2, bg="white", font=("Segoe UI", 10))
        self.session_label.pack(anchor="w", pady=(6, 0))
        self.screen_label = tk.Label(left, text="", fg=MUTED, bg="white", font=("Segoe UI", 9))
        self.screen_label.pack(anchor="w")
        self.capture_label = tk.Label(left, text="", fg=AMBER, bg="white", wraplength=460, justify="left", font=("Segoe UI", 9))
        self.capture_label.pack(anchor="w")
        self.app_usage_label = tk.Label(left, text="", fg=MUTED, bg="white", wraplength=460, justify="left", font=("Segoe UI", 9))
        self.app_usage_label.pack(anchor="w")
        buttons = tk.Frame(att, bg="white")
        buttons.grid(row=0, column=1, sticky="ne", padx=(20, 0))
        att.columnconfigure(0, weight=1)
        self.att_off_label = tk.Label(buttons, text="", fg=AMBER, bg="white", wraplength=210, justify="right", font=("Segoe UI", 9))
        self.att_off_label.pack(side="bottom")
        self.att_buttons = {}
        for action in ("check_in", "break_start", "break_end", "check_out"):
            look = {"check_in": "Primary.TButton", "break_end": "Success.TButton", "check_out": "Danger.TButton"}.get(action, "TButton")
            button = ttk.Button(buttons, text=ATTENDANCE_LABEL[action], style=look, command=lambda a=action: self.attendance(a), width=16)
            self.att_buttons[action] = button

        tshell = tk.Frame(body, bg=LINE, padx=1, pady=1)
        tshell.pack(fill="both", expand=True, pady=(14, 0))
        tasks_frame = tk.Frame(tshell, bg="white", padx=18, pady=14)
        tasks_frame.pack(fill="both", expand=True)
        top = tk.Frame(tasks_frame, bg="white")
        top.pack(fill="x")
        tk.Label(top, text="My open tasks", font=("Segoe UI", 12, "bold"), fg=NAVY, bg="white").pack(side="left")
        ttk.Button(top, text="+ Add task", style="Ghost.TButton", command=self._open_add_task).pack(side="left", padx=12)
        self.task_buttons = {
            "complete": ttk.Button(top, text="Complete", command=lambda: self.task_action("complete")),
            "pause": ttk.Button(top, text="Pause", command=lambda: self.task_action("pause")),
            "start": ttk.Button(top, text="Start / Resume", style="Primary.TButton", command=lambda: self.task_action("start")),
        }
        self.task_buttons["complete"].pack(side="right")
        self.task_buttons["pause"].pack(side="right", padx=6)
        self.task_buttons["start"].pack(side="right")
        self.task_hint = tk.Label(tasks_frame, text="Select a task, then use the buttons above (double-click starts or pauses its timer).", fg=MUTED, bg="white", anchor="w")
        self.task_hint.pack(fill="x", pady=(6, 0))
        columns = ("title", "project", "due", "logged", "status")
        self.tree = ttk.Treeview(tasks_frame, columns=columns, show="headings", selectmode="browse", height=8)
        for column, title, width in (("title", "Task", 260), ("project", "Project", 130), ("due", "Due", 90), ("logged", "Logged", 70), ("status", "Status", 110)):
            self.tree.heading(column, text=title)
            self.tree.column(column, width=width, anchor="w")
        self.tree.tag_configure("running", foreground=GREEN)
        self.tree.tag_configure("overdue", foreground=RED)
        self.tree.tag_configure("odd", background=STRIPE)
        self.tree.tag_configure("even", background="white")
        self.tree.pack(fill="both", expand=True, pady=(8, 0))
        self.tree.bind("<<TreeviewSelect>>", lambda _e: self._update_task_buttons())
        self.tree.bind("<Double-1>", lambda _e: self._toggle_selected_task())

        self._tick()
        self.root.after(SYNC_INTERVAL_MS, self._auto_sync)
        self.root.after(PRESENCE_INTERVAL_MS, self._watch_presence)
        self._render_sync()

    # Away time ----------------------------------------------------------------
    def _watch_presence(self):
        if not self.clock_label.winfo_exists():
            return
        try:
            working = (self.session or {}).get("status") == "working"
            now = datetime.now().astimezone()
            idle_for, locked = (presence.seconds_since_input(), presence.is_locked()) if working else (0.0, False)
            if working and self.last_presence_poll:
                elapsed = min(max((now - self.last_presence_poll).total_seconds(), 0), 10)  # a long gap is sleep: not counted
                if locked or idle_for >= SCREEN_IDLE_AFTER_SECONDS:
                    self.screen_idle += elapsed
                else:
                    self.screen_active += elapsed
                    if self.app_usage_on:
                        app_name = presence.foreground_app()
                        if app_name:
                            self.app_usage[app_name] = self.app_usage.get(app_name, 0.0) + elapsed
                            if self.window_titles_on:
                                title = presence.foreground_window_title()
                                if title:
                                    key = (app_name, title)
                                    self.window_titles[key] = self.window_titles.get(key, 0.0) + elapsed
            self.last_presence_poll = now if working else None
            if not working:
                self.last_capture = None
            elif self.last_capture is None:
                self.last_capture = now  # count the interval from when work (or the app) starts
            elif presence.should_capture(now, self.last_capture, self.capture_policy, (self.session or {}).get("status"), idle_for, locked):
                self.last_capture = now
                self._capture(now)
            if working and not self.away_prompt_open:
                period = self.away_tracker.feed(now, idle_for, locked)
                if period and period.reason == "asleep":
                    # The server records gaps in the app's reports as a break by itself (see AttendanceService).
                    self._flash(f"Computer was off/asleep {period.start.strftime('%I:%M %p')}-{period.end.strftime('%I:%M %p')}: recorded as a break. Ask for a correction if you were working.", AMBER)
                    self.root.after(35_000, self.refresh)
                elif period:
                    self._ask_about_away(period)
            elif (self.session or {}).get("status") != "working":
                self.away_tracker.reset()
        finally:
            self.root.after(PRESENCE_INTERVAL_MS, self._watch_presence)

    def _ask_about_away(self, period: "presence.AwayPeriod"):
        """The employee decides whether the away time was a break; nothing is recorded without a yes."""
        self.away_prompt_open = True
        try:
            self.root.deiconify()
            self.root.lift()
            span = f"{period.start.strftime('%I:%M %p')} to {period.end.strftime('%I:%M %p')}"
            answer = messagebox.askyesno("Welcome back", f"You were away from {span} ({period.minutes} min, {AWAY_REASON[period.reason]}).\n\nCount this time as a break?\n\nChoose No if you were working (for example in a meeting).", parent=self.root)
        finally:
            self.away_prompt_open = False
        if not answer:
            self._flash("Kept as working time.")
            return

        def done(session):
            self._set_session(session)
            self._flash(f"Recorded {period.minutes} min as a break.", GREEN)
            self.refresh()

        def failed(error):
            text = "You are offline, so the break was not recorded. Ask for an attendance correction if needed." if isinstance(error, ApiError) and error.is_network else (error.first_message if isinstance(error, ApiError) else str(error))
            self._flash(text, RED)

        self._run(lambda: self.client.away(period.start.isoformat(), period.end.isoformat(), period.reason), done, failed)

    # Add task ---------------------------------------------------------------
    def _open_add_task(self):
        win = tk.Toplevel(self.root, bg="white", padx=18, pady=14)
        win.title("Add task")
        win.transient(self.root)
        win.resizable(False, False)
        win.geometry("420x380")
        tk.Label(win, text="New task", font=("Segoe UI", 13, "bold"), fg=NAVY, bg="white").pack(anchor="w")
        tk.Label(win, text="What are you working on? *", bg="white", fg=INK, font=("Segoe UI", 9, "bold")).pack(anchor="w", pady=(10, 2))
        title_entry = tk.Entry(win, font=("Segoe UI", 10), relief="solid", bd=1)
        title_entry.pack(fill="x")
        tk.Label(win, text="Priority", bg="white", fg=INK, font=("Segoe UI", 9, "bold")).pack(anchor="w", pady=(10, 2))
        priority = tk.StringVar(value="medium")
        ttk.Combobox(win, textvariable=priority, values=["low", "medium", "high", "urgent"], state="readonly").pack(fill="x")

        # Due date picker (optional): day / month / year drop-downs, no typing.
        today = datetime.now()
        months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]
        set_due = tk.BooleanVar(value=False)
        day_v = tk.StringVar(value=str(today.day))
        mon_v = tk.StringVar(value=months[today.month - 1])
        year_v = tk.StringVar(value=str(today.year))

        tk.Checkbutton(win, text="Set a due date", variable=set_due, bg="white", fg=INK,
                       font=("Segoe UI", 9, "bold"), activebackground="white",
                       command=lambda: _toggle_due()).pack(anchor="w", pady=(10, 2))
        picker = tk.Frame(win, bg="white")
        picker.pack(fill="x")
        day_cb = ttk.Combobox(picker, textvariable=day_v, values=[str(d) for d in range(1, 32)], state="disabled", width=4)
        mon_cb = ttk.Combobox(picker, textvariable=mon_v, values=months, state="disabled", width=6)
        year_cb = ttk.Combobox(picker, textvariable=year_v, values=[str(y) for y in range(today.year, today.year + 3)], state="disabled", width=7)
        day_cb.pack(side="left")
        mon_cb.pack(side="left", padx=6)
        year_cb.pack(side="left")

        def _toggle_due():
            state = "readonly" if set_due.get() else "disabled"
            for cb in (day_cb, mon_cb, year_cb):
                cb.config(state=state)

        note = tk.Label(win, text="", bg="white", fg=MUTED, wraplength=380, justify="left")
        note.pack(anchor="w", pady=(10, 0))

        def save():
            title = title_entry.get().strip()
            if not title:
                note.config(text="Give the task a title.", fg=RED)
                return
            due = None
            if set_due.get():
                due = f"{int(year_v.get()):04d}-{months.index(mon_v.get()) + 1:02d}-{int(day_v.get()):02d}"
            note.config(text="Saving...", fg=MUTED)

            def done(_):
                self._flash("Task added.", GREEN)
                win.destroy()
                self.refresh()

            self._run(lambda: self.client.create_task(title, priority.get(), due), done,
                      lambda e: note.config(text=(e.first_message if isinstance(e, ApiError) else str(e)), fg=RED))

        buttons = tk.Frame(win, bg="white")
        buttons.pack(fill="x", side="bottom", pady=(14, 0))
        ttk.Button(buttons, text="Cancel", command=win.destroy).pack(side="right")
        ttk.Button(buttons, text="Add task", style="Primary.TButton", command=save).pack(side="right", padx=8)
        title_entry.focus_set()

    # Daily status -----------------------------------------------------------
    def _open_daily_status(self):
        win = tk.Toplevel(self.root, bg="white", padx=18, pady=14)
        win.title("Daily status")
        win.transient(self.root)
        win.geometry("460x600")
        tk.Label(win, text="Today's status", font=("Segoe UI", 13, "bold"), fg=NAVY, bg="white").pack(anchor="w")
        boxes = {}
        for key, label, height in (("yesterday", "Yesterday", 2), ("plan", "Plan for today", 3), ("summary", "Completed *", 5), ("blockers", "Blockers (your manager is notified)", 3), ("notes", "Notes", 2)):
            tk.Label(win, text=label, bg="white", fg=INK, font=("Segoe UI", 9, "bold")).pack(anchor="w", pady=(8, 2))
            box = tk.Text(win, height=height, wrap="word", font=("Segoe UI", 10), relief="solid", bd=1, highlightthickness=0)
            box.pack(fill="x")
            boxes[key] = box
        note = tk.Label(win, text="", bg="white", fg=MUTED, wraplength=420, justify="left")
        note.pack(anchor="w", pady=(8, 0))

        def fill(result):
            today = (result or {}).get("today") or {}
            for key, box in boxes.items():
                if today.get(key):
                    box.insert("1.0", today[key])

        def save():
            values = {k: b.get("1.0", "end").strip() for k, b in boxes.items()}
            if len(values["summary"]) < 5:
                note.config(text="Write what you completed today.", fg=RED)
                return
            note.config(text="Saving...", fg=MUTED)

            def done(_):
                self._flash("Daily status saved." + (" Your manager was told about the blocker." if values["blockers"] else ""), GREEN)
                win.destroy()

            self._run(lambda: self.client.save_daily_status(values["plan"], values["summary"], values["blockers"], values["notes"], values["yesterday"]), done,
                      lambda e: note.config(text=("You are offline; try again when connected." if isinstance(e, ApiError) and e.is_network else (e.first_message if isinstance(e, ApiError) else str(e))), fg=RED))

        ttk.Button(win, text="Save status", style="Primary.TButton", command=save).pack(fill="x", pady=(12, 0))
        self._run(self.client.daily_status, fill, lambda _e: None)

    # Tray -------------------------------------------------------------------
    def _start_tray(self):
        self.tray = Tray(lambda fn: self.root.after(0, fn), self._show_window, self._quit)
        if self.tray.start():
            self.root.protocol("WM_DELETE_WINDOW", self._hide_to_tray)

    def _show_window(self):
        self.root.deiconify()
        self.root.lift()

    def _hide_to_tray(self):
        self.root.withdraw()
        if not getattr(self, "_tray_hint_shown", False):
            self._tray_hint_shown = True
            self.tray.notify("SWS HR keeps running here (timer, sync). Right-click the tray icon to quit.")

    def _quit(self):
        if (self.session or {}).get("status") in ("working", "on_break"):
            self._show_window()
            if not messagebox.askyesno("Quit SWS HR", "You are still checked in. Quitting stops the timer reports; the time will count as a break until you open the app again.\n\nQuit anyway?", parent=self.root):
                return
        self.tray.stop()
        self.root.destroy()

    # Screen capture ---------------------------------------------------------
    def _apply_capture_policy(self, policy: dict | None):
        self.capture_policy = policy
        if not hasattr(self, "capture_label") or not self.capture_label.winfo_exists():
            return
        if not policy or not policy.get("enabled"):
            self.capture_label.config(text="")
            return
        window = f", {policy['window_start']}-{policy['window_end']}" if policy.get("window_start") else ""
        self.capture_label.config(text=f"Screen capture on: every {policy['interval_minutes']} min while working{window}. Not during breaks or after check-out.")

    def _capture(self, at: datetime):
        def work():
            return self.client.upload_screenshot(presence.capture_screen_jpeg(), at.isoformat())

        def failed(error):
            # Not queued offline: a capture that cannot be sent now is simply skipped.
            if isinstance(error, ApiError) and not error.is_network:
                self._flash(f"Screen capture skipped: {error.first_message}", MUTED)

        self._run(work, lambda _r: None, failed)

    def _toggle_autostart(self):
        wanted = self.autostart_var.get()

        def done(_):
            self._flash("SWS HR will open when you sign in to Windows." if wanted else "SWS HR will no longer open with Windows.", GREEN)

        def failed(error):
            self.autostart_var.set(autostart.is_enabled())
            self._flash(f"Could not change the Windows start-up setting ({error.__class__.__name__}).", RED)

        self._run(autostart.enable if wanted else autostart.disable, done, failed)

    def _prompt_check_in(self):
        """Opened with Windows and not checked in yet: ask (never check in automatically)."""
        self.check_in_prompted = True
        self.root.deiconify()
        self.root.lift()
        self.root.attributes("-topmost", True)
        self.root.after(500, lambda: self.root.attributes("-topmost", False))
        name = (self.me or {}).get("name", "").split(" ")[0]
        greeting = f"Good {'morning' if datetime.now().hour < 12 else 'day'}{', ' + name if name else ''}!"
        if messagebox.askyesno("SWS HR", f"{greeting}\n\nYou have not checked in today. Check in now?", parent=self.root):
            self.attendance("check_in")

    def _flash(self, text: str, color: str = INK):
        if hasattr(self, "flash_label") and self.flash_label.winfo_exists():
            self.flash_label.config(text=text, fg=color)

    # Data ------------------------------------------------------------------
    def refresh(self):
        def work():
            return self.client.me(), self.client.today(), self.client.tasks()

        def done(result):
            self.online = True
            self.me, session, self.tasks = result
            minutes = int((self.me.get("policies") or {}).get("away_prompt_minutes", 10))
            self._apply_capture_policy((self.me.get("policies") or {}).get("screen_capture"))
            self.app_usage_on = bool((self.me.get("policies") or {}).get("app_usage"))
            self.window_titles_on = bool((self.me.get("policies") or {}).get("window_titles"))
            sources = (self.me.get("policies") or {}).get("attendance_sources")
            self.desktop_attendance_allowed = (not isinstance(sources, list)) or ("desktop" in sources)
            if hasattr(self, "app_usage_label") and self.app_usage_label.winfo_exists():
                if self.app_usage_on and self.window_titles_on:
                    self.app_usage_label.config(text="Application usage AND the active window/tab title are recorded while you work.")
                elif self.app_usage_on:
                    self.app_usage_label.config(text="Application usage is recorded while you work: app name and time only, no titles or content.")
                else:
                    self.app_usage_label.config(text="")
            if self.away_tracker.threshold.total_seconds() != minutes * 60:
                self.away_tracker = presence.AwayTracker(minutes)
            self._set_session(session)
            name = self.me.get("name", "SWS HR")
            self.title_label.config(text=name)
            code = self.me["employee"]["employee_code"] if self.me.get("employee") else None
            self.subtitle_label.config(text=" · ".join(filter(None, [code, ", ".join(self.me.get("roles", [])) or "Employee"])))
            initials = "".join(part[0] for part in name.split()[:2]).upper() or "SW"
            self.avatar.config(text=initials)
            self._render_tasks()
            self._render_sync()
            if not self.me.get("employee"):
                self._flash("This account has no employee profile — attendance and tasks are unavailable.", AMBER)
            elif self.launched_at_startup and not self.check_in_prompted and not session and self.desktop_attendance_allowed:
                self._prompt_check_in()
            self._check_for_update()

        def failed(error):
            if isinstance(error, ApiError) and error.is_network:
                self.online = False
                self._flash("Offline — actions will be saved and synced later.", AMBER)
                self._render_sync()
            else:
                self._flash(error.first_message if isinstance(error, ApiError) else str(error), RED)

        self._run(work, done, failed)

    def _check_for_update(self):
        """Once per run: offer a newer version if the server publishes one."""
        if getattr(self, "update_checked", False):
            return
        self.update_checked = True

        def offer(latest):
            update = updater.available_update(latest)
            if not update:
                return
            notes = f"\n\n{update['notes']}" if update.get("notes") else ""
            if not messagebox.askyesno("SWS HR update", f"Version {update['version']} is available (you have {VERSION}).{notes}\n\nInstall it now? SWS HR will close and reopen.", parent=self.root):
                return
            self._flash("Downloading the update…", INK)
            self._run(lambda: updater.download(update), install, lambda error: self._flash(f"Update failed: {error}", RED))

        def install(path):
            updater.run_installer(path)
            self._quit()

        self._run(self.client.latest_desktop, offer, lambda error: None)

    def _set_session(self, session: dict | None):
        self.session = session
        self.session_fetched_at = datetime.now(timezone.utc)
        for button in self.att_buttons.values():
            button.pack_forget()
        # Today's time/status always shows; the buttons depend on the admin setting.
        if not session:
            self.status_pill.config(text="Not checked in", fg=MUTED, bg="#eef1f6")
            self.session_label.config(text="")
            if self.desktop_attendance_allowed:
                self.att_buttons["check_in"].pack(fill="x", pady=4)
        else:
            check_in = parse_time(session.get("check_in"))
            check_out = parse_time(session.get("check_out"))
            fg, bg = STATUS_TONE.get(session["status"], (MUTED, "#eef1f6"))
            self.status_pill.config(text=STATUS_LABEL.get(session["status"], session["status"]), fg=fg, bg=bg)
            text = f"In {check_in.astimezone().strftime('%I:%M %p') if check_in else '—'}"
            if check_out:
                text += f"   ·   Out {check_out.astimezone().strftime('%I:%M %p')}"
            self.session_label.config(text=text)
            if getattr(self, "tray", None):
                self.tray.set_title(f"SWS HR - {STATUS_LABEL.get(session['status'], session['status'])}")
            if self.desktop_attendance_allowed and not check_out:
                self.att_buttons["break_end" if session["status"] == "on_break" else "break_start"].pack(fill="x", pady=4)
                self.att_buttons["check_out"].pack(fill="x", pady=4)
        # A small note when the admin has turned desktop attendance off (buttons hidden, time still shown).
        if hasattr(self, "att_off_label") and self.att_off_label.winfo_exists():
            self.att_off_label.config(text="" if self.desktop_attendance_allowed else "Attendance from the desktop app is turned off for you. Use the website or mobile app.")

    def _tick(self):
        if not self.clock_label.winfo_exists():
            return
        session = self.session
        if session:
            worked = int(session.get("worked_seconds") or session.get("active_seconds") or 0)
            if session.get("status") == "working" and session.get("id"):
                worked += int((datetime.now(timezone.utc) - self.session_fetched_at).total_seconds())
            self.clock_label.config(text=f"{worked // 3600:02d}:{worked % 3600 // 60:02d}:{worked % 60:02d}")
        else:
            self.clock_label.config(text="--:--:--")
        if session:
            active = int(session.get("screen_active_seconds") or 0) + int(self.screen_active)
            idle = int(session.get("screen_idle_seconds") or 0) + int(self.screen_idle)
            self.screen_label.config(text=f"Screen time today: {active // 3600}:{active % 3600 // 60:02d} in use · {idle // 3600}:{idle % 3600 // 60:02d} idle" if active + idle else "Screen time starts counting while you are checked in.")
        else:
            self.screen_label.config(text="")
        self.root.after(1000, self._tick)

    def _selected_task(self) -> dict | None:
        selected = self.tree.selection()
        return next((t for t in self.tasks if str(t["id"]) == selected[0]), None) if selected else None

    def _update_task_buttons(self):
        task = self._selected_task()
        running = bool(task and task.get("timer_running_since"))
        self.task_buttons["start"].state(["!disabled"] if task and not running else ["disabled"])
        self.task_buttons["pause"].state(["!disabled"] if running else ["disabled"])
        self.task_buttons["complete"].state(["!disabled"] if task else ["disabled"])

    def _toggle_selected_task(self):
        task = self._selected_task()
        if task:
            self.task_action("pause" if task.get("timer_running_since") else "start")

    def _render_tasks(self):
        selected = self.tree.selection()
        self.tree.delete(*self.tree.get_children())
        self.task_hint.config(text="No open tasks assigned to you." if not self.tasks else "Select a task, then use the buttons above (double-click starts or pauses its timer).")
        for index, task in enumerate(self.tasks):
            logged = int(task.get("logged_seconds") or 0)
            status = "● running" if task.get("timer_running_since") else ("overdue" if task.get("overdue") else task["status"].replace("_", " ").title())
            stripe = "odd" if index % 2 else "even"
            tags = (("running", stripe) if task.get("timer_running_since") else ("overdue", stripe) if task.get("overdue") else (stripe,))
            due = datetime.strptime(task["due_on"][:10], "%Y-%m-%d").strftime("%d %b %Y") if task.get("due_on") else "—"
            self.tree.insert("", "end", iid=str(task["id"]), values=(task["title"], (task.get("project") or {}).get("name", "—"), due, f"{logged // 3600}:{logged % 3600 // 60:02d}", status), tags=tags)
        if selected and self.tree.exists(selected[0]):
            self.tree.selection_set(selected[0])
        self._update_task_buttons()

    def _render_sync(self):
        counts = self.offline.counts()
        parts = ["online" if self.online else "OFFLINE"]
        if counts["queued"]:
            parts.append(f"{counts['queued']} queued")
        if counts["conflict"] or counts["failed"]:
            parts.append(f"{counts['conflict'] + counts['failed']} need attention")
        self.sync_label.config(text=" · ".join(parts), fg=MUTED if self.online and not counts["queued"] else AMBER)

    # Actions ---------------------------------------------------------------
    def attendance(self, action: str):
        label = ATTENDANCE_LABEL[action]
        if action == "check_out" and not messagebox.askyesno("Check out", "This ends your work day. You cannot check in again today.\n\nCheck out now?"):
            return

        def done(session):
            self.online = True
            self._set_session(session)
            self._flash(f"{label} recorded.", GREEN)
            self.refresh()

        def failed(error):
            if isinstance(error, ApiError) and error.is_network:
                self.offline.enqueue_attendance(action, label)
                self.online = False
                self._apply_offline(action)
                self._flash(f"{label} saved offline — it will sync automatically.", AMBER)
                self._render_sync()
            else:
                message = error.first_message if isinstance(error, ApiError) else str(error)
                self._flash(message, RED)
                messagebox.showerror(label, f"{label} failed:\n\n{message}", parent=self.root)

        self._run(lambda: self.client.attendance(action), done, failed)

    def _apply_offline(self, action: str):
        now = datetime.now(timezone.utc).isoformat()
        session = dict(self.session or {"id": 0, "check_in": now, "check_out": None, "active_seconds": 0, "status": "working"})
        session["status"] = {"check_in": "working", "break_start": "on_break", "break_end": "working", "check_out": "completed"}[action]
        if action == "check_out":
            session["check_out"] = now
        self._set_session(session)

    def task_action(self, action: str):
        selected = self.tree.selection()
        if not selected:
            self._flash("Select a task first.", AMBER)
            return
        task = next(t for t in self.tasks if str(t["id"]) == selected[0])
        if action == "complete" and not messagebox.askyesno("Complete task", f"Mark “{task['title']}” as completed?"):
            return

        def done(_task):
            self.online = True
            self._flash({"start": "Timer started.", "pause": "Timer paused.", "complete": "Task completed."}[action], GREEN)
            self.refresh()

        def failed(error):
            if isinstance(error, ApiError) and error.is_network:
                kind = {"start": "task.resume" if task.get("logged_seconds") else "task.start", "pause": "task.pause", "complete": "task.complete"}[action]
                self.offline.enqueue(kind, f"{action} · {task['title']}", {}, entity_id=task["id"], base_version=task["server_version"])
                self.online = False
                self._flash("Saved offline — it will sync when the server is reachable.", AMBER)
                self._render_sync()
            else:
                self._flash(error.first_message if isinstance(error, ApiError) else str(error), RED)

        self._run(lambda: self.client.task(task["id"], action), done, failed)

    def sync_now(self):
        def done(accepted):
            self.online = True
            self._render_sync()
            self._flash(f"{accepted} offline action(s) synced." if accepted else "Nothing to sync.", GREEN if accepted else INK)
            if accepted:
                self.refresh()

        self._run(lambda: self.offline.flush(self.client), done)

    def _auto_sync(self):
        if not self.client.token or not hasattr(self, "sync_label") or not self.sync_label.winfo_exists():
            return
        if self.offline.counts()["queued"]:
            self.sync_now()
        elif self.session and self.session.get("id") and not self.session.get("check_out"):
            active, idle = int(self.screen_active), int(self.screen_idle)
            apps = {name: int(sec) for name, sec in self.app_usage.items() if int(sec) > 0}
            titles = [{"app": a, "title": t, "seconds": int(sec)} for (a, t), sec in self.window_titles.items() if int(sec) > 0] if self.window_titles_on else []

            def sent(session):
                self.online = True
                self.screen_active -= active
                self.screen_idle -= idle
                for name, sec in apps.items():
                    self.app_usage[name] = max(0.0, self.app_usage.get(name, 0.0) - sec)
                for row in titles:
                    key = (row["app"], row["title"])
                    self.window_titles[key] = max(0.0, self.window_titles.get(key, 0.0) - row["seconds"])
                if apps and self.app_usage_on:
                    self._run(lambda: self.client.report_app_usage(apps, titles), lambda _r: None, lambda _e: None)
                if session:
                    self.session = {**(self.session or {}), "screen_active_seconds": session.get("screen_active_seconds", 0), "screen_idle_seconds": session.get("screen_idle_seconds", 0)}

            self._run(lambda: self.client.heartbeat(active, idle), sent, lambda _e: None)
        self.root.after(SYNC_INTERVAL_MS, self._auto_sync)


SINGLE_INSTANCE_PORT = 47613


def _claim_single_instance() -> socket.socket | None:
    """Only one window per user session, so two timers never run side by side."""
    lock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        lock.bind(("127.0.0.1", SINGLE_INSTANCE_PORT))
    except OSError:
        lock.close()
        return None
    return lock


def main():
    at_startup = autostart.STARTUP_FLAG in sys.argv[1:]
    lock = _claim_single_instance()
    if lock is None:
        if not at_startup:
            hidden = tk.Tk()
            hidden.withdraw()
            messagebox.showinfo("SWS HR", "SWS HR is already open. Look for it in the taskbar.")
            hidden.destroy()
        return
    root = tk.Tk()
    DesktopApp(root, launched_at_startup=at_startup)
    root.mainloop()
    lock.close()


if __name__ == "__main__":
    main()
