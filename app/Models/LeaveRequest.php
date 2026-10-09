<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = ['employee_id', 'leave_type_id', 'starts_on', 'ends_on', 'days', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'days' => 'decimal:2', 'decided_at' => 'datetime'];
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
