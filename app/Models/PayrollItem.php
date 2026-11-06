<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollItem extends Model
{
    use Concerns\BelongsToEmployeeCompany;
    public $timestamps = false;
    protected $fillable = ['payroll_run_id', 'employee_id', 'gross_amount', 'adjustments', 'net_amount', 'calculation_data', 'working_days', 'present_days', 'paid_leave_days', 'unpaid_leave_days', 'overtime_hours', 'overtime_amount', 'penalties_amount', 'advances_amount', 'recovery_amount', 'adjustments_amount', 'statutory_earnings', 'statutory_deductions', 'calculation_breakdown', 'reimbursements_amount'];
    protected $casts = ['gross_amount' => 'decimal:2', 'adjustments' => 'decimal:2', 'net_amount' => 'decimal:2', 'calculation_data' => 'array', 'calculation_breakdown' => 'array', 'present_days' => 'decimal:2', 'paid_leave_days' => 'decimal:2', 'unpaid_leave_days' => 'decimal:2', 'overtime_hours' => 'decimal:2', 'overtime_amount' => 'decimal:2', 'penalties_amount' => 'decimal:2', 'advances_amount' => 'decimal:2', 'recovery_amount' => 'decimal:2', 'adjustments_amount' => 'decimal:2', 'reimbursements_amount' => 'decimal:2'];
    public function run(): BelongsTo { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function payments(): HasMany { return $this->hasMany(SalaryPayment::class); }
    public function payslip() { return $this->hasOne(Payslip::class); }
    /** Paid above the net salary (e.g. a minimum amount paid to everyone): shown as extra pay, never recovered from later salaries. */
    public function extraPay(): float { return round(max(0, (float) $this->payments->sum('amount') - (float) $this->net_amount), 2); }
}
