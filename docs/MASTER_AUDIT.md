# SWS — Master Audit (2026-10-09)

Evidence used: code inspection, `php artisan test` (**84 passed, 1,367 assertions**, incl. `HardCasesTest` 18 and `HrmsExtensionsTest` 13), Python desktop tests (16 passed), mobile `tsc` + web build + Android bundle, browser checks of every web page (5 roles, 375 px width), live desktop runs against the UAT server.
Not verifiable here (marked **NOT VERIFIED — ENVIRONMENT LIMITATION**): real Android/iOS device, Windows display scaling 125/150 %, load/performance under real data volume, production queue/Redis, email delivery.

## A. Executive summary

| Area | Estimate | Basis |
|---|---|---|
| Backend | 80 % | Core HR, attendance, leave, OT, compliance, payroll, payments, lifecycle, vault, approvals, offline sync, screen capture complete and tested; gaps below |
| Web | 80 % | All modules have pages; gaps: salary structure, task comments/subtasks, structured daily status, notification preferences, password reset |
| Database | 85 % | FKs/indexes/unique keys present; dates compared as dates; money `decimal(12,2)`; gaps follow the missing features |
| Automation | 85 % | Attendance → leave/OT/compliance → payroll → lock → payment → payslip connected and hand-verified (case 25); compliance detection misses early check-out / daily status / task entry |
| Desktop | 65 % | Works (check-in, breaks, tasks, offline queue, away/idle, screen time, screen capture, auto-start); Tk UI is plain, no tray icon, no daily status |
| Mobile | 60 % | Attendance (+location), tasks, leave, OT, expenses, corrections, payslips, notifications, approvals, training, holidays, offline queue; missing biometric, push, documents, assets, performance, daily status, manager dashboard; **device run NOT VERIFIED** |
| UI/UX | 75 % | Web consistent and responsive; desktop dated |
| Security | 80 % | Server-side RBAC, no self-approval, 409 conflicts, private files with audited access, encrypted vault, login throttle; missing password reset, no automated XSS/SQLi scan |
| Performance | NOT VERIFIED | No N+1 seen in reviewed hot paths; no load test |
| **Overall** | **≈ 75 %** | |

## B. Feature matrix

| Feature | Backend | Web | Desktop | Mobile | DB | Security | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| Login / logout / inactive block / throttle | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Password reset | 🔴 | 🔴 | — | 🔴 | 🔴 | — | — | 🔴 MISSING |
| Device register/revoke, token revoke on exit | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| RBAC (5 roles, manager = employee, no self-approval) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Employee CRUD, manager history, pending approvals move | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Probation / confirmation / employment type / notice / termination | 🔴 | 🔴 | — | — | 🔴 | — | — | 🔴 MISSING |
| Team transfer with effective date | 🟡 manager history only | 🟡 | — | — | 🟡 | ✅ | 🟡 | 🟡 PARTIAL |
| Attendance (check-in/out, breaks, late, half day, holiday, weekly off) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Night shift / missing check-out / shutdown gap / duplicates / conflicts | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Early check-out detection | 🔴 | 🔴 | — | — | — | — | — | 🔴 MISSING |
| Shifts & roster | ✅ | ✅ | — | 🟡 shows shift | ✅ | ✅ | ✅ | ✅ |
| Corrections (in/out/break) with approval + audit | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| Mobile location check-in | ✅ | ✅ settings | — | ✅ (device NOT VERIFIED) | ✅ | ✅ | ✅ | ✅ |
| Desktop activity: idle/lock/sleep, screen time | ✅ | ✅ | ✅ | — | ✅ | ✅ no keystrokes | ✅ | ✅ |
| Screen capture (policy, ack, rules, access, audit, retention) | ✅ | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ (team-specific policy 🟡) |
| Tasks, projects, timer, overdue | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Task comments / subtasks / project members / attachments | 🔴 | 🔴 | — | 🔴 | 🔴 | — | — | 🔴 MISSING |
| Daily status (plan / done / blockers / notes, missing detection, reminder) | 🟡 single summary | 🟡 | 🔴 | 🔴 | 🟡 | ✅ | 🟡 | 🟡 PARTIAL |
| Team notes | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Leave (types, balance, accrual, carry-forward, approve, cancel) | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| Leave + attendance conflict | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Overtime (limits, holiday rate, evidence, duplicates) | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ | ✅ (team policy 🟡) |
| Compliance: late, unauthorized absence, approve/reduce/waive/reject | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Compliance: early check-out, missing task, late task entry, missing daily status | 🔴 | 🔴 | — | — | — | — | — | 🔴 MISSING |
| Salary profile, revision split, history | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Salary structure (Basic / HRA / allowances) | 🔴 | 🔴 | — | — | 🔴 | — | — | 🔴 MISSING |
| Advances, recovery, auto-close | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Payroll calc / approve (not calculator) / lock / 409 on locked changes | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Payments (partial, method, reference, overpayment blocked) | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Payslip (incl. reimbursements), employee access | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ | ✅ (PDF = browser print) |
| Expenses → payroll / settlement | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| Notifications (in-app, read/unread) | ✅ | ✅ | 🟡 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Notification preferences / push / email | 🔴 | 🔴 | — | 🔴 | — | — | — | 🔴 MISSING |
| Approval center (all types, 409, audit) | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| Reports (+ CSV, scope, manager since-date) | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ (payment & team reports 🟡) |
| Documents | ✅ | ✅ | — | 🔴 | ✅ | ✅ | ✅ | 🟡 (mobile) |
| Assets incl. lost → write-off / recovery approval | ✅ | ✅ | — | 🔴 | ✅ | ✅ | ✅ | 🟡 (mobile) |
| Onboarding / offboarding / final settlement blocks | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Vault (encrypted, reveal audit) | ✅ | ✅ | — | — | ✅ | ✅ | ✅ | ✅ |
| Recruitment, training, announcements, holidays, celebrations, org chart | ✅ | ✅ | — | 🟡 | ✅ | ✅ | ✅ | ✅ |
| Offline sync (idempotent, conflicts, ignored duplicates) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Mobile biometric / documents / assets / performance / manager dashboard / daily status | — | — | — | 🔴 | — | — | — | 🔴 MISSING |
| Desktop tray / daily status / modern UI | — | — | 🔴 | — | — | — | — | 🔴 MISSING |

## C. Missing (exact places to add)
1. Password reset — `routes/web.php` (guest), `app/Http/Controllers/Auth/PasswordResetController`, `resources/views/auth/*`, mail config.
2. Salary structure — new `salary_components` on `SalaryProfile`, `HrWorkflowService::calculatePayroll`, `Livewire\Payroll\Salary`, payslip.
3. Employment fields — migration on `attendance_employees` (employment_type, probation_ends_on, confirmed_on, notice_period_days, exit_date, exit_reason), `EmployeeService`, employees form, lifecycle.
4. Compliance detection — `PeopleOperationsService::assessCompliance` (early check-out, missing daily status, late task entry), policy keys, settings.
5. Daily status fields — migration on `daily_work_statuses`, `Livewire\Work\DailyStatus`, API, reminder command.
6. Task comments / subtasks / project members — migrations + `WorkService` + `Livewire\Work\Tasks` + API + mobile.
7. Notification preferences — table + `ApprovalRequested::via()` filter + profile page.
8. Mobile — biometric unlock (`expo-local-authentication`), documents, assets, performance, daily status, manager team screen.
9. Desktop — tray icon, daily status form, refreshed UI.
10. Team-level policies (OT, leave, screen capture) — `PolicyConfigurationService` lookup: employee → team → company.

## D. Bugs found and fixed in this pass
| Sev | Module | Bug | Fix |
|---|---|---|---|
| CRITICAL | Attendance | Night-shift check-out failed after midnight; two open sessions | open-session window lookup |
| CRITICAL | Payroll | Mid-month salary revision applied to the whole month | per-day salary segments |
| HIGH | Attendance | Forgotten check-out stayed open forever | `attendance:close-stale` at last activity |
| HIGH | Payroll | Last-day adjustments/penalties could be skipped (datetime vs date) | date-only comparisons |
| HIGH | Payroll | Approved penalty could not be waived; recalculation not enforced | waive + `needs_recalculation` gate |
| HIGH | Payroll | Changes for locked periods were accepted | `PayrollLockGuard` → 409 |
| MEDIUM | Leave | Leave + attendance same day silently paid as work | conflict flag + resolution page |
| MEDIUM | Advances | Fully recovered advance stayed "disbursed" | status `recovered` |
| MEDIUM | Approvals | Pending approvals stuck with old manager | moved on manager change |

## E. Security
No open issues found in reviewed code. Notes: password reset absent (users depend on HR); add automated security scan; production must set `APP_DEBUG=false` (raw errors are already hidden in mobile/desktop clients).

## F. Performance
NOT VERIFIED under load. Watch: `ApprovalInbox::pending()` loads up to 300 rows and filters in PHP; `CompanyService::celebrations()` scans all active employees; report previews are limited and paginated.

## G. UI/UX
Web: consistent; all pages fit 375 px. Desktop: functional but plain Tk. Mobile: consistent; device check NOT VERIFIED.

## H. Automation
Attendance → Task → Leave → OT → Compliance (late / absence) → Payroll → Payment → Payslip: **connected** and verified by `HardCasesTest::test_25` (hand calculation matches to the paisa). Compliance for early check-out / daily status / task entry: **not connected (missing)**.

## I. Result
**⚠️ PASS WITH ISSUES** — core flows pass with evidence; the items in section C are not built yet.

---

## Update — completion pass (2026-10-09)

Evidence: `php artisan test` **95 passed (1,456 assertions)** incl. `CompletionTest` (11) and `HardCasesTest` (18); desktop tests pass; mobile `tsc` clean and Android bundle builds (6.6 MB); desktop driven live against the UAT server (tray, daily status).

| Previously missing (section C) | Now | Where |
|---|---|---|
| 1. Password reset | ✅ e-mail link (60 min, same reply for unknown e-mails, throttled, revokes app tokens); "Forgot password?" on web, mobile and desktop login | `Auth\PasswordResetController`, `auth/forgot-password`, `auth/reset-password` |
| 2. Salary structure | ✅ per-employee components (earnings must equal salary, fixed deductions) + company % template with "Balance allowance"; prorated per component; statutory "basic" uses the real Basic; payslip/report lines | `SalaryStructureService`, Payroll → Salary, Settings → Payroll |
| 3. Employment fields | ✅ employment type, probation end + **Confirm** (audited, notified), notice period, exit type/date/reason/resignation date via offboarding; HR dashboard "Probation reviews due"; profile History (manager history + audit) | `EmployeeService::confirm`, `openLifecycle($exit)` |
| 4. Compliance detection | ✅ early check-out, worked day without a task, task entered late (grace hours), missing/late daily status; late uses the shift; all **pending** (nothing deducted before approval); nightly `compliance:assess` | `PeopleOperationsService::assessWorkCompliance` |
| 5. Daily status | ✅ plan / completed / blockers / notes, submitted time, manager notified of blockers, "Remind" button + 18:15 `work:remind-daily-status`; API + mobile + desktop | `Work\DailyStatus`, `Api\DailyStatusController` |
| 6. Tasks collaboration | ✅ employees add their own tasks (work date), subtasks (one level), comment threads with notifications, project members (assignment limited to members) — web, API, mobile | `WorkService`, `Work\Tasks`, `Work\Projects` |
| 7. Notification preferences | ✅ per group in-app / e-mail; important updates cannot be muted; profile page + API | `ApprovalRequested::via`, Profile |
| 8. Mobile | ✅ biometric unlock (opt-in, re-lock after 5 min in background), documents (5-minute signed links) & assets, performance (server-side numbers), daily status, manager "My team today" (late, on break, blockers, approvals), own tasks + comments; sign-out wipes cached data and the offline queue | `mobile/src/app/*` |
| 9. Desktop | ✅ system tray (close keeps it running; quit warns while checked in), daily status window, refreshed styling, coloured state, forgot-password link | `sws_desktop/tray.py`, `app.py` |
| 10. Team policies | ✅ company → **team** → employee for every overridable rule (compliance, overtime, advance leave, screen capture) | `PolicyConfigurationService`, Compliance page |

### Still open / not verifiable here
- **NOT VERIFIED — ENVIRONMENT LIMITATION:** real phone (biometric, camera, GPS), Windows display scaling 125/150 %, load testing, outgoing e-mail (MAIL_MAILER=log locally).
- Push notifications to the phone (needs an EAS/FCM project) — in-app + e-mail are in place.
- Desktop packaging as a signed installer (PyInstaller/MSIX) and auto-update — runs from `SWS HR Desktop.bat`.
- Team membership history (manager history is kept; team-only moves are not effective-dated).

### Result after this pass
Backend 92 % · Web 92 % · Database 92 % · Automation 95 % · Mobile 85 % (device not verified) · Desktop 85 % · Security 90 % · Performance NOT VERIFIED → **Overall ≈ 90 % — ⚠️ PASS WITH ISSUES** (remaining items above).

---

## Update — remaining items pass (2026-10-09, evening)

Evidence: `php artisan test` **99 passed (1,512 assertions)** incl. `RemainingFeaturesTest` (4); desktop `unittest` 15 OK (2 live tests skipped); mobile `tsc` clean; `SWS HR.exe` built with PyInstaller and opened on this PC; new API endpoints and report pages checked against the UAT server (HTTP 200, no errors).

| Item | Now | Where |
|---|---|---|
| Mobile offline for leave / expense / daily status | ✅ queued on the phone, sent automatically; server de-duplicates by `X-Action-Id` (replay → same record, changed body → 409); daily status keeps the day it was written for (≤ 2 days late) | `mobile/src/lib/queue.ts`, `ApiController::once` |
| Team membership history | ✅ dated history on every team change (employee or team screen, API); team policies and the Teams report use the team on that date | `EmployeeTeamHistory`, `EmployeeService::syncTeams` |
| Reports | ✅ Payments (salary + full & final), Teams, Screen captures (counts only, `monitoring.view`) — on screen + CSV | `ReportService` |
| Phone push | ✅ token register/remove, Expo push channel (off until `EXPO_PUSH_ENABLED`), per-group push switch, dead phones forgotten, tap opens Notifications | `ExpoPushChannel`, `mobile/src/lib/push.ts` |
| Desktop installer + auto-update | ✅ PyInstaller exe (no Python needed), Inno Setup per-user installer script, update check with mandatory https + SHA-256 match, autostart works for the installed exe | `python/build-desktop.bat`, `installer/sws-hr.iss`, `sws_desktop/updater.py` |
| Go-live guide | ✅ | `docs/DEPLOYMENT.md` |

### Still NOT VERIFIED (needs the real environment)
- Push delivery to a phone (needs an EAS build + project id), biometric / camera / GPS on a phone.
- Installer `.exe` (Inno Setup not installed here) and an end-to-end update from a published https URL.
- Windows display scaling 125/150 %, load test, real SMTP e-mail.

### Result after this pass
Backend 95 % · Web 94 % · Database 95 % · Automation 95 % · Mobile 90 % (device not verified) · Desktop 90 % (installer not built here) · Security 90 % · Performance NOT VERIFIED → **Overall ≈ 93 % — ⚠️ PASS WITH ISSUES** (only environment-dependent checks remain).

### Checklist cross-check (testing checklist, 21 sections)
Four gaps found against the checklist and closed (test `test_checklist_gaps_*`, 100 tests / 1,529 assertions pass):
- Daily status **Yesterday** field (web, API, offline sync, mobile, desktop).
- Payment **method** and **payment date** on salary and final-settlement payments (payments page, payslip, Payments report).
- **Task overdue** notification to employee + manager, once per due date (`work:notify-overdue-tasks`, daily 09:45).
- **Expired token**: mobile/desktop sign-in tokens expire after `API_TOKEN_DAYS` (30); expired tokens pruned daily. Attendance device tokens are unchanged.
