<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeFinalSettlementPayment extends Model
{
    use Concerns\BelongsToEmployeeCompany;

    /** A login limited to one company sees this only when its EmployeeFinalSettlement is of that company. */
    public function companyOwner(): array { return ['final_settlement_id', EmployeeFinalSettlement::class]; }

    protected $fillable = ['final_settlement_id', 'amount', 'paid_on', 'method', 'reference', 'recorded_by'];
    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];
    public function settlement() { return $this->belongsTo(EmployeeFinalSettlement::class, 'final_settlement_id'); }
}
