<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShift extends Model
{
    protected $fillable = ['employee_id', 'shift_id', 'starts_on', 'ends_on', 'assigned_by'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function shift(): BelongsTo { return $this->belongsTo(Shift::class); }
}
