<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\EmployeePenalty;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PerformanceReview;
use App\Models\SalaryProfile;
use App\Models\Task;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One definition per report, shared by the on-screen report and the CSV
 * export so both always show the same rows for the same filters.
 *
 * Filters: from, to (Y-m-d), employee_id, department_id, team_id, search.
 */
class ReportService
{
    /** type => [label, view permissions (any), organisation-wide permissions (any; otherwise own team only)] */
    public const TYPES = [
        'attendance' => ['Attendance', ['reports.view', 'hr.manage', 'attendance.view'], ['hr.manage', 'employees.manage']],
        'leave' => ['Leave', ['leave.manage', 'leave.approve', 'hr.manage'], ['hr.manage', 'leave.manage']],
        'tasks' => ['Tasks', ['reports.view', 'hr.manage', 'tasks.manage'], ['hr.manage', 'employees.manage']],
        'projects' => ['Projects', ['reports.view', 'hr.manage', 'projects.manage'], ['hr.manage', 'employees.manage']],
        'overtime' => ['Overtime', ['overtime.manage', 'hr.manage', 'payroll.manage'], ['hr.manage', 'employees.manage', 'payroll.manage']],
        'payroll' => ['Payroll', ['payroll.manage', 'payroll.approve'], ['payroll.manage', 'payroll.approve']],
        'salary' => ['Salary', ['payroll.manage', 'payroll.approve'], ['payroll.manage', 'payroll.approve']],
        'performance' => ['Performance', ['reports.view', 'hr.manage'], ['hr.manage', 'employees.manage']],
        'productivity' => ['Productivity', ['reports.view', 'hr.manage'], ['hr.manage', 'employees.manage']],
        'compliance' => ['Compliance', ['penalties.manage', 'policies.manage', 'hr.manage'], ['hr.manage', 'penalties.manage', 'policies.manage']],
        'audit' => ['Audit', ['audit.view'], ['audit.view']],
        'employees' => ['Employees', ['employees.manage', 'hr.manage'], ['employees.manage', 'hr.manage']],
        'expenses' => ['Expenses', ['expenses.manage', 'hr.manage', 'approvals.manage'], ['expenses.manage', 'hr.manage']],
        'training' => ['Training', ['training.manage', 'training.assign', 'hr.manage'], ['training.manage', 'hr.manage']],
        'payments' => ['Payments', ['payroll.manage', 'payroll.approve'], ['payroll.manage', 'payroll.approve']],
        'teams' => ['Teams', ['reports.view', 'hr.manage', 'teams.manage'], ['hr.manage', 'employees.manage', 'teams.manage']],
        'screenshots' => ['Screen captures', ['monitoring.view'], ['monitoring.view']],
        'app_usage' => ['Application usage', ['monitoring.view'], ['monitoring.view']],
        'window_titles' => ['Window titles', ['monitoring.view'], ['monitoring.view']],
    ];

    public function __construct(private readonly AttendanceService $attendance, private readonly WorkService $work) {}

    public function label(string $type): string
    {
        return self::TYPES[$type][0] ?? ucfirst($type);
    }

    /** Reports a manager (an employee with people reporting to them) may open — for their own team only. */
    public const TEAM_LEAD_TYPES = ['attendance', 'leave', 'tasks', 'projects', 'overtime', 'expenses', 'performance', 'productivity'];

    public function canView(?User $user, string $type): bool
    {
        if ($user === null || ! isset(self::TYPES[$type])) return false;
        if ($type === 'overtime' && ! app(PeopleOperationsService::class)->overtimeEnabled()) return false;

        return $user->canAny(self::TYPES[$type][1]) || (in_array($type, self::TEAM_LEAD_TYPES, true) && ApprovalInbox::isTeamLead($user));
    }

    /** @return array<int, string> types the user may open */
    public function availableTypes(User $user): array
    {
        return array_values(array_filter(array_keys(self::TYPES), fn ($type) => $this->canView($user, $type)));
    }

    /**
     * Duration columns (unit in seconds) shown as HH:MM:SS, the same format
     * as the attendance and task screens. The column is renamed to *_time so
     * a spreadsheet never mixes decimal hours with clock times.
     */
    public const DURATION_COLUMNS = ['active_hours' => 3600, 'idle_hours' => 3600, 'break_hours' => 3600, 'screen_active_hours' => 3600, 'screen_idle_hours' => 3600, 'screen_meeting_hours' => 3600, 'screen_corrected_hours' => 3600, 'late_minutes' => 60, 'early_minutes' => 60, 'estimated_hours' => 3600, 'logged_hours' => 3600, 'not_counted_hours' => 3600, 'requested_minutes' => 60, 'approved_minutes' => 60, 'hours' => 3600, 'minutes' => 60];

    public static function durationColumn(string $column): string
    {
        if (! isset(self::DURATION_COLUMNS[$column])) return $column;
        if (in_array($column, ['hours', 'minutes'], true)) return 'time';

        return preg_replace('/_(hours|minutes)$/', '', $column).'_time';
    }

    public function columns(string $type): array
    {
        return array_map([self::class, 'durationColumn'], $this->rawColumns($type));
    }

    private function rawColumns(string $type): array
    {
        return match ($type) {
            'attendance' => ['employee_code', 'employee_name', 'department', 'work_date', 'day', 'day_status', 'note', 'check_in', 'check_out', 'active_hours', 'idle_hours', 'break_hours', 'screen_active_hours', 'screen_meeting_hours', 'screen_corrected_hours', 'screen_idle_hours', 'late_minutes', 'early_minutes', 'session_status', 'check_in_ip', 'check_out_ip'],
            'leave' => ['id', 'employee_code', 'employee_name', 'leave_type', 'paid', 'starts_on', 'ends_on', 'days', 'status', 'decided_at'],
            'projects' => ['id', 'project', 'client', 'manager', 'status', 'starts_on', 'ends_on', 'members', 'tasks', 'completed_tasks', 'open_tasks', 'overdue_tasks', 'progress_pct', 'estimated_hours', 'logged_hours'],
            'tasks' => ['id', 'title', 'project', 'employee_code', 'employee_name', 'priority', 'status', 'due_on', 'estimated_hours', 'logged_hours', 'completed_at', 'timeliness'],
            'overtime' => ['id', 'employee_code', 'employee_name', 'work_date', 'requested_minutes', 'approved_minutes', 'status', 'reason'],
            'payroll' => ['run_id', 'period_start', 'period_end', 'run_status', 'employee_code', 'employee_name', 'working_days', 'present_days', 'paid_leave_days', 'unpaid_leave_days', 'overtime_amount', 'gross', 'statutory_earnings', 'statutory_deductions', 'penalties', 'advance_recovery', 'adjustments', 'fixed_deductions', 'reimbursements', 'payable', 'paid', 'balance'],
            'salary' => ['employee_code', 'employee_name', 'month', 'salary_type', 'monthly_salary', 'salary_since', 'working_days', 'per_day_rate', 'payroll_payable', 'paid', 'remaining', 'payroll_status'],
            'performance' => ['employee_code', 'employee_name', 'completed_tasks', 'attendance_days', 'approved_leave_days', 'completed_late', 'overdue_tasks', 'logged_hours', 'not_counted_hours', 'active_hours', 'utilisation_pct', 'average_rating'],
            'productivity' => ['employee_code', 'employee_name', 'completed_tasks', 'completed_late', 'on_time_rate', 'open_tasks', 'overdue_tasks', 'estimated_hours', 'logged_hours', 'active_hours', 'utilization'],
            'compliance' => ['id', 'employee_code', 'employee_name', 'effective_on', 'amount', 'reason', 'rule', 'status', 'in_payroll'],
            'audit' => ['id', 'created_at', 'actor', 'action', 'record_type', 'record_id', 'ip_address', 'metadata'],
            'employees' => ['employee_code', 'name', 'email', 'designation', 'department', 'manager', 'joined_at', 'left_on', 'status'],
            'expenses' => ['id', 'employee_code', 'employee_name', 'spent_on', 'category', 'amount', 'description', 'status', 'receipt', 'paid_in_payroll'],
            'training' => ['employee_code', 'employee_name', 'course', 'mandatory', 'assigned_on', 'due_on', 'status', 'completed_at'],
            'payments' => ['paid_on', 'kind', 'employee_code', 'employee_name', 'for_period', 'amount', 'payment_status', 'remaining', 'method', 'reference', 'recorded_by'],
            'teams' => ['team', 'department', 'manager', 'members', 'attendance_days', 'late_days', 'leave_days', 'active_hours', 'tasks_completed', 'tasks_completed_late', 'tasks_overdue'],
            'screenshots' => ['employee_code', 'employee_name', 'date', 'captures', 'first_at', 'last_at', 'size_mb'],
            'app_usage' => ['employee_code', 'employee_name', 'date', 'application', 'hours'],
            'window_titles' => ['employee_code', 'employee_name', 'date', 'application', 'title', 'minutes'],
            default => [],
        };
    }

    /** @return Collection<int, array<string, mixed>> rows keyed by column */
    public function rows(User $user, string $type, array $filters, ?int $limit = null): Collection
    {
        abort_unless($this->canView($user, $type), 403);
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? today()->toDateString();
        $ids = $this->employeeIds($user, $type, $filters);

        $rows = match ($type) {
            'attendance' => $this->attendanceDays($ids, $from, $to, $filters['day_status'] ?? null, $limit),
            'leave' => LeaveRequest::with(['employee', 'leaveType'])->whereIn('employee_id', $ids)
                ->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->orderByDesc('starts_on')
                ->when($limit, fn ($q) => $q->limit($limit))->get()
                ->map(fn ($r) => ['id' => $r->id, 'employee_code' => $r->employee?->employee_code, 'employee_name' => $r->employee?->name, 'leave_type' => $r->leaveType?->name, 'paid' => $r->leaveType?->paid ? 'yes' : 'no', 'starts_on' => $r->starts_on?->toDateString(), 'ends_on' => $r->ends_on?->toDateString(), 'days' => (float) $r->days, 'status' => $r->status, 'decided_at' => $r->decided_at?->toDateTimeString()]),
            'tasks' => Task::with(['assignee', 'project', 'timeEntries'])->whereIn('assignee_id', $ids)
                ->where(fn ($q) => $q->whereBetween('due_on', [$from, $to])->orWhere(fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)))
                ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
                ->orderByDesc('id')->when($limit, fn ($q) => $q->limit($limit))->get()
                ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'project' => $t->project?->name, 'employee_code' => $t->assignee?->employee_code, 'employee_name' => $t->assignee?->name, 'priority' => $t->priority, 'status' => $t->status, 'due_on' => $t->due_on?->toDateString(), 'estimated_hours' => $t->estimated_hours !== null ? (float) $t->estimated_hours : null, 'logged_hours' => $this->work->loggedSeconds($t) / 3600, 'completed_at' => $t->completed_at?->toDateTimeString(), 'timeliness' => $t->wasCompletedLate() ? 'late' : ($t->isOverdue() ? 'overdue' : ($t->status === 'completed' ? 'on_time' : 'open'))]),
            'overtime' => OvertimeRequest::with('employee')->whereIn('employee_id', $ids)
                ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->orderByDesc('work_date')
                ->when($limit, fn ($q) => $q->limit($limit))->get()
                ->map(fn ($r) => ['id' => $r->id, 'employee_code' => $r->employee?->employee_code, 'employee_name' => $r->employee?->name, 'work_date' => $r->work_date?->toDateString(), 'requested_minutes' => $r->requested_minutes, 'approved_minutes' => $r->approved_minutes, 'status' => $r->status, 'reason' => $r->reason]),
            'payroll' => PayrollItem::with(['run', 'employee', 'payments'])->whereIn('employee_id', $ids)
                ->whereHas('run', fn ($q) => $q->whereDate('period_starts_on', '<=', $to)->whereDate('period_ends_on', '>=', $from))
                ->orderByDesc('payroll_run_id')->when($limit, fn ($q) => $q->limit($limit))->get()
                ->map(function (PayrollItem $r) {
                    $paid = (float) $r->payments->sum('amount') + (float) ($r->calculation_breakdown['old_sheet']['paid'] ?? 0); // a month from the old salary sheet: what it paid counts

                    return ['run_id' => $r->payroll_run_id, 'period_start' => $r->run?->period_starts_on?->toDateString(), 'period_end' => $r->run?->period_ends_on?->toDateString(), 'run_status' => $r->run?->status, 'employee_code' => $r->employee?->employee_code, 'employee_name' => $r->employee?->name, 'working_days' => (int) $r->working_days, 'present_days' => (float) $r->present_days, 'paid_leave_days' => (float) $r->paid_leave_days, 'unpaid_leave_days' => (float) $r->unpaid_leave_days, 'overtime_amount' => (float) $r->overtime_amount, 'gross' => (float) $r->gross_amount, 'statutory_earnings' => (float) $r->statutory_earnings, 'statutory_deductions' => (float) $r->statutory_deductions, 'penalties' => (float) $r->penalties_amount, 'advance_recovery' => (float) $r->recovery_amount, 'adjustments' => (float) $r->adjustments_amount, 'fixed_deductions' => (float) ($r->calculation_breakdown['fixed_deductions_total'] ?? 0), 'reimbursements' => (float) $r->reimbursements_amount, 'payable' => (float) $r->net_amount, 'paid' => round($paid, 2), 'balance' => round((float) $r->net_amount - $paid, 2)];
                }),
            'salary' => $this->salaryByMonth($ids, $from, $to, $limit),
            'performance' => $this->performanceRows($ids, $from, $to),
            'productivity' => $this->work->productivity(AttendanceEmployee::whereIn('id', $ids)->orderBy('name')->get(), $from, $to)
                ->map(fn ($m) => ['employee_code' => $m->employee->employee_code, 'employee_name' => $m->employee->name, 'completed_tasks' => $m->completed_tasks, 'completed_late' => $m->completed_late, 'on_time_rate' => $m->on_time_rate, 'open_tasks' => $m->open_tasks, 'overdue_tasks' => $m->overdue_tasks, 'estimated_hours' => $m->estimated_hours, 'logged_hours' => $m->logged_hours, 'active_hours' => $m->active_hours, 'utilization' => $m->utilization]),
            'compliance' => EmployeePenalty::with(['employee', 'policyRule'])->whereIn('employee_id', $ids)
                ->whereDate('effective_on', '>=', $from)->whereDate('effective_on', '<=', $to)->orderByDesc('effective_on')
                ->when($limit, fn ($q) => $q->limit($limit))->get()
                ->map(fn ($r) => ['id' => $r->id, 'employee_code' => $r->employee?->employee_code, 'employee_name' => $r->employee?->name, 'effective_on' => $r->effective_on?->toDateString(), 'amount' => (float) $r->amount, 'reason' => $r->reason, 'rule' => $r->policyRule?->rule_key ?? 'manual', 'status' => $r->status, 'in_payroll' => $r->payroll_item_id ? 'yes' : 'no']),
            'audit' => AuditLog::with('actor')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('action', 'like', "%{$term}%"))
                ->orderByDesc('id')->when($limit, fn ($q) => $q->limit($limit))->get()
                ->map(fn ($r) => ['id' => $r->id, 'created_at' => $r->created_at?->toDateTimeString(), 'actor' => $r->actor?->email ?? 'system', 'action' => $r->action, 'record_type' => $r->subject_type ? class_basename($r->subject_type) : null, 'record_id' => $r->subject_id, 'ip_address' => $r->ip_address, 'metadata' => json_encode($r->metadata)]),
            // Employee-number order (EMP-01, EMP-02 … EMP-10), as in Employees; people who left say "Ex-employee".
            'employees' => AttendanceEmployee::with(['department', 'manager'])->whereIn('id', $ids)->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')
                ->when($limit, fn ($q) => $q->limit($limit))->get()
                ->map(fn ($e) => ['employee_code' => $e->employee_code, 'name' => $e->name, 'email' => $e->email, 'designation' => $e->designation, 'department' => $e->department?->name, 'manager' => $e->manager?->name, 'joined_at' => $e->joined_at?->toDateString(),
                    'left_on' => $e->exit_date?->toDateString(), 'status' => $e->is_active ? 'active' : ($e->exit_date || $e->employment_type === EmployeeService::EX_EMPLOYEE ? 'ex-employee' : 'inactive')]),
            'expenses' => ExpenseClaim::with('employee')->whereIn('employee_id', $ids)
                ->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)->orderByDesc('spent_on')
                ->when($limit, fn ($q) => $q->limit($limit))->get()
                ->map(fn ($r) => ['id' => $r->id, 'employee_code' => $r->employee?->employee_code, 'employee_name' => $r->employee?->name, 'spent_on' => $r->spent_on?->toDateString(), 'category' => $r->category, 'amount' => (float) $r->amount, 'description' => $r->description, 'status' => $r->displayStatus(), 'receipt' => $r->receipt_path ? 'yes' : 'no', 'paid_in_payroll' => $r->payroll_item_id ? 'yes' : 'no']),
            'training' => TrainingAssignment::with(['employee', 'course'])->whereIn('employee_id', $ids)
                ->where(fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->orWhere('status', '!=', 'completed'))
                ->orderBy('employee_id')->orderBy('due_on')->when($limit, fn ($q) => $q->limit($limit))->get()
                ->map(fn ($r) => ['employee_code' => $r->employee?->employee_code, 'employee_name' => $r->employee?->name, 'course' => $r->course?->title, 'mandatory' => $r->course?->is_mandatory ? 'yes' : 'no', 'assigned_on' => $r->created_at?->toDateString(), 'due_on' => $r->due_on?->toDateString(), 'status' => $r->displayStatus(), 'completed_at' => $r->completed_at?->toDateString()]),
            'payments' => $this->paymentRows($ids, $from, $to),
            'projects' => $this->projectRows($user, $ids, $from, $to, $filters, $limit),
            'teams' => $this->teamRows($ids, $from, $to, $filters),
            'screenshots' => \App\Models\Screenshot::with('employee')->whereIn('employee_id', $ids)
                ->whereBetween('captured_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('captured_at')->get()
                ->groupBy(fn ($c) => $c->employee_id.'|'.$c->captured_at->toDateString())
                ->map(fn ($day) => ['employee_code' => $day->first()->employee?->employee_code, 'employee_name' => $day->first()->employee?->name, 'date' => $day->first()->captured_at->toDateString(), 'captures' => $day->count(), 'first_at' => $day->first()->captured_at->format('H:i'), 'last_at' => $day->last()->captured_at->format('H:i'), 'size_mb' => round($day->sum('bytes') / 1048576, 2)])
                ->sortByDesc('date')->values(),
            'app_usage' => \App\Models\AppUsage::with('employee')->whereIn('employee_id', $ids)
                ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->orderByDesc('work_date')->orderByDesc('seconds')->get()
                ->map(fn ($u) => ['employee_code' => $u->employee?->employee_code, 'employee_name' => $u->employee?->name, 'date' => $u->work_date->toDateString(), 'application' => $u->app_name, 'hours' => $u->seconds / 3600])
                ->values(),
            'window_titles' => \App\Models\ActivityTitle::with('employee')->whereIn('employee_id', $ids)
                ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->orderByDesc('work_date')->orderByDesc('seconds')->get()
                ->map(fn ($u) => ['employee_code' => $u->employee?->employee_code, 'employee_name' => $u->employee?->name, 'date' => $u->work_date->toDateString(), 'application' => $u->app_name, 'title' => $u->title, 'minutes' => $u->seconds / 60])
                ->values(),
            default => collect(),
        };

        // A manager sees a report's rows only from the day they started managing each person.
        $since = $this->managerSince($user, $type);
        $dateColumn = ['attendance' => 'work_date', 'leave' => 'ends_on', 'overtime' => 'work_date', 'expenses' => 'spent_on', 'tasks' => 'due_on'][$type] ?? null;
        if ($since && $dateColumn) {
            $codes = AttendanceEmployee::whereIn('id', array_keys($since))->pluck('employee_code', 'id');
            $sinceByCode = collect($since)->mapWithKeys(fn ($date, $id) => [$codes[$id] ?? '' => $date]);
            $rows = $rows->filter(fn ($row) => ! isset($sinceByCode[$row['employee_code'] ?? '']) || ($row[$dateColumn] ?? '9999') >= $sinceByCode[$row['employee_code']]);
        }

        $rows = $rows->map(fn (array $row) => $this->formatDurations($row));
        // Every per-employee report reads in employee-number order (EMP-01, EMP-02 … EMP-10), keeping each
        // employee's own rows in their date order. Salary lists months newest first, so it keeps its own order.
        if ($type !== 'salary' && $rows->isNotEmpty() && array_key_exists('employee_code', $rows->first())) {
            $rows = $rows->values()->map(fn ($row, $i) => [$row, $i])
                ->sortBy(fn ($pair) => sprintf('%04d|%s|%08d', strlen((string) $pair[0]['employee_code']), $pair[0]['employee_code'], $pair[1]))
                ->map(fn ($pair) => $pair[0]);
        }

        return $limit ? $rows->take($limit)->values() : $rows->values();
    }

    /** Converts duration columns to HH:MM:SS under their *_time name. */
    private function formatDurations(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (isset(self::DURATION_COLUMNS[$key])) {
                $out[self::durationColumn($key)] = $value === null ? null : \App\Support\Duration::hms((float) $value * self::DURATION_COLUMNS[$key]);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @return array<int, string> direct report id => date the current manager took over (non-org-wide users only) */
    private function managerSince(User $user, string $type): array
    {
        if ($user->canAny(self::TYPES[$type][2] ?? ['-'])) return [];
        $own = AttendanceEmployee::where('user_id', $user->id)->first();
        if (! $own) return [];

        return \App\Models\EmployeeManagerHistory::where('manager_id', $own->id)->whereNull('ends_on')->pluck('starts_on', 'employee_id')
            ->map(fn ($date) => \Illuminate\Support\Carbon::parse($date)->toDateString())->all();
    }

    /** Employees in scope for this user + report, after employee/department/team/search filters. */
    public function employeeIds(User $user, string $type, array $filters): Collection
    {
        $orgWide = $user->canAny(self::TYPES[$type][2] ?? ['-']);
        $own = AttendanceEmployee::where('user_id', $user->id)->first();

        return AttendanceEmployee::query()
            ->when(! in_array($type, ['employees', 'payroll', 'payments', 'screenshots', 'app_usage', 'window_titles', 'audit', 'compliance', 'leave', 'overtime', 'tasks', 'salary'], true), fn ($q) => $q->where('is_active', true))
            ->when(! $orgWide, fn ($q) => $q->where(fn ($q) => $q->whereKey($own?->id ?? 0)->orWhere('manager_id', $own?->id ?? 0)))
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['team_id'] ?? null, fn ($q, $id) => $q->whereHas('teams', fn ($t) => $t->whereKey($id)))
            ->when(($filters['search'] ?? null) && ! in_array($type, ['tasks', 'audit', 'projects'], true), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')->orWhere('employee_code', 'like', '%'.$filters['search'].'%')))
            ->pluck('id');
    }

    /** Salary payments and final-settlement payments made in the period. */
    private function paymentRows(Collection $ids, string $from, string $to): Collection
    {
        $salary = \App\Models\SalaryPayment::with(['payrollItem.employee', 'payrollItem.run'])
            ->whereHas('payrollItem', fn ($q) => $q->whereIn('employee_id', $ids))
            ->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)->get()
            ->map(fn ($p) => ['paid_on' => $p->paid_on?->toDateString(), 'kind' => 'salary', 'employee_code' => $p->payrollItem?->employee?->employee_code, 'employee_name' => $p->payrollItem?->employee?->name, 'for_period' => $p->payrollItem?->run?->period_starts_on?->format('M Y'), 'amount' => (float) $p->amount, 'method' => $p->method, 'reference' => $p->reference, 'recorded_by' => $p->recorded_by]);
        $settlement = \App\Models\EmployeeFinalSettlementPayment::with('settlement.lifecycleCase.employee')
            ->whereHas('settlement.lifecycleCase', fn ($q) => $q->whereIn('employee_id', $ids))
            ->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)->get()
            ->map(fn ($p) => ['paid_on' => $p->paid_on ? \Illuminate\Support\Carbon::parse($p->paid_on)->toDateString() : null, 'kind' => 'final_settlement', 'employee_code' => $p->settlement?->lifecycleCase?->employee?->employee_code, 'employee_name' => $p->settlement?->lifecycleCase?->employee?->name, 'for_period' => 'full & final', 'amount' => (float) $p->amount, 'method' => $p->method, 'reference' => $p->reference, 'recorded_by' => $p->recorded_by]);
        // Months paid from the old salary sheet (Payroll > Legacy Salary): paid / partly paid / unpaid and what is still due.
        // A month with no payment date yet (still unpaid) is listed by its salary month instead.
        $legacy = \App\Models\LegacySalaryRecord::with('employee')->whereIn('employee_id', $ids)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereDate('payment_date', '>=', $from)->whereDate('payment_date', '<=', $to))
                ->orWhere(fn ($q) => $q->whereNull('payment_date')->where('payroll_month', '>=', substr($from, 0, 7))->where('payroll_month', '<=', substr($to, 0, 7))))->get()
            ->map(fn ($r) => ['sort_date' => $r->payment_date?->toDateString() ?? \Illuminate\Support\Carbon::parse($r->payroll_month.'-01')->endOfMonth()->toDateString(),
                'paid_on' => $r->payment_date?->toDateString(), 'kind' => 'legacy_salary', 'employee_code' => $r->employee?->employee_code ?? $r->employee_code, 'employee_name' => $r->employee?->name ?? $r->name,
                'for_period' => \Illuminate\Support\Carbon::parse($r->payroll_month.'-01')->format('M Y').' (old salary sheet)', 'amount' => round((float) $r->salary_paid, 2), 'method' => 'Old salary sheet',
                'reference' => $r->notes ? \Illuminate\Support\Str::limit(str_replace("\n", ' · ', $r->notes), 120) : null, 'recorded_by' => $r->imported_by,
                'payment_status' => $r->payment_status ?: ((float) $r->salary_paid > 0 ? 'paid' : 'unpaid'),
                // What the sheet says is still due. Only a month with nothing paid and no "remains" value at all
                // counts its whole salary as due (a month paid without a date is not unpaid).
                'remaining' => round(max(0, (float) ($r->salary_remains !== null ? $r->salary_remains
                    : ((float) $r->salary_paid > 0 ? 0 : ($r->total_salary_with_bonus ?? $r->salary_after_deduction ?? 0)))), 2)]);
        $rows = $salary->concat($settlement)->concat($legacy);
        $names = User::whereIn('id', $rows->pluck('recorded_by')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn ($row) => array_merge($row, ['recorded_by' => $names[$row['recorded_by']] ?? null]))
            ->sortByDesc(fn ($row) => $row['sort_date'] ?? $row['paid_on'])->map(fn ($row) => \Illuminate\Support\Arr::except($row, ['sort_date']))->values();
    }

    /**
     * One row per project running in the period (started by its end, not finished before its start).
     * HR/admin see every project; others see the projects their people manage, belong to or have tasks in.
     */
    private function projectRows(User $user, Collection $ids, string $from, string $to, array $filters, ?int $limit): Collection
    {
        $everything = $user->canAny(self::TYPES['projects'][2]) && empty($filters['employee_id']) && empty($filters['department_id']) && empty($filters['team_id']);

        return ($everything ? \App\Models\Project::query() : $this->work->projectsInvolving($ids))
            ->with(['manager:id,name', 'tasks.timeEntries'])->withCount('members')
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $to))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from)->orWhere('status', '!=', 'completed'))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->orderBy('id') // ID order (1, 2, 3 …)
            ->when($limit, fn ($q) => $q->limit($limit))->get()
            ->map(function (\App\Models\Project $p) {
                $tasks = $p->tasks;
                $done = $tasks->where('status', 'completed')->count();

                return ['id' => $p->id, 'project' => $p->name, 'client' => $p->client_name, 'manager' => $p->manager?->name, 'status' => $p->status, 'starts_on' => $p->starts_on?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(),
                    'members' => (int) $p->members_count, 'tasks' => $tasks->count(), 'completed_tasks' => $done, 'open_tasks' => $tasks->count() - $done,
                    'overdue_tasks' => $tasks->filter(fn (Task $t) => $t->isOverdue())->count(), 'progress_pct' => $tasks->count() ? (int) round($done / $tasks->count() * 100) : 0,
                    'estimated_hours' => (float) $tasks->sum('estimated_hours'), 'logged_hours' => $tasks->sum(fn (Task $t) => $this->work->loggedSeconds($t)) / 3600];
            });
    }

    /** One row per team: members on the last day of the period (from the dated history) and what they did in it. */
    private function teamRows(Collection $ids, string $from, string $to, array $filters): Collection
    {
        $teams = \App\Models\Team::with(['department', 'manager'])->where('is_active', true)->when($filters['team_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('name')->get();

        return $teams->map(function ($team) use ($ids, $from, $to) {
            $history = \App\Models\EmployeeTeamHistory::where('team_id', $team->id)->on($to)->pluck('employee_id');
            $members = ($history->isNotEmpty() ? $history : $team->members()->pluck('attendance_employees.id'))->intersect($ids)->values();
            $sessions = AttendanceSession::whereIn('employee_id', $members)->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->get();
            $tasks = Task::whereIn('assignee_id', $members)->get();
            $completed = $tasks->filter(fn ($t) => $t->completed_at && $t->completed_at->toDateString() >= $from && $t->completed_at->toDateString() <= $to);

            return [
                'team' => $team->name, 'department' => $team->department?->name, 'manager' => $team->manager?->name, 'members' => $members->count(),
                'attendance_days' => $sessions->count(),
                'late_days' => $sessions->filter(fn ($s) => $this->attendance->isLate($s))->count(),
                'leave_days' => (float) LeaveRequest::whereIn('employee_id', $members)->where('status', 'approved')->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->sum('days'),
                'active_hours' => $this->hours($sessions->sum('active_seconds')),
                'tasks_completed' => $completed->count(),
                'tasks_completed_late' => $completed->filter(fn ($t) => $t->wasCompletedLate())->count(),
                'tasks_overdue' => $tasks->filter(fn ($t) => $t->isOverdue())->count(),
            ];
        })->filter(fn ($row) => $row['members'] > 0)->values();
    }

    private function performanceRows(Collection $ids, string $from, string $to): Collection
    {
        $metrics = $this->work->productivity(AttendanceEmployee::whereIn('id', $ids)->orderBy('name')->get(), $from, $to);
        $ratings = PerformanceReview::whereIn('employee_id', $ids)->whereDate('period_ends_on', '>=', $from)->whereDate('period_starts_on', '<=', $to)
            ->selectRaw('employee_id, AVG(rating) as rating')->groupBy('employee_id')->pluck('rating', 'employee_id');

        return $metrics->map(fn ($m) => [
            'employee_code' => $m->employee->employee_code,
            'employee_name' => $m->employee->name,
            'completed_tasks' => $m->completed_tasks,
            'attendance_days' => $m->attendance_days,
            'approved_leave_days' => (float) LeaveRequest::where('employee_id', $m->employee->id)->where('status', 'approved')->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->sum('days'),
            'completed_late' => $m->completed_late,
            'overdue_tasks' => $m->overdue_tasks,
            // Task timer time (Productivity's "Logged H"), what was not counted (timers left running), and attendance time.
            'logged_hours' => $m->logged_hours,
            'not_counted_hours' => round($m->trimmed_seconds / 3600, 4),
            'active_hours' => $m->active_hours,
            'utilisation_pct' => $m->utilization,
            'average_rating' => isset($ratings[$m->employee->id]) ? round((float) $ratings[$m->employee->id], 2) : null,
        ]);
    }

    /** Unrounded, so the HH:MM:SS shown in reports keeps every second. */
    private function hours(mixed $seconds): float
    {
        return ((int) $seconds) / 3600;
    }

    /** Kinds of day in the attendance report (and its Day filter). */
    public const DAY_STATUSES = ['present' => 'Present', 'late' => 'Late', 'half_day' => 'Half-day', 'on_leave' => 'On leave', 'extra_work' => 'Extra work (off day)', 'holiday' => 'Holiday', 'weekend' => 'Weekly off', 'absent' => 'Absent', 'leave_conflict' => 'Worked on a leave day'];

    /**
     * Every day of the range for each employee (employee-code order, then date): worked days with their times,
     * and leave, holidays, weekly offs and absences too. Empty days before joining, days after leaving and future days are left out.
     */
    private function attendanceDays($ids, string $from, string $to, ?string $only, ?int $limit): Collection
    {
        $rows = collect();
        $end = min($to, today()->toDateString());
        $employees = AttendanceEmployee::with('department')->whereIn('id', $ids)->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')->get();
        foreach ($employees as $e) {
            // Not cut at the joining date: imported attendance from before it still shows; empty days before
            // joining come back as "not_joined" and are skipped below.
            $start = $from;
            $last = $e->exit_date ? min($end, $e->exit_date->toDateString()) : $end;
            if ($start > $last) continue;
            foreach ($this->attendance->days($e, \Illuminate\Support\Carbon::parse($start), \Illuminate\Support\Carbon::parse($last)) as $day) {
                if (in_array($day['status'], ['upcoming', 'not_joined'], true)) continue;
                // Before joining only days someone actually worked (imported history) are listed.
                if (! $day['session'] && $e->joined_at && $day['date'] < $e->joined_at->toDateString()) continue;
                if ($only && $day['status'] !== $only) continue;
                $s = $day['session'];
                $rows->push(['employee_code' => $e->employee_code, 'employee_name' => $e->name, 'department' => $e->department?->name, 'work_date' => $day['date'],
                    'day' => \Illuminate\Support\Carbon::parse($day['date'])->format('D'), 'day_status' => $day['status'],
                    'note' => $day['extra'] ? 'Extra working · '.$day['extra_reason'] : ($day['label'] ?: null),
                    'check_in' => $s?->check_in?->format('h:i:s A'), 'check_out' => $s?->check_out?->format('h:i:s A'),
                    'active_hours' => $s ? $this->hours($this->attendance->workedSeconds($s)) : null, 'idle_hours' => $s ? $this->hours($s->idleSeconds()) : null, 'break_hours' => $s ? $this->hours($s->break_seconds) : null,
                    'screen_active_hours' => $s ? $this->hours($s->screen_active_seconds) : null, 'screen_meeting_hours' => $s ? $this->hours($s->screen_meeting_seconds) : null, 'screen_corrected_hours' => $s ? $this->hours($s->correctedScreenSeconds()) : null, 'screen_idle_hours' => $s ? $this->hours($s->screen_idle_seconds) : null,
                    'late_minutes' => $s ? $this->attendance->lateMinutes($s) : null, 'early_minutes' => $s ? $this->attendance->earlyMinutes($s) : null,
                    'session_status' => $s?->status, 'check_in_ip' => $s?->check_in_ip, 'check_out_ip' => $s?->check_out_ip]);
                if ($limit && $rows->count() >= $limit) return $rows;
            }
        }

        return $rows;
    }

    /**
     * Salary month by month: for each employee and each month in the range (from joining to leaving), the salary in
     * effect that month, that month's working days and per-day rate, and — when that month's payroll was run — what
     * was payable and paid. Newest month first.
     */
    private function salaryByMonth($ids, string $from, string $to, ?int $limit): \Illuminate\Support\Collection
    {
        $employees = AttendanceEmployee::with(['salaryProfiles' => fn ($q) => $q->orderBy('effective_from')->orderBy('id')])->whereIn('id', $ids)
            ->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')->get();
        $items = \App\Models\PayrollItem::with(['run', 'payments'])->whereIn('employee_id', $ids)
            ->whereHas('run', fn ($q) => $q->whereDate('period_starts_on', '<=', $to)->whereDate('period_ends_on', '>=', $from))->get()
            ->groupBy(fn ($i) => $i->employee_id.'|'.$i->run->period_starts_on->format('Y-m'));
        // Months paid from the old salary sheet: its own basic salary, working days and amounts are what was really paid.
        $legacy = \App\Models\LegacySalaryRecord::whereIn('employee_id', $ids)
            ->where('payroll_month', '>=', substr($from, 0, 7))->where('payroll_month', '<=', substr($to, 0, 7))->get()
            ->keyBy(fn ($r) => $r->employee_id.'|'.$r->payroll_month);
        $rows = collect();
        $first = \Illuminate\Support\Carbon::parse($from)->startOfMonth();
        for ($month = \Illuminate\Support\Carbon::parse($to)->startOfMonth(); $month->gte($first); $month->subMonth()) {
            foreach ($employees as $e) {
                if ($e->joined_at && $e->joined_at->gt($month->copy()->endOfMonth())) continue;
                if ($e->exit_date && $e->exit_date->lt($month)) continue;
                $monthEnd = $month->copy()->endOfMonth()->toDateString();
                if ($sheet = $legacy->get($e->id.'|'.$month->format('Y-m'))) {
                    $rows->push(['employee_code' => $e->employee_code, 'employee_name' => $e->name, 'month' => $month->format('M Y'), 'salary_type' => 'monthly',
                        'monthly_salary' => round((float) $sheet->basic_salary, 2), 'salary_since' => 'old salary sheet', 'working_days' => (int) round((float) $sheet->total_working_days),
                        'per_day_rate' => round((float) $sheet->salary_per_day, 2), 'payroll_payable' => round((float) ($sheet->total_salary_with_bonus ?? $sheet->salary_after_deduction), 2),
                        'paid' => round((float) $sheet->salary_paid, 2), 'remaining' => round(max(0, (float) $sheet->salary_remains), 2), 'payroll_status' => 'old sheet']);
                    if ($limit && $rows->count() >= $limit) return $rows;
                    continue;
                }
                $profile = $e->salaryProfiles->filter(fn ($p) => $p->effective_from->toDateString() <= $monthEnd)->last();
                if (! $profile) continue;
                $days = app(HrWorkflowService::class)->salaryDaysFor($e, $month->toDateString(), $profile)['days'];
                $item = $items->get($e->id.'|'.$month->format('Y-m'))?->first();
                $rows->push(['employee_code' => $e->employee_code, 'employee_name' => $e->name, 'month' => $month->format('M Y'),
                    'salary_type' => $profile->isDaily() ? 'per day' : 'monthly',
                    'monthly_salary' => $profile->isDaily() ? number_format((float) $profile->daily_rate, 2).' / day' : (float) $profile->monthly_salary,
                    'salary_since' => $profile->effective_from->toDateString(), 'working_days' => $days,
                    'per_day_rate' => $profile->isDaily() ? (float) $profile->daily_rate : round((float) $profile->monthly_salary / max(1, $days), 2),
                    'payroll_payable' => $item ? (float) $item->net_amount : null, 'paid' => $item ? round((float) $item->payments->sum('amount'), 2) : null,
                    // Still to pay for the month: payable less what was already paid.
                    'remaining' => $item ? round(max(0, (float) $item->net_amount - (float) $item->payments->sum('amount')), 2) : null,
                    'payroll_status' => $item ? $item->run->status : 'not run']);
                if ($limit && $rows->count() >= $limit) return $rows;
            }
        }

        return $rows;
    }

}
