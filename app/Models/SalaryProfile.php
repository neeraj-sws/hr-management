<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryProfile extends Model
{
    protected $fillable = ['employee_id', 'monthly_salary', 'effective_from', 'configured_by', 'working_days_per_month', 'standard_daily_hours', 'components'];
    protected $casts = ['monthly_salary' => 'decimal:2', 'effective_from' => 'date', 'working_days_per_month' => 'integer', 'standard_daily_hours' => 'decimal:2', 'components' => 'array'];
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
}
