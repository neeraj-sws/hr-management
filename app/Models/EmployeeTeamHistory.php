<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTeamHistory extends Model
{
    protected $table = 'employee_team_history';
    protected $fillable = ['employee_id', 'team_id', 'starts_on', 'ends_on', 'changed_by'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }

    /** Memberships that covered the given date. */
    public function scopeOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }
}
