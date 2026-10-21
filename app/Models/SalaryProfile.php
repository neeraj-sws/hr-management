<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryProfile extends Model
{
    public const PAY_BASES = ['monthly' => 'Monthly salary', 'daily' => 'Per day (daily wage)'];

    protected $fillable = ['employee_id', 'monthly_salary', 'pay_basis', 'daily_rate', 'effective_from', 'configured_by', 'working_days_per_month', 'standard_daily_hours', 'components'];
    protected $casts = ['monthly_salary' => 'decimal:2', 'daily_rate' => 'decimal:2', 'effective_from' => 'date', 'working_days_per_month' => 'integer', 'standard_daily_hours' => 'decimal:2', 'components' => 'array'];

    /** Paid per day attended (daily_rate × days present) instead of a monthly salary. */
    public function isDaily(): bool
    {
        return $this->pay_basis === 'daily';
    }

    /** "₹130.00 / day" or "25,000.00 / month" for lists. */
    public function rateLabel(): string
    {
        return $this->isDaily() ? number_format((float) $this->daily_rate, 2).' / day' : number_format((float) $this->monthly_salary, 2).' / month';
    }
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
}
