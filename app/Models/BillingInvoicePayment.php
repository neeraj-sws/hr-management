<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BillingInvoicePayment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];
    public function invoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(BillingInvoice::class, 'invoice_id'); }
    public function recorder(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
