<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['name', 'code', 'manager_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function employees(): HasMany { return $this->hasMany(AttendanceEmployee::class); }
    public function teams(): HasMany { return $this->hasMany(Team::class); }
    public function head() { return $this->belongsTo(AttendanceEmployee::class, 'manager_id'); }
}
