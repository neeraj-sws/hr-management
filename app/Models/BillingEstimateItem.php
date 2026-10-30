<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BillingEstimateItem extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['quantity' => 'decimal:2', 'price' => 'decimal:2', 'discount' => 'decimal:2'];
    public function tax(): BelongsTo { return $this->belongsTo(BillingTax::class, 'tax_id'); }
    public function lineSubtotal(): float { return (float) $this->quantity * (float) $this->price - (float) $this->discount; }
    public function lineTax(): float { return $this->tax ? round($this->lineSubtotal() * (float) $this->tax->rate / 100, 2) : 0.0; }
}
