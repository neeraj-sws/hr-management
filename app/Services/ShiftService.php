<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\EmployeeShift;
use App\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shift definitions and the effective-dated roster (which shift an employee works on a date). */
class ShiftService
{
    /** @var array<string, ?Shift> per-request cache keyed by "employee|date" */
    private array $cache = [];

    public function __construct(private readonly AuditLogger $audit) {}

    public function shiftFor(AttendanceEmployee $employee, string $date): ?Shift
    {
        $key = $employee->id.'|'.$date;
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = EmployeeShift::with('shift')->where('employee_id', $employee->id)
                ->whereDate('starts_on', '<=', $date)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
                ->orderByDesc('starts_on')->orderByDesc('id')->first()?->shift;
        }

        return $this->cache[$key];
    }

    /**
     * Assigns a shift to employees from $startsOn (optionally until $endsOn).
     * Open-ended assignments that would overlap are closed the day before.
     *
     * @param  array<int>  $employeeIds
     */
    public function assign(array $employeeIds, Shift $shift, string $startsOn, ?string $endsOn, int $actor): int
    {
        if ($endsOn && $endsOn < $startsOn) throw ValidationException::withMessages(['endsOn' => 'The end date must be on or after the start date.']);
        if (! $shift->is_active) throw ValidationException::withMessages(['shiftId' => 'This shift is inactive.']);

        $assigned = DB::transaction(function () use ($employeeIds, $shift, $startsOn, $endsOn, $actor) {
            $people = collect();
            foreach (AttendanceEmployee::with(['user', 'manager.user'])->whereIn('id', $employeeIds)->where('is_active', true)->get() as $employee) {
                $this->clearRange($employee->id, $startsOn, $endsOn, $actor);
                EmployeeShift::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'assigned_by' => $actor]);
                $this->mergeAdjacent($employee->id);
                $people->push($employee);
            }
            $this->audit->record('shift.assigned', $shift, ['employees' => $people->count(), 'employee_ids' => $people->pluck('id')->all(), 'starts_on' => $startsOn, 'ends_on' => $endsOn]);
            $this->cache = [];

            return $people;
        });

        // The employee (and their manager) hear about the new shift and its dates.
        $range = Carbon::parse($startsOn)->format('D d M Y').($endsOn ? ' to '.Carbon::parse($endsOn)->format('D d M Y') : ' onwards');
        $by = \App\Models\User::find($actor)?->name ?? 'HR';
        foreach ($assigned as $employee) {
            Notifier::send($employee->user, 'Shift', $shift->id, "{$by} assigned you the {$shift->name} shift ({$shift->hours()}) from {$range}.", route('attendance.my', [], false), $actor);
            Notifier::send($employee->manager?->user, 'Shift', $shift->id, "{$employee->name} is on the {$shift->name} shift ({$shift->hours()}) from {$range}.", route('attendance.shifts', ['week' => Carbon::parse($startsOn)->startOfWeek()->toDateString()], false), $actor);
        }

        return $assigned->count();
    }

    /** An employee's assignments in date order: [id, shift_id, starts_on, ends_on (null = onwards)]. */
    public function assignmentsOf(AttendanceEmployee $employee): Collection
    {
        return EmployeeShift::with('shift')->where('employee_id', $employee->id)->orderBy('starts_on')->orderBy('id')->get();
    }

    /**
     * Saves an employee's whole shift timeline as edited on the roster ("Edit shift dates"): each row is
     * [shift_id, starts_on, ends_on|null]. Rows may not overlap and only the last may be open-ended.
     * The employee and their manager are told the new timeline.
     *
     * @param  array<int, array{shift_id: int|string, starts_on: string, ends_on: ?string}>  $rows
     */
    public function replaceAssignments(AttendanceEmployee $employee, array $rows, int $actor): void
    {
        $rows = collect($rows)->map(fn ($r) => ['shift_id' => (int) $r['shift_id'], 'starts_on' => (string) $r['starts_on'], 'ends_on' => ($r['ends_on'] ?? '') !== '' ? (string) $r['ends_on'] : null])
            ->sortBy('starts_on')->values();
        foreach ($rows as $i => $row) {
            $n = $i + 1;
            if (! Shift::whereKey($row['shift_id'])->exists()) throw ValidationException::withMessages(['shiftRows' => "Row {$n}: choose a shift."]);
            if ($row['ends_on'] !== null && $row['ends_on'] < $row['starts_on']) throw ValidationException::withMessages(['shiftRows' => "Row {$n}: \"Until\" is before \"From\"."]);
            $next = $rows[$i + 1] ?? null;
            if ($next && ($row['ends_on'] === null || $row['ends_on'] >= $next['starts_on'])) {
                throw ValidationException::withMessages(['shiftRows' => 'The dates of '.Carbon::parse($row['starts_on'])->format('d M Y').' and '.Carbon::parse($next['starts_on'])->format('d M Y').' overlap — end the first one before the next one starts.']);
            }
        }

        DB::transaction(function () use ($employee, $rows, $actor) {
            EmployeeShift::where('employee_id', $employee->id)->delete();
            foreach ($rows as $row) EmployeeShift::create(['employee_id' => $employee->id, ...$row, 'assigned_by' => $actor]);
            $this->mergeAdjacent($employee->id);
            $this->audit->record('shift.timeline_updated', $employee, ['rows' => $rows->all()]);
            $this->cache = [];
        });

        $names = Shift::whereIn('id', $rows->pluck('shift_id'))->pluck('name', 'id');
        $summary = $rows->map(fn ($r) => ($names[$r['shift_id']] ?? 'Shift').' '.Carbon::parse($r['starts_on'])->format('d M').($r['ends_on'] ? '–'.Carbon::parse($r['ends_on'])->format('d M Y') : ' onwards'))->join('; ');
        $by = \App\Models\User::find($actor)?->name ?? 'HR';
        $employee->loadMissing('user', 'manager.user');
        Notifier::send($employee->user, 'Shift', $employee->id, "{$by} updated your shifts: ".($summary ?: 'no shift assigned').'.', route('attendance.my', [], false), $actor);
        Notifier::send($employee->manager?->user, 'Shift', $employee->id, "{$by} updated {$employee->name}'s shifts: ".($summary ?: 'no shift assigned').'.', route('attendance.shifts', [], false), $actor);
    }

    /** Joins back-to-back assignments of the same shift (e.g. Night 15–28 Oct + Night 29 Oct onwards) into one. */
    private function mergeAdjacent(int $employeeId): void
    {
        $previous = null;
        foreach (EmployeeShift::where('employee_id', $employeeId)->orderBy('starts_on')->orderBy('id')->get() as $current) {
            if ($previous && $previous->shift_id === $current->shift_id && $previous->ends_on
                && $previous->ends_on->copy()->addDay()->toDateString() === $current->starts_on->toDateString()) {
                $previous->update(['ends_on' => $current->ends_on]);
                $current->delete();
                continue;
            }
            $previous = $current;
        }
    }

    /**
     * Makes room for a new assignment on [$from, $until] (null = open-ended):
     * earlier assignments end the day before, later ones start the day after,
     * one spanning the whole range is split around it, and ones fully inside
     * are removed — so exactly one shift applies on every day.
     */
    private function clearRange(int $employeeId, string $from, ?string $until, int $actor): void
    {
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();
        $dayAfter = $until ? Carbon::parse($until)->addDay()->toDateString() : null;
        $overlapping = EmployeeShift::where('employee_id', $employeeId)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('starts_on', '<=', $until))
            ->get();
        foreach ($overlapping as $old) {
            $oldStart = $old->starts_on->toDateString();
            $oldEnd = $old->ends_on?->toDateString();
            $runsPast = $until !== null && ($oldEnd === null || $oldEnd > $until);
            if ($oldStart < $from) {
                if ($runsPast) EmployeeShift::create(['employee_id' => $employeeId, 'shift_id' => $old->shift_id, 'starts_on' => $dayAfter, 'ends_on' => $oldEnd, 'assigned_by' => $old->assigned_by ?? $actor]);
                $old->update(['ends_on' => $dayBefore]);
            } elseif ($runsPast) {
                $old->update(['starts_on' => $dayAfter]);
            } else {
                $old->delete();
            }
        }
    }

    /**
     * Roster grid: employee id => [date => ?Shift] for the given dates.
     *
     * @param  Collection<int, AttendanceEmployee>  $employees
     * @param  array<int, string>  $dates
     */
    public function roster(Collection $employees, array $dates): array
    {
        $assignments = EmployeeShift::with('shift')->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('starts_on', '<=', end($dates))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', reset($dates)))
            ->orderBy('starts_on')->orderBy('id')->get()->groupBy('employee_id');

        $grid = [];
        foreach ($employees as $employee) {
            foreach ($dates as $date) {
                $grid[$employee->id][$date] = $assignments->get($employee->id, collect())
                    ->filter(fn (EmployeeShift $a) => $a->starts_on->toDateString() <= $date && (! $a->ends_on || $a->ends_on->toDateString() >= $date))
                    ->last()?->shift;
            }
        }

        return $grid;
    }
}
