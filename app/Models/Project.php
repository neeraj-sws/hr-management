<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['name', 'description', 'manager_id', 'starts_on', 'ends_on', 'status'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];
    public function tasks(): HasMany { return $this->hasMany(Task::class); }
    public function manager() { return $this->belongsTo(AttendanceEmployee::class, 'manager_id'); }
    public function members(): \Illuminate\Database\Eloquent\Relations\BelongsToMany { return $this->belongsToMany(AttendanceEmployee::class, 'project_members', 'project_id', 'employee_id')->withPivot('role')->withTimestamps(); }
}
