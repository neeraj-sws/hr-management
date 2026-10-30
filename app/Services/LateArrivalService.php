<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\LateArrivalRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Coming late, told in advance: the employee sends the expected check-in time and a valid reason before their day
 * starts. The manager (HR / admin when away) approves or not - sending it does not mean approval. Approved and
 * checked in by that time: no late fine and the day is not cut for the late check-in.
 */
class LateArrivalService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function request(AttendanceEmployee $employee, string $date, string $expectedAt, string $reason): LateArrivalRequest
    {
        $reason = trim($reason);
        $day = Carbon::parse($date)->startOfDay();
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $expectedAt)) throw ValidationException::withMessages(['expected_at' => 'Give the time as HH:MM.']);
        if ($day->lt(today())) throw ValidationException::withMessages(['work_date' => 'Tell in advance: a late arrival can be sent for today or a later day.']);
        if ($day->gt(today()->addDays(30))) throw ValidationException::withMessages(['work_date' => 'Send it at most 30 days ahead.']);
        // In advance: before the day's start time.
        $start = app(AttendanceService::class)->rules($employee, $day->toDateString())['work_start'] ?? null;
        if ($start && $day->isToday() && now()->format('H:i') >= substr((string) $start, 0, 5)) {
            throw ValidationException::withMessages(['work_date' => 'Tell in advance: today\'s late arrival must be sent before '.Carbon::parse($start)->format('h:i A').'.']);
        }
        if ($start && $expectedAt <= substr((string) $start, 0, 5)) throw ValidationException::withMessages(['expected_at' => 'The expected check-in is after your start time ('.Carbon::parse($start)->format('h:i A').').']);
        if (mb_strlen($reason) < 10) throw ValidationException::withMessages(['reason' => 'Give a valid reason (at least 10 characters).']);
        if (LateArrivalRequest::where('employee_id', $employee->id)->whereDate('work_date', $date)->whereIn('status', ['pending', 'approved'])->exists()) {
            throw ValidationException::withMessages(['work_date' => 'A late arrival for this day was already sent.']);
        }

        return DB::transaction(function () use ($employee, $date, $expectedAt, $reason) {
            $personal = \App\Support\PersonalReason::match($reason, 'late');
            $request = LateArrivalRequest::create(['employee_id' => $employee->id, 'work_date' => $date, 'expected_at' => $expectedAt, 'reason' => mb_substr($reason, 0, 2000), 'status' => $personal ? 'rejected' : 'pending',
                'decided_at' => $personal ? now() : null, 'decision_note' => $personal ? \App\Support\PersonalReason::note('late') : null]);
            $this->audit->record($personal ? 'late_arrival.auto_rejected' : 'late_arrival.requested', $request, ['expected_at' => $expectedAt]);
            $day = Carbon::parse($date)->format('d M');
            if ($personal) {
                Notifier::send($employee->user, 'LateArrival', $request->id, "Your late arrival for {$day} was rejected automatically. {$request->decision_note}");

                return $request;
            }
            $approverId = ApprovalInbox::approverFor($employee);
            ApprovalRequest::create(['approvable_type' => LateArrivalRequest::class, 'approvable_id' => $request->id, 'approver_id' => $approverId, 'status' => 'pending']);
            $recipients = collect([$approverId ? User::find($approverId) : null, $employee->manager?->user])->filter()->unique('id');
            Notifier::send($recipients->isNotEmpty() ? $recipients : Notifier::withPermission('hr.manage'), 'LateArrival', $request->id,
                "Late arrival of {$employee->name} needs review: {$day}, expected check-in {$request->expectedLabel()}. Reason: ".\Illuminate\Support\Str::limit($reason, 150),
                route('approvals.center', [], false), $employee->user_id);

            return $request;
        });
    }

    public function decide(LateArrivalRequest $request, int $approver, bool $approve, ?string $note = null): LateArrivalRequest
    {
        $note = trim((string) $note) ?: null;
        if (! $approve && ! $note) throw ValidationException::withMessages(['note' => 'Say why the late arrival is not approved.']);

        $request = DB::transaction(function () use ($request, $approver, $approve, $note) {
            $request = LateArrivalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') throw ValidationException::withMessages(['approval' => 'This request has already been decided.'])->status(409);
            $date = $request->work_date->toDateString();
            $carry = $approve && $request->work_date->lt(today()) && app(PayrollLockGuard::class)->changedOrCarry($request->employee_id, $date, 'late arrival');
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approver, 'decided_at' => now(), 'decision_note' => $note]);
            ApprovalRequest::where('approvable_type', LateArrivalRequest::class)->where('approvable_id', $request->id)->where('status', 'pending')
                ->update(['approver_id' => $approver, 'status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'comment' => $note]);
            $this->audit->record($approve ? 'late_arrival.approved' : 'late_arrival.rejected', $request);
            if ($carry) app(PayrollCarryOver::class)->settle($request->employee_id, $date, $date, 'Late arrival approved for '.$request->work_date->format('d M Y'), $approver);
            $day = $request->work_date->format('d M');
            Notifier::send($request->employee?->user, 'LateArrival', $request->id, $approve
                ? "Your late arrival for {$day} is approved: check in by {$request->expectedLabel()} and no late fine or late cut applies."
                : "Your late arrival for {$day} was not approved: the normal late rules apply.".($note ? " Reason: {$note}" : ''), null, $approver);

            return $request->fresh();
        });
        // Decided after the day: its late fine goes (or stays) at once.
        if ($request->work_date->lt(today()) && $request->employee) app(PeopleOperationsService::class)->reassessDay($request->employee, $request->work_date->toDateString(), $approver, 'late arrival '.($approve ? 'approved' : 'not approved'));

        return $request;
    }

    public function cancel(LateArrivalRequest $request, AttendanceEmployee $employee): LateArrivalRequest
    {
        abort_unless($request->employee_id === $employee->id, 403);
        if ($request->status !== 'pending') throw ValidationException::withMessages(['request' => 'Only a request waiting for approval can be cancelled.']);
        $request->update(['status' => 'cancelled', 'decided_at' => now()]);
        ApprovalRequest::where('approvable_type', LateArrivalRequest::class)->where('approvable_id', $request->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
        $this->audit->record('late_arrival.cancelled', $request);

        return $request;
    }
}
