<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EmployeeResource;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EmployeeController extends ApiController
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('employees.manage') || $user->can('hr.manage') || $user->can('leave.approve'), 403);
        $query = AttendanceEmployee::with(['department', 'manager'])->where('is_active', true);
        if (! $user->can('employees.manage') && ! $user->can('hr.manage')) {
            $profile = AttendanceEmployee::where('user_id', $user->id)->firstOrFail();
            $query->where(fn ($q) => $q->whereKey($profile->id)->orWhere('manager_id', $profile->id));
        }

        return EmployeeResource::collection($query->inCodeOrder()->paginate(50));
    }

    public function show(Request $request, AttendanceEmployee $employee)
    {
        abort_unless($request->user()->can('view', $employee), 403);

        return new EmployeeResource($employee->load(['department', 'manager']));
    }

    /** GET /api/employees/form-options — departments + managers for the mobile create/edit form. */
    public function formOptions(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'employees.manage', 'hr.manage');

        return response()->json([
            'departments' => Department::orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'managers' => AttendanceEmployee::where('is_active', true)->inCodeOrder()->get(['id', 'name', 'employee_code'])->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'code' => $e->employee_code])->values(),
            'employment_types' => collect(EmployeeService::EMPLOYMENT_TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    /** POST /api/employees — create a new employee. */
    public function store(Request $request, EmployeeService $service): JsonResponse
    {
        $user = $this->requirePermission($request, 'employees.manage', 'hr.manage');

        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('attendance_employees', 'employee_code')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(12)->letters()->numbers()],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'manager_id' => ['nullable', 'exists:attendance_employees,id'],
            'joined_at' => ['nullable', 'date'],
            'employment_type' => ['required', Rule::in(array_keys(EmployeeService::EMPLOYMENT_TYPES))],
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'work_start' => ['nullable', 'date_format:H:i'],
            'work_end' => ['nullable', 'date_format:H:i'],
            'daily_work_hours' => ['nullable', 'numeric', 'gt:0', 'max:24'],
        ], [], ['employee_code' => 'employee code', 'department_id' => 'department', 'manager_id' => 'manager', 'monthly_salary' => 'monthly salary']);

        $employee = $service->create([
            'employee_code' => $data['employee_code'],
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'designation' => $data['designation'] ?? null,
            'phone' => $data['phone'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'joined_at' => $data['joined_at'] ?? today()->toDateString(),
            'employment_type' => $data['employment_type'],
            'monthly_salary' => $data['monthly_salary'] ?? 0,
            'pay_basis' => 'monthly',
            'work_start' => $data['work_start'] ?? '09:30',
            'work_end' => $data['work_end'] ?? '18:30',
            'daily_work_hours' => $data['daily_work_hours'] ?? 8,
            'team_ids' => [],
        ], $user);

        return response()->json([
            'data' => ['id' => $employee->id, 'name' => $employee->name, 'employee_code' => $employee->employee_code],
            'message' => 'Employee created.',
        ], 201);
    }

    /** PATCH /api/employees/{employee} — update an existing employee's profile. */
    public function update(Request $request, AttendanceEmployee $employee, EmployeeService $service): JsonResponse
    {
        $user = $this->requirePermission($request, 'employees.manage', 'hr.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'manager_id' => ['nullable', 'exists:attendance_employees,id', Rule::notIn([$employee->id])],
            'employment_type' => ['required', Rule::in(array_keys(EmployeeService::EMPLOYMENT_TYPES))],
            'work_start' => ['nullable', 'date_format:H:i'],
            'work_end' => ['nullable', 'date_format:H:i'],
            'daily_work_hours' => ['nullable', 'numeric', 'gt:0', 'max:24'],
            'joined_at' => ['nullable', 'date'],
        ], [], ['department_id' => 'department', 'manager_id' => 'manager']);

        $service->update($employee, [
            'name' => $data['name'],
            'designation' => $data['designation'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'employment_type' => $data['employment_type'],
            'work_start' => $data['work_start'] ?? $employee->work_start,
            'work_end' => $data['work_end'] ?? $employee->work_end,
            'daily_work_hours' => $data['daily_work_hours'] ?? $employee->daily_work_hours,
            'joined_at' => $data['joined_at'] ?? $employee->joined_at?->toDateString(),
            'team_ids' => $employee->teams()->pluck('teams.id')->all(),
        ], $user);

        return response()->json(['data' => ['id' => $employee->id], 'message' => 'Employee updated.']);
    }
}
