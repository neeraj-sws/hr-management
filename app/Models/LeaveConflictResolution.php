<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HR's decision for a day with both approved leave and attendance: "worked" (refund the leave day) or "leave". */
class LeaveConflictResolution extends Model
{
    protected $fillable = ['employee_id', 'leave_request_id', 'work_date', 'resolution', 'resolved_by', 'note'];
    protected $casts = ['work_date' => 'date'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function leaveRequest(): BelongsTo { return $this->belongsTo(LeaveRequest::class); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
}
