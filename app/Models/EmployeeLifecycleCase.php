<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeLifecycleCase extends Model
{
    protected $fillable = ['employee_id','kind','status','effective_on','notes','opened_by','closed_by','closed_at'];
    protected $casts = ['effective_on'=>'date','closed_at'=>'datetime'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
    public function clearances(){return $this->hasMany(EmployeeClearanceItem::class,'lifecycle_case_id');}
    public function settlement(){return $this->hasOne(EmployeeFinalSettlement::class,'lifecycle_case_id');}
}
