<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeClearanceItem extends Model
{
    protected $fillable = ['lifecycle_case_id','item','status','cleared_by','cleared_at','notes'];
    protected $casts = ['cleared_at'=>'datetime'];
    public function lifecycleCase(){return $this->belongsTo(EmployeeLifecycleCase::class,'lifecycle_case_id');}
}
