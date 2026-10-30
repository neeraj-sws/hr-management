<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "I will come late": sent before the day starts with the expected check-in time and a valid reason. Approved, and
 * the person checks in by that time: no late fine and no late-hours cut that day. Checking in later than the time
 * given, or not approved: the normal late rules.
 */
class LateArrivalRequest extends Model
{
    protected $fillable = ['employee_id', 'work_date', 'expected_at', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['work_date' => 'date', 'decided_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }

    /** An approved request covers this check-in (on or before the expected time). */
    public static function excuses(int $employeeId, string $date, ?string $checkInHm): bool
    {
        if (! $checkInHm) return false;

        return static::where('employee_id', $employeeId)->whereDate('work_date', $date)->where('status', 'approved')->where('expected_at', '>=', $checkInHm)->exists();
    }

    public function expectedLabel(): string
    {
        return \Illuminate\Support\Carbon::parse($this->expected_at)->format('h:i A');
    }
}
