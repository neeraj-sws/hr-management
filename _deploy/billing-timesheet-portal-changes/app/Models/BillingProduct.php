<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BillingProduct extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['sale_price' => 'decimal:2', 'purchase_price' => 'decimal:2', 'is_active' => 'boolean'];
    public function tax(): BelongsTo { return $this->belongsTo(BillingTax::class, 'tax_id'); }
}
