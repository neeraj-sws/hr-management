<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    protected $fillable = ['employee_id', 'work_date', 'requested_check_in', 'requested_check_out', 'reason', 'status', 'attendance_session_id', 'decided_by', 'decided_at', 'decision_note', 'requested_break_minutes'];
    protected $casts = ['work_date' => 'date', 'requested_check_in' => 'datetime', 'requested_check_out' => 'datetime', 'decided_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function session(): BelongsTo { return $this->belongsTo(AttendanceSession::class, 'attendance_session_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
