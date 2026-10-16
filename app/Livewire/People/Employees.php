<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Models\Team;
use App\Services\EmployeeService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Employees extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public ?int $department = null;
    #[Url] public string $status = 'active';
    /** Joined between these dates (empty = any). */
    #[Url] public string $joinedFrom = '';
    #[Url] public string $joinedTo = '';
    /** Only people reporting to this manager (opened from Managers). */
    #[Url(as: 'manager')] public ?int $reportsTo = null;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $employeeCode = '';
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $designation = '';
    public string $phone = '';
    public ?int $departmentId = null;
    public ?int $managerId = null;
    public string $joinedAt = '';
    public string $dateOfBirth = '';
    public string $employmentType = 'full_time';
    public string $probationEndsOn = '';
    public string $noticePeriodDays = '';
    public string $workStart = '09:30';
    public string $workEnd = '18:30';
    public string $dailyHours = '8';
    public string $monthlySalary = '';
    public array $teamIds = [];
    public bool $isManager = false;

    public ?int $pwEmployeeId = null;
    public string $pwPassword = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'department', 'status', 'joinedFrom', 'joinedTo', 'reportsTo'], true)) $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->joinedAt = today()->toDateString();
        // New employees start from the company defaults under Settings > Work hours; they can be changed per person.
        $hours = app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'work.hours', today()->toDateString(), []);
        if (! empty($hours['start_time'])) $this->workStart = substr((string) $hours['start_time'], 0, 5);
        if (! empty($hours['end_time'])) $this->workEnd = substr((string) $hours['end_time'], 0, 5);
        if ((float) ($hours['standard_daily_hours'] ?? 0) > 0) $this->dailyHours = (string) (float) $hours['standard_daily_hours'];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $employee = AttendanceEmployee::with(['teams', 'user'])->findOrFail($id);
        $this->resetForm();
        $this->editingId = $employee->id;
        $this->fill([
            'employeeCode' => $employee->employee_code, 'name' => $employee->name, 'email' => (string) $employee->email,
            'designation' => (string) $employee->designation, 'phone' => (string) $employee->phone,
            'departmentId' => $employee->department_id, 'managerId' => $employee->manager_id,
            'joinedAt' => (string) $employee->joined_at?->toDateString(), 'dateOfBirth' => (string) $employee->date_of_birth?->toDateString(), 'employmentType' => $employee->employment_type ?: 'full_time', 'probationEndsOn' => (string) $employee->probation_ends_on?->toDateString(), 'noticePeriodDays' => (string) ($employee->notice_period_days ?? ''), 'workStart' => substr((string) $employee->work_start, 0, 5),
            'workEnd' => substr((string) $employee->work_end, 0, 5), 'dailyHours' => (string) (float) $employee->daily_work_hours,
            'teamIds' => $employee->teams->pluck('id')->map(fn ($id) => (string) $id)->all(), 'isManager' => (bool) $employee->user?->hasRole('Manager'),
        ]);
        $this->showForm = true;
    }

    public function save(EmployeeService $service): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $employee = $this->editingId ? AttendanceEmployee::with('user')->findOrFail($this->editingId) : null;
        $rules = [
            'employeeCode' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('attendance_employees', 'employee_code')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'departmentId' => ['nullable', 'exists:departments,id'],
            'managerId' => ['nullable', 'exists:attendance_employees,id', Rule::notIn([$employee?->id ?? 0])],
            'joinedAt' => ['nullable', 'date'],
            'dateOfBirth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'employmentType' => ['required', Rule::in(array_keys(EmployeeService::EMPLOYMENT_TYPES))],
            'probationEndsOn' => ['nullable', 'date', 'after_or_equal:joinedAt'],
            'noticePeriodDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'workStart' => ['nullable', 'date_format:H:i'],
            'workEnd' => ['nullable', 'date_format:H:i'],
            'dailyHours' => ['required', 'numeric', 'gt:0', 'max:24'],
            'teamIds' => ['array'], 'teamIds.*' => ['integer', 'exists:teams,id'],
        ];
        if (! $employee) {
            $rules['password'] = ['required', 'string', Password::min(12)->letters()->numbers()];
            $rules['monthlySalary'] = ['required', 'numeric', 'min:0'];
        }
        $this->validate($rules, [], ['employeeCode' => 'employee code', 'managerId' => 'manager', 'departmentId' => 'department', 'monthlySalary' => 'monthly salary', 'dailyHours' => 'daily hours']);

        $data = [
            'employee_code' => $this->employeeCode, 'name' => $this->name, 'email' => $this->email,
            'designation' => $this->designation ?: null, 'phone' => $this->phone ?: null,
            'department_id' => $this->departmentId ?: null, 'manager_id' => $this->managerId ?: null,
            'joined_at' => $this->joinedAt ?: null, 'date_of_birth' => $this->dateOfBirth ?: null, 'employment_type' => $this->employmentType, 'probation_ends_on' => $this->probationEndsOn ?: null, 'notice_period_days' => $this->noticePeriodDays === '' ? null : (int) $this->noticePeriodDays, 'work_start' => $this->workStart ?: null, 'work_end' => $this->workEnd ?: null,
            'daily_work_hours' => $this->dailyHours, 'team_ids' => array_map('intval', $this->teamIds), 'is_manager' => $this->isManager,
        ];
        if ($employee) {
            $service->update($employee, $data, $this->user());
            $this->toast('Employee updated.');
        } else {
            $service->create([...$data, 'password' => $this->password, 'monthly_salary' => $this->monthlySalary], $this->user());
            $this->toast('Employee and login account created.');
        }
        $this->closeModal();
    }

    public function openPassword(int $id): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403, 'Only an administrator can change an employee password.');
        $this->pwEmployeeId = AttendanceEmployee::findOrFail($id)->id;
        $this->pwPassword = '';
        $this->resetValidation();
    }

    public function resetPassword(EmployeeService $service): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403, 'Only an administrator can change an employee password.');
        $this->validate(['pwPassword' => ['required', 'string', Password::min(12)->letters()->numbers()]], [], ['pwPassword' => 'new password']);
        $service->resetPassword(AttendanceEmployee::with('user')->findOrFail($this->pwEmployeeId), $this->pwPassword, $this->user());
        $this->pwEmployeeId = null;
        $this->pwPassword = '';
        $this->toast('Password reset. The employee can sign in with the new password; their API tokens were revoked.');
    }

    public function toggleActive(int $id, EmployeeService $service): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $employee = AttendanceEmployee::with('user')->findOrFail($id);
        abort_if($employee->user_id === auth()->id(), 422, 'You cannot deactivate your own account.');
        $service->setActive($employee, ! $employee->is_active);
        $this->toast($employee->is_active ? 'Employee deactivated and access revoked.' : 'Employee reactivated.');
    }

    /** Administrator: removes an employee added by mistake (only when they have no records). */
    public function remove(int $id, EmployeeService $service): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403, 'Only an administrator can remove an employee.');
        $employee = AttendanceEmployee::with('user')->findOrFail($id);
        $service->remove($employee, $this->user());
        $this->toast("{$employee->name} was removed.");
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->pwEmployeeId = null;
        $this->pwPassword = '';
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'employeeCode', 'name', 'email', 'password', 'designation', 'phone', 'departmentId', 'managerId', 'joinedAt', 'dateOfBirth', 'employmentType', 'probationEndsOn', 'noticePeriodDays', 'workStart', 'workEnd', 'dailyHours', 'monthlySalary', 'teamIds', 'isManager');
        $this->resetValidation();
    }

    public function render()
    {
        $term = trim($this->search);
        $employees = AttendanceEmployee::with(['department', 'manager', 'user.roles', 'teams'])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('employee_code', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('designation', 'like', "%{$term}%")))
            ->when($this->department, fn ($q, $id) => $q->where('department_id', $id))
            ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
            ->when($this->reportsTo, fn ($q, $id) => $q->where('manager_id', $id))
            ->when(strtotime($this->joinedFrom), fn ($q) => $q->whereDate('joined_at', '>=', $this->joinedFrom))
            ->when(strtotime($this->joinedTo), fn ($q) => $q->whereDate('joined_at', '<=', $this->joinedTo))
            // Newest employee number first (natural order: EMP-10 after EMP-9).
            ->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')->paginate(15);

        return $this->page('livewire.people.employees', [
            'employees' => $employees,
            'departments' => Department::orderBy('name')->get(),
            'managers' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']),
            'teams' => Team::where('is_active', true)->orderBy('name')->get(),
            'counts' => ['active' => AttendanceEmployee::where('is_active', true)->count(), 'inactive' => AttendanceEmployee::where('is_active', false)->count()],
            'pwEmployee' => $this->pwEmployeeId ? AttendanceEmployee::find($this->pwEmployeeId) : null,
            'reportsToManager' => $this->reportsTo ? AttendanceEmployee::find($this->reportsTo) : null,
            'isAdmin' => \App\Support\Access::isAdmin($this->user()),
        ], 'Employees');
    }
}
