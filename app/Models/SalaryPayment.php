<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryPayment extends Model
{
    public const METHODS = ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash', 'other' => 'Other'];

    protected $fillable = ['payroll_item_id', 'amount', 'paid_on', 'method', 'reference', 'recorded_by'];
    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];
    public function payrollItem(): BelongsTo { return $this->belongsTo(PayrollItem::class); }
}
