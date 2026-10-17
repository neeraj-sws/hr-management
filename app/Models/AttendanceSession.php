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
     * Idle time for the day, taken off the working time. The desktop app reports
     * untouched-screen time with every heartbeat (screen_idle_seconds); older
     * device agents report idle_seconds at sync. Whichever source is tracking
     * counts, so the value keeps updating during the day. Away periods the
     * employee already answered for are not idle again: "it was a break" is in
     * the break time, "I was working" (e.g. a meeting) is working time.
     */
    public function idleSeconds(): int
    {
        $screenIdle = (int) $this->screen_idle_seconds;
        if ($screenIdle > 0) {
            $events = $this->relationLoaded('events') ? $this->events : $this->events()->whereIn('event_type', ['break_end', 'away_worked'])->get();
            $answered = $events->filter(fn ($e) => $e->event_type === 'away_worked' || ($e->event_type === 'break_end' && in_array($e->metadata['reason'] ?? null, ['idle', 'locked'], true)))->sum('duration_seconds');
            $screenIdle = max(0, $screenIdle - (int) $answered);
        }

        return max((int) $this->idle_seconds, $screenIdle);
    }

    /** Actual working time of a finished day: in–out less break and idle. */
    public function actualSeconds(): ?int
    {
        if (! $this->check_in || ! $this->check_out) return null;

        return (int) max($this->check_in->diffInSeconds($this->check_out) - (int) $this->break_seconds - $this->idleSeconds(), 0);
    }
}
