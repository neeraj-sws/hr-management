<?php

namespace App\Console\Commands;

use App\Models\ApprovalRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Notifier;
use App\Services\TelegramEvents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Leave requests nobody has decided, so they never stay "pending" (and the days "absent") by mistake:
 *  1. each day from 10:00 the approver and the employee's manager are reminded (once a day) until it is decided;
 *  2. after 24 hours undecided it goes to HR (leave.manage) and the administrators too (once);
 *  3. when the leave day arrives and it is still undecided: an urgent alert to the administrators (once), also on
 *     the Super Admin Telegram chat.
 * Runs every hour (routes/console.php); each step is sent once, however often it runs.
 */
class RemindPendingLeave extends Command
{
    protected $signature = 'leave:pending-reminders';
    protected $description = 'Remind approvers of undecided leave, escalate after 24 hours, alert when the leave day comes';

    public function handle(): int
    {
        $now = now();
        $counts = ['reminded' => 0, 'escalated' => 0, 'urgent' => 0];
        $admins = User::role('Master Admin')->where('is_active', true)->get();
        $hr = Notifier::withPermission('leave.manage');

        foreach (LeaveRequest::with(['employee.user', 'employee.manager.user', 'leaveType'])->where('status', 'pending')->get() as $leave) {
            $employee = $leave->employee;
            if (! $employee) continue;
            $range = $leave->starts_on->format('d M').($leave->ends_on->isSameDay($leave->starts_on) ? '' : ' – '.$leave->ends_on->format('d M Y'));
            $what = "{$employee->name}'s ".($leave->typeName() ?? 'leave')." ({$range}, ".rtrim(rtrim(number_format((float) $leave->days, 2), '0'), '.').' day(s))';
            $url = route('leave.requests', ['tab' => 'approvals'], false);
            $approverId = ApprovalRequest::where('approvable_type', LeaveRequest::class)->where('approvable_id', $leave->id)->where('status', 'pending')->value('approver_id');
            $approvers = collect([$approverId ? User::find($approverId) : null, $employee->manager?->user])->filter()->unique('id');
            if ($approvers->isEmpty()) $approvers = $hr;

            // 1. Daily reminder (from 10:00, once a day).
            if ($now->format('H:i') >= '10:00' && Cache::add("leave-pending:remind:{$leave->id}:{$now->toDateString()}", true, $now->copy()->endOfDay())) {
                $counts['reminded'] += Notifier::send($approvers, 'LeaveReminder', $leave->id, "Reminder: {$what} is still waiting for your decision (asked on {$leave->created_at->format('d M')}). Approve or reject it.", $url, $employee->user_id);
            }
            // 2. Undecided for 24 hours: HR and the administrators too (once).
            if ($leave->created_at->lte($now->copy()->subDay()) && Cache::add("leave-pending:escalated:{$leave->id}", true, now()->addDays(90))) {
                $counts['escalated'] += Notifier::send($hr->merge($admins)->unique('id')->reject(fn ($u) => $approvers->contains('id', $u->id)), 'LeaveEscalated', $leave->id, "Not decided for 24 hours: {$what}. Please approve or reject it.", $url, $employee->user_id);
            }
            // 3. The leave day has come and it is still undecided: urgent (once).
            if ($leave->starts_on->lte($now->copy()->startOfDay()) && Cache::add("leave-pending:urgent:{$leave->id}", true, now()->addDays(90))) {
                $counts['urgent'] += Notifier::send($admins->merge($hr)->unique('id'), 'LeaveUrgent', $leave->id, "URGENT: {$what} has started and is still not decided. Until it is, the day shows \"Leave pending\" and is not paid.", $url, $employee->user_id);
                rescue(fn () => app(TelegramEvents::class)->leavePendingUrgent($leave), null, true);
            }
        }
        $this->info("Pending leave: {$counts['reminded']} reminder(s), {$counts['escalated']} escalation(s), {$counts['urgent']} urgent alert(s).");

        return self::SUCCESS;
    }
}
