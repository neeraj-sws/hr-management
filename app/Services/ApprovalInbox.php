<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeFinalSettlement;
use App\Models\EmployeePenalty;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollRun;
use App\Models\SalaryAdjustment;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Support\Collection;

/** Single place that decides which approvals a user can see and act on. */
class ApprovalInbox
{
    /** approvable type => [label, permissions that may decide it] */
    public const TYPES = [
        LeaveRequest::class => ['Leave', ['leave.manage', 'leave.approve']],
        OvertimeRequest::class => ['Overtime', ['overtime.manage']],
        SalaryAdjustment::class => ['Salary adjustment', ['salary.adjust']],
        SalaryAdvance::class => ['Salary advance', ['salary.advance']],
        EmployeePenalty::class => ['Penalty', ['penalties.manage']],
        PayrollRun::class => ['Payroll run', ['payroll.approve']],
        EmployeeFinalSettlement::class => ['Final settlement', ['payroll.approve']],
        AttendanceCorrection::class => ['Attendance correction', ['attendance.correct', 'approvals.manage']],
        ExpenseClaim::class => ['Expense claim', ['expenses.manage', 'approvals.manage']],
        \App\Models\MonitoringRequest::class => ['Screen capture', ['monitoring.capture', 'monitoring.manage']],
    ];

    public function __construct(
        private readonly HrWorkflowService $workflow,
        private readonly PeopleOperationsService $people,
        private readonly FinalSettlementService $settlements,
        private readonly AttendanceCorrectionService $corrections,
        private readonly ExpenseService $expenses,
    ) {}

    /**
     * Employee requests that the employee's own manager decides. A team lead
     * is the designated approver for these even without a Manager role, so the
     * request they are notified about never ends in "403 Forbidden".
     */
    public const TEAM_LEAD_TYPES = [LeaveRequest::class, OvertimeRequest::class, AttendanceCorrection::class, ExpenseClaim::class];

    /**
     * Who decides an employee's request: their manager — unless there is no
     * manager, the manager (or their login) is inactive, or the manager is on
     * approved leave today. Then null: the request goes to the administrators /
     * HR who hold the permission for it (they always see unassigned requests).
     */
    public static function approverFor(AttendanceEmployee $employee): ?int
    {
        $manager = $employee->manager()->with('user')->first();
        if (! $manager || ! $manager->is_active || ! $manager->user || ! $manager->user->is_active) return null;
        $onLeave = LeaveRequest::where('employee_id', $manager->id)->where('status', 'approved')
            ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->exists();

        return $onLeave ? null : $manager->user_id;
    }

    /** Types this user holds any deciding permission for (plus team requests for a team lead). */
    public function typesFor(User $user): array
    {
        $types = array_keys(array_filter(self::TYPES, fn ($definition) => $user->canAny($definition[1])));
        if (self::isTeamLead($user)) $types = array_values(array_unique([...$types, ...self::TEAM_LEAD_TYPES]));
        // Overtime switched off in Settings > Payroll: no overtime requests to decide (and none in the type filter).
        if (! app(PeopleOperationsService::class)->overtimeEnabled()) $types = array_values(array_diff($types, [OvertimeRequest::class]));

        return $types;
    }

    /** True when the user's employee has direct reports or is named approver on a pending request. */
    public static function isTeamLead(User $user): bool
    {
        return (bool) $user->employeeProfile?->directReports()->exists()
            || ApprovalRequest::where('approver_id', $user->id)->where('status', 'pending')->exists();
    }

    public function pending(User $user, ?string $type = null): Collection
    {
        $types = $this->typesFor($user);
        if ($type) $types = array_values(array_intersect($types, [$type]));
        if (! $types) return collect();

        return ApprovalRequest::with('approvable')
            ->where('status', 'pending')->whereIn('approvable_type', $types)
            ->latest()->limit(300)->get()
            ->filter(fn (ApprovalRequest $approval) => $approval->approvable && $this->canDecide($user, $approval))
            ->values();
    }

    public function pendingCount(User $user): int
    {
        return $this->pending($user)->count();
    }

    public function history(User $user, ?string $type = null, int $limit = 200): Collection
    {
        $types = $this->typesFor($user);
        if ($type) $types = array_values(array_intersect($types, [$type]));

        return ApprovalRequest::with(['approvable', 'approver'])
            ->whereIn('status', ['approved', 'rejected'])
            ->where(fn ($query) => $query->where('approver_id', $user->id)->orWhereIn('approvable_type', $types ?: ['-']))
            // The chosen type always applies — also to decisions the person made themself.
            ->when($type, fn ($query) => $query->where('approvable_type', $type))
            ->latest('decided_at')->limit($limit)->get()
            ->filter(fn (ApprovalRequest $approval) => $approval->approvable && ($approval->approver_id === $user->id || $this->canSeeDecision($user, $approval)))
            ->values();
    }

    public function canDecide(User $user, ApprovalRequest $approval): bool
    {
        $subject = $approval->approvable;
        if (! $subject || $approval->status !== 'pending') return false;
        $designated = in_array($approval->approvable_type, self::TEAM_LEAD_TYPES, true) && ($approval->approver_id === $user->id || $this->managesEmployee($user, $this->subjectEmployee($subject)));
        if (! $designated && ! $user->canAny(self::TYPES[$approval->approvable_type][1] ?? ['-'])) return false;
        if ($this->subjectEmployee($subject)?->user_id === $user->id) return false; // no self-approval

        return match (true) {
            $subject instanceof LeaveRequest => $subject->status === 'pending' && ($user->can('leave.manage') || $approval->approver_id === $user->id || $this->managesEmployee($user, $subject->employee)),
            $subject instanceof OvertimeRequest => $subject->status === 'pending' && ($user->canAny(['hr.manage', 'employees.manage']) || $approval->approver_id === $user->id || $this->managesEmployee($user, $subject->employee)),
            $subject instanceof AttendanceCorrection => $subject->status === 'pending' && ($user->canAny(['attendance.correct', 'approvals.manage']) || $approval->approver_id === $user->id || $this->managesEmployee($user, $subject->employee)),
            $subject instanceof ExpenseClaim => $subject->status === 'pending' && ($user->canAny(['expenses.manage', 'approvals.manage']) || $approval->approver_id === $user->id || $this->managesEmployee($user, $subject->employee)),
            $subject instanceof PayrollRun => $subject->status === 'draft',
            $subject instanceof EmployeeFinalSettlement => $subject->status === 'pending',
            default => $subject->status === 'pending',
        };
    }

    /** $amount: optional reduced amount when approving a penalty. */
    public function decide(User $user, ApprovalRequest $approval, bool $approve, ?string $note = null, ?float $amount = null): void
    {
        $approval->loadMissing('approvable');
        if ($approval->status !== 'pending') throw \Illuminate\Validation\ValidationException::withMessages(['approval' => 'This request has already been decided.'])->status(409);
        abort_unless($this->canDecide($user, $approval), 403);
        $subject = $approval->approvable;

        match (true) {
            $subject instanceof LeaveRequest => $this->workflow->decideLeave($subject, $user->id, $approve, $note),
            $subject instanceof OvertimeRequest => $this->people->decideOvertime($subject, $user->id, $approve, null, $note),
            $subject instanceof AttendanceCorrection => $this->corrections->decide($subject, $user->id, $approve, $note),
            $subject instanceof ExpenseClaim => $this->expenses->decide($subject, $user->id, $approve, $note),
            $subject instanceof PayrollRun => $approve ? $this->workflow->approvePayroll($subject, $user->id) : $this->workflow->rejectPayroll($subject, $user->id, $note),
            $subject instanceof EmployeeFinalSettlement => $approve ? $this->settlements->approve($subject, $user->id) : $this->settlements->reject($subject, $user->id, $note),
            $subject instanceof \App\Models\MonitoringRequest => app(TeamMonitoringService::class)->decideRequest($subject, $user, $approve, $note),
            default => $this->people->decideFinancialRequest($subject::class, $subject->id, $user->id, $approve, $note, $amount),
        };
    }

    public function typeLabel(string $type): string
    {
        return self::TYPES[$type][0] ?? class_basename($type);
    }

    /** One-line human summary of the request being approved. */
    public function summary(ApprovalRequest $approval): string
    {
        $subject = $approval->approvable;
        $who = $this->subjectEmployee($subject)?->name;

        return match (true) {
            $subject instanceof LeaveRequest => "{$who} · {$subject->leaveType?->name} · {$subject->starts_on->format('d M')}–{$subject->ends_on->format('d M Y')} ({$subject->days} days)".($subject->isBackdated() ? ' · OLD DATE: asked on '.$subject->created_at->format('d M').', give a reason to approve' : ''),
            $subject instanceof OvertimeRequest => "{$who} · {$subject->work_date->format('d M Y')} · {$subject->requested_minutes} min",
            $subject instanceof SalaryAdjustment => "{$who} · ".$subject->label().' '.number_format((float) $subject->amount, 2).($subject->is_recurring ? ' / month'.($subject->recurring_until ? ' until '.$subject->recurring_until->format('M Y') : '') : ''),
            $subject instanceof SalaryAdvance => "{$who} · ".number_format((float) $subject->amount, 2).' (recover '.number_format((float) $subject->installment_amount, 2).'/run)',
            $subject instanceof EmployeePenalty => "{$who} · ".number_format((float) $subject->amount, 2).' · '.$subject->reason,
            $subject instanceof AttendanceCorrection => "{$who} · {$subject->work_date->format('d M Y')} · ".collect(['in '.$subject->requested_check_in?->format('h:i A'), 'out '.$subject->requested_check_out?->format('h:i A')])->filter(fn ($part) => strlen($part) > 4)->join(', '),
            $subject instanceof ExpenseClaim => "{$who} · {$subject->category} · ".number_format((float) $subject->amount, 2).' · '.$subject->spent_on->format('d M Y'),
            $subject instanceof PayrollRun => "Period {$subject->period_starts_on->format('d M')}–{$subject->period_ends_on->format('d M Y')} · {$subject->items()->count()} employees",
            $subject instanceof EmployeeFinalSettlement => "{$who} · net ".number_format((float) $subject->net_amount, 2),
            $subject instanceof \App\Models\MonitoringRequest => "{$who} · turn on screen capture".($subject->until ? ' until '.$subject->until->format('d M Y') : '').' · asked by '.($subject->requester?->name ?? '—').' · '.$subject->reason,
            default => class_basename($subject).' #'.$subject?->id,
        };
    }

    public function subjectEmployee(mixed $subject): ?AttendanceEmployee
    {
        return match (true) {
            $subject instanceof EmployeeFinalSettlement => $subject->lifecycleCase?->employee,
            $subject instanceof PayrollRun, $subject === null => null,
            default => $subject->employee ?? null,
        };
    }

    private function managesEmployee(User $user, ?AttendanceEmployee $employee): bool
    {
        return $employee && $employee->manager?->user_id === $user->id;
    }

    private function canSeeDecision(User $user, ApprovalRequest $approval): bool
    {
        $subject = $approval->approvable;

        return match (true) {
            $subject instanceof LeaveRequest => $user->can('leave.manage') || $this->managesEmployee($user, $subject->employee),
            $subject instanceof OvertimeRequest => $user->canAny(['hr.manage', 'employees.manage']) || $this->managesEmployee($user, $subject->employee),
            $subject instanceof AttendanceCorrection => $user->canAny(['attendance.correct', 'approvals.manage']) || $this->managesEmployee($user, $subject->employee),
            $subject instanceof ExpenseClaim => $user->canAny(['expenses.manage', 'approvals.manage']) || $this->managesEmployee($user, $subject->employee),
            default => $user->canAny(self::TYPES[$approval->approvable_type][1] ?? ['-']),
        };
    }
}
