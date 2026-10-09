<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\EmployeeShift;
use App\Models\Shift;
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

        return DB::transaction(function () use ($employeeIds, $shift, $startsOn, $endsOn, $actor) {
            $count = 0;
            foreach (AttendanceEmployee::whereIn('id', $employeeIds)->where('is_active', true)->get() as $employee) {
                EmployeeShift::where('employee_id', $employee->id)->whereNull('ends_on')->whereDate('starts_on', '<', $startsOn)
                    ->update(['ends_on' => now()->parse($startsOn)->subDay()->toDateString()]);
                EmployeeShift::where('employee_id', $employee->id)->whereDate('starts_on', $startsOn)->delete();
                EmployeeShift::create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'assigned_by' => $actor]);
                $count++;
            }
            $this->audit->record('shift.assigned', $shift, ['employees' => $count, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);
            $this->cache = [];

            return $count;
        });
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
