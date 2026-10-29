<?php

namespace App\Support;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Models\SalaryPayment;
use App\Models\Task;
use App\Services\TelegramNotificationService as Telegram;
use Illuminate\Support\Carbon;

/**
 * A person's own notification as a Telegram message: "SWS HRMS | LEAVE APPROVED", "Hello Priya,", what happened to
 * their record, then the details. Built from the record the notification is about - only when it is that person's own
 * record; anything else is the notification text under a title. Templates only: sending is TelegramNotificationService.
 */
class TelegramTemplates
{
    /** Title for the kinds without a template of their own. */
    private const TITLES = [
        'EmployeePenalty' => 'Fine', 'PenaltyAppeal' => 'Fine reason', 'DailyStatusMissed' => 'Daily status not written', 'DailyStatusReminder' => 'Daily status reminder',
        'DailyStatusSubmitted' => 'Daily status', 'AttendanceLate' => 'Late arrival', 'AttendanceAbsent' => 'Not checked in', 'AttendanceSession' => 'Attendance',
        'CheckoutReminder' => 'Check-out reminder', 'ShiftReminder' => 'Shift reminder', 'LeaveBalance' => 'Leave balance updated', 'SalaryAdjustment' => 'Salary adjustment',
        'SalaryAdvance' => 'Salary advance', 'OvertimeRequest' => 'Overtime', 'ExpenseClaim' => 'Expense claim', 'TaskComment' => 'Task comment', 'TaskCompleted' => 'Task completed',
        'TaskOverdue' => 'Task overdue', 'TaskUpdated' => 'Task updated', 'EmployeeDocument' => 'Document', 'TrainingAssignment' => 'Training', 'Announcement' => 'Announcement',
    ];

    private static function money(float $v): string
    {
        return 'Rs '.number_format($v, 2);
    }

    private static function date($d): ?string
    {
        return $d ? Carbon::parse($d)->format('d F Y') : null;
    }

    public static function forNotification(string $kind, int $recordId, string $message, object $notifiable, string $url): string
    {
        $name = trim(strtok((string) ($notifiable->name ?? ''), ' ')) ?: 'there';
        $hello = "Hello {$name},";
        $built = rescue(fn () => self::own($kind, $recordId, $message, (int) $notifiable->getKey(), $hello), null, false);
        [$title, $lines] = $built ?? [self::TITLES[$kind] ?? 'Notification', [$hello, $message]];

        return Telegram::format($title, $lines)."\n\n<a href=\"".Telegram::escape($url).'">Open SWS HRMS</a>';
    }

    /** [title, lines] for the person's own leave, payslip, payment, advance, correction or task; null otherwise. */
    private static function own(string $kind, int $id, string $message, int $userId, string $hello): ?array
    {
        $by = fn ($userId) => $userId ? \App\Models\User::find($userId)?->name : null;
        switch ($kind) {
            case 'LeaveRequest':
                $leave = LeaveRequest::with(['employee', 'leaveType'])->find($id);
                if (! $leave || $leave->employee?->user_id !== $userId || ! in_array($leave->status, ['approved', 'rejected'], true) || ! preg_match('/(approved|rejected) your leave/i', $message)) return null;
                $days = rtrim(rtrim(number_format((float) $leave->days, 2), '0'), '.');

                return ['Leave '.$leave->status, [$hello, 'Your '.($leave->leaveType?->name ?? 'leave')." request for {$days} day(s) has been {$leave->status}.", 'Leave Type' => $leave->leaveType?->name, 'From' => self::date($leave->starts_on), 'To' => self::date($leave->ends_on), ucfirst($leave->status).' by' => $by($leave->decided_by), 'Note' => $leave->decision_note]];
            case 'Payslip':
                $slip = Payslip::with(['employee', 'item.run'])->find($id);
                if (! $slip || $slip->employee?->user_id !== $userId) return null;

                return ['Salary slip available', [$hello, 'Your salary slip for '.($slip->item?->run?->period_starts_on?->format('F Y') ?? 'this month').' is ready.', 'Net Salary' => $slip->item ? self::money((float) $slip->item->net_amount) : null, 'Reference' => $slip->reference]];
            case 'SalaryPayment':
                $payment = SalaryPayment::with('payrollItem.employee', 'payrollItem.run')->find($id);
                if (! $payment || $payment->payrollItem?->employee?->user_id !== $userId) return null;

                return ['Salary payment recorded', [$hello, 'A salary payment has been recorded for you.', 'Amount' => self::money((float) $payment->amount), 'For' => $payment->payrollItem?->run?->period_starts_on?->format('F Y'), 'Paid On' => self::date($payment->paid_on), 'Method' => $payment->method]];
            case 'SalaryAdvance':
                $advance = SalaryAdvance::with('employee')->find($id);
                if (! $advance || $advance->employee?->user_id !== $userId) return null;
                if (str_contains(strtolower($message), 'paid')) {
                    return ['Advance payment recorded', [$hello, $message, 'Total advance' => self::money((float) $advance->amount), 'Paid so far' => self::money((float) $advance->disbursed_amount), 'Recovery per payroll' => self::money((float) $advance->installment_amount)]];
                }

                return ['Salary advance '.$advance->status, [$hello, $message, 'Amount' => self::money((float) $advance->amount), 'Recovery per payroll' => self::money((float) $advance->installment_amount)]];
            case 'AttendanceCorrection':
                $c = AttendanceCorrection::with('employee')->find($id);
                if (! $c || $c->employee?->user_id !== $userId || ! in_array($c->status, ['approved', 'rejected'], true)) return null;

                return [$c->status === 'approved' ? 'Attendance corrected' : 'Attendance correction rejected', [$hello, $message, 'Date' => self::date($c->work_date), 'Check-in' => $c->requested_check_in?->format('h:i A'), 'Check-out' => $c->requested_check_out?->format('h:i A'), 'Note' => $c->decision_note]];
            case 'TaskAssignment':
            case 'TaskUpdated':
                $task = Task::with(['assignee', 'project'])->find($id);
                if (! $task || $task->assignee?->user_id !== $userId) return null;

                return [$kind === 'TaskAssignment' ? 'Task assigned' : 'Task updated', [$hello, $message, 'Task' => $task->title, 'Project' => $task->project?->name, 'Status' => str_replace('_', ' ', (string) $task->status), 'Due' => self::date($task->due_on), 'Priority' => $task->priority ? ucfirst((string) $task->priority) : null]];
        }

        return null;
    }
}
