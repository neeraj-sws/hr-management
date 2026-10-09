<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeDocument extends Model
{
    protected $fillable = ['employee_id','uploaded_by','category','original_name','storage_path','expires_on'];
    protected $casts = ['expires_on'=>'date'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
    public function uploader(){return $this->belongsTo(User::class,'uploaded_by');}
}
