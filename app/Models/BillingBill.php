<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A purchase bill from a vendor: items, payments made and debit notes. */
class BillingBill extends Model
{
    public const STATUSES = ['Draft', 'Sent', 'Unpaid', 'Partially Paid', 'Paid'];

    protected $guarded = ['id'];
    protected $casts = ['bill_date' => 'date', 'due_date' => 'date'];

    public function vendor(): BelongsTo { return $this->belongsTo(BillingVendor::class, 'vendor_id'); }
    public function category(): BelongsTo { return $this->belongsTo(BillingCategory::class, 'category_id'); }
    public function items(): HasMany { return $this->hasMany(BillingBillItem::class, 'bill_id'); }
    public function payments(): HasMany { return $this->hasMany(BillingBillPayment::class, 'bill_id'); }
    public function debitNotes(): HasMany { return $this->hasMany(BillingDebitNote::class, 'bill_id'); }

    /** e.g. BILL00012 (prefix from Billing settings). */
    public function number(): string
    {
        return \App\Services\BillingService::code('bill', (int) $this->number);
    }

    public function subTotal(): float { return round($this->items->sum(fn ($i) => $i->lineSubtotal()), 2); }
    public function totalTax(): float { return round($this->items->sum(fn ($i) => $i->lineTax()), 2); }
    public function total(): float { return round($this->subTotal() + $this->totalTax(), 2); }
    public function paidAmount(): float { return round((float) $this->payments->sum('amount'), 2); }
    public function debitTotal(): float { return round((float) $this->debitNotes->sum('amount'), 2); }
    public function due(): float { return round($this->total() - $this->paidAmount() - $this->debitTotal(), 2); }

    public function refreshStatus(): void
    {
        if ($this->status === 'Draft') return;
        $this->status = $this->due() <= 0 ? 'Paid' : ($this->paidAmount() > 0 ? 'Partially Paid' : ($this->status === 'Sent' ? 'Sent' : 'Unpaid'));
        $this->save();
    }
}
