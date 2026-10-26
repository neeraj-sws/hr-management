<?php

namespace App\Support;

use App\Models\AttendanceEmployee;
use App\Models\LeaveRequest;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** One person's leave for a leave year: the totals, every request, and the days absent with no leave. */
class LeaveHistory
{
    public const DURATIONS = ['full' => 'Full day', 'half' => 'Half day', 'short' => 'Short leave'];

    /**
     * @return array{year: int, label: string, summary: array, requests: Collection, absent: Collection}
     */
    public static function for(AttendanceEmployee $employee, int $year): array
    {
        $from = LeaveYear::start($year);
        $to = LeaveYear::end($year);
        $requests = LeaveRequest::with(['leaveType', 'decider'])->where('employee_id', $employee->id)
            ->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString())
            ->orderByDesc('starts_on')->orderByDesc('id')->get();
        $approved = $requests->where('status', 'approved');
        $summary = [
            'full' => $approved->where('duration', 'full')->sum(fn ($r) => (float) $r->days),
            'half' => $approved->where('duration', 'half')->count(),
            'short' => $approved->where('duration', 'short')->count(),
            'total' => round((float) $approved->sum('days'), 2),
            'paid' => round((float) $approved->filter(fn ($r) => $r->leaveType?->paid)->sum('days'), 2),
            'unpaid' => round((float) $approved->reject(fn ($r) => $r->leaveType?->paid)->sum('days'), 2),
            'pending' => $requests->where('status', 'pending')->count(),
        ];

        // Working days marked absent with no leave (past days only, from joining): unpaid, and nobody knows why.
        $until = $to->copy()->min(today()->subDay());
        $start = $from->copy();
        if ($employee->joined_at && Carbon::parse($employee->joined_at)->gt($start)) $start = Carbon::parse($employee->joined_at)->startOfDay();
        $absent = $until->gte($start)
            ? app(AttendanceService::class)->days($employee, $start, $until)->where('status', 'absent')->pluck('date')->sortDesc()->values()
            : collect();
        $summary['absent'] = $absent->count();

        return ['year' => $year, 'label' => LeaveYear::label($year), 'summary' => $summary, 'requests' => $requests, 'absent' => $absent];
    }
}
