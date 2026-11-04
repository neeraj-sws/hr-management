<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BillingInvoiceItem extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['quantity' => 'decimal:2', 'price' => 'decimal:2', 'discount' => 'decimal:2'];
    public function invoice(): BelongsTo { return $this->belongsTo(BillingInvoice::class, 'invoice_id'); }
    public function tax(): BelongsTo { return $this->belongsTo(BillingTax::class, 'tax_id'); }
    /** Line amount = qty*price - discount, plus its tax. */
    public function lineSubtotal(): float { return (float) $this->quantity * (float) $this->price - (float) $this->discount; }
    public function lineTax(): float { return $this->tax ? round($this->lineSubtotal() * (float) $this->tax->rate / 100, 2) : 0.0; }
    public function lineTotal(): float { return round($this->lineSubtotal() + $this->lineTax(), 2); }
}
