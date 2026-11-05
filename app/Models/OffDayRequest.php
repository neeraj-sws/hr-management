<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "I am taking my off on this day": an employee whose week-of-month offs count only when asked for; HR approves it. */
class OffDayRequest extends Model
{
    use Concerns\BelongsToEmployeeCompany;
    protected $fillable = ['employee_id', 'off_date', 'note', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['off_date' => 'date', 'decided_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
