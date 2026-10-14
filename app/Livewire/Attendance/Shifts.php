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

    public function openAssign(?int $shiftId = null, ?int $employeeId = null): void
    {
        $this->authorizeShifts();
        $this->fill(['shiftId' => (string) ($shiftId ?? Shift::where('is_active', true)->value('id') ?? ''), 'employeeIds' => $employeeId ? [(string) $employeeId] : [], 'startsOn' => $this->week, 'endsOn' => '']);
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
        $this->toast("Shift assigned to {$count} ".str('employee')->plural($count).'.');
    }

    public function closeModal(): void
    {
        $this->showShiftForm = false;
        $this->showAssign = false;
    }

    public function render(ShiftService $shifts)
    {
        $start = Carbon::parse($this->week);
        $dates = collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i)->toDateString())->all();
        $employees = AttendanceEmployee::with('department')->where('is_active', true)
            ->when($this->department, fn ($q) => $q->where('department_id', $this->department))->orderBy('name')->paginate(20);

        return $this->page('livewire.attendance.shifts', [
            'shiftList' => Shift::withCount(['assignments' => fn ($q) => $q->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))])->orderBy('start_time')->get(),
            'employees' => $employees,
            'allEmployees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'dates' => $dates,
            'roster' => $shifts->roster($employees->getCollection(), $dates),
        ], 'Shifts & Roster');
    }
}
