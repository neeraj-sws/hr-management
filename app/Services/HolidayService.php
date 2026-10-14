<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\CompOffCredit;
use App\Models\EmployeeHoliday;
use App\Models\HolidayOverride;
use App\Models\HolidayRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves holidays per employee from scoped, effective-dated rules
 * (company -> department -> team -> employee, most specific wins), applies
 * manual adjustments (shift / cancel / add), materialises them into
 * employee_holidays for attendance and payroll, and credits comp-off when an
 * employee works on their holiday. Single company for now.
 */
class HolidayService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The holiday, if any, that applies to this employee on this date — after
     * rule priority and manual overrides. Null means it is a normal working day.
     *
     * @return array{name: string, source: string, rule_id: ?int}|null
     */
    public function resolve(AttendanceEmployee $employee, Carbon $date, ?Collection $rules = null, ?Collection $overrides = null): ?array
    {
        $rules ??= $this->rulesFor($employee);
        $overrides ??= $this->overridesFor($employee);
        $ymd = $date->toDateString();

        // A manual adjustment is the most direct instruction and wins over rules.
        $override = $this->mostSpecific($overrides->filter(fn (HolidayOverride $o) => $o->original_date->toDateString() === $ymd || ($o->action === 'shift' && $o->new_date?->toDateString() === $ymd)));
        if ($override) {
            if ($override->action === 'add') return ['name' => $override->name ?: 'Holiday', 'source' => 'override', 'rule_id' => null];
            if ($override->action === 'shift' && $override->new_date?->toDateString() === $ymd) return ['name' => $override->name ?: 'Holiday (shifted)', 'source' => 'override', 'rule_id' => null];
            // cancel, or the source side of a shift: this date is now a working day.
            if ($override->original_date->toDateString() === $ymd) return null;
        }

        // Fixed-date holidays (e.g. 26 Jan) apply regardless of weekday.
        $fixed = $rules->first(fn (HolidayRule $r) => $r->type === 'fixed_date' && (int) $r->month === (int) $date->month && (int) $r->day === (int) $date->day && $this->effective($r, $date));
        if ($fixed) return ['name' => $fixed->name, 'source' => 'rule', 'rule_id' => $fixed->id];

        // Weekday rules: the most specific scope that governs this weekday decides,
        // so an employee can have a different Saturday pattern than the company.
        $weekday = $date->isoWeekday();
        $weekdayRules = $rules->filter(fn (HolidayRule $r) => in_array($r->type, ['weekly', 'nth_weekday'], true) && (int) $r->weekday === $weekday && $this->effective($r, $date));
        if ($weekdayRules->isEmpty()) return null;
        $topPriority = $weekdayRules->max(fn (HolidayRule $r) => $r->priority());
        $governing = $weekdayRules->filter(fn (HolidayRule $r) => $r->priority() === $topPriority);
        $match = $governing->first(fn (HolidayRule $r) => $this->weekdayOccurrenceMatches($r, $date));

        return $match ? ['name' => $match->name, 'source' => 'rule', 'rule_id' => $match->id] : null;
    }

    public function isHolidayForEmployee(AttendanceEmployee $employee, Carbon $date): bool
    {
        return $this->resolve($employee, $date) !== null;
    }

    /**
     * Materialise holidays for a month into employee_holidays for the given
     * employees (default: all active). Idempotent — safe to re-run; it reflects
     * rule and override changes, adding and removing rows as needed.
     *
     * @return int holidays written
     */
    public function generateMonth(int $year, int $month, ?Collection $employees = null): int
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $employees ??= AttendanceEmployee::where('is_active', true)->get();
        $written = 0;

        foreach ($employees as $employee) {
            $rules = $this->rulesFor($employee);
            $overrides = $this->overridesFor($employee);
            $keep = [];
            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                $resolved = $this->resolve($employee, $day, $rules, $overrides);
                if (! $resolved) continue;
                EmployeeHoliday::updateOrCreate(
                    ['employee_id' => $employee->id, 'date' => $day->toDateString()],
                    ['name' => $resolved['name'], 'source' => $resolved['source'], 'holiday_rule_id' => $resolved['rule_id']]
                );
                $keep[] = $day->toDateString();
                $written++;
            }
            // Drop holidays in this month that no longer apply (rule removed / cancelled).
            EmployeeHoliday::where('employee_id', $employee->id)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->whereNotIn('date', $keep ?: ['1970-01-01'])
                ->delete();
        }

        return $written;
    }

    /** Credits a comp-off day when an employee works on what is their holiday. Idempotent per date. */
    public function creditCompOff(AttendanceEmployee $employee, Carbon $date, float $days = 1.0, ?int $actorId = null): ?CompOffCredit
    {
        if (! $this->isHolidayForEmployee($employee, $date)) return null;
        $credit = CompOffCredit::firstOrCreate(
            ['employee_id' => $employee->id, 'earned_on' => $date->toDateString()],
            ['days' => $days, 'status' => 'available', 'expires_on' => $date->copy()->addDays(90)->toDateString(), 'note' => 'Worked on holiday', 'created_by' => $actorId]
        );
        if ($credit->wasRecentlyCreated) {
            $this->audit->record('holiday.comp_off_credited', $credit, ['date' => $date->toDateString(), 'days' => $days]);
        }

        return $credit;
    }

    /** Active rules whose scope applies to this employee. */
    public function rulesFor(AttendanceEmployee $employee): Collection
    {
        $teamIds = $employee->teams->pluck('id')->all();

        return HolidayRule::where('is_active', true)
            ->where(function ($q) use ($employee, $teamIds) {
                $q->where('scope_type', 'company')
                    ->orWhere(fn ($q) => $q->where('scope_type', 'department')->where('scope_id', $employee->department_id))
                    ->orWhere(fn ($q) => $q->where('scope_type', 'team')->whereIn('scope_id', $teamIds ?: [0]))
                    ->orWhere(fn ($q) => $q->where('scope_type', 'employee')->where('scope_id', $employee->id));
            })
            ->get();
    }

    /** Overrides whose scope applies to this employee. */
    public function overridesFor(AttendanceEmployee $employee): Collection
    {
        $teamIds = $employee->teams->pluck('id')->all();

        return HolidayOverride::where(function ($q) use ($employee, $teamIds) {
            $q->where('scope_type', 'company')
                ->orWhere(fn ($q) => $q->where('scope_type', 'department')->where('scope_id', $employee->department_id))
                ->orWhere(fn ($q) => $q->where('scope_type', 'team')->whereIn('scope_id', $teamIds ?: [0]))
                ->orWhere(fn ($q) => $q->where('scope_type', 'employee')->where('scope_id', $employee->id));
        })->get();
    }

    private function effective(HolidayRule $rule, Carbon $date): bool
    {
        if ($rule->effective_from && $date->lt($rule->effective_from)) return false;
        if ($rule->effective_until && $date->gt($rule->effective_until)) return false;

        return true;
    }

    private function weekdayOccurrenceMatches(HolidayRule $rule, Carbon $date): bool
    {
        if ($rule->type === 'weekly') return true; // weekday already matched
        $nth = (int) ceil($date->day / 7);
        $isLast = $date->copy()->addWeek()->month !== $date->month;
        $weeks = $rule->weeks ?? [];

        return in_array($nth, $weeks, true) || ($isLast && in_array(-1, $weeks, true));
    }

    /** @param Collection<int, HolidayOverride> $overrides */
    private function mostSpecific(Collection $overrides): ?HolidayOverride
    {
        return $overrides->sortByDesc(fn (HolidayOverride $o) => HolidayRule::SCOPE_PRIORITY[$o->scope_type] ?? 0)->first();
    }
}
