<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\LeaveBalance;
use App\Models\LeaveConflictResolution;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Days with approved leave AND attendance. Payroll never pays them twice
 * (see HrWorkflowService), and HR decides here whether the day counts as
 * worked (the leave day is given back) or as leave.
 */
class LeaveConflictService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Unresolved conflicts between $from and $to, optionally for some employees.
     *
     * @return Collection<int, array{employee_id:int, date:string, leave:LeaveRequest, session:AttendanceSession}>
     */
    public function open(string $from, string $to, ?array $employeeIds = null): Collection
    {
        $leaves = LeaveRequest::with(['employee', 'leaveType'])->where('status', 'approved')
            ->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds ?: [0]))->get();
        if ($leaves->isEmpty()) return collect();
        $sessions = AttendanceSession::whereIn('employee_id', $leaves->pluck('employee_id')->unique())
            ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->whereNotNull('check_in')->get()
            ->keyBy(fn ($s) => $s->employee_id.'|'.$s->work_date->toDateString());
        $resolved = LeaveConflictResolution::whereIn('employee_id', $leaves->pluck('employee_id')->unique())->whereDate('work_date','>=',$from)->whereDate('work_date','<=',$to)->get()
            ->keyBy(fn ($r) => $r->employee_id.'|'.$r->work_date->toDateString());

        $conflicts = collect();
        foreach ($leaves as $leave) {
            for ($day = $leave->starts_on->copy()->max(Carbon::parse($from)); $day->lte($leave->ends_on->copy()->min(Carbon::parse($to))); $day->addDay()) {
                $key = $leave->employee_id.'|'.$day->toDateString();
                if ($sessions->has($key) && ! $resolved->has($key)) {
                    $conflicts->push(['employee_id' => $leave->employee_id, 'date' => $day->toDateString(), 'leave' => $leave, 'session' => $sessions->get($key)]);
                }
            }
        }

        return $conflicts->sortBy('date')->values();
    }

    /** "worked": the day is paid as work and the leave day goes back to the balance. "leave": paid/unpaid as the leave type says. */
    public function resolve(LeaveRequest $leave, string $date, string $resolution, int $actor, ?string $note = null): LeaveConflictResolution
    {
        if (! in_array($resolution, ['worked', 'leave'], true)) throw ValidationException::withMessages(['resolution' => 'Choose worked or leave.']);
        if ($date < $leave->starts_on->toDateString() || $date > $leave->ends_on->toDateString()) throw ValidationException::withMessages(['date' => 'This day is not part of the leave.']);
        app(PayrollLockGuard::class)->changed($leave->employee_id, $date, 'leave');

        return DB::transaction(function () use ($leave, $date, $resolution, $actor, $note) {
            if (LeaveConflictResolution::where('employee_id', $leave->employee_id)->whereDate('work_date', $date)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['date' => 'This day has already been resolved.'])->status(409);
            }
            $record = LeaveConflictResolution::create(['employee_id' => $leave->employee_id, 'leave_request_id' => $leave->id, 'work_date' => $date, 'resolution' => $resolution, 'resolved_by' => $actor, 'note' => $note]);
            if ($resolution === 'worked' && $leave->leaveType?->paid && (float) $leave->days >= 1) {
                $leave->decrement('days', 1);
                $balance = LeaveBalance::where('employee_id', $leave->employee_id)->where('leave_type_id', $leave->leave_type_id)->where('year', \App\Support\LeaveYear::of($date))
                    ->where('used_days', '>=', 1)->lockForUpdate()->first();
                if ($balance) {
                    $balance->decrement('used_days', 1);
                    \App\Models\LeaveLedgerEntry::record($balance, 'reversal', 1, 'Worked on leave day '.Carbon::parse($date)->format('d M Y').' — day returned', $leave, $actor);
                }
            }
            $this->audit->record('leave.conflict_resolved', $record, ['date' => $date, 'resolution' => $resolution, 'leave_request_id' => $leave->id]);
            // The employee hears how the day was settled (and that a paid leave day came back).
            $leave->loadMissing('employee.user', 'leaveType');
            Notifier::send($leave->employee?->user, 'LeaveRequest', $leave->id, Carbon::parse($date)->format('d M Y').': you had '.($leave->leaveType?->name ?? 'leave').' and also worked. '.($resolution === 'worked' ? 'HR counted it as a working day'.($leave->leaveType?->paid ? ' and the leave day was returned to your balance.' : '.') : 'HR counted it as leave.'), route('leave.requests', [], false), $actor);

            return $record;
        });
    }
}
