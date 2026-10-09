# SWS HR — Implementation & QA Report

Date: 2026-10-05. See `AUDIT_REPORT.md` for the starting point.

## Status by component

| Component | Source | Implemented | Tested | Status |
|---|---|---|---|---|
| Web (Laravel 12 + Livewire 4) | Yes | 34 pages, permission-driven sidebar (16 groups), header, breadcrumbs, search, notifications, profile, modals, filters, pagination, toasts, responsive | 51 PHPUnit tests + every sidebar link rendered over HTTP for 5 roles + manual browser E2E | **COMPLETE (UAT candidate)** |
| API (Sanctum) | Yes | Auth, /me, employees, teams, attendance actions + history, tasks + timer, leave, overtime, approvals, payslips, notifications, offline sync, device sync | `ApiTest`, `AttendanceFlowTest`, readiness tests (401/403/422/2xx) | **COMPLETE (UAT candidate)** |
| React Native mobile (`mobile/`, Expo SDK 57) | Yes | Login, home, attendance (check-in/break/resume/check-out + 30-day history), tasks + live timer, leave, overtime, payslips, notifications, approvals, offline queue, secure token storage | `tsc` clean; full flow tested in a browser at phone size against the live API, including an offline action replayed after the server came back | **COMPLETE (tested on web build; device build via Expo Go/EAS not yet run)** |
| Windows desktop (`python/sws_desktop`, Python + Tk) | Yes | Login (DPAPI-encrypted token), live work clock, check-in/break/resume/check-out, task start/pause/complete, heartbeat, SQLite offline queue + auto sync | 6 unit tests + live end-to-end test against the API + real window driven on Windows | **COMPLETE** |
| Python | Desktop app + device client library | Yes | 9 unit tests + 1 live test pass | **COMPLETE** (client-side only; no server-side Python needed) |
| Database (MariaDB 10.4) | 25 migrations | Schema + FK + indexes; demo seeder | Fresh migrate + seed on MariaDB; SQLite in tests | **COMPLETE** |
| Offline sync | Server + web + mobile + desktop queues | Idempotent receipts, payload-hash conflicts, version conflicts (409), attendance replay with `occurred_at` (stored in app timezone) | API tests, browser, mobile (server stopped/restarted) and desktop live test | **COMPLETE** |

**Python is not required by the verified architecture** (no server-side Python component). React Native and Windows desktop sources are missing from this repository; the API they need is in place.

## Key fixes made

- Fatal Blade error on `/hr/people` (unclosed `@if`) — whole people/policy area was down.
- **Payroll:** approved deduction adjustments were *added* to net pay; now subtracted.
- **MariaDB:** `task_time_entries.started_at` and `payslips.issued_at` were implicitly `ON UPDATE CURRENT_TIMESTAMP`, so every timer stop/payslip update overwrote the start/issue time (found in browser QA). Converted to `DATETIME`.
- Vault: HR could reveal another user's *private* secret; denied reveals are now audited.
- Audit CSV exported blank columns; `EmployeeResource` crashed on `joined_at`; user API tokens could never read `/api/attendance`; notification links all went to one page; offline queue JS posted to `/work/undefined`.
- Compliance `max_amount = 0` meant "no penalty" instead of "no cap".
- Separation of duties: payroll calculator cannot approve own run; settlement preparer cannot approve it; nobody can approve their own request (Master Admin exempt for single-admin setups).
- Duplicate logic consolidated: attendance actions (`AttendanceService`), approvals (`ApprovalInbox`), reports + exports (`ReportService`), policy lookup (`PolicyConfigurationService`), settlement workflow (`FinalSettlementService`). Unused `employees` table/model removed.

## Tests

- `php artisan test`: **65 passed, 0 failed (1216 assertions)**.
- Python `python -m unittest test_desktop test_client`: **14 passed, 1 live test skipped without a server**.
- HTTP: every visible sidebar link returns 200 for Master Admin, HR, Accounts, Manager, Employee; 14 admin URLs return 403 for an employee.
- Browser (UAT DB `sws_hr_uat`, IST): admin creates employee (with validation errors first) → manager approves leave (reject requires note) and assigns task → new employee checks in (late flagged), starts timer, break pauses timer and blocks restart, resume, complete, daily status, offline-queued status (replayed without duplicate), check-out → leave + OT requests (duplicate OT blocked) → Accounts partial salary payment (overpayment blocked), payroll CSV equals DB → vault denies and audits unauthorised reveal → full offboarding (clearance, asset return, settlement from real data, HR approval, two payments, close) → offboarded employee cannot log in. Mobile width (375 px): no horizontal overflow, menu toggles.

## Remaining work / known limitations

1. Run the mobile app on a real Android/iOS device (Expo Go or an EAS build) and package the desktop app (e.g. PyInstaller) for distribution.
2. Payroll includes unpaid balances of locked payroll in the final settlement; paying the same balance from **Payments** as well would double-pay. Add a guard that closes payroll items included in an approved settlement.
3. Overtime multiplier default `1.5` is seeded as an editable employer default; statutory components ship empty by design.
4. Working-day helper is still duplicated in four services (behaviour identical) — candidate for a shared `WorkCalendar`.
5. Redundant duplicate unique indexes on `attendance_sessions` and `attendance_activity_events` (harmless).
6. Production: run `php artisan queue:work` and the scheduler; set `APP_URL`, `APP_TIMEZONE`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE` for HTTPS.

## Final status

Web, API, mobile (web build) and Windows desktop are implemented and tested. Before calling the whole system UAT-ready, run the mobile app once on a real phone (Expo Go) — it has only been exercised in the browser build here.

## Usability pass (2026-10-07)

- Mobile: calendar date pickers for leave From/To (To cannot be before From, auto-filled) and overtime work date (cannot be in the future; defaults to today in local time, not UTC); quick overtime chips (30 min–3 h); confirmations for check-out, task complete, leave cancel and sign-out; show/hide password; server address collapsed behind "Change"; server errors (5xx) show a plain message instead of SQL/debug text; home shows "Loading…" instead of an empty-state flash.
- Web: holidays are added with a date picker and listed as removable dates (was free-text YYYY-MM-DD); confirmations added to inline penalty, advance and salary-adjustment decisions, team archive, clearance "Mark done" and asset "Mark returned". All other date fields already use native date inputs.
- Desktop: check-out confirmation, "Show password", plain message for server errors.
- `start-uat.bat` also starts the XAMPP MariaDB (E:\xampp8.2.12) if it is not running and prints the phone (LAN) addresses.
- Second pass (all pages crawled as Master Admin and Employee, plus every page at 375 px width):
  - Web: permission names on Roles shown as readable labels (code kept as small text), compliance rules no longer show internal keys, audit report details shown as "Field: value" lines instead of raw JSON, cases filter labelled, master/detail layouts (payroll runs, offboarding cases, settlements) stack on phones (were overflowing 4–31 px), every end-date picker blocks dates before its start date.
  - Mobile: approve/reject asks for confirmation and a reject comment is required before sending; new leave/overtime requests appear in the list immediately; date picker uses the current `onValueChange`/`onDismiss` API (no deprecation warning).
  - Desktop: task due dates shown as "02 Oct 2026", task buttons enabled only when they apply, double-click toggles a task timer, "No open tasks" message.
- Desktop auto-start (2026-10-07): "Open when Windows starts" checkbox (per-user Startup-folder shortcut, `sws_desktop/autostart.py`); when opened at sign-in and not yet checked in, the app asks "Check in now?" (never checks in on its own); single-instance guard so two timers cannot run. Tests: 13 pass + 1 live test skipped.

## New HRMS features (2026-10-08)

| Feature | Web | API / mobile | Desktop | Tests |
|---|---|---|---|---|
| Attendance correction (forgot check-out / wrong time → manager approves → attendance updated) | Attendance → Corrections, "Correct" on My Attendance, Approval Center | `/api/attendance/corrections`, mobile screen + "Correct" on history | — | HrmsExtensionsTest (approve, missed day, reject needs note, no self-approval) |
| Away time: idle / screen locked / asleep | Settings → Work hours: minutes before asking (0 = off) | `POST /api/attendance/away` | Asks "Count this time as a break?" on return; only *when* input last happened is read, never what was typed; task timer is split so away time isn't logged on the task | API test + AwayTracker unit test + live prompt |
| Shifts & roster | Attendance → Shifts & Roster (shifts, weekly roster, assign by date range); late marking uses the shift | `/api/me` → `schedule` | — | shift/late test |
| Mobile check-in at office | Settings → Office locations (offices, radius, on/off) ; records show where | check-in/out send one position only when required; clear messages ("You are about 1.2 km from Head Office") | not location-checked | location test |
| Expense claims → payroll | Expenses → Expense Claims (receipt upload, approve/reject), payslip line "Expense reimbursements", Expenses report | `/api/expenses` (multipart), mobile screen with camera/photo | — | claim → approve → payroll net + no double pay on recalculation; receipt privacy |
| Announcements | Company → Announcements (everyone / department / team, pin, expiry), dashboard card, notification | `/api/announcements`, mobile screen + Home card | — | audience test |
| Holiday calendar for employees | Company → Holidays (names, weekly off); Settings → Calendar takes names | `/api/holidays`, mobile screen | — | holidays test |
| Birthdays & work anniversaries | Company → Celebrations, dashboard card; employee chooses to share (year never shown); daily `hr:celebrations` at 09:00 | `/api/celebrations`, mobile Home card | — | privacy + sent-once test |
| Recruitment | Recruitment → Job Openings, Candidates (pipeline board, CV upload, interviews, Hire → login + onboarding case), Interviews (feedback & rating; visible to assigned interviewers) | — | — | pipeline → hire test |
| Training | Training → My Training, Courses (HR catalogue; managers assign to own team), Training report | `/api/training`, mobile screen | — | assign / complete / team-only test |
| Org chart | Company → Org Chart (search, by department) | — | — | page test |

New permissions: `attendance.correct`, `shifts.manage`, `expenses.manage`, `announcements.manage`, `recruitment.manage`, `training.manage`, `training.assign`. Demo data: `HrmsExtensionsDemoSeeder` (fake names, @example.com).
Mobile adds `expo-location` and `expo-image-picker` (both available in Expo Go).

## Hard UAT cases (2026-10-09) — `tests/Feature/HardCasesTest.php` (18 tests)

| # | Case | Result |
|---|---|---|
| 1A | Night shift 23:00→07:00 | One session, attributed to the start date, 8 h; check-in blocked while it is open |
| 1B | Missing check-out | `attendance:close-stale` (every 15 min) closes sessions open > 16 h (Settings `max_session_hours`) at the **last activity**, never "now"; employee + manager notified; flagged "auto-closed" |
| 1C | Laptop shut down 13:00–18:00 | Gap in desktop reports ≥ away threshold is recorded as a break automatically (notification; correction possible) |
| 2 | Offline duplicate check-in | One session; duplicate returns `ignored` (not failed); retry safe; audited |
| 3 | Offline check-out after HR checked out | HR "Check out" button on Team Attendance; late offline check-out ignored, server state wins, audited |
| 4 | Break too long / break left open | Correction can set break minutes; open break closed at last activity by the stale job |
| 5/6 | Leave + attendance same day | Flagged `leave_conflict`; Attendance → Leave Conflicts: "Worked" (leave day refunded) or "Leave"; policy `leave_conflict` default attendance; never paid twice |
| 7 | Balance 2 + 1.75 + 3 − 5 = 1.75 | Verified; overdraw blocked unless advance leave allowed |
| 9/10/11 | OT vs attendance, duplicate OT, holiday OT | Approver sees that day's attendance and "claims more than attendance shows"; duplicates blocked; holiday/weekly-off rate replaces (never stacks) the normal rate; daily and monthly OT limits |
| 12 | Penalty waived after draft payroll | Approve / **Reduce** / Reject / **Waive**; draft run marked "recalculate", approval blocked until recalculated |
| 13 | Locked payroll attack | Salary, OT, penalty, adjustment, leave, correction changes for a locked/approved period → **409**; run untouched |
| 14 | Salary revision mid-month | Each day paid at the salary in effect that day (`salary_segments` in the breakdown) |
| 15 | Advance 50k / 10k | 10k × 5, then 0; never negative; status → `recovered`; recalculation never recovers twice |
| 16 | Partial payments | 20k + 26,038.47 then overpayment blocked |
| 17/18 | Manager changed mid-month | Reporting history kept; pending approvals move to the new manager; decided ones keep their actor; new manager's team views/reports start from the change date; old manager loses access |
| 19/20 | Manager is also employee; two approvers at once | No self-approval; second decision → **409** |
| 21/22 | Exit with lost laptop / pending finance | Asset "Lost" → Write off or Request recovery (salary-deduction approval, never direct); settlement blocked while assets or financial requests are pending; unpaid approved expenses included |
| 23/24 | Screen capture | Off by default; HR enables (5/10/15/30 min, active only, work window, retention); employee must acknowledge in the desktop app; server rejects captures before check-in, on break, after check-out, outside window or too soon; only the employee, their manager and `monitoring.view` (HR/Admin) can open them — others 403, Accounts 403; every view/download audited; expired → 410 and purged daily |
| 25 | Combined month | 50,000 / 26 days / 23 present / 1 paid leave / 2 unpaid / 8 h OT ×1.5 / bonus 3,000 / penalty 1,000 / advance 5,000 → base 46,153.85, OT 2,884.62, gross 52,038.47, **net 46,038.47** (hand calculation matches) → approve (not by the calculator) → lock → 2 payments → payslip |
