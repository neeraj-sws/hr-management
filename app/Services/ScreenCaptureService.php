<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\EmployeeManagerHistory;
use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Optional screen capture from the desktop app (off unless HR turns it on).
 * Rules enforced here, not only in the app: an administrator turned it on for the employee (or everyone),
 * was checked in and working (not on break, not checked out), inside the
 * work window, no faster than the interval. Files are private; only the
 * employee, their current manager and people with monitoring.view can open
 * them, every view/download is audited, and old captures are deleted.
 */
class ScreenCaptureService
{
    public const INTERVALS = [5, 10, 15, 30];
    public const DEFAULT_PURPOSE = 'To confirm working time and to support work reviews.';
    public const VIEWERS = 'Your current manager, and HR/administrators with the monitoring permission. You do not see your own screenshots. Every view and download is logged.';
    public const NEVER_RECORDED = 'Keystrokes, passwords, webcam, microphone and clipboard are never recorded.';
    public const IMAGE_RULES = ['required', 'file', 'max:4096', 'mimes:jpg,jpeg,png'];

    public function __construct(private readonly PolicyConfigurationService $policies, private readonly AttendanceService $attendance, private readonly AuditLogger $audit) {}

    /** @return array{enabled: bool, interval_minutes: int, active_only: bool, window_start: ?string, window_end: ?string, retention_days: int, purpose: string} */
    public function policy(?AttendanceEmployee $employee): array
    {
        $p = $this->policies->forEmployee($employee, 'monitoring.screenshots', today()->toDateString(), []);

        return [
            'enabled' => (bool) ($p['enabled'] ?? false),
            'interval_minutes' => in_array((int) ($p['interval_minutes'] ?? 10), self::INTERVALS, true) ? (int) ($p['interval_minutes'] ?? 10) : 10,
            'active_only' => (bool) ($p['active_only'] ?? true),
            'window_start' => $p['window_start'] ?? null,
            'window_end' => $p['window_end'] ?? null,
            'retention_days' => max(1, min(365, (int) ($p['retention_days'] ?? 30))),
            'purpose' => trim((string) ($p['purpose'] ?? '')) ?: self::DEFAULT_PURPOSE,
        ];
    }

    /** A fingerprint of what the employee is told; when it changes they are told (and asked) again. */
    public function terms(array $policy): string
    {
        return hash('sha256', json_encode([$policy['interval_minutes'], $policy['active_only'], $policy['window_start'], $policy['window_end'], $policy['retention_days'], $policy['purpose']]));
    }

    /** The employee has acknowledged the capture terms that apply to them now. */
    public function isAcknowledged(?User $user, array $policy): bool
    {
        return $user !== null && $user->screen_capture_ack_terms === $this->terms($policy);
    }

    /** "Up to one screenshot every 10 minutes, while you are checked in and working, 09:00–18:00…" */
    public function describe(array $policy): array
    {
        $window = $policy['window_start'] && $policy['window_end'] ? " between {$policy['window_start']} and {$policy['window_end']}" : '';

        return [
            'frequency' => "A screenshot of your screen may be taken every {$policy['interval_minutes']} minutes{$window}.",
            'when' => 'Only while you are checked in and working — never before check-in, during a break or after check-out'.($policy['active_only'] ? ', and not while the computer is idle or locked.' : '.'),
            'purpose' => $policy['purpose'],
            'viewers' => self::VIEWERS,
            'retention' => "Screenshots are deleted automatically after {$policy['retention_days']} days.",
            'never' => self::NEVER_RECORDED,
        ];
    }

    /**
     * Tells the employee (in-app and by e-mail) that screen capture is on for
     * them and what it means — once per version of the terms: when it is
     * switched on, when they join while it is on, or when the terms change.
     */
    public function notifyIfNeeded(AttendanceEmployee $employee): bool
    {
        $user = $employee->user;
        if (! $user || ! ($user->is_active ?? true) || ! $employee->is_active) return false;
        $policy = $this->policy($employee);
        if (! $policy['enabled']) return false;
        $terms = $this->terms($policy);
        if ($user->screen_capture_notified_terms === $terms) return false;
        $user->forceFill(['screen_capture_notified_terms' => $terms])->save();
        rescue(fn () => $user->notify(new \App\Notifications\ScreenCaptureNotice($employee->id, $this->describe($policy))), null, true);
        $this->audit->record('screen_capture.notice_sent', $employee, ['interval_minutes' => $policy['interval_minutes']]);

        return true;
    }

    /** Everyone with capture on who has not been told about the current terms (after a policy change, and hourly). */
    public function notifyAll(): int
    {
        $sent = 0;
        AttendanceEmployee::with('user')->where('is_active', true)->whereNotNull('user_id')->orderBy('id')->chunkById(200, function ($employees) use (&$sent) {
            foreach ($employees as $employee) if ($this->notifyIfNeeded($employee)) $sent++;
        });

        return $sent;
    }

    /**
     * Turns capture on/off for one employee (admin only, monitoring.manage).
     * Interval, work window and retention stay as set company-wide in Settings.
     */
    public function setForEmployee(AttendanceEmployee $employee, bool $enabled, User $actor): void
    {
        abort_unless($actor->can('monitoring.manage'), 403, 'Only an administrator can change screen capture.');
        app(PeopleOperationsService::class)->setPolicy('monitoring.screenshots', 'monitoring', 'Screen capture', ['enabled' => $enabled], $employee->id, $actor->id, today()->toDateString());
        $this->audit->record($enabled ? 'screen_capture.enabled_for_employee' : 'screen_capture.disabled_for_employee', $employee, ['by' => $actor->id]);
        if ($enabled) $this->notifyIfNeeded($employee->fresh('user'));
    }

    /** "I have read this": records which version of the terms the person acknowledged. */
    public function acknowledge(User $user): void
    {
        $terms = $this->terms($this->policy($user->employeeProfile));
        if ($user->screen_capture_ack_terms === $terms) return;
        $user->forceFill(['screen_capture_acknowledged_at' => now(), 'screen_capture_ack_terms' => $terms])->save();
        $this->audit->record('screen_capture.acknowledged', $user);
    }

    public function store(AttendanceEmployee $employee, UploadedFile $image, Carbon $capturedAt): Screenshot
    {
        $policy = $this->policy($employee);
        $reject = fn (string $message) => throw ValidationException::withMessages(['screenshot' => $message]);
        if (! $policy['enabled']) $reject('Screen capture is turned off.');
        $capturedAt = $capturedAt->copy()->setTimezone(config('app.timezone'));
        if ($capturedAt->gt(now()->addMinute()) || $capturedAt->lt(now()->subMinutes(15))) $reject('Captures must be uploaded within 15 minutes.');
        $session = $this->attendance->openSession($employee, $capturedAt);
        if (! $session || $session->check_in->gt($capturedAt)) $reject('Not checked in at that time.');
        if ($session->status !== 'working') $reject('No capture during a break.');
        if ($policy['window_start'] && $policy['window_end']) {
            $time = $capturedAt->format('H:i');
            $inside = $policy['window_start'] <= $policy['window_end'] ? ($time >= $policy['window_start'] && $time <= $policy['window_end']) : ($time >= $policy['window_start'] || $time <= $policy['window_end']);
            if (! $inside) $reject('Outside the capture window.');
        }
        $previous = Screenshot::where('employee_id', $employee->id)->latest('captured_at')->value('captured_at');
        if ($previous && Carbon::parse($previous)->diffInSeconds($capturedAt, false) < ($policy['interval_minutes'] * 60) - 60) $reject('Too soon after the previous capture.');

        $path = $image->storeAs('screenshots/'.$employee->id.'/'.$capturedAt->format('Y-m-d'), Str::uuid().'.'.($image->extension() === 'png' ? 'png' : 'jpg'), 'local');

        return Screenshot::create(['employee_id' => $employee->id, 'attendance_session_id' => $session->id, 'captured_at' => $capturedAt, 'path' => $path, 'bytes' => (int) $image->getSize()]);
    }

    /** The employee, their current manager (for captures since they became manager) or monitoring.view holders. */
    public function canView(User $user, Screenshot $shot): bool
    {
        $employee = $shot->employee;
        if (! $employee) return false;
        if ($employee->user_id === $user->id) return false; // never your own screenshots
        if ($user->can('monitoring.view')) return true;
        $manager = $employee->manager;
        if ((! $manager || $manager->user_id !== $user->id) && $user->can('monitoring.team')) {
            // Team monitoring: the manager's reports' reports too (never outside their tree).
            return (bool) app(TeamMonitoringService::class)->scopeIds($user)?->contains($employee->id);
        }
        if (! $manager || $manager->user_id !== $user->id) return false;
        $since = EmployeeManagerHistory::where('employee_id', $employee->id)->where('manager_id', $manager->id)->whereNull('ends_on')->value('starts_on');

        return ! $since || $shot->captured_at->toDateString() >= Carbon::parse($since)->toDateString();
    }

    public function isExpired(Screenshot $shot): bool
    {
        return $shot->captured_at->lt(now()->subDays($this->policy($shot->employee)['retention_days']));
    }

    /** Deletes captures older than each employee's retention period (scheduled daily). */
    public function purgeExpired(): int
    {
        $deleted = 0;
        Screenshot::with('employee')->where('captured_at', '<', now()->subDay())->orderBy('id')->chunkById(500, function ($shots) use (&$deleted) {
            foreach ($shots as $shot) {
                if (! $this->isExpired($shot)) continue;
                Storage::disk('local')->delete($shot->path);
                $shot->delete();
                $deleted++;
            }
        });
        if ($deleted) $this->audit->record('screen_capture.purged', null, ['deleted' => $deleted]);

        return $deleted;
    }
}
