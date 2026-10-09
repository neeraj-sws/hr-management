<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $fillable = ['name', 'start_time', 'end_time', 'grace_minutes', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'grace_minutes' => 'integer'];

    public function assignments(): HasMany { return $this->hasMany(EmployeeShift::class); }

    /** "09:30–18:30" (night shifts show the next-day end, e.g. "21:00–06:00"). */
    public function hours(): string
    {
        return substr((string) $this->start_time, 0, 5).'–'.substr((string) $this->end_time, 0, 5);
    }

    public function crossesMidnight(): bool
    {
        return substr((string) $this->end_time, 0, 5) <= substr((string) $this->start_time, 0, 5);
    }
}
