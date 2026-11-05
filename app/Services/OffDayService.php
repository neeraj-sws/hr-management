<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\OffDayRequest;
use App\Support\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Off day requests: for an employee whose week-of-month offs (e.g. 2nd Saturday) count only when asked for
 * (Settings > Working calendar, "Offs only when asked for"). They ask "I am taking my off on this day"; HR / admin
 * approve or reject it, or it is approved at once when "approve automatically" is ticked for them. Approved: the day is an off (paid like any weekly off). Not asked for or not approved: a
 * normal working day, so not coming in is absent.
 */
class OffDayService
{
    /** How far back and ahead an off day can be asked for (days). */
    public const DAYS_BACK = 7;
    public const DAYS_AHEAD = 60;

    public function __construct(private readonly AuditLogger $audit, private readonly PolicyConfigurationService $policies) {}

    /** The employee's days that are off only when asked for, in the window they can ask for. @return list<string> */
    public function requestableDates(AttendanceEmployee $employee): array
    {
        $from = today()->subDays(self::DAYS_BACK);
        $to = today()->addDays(self::DAYS_AHEAD);
        $out = [];
        for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonth()) {
            $calendar = $this->policies->forEmployee($employee, 'calendar.working_days', $month->copy()->endOfMonth()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
            $out = [...$out, ...WorkCalendar::requestableOffs($calendar, $month->copy()->max($from), $month->copy()->endOfMonth()->min($to))];
        }

        return $out;
    }

    public function needsRequests(AttendanceEmployee $employee): bool
    {
        return ! empty($this->calendar($employee)['offs_need_request']);
    }

    /** Their requests are approved at once (Settings > Working calendar, "Approve automatically"). */
    public function autoApproves(AttendanceEmployee $employee): bool
    {
        return ! empty($this->calendar($employee)['offs_auto_approve']);
    }

    private function calendar(AttendanceEmployee $employee): array
    {
        return $this->policies->forEmployee($employee, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
    }

    public function request(AttendanceEmployee $employee, string $date, ?string $note = null): OffDayRequest
    {
        $day = Carbon::parse($date)->startOfDay();
        $note = trim((string) $note) ?: null;
        if ($day->lt(today()->subDays(self::DAYS_BACK))) throw ValidationException::withMessages(['off_date' => 'An off day can be asked for up to '.self::DAYS_BACK.' days back. For older days ask HR.']);
        if ($day->gt(today()->addDays(self::DAYS_AHEAD))) throw ValidationException::withMessages(['off_date' => 'An off day can be asked for up to '.self::DAYS_AHEAD.' days ahead.']);
        if (! in_array($day->toDateString(), $this->requestableDates($employee), true)) {
            throw ValidationException::withMessages(['off_date' => $day->format('D d M Y').' is not one of your offs that need asking for (e.g. your 2nd Saturday).']);
        }
        if (OffDayRequest::where('employee_id', $employee->id)->whereDate('off_date', $day)->whereIn('status', ['pending', 'approved'])->exists()) {
            throw ValidationException::withMessages(['off_date' => 'You already asked for this off day.']);
        }

        $auto = $this->autoApproves($employee);
        $request = DB::transaction(function () use ($employee, $day, $note, $auto) {
            $request = OffDayRequest::create(['employee_id' => $employee->id, 'off_date' => $day->toDateString(), 'note' => $note ? mb_substr($note, 0, 1000) : null, 'status' => 'pending']);
            ApprovalRequest::create(['approvable_type' => OffDayRequest::class, 'approvable_id' => $request->id, 'approver_id' => null, 'status' => 'pending']);
            $this->audit->record('off_day.requested', $request, ['date' => $day->toDateString()]);
            Notifier::send(Notifier::withPermission('hr.manage', 'approvals.manage'), 'OffDay', $request->id,
                "{$employee->name} is taking the off on ".$day->format('D d M Y').($auto ? ' (approved automatically)' : ' and asks you to approve it').($note ? ": {$note}" : '.'),
                route($auto ? 'leave.off-days' : 'approvals.center', $auto ? ['tab' => 'team'] : [], false), $employee->user_id);

            return $request;
        });

        return $auto ? $this->decide($request, null, true, 'Approved automatically') : $request;
    }

    /** $approver null: approved automatically (setting). */
    public function decide(OffDayRequest $request, ?int $approver, bool $approve, ?string $note = null): OffDayRequest
    {
        $note = trim((string) $note) ?: null;
        if (! $approve && ! $note) throw ValidationException::withMessages(['note' => 'Say why the off day is not approved.']);

        return DB::transaction(function () use ($request, $approver, $approve, $note) {
            $request = OffDayRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') throw ValidationException::withMessages(['approval' => 'This request has already been decided.'])->status(409);
            $date = $request->off_date->toDateString();
            // Approving turns a working day into an off: a payroll already worked out for it is redone / carried over.
            $carry = $approve && app(PayrollLockGuard::class)->changedOrCarry($request->employee_id, $date, 'off day');
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approver, 'decided_at' => now(), 'decision_note' => $note]);
            ApprovalRequest::where('approvable_type', OffDayRequest::class)->where('approvable_id', $request->id)->where('status', 'pending')
                ->update(['approver_id' => $approver, 'status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'comment' => $note]);
            $this->audit->record($approve ? 'off_day.approved' : 'off_day.rejected', $request);
            if ($carry) app(PayrollCarryOver::class)->settle($request->employee_id, $date, $date, 'Off day on '.$request->off_date->format('d M Y'), $approver);
            $day = $request->off_date->format('D d M');
            Notifier::send($request->employee?->user, 'OffDay', $request->id, $approve
                ? "Your off on {$day} is approved."
                : "Your off on {$day} was not approved, so it is a working day.".($note ? " Reason: {$note}" : ''), null, $approver);

            return $request->fresh();
        });
    }

    public function cancel(OffDayRequest $request, AttendanceEmployee $employee): OffDayRequest
    {
        abort_unless($request->employee_id === $employee->id, 403);
        if ($request->status !== 'pending') throw ValidationException::withMessages(['request' => 'Only a request waiting for approval can be cancelled.']);
        $request->update(['status' => 'cancelled', 'decided_at' => now()]);
        ApprovalRequest::where('approvable_type', OffDayRequest::class)->where('approvable_id', $request->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
        $this->audit->record('off_day.cancelled', $request);

        return $request;
    }
}
