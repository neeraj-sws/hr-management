<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SalaryAdvance extends Model
{
    protected $fillable = ['employee_id','amount','recovered_amount','installment_amount','disbursed_amount','disbursed_on','requested_on','status','reason','requested_by','decided_by','decided_at'];
    protected $casts = ['amount'=>'decimal:2','recovered_amount'=>'decimal:2','installment_amount'=>'decimal:2','disbursed_amount'=>'decimal:2','disbursed_on'=>'date','requested_on'=>'date','decided_at'=>'datetime'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
    public function repayments(){return $this->hasMany(SalaryAdvanceRepayment::class);}
}
