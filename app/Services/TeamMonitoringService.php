<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\MonitoringRequest;
use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Team monitoring (Monitoring > Team monitoring): live work status, the day's timeline and screenshots for
 * the people a manager leads (their reports and their reports' reports), or everyone for HR / Admin.
 * Follows the Monitoring policy: screenshots only while checked in and working, deleted after the retention
 * period, every view logged, and the employee is told before capture is turned on.
 */
class TeamMonitoringService
{
    public function __construct(
        private readonly LiveStatusService $live,
        private readonly ScreenCaptureService $captures,
        private readonly PolicyConfigurationService $policies,
        private readonly AttendanceService $attendance,
        private readonly AuditLogger $audit,
    ) {}

    public function canUse(?User $user): bool
    {
        return (bool) $user?->canAny(['monitoring.team', 'monitoring.view']);
    }

    /** HR / Admin: everyone (with Department and Manager filters). */
    public function isOrgWide(User $user): bool
    {
        return $user->can('monitoring.view');
    }

    /** Turn capture on / off: HR and Admin only; managers can only ask. */
    public function canToggle(User $user): bool
    {
        return $user->canAny(['monitoring.capture', 'monitoring.manage']);
    }

    /** @return Collection<int, int>|null null = everyone (HR / Admin); otherwise the manager's reports and their reports. */
    public function scopeIds(User $user): ?Collection
    {
        abort_unless($this->canUse($user), 403);
        if ($this->isOrgWide($user)) return null;
        $own = $user->employeeProfile;
        if (! $own) return collect();
        $ids = collect();
        $level = collect([$own->id]);
        for ($depth = 0; $depth < 6 && $level->isNotEmpty(); $depth++) {
            $level = AttendanceEmployee::whereIn('manager_id', $level->all())->whereNotIn('id', $ids->push($own->id)->all())->pluck('id');
            $ids = $ids->merge($level);
        }

        return $ids->reject(fn ($id) => $id === $own->id)->unique()->values();
    }

    /** 403 unless the person is in the viewer's scope — the same check for pages, API and URL changes. */
    public function assertCanSee(User $user, AttendanceEmployee $employee): void
    {
        $scope = $this->scopeIds($user);
        abort_unless($scope === null || $scope->contains($employee->id), 403);
    }

    /** Capture for one person: on/off, since when, why, until when, who turned it on. */
    public function captureState(AttendanceEmployee $employee): array
    {
        $p = $this->policies->forEmployee($employee, 'monitoring.screenshots', today()->toDateString(), []);

        return ['on' => (bool) ($p['enabled'] ?? false), 'since' => $p['since'] ?? null, 'reason' => $p['reason'] ?? null, 'until' => $p['until'] ?? null, 'by' => $p['by_name'] ?? null];
    }

    /**
     * Turns capture on (reason required, optional end date) or off for one person. The employee is told in
     * the app and by e-mail first; the change is audit-logged with the reason.
     */
    public function setCapture(AttendanceEmployee $employee, bool $on, ?string $reason, ?string $until, User $actor): void
    {
        abort_unless($this->canToggle($actor), 403, 'Only HR or an administrator can change screen capture.');
        $reason = trim((string) $reason);
        if ($on && mb_strlen($reason) < 5) throw ValidationException::withMessages(['captureReason' => 'Write why screen capture is turned on (at least 5 characters).']);
        if ($on && $until && Carbon::parse($until)->lt(today())) throw ValidationException::withMessages(['captureUntil' => 'The end date cannot be in the past.']);
        $employee->loadMissing('user');

        $value = $on ? ['enabled' => true, 'reason' => $reason, 'until' => $until ?: null, 'since' => today()->toDateString(), 'by_name' => $actor->name]
            : ['enabled' => false, 'reason' => $reason ?: null, 'since' => today()->toDateString(), 'by_name' => $actor->name];
        if ($on && $employee->user) {
            // Told again even if they were told before: a fresh notice (in the app and by e-mail) goes out now, before any capture.
            $employee->user->forceFill(['screen_capture_notified_terms' => null])->save();
        }
        app(PeopleOperationsService::class)->setPolicy('monitoring.screenshots', 'monitoring', 'Screen capture', $value, $employee->id, $actor->id, today()->toDateString());
        $this->audit->record($on ? 'screen_capture.enabled_for_employee' : 'screen_capture.disabled_for_employee', $employee, ['by' => $actor->id, 'reason' => $reason ?: null, 'until' => $until ?: null]);

        if ($on) {
            $this->captures->notifyIfNeeded($employee->fresh('user'));
        } else {
            Notifier::send($employee->user, 'ScreenCapture', $employee->id, 'Screen capture is now off for you'.($reason ? " ({$reason})" : '').'. No more screenshots are taken.', route('monitoring.policy', [], false));
        }
    }

    /** Capture switched on with an end date: off again after that day (scheduled daily). */
    public function expireCaptures(): int
    {
        $ended = 0;
        AttendanceEmployee::with('user')->where('is_active', true)->get()->each(function (AttendanceEmployee $e) use (&$ended) {
            $state = $this->captureState($e);
            if (! $state['on'] || ! $state['until'] || Carbon::parse($state['until'])->gte(today())) return;
            $system = (int) (User::role('Master Admin')->orderBy('id')->value('id') ?? User::orderBy('id')->value('id'));
            app(PeopleOperationsService::class)->setPolicy('monitoring.screenshots', 'monitoring', 'Screen capture', ['enabled' => false, 'reason' => 'End date reached', 'since' => today()->toDateString(), 'by_name' => 'System'], $e->id, $system, today()->toDateString());
            $this->audit->record('screen_capture.disabled_for_employee', $e, ['reason' => 'End date reached', 'until' => $state['until']]);
            Notifier::send($e->user, 'ScreenCapture', $e->id, 'Screen capture has ended for you (end date reached). No more screenshots are taken.', route('monitoring.policy', [], false));
            $ended++;
        });

        return $ended;
    }

    /** A manager asks HR to turn capture on: an approval in Approvals. */
    public function requestCapture(AttendanceEmployee $employee, string $reason, ?string $until, User $manager): MonitoringRequest
    {
        $this->assertCanSee($manager, $employee);
        if (mb_strlen(trim($reason)) < 5) throw ValidationException::withMessages(['captureReason' => 'Write why screen capture is needed (at least 5 characters).']);
        if (MonitoringRequest::where('employee_id', $employee->id)->where('status', 'pending')->exists()) throw ValidationException::withMessages(['captureReason' => 'A request for this person is already waiting for HR.']);
        $request = MonitoringRequest::create(['employee_id' => $employee->id, 'requested_by' => $manager->id, 'reason' => trim($reason), 'until' => $until ?: null, 'status' => 'pending']);
        ApprovalRequest::create(['approvable_type' => MonitoringRequest::class, 'approvable_id' => $request->id, 'approver_id' => null, 'status' => 'pending']);
        $this->audit->record('screen_capture.requested', $employee, ['request_id' => $request->id, 'reason' => $request->reason, 'until' => $until]);
        Notifier::send(Notifier::withPermission('monitoring.capture'), 'MonitoringRequest', $request->id, "A screen capture request from {$manager->name} for {$employee->name} needs review. Reason: {$request->reason}", route('approvals.pending', [], false), $manager->id);

        return $request;
    }

    /** HR decides a request (from Approvals): approving turns capture on with the manager's reason. */
    public function decideRequest(MonitoringRequest $request, User $actor, bool $approve, ?string $note): void
    {
        abort_unless($this->canToggle($actor), 403);
        if ($request->status !== 'pending') throw ValidationException::withMessages(['approval' => 'This request has already been decided.']);
        $request->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
        ApprovalRequest::where('approvable_type', MonitoringRequest::class)->where('approvable_id', $request->id)->where('status', 'pending')
            ->update(['status' => $approve ? 'approved' : 'rejected', 'approver_id' => $actor->id, 'decided_at' => now(), 'comment' => $note]);
        if ($approve) $this->setCapture($request->employee, true, $request->reason.' (requested by '.($request->requester?->name ?? 'manager').')', $request->until?->toDateString(), $actor);
        Notifier::send($request->requester, 'MonitoringRequest', $request->id, ($approve ? 'Approved: ' : 'Rejected: ').'screen capture for '.$request->employee?->name.($note ? " — {$note}" : '.'), route('monitoring.team', [], false), $actor->id);
    }

    /**
     * Figures for a list of people on a day: live status, check-in, worked / break / idle / meeting, screen use,
     * the day bar and screenshot counts.
     *
     * @param  Collection<int, AttendanceEmployee>  $employees
     */
    public function rows(Collection $employees, string $date): array
    {
        $isToday = $date === today()->toDateString();
        $live = $this->live->forEmployees($employees->pluck('id')->all(), $date);
        $shots = Screenshot::whereIn('employee_id', $employees->pluck('id')->all() ?: [0])->whereDate('captured_at', $date)
            ->selectRaw('employee_id, COUNT(*) as n')->groupBy('employee_id')->pluck('n', 'employee_id');
        $events = \App\Models\AttendanceActivityEvent::whereIn('attendance_session_id', collect($live)->flatMap(fn ($l) => $l['sessions']->pluck('id'))->all() ?: [0])
            ->orderBy('event_time')->get()->groupBy('attendance_session_id');

        return $employees->mapWithKeys(function (AttendanceEmployee $e) use ($live, $shots, $events, $isToday, $date) {
            $l = $live[$e->id];
            $status = $isToday ? $l['status'] : ($l['session'] ? 'checked_out' : 'not_in');
            $day = $this->dayFigures($l['sessions'], $isToday);

            return [$e->id => [
                'employee' => $e, 'status' => $status, 'status_label' => LiveStatusService::STATUSES[$status][0], 'status_color' => LiveStatusService::STATUSES[$status][1],
                'check_in' => $l['sessions']->first()?->check_in, 'check_out' => $l['sessions']->every(fn ($s) => $s->check_out) ? $l['sessions']->last()?->check_out : null,
                ...$day,
                'timeline' => $this->timeline($l['sessions'], $events, $date),
                'capture' => $this->captureState($e)['on'],
                'screenshots' => (int) ($shots[$e->id] ?? 0),
            ]];
        })->all();
    }

    /** Totals for the day across its sessions (live sessions counted up to now). */
    private function dayFigures(Collection $sessions, bool $isToday): array
    {
        $sum = ['worked' => 0, 'break' => 0, 'idle' => 0, 'meeting' => 0, 'active' => 0, 'untracked' => 0];
        foreach ($sessions as $session) {
            $f = rescue(fn () => $this->attendance->liveFigures($session), null, false);
            if (! $f) continue;
            $sum['worked'] += (int) $f['worked'];
            $sum['break'] += (int) $f['break'];
            $sum['idle'] += (int) $f['idle'];
            $sum['meeting'] += (int) ($f['screen']['meeting'] ?? 0);
            $sum['active'] += (int) ($f['screen']['active'] ?? 0);
            $sum['untracked'] += (int) ($f['screen']['untracked'] ?? 0);
        }

        return $sum;
    }

    /**
     * The day as coloured parts on one bar: working (green), break (yellow), idle (orange), meeting (blue), off (grey).
     * The bar runs from 08:00 (or the first check-in) to 20:00 (or the last check-out / now).
     *
     * @return array{start: string, end: string, parts: list<array{type: string, left: float, width: float, from: string, to: string}>}
     */
    public function timeline(Collection $sessions, Collection $eventsBySession, string $date): array
    {
        $dayStart = Carbon::parse($date.' 08:00');
        $dayEnd = Carbon::parse($date.' 20:00');
        $now = now();
        foreach ($sessions as $s) {
            if ($s->check_in && $s->check_in->lt($dayStart)) $dayStart = $s->check_in->copy()->startOfHour();
            $out = $s->check_out ?? ($date === today()->toDateString() ? $now : $s->check_in);
            if ($out && $out->gt($dayEnd)) $dayEnd = $out->copy()->addHour()->startOfHour();
        }
        $span = max(1, $dayStart->diffInSeconds($dayEnd));
        $parts = [];
        $add = function (string $type, Carbon $from, Carbon $to) use (&$parts, $dayStart, $span) {
            if ($to->lte($from)) return;
            $parts[] = ['type' => $type, 'left' => round($dayStart->diffInSeconds($from) * 100 / $span, 3), 'width' => round($from->diffInSeconds($to) * 100 / $span, 3), 'from' => $from->format('h:i A'), 'to' => $to->format('h:i A')];
        };
        foreach ($sessions as $s) {
            if (! $s->check_in) continue;
            $end = $s->check_out ?? ($date === today()->toDateString() ? $now->copy() : $s->check_in->copy());
            // Walk the session's events: breaks and screen states cut the working time into parts.
            $cursor = $s->check_in->copy();
            $state = 'working';
            foreach ($eventsBySession->get($s->id, collect()) as $event) {
                $at = Carbon::parse($event->event_time);
                if ($at->lt($cursor) || $at->gt($end)) continue;
                $next = match ($event->event_type) {
                    'break_start' => 'break',
                    'break_end' => 'working',
                    'screen_state' => $state === 'break' ? 'break' : ['idle' => 'idle', 'meeting' => 'meeting'][$event->metadata['state'] ?? ''] ?? 'working',
                    default => null,
                };
                if ($next === null || $next === $state) continue;
                $add($state, $cursor, $at);
                [$cursor, $state] = [$at->copy(), $next];
            }
            $add($state, $cursor, $end);
        }

        return ['start' => $dayStart->format('h A'), 'end' => $dayEnd->format('h A'), 'parts' => $parts];
    }

    /** Screenshots of the day the viewer may see: within the retention period, grouped by hour. */
    public function screenshots(AttendanceEmployee $employee, string $date): Collection
    {
        $retention = $this->captures->policy($employee)['retention_days'];
        if (Carbon::parse($date)->lt(today()->subDays($retention))) return collect();

        return Screenshot::where('employee_id', $employee->id)->whereDate('captured_at', $date)
            ->where('captured_at', '>=', now()->subDays($retention))->orderBy('captured_at')->get()
            ->groupBy(fn (Screenshot $s) => $s->captured_at->format('H').':00');
    }

    /** Worked vs idle for the 7 days ending on $date. */
    public function week(AttendanceEmployee $employee, string $date): array
    {
        $end = Carbon::parse($date);
        $sessions = AttendanceSession::where('employee_id', $employee->id)->whereBetween('work_date', [$end->copy()->subDays(6)->toDateString(), $end->toDateString()])->get()->groupBy(fn ($s) => $s->work_date->toDateString());
        $days = [];
        for ($d = $end->copy()->subDays(6); $d->lte($end); $d->addDay()) {
            $f = $this->dayFigures($sessions->get($d->toDateString(), collect()), $d->isToday());
            $days[] = ['date' => $d->toDateString(), 'label' => $d->format('D d'), 'worked' => $f['worked'], 'idle' => $f['idle']];
        }

        return $days;
    }

    /** Logs a look at someone's monitoring data (every view is logged). */
    public function logView(User $viewer, AttendanceEmployee $employee, string $what, array $extra = []): void
    {
        $this->audit->record('monitoring.'.$what, $employee, ['employee_id' => $employee->id, 'viewer' => $viewer->name, ...$extra]);
    }

    /** Who looked at this person's screenshots / monitoring data, newest first (for their own policy page). */
    public function viewsOf(AttendanceEmployee $employee, int $limit = 20): Collection
    {
        $shotIds = Screenshot::where('employee_id', $employee->id)->pluck('id');

        return AuditLog::with('actor')->where(fn ($q) => $q
            ->where(fn ($q) => $q->whereIn('action', ['screenshot.viewed', 'screenshot.downloaded'])->where('subject_type', Screenshot::class)->whereIn('subject_id', $shotIds->all() ?: [0]))
            ->orWhere(fn ($q) => $q->where('action', 'like', 'monitoring.%')->where('subject_type', AttendanceEmployee::class)->where('subject_id', $employee->id)))
            ->where('actor_id', '!=', $employee->user_id ?? 0)
            ->latest('id')->limit($limit)->get();
    }
}
