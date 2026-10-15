<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A change to an employee's pay, approved before it applies.
 *  - increment: raises the monthly salary from the effective date (a new salary
 *    revision is created on approval, so the old salary stays in history);
 *  - bonus / earning: added to pay; performance_deduction / deduction: taken from pay.
 * A recurring adjustment applies in every payroll month from its effective month
 * through `recurring_until` (or until stopped); a one-off applies once.
 */
class SalaryAdjustment extends Model
{
    public const KINDS = [
        'increment' => 'Increment',
        'bonus' => 'Bonus',
        'performance_deduction' => 'Performance deduction',
        'earning' => 'Other earning',
        'deduction' => 'Other deduction',
    ];

    public const DEDUCTION_KINDS = ['performance_deduction', 'deduction'];

    protected $fillable = ['employee_id','effective_on','amount','kind','is_recurring','recurring_until','status','reason','requested_by','decided_by','decided_at','payroll_item_id'];
    protected $casts = ['effective_on'=>'date','recurring_until'=>'date','is_recurring'=>'boolean','amount'=>'decimal:2','decided_at'=>'datetime'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    public function isDeduction(): bool
    {
        return in_array($this->kind, self::DEDUCTION_KINDS, true);
    }

    public function isIncrement(): bool
    {
        return $this->kind === 'increment';
    }

    /** The signed amount this adjustment adds to pay for one payroll month (0 for an increment, which changes the salary itself). */
    public function signedAmount(): float
    {
        if ($this->isIncrement()) return 0.0;

        return $this->isDeduction() ? -(float) $this->amount : (float) $this->amount;
    }

    /** How many calendar months of [start, end] a recurring adjustment covers. */
    public function recurringMonthsIn(string $start, string $end): int
    {
        $from = Carbon::parse($start)->startOfMonth()->max($this->effective_on->copy()->startOfMonth());
        $to = Carbon::parse($end)->startOfMonth();
        if ($this->recurring_until) $to = $to->min($this->recurring_until->copy()->startOfMonth());
        if ($to->lt($from)) return 0;

        return (int) $from->diffInMonths($to) + 1;
    }
}
