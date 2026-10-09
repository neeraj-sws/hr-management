<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'audience', 'department_id', 'team_id', 'is_pinned', 'published_at', 'expires_on', 'created_by'];
    protected $casts = ['is_pinned' => 'boolean', 'published_at' => 'datetime', 'expires_on' => 'date'];

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /** Published, not expired, and addressed to everyone or to the employee's department/team. */
    public function scopeVisibleTo(Builder $query, ?AttendanceEmployee $employee): Builder
    {
        $teamIds = $employee ? $employee->teams()->pluck('teams.id')->all() : [];

        return $query->where('published_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()))
            ->where(fn ($q) => $q->where('audience', 'all')
                ->orWhere(fn ($q) => $q->where('audience', 'department')->where('department_id', $employee?->department_id ?? 0))
                ->orWhere(fn ($q) => $q->where('audience', 'team')->whereIn('team_id', $teamIds ?: [0])));
    }

    public function audienceLabel(): string
    {
        return match ($this->audience) {
            'department' => 'Department: '.($this->department?->name ?? '—'),
            'team' => 'Team: '.($this->team?->name ?? '—'),
            default => 'Everyone',
        };
    }
}
