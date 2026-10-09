<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Team extends Model
{
    protected $fillable = ['name', 'description', 'department_id', 'manager_employee_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function members(): BelongsToMany { return $this->belongsToMany(AttendanceEmployee::class, 'employee_team', 'team_id', 'employee_id')->withTimestamps(); }
    public function manager(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'manager_employee_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
}
