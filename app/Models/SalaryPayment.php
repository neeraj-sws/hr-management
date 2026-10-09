<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryPayment extends Model
{
    protected $fillable = ['payroll_item_id', 'amount', 'paid_on', 'reference', 'recorded_by'];
    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];
    public function payrollItem(): BelongsTo { return $this->belongsTo(PayrollItem::class); }
}
