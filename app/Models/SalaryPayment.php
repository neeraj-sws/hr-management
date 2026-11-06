<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryPayment extends Model
{
    use Concerns\BelongsToEmployeeCompany;

    /** A login limited to one company sees this only when its PayrollItem is of that company. */
    public function companyOwner(): array { return ['payroll_item_id', PayrollItem::class]; }

    public const METHODS = ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash', 'other' => 'Other'];

    protected $fillable = ['payroll_item_id', 'amount', 'paid_on', 'method', 'reference', 'recorded_by'];
    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];
    public function payrollItem(): BelongsTo { return $this->belongsTo(PayrollItem::class); }

    /** The part of this payment above the month's net salary (earlier payments count first): extra pay. */
    public function extraPart(): float
    {
        $item = $this->payrollItem;
        if (! $item) return 0.0;
        $before = (float) $item->payments->filter(fn ($p) => $p->id < $this->id)->sum('amount');

        return round(max(0, min((float) $this->amount, $before + (float) $this->amount - (float) $item->net_amount)), 2);
    }
}
