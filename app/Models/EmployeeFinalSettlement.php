<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeFinalSettlement extends Model
{
    protected $fillable = ['lifecycle_case_id','gross_amount','deductions','net_amount','status','prepared_by','approved_by','approved_at','as_of_date','calculation_breakdown'];
    protected $casts = ['gross_amount'=>'decimal:2','deductions'=>'decimal:2','net_amount'=>'decimal:2','approved_at'=>'datetime','as_of_date'=>'date','calculation_breakdown'=>'array'];
    public function lifecycleCase(){return $this->belongsTo(EmployeeLifecycleCase::class,'lifecycle_case_id');}
    public function payments(){return $this->hasMany(EmployeeFinalSettlementPayment::class,'final_settlement_id');}
}
