<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An employee's reason for a late-arrival fine. Accepted: the fine is removed (or refunded in the next payroll). */
class PenaltyAppeal extends Model
{
    protected $fillable = ['employee_penalty_id', 'employee_id', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note', 'refund_adjustment_id'];

    protected $casts = ['decided_at' => 'datetime'];

    public function penalty()
    {
        return $this->belongsTo(EmployeePenalty::class, 'employee_penalty_id');
    }

    public function employee()
    {
        return $this->belongsTo(AttendanceEmployee::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
