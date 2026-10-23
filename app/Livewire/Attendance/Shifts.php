<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Models\Shift;
use App\Services\AuditLogger;
use App\Services\ShiftService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class Shifts extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Url] public string $week = '';
    #[Url] public string $department = '';

    public bool $showShiftForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $startTime = '09:30';
    public string $endTime = '18:30';
    public string $graceMinutes = '';
    public bool $isActive = true;

    public bool $showAssign = false;
    public string $shiftId = '';
    public array $employeeIds = [];
    public string $startsOn = '';
    public string $endsOn = '';

    /** "Edit shift dates" for one employee: their whole timeline, row = [shift_id, starts_on, ends_on]. */
    public ?int $timelineEmployeeId = null;
    public array $shiftRows = [];

    public function mount(): void
    {
        $this->week = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) ? Carbon::parse($this->week)->startOfWeek()->toDateString() : today()->startOfWeek()->toDateString();
    }

    private function authorizeShifts(): void
    {
        abort_unless($this->user()->canAny(['shifts.manage', 'hr.manage']), 403);
    }

    public function moveWeek(int $direction): void
    {
        $this->week = Carbon::parse($this->week)->addWeeks($direction)->toDateString();
    }

    public function editShift(?int $id = null): void
    {
        $this->authorizeShifts();
        $shift = $id ? Shift::findOrFail($id) : null;
        $this->fill([
            'editingId' => $shift?->id, 'name' => $shift?->name ?? '',
            'startTime' => $shift ? substr($shift->start_time, 0, 5) : '09:30', 'endTime' => $shift ? substr($shift->end_time, 0, 5) : '18:30',
            'graceMinutes' => $shift?->grace_minutes !== null ? (string) $shift?->grace_minutes : '', 'isActive' => $shift?->is_active ?? true,
        ]);
        $this->resetValidation();
        $this->showShiftForm = true;
    }

    public function saveShift(AuditLogger $audit): void
    {
        $this->authorizeShifts();
        $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('shifts', 'name')->ignore($this->editingId)],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'different:startTime'],
            'graceMinutes' => ['nullable', 'integer', 'min:0', 'max:240'],
        ], ['endTime.different' => 'The end time must differ from the start time.'], ['startTime' => 'start time', 'endTime' => 'end time', 'graceMinutes' => 'grace minutes']);
        $shift = Shift::updateOrCreate(['id' => $this->editingId], ['name' => $this->name, 'start_time' => $this->startTime, 'end_time' => $this->endTime, 'grace_minutes' => $this->graceMinutes === '' ? null : (int) $this->graceMinutes, 'is_active' => $this->isActive]);
        $audit->record($this->editingId ? 'shift.updated' : 'shift.created', $shift);
        $this->showShiftForm = false;
        $this->toast('Shift saved.');
    }

    /** $date: the roster day that was clicked — the assignment starts there (not on Monday of the week). */
    public function openAssign(?int $shiftId = null, ?int $employeeId = null, ?string $date = null): void
    {
        $this->authorizeShifts();
        $from = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : max($this->week, today()->toDateString());
        $current = $employeeId ? app(ShiftService::class)->shiftFor(AttendanceEmployee::findOrFail($employeeId), $from) : null;
        $this->fill(['shiftId' => (string) ($shiftId ?? $current?->id ?? Shift::where('is_active', true)->value('id') ?? ''), 'employeeIds' => $employeeId ? [(string) $employeeId] : [], 'startsOn' => $from,
            // A roster cell changes that one day; widen "Until" for a longer period (blank = until changed).
            'endsOn' => $date && $employeeId ? $from : '']);
        $this->resetValidation();
        $this->showAssign = true;
    }

    public function assign(ShiftService $shifts): void
    {
        $this->authorizeShifts();
        $this->validate([
            'shiftId' => ['required', 'exists:shifts,id'],
            'employeeIds' => ['required', 'array', 'min:1'], 'employeeIds.*' => ['integer', 'exists:attendance_employees,id'],
            'startsOn' => ['required', 'date'], 'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
        ], ['employeeIds.required' => 'Select at least one employee.'], ['shiftId' => 'shift', 'startsOn' => 'start date', 'endsOn' => 'end date']);
        $count = $shifts->assign(array_map('intval', $this->employeeIds), Shift::findOrFail($this->shiftId), $this->startsOn, $this->endsOn ?: null, auth()->id());
        $this->showAssign = false;
        $this->toast("Shift assigned to {$count} ".str('employee')->plural($count).' from '.Carbon::parse($this->startsOn)->format('d M').($this->endsOn ? ' to '.Carbon::parse($this->endsOn)->format('d M') : '').'. They were notified.');
    }

    public function openTimeline(int $employeeId, ShiftService $shifts): void
    {
        $this->authorizeShifts();
        $employee = AttendanceEmployee::findOrFail($employeeId);
        $this->timelineEmployeeId = $employee->id;
        $this->shiftRows = $shifts->assignmentsOf($employee)->map(fn ($a) => ['shift_id' => (string) $a->shift_id, 'starts_on' => $a->starts_on->toDateString(), 'ends_on' => (string) $a->ends_on?->toDateString()])->all();
        $this->resetValidation();
    }

    public function addShiftRow(): void
    {
        $last = end($this->shiftRows) ?: null;
        $from = $last && $last['ends_on'] !== '' ? Carbon::parse($last['ends_on'])->addDay()->toDateString() : today()->toDateString();
        $this->shiftRows[] = ['shift_id' => (string) (Shift::where('is_active', true)->value('id') ?? ''), 'starts_on' => $from, 'ends_on' => ''];
    }

    public function removeShiftRow(int $index): void
    {
        unset($this->shiftRows[$index]);
        $this->shiftRows = array_values($this->shiftRows);
    }

    public function saveTimeline(ShiftService $shifts): void
    {
        $this->authorizeShifts();
        $this->validate(['shiftRows.*.shift_id' => ['required', 'exists:shifts,id'], 'shiftRows.*.starts_on' => ['required', 'date'], 'shiftRows.*.ends_on' => ['nullable', 'date']],
            ['shiftRows.*.shift_id.required' => 'Choose a shift on every row.', 'shiftRows.*.starts_on.required' => 'Every row needs a "From" date.']);
        $employee = AttendanceEmployee::findOrFail($this->timelineEmployeeId);
        $shifts->replaceAssignments($employee, $this->shiftRows, auth()->id());
        $this->timelineEmployeeId = null;
        $this->toast("{$employee->name}'s shift dates saved. They were notified.");
    }

    /** What each person actually worked on the roster days: "employee|date" => [in, out, worked seconds, required seconds]. */
    private function actuals(array $employeeIds, array $dates): array
    {
        $attendance = app(\App\Services\AttendanceService::class);
        $out = [];
        $people = AttendanceEmployee::whereIn('id', $employeeIds)->get()->keyBy('id');
        foreach (\App\Models\AttendanceSession::whereIn('employee_id', $employeeIds)->whereDate('work_date', '>=', reset($dates))->whereDate('work_date', '<=', end($dates))->get() as $s) {
            $key = $s->employee_id.'|'.$s->work_date->toDateString();
            $out[$key] = ['in' => $s->check_in, 'out' => $s->check_out, 'worked' => ($out[$key]['worked'] ?? 0) + $attendance->workedSeconds($s),
                'required' => (int) round($attendance->dailyHours($people[$s->employee_id], $s->work_date->toDateString()) * 3600)];
        }

        return $out;
    }

    /**
     * Off days on the roster, per person (each may have their own calendar): "employee|date" =>
     * ['kind' => holiday|special|weekly, 'label' => 'Diwali' / '3rd Saturday — weekly off' / 'Sunday — weekly off'].
     */
    private function offDays($employees, array $dates): array
    {
        $policies = app(\App\Services\PolicyConfigurationService::class);
        $out = [];
        foreach ($employees as $e) {
            foreach ($dates as $date) {
                $calendar = $policies->forEmployee($e, 'calendar.working_days', $date, ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
                if (\App\Support\WorkCalendar::isWorkday($date, $calendar)) continue;
                $holiday = in_array($date, \App\Support\WorkCalendar::offHolidays($calendar), true);
                $special = \App\Support\WorkCalendar::specialOffReason($date, $calendar);
                $out[$e->id.'|'.$date] = $holiday
                    ? ['kind' => 'holiday', 'label' => (string) ($calendar['holiday_names'][$date] ?? 'Holiday')]
                    : ['kind' => $special ? 'special' : 'weekly', 'label' => \App\Support\WorkCalendar::offDayReason($date, $calendar)];
            }
        }

        return $out;
    }

    public function closeModal(): void
    {
        $this->showShiftForm = false;
        $this->showAssign = false;
        $this->timelineEmployeeId = null;
    }

    public function render(ShiftService $shifts)
    {
        $start = Carbon::parse($this->week);
        $dates = collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i)->toDateString())->all();
        $employees = AttendanceEmployee::with('department')->where('is_active', true)
            ->when($this->department, fn ($q) => $q->where('department_id', $this->department))->inCodeOrder()->paginate(20);

        return $this->page('livewire.attendance.shifts', [
            'shiftList' => Shift::withCount(['assignments' => fn ($q) => $q->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))])->orderBy('start_time')->get(),
            'employees' => $employees,
            'allEmployees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'dates' => $dates,
            'roster' => $shifts->roster($employees->getCollection(), $dates),
            'actuals' => $this->actuals($employees->getCollection()->pluck('id')->all(), $dates),
            'offDays' => $this->offDays($employees->getCollection(), $dates),
            'timelineEmployee' => $this->timelineEmployeeId ? AttendanceEmployee::find($this->timelineEmployeeId) : null,
        ], 'Shifts & Roster');
    }
}
