<?php

namespace App\Livewire\Lifecycle;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeAsset;
use App\Models\EmployeeClearanceItem;
use App\Models\EmployeeLifecycleCase;
use App\Models\LeaveType;
use App\Models\SalaryAdvance;
use App\Services\AuditLogger;
use App\Services\LeaveAccrualService;
use App\Services\PeopleOperationsService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Onboarding: department → team → manager → salary → leave policy → documents → assets → active.
 * Offboarding: clearance → asset return → leave balance → salary/advance recovery → final settlement → payment → inactive.
 */
class Cases extends Component
{
    use InteractsWithHr;

    #[Locked] public string $kind = 'onboarding';
    #[Url] public ?int $case = null;
    #[Url] public string $status = 'open';

    public bool $showForm = false;
    public ?int $employeeId = null;
    public string $effectiveOn = '';
    public string $notes = '';
    public string $exitType = 'resignation';
    public string $resignedOn = '';
    public string $newItem = '';

    public function mount(): void
    {
        $this->kind = request()->routeIs('lifecycle.offboarding') ? 'offboarding' : 'onboarding';
    }

    public function create(): void
    {
        $this->reset('employeeId', 'notes');
        $this->effectiveOn = today()->toDateString();
        $this->exitType = 'resignation';
        $this->resignedOn = today()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function open(PeopleOperationsService $people): void
    {
        abort_unless($this->user()->can('lifecycle.manage'), 403);
        $this->validate(['employeeId' => ['required', 'exists:attendance_employees,id'], 'effectiveOn' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000'], 'exitType' => [$this->kind === 'offboarding' ? 'required' : 'nullable', 'in:resignation,termination,end_of_contract,retirement'], 'resignedOn' => ['nullable', 'date']], [], ['employeeId' => 'employee', 'exitType' => 'exit type', 'resignedOn' => 'resignation date', 'effectiveOn' => $this->kind === 'offboarding' ? 'last working day' : 'start date']);
        if (EmployeeLifecycleCase::where('employee_id', $this->employeeId)->where('kind', $this->kind)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['employeeId' => "This employee already has an open {$this->kind} case."]);
        }
        $case = $people->openLifecycle(AttendanceEmployee::findOrFail($this->employeeId), $this->kind, $this->effectiveOn, $this->notes ?: null, auth()->id(), $this->kind === 'offboarding' ? ['type' => $this->exitType, 'resigned_on' => $this->resignedOn ?: null] : null);
        $this->showForm = false;
        $this->case = $case->id;
        $this->toast(ucfirst($this->kind).' case opened.');
    }

    public function clearItem(int $id, AuditLogger $audit): void
    {
        abort_unless($this->user()->can('lifecycle.manage'), 403);
        $item = EmployeeClearanceItem::where('lifecycle_case_id', $this->case)->findOrFail($id);
        if ($item->status !== 'pending') return;
        $item->update(['status' => 'cleared', 'cleared_by' => auth()->id(), 'cleared_at' => now()]);
        $audit->record('employee.clearance_completed', $item, ['item' => $item->item]);
    }

    public function addItem(AuditLogger $audit): void
    {
        abort_unless($this->user()->can('lifecycle.manage'), 403);
        $this->validate(['newItem' => ['required', 'string', 'max:255']], [], ['newItem' => 'checklist item']);
        $case = EmployeeLifecycleCase::where('status', 'open')->findOrFail($this->case);
        $item = $case->clearances()->create(['item' => $this->newItem, 'status' => 'pending']);
        $audit->record('employee.clearance_added', $item);
        $this->newItem = '';
    }

    public function returnAsset(int $assetId, AuditLogger $audit): void
    {
        abort_unless($this->user()->can('lifecycle.manage'), 403);
        $case = EmployeeLifecycleCase::findOrFail($this->case);
        $asset = EmployeeAsset::where('employee_id', $case->employee_id)->where('status', 'assigned')->findOrFail($assetId);
        $asset->update(['status' => 'returned', 'returned_on' => today()]);
        $audit->record('employee.asset_returned', $asset);
        $this->toast('Asset marked as returned.');
    }

    public function close(PeopleOperationsService $people): void
    {
        abort_unless($this->user()->can('lifecycle.manage'), 403);
        $case = EmployeeLifecycleCase::findOrFail($this->case);
        if ($case->kind === 'onboarding') {
            $missing = collect($this->onboardingChecks($case->employee))->reject(fn ($check) => $check['done'] || ! $check['required'])->pluck('label');
            if ($missing->isNotEmpty()) throw ValidationException::withMessages(['clearance' => 'Complete onboarding first: '.$missing->join(', ').'.']);
        }
        $people->closeLifecycle($case, auth()->id());
        $this->toast($case->kind === 'offboarding' ? 'Offboarding closed. The employee is now inactive and all access was revoked.' : 'Onboarding completed. The employee is active.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
    }

    /** Data-driven onboarding readiness, checked against the employee's real records. */
    private function onboardingChecks(AttendanceEmployee $e): array
    {
        return [
            ['label' => 'Department assigned', 'done' => (bool) $e->department_id, 'required' => true],
            ['label' => 'Added to a team', 'done' => $e->teams()->exists(), 'required' => false],
            ['label' => 'Manager assigned', 'done' => (bool) $e->manager_id, 'required' => true],
            ['label' => 'Salary profile configured', 'done' => $e->salaryProfiles()->exists(), 'required' => true],
            ['label' => 'Leave balances for '.today()->year, 'done' => $e->leaveBalances()->where('year', today()->year)->exists(), 'required' => true],
            ['label' => 'Documents uploaded', 'done' => $e->documents()->exists(), 'required' => false],
            ['label' => 'Assets issued', 'done' => $e->assets()->exists(), 'required' => false],
            ['label' => 'Login account active', 'done' => (bool) $e->user?->is_active, 'required' => true],
        ];
    }

    public function render(LeaveAccrualService $leave)
    {
        $cases = EmployeeLifecycleCase::with(['employee', 'clearances', 'settlement'])->where('kind', $this->kind)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))->latest()->get();
        $selected = $this->case ? EmployeeLifecycleCase::with(['employee.department', 'employee.manager', 'clearances', 'settlement.payments'])->where('kind', $this->kind)->find($this->case) : null;
        $details = [];
        if ($selected) {
            $employee = $selected->employee;
            $details['assets'] = $employee->assets()->latest()->get();
            if ($this->kind === 'onboarding') {
                $details['checks'] = $this->onboardingChecks($employee);
            } else {
                $details['leave'] = LeaveType::where('is_active', true)->where('paid', true)->get()->map(fn ($t) => ['type' => $t->name, 'remaining' => $leave->balance($employee, $t, today()->year)['remaining']]);
                $details['advances'] = SalaryAdvance::where('employee_id', $employee->id)->whereIn('status', ['approved', 'disbursed'])->get()->sum(fn ($a) => max(0, (float) $a->disbursed_amount - (float) $a->recovered_amount));
            }
        }

        return $this->page('livewire.lifecycle.cases', [
            'cases' => $cases,
            'selected' => $selected,
            'details' => $details,
            'employees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']),
        ], $this->kind === 'offboarding' ? 'Offboarding' : 'Onboarding');
    }
}
