<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeHoliday extends Model
{
    protected $fillable = ['employee_id', 'date', 'name', 'source', 'holiday_rule_id'];

    protected $casts = ['date' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AttendanceEmployee::class, 'employee_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(HolidayRule::class, 'holiday_rule_id');
    }
}
