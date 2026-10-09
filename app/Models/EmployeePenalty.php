<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeePenalty extends Model
{
    protected $fillable = ['employee_id','policy_rule_id','effective_on','amount','status','reason','requested_by','decided_by','decided_at','payroll_item_id','source_key'];
    protected $casts = ['effective_on'=>'date','amount'=>'decimal:2','decided_at'=>'datetime'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
    public function policyRule(){return $this->belongsTo(PolicyRule::class);}
    public function payrollItem(){return $this->belongsTo(PayrollItem::class);}
}
