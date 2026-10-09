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
    public const IMAGE_RULES = ['required', 'file', 'max:4096', 'mimes:jpg,jpeg,png'];

    public function __construct(private readonly PolicyConfigurationService $policies, private readonly AttendanceService $attendance, private readonly AuditLogger $audit) {}

    /** @return array{enabled: bool, interval_minutes: int, active_only: bool, window_start: ?string, window_end: ?string, retention_days: int} */
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
        ];
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
    }

    public function acknowledge(User $user): void
    {
        if (! $user->screen_capture_acknowledged_at) {
            $user->forceFill(['screen_capture_acknowledged_at' => now()])->save();
            $this->audit->record('screen_capture.acknowledged', $user);
        }
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
        if ($employee->user_id === $user->id || $user->can('monitoring.view')) return true;
        $manager = $employee->manager;
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
