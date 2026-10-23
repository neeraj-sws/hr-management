<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    protected $fillable = [
        'employee_id',
        'device_id',
        'work_date',
        'check_in',
        'check_out',
        'active_seconds',
        'idle_seconds',
        'break_seconds',
        'status',
        'last_sync_at',
        'server_version',
        'client_payload_hash',
        'check_in_latitude',
        'check_in_longitude',
        'check_in_office_id',
        'check_in_distance_meters',
        'check_out_latitude',
        'check_out_longitude',
        'check_out_office_id',
        'check_out_distance_meters',
        'screen_active_seconds',
        'screen_idle_seconds',
        'screen_meeting_seconds',
        'screen_reported_at',
        'closed_automatically',
        'forced_checkout_by',
        'forced_checkout_reason',
        'forced_checkout_note',
        'forced_checkout_at',
        'check_in_photo_path',
        'check_in_ip',
        'check_out_ip',
        'check_out_photo_path',
    ];

    /** Reasons an administrator must pick when checking someone out (Team Attendance). */
    public const FORCED_CHECKOUT_REASONS = [
        'forgot' => 'Employee forgot to check out',
        'system_issue' => 'System issue',
        'unwell' => 'Employee unwell',
        'other' => 'Other',
    ];

    protected $casts = [
        'work_date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'last_sync_at' => 'datetime',
        'screen_reported_at' => 'datetime',
        'closed_automatically' => 'boolean',
        'forced_checkout_at' => 'datetime',
        'server_version' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AttendanceEmployee::class, 'employee_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function checkInOffice(): BelongsTo { return $this->belongsTo(OfficeLocation::class, 'check_in_office_id'); }
    public function checkOutOffice(): BelongsTo { return $this->belongsTo(OfficeLocation::class, 'check_out_office_id'); }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceActivityEvent::class);
    }

    public function forcedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forced_checkout_by');
    }

    public function wasForcedOut(): bool
    {
        return $this->forced_checkout_at !== null;
    }

    /** Readable reason for an administrator check-out ("Other: <explanation>"). */
    public function forcedReasonLabel(): ?string
    {
        if (! $this->forced_checkout_reason) return null;
        $label = self::FORCED_CHECKOUT_REASONS[$this->forced_checkout_reason] ?? $this->forced_checkout_reason;

        return $this->forced_checkout_note ? $label.': '.$this->forced_checkout_note : $label;
    }

    /**
     * Working time the desktop app did not see but an approved attendance correction confirmed — e.g. the
     * app stopped for 2 hours by a glitch, the employee asked for a correction and it was approved. It is
     * the corrected in–out time less break, minus everything the app measured (active, meetings, idle),
     * and is shown in the screen time as "added by approved correction". 0 without an approved correction.
     */
    public function correctedScreenSeconds(): int
    {
        if (! $this->check_in) return 0;
        $raw = (int) $this->screen_active_seconds + (int) $this->screen_meeting_seconds + (int) $this->screen_idle_seconds;
        if ($raw <= 0) return 0; // no desktop app that day: nothing to fill in
        // Away time answered as a break is in break_seconds already, so it is not measured screen time.
        $measured = $raw - min((int) $this->screen_idle_seconds, $this->answeredAwaySeconds()['break']);
        $corrected = $this->relationLoaded('events') ? $this->events->contains('event_type', 'correction') : $this->events()->where('event_type', 'correction')->exists();
        if (! $corrected) return 0;
        $span = (int) $this->check_in->diffInSeconds($this->check_out ?? now()) - (int) $this->break_seconds;

        return max(0, $span - $measured);
    }

    /**
     * Idle time for the day, taken off the working time. The desktop app reports
     * untouched-screen time with every heartbeat (screen_idle_seconds); older
     * device agents report idle_seconds at sync. Whichever source is tracking
     * counts, so the value keeps updating during the day. Away periods the
     * employee already answered for are not idle again: "it was a break" is in
     * the break time, "I was working" (e.g. a meeting) is working time.
     */
    public function idleSeconds(): int
    {
        return max((int) $this->idle_seconds, $this->screenTime()['idle']);
    }

    /**
     * The day's screen time as shown everywhere (admin list, employee page, reports, desktop and
     * mobile app), so every place shows the same figures. The desktop app reports untouched time
     * as idle; when the employee then answers the away question:
     *  - "I was working" (a meeting, a call, away from the desk for work) moves it to meetings,
     *  - "count it as a break" moves it out of screen time (it is in the break time).
     * Only what is left is idle. 'untracked' is working time the desktop app did not see (it was
     * closed, being updated, or opened after check-in): in-out less break, less everything above.
     * So active + meeting + idle + corrected + untracked = in-out time less break.
     *
     * @return array{active: int, meeting: int, idle: int, corrected: int, untracked: int}
     */
    public function screenTime(): array
    {
        $idle = (int) $this->screen_idle_seconds;
        $answered = $idle > 0 ? $this->answeredAwaySeconds() : ['work' => 0, 'break' => 0];
        $asWork = min($idle, $answered['work']);
        $asBreak = min($idle - $asWork, $answered['break']);

        $time = [
            'active' => (int) $this->screen_active_seconds,
            'meeting' => (int) $this->screen_meeting_seconds + $asWork,
            'idle' => $idle - $asWork - $asBreak,
            'corrected' => $this->correctedScreenSeconds(),
            'untracked' => 0,
        ];
        $measured = $time['active'] + $time['meeting'] + $time['idle'];
        if ($this->check_in && $measured > 0) {
            // Up to check-out, or (still working) to the app's last report, so it does not grow between reports.
            $end = $this->check_out ?? $this->screen_reported_at ?? now();
            $span = (int) $this->check_in->diffInSeconds($end) - (int) $this->break_seconds;
            $time['untracked'] = max(0, $span - $measured - $time['corrected']);
        }

        return $time;
    }

    /** Away periods the employee answered for: as working time, and as a break (seconds). */
    private function answeredAwaySeconds(): array
    {
        $events = $this->relationLoaded('events') ? $this->events : $this->events()->whereIn('event_type', ['break_end', 'away_worked'])->get();

        return [
            'work' => (int) $events->where('event_type', 'away_worked')->sum('duration_seconds'),
            'break' => (int) $events->filter(fn ($e) => $e->event_type === 'break_end' && in_array($e->metadata['reason'] ?? null, ['idle', 'locked'], true))->sum('duration_seconds'),
        ];
    }

    /** Actual working time of a finished day: in–out less break and idle. */
    public function actualSeconds(): ?int
    {
        if (! $this->check_in || ! $this->check_out) return null;

        return (int) max($this->check_in->diffInSeconds($this->check_out) - (int) $this->break_seconds - $this->idleSeconds(), 0);
    }
}
