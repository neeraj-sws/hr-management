<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeePenalty;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Models\SalaryPayment;
use App\Models\Task;
use App\Support\Duration;
use Illuminate\Support\Carbon;

/**
 * The Telegram messages for HR events: the Super Admin's feed and, for check-in / check-out, the employee's own chat
 * (everything else reaches the employee through their normal notifications). Each message has a fixed key, so the
 * same event is never sent twice. Nothing here changes attendance, leave or payroll.
 */
class TelegramEvents
{
    public function __construct(private readonly TelegramNotificationService $telegram) {}

    private static function money(float $amount): string
    {
        return 'Rs '.number_format($amount, 2);
    }

    private static function who(?AttendanceEmployee $e): array
    {
        return ['Employee' => $e?->name, 'Employee ID' => $e?->employee_code];
    }

    public function checkedIn(AttendanceSession $session): void
    {
        $e = $session->employee;
        if (! $e || ! $session->check_in) return;
        $late = rescue(fn () => app(AttendanceService::class)->isLate($session) ? app(AttendanceService::class)->lateMinutes($session) : 0, 0, false);
        $status = $late > 0 ? "Late by {$late} min" : 'On time';
        $this->telegram->toAdmin('check_in', "in:{$session->id}", TelegramNotificationService::format('Attendance started', [...self::who($e), 'Date' => $session->check_in->format('d F Y'), 'Time' => $session->check_in->format('h:i A'), 'Status' => $status]));
        $this->telegram->toUser($e->user, 'check_in', "in:{$session->id}", TelegramNotificationService::format('Attendance started', ['Hello '.$e->name.', your attendance is recorded.', 'Date' => $session->check_in->format('d F Y'), 'Time' => $session->check_in->format('h:i A'), 'Status' => $status]));
    }

    public function checkedOut(AttendanceSession $session): void
    {
        $e = $session->employee;
        if (! $e || ! $session->check_out) return;
        $worked = rescue(fn () => Duration::hm(app(AttendanceService::class)->workedSeconds($session)), null, false);
        $lines = ['Date' => $session->work_date->format('d F Y'), 'Check-in' => $session->check_in?->format('h:i A'), 'Checkout Time' => $session->check_out->format('h:i A'), 'Worked' => $worked];
        $this->telegram->toAdmin('check_out', "out:{$session->id}", TelegramNotificationService::format('Checkout completed', [...self::who($e), ...$lines]));
        $this->telegram->toUser($e->user, 'check_out', "out:{$session->id}", TelegramNotificationService::format('Checkout completed', ['Hello '.$e->name.', your checkout is recorded.', ...$lines]));
    }

    public function breakStarted(AttendanceEmployee $e, AttendanceSession $session, \Carbon\CarbonInterface $at): void
    {
        $this->telegram->toAdmin('break_start', "brk-in:{$session->id}:{$at->timestamp}", TelegramNotificationService::format('Break started', [...self::who($e), 'Date' => $at->format('d F Y'), 'Time' => $at->format('h:i A')]));
    }

    public function breakEnded(AttendanceEmployee $e, AttendanceSession $session, \Carbon\CarbonInterface $at, int $seconds): void
    {
        $this->telegram->toAdmin('break_end', "brk-out:{$session->id}:{$at->timestamp}", TelegramNotificationService::format('Break ended', [...self::who($e), 'Date' => $at->format('d F Y'), 'Time' => $at->format('h:i A'), 'Break' => Duration::words(intdiv($seconds, 60))]));
    }

    public function absent(AttendanceEmployee $e, string $date): void
    {
        $this->telegram->toAdmin('absent', "absent:{$e->id}:{$date}", TelegramNotificationService::format('Not checked in', [...self::who($e), 'Date' => Carbon::parse($date)->format('d F Y'), 'Not checked in and not on approved leave']));
    }

    public function leave(LeaveRequest $leave, string $what): void
    {
        $leave->loadMissing(['employee', 'leaveType']);
        $title = ['applied' => 'New leave request', 'approved' => 'Leave approved', 'rejected' => 'Leave rejected', 'cancelled' => 'Leave cancelled'][$what] ?? 'Leave updated';
        $event = ['applied' => 'leave_applied', 'cancelled' => 'leave_cancelled'][$what] ?? 'leave_decided';
        $this->telegram->toAdmin($event, "leave:{$leave->id}:{$what}".($what === 'applied' ? '' : ':'.$leave->decided_at?->timestamp), TelegramNotificationService::format($title, [...self::who($leave->employee), 'Leave Type' => $leave->typeName() ?? 'Leave', 'From' => $leave->starts_on?->format('d F Y'), 'To' => $leave->ends_on?->format('d F Y'),
            'Days' => rtrim(rtrim(number_format((float) $leave->days, 2), '0'), '.'), 'Reason' => $what === 'applied' ? $leave->reason : null, 'Status' => $what === 'applied' ? 'Pending Approval' : ucfirst($what), 'Note' => $what !== 'applied' ? $leave->decision_note : null]));
    }

    /** A leave asked for a personal reason: rejected by the system at once. */
    public function leaveAutoRejected(LeaveRequest $leave): void
    {
        $leave->loadMissing(['employee', 'leaveType']);
        $this->telegram->toAdmin('auto_rejected', "leave:{$leave->id}:auto-rejected", TelegramNotificationService::format('Leave rejected automatically', [...self::who($leave->employee), 'Leave Type' => $leave->typeName() ?? 'Leave', 'From' => $leave->starts_on?->format('d F Y'), 'To' => $leave->ends_on?->format('d F Y'),
            'Reason given' => $leave->reason, 'Why rejected' => 'Personal reason - not accepted for leave']));
    }

    /** A late fine's reason was a personal reason: rejected by the system, the fine stays. */
    public function lateReasonAutoRejected(\App\Models\PenaltyAppeal $appeal): void
    {
        $penalty = $appeal->penalty;
        $penalty?->loadMissing('employee');
        $this->telegram->toAdmin('auto_rejected', "late-reason:{$appeal->id}:auto-rejected", TelegramNotificationService::format('Late reason rejected automatically', [...self::who($penalty?->employee), 'Late on' => $penalty?->effective_on?->format('d F Y'), 'Fine' => $penalty ? self::money((float) $penalty->amount).' (stays)' : null,
            'Reason given' => $appeal->reason, 'Why rejected' => 'Personal reason - not accepted for coming late']));
    }

    public function overtimeRequested(\App\Models\OvertimeRequest $request): void
    {
        $request->loadMissing('employee');
        $this->telegram->toAdmin('overtime_requested', "overtime:{$request->id}:requested", TelegramNotificationService::format('Overtime requested', [...self::who($request->employee), 'Date' => $request->work_date?->format('d F Y'), 'Overtime' => Duration::words((int) $request->requested_minutes), 'Reason' => $request->reason, 'Status' => 'Pending approval']));
    }

    public function expenseSubmitted(\App\Models\ExpenseClaim $claim): void
    {
        $claim->loadMissing('employee');
        $this->telegram->toAdmin('expense_submitted', "expense:{$claim->id}:submitted", TelegramNotificationService::format('Expense claim submitted', [...self::who($claim->employee), 'Spent on' => $claim->spent_on?->format('d F Y'), 'Category' => $claim->category ? ucfirst(str_replace('_', ' ', (string) $claim->category)) : null, 'Amount' => self::money((float) $claim->amount),
            'Details' => \Illuminate\Support\Str::limit(trim((string) $claim->description), 200), 'Receipt' => $claim->receipt_path ? 'Attached' : 'None', 'Status' => 'Pending approval']));
    }

    /** How long a leave request has waited, in words ("5 hours", "2 days"). */
    public static function waiting(LeaveRequest $leave): string
    {
        $hours = (int) $leave->created_at->diffInHours(now());

        return $hours < 24 ? max(1, $hours).' hour'.($hours === 1 ? '' : 's') : intdiv($hours, 24).' day'.(intdiv($hours, 24) === 1 ? '' : 's');
    }

    /** Undecided for 24 hours: the Super Admin is told (once). */
    public function leavePendingEscalated(LeaveRequest $leave): void
    {
        $leave->loadMissing(['employee', 'leaveType']);
        $this->telegram->toAdmin('leave_pending', "leave:{$leave->id}:pending-24h", TelegramNotificationService::format('Leave not decided for 24 hours', [...self::who($leave->employee), 'Leave Type' => $leave->typeName() ?? 'Leave', 'From' => $leave->starts_on?->format('d F Y'), 'To' => $leave->ends_on?->format('d F Y'),
            'Asked on' => $leave->created_at?->format('d F Y, h:i A'), 'Waiting' => self::waiting($leave), 'Reason' => $leave->reason, 'Status' => 'Pending - no action taken yet. Please approve or reject.']));
    }

    /** Every morning: one message listing every leave request nobody has decided yet (oldest first). */
    public function leavePendingDigest(\Illuminate\Support\Collection $leaves): void
    {
        if ($leaves->isEmpty()) return;
        $lines = [];
        foreach ($leaves->values() as $i => $leave) {
            $leave->loadMissing(['employee', 'leaveType']);
            $range = $leave->starts_on->format('d M').($leave->ends_on->isSameDay($leave->starts_on) ? '' : ' - '.$leave->ends_on->format('d M'));
            $when = $leave->starts_on->lt(today()) ? 'already started' : ($leave->starts_on->isToday() ? 'starts TODAY' : ($leave->starts_on->isTomorrow() ? 'starts tomorrow' : null));
            $lines[] = ($i + 1).'. '.$leave->employee?->name.' · '.($leave->typeName() ?? 'Leave').' · '.$range.' · waiting '.self::waiting($leave).($when ? ' · '.$when : '');
        }
        $this->telegram->toAdmin('leave_pending', 'leave-pending-digest:'.today()->toDateString(), TelegramNotificationService::format('Leave requests not decided ('.$leaves->count().')', [...$lines, 'No action taken yet. Approve or reject them in Leave > Approvals.']));
    }

    /** The leave day has come and nobody has decided the request. */
    public function leavePendingUrgent(LeaveRequest $leave): void
    {
        $leave->loadMissing(['employee', 'leaveType']);
        $this->telegram->toAdmin('leave_pending', "leave:{$leave->id}:pending-urgent", TelegramNotificationService::format('Leave not decided', [...self::who($leave->employee), 'Leave Type' => $leave->typeName() ?? 'Leave', 'From' => $leave->starts_on?->format('d F Y'), 'To' => $leave->ends_on?->format('d F Y'), 'Asked on' => $leave->created_at?->format('d F Y'), 'Status' => 'Pending Approval - please approve or reject']));
    }

    public function correctionRequested(AttendanceCorrection $c): void
    {
        $c->loadMissing('employee');
        $this->telegram->toAdmin('correction_requested', "correction:{$c->id}:requested", TelegramNotificationService::format('Attendance correction requested', [...self::who($c->employee), 'Date' => $c->work_date?->format('d F Y'),
            'Check-in' => $c->requested_check_in?->format('h:i A'), 'Check-out' => $c->requested_check_out?->format('h:i A'), 'Break' => $c->requested_break_minutes ? Duration::words($c->requested_break_minutes) : null, 'Reason' => $c->reason, 'Status' => 'Pending approval']));
    }

    /** A daily status: today's, or an old day's (sent late, with the reason given). */
    public function dailyStatus(\App\Models\DailyWorkStatus $status): void
    {
        $status->loadMissing('employee');
        $old = $status->work_date && $status->work_date->lt(today());
        $this->telegram->toAdmin('daily_status', "status:{$status->id}", TelegramNotificationService::format($old ? 'Daily status (old day)' : 'Daily status submitted', [...self::who($status->employee), 'Date' => $status->work_date?->format('d F Y'),
            'Submitted' => now()->format('d F Y, h:i A'), 'Summary' => \Illuminate\Support\Str::limit(trim((string) $status->summary), 300), 'Late reason' => $status->late_reason]));
    }

    public function correction(AttendanceCorrection $c): void
    {
        $c->loadMissing('employee');
        $this->telegram->toAdmin('correction_decided', "correction:{$c->id}:{$c->status}", TelegramNotificationService::format('Attendance correction '.$c->status, [...self::who($c->employee), 'Date' => $c->work_date?->format('d F Y'),
            'Check-in' => $c->requested_check_in?->format('h:i A'), 'Check-out' => $c->requested_check_out?->format('h:i A'), 'Reason' => $c->reason, 'Note' => $c->decision_note]));
    }

    public function task(Task $task, string $what): void
    {
        $task->loadMissing(['assignee', 'project']);
        $event = $what === 'completed' ? 'task_completed' : 'task_assigned';
        $this->telegram->toAdmin($event, "task:{$task->id}:{$what}:".($what === 'assigned' ? $task->assignee_id : ''), TelegramNotificationService::format($what === 'completed' ? 'Task completed' : 'Task assigned', [...self::who($task->assignee), 'Task' => $task->title, 'Project' => $task->project?->name, 'Due' => $task->due_on?->format('d F Y'), 'Priority' => $task->priority ? ucfirst((string) $task->priority) : null]));
    }

    public function payslip(Payslip $payslip): void
    {
        $payslip->loadMissing(['employee', 'item.run']);
        $run = $payslip->item?->run;
        $this->telegram->toAdmin('payslip', "payslip:{$payslip->id}", TelegramNotificationService::format('Salary slip generated', [...self::who($payslip->employee), 'Period' => $run ? $run->period_starts_on?->format('d M').' – '.$run->period_ends_on?->format('d M Y') : null, 'Net' => $payslip->item ? self::money((float) $payslip->item->net_amount) : null, 'Reference' => $payslip->reference]));
    }

    public function salaryPayment(SalaryPayment $payment): void
    {
        $payment->loadMissing('payrollItem.employee');
        $this->telegram->toAdmin('salary_payment', "salary_payment:{$payment->id}", TelegramNotificationService::format('Salary payment recorded', [...self::who($payment->payrollItem?->employee), 'Amount' => self::money((float) $payment->amount), 'Paid On' => $payment->paid_on ? Carbon::parse($payment->paid_on)->format('d F Y') : null, 'Method' => $payment->method]));
    }

    public function advancePaid(SalaryAdvance $advance, float $amount): void
    {
        $advance->loadMissing('employee');
        $this->telegram->toAdmin('advance_paid', "advance:{$advance->id}:".number_format((float) $advance->disbursed_amount, 2, '.', ''), TelegramNotificationService::format('Advance payment recorded', [...self::who($advance->employee), 'Amount' => self::money($amount), 'Total advance' => self::money((float) $advance->amount), 'Recovery per payroll' => self::money((float) $advance->installment_amount)]));
    }

    public function fine(EmployeePenalty $penalty, string $what): void
    {
        $penalty->loadMissing('employee');
        $this->telegram->toAdmin('fine', "fine:{$penalty->id}:{$what}", TelegramNotificationService::format($what === 'removed' ? 'Fine removed' : 'Fine made', [...self::who($penalty->employee), 'Date' => $penalty->effective_on?->format('d F Y'), 'Amount' => self::money((float) $penalty->amount), 'Why' => $penalty->reason, 'Note' => $what === 'removed' ? $penalty->decision_note : null]));
    }
}
