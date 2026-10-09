<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PolicyRule extends Model
{
    protected $fillable = ['rule_key','category','name','configuration','is_active','effective_from','effective_until','updated_by'];
    protected $casts = ['configuration'=>'array','is_active'=>'boolean','effective_from'=>'date','effective_until'=>'date'];
}
