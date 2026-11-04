<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\EmployeePolicyOverride;
use App\Models\PolicyRule;
use App\Models\TeamPolicyOverride;

/**
 * Effective policy for an employee on a date: the company rule, then the
 * employee's team override (lowest team id if they are in several), then the
 * employee's own override — each layer only replaces the keys it sets.
 */
class PolicyConfigurationService
{
    /** Rows loaded once while remember() is on (null = off: every call asks the database). */
    private ?array $memo = null;

    /**
     * For pages that show many people and days at once (Attendance records): each rule, override and
     * team list is read once and the effective one is picked in memory for every date. Read-only use:
     * a policy saved later in the same request is not seen until forget().
     */
    public function remember(): static
    {
        $this->memo ??= [];

        return $this;
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    public function forEmployee(?AttendanceEmployee $employee, string $key, string $asOf, array $fallback = []): array
    {
        if ($this->memo !== null) return $this->fromMemo($employee, $key, \Illuminate\Support\Carbon::parse($asOf)->toDateString(), $fallback);

        $global = PolicyRule::query()
            ->where('rule_key', $key)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $asOf))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->orderByRaw('effective_from IS NULL')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()?->configuration ?? $fallback;

        if (! $employee) return $global;

        $override = EmployeePolicyOverride::query()
            ->where('employee_id', $employee->id)
            ->where('rule_key', $key)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $asOf))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->orderByRaw('effective_from IS NULL')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()?->configuration ?? [];

        // The teams the employee belonged to on that date; current teams when no history was recorded.
        $teamIds = \App\Models\EmployeeTeamHistory::where('employee_id', $employee->id)->exists()
            ? \App\Models\EmployeeTeamHistory::where('employee_id', $employee->id)->on(\Illuminate\Support\Carbon::parse($asOf)->toDateString())->pluck('team_id')->unique()->sort()->values()->all()
            : $employee->teams()->pluck('teams.id')->sort()->values()->all();
        $team = $teamIds ? TeamPolicyOverride::query()
            ->whereIn('team_id', $teamIds)
            ->where('rule_key', $key)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $asOf))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->orderBy('team_id')
            ->first()?->configuration ?? [] : [];

        return array_replace($global, $team, $override);
    }

    /** The same answer as forEmployee() from rows loaded once per key / employee. */
    private function fromMemo(?AttendanceEmployee $employee, string $key, string $asOf, array $fallback): array
    {
        $active = fn ($row) => (! $row->effective_from || $row->effective_from->toDateString() <= $asOf) && (! $row->effective_until || $row->effective_until->toDateString() >= $asOf);
        // Dated rows first (latest start first), then undated ones; newest id first within a tie.
        $latest = fn ($rows) => $rows->filter($active)->sort(fn ($a, $b) => [$a->effective_from === null, $b->effective_from?->toDateString(), $b->id] <=> [$b->effective_from === null, $a->effective_from?->toDateString(), $a->id])->first();

        $global = $latest($this->memo['global|'.$key] ??= PolicyRule::where('rule_key', $key)->where('is_active', true)->get())?->configuration ?? $fallback;
        if (! $employee) return $global;

        $override = $latest($this->memo['employee|'.$employee->id.'|'.$key] ??= EmployeePolicyOverride::where('employee_id', $employee->id)->where('rule_key', $key)->get())?->configuration ?? [];

        $history = $this->memo['history|'.$employee->id] ??= \App\Models\EmployeeTeamHistory::where('employee_id', $employee->id)->get(['team_id', 'starts_on', 'ends_on']);
        $teamIds = $history->isNotEmpty()
            ? $history->filter(fn ($h) => $h->starts_on && $h->starts_on->toDateString() <= $asOf && (! $h->ends_on || $h->ends_on->toDateString() >= $asOf))->pluck('team_id')->unique()->all()
            : ($this->memo['teams|'.$employee->id] ??= $employee->teams()->pluck('teams.id')->all());
        $team = $teamIds ? ($this->memo['team|'.$key] ??= TeamPolicyOverride::where('rule_key', $key)->orderBy('team_id')->orderBy('id')->get())
            ->filter(fn ($row) => in_array($row->team_id, $teamIds) && $active($row))->first()?->configuration ?? [] : [];

        return array_replace($global, $team, $override);
    }
}
