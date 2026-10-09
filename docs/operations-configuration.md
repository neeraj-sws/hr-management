# Payroll, leave, compliance, and offline operations

## Configuration that must come from the employer

The system intentionally has no default statutory tax, contribution, benefit, or withholding rates. A user with `policies.manage` must configure the global or employee-specific `payroll.statutory` rule using the requirements supplied for the employer's jurisdiction and payroll policy. Each component has a unique `key`, a display `name`, a `type` (`earning` or `deduction`), a `basis` (`basic_salary` or `gross`), and exactly one of `fixed_amount` or `rate_bps`; `cap_amount` is optional. Policy effective dates control which configuration is used. An empty `components` list means no statutory component is applied.

The existing weekday calendar defaults to ISO weekdays 1–5. Configure `calendar.working_days` globally or per employee with a `days` list (ISO weekday numbers 1–7) and optional `holidays` (`YYYY-MM-DD`). Payroll proration, leave-day counting, task-lateness day counts, unauthorized-absence checks, and final settlement use this calendar.

Leave accrual uses each leave type's `monthly_accrual`, `carry_forward`, `carry_forward_limit`, and optional `carry_forward_expiry_months`. Employee-specific overrides use `leave.accrual.<leave-type-id>` with optional `days_per_month`, `carry_forward_limit`, and `carry_forward_expiry_months`. Pending leave is reserved in the displayed/requestable balance. Paid leave consumes the balance on approval; unpaid leave is approved without consuming paid leave. `leave.advance_limit` controls permitted paid leave beyond the available balance.

Final settlement leave encashment is disabled unless `leave.final_settlement` is configured with `leave_encashment_enabled: true` and an employer-approved `rate_per_day`. No rate is supplied by the application.

Compliance checks are opt-in through global policy rules or employee overrides:

- `attendance.late_penalty`: `amount_per_minute`, optional `grace_minutes`, and optional `max_amount`.
- `task.late_penalty`: `amount_per_day` and optional `max_amount`.
- `attendance.unauthorized_absence`: `amount_per_day`.

Zero or missing penalty values do not create penalties. Assessments create pending approval requests and use source keys to prevent duplicate penalties on retries.

## Scheduled accrual and queues

`leave:accrue` processes monthly leave accrual idempotently through the current month. Laravel's scheduler runs it on the first of each month. Production hosts must run `php artisan schedule:run` every minute. Running `composer dev` locally starts Laravel, the database queue listener, log tailing, and Vite. Deployments using the database queue must run a queue worker; `QUEUE_CONNECTION=sync` is available for local setups without a worker.

Database notifications are queued after their surrounding transaction commits. The application includes notifications for approval requests and decisions, leave, overtime, payroll, salary payments, and task assignments.

## Offline action synchronization

The Daily Work Status page ("Save offline") stores queued actions in browser local storage and submits them when online; the browser session must be on a host listed in `SANCTUM_STATEFUL_DOMAINS` (defaults to `APP_URL`'s host). The API supports `task.start`, `task.pause`, `task.resume`, `task.complete`, `daily_status.submit`, `attendance.session` (device agents) and `attendance.check_in`, `attendance.break_start`, `attendance.break_end`, `attendance.check_out` (user tokens; `payload.occurred_at` must be within the last 48 hours). Task and daily-status actions require `action_id` and the last `base_version` (task actions also require `entity_id`). A repeated identical `action_id` returns its recorded response; reusing it with changed data returns a conflict. A stale task or daily-status version returns the authoritative server state/version so the client can refresh and submit a new action ID. Attendance session actions still require a registered attendance-device token with `attendance:sync`; attendance uses its existing version and sync receipt protection.

The browser queue retries transient/network errors, retains conflicts and permanent validation/authentication failures for review, and exposes local queue and server status methods through `window.SwsOfflineQueue`. Bearer tokens, when used by a client, should be supplied at runtime and not embedded in source.

## Report downloads

Authenticated CSV downloads are available for every report type (attendance, leave, tasks, overtime, payroll, salary, performance, productivity, compliance, audit, employees) at `/reports/{type}/export?from=YYYY-MM-DD&to=YYYY-MM-DD`, with optional `employee_id`, `department_id`, `team_id` and `search`. The on-screen report and the CSV use the same query (`ReportService`). Report permissions and team scoping are checked by the server and each export is audited. CSV is UTF-8 with a BOM for spreadsheet compatibility.
