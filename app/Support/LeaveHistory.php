<?php

namespace App\Support;

use App\Models\AttendanceEmployee;
use App\Models\LeaveRequest;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One person's leave for one month, day by day: full-day leave, half-day / short leave, half days from
 * attendance (at work under the half-day limit) and working days absent with no leave.
 */
class LeaveHistory
{
    public const DURATIONS = ['full' => 'Full day', 'half' => 'Half day', 'short' => 'Short leave'];

    /**
     * @return array{month: Carbon, summary: array{full: float, half: int, short: int, half_day: int, absent: int}, days: Collection}
     */
    public static function month(AttendanceEmployee $employee, string $month): array
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $attendance = app(AttendanceService::class);
        $leaves = LeaveRequest::with(['leaveType', 'decider'])->where('employee_id', $employee->id)->whereIn('status', ['approved', 'pending'])
            ->whereDate('starts_on', '<=', $end->toDateString())->whereDate('ends_on', '>=', $start->toDateString())->get();
        $from = $start->copy();
        if ($employee->joined_at && Carbon::parse($employee->joined_at)->gt($from)) $from = Carbon::parse($employee->joined_at)->startOfDay();
        $days = $from->lte($end) ? $attendance->days($employee, $from, $end) : collect();

        $rows = collect();
        foreach ($days as $day) {
            $date = $day['date'];
            if (in_array($day['status'], ['weekend', 'holiday', 'not_joined', 'left'], true)) continue; // off days need no leave
            $leave = $leaves->first(fn ($l) => $l->starts_on->toDateString() <= $date && $l->ends_on->toDateString() >= $date);
            $session = $day['session'];
            if ($leave) {
                $rows->push(['date' => $date, 'kind' => $leave->duration === 'full' ? 'full' : ($leave->duration === 'half' ? 'half' : 'short'),
                    'what' => (self::DURATIONS[$leave->duration] ?? 'Leave').' leave', 'type' => $leave->leaveType?->name, 'paid' => (bool) $leave->leaveType?->paid,
                    'status' => $leave->status, 'detail' => trim(($leave->reason ?: '').($leave->decider ? ' · '.($leave->status === 'approved' ? 'approved' : $leave->status).' by '.$leave->decider->name : ''), ' ·') ?: null,
                    'at_work' => $session && $session->check_out ? $attendance->officeSeconds($session) : null]);
            } elseif ($day['status'] === 'half_day') {
                $rows->push(['date' => $date, 'kind' => 'half_day', 'what' => 'Half day', 'type' => null, 'paid' => null, 'status' => 'half_day',
                    'detail' => 'at work '.Duration::hms((int) $attendance->officeSeconds($session)).' (under the half-day limit) · '.$session->check_in->format('h:i A').' – '.$session->check_out?->format('h:i A'),
                    'at_work' => $attendance->officeSeconds($session)]);
            } elseif ($day['status'] === 'absent') {
                $rows->push(['date' => $date, 'kind' => 'absent', 'what' => 'Absent, no leave', 'type' => null, 'paid' => false, 'status' => 'absent', 'detail' => 'not paid', 'at_work' => null]);
            }
        }

        return ['month' => $start, 'days' => $rows->values(), 'summary' => [
            'full' => (float) $rows->where('kind', 'full')->count(),
            'half' => $rows->where('kind', 'half')->count(),
            'short' => $rows->where('kind', 'short')->count(),
            'half_day' => $rows->where('kind', 'half_day')->count(),
            'absent' => $rows->where('kind', 'absent')->count(),
        ]];
    }
}
