<?php

namespace App\Services;

use App\Models\AttendanceActivityEvent;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Models\OfficeLocation;
use App\Models\TaskTimeEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Web/mobile attendance actions and day classification. Device-agent sessions
 * arrive through AttendanceSyncController and share the same tables.
 */
class AttendanceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PolicyConfigurationService $policies,
        private readonly ShiftService $shifts,
    ) {}

    /** Default longest a session may stay open before it is treated as a missed check-out. */
    public const DEFAULT_MAX_SESSION_HOURS = 16;

    /**
     * The current session: one still open (it may have started yesterday on a
     * night shift), otherwise today's.
     */
    public function today(AttendanceEmployee $employee): ?AttendanceSession
    {
        return $this->openSession($employee) ?? AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', today())->latest('id')->first();
    }

    /** Open session that started within the allowed window before $at. */
    public function openSession(AttendanceEmployee $employee, ?CarbonInterface $at = null, bool $lock = false): ?AttendanceSession
    {
        $at = $at ? $this->local($at) : now();
        $query = AttendanceSession::where('employee_id', $employee->id)->whereNull('check_out')
            ->where('check_in', '<=', $at->copy()->addMinutes(5))->where('check_in', '>=', $at->copy()->subHours($this->maxSessionHours($employee)))
            ->latest('check_in');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function maxSessionHours(?AttendanceEmployee $employee): int
    {
        $rules = $this->policies->forEmployee($employee, 'attendance.rules', today()->toDateString(), []);

        return max(4, min(24, (int) ($rules['max_session_hours'] ?? self::DEFAULT_MAX_SESSION_HOURS)));
    }

    /**
     * $at lets offline clients replay an action with the time it happened
     * (validated by the sync endpoint); live actions use the server clock.
     */
    public function checkIn(AttendanceEmployee $employee, string $source = 'web', ?CarbonInterface $at = null, ?array $location = null): AttendanceSession
    {
        if (! $employee->is_active) throw ValidationException::withMessages(['attendance' => 'Inactive employees cannot check in.']);
        $place = $this->locate($employee, $source, $location, 'check in');

        return DB::transaction(function () use ($employee, $source, $at, $place) {
            AttendanceEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $now = $at ? $this->local($at) : now();
            $open = $this->openSession($employee, $now);
            if ($open) throw ValidationException::withMessages(['attendance' => 'You are still checked in since '.$open->check_in->format('d M, h:i A').'. Check out first.']);
            if (AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', $now->toDateString())->exists()) throw ValidationException::withMessages(['attendance' => 'An attendance session already exists for this day.']);
            $session = AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => $now->toDateString(), 'check_in' => $now, 'status' => 'working', 'last_sync_at' => $now,
                'check_in_latitude' => $place['latitude'], 'check_in_longitude' => $place['longitude'], 'check_in_office_id' => $place['office_id'], 'check_in_distance_meters' => $place['distance']]);
            $this->event($session, 'check_in', $now, 0, ['source' => $source]);
            $this->audit->record('attendance.checked_in', $session, ['source' => $source]);

            return $session;
        });
    }

    public function startBreak(AttendanceEmployee $employee, string $source = 'web', ?CarbonInterface $at = null): AttendanceSession
    {
        return DB::transaction(function () use ($employee, $source, $at) {
            $now = $at ? $this->local($at) : now();
            $session = $this->lockedOpenSession($employee, $now);
            if ($session->status === 'on_break') throw ValidationException::withMessages(['break' => 'A break is already active.']);
            $running = TaskTimeEntry::where('employee_id', $employee->id)->where('status', 'running')->first();
            if ($running) app(HrWorkflowService::class)->stopTaskTimer($employee, $running);
            $this->event($session, 'break_start', $now, 0, ['source' => $source]);
            $session->update(['status' => 'on_break', 'last_sync_at' => now()]);
            $session->increment('server_version');
            $this->audit->record('attendance.break_started', $session);

            return $session->fresh();
        });
    }

    public function endBreak(AttendanceEmployee $employee, string $source = 'web', ?CarbonInterface $at = null): AttendanceSession
    {
        return DB::transaction(function () use ($employee, $source, $at) {
            $now = $at ? $this->local($at) : now();
            $session = $this->lockedOpenSession($employee, $now);
            if ($session->status !== 'on_break') throw ValidationException::withMessages(['break' => 'No active break found.']);
            $this->closeBreak($session, $source, $now);

            return $session->fresh();
        });
    }

    public function checkOut(AttendanceEmployee $employee, string $source = 'web', ?CarbonInterface $at = null, ?array $location = null): AttendanceSession
    {
        $place = $this->locate($employee, $source, $location, 'check out');

        return DB::transaction(function () use ($employee, $source, $at, $place) {
            $checkOut = $at ? $this->local($at) : now();
            $session = $this->lockedOpenSession($employee, $checkOut);
            if ($checkOut->lt($session->check_in)) throw ValidationException::withMessages(['attendance' => 'Check-out cannot be before check-in.']);
            if ($session->status === 'on_break') $this->closeBreak($session, $source, $checkOut);
            $running = TaskTimeEntry::where('employee_id', $employee->id)->where('status', 'running')->first();
            if ($running) app(HrWorkflowService::class)->stopTaskTimer($employee, $running);
            $session->refresh();
            $activeSeconds = max($session->check_in->diffInSeconds($checkOut) - (int) $session->break_seconds - (int) $session->idle_seconds, 0);
            $session->update(['check_out' => $checkOut, 'active_seconds' => (int) $activeSeconds, 'status' => 'completed', 'last_sync_at' => $checkOut,
                'check_out_latitude' => $place['latitude'], 'check_out_longitude' => $place['longitude'], 'check_out_office_id' => $place['office_id'], 'check_out_distance_meters' => $place['distance']]);
            $session->increment('server_version');
            $this->event($session, 'check_out', $checkOut, 0, ['source' => $source]);
            $this->audit->record('attendance.checked_out', $session, ['active_seconds' => (int) $activeSeconds]);

            return $session->fresh();
        });
    }

    /**
     * Records time away from the computer (idle, screen locked or asleep) as a
     * break, after the employee confirms it. A running task timer is split so
     * the away time is not logged against the task.
     */
    public function recordAway(AttendanceEmployee $employee, CarbonInterface $from, CarbonInterface $to, string $reason, string $source = 'desktop'): AttendanceSession
    {
        return DB::transaction(function () use ($employee, $from, $to, $reason, $source) {
            $from = $this->local($from);
            $to = $this->local($to);
            if ($to->lte($from)) throw ValidationException::withMessages(['away' => 'The away period must end after it starts.']);
            if ($to->gt(now()->addMinutes(2))) throw ValidationException::withMessages(['away' => 'The away period cannot end in the future.']);
            $session = $this->lockedOpenSession($employee, $from);
            if ($session->status !== 'working') throw ValidationException::withMessages(['away' => 'Away time can only be recorded while you are working.']);
            if ($from->lt($session->check_in)) $from = $session->check_in->copy();
            $lastChange = $session->events()->max('event_time');
            if ($lastChange && Carbon::parse($lastChange)->gt($from)) throw ValidationException::withMessages(['away' => 'This period overlaps an attendance change that is already recorded.']);

            $running = TaskTimeEntry::where('employee_id', $employee->id)->where('status', 'running')->lockForUpdate()->first();
            if ($running && $running->started_at->lt($from)) {
                $running->update(['ended_at' => $from, 'duration_seconds' => $running->started_at->diffInSeconds($from), 'status' => 'stopped']);
                TaskTimeEntry::create(['task_id' => $running->task_id, 'employee_id' => $employee->id, 'started_at' => $to, 'status' => 'running']);
            }

            $seconds = (int) $from->diffInSeconds($to);
            $this->event($session, 'break_start', $from, 0, ['source' => $source, 'reason' => $reason]);
            $this->event($session, 'break_end', $to, $seconds, ['source' => $source, 'reason' => $reason]);
            $session->increment('break_seconds', $seconds);
            $session->update(['last_sync_at' => now()]);
            $session->increment('server_version');
            $this->audit->record('attendance.away_recorded', $session, ['reason' => $reason, 'minutes' => intdiv($seconds, 60)]);

            return $session->fresh();
        });
    }

    /**
     * Keeps an open session marked as alive. The desktop app also reports
     * screen time since its last heartbeat: seconds the computer was in use
     * vs. untouched. Reports can never add up to more than the time elapsed.
     */
    public function heartbeat(AttendanceEmployee $employee, int $screenActive = 0, int $screenIdle = 0): ?AttendanceSession
    {
        $session = $this->today($employee);
        if ($session && ! $session->check_out) {
            $session = $this->recordDesktopGap($employee, $session) ?? $session;
            $changes = ['last_sync_at' => now()];
            if ($screenActive > 0 || $screenIdle > 0) {
                $since = $session->screen_reported_at ?? $session->check_in;
                $allowed = max(0, (int) $since->diffInSeconds(now()) + 15);
                $total = $screenActive + $screenIdle;
                if ($total > $allowed) {
                    $screenActive = (int) floor($screenActive * $allowed / $total);
                    $screenIdle = (int) floor($screenIdle * $allowed / $total);
                }
                $changes += ['screen_active_seconds' => (int) $session->screen_active_seconds + $screenActive, 'screen_idle_seconds' => (int) $session->screen_idle_seconds + $screenIdle, 'screen_reported_at' => now()];
            }
            $session->update($changes);
        }

        return $session;
    }

    /**
     * The desktop app reports every 30 seconds while running. If its reports
     * stopped for longer than the away threshold (computer shut down, asleep,
     * app closed) while the employee was working, that gap is recorded as a
     * break so it is never paid as work; the employee can ask for a correction.
     */
    private function recordDesktopGap(AttendanceEmployee $employee, AttendanceSession $session): ?AttendanceSession
    {
        if ($session->status !== 'working' || ! $session->screen_reported_at) return null;
        $minutes = (int) ($this->policies->forEmployee($employee, 'attendance.idle', today()->toDateString(), ['away_prompt_minutes' => 10])['away_prompt_minutes'] ?? 10);
        $gapStart = $session->screen_reported_at->copy();
        if ($minutes <= 0 || $gapStart->diffInMinutes(now()) < max(5, $minutes)) return null;
        if (($last = $session->events()->max('event_time')) && Carbon::parse($last)->gt($gapStart)) return null;

        try {
            $session = $this->recordAway($employee, $gapStart, now(), 'offline', 'desktop');
            $session->employee?->user?->notify(new \App\Notifications\ApprovalRequested('AttendanceSession', $session->id, 'The SWS HR desktop app was off from '.$gapStart->format('h:i A').' to '.now()->format('h:i A').'. That time was recorded as a break. Ask for a correction if you were working.', route('attendance.corrections', ['date' => $session->work_date->toDateString()], false)));

            return $session;
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Closes sessions nobody checked out of (scheduled). The check-out is set to
     * the last sign of activity (heartbeat or action), never "now", so a
     * forgotten check-out cannot turn into 24 paid hours. Open breaks end there too.
     */
    public function closeStaleSessions(): int
    {
        $closed = 0;
        $candidates = AttendanceSession::with('employee.user', 'employee.manager.user')->whereNull('check_out')->where('check_in', '<', now()->subHours(4))->get();
        foreach ($candidates as $candidate) {
            $employee = $candidate->employee;
            if (! $employee || $candidate->check_in->gt(now()->subHours($this->maxSessionHours($employee)))) continue;
            DB::transaction(function () use ($candidate, &$closed) {
                $session = AttendanceSession::whereKey($candidate->id)->lockForUpdate()->first();
                if (! $session || $session->check_out) return;
                $lastEvent = $session->events()->max('event_time');
                $end = collect([$session->check_in, $session->last_sync_at, $session->screen_reported_at, $lastEvent ? Carbon::parse($lastEvent) : null])->filter()->max();
                if ($session->status === 'on_break') {
                    $start = $session->events()->where('event_type', 'break_start')->latest('event_time')->first();
                    if ($start && $end->gte($start->event_time)) {
                        $seconds = (int) $start->event_time->diffInSeconds($end);
                        $this->event($session, 'break_end', $end->copy()->addSecond(), $seconds, ['source' => 'system', 'reason' => 'auto_close']);
                        $session->increment('break_seconds', $seconds);
                    }
                }
                $session->refresh();
                $active = (int) max($session->check_in->diffInSeconds($end) - (int) $session->break_seconds - (int) $session->idle_seconds, 0);
                $session->update(['check_out' => $end, 'active_seconds' => $active, 'status' => 'completed', 'closed_automatically' => true]);
                $session->increment('server_version');
                $this->event($session, 'auto_check_out', $end->copy()->addSeconds(2), 0, ['source' => 'system', 'reason' => 'no check-out']);
                $this->audit->record('attendance.auto_checked_out', $session, ['check_out' => $end->toDateTimeString(), 'active_seconds' => $active]);
                $message = 'No check-out on '.$session->work_date->format('d M').'. It was set to '.$end->format('h:i A').' (last activity). Ask for a correction if you worked longer.';
                $session->employee?->user?->notify(new \App\Notifications\ApprovalRequested('AttendanceSession', $session->id, $message, route('attendance.corrections', ['date' => $session->work_date->toDateString()], false)));
                $session->employee?->manager?->user?->notify(new \App\Notifications\ApprovalRequested('AttendanceSession', $session->id, "{$session->employee->name} did not check out on {$session->work_date->format('d M')}; check-out was set to {$end->format('h:i A')}.", route('attendance.records', [], false)));
                $closed++;
            });
        }

        return $closed;
    }

    /** Live worked seconds: completed sessions use stored active time, open ones are computed. */
    public function workedSeconds(AttendanceSession $session): int
    {
        if ($session->check_out || ! $session->check_in) return (int) $session->active_seconds;
        $breakSeconds = (int) $session->break_seconds;
        if ($session->status === 'on_break') {
            $start = $session->events()->where('event_type', 'break_start')->latest('event_time')->value('event_time');
            if ($start) $breakSeconds += Carbon::parse($start)->diffInSeconds(now());
        }

        return (int) max($session->check_in->diffInSeconds(now()) - $breakSeconds - (int) $session->idle_seconds, 0);
    }

    /** Attendance rules for an employee: work start, grace minutes and half-day threshold. */
    public function rules(AttendanceEmployee $employee, string $date): array
    {
        $configured = $this->policies->forEmployee($employee, 'attendance.rules', $date, []);
        $hours = $this->policies->forEmployee($employee, 'work.hours', $date, []);
        $dailyHours = (float) ($hours['standard_daily_hours'] ?? $employee->daily_work_hours ?: 8);

        $shift = $this->shifts->shiftFor($employee, $date);

        return [
            'work_start' => $shift ? substr((string) $shift->start_time, 0, 5) : ($employee->work_start ?: ($hours['start_time'] ?? null)),
            'grace_minutes' => $shift && $shift->grace_minutes !== null ? $shift->grace_minutes : max(0, (int) ($configured['grace_minutes'] ?? 0)),
            'shift' => $shift?->name,
            'half_day_seconds' => (int) round(((float) ($configured['half_day_hours'] ?? $dailyHours / 2)) * 3600),
            'daily_seconds' => (int) round($dailyHours * 3600),
        ];
    }

    public function isLate(AttendanceSession $session, ?array $rules = null): bool
    {
        $employee = $session->employee;
        if (! $employee || ! $session->check_in) return false;
        $rules ??= $this->rules($employee, $session->work_date->toDateString());
        if (! $rules['work_start']) return false;
        $expected = Carbon::parse($session->work_date->toDateString().' '.$rules['work_start'])->addMinutes($rules['grace_minutes']);

        return $session->check_in->gt($expected);
    }

    public function lateMinutes(AttendanceSession $session): int
    {
        $employee = $session->employee;
        if (! $employee || ! $session->check_in) return 0;
        $rules = $this->rules($employee, $session->work_date->toDateString());
        if (! $rules['work_start']) return 0;
        $expected = Carbon::parse($session->work_date->toDateString().' '.$rules['work_start']);

        return $session->check_in->gt($expected) ? (int) $expected->diffInMinutes($session->check_in) : 0;
    }

    /** Scheduled end of the working day: shift end (next day for night shifts), else the employee's or company end time. */
    public function expectedEnd(AttendanceEmployee $employee, string $date): ?Carbon
    {
        $shift = $this->shifts->shiftFor($employee, $date);
        if ($shift) {
            $end = Carbon::parse($date.' '.substr((string) $shift->end_time, 0, 5));

            return $shift->crossesMidnight() ? $end->addDay() : $end;
        }
        $time = $employee->work_end ?: ($this->policies->forEmployee($employee, 'work.hours', $date, [])['end_time'] ?? null);

        return $time ? Carbon::parse($date.' '.substr((string) $time, 0, 5)) : null;
    }

    /** Minutes checked out before the scheduled end (0 when on time, unknown, or still checked in). */
    public function earlyMinutes(AttendanceSession $session): int
    {
        if (! $session->check_out || ! $session->employee) return 0;
        $end = $this->expectedEnd($session->employee, $session->work_date->toDateString());

        return $end && $session->check_out->lt($end) ? (int) $session->check_out->diffInMinutes($end) : 0;
    }

    public function isHalfDay(AttendanceSession $session, ?array $rules = null): bool
    {
        if (! $session->check_out || ! $session->employee) return false;
        $rules ??= $this->rules($session->employee, $session->work_date->toDateString());

        return (int) $session->active_seconds < $rules['half_day_seconds'];
    }

    /**
     * Per-day status for an employee over a range: present, late, half_day,
     * on_leave, absent, holiday/weekend (non-working) or upcoming.
     *
     * @return Collection<int, array{date:string, status:string, session:?AttendanceSession}>
     */
    public function days(AttendanceEmployee $employee, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $sessions = AttendanceSession::with('employee')->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->get()->keyBy(fn ($session) => $session->work_date->toDateString());
        $leaves = LeaveRequest::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString())->get();
        $calendar = $this->policies->forEmployee($employee, 'calendar.working_days', $to->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        $joined = $employee->joined_at ? Carbon::parse($employee->joined_at)->startOfDay() : null;
        $resolutions = \App\Models\LeaveConflictResolution::where('employee_id', $employee->id)->whereDate('work_date','>=',$from->toDateString())->whereDate('work_date','<=',$to->toDateString())->get()
            ->mapWithKeys(fn ($r) => [$r->work_date->toDateString() => $r->resolution]);

        $days = collect();
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            $session = $sessions->get($date);
            $workday = in_array($day->isoWeekday(), $calendar['days'] ?? [1, 2, 3, 4, 5], true) && ! in_array($date, $calendar['holidays'] ?? [], true);
            $onLeave = $leaves->contains(fn ($leave) => $leave->starts_on->toDateString() <= $date && $leave->ends_on->toDateString() >= $date);
            $conflict = $session !== null && $onLeave ? ($resolutions[$date] ?? 'open') : null;
            $status = match (true) {
                $conflict === 'open' => 'leave_conflict',
                $conflict === 'leave' => 'on_leave',
                $session !== null && $this->isHalfDay($session) => 'half_day',
                $session !== null && $this->isLate($session) => 'late',
                $session !== null => 'present',
                $onLeave => 'on_leave',
                ! $workday => in_array($date, $calendar['holidays'] ?? [], true) ? 'holiday' : 'weekend',
                $day->gte(today()) => 'upcoming',
                $joined && $day->lt($joined) => 'not_joined',
                default => 'absent',
            };
            $days->push(['date' => $date, 'status' => $status, 'session' => $session]);
        }

        return $days;
    }

    /**
     * Where an action happened. When the employer requires it (Settings > Locations),
     * mobile check-in/out must be within an active office's radius; otherwise a
     * supplied position is stored for reference only. Positions are taken only at
     * the moment of check-in/out, never tracked in between.
     *
     * @return array{latitude: ?float, longitude: ?float, office_id: ?int, distance: ?int}
     */
    public function locate(AttendanceEmployee $employee, string $source, ?array $location, string $action = 'check in'): array
    {
        $none = ['latitude' => null, 'longitude' => null, 'office_id' => null, 'distance' => null];
        $policy = $this->policies->forEmployee($employee, 'attendance.location', today()->toDateString(), []);
        $required = $source === 'mobile' && ! empty($policy['required_for_mobile']);
        $latitude = isset($location['latitude']) && is_numeric($location['latitude']) ? (float) $location['latitude'] : null;
        $longitude = isset($location['longitude']) && is_numeric($location['longitude']) ? (float) $location['longitude'] : null;
        if ($latitude === null || $longitude === null || abs($latitude) > 90 || abs($longitude) > 180) {
            if ($required) throw ValidationException::withMessages(['location' => "Turn on location to {$action} from the mobile app. Your company requires it at an office."]);

            return $none;
        }

        $nearest = OfficeLocation::where('is_active', true)->get()
            ->map(fn (OfficeLocation $office) => ['office' => $office, 'distance' => $office->distanceTo($latitude, $longitude)])
            ->sortBy('distance')->first();
        $tolerance = (int) min(max((float) ($location['accuracy'] ?? 0), 0), 100);
        $inside = $nearest && $nearest['distance'] - $tolerance <= $nearest['office']->radius_meters;
        if ($required && ! $nearest) throw ValidationException::withMessages(['location' => 'No office locations are configured yet. Ask HR to add one under Settings > Locations.']);
        if ($required && ! $inside) {
            $away = $nearest['distance'] >= 1000 ? round($nearest['distance'] / 1000, 1).' km' : $nearest['distance'].' m';
            throw ValidationException::withMessages(['location' => "You are about {$away} from {$nearest['office']->name}. You can {$action} within {$nearest['office']->radius_meters} m of an office."]);
        }

        return ['latitude' => round($latitude, 7), 'longitude' => round($longitude, 7), 'office_id' => $inside ? $nearest['office']->id : null, 'distance' => $nearest['distance'] ?? null];
    }

    /** Client timestamps may arrive in any offset (e.g. UTC "Z"); store them in the app timezone. */
    private function local(CarbonInterface $at): Carbon
    {
        return Carbon::instance($at)->setTimezone(config('app.timezone'));
    }

    private function lockedOpenSession(AttendanceEmployee $employee, ?CarbonInterface $at = null): AttendanceSession
    {
        AttendanceEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
        $session = $this->openSession($employee, $at, true);
        if ($session) return $session;
        $day = ($at ? $this->local($at) : now())->toDateString();
        if (AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', $day)->whereNotNull('check_out')->exists()) throw ValidationException::withMessages(['attendance' => 'You have already checked out today.']);

        throw ValidationException::withMessages(['attendance' => 'Check in before using break or check-out.']);
    }

    private function closeBreak(AttendanceSession $session, string $source, ?CarbonInterface $at = null): void
    {
        $start = $session->events()->where('event_type', 'break_start')->latest('event_time')->first();
        if (! $start) throw ValidationException::withMessages(['break' => 'No active break found.']);
        $now = $at ? $this->local($at) : now();
        if ($now->lt($start->event_time)) throw ValidationException::withMessages(['break' => 'A break cannot end before it started.']);
        $seconds = (int) $start->event_time->diffInSeconds($now);
        $this->event($session, 'break_end', $now, $seconds, ['source' => $source]);
        $session->increment('break_seconds', $seconds);
        $session->update(['status' => 'working', 'last_sync_at' => $now]);
        $session->increment('server_version');
        $this->audit->record('attendance.break_ended', $session, ['duration_seconds' => $seconds]);
    }

    private function event(AttendanceSession $session, string $type, CarbonInterface $at, int $duration, array $metadata = []): void
    {
        AttendanceActivityEvent::firstOrCreate(
            ['attendance_session_id' => $session->id, 'event_type' => $type, 'event_time' => $at->toDateTimeString()],
            ['duration_seconds' => $duration, 'metadata' => $metadata ?: null]
        );
    }
}
