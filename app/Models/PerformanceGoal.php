<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A KRA (ongoing key result area) or OKR (objective with a measurable key result) for a period. */
class PerformanceGoal extends Model
{
    public const KINDS = ['kra' => 'KRA', 'okr' => 'OKR'];

    protected $fillable = ['employee_id', 'kind', 'title', 'description', 'target', 'weight', 'progress', 'status', 'starts_on', 'ends_on', 'created_by', 'approved_by'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'weight' => 'integer', 'progress' => 'integer'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updates(): HasMany { return $this->hasMany(PerformanceGoalUpdate::class, 'goal_id')->latest('id'); }

    /** Goals that overlap a period (cancelled ones left out). */
    public function scopeForPeriod($query, string $from, string $to)
    {
        return $query->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->where('status', '!=', 'cancelled');
    }
}
