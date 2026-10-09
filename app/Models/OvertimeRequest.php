<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OvertimeRequest extends Model
{
    protected $fillable = ['employee_id','work_date','requested_minutes','approved_minutes','reason','status','decision_note','decided_by','decided_at'];
    protected $casts = ['work_date'=>'date','decided_at'=>'datetime'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
    public function decider(){return $this->belongsTo(User::class,'decided_by');}
}
