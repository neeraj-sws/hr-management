<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeAsset extends Model
{
    protected $fillable = ['employee_id','asset_tag','name','status','assigned_on','returned_on','condition_notes','resolution_note','recovery_adjustment_id'];
    protected $casts = ['assigned_on'=>'date','returned_on'=>'date'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
}
