<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SalaryAdjustment extends Model
{
    protected $fillable = ['employee_id','effective_on','amount','kind','status','reason','requested_by','decided_by','decided_at','payroll_item_id'];
    protected $casts = ['effective_on'=>'date','amount'=>'decimal:2','decided_at'=>'datetime'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
}
