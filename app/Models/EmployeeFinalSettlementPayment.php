<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeFinalSettlementPayment extends Model
{
    protected $fillable = ['final_settlement_id', 'amount', 'paid_on', 'method', 'reference', 'recorded_by'];
    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];
    public function settlement() { return $this->belongsTo(EmployeeFinalSettlement::class, 'final_settlement_id'); }
}
