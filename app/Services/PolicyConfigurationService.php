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
    public function forEmployee(?AttendanceEmployee $employee, string $key, string $asOf, array $fallback = []): array
    {
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

        $teamIds = $employee->teams()->pluck('teams.id')->sort()->values()->all();
        $team = $teamIds ? TeamPolicyOverride::query()
            ->whereIn('team_id', $teamIds)
            ->where('rule_key', $key)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $asOf))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->orderBy('team_id')
            ->first()?->configuration ?? [] : [];

        return array_replace($global, $team, $override);
    }
}
