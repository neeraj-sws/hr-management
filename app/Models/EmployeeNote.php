<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeNote extends Model
{
    protected $fillable = ['author_id','employee_id','team_id','visibility','title','body'];
    public function author(){return $this->belongsTo(User::class,'author_id');}
    public function employee(){return $this->belongsTo(AttendanceEmployee::class,'employee_id');}
    public function team(){return $this->belongsTo(Team::class,'team_id');}
}
