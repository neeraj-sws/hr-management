<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\NightWorkRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Night work: an employee who worked at night (e.g. 10 PM – 1 AM, after the office day) asks for it with the tasks
 * worked on and what was done. Their manager (HR / admin when the manager is away) approves or rejects it; approved
 * time is paid on top at the hourly rate (Payroll: "Night work"). A payroll already approved / locked gets it in the
 * next salary.
 */
class NightWorkService
{
    /** How far back night work can be asked for (days). */
    public const DAYS_BACK = 7;

    public function __construct(private readonly AuditLogger $audit) {}

    /** Minutes from start to end; an end before the start is after midnight (22:00 – 01:30 = 210). */
    public static function minutes(string $from, string $to): int
    {
        $start = Carbon::parse('2000-01-01 '.$from);
        $end = Carbon::parse('2000-01-01 '.$to);
        if ($end->lte($start)) $end->addDay();

        return (int) $start->diffInMinutes($end);
    }

    /** @param list<int> $taskIds */
    public function request(AttendanceEmployee $employee, string $date, string $from, string $to, array $taskIds, string $workDone): NightWorkRequest
    {
        $workDone = trim($workDone);
        $day = Carbon::parse($date)->startOfDay();
        if ($day->gt(today())) throw ValidationException::withMessages(['work_date' => 'Night work can be asked for a day that has passed or today.']);
        if ($day->lt(today()->subDays(self::DAYS_BACK))) throw ValidationException::withMessages(['work_date' => 'Night work can be asked for up to '.self::DAYS_BACK.' days back. For older days ask HR.']);
        foreach (['starts_at' => $from, 'ends_at' => $to] as $key => $time) {
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw ValidationException::withMessages([$key => 'Give the time as HH:MM.']);
        }
        $minutes = self::minutes($from, $to);
        if ($minutes < 15 || $minutes > 12 * 60) throw ValidationException::withMessages(['ends_at' => 'Night work is from 15 minutes to 12 hours.']);
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
        $ownTasks = Task::where('assignee_id', $employee->id)->whereIn('id', $taskIds ?: [0])->pluck('id')->all();
        if ($taskIds && count($ownTasks) !== count($taskIds)) throw ValidationException::withMessages(['task_ids' => 'Choose only your own tasks.']);
        if (! $taskIds && mb_strlen($workDone) < 10) throw ValidationException::withMessages(['task_ids' => 'Choose the task(s) you worked on, or write what you did.']);
        if (mb_strlen($workDone) < 10) throw ValidationException::withMessages(['work_done' => 'Write what you did (at least 10 characters).']);
        if (NightWorkRequest::where('employee_id', $employee->id)->whereDate('work_date', $date)->whereIn('status', ['pending', 'approved'])->get()
            ->contains(fn (NightWorkRequest $r) => $this->overlaps($r->starts_at, $r->ends_at, $from, $to))) {
            throw ValidationException::withMessages(['starts_at' => 'You already asked for night work at this time that day.']);
        }

        return DB::transaction(function () use ($employee, $date, $from, $to, $minutes, $taskIds, $workDone) {
            $request = NightWorkRequest::create(['employee_id' => $employee->id, 'work_date' => $date, 'starts_at' => $from, 'ends_at' => $to, 'minutes' => $minutes, 'task_ids' => $taskIds ?: null, 'work_done' => mb_substr($workDone, 0, 3000), 'status' => 'pending']);
            $approverId = ApprovalInbox::approverFor($employee);
            ApprovalRequest::create(['approvable_type' => NightWorkRequest::class, 'approvable_id' => $request->id, 'approver_id' => $approverId, 'status' => 'pending']);
            $this->audit->record('night_work.requested', $request, ['minutes' => $minutes]);
            $recipients = collect([$approverId ? User::find($approverId) : null, $employee->manager?->user])->filter()->unique('id');
            Notifier::send($recipients->isNotEmpty() ? $recipients : Notifier::withPermission('hr.manage'), 'NightWork', $request->id,
                "Night work by {$employee->name} needs review: ".Carbon::parse($date)->format('d M').' ('.$request->timeRange().', '.\App\Support\Duration::words($minutes).') - '.\Illuminate\Support\Str::limit($workDone, 120),
                route('approvals.center', [], false), $employee->user_id);

            return $request;
        });
    }

    public function decide(NightWorkRequest $request, int $approver, bool $approve, ?string $note = null): NightWorkRequest
    {
        $note = trim((string) $note) ?: null;
        if (! $approve && ! $note) throw ValidationException::withMessages(['note' => 'Say why the night work is not approved.']);

        return DB::transaction(function () use ($request, $approver, $approve, $note) {
            $request = NightWorkRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') throw ValidationException::withMessages(['approval' => 'This request has already been decided.'])->status(409);
            $date = $request->work_date->toDateString();
            $carry = $approve && app(PayrollLockGuard::class)->changedOrCarry($request->employee_id, $date, 'night work');
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approver, 'decided_at' => now(), 'decision_note' => $note]);
            ApprovalRequest::where('approvable_type', NightWorkRequest::class)->where('approvable_id', $request->id)->where('status', 'pending')
                ->update(['approver_id' => $approver, 'status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'comment' => $note]);
            $this->audit->record($approve ? 'night_work.approved' : 'night_work.rejected', $request);
            if ($carry) app(PayrollCarryOver::class)->settle($request->employee_id, $date, $date, 'Night work on '.$request->work_date->format('d M Y'), $approver);
            $day = $request->work_date->format('d M');
            Notifier::send($request->employee?->user, 'NightWork', $request->id, $approve
                ? "Your night work on {$day} ({$request->timeRange()}, ".\App\Support\Duration::words($request->minutes).') is approved and will be paid with your salary.'
                : "Your night work on {$day} ({$request->timeRange()}) was not approved.".($note ? " Reason: {$note}" : ''), null, $approver);

            return $request->fresh();
        });
    }

    public function cancel(NightWorkRequest $request, AttendanceEmployee $employee): NightWorkRequest
    {
        abort_unless($request->employee_id === $employee->id, 403);
        if ($request->status !== 'pending') throw ValidationException::withMessages(['request' => 'Only a request waiting for approval can be cancelled.']);
        $request->update(['status' => 'cancelled', 'decided_at' => now()]);
        ApprovalRequest::where('approvable_type', NightWorkRequest::class)->where('approvable_id', $request->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
        $this->audit->record('night_work.cancelled', $request);

        return $request;
    }

    private function overlaps(string $aFrom, string $aTo, string $bFrom, string $bTo): bool
    {
        $range = function (string $from, string $to) {
            $start = Carbon::parse('2000-01-01 '.$from);
            $end = Carbon::parse('2000-01-01 '.$to);
            if ($end->lte($start)) $end->addDay();

            return [$start, $end];
        };
        [$a1, $a2] = $range($aFrom, $aTo);
        [$b1, $b2] = $range($bFrom, $bTo);

        return $a1->lt($b2) && $b1->lt($a2);
    }
}
