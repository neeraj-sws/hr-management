<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseClaim extends Model
{
    public const CATEGORIES = ['Travel', 'Food', 'Accommodation', 'Fuel', 'Internet & phone', 'Office supplies', 'Client meeting', 'Other'];

    protected $fillable = ['employee_id', 'category', 'spent_on', 'amount', 'description', 'receipt_path', 'receipt_name', 'status', 'decided_by', 'decided_at', 'decision_note', 'payroll_item_id'];
    protected $casts = ['spent_on' => 'date', 'amount' => 'decimal:2', 'decided_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    public function payrollItem(): BelongsTo { return $this->belongsTo(PayrollItem::class); }

    /** Approved and not yet in a payroll run → "approved"; paid through payroll → "paid". */
    public function displayStatus(): string
    {
        return $this->status === 'approved' && $this->payroll_item_id ? 'paid' : $this->status;
    }
}
