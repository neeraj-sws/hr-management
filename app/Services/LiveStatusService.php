<?php

namespace App\Services;

use App\Models\AttendanceSession;

/**
 * Where people are right now: working, on a break, idle, in a meeting, checked out or not checked in.
 * Idle and meeting come from what the desktop app last reported (AttendanceService::liveFigures).
 */
class LiveStatusService
{
    /** status => [label, colour] */
    public const STATUSES = [
        'working' => ['Working', '#2EAD5B'],
        'break' => ['Break', '#EF9F27'],
        'idle' => ['Idle', '#D85A30'],
        'meeting' => ['In meeting', '#378ADD'],
        'checked_out' => ['Checked out', '#888780'],
        'not_in' => ['Not checked in', '#888780'],
    ];

    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array{status: string, label: string, color: string, session: ?AttendanceSession, figures: ?array}>
     */
    public function forEmployees(array $employeeIds, ?string $date = null): array
    {
        $date ??= today()->toDateString();
        $sessions = AttendanceSession::whereIn('employee_id', $employeeIds ?: [0])->whereDate('work_date', $date)
            ->orderBy('check_in')->get()->groupBy('employee_id');
        $out = [];
        foreach ($employeeIds as $id) {
            $day = $sessions->get($id);
            $session = $day?->last();
            $figures = $session ? rescue(fn () => $this->attendance->liveFigures($session), null, false) : null;
            $status = match (true) {
                ! $session => 'not_in',
                (bool) $session->check_out => 'checked_out',
                $session->status === 'on_break' => 'break',
                ($figures['state'] ?? null) === 'idle' => 'idle',
                ($figures['state'] ?? null) === 'meeting' => 'meeting',
                default => 'working',
            };
            $out[$id] = ['status' => $status, 'label' => self::STATUSES[$status][0], 'color' => self::STATUSES[$status][1], 'session' => $session, 'sessions' => $day ?? collect(), 'figures' => $figures];
        }

        return $out;
    }
}
