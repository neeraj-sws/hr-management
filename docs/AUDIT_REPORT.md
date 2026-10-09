# SWS HR — Codebase Audit (Phase 1 & 2)

Audit date: 2026-10-05. Baseline: commit `954679a` plus uncommitted working tree (≈8.7k lines).

## Platform coverage (as found, before changes)

| Component | Source Found | Current Status | Missing | Critical Issues |
|---|---|---|---|---|
| Web | Laravel 12 + Livewire 4, 4 Livewire components, inline CSS layout | BROKEN | Dedicated module pages, profile, roles/permissions UI, settings, report filters (employee/department/team), modals | `people-center.blade.php` has an unclosed `@if` → `/hr/people` throws a fatal view error (6 tests fail). Most sidebar links are `#anchors` into two "mega pages"; several point to sections that do not render for that role. "Coming soon" placeholders. Mojibake (`Â·`) throughout. |
| Laravel API | Sanctum token auth, employees, teams, attendance device register/sync, offline action sync | PARTIAL | Tasks, leave, overtime, payroll/payslips, notifications, approvals, profile (`/me`), web-style attendance actions for user tokens | User API tokens can never read `/api/attendance` (checks a non-existent `attendance:view` ability). `EmployeeResource` calls `->toDateString()` on an uncast `joined_at` string. |
| React Native | **Not found** (no `package.json` app, no `*.tsx/*.jsx`, no `android/` or `ios/`) | NOT IMPLEMENTED | Entire mobile application | — |
| Windows Desktop | **Not found** as an application. Only `python/api_client.py` + `python/device_token.py` (device registration/sync client library, DPAPI token storage) | NOT IMPLEMENTED (client library only) | UI, tray agent, activity tracking, local queue, packaging | README notes the real checkout/SQLite agent was never copied into this repo |
| Python | `python/` — 2 modules + 3 unit tests, `requests` dependency | PARTIAL (library) | Running agent/service | Python is only the client side of the Windows attendance-device API. No server-side Python service is required by the verified architecture. |
| Database | 23 migrations, MariaDB/MySQL (`sws_hr_new`) all ran | PARTIAL | Seed/demo data | Unused `employees` table + empty `Employee` model (duplicate of `attendance_employees`). Duplicate unique indexes on `attendance_sessions(employee_id, work_date)` and `attendance_activity_events(session,type,time)` (harmless, redundant). |
| Offline Sync | Server: receipts + payload hash + version conflicts for attendance, tasks, daily status. Browser: `public/js/offline-action-queue.js` (localStorage) | PARTIAL | Mobile/desktop queues (no clients exist) | Server side is sound (idempotent replay + 409 conflicts). |

**Python is not required by the verified architecture** as a server component. The existing Python code is a client library for the (missing) Windows attendance agent.

**React Native source is missing. Windows Desktop application source is missing.**

## Dependency map (server)

```
Livewire pages ──► Services ──► Models ──► MariaDB
                    │  HrWorkflowService      employees, tasks/timer, leave request/decide, payroll calc/approve/lock/pay
                    │  PeopleOperationsService overtime, adjustments, advances, penalties, policies, compliance, lifecycle
                    │  LeaveAccrualService     monthly accrual, carry-forward + expiry, balance snapshot
                    │  FinalSettlementService  settlement from attendance/leave/OT/advances/locked payroll
                    │  PolicyConfigurationService global + employee-override effective-dated rules
                    └  AuditLogger             audit_logs
API controllers ──► same services (OfflineActionSyncController → HrWorkflowService)
Notifications: ApprovalRequested (queued, database channel, after commit)
Scheduler: leave:accrue monthly
```

## Duplicates / conflicts found

- Business logic duplicated in Livewire: attendance check-in/break/check-out lived only inside `Operations` (not reusable by the API); vault access rules duplicated in `PeopleCenter::canAccessCredential` and `CredentialVaultEntryPolicy` (and they disagree: HR could reveal another user's *private* secret).
- `policyConfiguration()` re-implemented in `HrWorkflowService` alongside `PolicyConfigurationService` (and with different ordering semantics).
- Report queries duplicated between `PeopleCenter::report()` and `ReportExportController`.
- Model `Employee` / table `employees` unused (real model is `AttendanceEmployee`).

## Bugs found

1. `people-center.blade.php` — unclosed `@if` around the vault section → fatal on `/hr/people`.
2. Audit CSV export reads non-existent columns (`user_id`, `auditable_type`, `auditable_id`) → blank columns.
3. `EmployeeResource` → fatal when `joined_at` is set (no date cast).
4. `/api/attendance` for user tokens requires an ability that is never issued.
5. Approval center hides manager-assigned leave from HR (`leave.manage`) users.
6. Vault: "private" entries revealable by any `hr.manage` user.
7. Notification links all point to `/hr/people`.
8. Layout mixes `@canany` with `@endcan`; "Coming soon" disabled items; "Profile details page: coming soon".

## Implementation plan (safe order)

1. Fix fatal Blade error and data bugs (keeps existing tests meaningful).
2. Extract attendance actions into `AttendanceService`; unify policy lookup on `PolicyConfigurationService`; centralize report queries in `ReportService`; centralize approval visibility in `ApprovalInbox`; vault rules in the policy only.
3. Replace the two mega pages with one Livewire page per module, a permission-driven sidebar (`config/navigation.php`) so no link is rendered without its route + permission.
4. Complete API (tasks, leave, OT, payslips, notifications, approvals, `/me`, user attendance actions).
5. Demo seeder (5 roles, 10 users, full fake data, `SEED_USER_PASSWORD`).
6. Port + extend tests; HTTP and browser QA.

Mobile and desktop clients are out of scope for this pass because no source exists; they are reported, not invented.
