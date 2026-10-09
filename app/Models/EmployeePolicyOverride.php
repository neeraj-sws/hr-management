<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeePolicyOverride extends Model
{
    protected $fillable = ['employee_id','rule_key','configuration','effective_from','effective_until','configured_by'];
    protected $casts = ['configuration'=>'array','effective_from'=>'date','effective_until'=>'date'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
}
