<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'audience', 'department_id', 'team_id', 'excluded_department_ids', 'excluded_team_ids', 'is_pinned', 'published_at', 'expires_on', 'created_by'];
    protected $casts = ['is_pinned' => 'boolean', 'published_at' => 'datetime', 'expires_on' => 'date', 'excluded_department_ids' => 'array', 'excluded_team_ids' => 'array'];

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /** Published, not expired, addressed to everyone or to the employee's department/team, and not leaving them out. */
    public function scopeVisibleTo(Builder $query, ?AttendanceEmployee $employee): Builder
    {
        $teamIds = $employee ? $employee->teams()->pluck('teams.id')->all() : [];
        $left = Announcement::query()->whereNotNull('excluded_department_ids')->orWhereNotNull('excluded_team_ids')->get(['id', 'excluded_department_ids', 'excluded_team_ids'])
            ->filter(fn (Announcement $a) => $a->leavesOut($employee?->department_id, $teamIds))->pluck('id')->all();

        return $query->when($left, fn ($q) => $q->whereNotIn('id', $left))->where('published_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()))
            ->where(fn ($q) => $q->where('audience', 'all')
                ->orWhere(fn ($q) => $q->where('audience', 'department')->where('department_id', $employee?->department_id ?? 0))
                ->orWhere(fn ($q) => $q->where('audience', 'team')->whereIn('team_id', $teamIds ?: [0])));
    }

    /** True when the person's department or one of their teams is left out of this announcement. */
    public function leavesOut(?int $departmentId, array $teamIds): bool
    {
        return ($departmentId && in_array($departmentId, array_map('intval', (array) $this->excluded_department_ids), true))
            || array_intersect(array_map('intval', $teamIds), array_map('intval', (array) $this->excluded_team_ids)) !== [];
    }

    public function audienceLabel(): string
    {
        $label = match ($this->audience) {
            'department' => 'Department: '.($this->department?->name ?? '—'),
            'team' => 'Team: '.($this->team?->name ?? '—'),
            default => 'Everyone',
        };
        $out = collect(Department::whereIn('id', (array) $this->excluded_department_ids)->pluck('name'))->merge(Team::whereIn('id', (array) $this->excluded_team_ids)->pluck('name'));

        return $out->isEmpty() ? $label : $label.' except '.$out->join(', ');
    }
}
