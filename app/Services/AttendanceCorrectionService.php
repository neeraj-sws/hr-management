<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceActivityEvent;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "I forgot to check out" / "my check-in was wrong": the employee asks, the
 * manager (or HR) approves, and only then is the attendance record changed.
 */
class AttendanceCorrectionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** $checkIn / $checkOut are "H:i" on $workDate; a check-out earlier than the check-in means the next morning. */
    public function request(AttendanceEmployee $employee, string $workDate, ?string $checkIn, ?string $checkOut, string $reason, ?int $breakMinutes = null): AttendanceCorrection
    {
        $date = Carbon::parse($workDate)->startOfDay();
        if ($date->gt(today())) throw ValidationException::withMessages(['workDate' => 'You can only correct today or an earlier day.']);
        if ($employee->joined_at && $date->lt($employee->joined_at)) throw ValidationException::withMessages(['workDate' => 'This date is before your joining date.']);
        if (! $checkIn && ! $checkOut && $breakMinutes === null) throw ValidationException::withMessages(['checkIn' => 'Give the correct check-in time, check-out time or break minutes.']);
        if ($breakMinutes !== null && ($breakMinutes < 0 || $breakMinutes > 720)) throw ValidationException::withMessages(['breakMinutes' => 'Break must be between 0 and 720 minutes.']);

        $session = AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', $date)->latest('id')->first();
        if (! $session && ! $checkIn) throw ValidationException::withMessages(['checkIn' => 'There is no attendance on this day, so a check-in time is needed.']);
        $in = $checkIn ? $date->copy()->setTimeFromTimeString($checkIn) : $session?->check_in;
        $out = $checkOut ? $date->copy()->setTimeFromTimeString($checkOut) : null;
        if ($out && $in && $out->lte($in)) $out->addDay();
        if ($out && $out->gt(now())) throw ValidationException::withMessages(['checkOut' => 'The check-out time cannot be in the future.']);
        if ($in && $in->gt(now())) throw ValidationException::withMessages(['checkIn' => 'The check-in time cannot be in the future.']);
        if (AttendanceCorrection::where('employee_id', $employee->id)->whereDate('work_date', $date)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['workDate' => 'You already have a pending correction for this day.']);
        }

        // With no attendance that day the approval creates it from the given check-in, so a break can come with it.
        if ($breakMinutes !== null && ! $session && ! $checkIn) throw ValidationException::withMessages(['breakMinutes' => 'There is no attendance on this day to correct the break of.']);
        if ($breakMinutes !== null && $in && $out && $breakMinutes * 60 >= $in->diffInSeconds($out)) throw ValidationException::withMessages(['breakMinutes' => 'The break must be shorter than the time between check-in and check-out.']);

        $correction = DB::transaction(function () use ($employee, $date, $checkIn, $in, $out, $reason, $session, $breakMinutes) {
            $correction = AttendanceCorrection::create([
                'employee_id' => $employee->id, 'work_date' => $date->toDateString(),
                'requested_check_in' => $checkIn ? $in : null, 'requested_check_out' => $out,
                'reason' => $reason, 'status' => 'pending', 'attendance_session_id' => $session?->id, 'requested_break_minutes' => $breakMinutes,
            ]);
            // The manager decides; if there is none or they are unavailable (inactive / on leave today) it goes to the admins.
            $approver = ApprovalInbox::approverFor($employee);
            ApprovalRequest::create(['approvable_type' => AttendanceCorrection::class, 'approvable_id' => $correction->id, 'approver_id' => $approver, 'status' => 'pending']);
            $this->audit->record('attendance.correction_requested', $correction, ['work_date' => $date->toDateString()]);

            return $correction;
        });
        // Everyone who can act on it — the administrators/HR holding attendance.correct AND the
        // employee's own manager — so a correction always reaches an admin. A notification problem
        // never sinks the employee's request.
        $what = collect([$correction->requested_check_in ? 'in '.$correction->requested_check_in->format('h:i A') : null, $correction->requested_check_out ? 'out '.$correction->requested_check_out->format('h:i A') : null, $correction->requested_break_minutes !== null ? 'break '.\App\Support\Duration::fromMinutes($correction->requested_break_minutes) : null])->filter()->join(', ');
        $approverId = ApprovalRequest::where('approvable_type', AttendanceCorrection::class)->where('approvable_id', $correction->id)->value('approver_id');
        $approvers = $this->reviewers()->push($approverId ? User::find($approverId) : null);
        Notifier::send($approvers,'AttendanceCorrection', $correction->id, "An attendance correction from {$employee->name} for {$correction->work_date->format('d M Y')} ({$what}) needs review. Reason: ".\Illuminate\Support\Str::limit($reason, 120), route('attendance.corrections', ['tab' => 'approvals'], false), $employee->user_id);
        Notifier::send($employee->user, 'AttendanceCorrection', $correction->id, "Your attendance correction for {$correction->work_date->format('d M Y')} was sent for approval.", route('attendance.corrections', [], false));

        return $correction;
    }

    public function decide(AttendanceCorrection $correction, int $approverId, bool $approve, ?string $note = null): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $approverId, $approve, $note) {
            $correction = AttendanceCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();
            if ($correction->status !== 'pending') throw ValidationException::withMessages(['correction' => 'This correction has already been decided.'])->status(409);
            $carry = $approve && app(PayrollLockGuard::class)->changedOrCarry($correction->employee_id, $correction->work_date->toDateString(), 'attendance');
            if (! $approve && ! trim((string) $note)) throw ValidationException::withMessages(['decisionNote' => 'Add a reason when rejecting.']);

            if ($approve) $correction->attendance_session_id = $this->apply($correction, $approverId)->id;
            $correction->fill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approverId, 'decided_at' => now(), 'decision_note' => $note])->save();
            ApprovalRequest::where('approvable_type', AttendanceCorrection::class)->where('approvable_id', $correction->id)->where('status', 'pending')
                ->update(['approver_id' => $approverId, 'status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'comment' => $note]);
            $this->audit->record($approve ? 'attendance.correction_approved' : 'attendance.correction_rejected', $correction);
            if ($carry) app(PayrollCarryOver::class)->settle($correction->employee_id, $correction->work_date->toDateString(), $correction->work_date->toDateString(), 'Attendance correction for '.$correction->work_date->format('d M Y'), $approverId);
            $day = $correction->work_date->format('d M');
            $by = User::find($approverId)?->name ?? 'Your approver';
            Notifier::send($correction->employee?->user, 'AttendanceCorrection', $correction->id, $approve ? "{$by} approved your attendance correction for {$day}." : "{$by} rejected your attendance correction for {$day}: {$note}");

            return $correction->fresh();
        });
    }

    public function cancel(AttendanceCorrection $correction, AttendanceEmployee $employee): AttendanceCorrection
    {
        if ($correction->employee_id !== $employee->id) abort(403);
        if ($correction->status !== 'pending') throw ValidationException::withMessages(['correction' => 'Only pending corrections can be cancelled.']);
        $correction->update(['status' => 'cancelled', 'decided_at' => now()]);
        ApprovalRequest::where('approvable_type', AttendanceCorrection::class)->where('approvable_id', $correction->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
        $this->audit->record('attendance.correction_cancelled', $correction);
        Notifier::send($this->reviewers()->push($employee->manager?->user),'AttendanceCorrection', $correction->id, "{$employee->name} cancelled their attendance correction for {$correction->work_date->format('d M Y')}.", route('attendance.corrections', ['tab' => 'all'], false), $employee->user_id);

        return $correction;
    }

    /**
     * Administrators who see every correction in Pending Approvals: holders of attendance.correct
     * or approvals.manage, and the Master Admins. The employee's own manager is added by the caller.
     */
    private function reviewers(): \Illuminate\Support\Collection
    {
        $admins = rescue(fn () => User::role('Master Admin')->where('is_active', true)->get(), collect(), false);

        return Notifier::withPermission('attendance.correct', 'approvals.manage')->merge($admins)->unique('id')->values();
    }

    /** Writes the approved times onto the day's session (creating it when the employee never checked in). */
    private function apply(AttendanceCorrection $correction, int $approverId): AttendanceSession
    {
        $session = AttendanceSession::where('employee_id', $correction->employee_id)->whereDate('work_date', $correction->work_date)->lockForUpdate()->latest('id')->first();
        $before = $session ? ['check_in' => $session->check_in?->toDateTimeString(), 'check_out' => $session->check_out?->toDateTimeString(), 'break_minutes' => intdiv((int) $session->break_seconds, 60)] : null;
        if (! $session) {
            $session = AttendanceSession::create(['employee_id' => $correction->employee_id, 'work_date' => $correction->work_date->toDateString(), 'check_in' => $correction->requested_check_in, 'status' => 'working', 'last_sync_at' => now()]);
        }
        $checkIn = $correction->requested_check_in ?? $session->check_in;
        $checkOut = $correction->requested_check_out ?? $session->check_out;
        if ($checkOut && $checkOut->lte($checkIn)) throw ValidationException::withMessages(['correction' => 'After this correction the check-out would be before the check-in.']);

        $changes = ['check_in' => $checkIn, 'last_sync_at' => now()];
        if ($correction->requested_break_minutes !== null) {
            if ($session->status === 'on_break') throw ValidationException::withMessages(['correction' => 'End the open break (or include a check-out) before correcting the break time.']);
            $session->break_seconds = $correction->requested_break_minutes * 60;
            $changes['break_seconds'] = $session->break_seconds;
        }
        if (! $checkOut && $session->check_out) $checkOut = $session->check_out;
        if ($checkOut) {
            $openBreak = $session->status === 'on_break';
            $breakSeconds = (int) $session->break_seconds;
            if ($openBreak) {
                $start = $session->events()->where('event_type', 'break_start')->latest('event_time')->value('event_time');
                if ($start && Carbon::parse($start)->lt($checkOut)) {
                    $seconds = (int) Carbon::parse($start)->diffInSeconds($checkOut);
                    $breakSeconds += $seconds;
                    AttendanceActivityEvent::create(['attendance_session_id' => $session->id, 'event_type' => 'break_end', 'event_time' => $checkOut, 'duration_seconds' => $seconds, 'metadata' => ['source' => 'correction']]);
                }
            }
            $changes += ['check_out' => $checkOut, 'status' => 'completed', 'break_seconds' => $breakSeconds,
                'active_seconds' => (int) max($checkIn->diffInSeconds($checkOut) - $breakSeconds - $session->deductedIdleSeconds(), 0)];
        }
        $session->update($changes);
        $session->increment('server_version');
        AttendanceActivityEvent::create(['attendance_session_id' => $session->id, 'event_type' => 'correction', 'event_time' => now(), 'duration_seconds' => 0,
            'metadata' => ['correction_id' => $correction->id, 'approved_by' => $approverId, 'before' => $before, 'after' => ['check_in' => $checkIn?->toDateTimeString(), 'check_out' => $checkOut?->toDateTimeString(), 'break_minutes' => $correction->requested_break_minutes]]]);

        return $session;
    }
}
