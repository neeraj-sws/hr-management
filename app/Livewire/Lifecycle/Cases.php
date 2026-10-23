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
    use InteractsWithHr, \Livewire\WithPagination;

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
    public bool $showCorrect = false;
    public bool $showCancel = false;
    public string $correctionReason = '';
    public string $cancelReason = '';

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
        $asset->recordEvent('returned', $asset->employee_id, 'Returned during offboarding');
        $audit->record('employee.asset_returned', $asset, ['employee_id' => $asset->employee_id, 'asset_tag' => $asset->asset_tag]);
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

    /** Correct an offboarding case opened with wrong details (within the correction window). */
    public function openCorrect(PeopleOperationsService $people): void
    {
        $case = EmployeeLifecycleCase::with('employee')->findOrFail($this->case);
        abort_unless($people->canCorrectOffboarding($this->user(), $case), 403, 'The correction window for this case has passed. Ask an administrator.');
        $this->fill(['effectiveOn' => (string) $case->effective_on?->toDateString(), 'exitType' => $case->employee->exit_type ?: 'resignation', 'resignedOn' => (string) $case->employee->resigned_on?->toDateString(), 'notes' => (string) $case->notes, 'correctionReason' => '', 'showCorrect' => true]);
        $this->resetValidation();
    }

    public function saveCorrection(PeopleOperationsService $people): void
    {
        $this->validate(['effectiveOn' => ['required', 'date'], 'exitType' => ['required', 'in:resignation,termination,end_of_contract,retirement'], 'resignedOn' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000'], 'correctionReason' => ['required', 'string', 'min:5', 'max:1000']], [], ['effectiveOn' => 'last working day', 'exitType' => 'exit type', 'correctionReason' => 'reason for the correction']);
        $people->correctOffboarding(EmployeeLifecycleCase::findOrFail($this->case), ['effective_on' => $this->effectiveOn, 'exit_type' => $this->exitType, 'resigned_on' => $this->resignedOn ?: null, 'notes' => $this->notes ?: null], $this->correctionReason, $this->user());
        $this->showCorrect = false;
        $this->toast('Offboarding case corrected. The change is in the audit log.');
    }

    /** Cancel an offboarding case opened by mistake (a closed one is reversed and the employee re-activated). */
    public function openCancel(PeopleOperationsService $people): void
    {
        $case = EmployeeLifecycleCase::findOrFail($this->case);
        abort_unless($people->canCorrectOffboarding($this->user(), $case), 403, 'The correction window for this case has passed. Ask an administrator.');
        $this->fill(['cancelReason' => '', 'showCancel' => true]);
        $this->resetValidation();
    }

    public function cancelCase(PeopleOperationsService $people): void
    {
        $this->validate(['cancelReason' => ['required', 'string', 'min:5', 'max:1000']], ['cancelReason.required' => 'Say why the offboarding is being cancelled.'], ['cancelReason' => 'reason']);
        $case = $people->cancelOffboarding(EmployeeLifecycleCase::findOrFail($this->case), $this->cancelReason, $this->user());
        $this->showCancel = false;
        $this->status = 'all';
        $this->toast('Offboarding cancelled'.($case->employee->is_active ? '; the employee is active.' : '.'));
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->showCorrect = false;
        $this->showCancel = false;
    }

    /** Data-driven onboarding readiness, checked against the employee's real records. */
    private function onboardingChecks(AttendanceEmployee $e): array
    {
        return [
            ['label' => 'Department assigned', 'done' => (bool) $e->department_id, 'required' => true],
            ['label' => 'Added to a team', 'done' => $e->teams()->exists(), 'required' => false],
            ['label' => 'Manager assigned', 'done' => (bool) $e->manager_id, 'required' => true],
            $e->isTrainee()
                ? ['label' => 'Enrolled in a course (Training > Course fees)', 'done' => \App\Models\TrainingEnrollment::where('employee_id', $e->id)->exists(), 'required' => false]
                : ['label' => 'Salary profile configured', 'done' => $e->salaryProfiles()->exists(), 'required' => true],
            ['label' => 'Leave balances for '.\App\Support\LeaveYear::label(\App\Support\LeaveYear::current()), 'done' => $e->leaveBalances()->where('year', \App\Support\LeaveYear::current())->exists(), 'required' => true],
            ['label' => 'Documents uploaded', 'done' => $e->documents()->exists(), 'required' => false],
            ['label' => 'Assets issued', 'done' => $e->assets()->exists(), 'required' => false],
            ['label' => 'Login account active', 'done' => (bool) $e->user?->is_active, 'required' => true],
        ];
    }

    public function render(LeaveAccrualService $leave)
    {
        // whereHas('employee'): a case whose employee no longer exists is skipped, not a crash.
        $cases = EmployeeLifecycleCase::with(['employee', 'clearances', 'settlement'])->where('kind', $this->kind)->whereHas('employee')
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))->latest()->paginate(20);
        $selected = $this->case ? EmployeeLifecycleCase::with(['employee.department', 'employee.manager', 'clearances', 'settlement.payments', 'canceller'])->where('kind', $this->kind)->whereHas('employee')->find($this->case) : null;
        $people = app(PeopleOperationsService::class);
        $details = [];
        if ($selected) {
            $employee = $selected->employee;
            $details['assets'] = $employee->assets()->latest()->get();
            if ($this->kind === 'onboarding') {
                $details['checks'] = $this->onboardingChecks($employee);
            } else {
                $details['leave'] = LeaveType::where('is_active', true)->where('paid', true)->get()->map(fn ($t) => ['type' => $t->name, 'remaining' => $leave->balance($employee, $t, \App\Support\LeaveYear::current())['remaining']]);
                $details['advances'] = SalaryAdvance::where('employee_id', $employee->id)->whereIn('status', ['approved', 'disbursed'])->get()->sum(fn ($a) => max(0, (float) $a->disbursed_amount - (float) $a->recovered_amount));
            }
        }

        return $this->page('livewire.lifecycle.cases', [
            'cases' => $cases,
            'selected' => $selected,
            'details' => $details,
            'employees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']),
            'canCorrect' => $selected && $selected->kind === 'offboarding' ? $people->canCorrectOffboarding($this->user(), $selected) : false,
            'correctionDeadline' => $selected && $selected->kind === 'offboarding' ? $people->correctionDeadline($selected) : null,
            'windowHours' => $people->offboardingWindowHours(),
        ], $this->kind === 'offboarding' ? 'Offboarding' : 'Onboarding');
    }
}
