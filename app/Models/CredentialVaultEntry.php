<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CredentialVaultEntry extends Model
{
    protected $fillable = ['created_by','owner_employee_id','team_id','category','label','username','secret_value','url','visibility'];
    protected $hidden = ['secret_value'];
    protected $casts = ['secret_value'=>'encrypted'];
    public function creator(){return $this->belongsTo(User::class,'created_by');}
    public function owner(){return $this->belongsTo(AttendanceEmployee::class,'owner_employee_id');}
    public function team(){return $this->belongsTo(Team::class);}
}
