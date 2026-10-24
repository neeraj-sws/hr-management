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
    use InteractsWithHr, WithPagination, \Livewire\WithFileUploads;

    #[Url] public string $search = '';
    #[Url] public ?int $department = null;
    #[Url] public string $status = 'active';
    /** Joined between these dates (empty = any). */
    #[Url] public string $joinedFrom = '';
    #[Url] public string $joinedTo = '';
    /** Column sort: code | name | email | department | hours | joined | status, and direction. */
    #[Url] public string $sort = 'code';
    #[Url] public string $dir = 'asc';
    #[Url] public int $perPage = 10;
    /** Import employees from a CSV / Excel sheet. */
    public bool $showImport = false;
    public $importFile = null;
    public ?array $importResult = null;

    public const SORTS = ['code' => null, 'name' => 'name', 'email' => 'email', 'department' => 'department', 'hours' => 'daily_work_hours', 'joined' => 'joined_at', 'status' => 'is_active'];

    public function sortBy(string $column): void
    {
        if (! array_key_exists($column, self::SORTS)) return;
        $this->dir = $this->sort === $column && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'department', 'joinedFrom', 'joinedTo', 'reportsTo');
        $this->status = 'active';
        $this->resetPage();
    }

    public function openImport(): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $this->reset('importFile', 'importResult');
        $this->resetValidation();
        $this->showImport = true;
    }

    public function importEmployees(\App\Services\EmployeeImport $import): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $this->validate(['importFile' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx']], ['importFile.required' => 'Choose a CSV or Excel file.', 'importFile.mimes' => 'Upload a .csv or .xlsx file.']);
        $this->importResult = $import->import($this->importFile, $this->user());
        $this->reset('importFile');
        if (! $this->importResult['fatal']) $this->toast("Import done: {$this->importResult['created']} added, {$this->importResult['updated']} updated.");
    }

    /** Only people reporting to this manager (opened from Managers). */
    #[Url(as: 'manager')] public ?int $reportsTo = null;

    public bool $showForm = false;
    /** The Trainees page (People > Trainees) is this list with trainees only; Employees leaves them out. */
    protected bool $traineesOnly = false;
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
    /** monthly | daily (daily wage, e.g. 130 per day attended). */
    public string $payBasis = 'monthly';
    public string $dailyRate = '';
    /** Ex-employee: last working day and how they left. */
    public string $exitDate = '';
    public string $exitType = 'resignation';
    public string $exitReason = '';
    public array $teamIds = [];
    public bool $isManager = false;

    public ?int $pwEmployeeId = null;
    public string $pwPassword = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'department', 'status', 'joinedFrom', 'joinedTo', 'reportsTo', 'perPage'], true)) $this->resetPage();
        // Choosing "Daily wage" sets the salary to per day.
        if ($property === 'employmentType' && $this->employmentType === 'daily_wage') $this->payBasis = 'daily';
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
        if ($this->traineesOnly) $this->employmentType = EmployeeService::TRAINEE;
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
            'exitDate' => (string) $employee->exit_date?->toDateString(), 'exitType' => $employee->exit_type ?: 'resignation', 'exitReason' => (string) $employee->exit_reason,
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
        $left = $this->employmentType === EmployeeService::EX_EMPLOYEE;
        if ($left) {
            $rules['exitDate'] = ['required', 'date', 'before_or_equal:today', ...($this->joinedAt ? ['after_or_equal:joinedAt'] : [])];
            $rules['exitType'] = ['required', Rule::in(array_keys(EmployeeService::EXIT_TYPES))];
            $rules['exitReason'] = ['nullable', 'string', 'max:500'];
        }
        if (! $employee) {
            // An ex-employee never signs in, so no password is needed.
            $rules['password'] = $left ? ['nullable', 'string', Password::min(12)->letters()->numbers()] : ['required', 'string', Password::min(12)->letters()->numbers()];
            $rules['payBasis'] = ['required', Rule::in(['monthly', 'daily'])];
            if ($this->payBasis === 'daily') $rules['dailyRate'] = ['required', 'numeric', 'gt:0', 'max:1000000'];
            // Ex-employees and trainees (they pay a course fee) have no salary to enter.
            else $rules['monthlySalary'] = [$left || $this->employmentType === EmployeeService::TRAINEE ? 'nullable' : 'required', 'numeric', 'min:0'];
        }
        $this->validate($rules, [], ['employeeCode' => 'employee code', 'managerId' => 'manager', 'departmentId' => 'department', 'monthlySalary' => 'monthly salary', 'dailyHours' => 'daily hours', 'dailyRate' => 'per-day salary', 'exitDate' => 'last working day', 'exitType' => 'exit type']);

        $data = [
            'employee_code' => $this->employeeCode, 'name' => $this->name, 'email' => $this->email,
            'designation' => $this->designation ?: null, 'phone' => $this->phone ?: null,
            'department_id' => $this->departmentId ?: null, 'manager_id' => $this->managerId ?: null,
            'joined_at' => $this->joinedAt ?: null, 'date_of_birth' => $this->dateOfBirth ?: null, 'employment_type' => $this->employmentType, 'probation_ends_on' => $this->probationEndsOn ?: null, 'notice_period_days' => $this->noticePeriodDays === '' ? null : (int) $this->noticePeriodDays, 'work_start' => $this->workStart ?: null, 'work_end' => $this->workEnd ?: null,
            'daily_work_hours' => $this->dailyHours, 'team_ids' => array_map('intval', $this->teamIds), 'is_manager' => $this->isManager,
            ...($left ? ['exit_date' => $this->exitDate, 'exit_type' => $this->exitType, 'exit_reason' => $this->exitReason ?: null] : []),
        ];
        if ($employee) {
            $service->update($employee, $data, $this->user());
            $this->toast($left ? 'Saved as an ex-employee. Their login and devices are switched off; their records stay.' : 'Employee updated.');
        } else {
            $service->create([...$data, 'password' => $this->password, 'monthly_salary' => $this->monthlySalary === '' ? 0 : $this->monthlySalary, 'pay_basis' => $this->payBasis, 'daily_rate' => $this->payBasis === 'daily' ? $this->dailyRate : null], $this->user());
            $this->toast($left ? 'Ex-employee added (inactive, no login). Their old attendance and salary can now be imported.' : 'Employee and login account created.');
        }
        // Saved as the other kind (e.g. made a trainee from Employees): say where they now are.
        if (! $left && ($this->employmentType === EmployeeService::TRAINEE) !== $this->traineesOnly) $this->toast($this->traineesOnly ? 'Saved. Not a trainee any more, so now listed under People > Employees.' : 'Saved as a trainee, so now listed under People > Trainees.');
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
        $this->showImport = false;
        $this->pwEmployeeId = null;
        $this->pwPassword = '';
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'employeeCode', 'name', 'email', 'password', 'designation', 'phone', 'departmentId', 'managerId', 'joinedAt', 'dateOfBirth', 'employmentType', 'probationEndsOn', 'noticePeriodDays', 'workStart', 'workEnd', 'dailyHours', 'monthlySalary', 'payBasis', 'dailyRate', 'exitDate', 'exitType', 'exitReason', 'teamIds', 'isManager');
        $this->resetValidation();
    }

    public function render()
    {
        $term = trim($this->search);
        $kind = fn ($q) => $this->traineesOnly ? $q->where('employment_type', EmployeeService::TRAINEE) : $q->onPayroll();
        $employees = AttendanceEmployee::with(['department', 'manager', 'user.roles', 'teams'])
            ->where($kind)
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('employee_code', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('designation', 'like', "%{$term}%")))
            ->when($this->department, fn ($q, $id) => $q->where('department_id', $id))
            ->when($this->status === 'left', fn ($q) => $q->where('is_active', false)->where(fn ($q) => $q->whereNotNull('exit_date')->orWhere('employment_type', EmployeeService::EX_EMPLOYEE)))
            ->when(! in_array($this->status, ['all', 'left'], true), fn ($q) => $q->where('is_active', $this->status === 'active'))
            ->when($this->reportsTo, fn ($q, $id) => $q->where('manager_id', $id))
            ->when(strtotime($this->joinedFrom), fn ($q) => $q->whereDate('joined_at', '>=', $this->joinedFrom))
            ->when(strtotime($this->joinedTo), fn ($q) => $q->whereDate('joined_at', '<=', $this->joinedTo))
            ->tap(function ($q) {
                $dir = $this->dir === 'desc' ? 'desc' : 'asc';
                match ($this->sort) {
                    'department' => $q->orderBy(Department::select('name')->whereColumn('departments.id', 'attendance_employees.department_id'), $dir),
                    'code' => $q->orderByRaw('LENGTH(employee_code) '.$dir)->orderBy('employee_code', $dir),
                    default => $q->orderBy(self::SORTS[$this->sort] ?? 'employee_code', $dir),
                };
                // Then employee number in natural order (EMP-10 after EMP-9).
                $q->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code');
            })
            ->paginate(in_array($this->perPage, [10, 15, 25, 50], true) ? $this->perPage : 10);

        return $this->page('livewire.people.employees', [
            'employees' => $employees,
            'departments' => Department::orderBy('name')->get(),
            'managers' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']),
            'teams' => Team::where('is_active', true)->orderBy('name')->get(),
            'counts' => ['active' => AttendanceEmployee::where($kind)->where('is_active', true)->count(), 'inactive' => AttendanceEmployee::where($kind)->where('is_active', false)->count(),
                'other' => $this->traineesOnly ? AttendanceEmployee::onPayroll()->where('is_active', true)->count() : AttendanceEmployee::where('employment_type', EmployeeService::TRAINEE)->where('is_active', true)->count()],
            'traineesOnly' => $this->traineesOnly,
            'pwEmployee' => $this->pwEmployeeId ? AttendanceEmployee::find($this->pwEmployeeId) : null,
            'reportsToManager' => $this->reportsTo ? AttendanceEmployee::find($this->reportsTo) : null,
            'isAdmin' => \App\Support\Access::isAdmin($this->user()),
            'canImport' => $this->user()->canAny(['employees.manage', 'hr.manage']),
        ], $this->traineesOnly ? 'Trainees' : 'Employees');
    }
}
