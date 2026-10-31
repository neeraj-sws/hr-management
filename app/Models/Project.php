<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['name', 'client_name', 'client_id', 'hourly_rate', 'client_sees_developer', 'description', 'manager_id', 'starts_on', 'ends_on', 'status'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'hourly_rate' => 'decimal:2', 'client_sees_developer' => 'boolean'];
    public function tasks(): HasMany { return $this->hasMany(Task::class); }
    public function manager() { return $this->belongsTo(AttendanceEmployee::class, 'manager_id'); }
    public function members(): \Illuminate\Database\Eloquent\Relations\BelongsToMany { return $this->belongsToMany(AttendanceEmployee::class, 'project_members', 'project_id', 'employee_id')->withPivot('role')->withTimestamps(); }
    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Client::class); }
    public function timesheetEntries(): HasMany { return $this->hasMany(TimesheetEntry::class); }
}
