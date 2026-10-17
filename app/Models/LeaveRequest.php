<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = ['employee_id', 'leave_type_id', 'starts_on', 'ends_on', 'duration', 'days', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note'];

    /** Fractional day weight for each leave duration (full day = one working day). */
    public const DURATION_DAYS = ['full' => 1.0, 'half' => 0.5, 'short' => 0.25];

    public const DURATION_LABELS = ['full' => 'Full day', 'half' => 'Half day', 'short' => 'Short leave'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'days' => 'decimal:2', 'decided_at' => 'datetime'];
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    /** Days of this leave that HR settled because the employee also worked (Leave conflicts). */
    public function conflictResolutions(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(LeaveConflictResolution::class, 'leave_request_id'); }
}
