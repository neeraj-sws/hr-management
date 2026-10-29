<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = ['employee_id', 'leave_type_id', 'other_type', 'starts_on', 'ends_on', 'duration', 'days', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note'];

    /** Fractional day weight for each leave duration (full day = one working day). */
    public const DURATION_DAYS = ['full' => 1.0, 'half' => 0.5, 'short' => 0.25];

    public const DURATION_LABELS = ['full' => 'Full day', 'half' => 'Half day', 'short' => 'Short leave'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'days' => 'decimal:2', 'decided_at' => 'datetime'];
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }

    /** The type as people read it: the type's name, or "Others: Exam" when the employee wrote their own kind. */
    public function typeName(): ?string
    {
        $name = $this->leaveType?->name;
        if ($name !== null && $this->leaveType?->is_other && trim((string) $this->other_type) !== '') return $name.': '.trim((string) $this->other_type);

        return $name;
    }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    /** Days of this leave that HR settled because the employee also worked (Leave conflicts). */
    public function conflictResolutions(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(LeaveConflictResolution::class, 'leave_request_id'); }

    /** Asked for after its first day had already passed (an "old date" leave). */
    public function isBackdated(): bool
    {
        return $this->starts_on && $this->created_at && $this->starts_on->lt($this->created_at->copy()->startOfDay());
    }
}
