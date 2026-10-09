<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\PayrollRun;
use App\Models\SalaryAdjustment;
use App\Models\SalaryProfile;
use App\Services\ApprovalInbox;
use App\Services\AuditLogger;
use App\Services\PeopleOperationsService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Salary extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';

    public ?int $profileEmployeeId = null;
    public string $monthly = '';
    public string $workingDays = '22';
    public string $dailyHours = '8';
    public string $effectiveFrom = '';
    /** @var array<int, array{name: string, type: string, amount: string}> */
    public array $components = [];

    public bool $showAdjustment = false;
    public ?int $adjustmentEmployeeId = null;
    public string $kind = 'earning';
    public string $amount = '';
    public string $effectiveOn = '';
    public string $reason = '';

    private function canManage(): bool
    {
        return $this->user()->canAny(['payroll.manage', 'hr.manage']);
    }

    public function editProfile(int $employeeId): void
    {
        abort_unless($this->canManage(), 403);
        $employee = AttendanceEmployee::findOrFail($employeeId);
        $current = $employee->salaryProfiles()->orderByDesc('effective_from')->first();
        $this->fill(['profileEmployeeId' => $employee->id, 'monthly' => (string) ($current?->monthly_salary ?? ''), 'workingDays' => (string) ($current?->working_days_per_month ?? 22), 'dailyHours' => (string) (float) ($current?->standard_daily_hours ?? 8), 'effectiveFrom' => today()->startOfMonth()->toDateString(),
            'components' => collect($current?->components ?? [])->map(fn ($c) => ['name' => (string) $c['name'], 'type' => (string) $c['type'], 'amount' => (string) $c['amount']])->all()]);
        $this->resetValidation();
    }

    public function addComponent(string $type = 'earning'): void
    {
        $this->components[] = ['name' => '', 'type' => $type, 'amount' => ''];
    }

    public function removeComponent(int $index): void
    {
        unset($this->components[$index]);
        $this->components = array_values($this->components);
    }

    /** Fills the rows from the company template for the entered monthly salary. */
    public function applyTemplate(\App\Services\SalaryStructureService $structure): void
    {
        $this->validate(['monthly' => ['required', 'numeric', 'min:0']], [], ['monthly' => 'monthly salary']);
        $profile = new SalaryProfile(['monthly_salary' => $this->monthly]);
        $this->components = collect($structure->components($profile, AttendanceEmployee::find($this->profileEmployeeId)))->map(fn ($c) => ['name' => $c['name'], 'type' => $c['type'], 'amount' => (string) $c['amount']])->all();
    }

    public function saveProfile(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['monthly' => ['required', 'numeric', 'min:0', 'max:100000000'], 'workingDays' => ['required', 'integer', 'min:1', 'max:31'], 'dailyHours' => ['required', 'numeric', 'gt:0', 'max:24'], 'effectiveFrom' => ['required', 'date']], [], ['monthly' => 'monthly salary', 'workingDays' => 'working days', 'dailyHours' => 'daily hours', 'effectiveFrom' => 'effective date']);
        $lockedThrough = PayrollRun::where('status', 'locked')->max('period_ends_on');
        if ($lockedThrough && $this->effectiveFrom <= substr((string) $lockedThrough, 0, 10)) {
            throw ValidationException::withMessages(['effectiveFrom' => 'Payroll is locked through '.substr((string) $lockedThrough, 0, 10).'. Choose a later effective date.']);
        }
        $rows = app(\App\Services\SalaryStructureService::class)->validate($this->components, (float) $this->monthly);
        app(\App\Services\PayrollLockGuard::class)->changed((int) $this->profileEmployeeId, $this->effectiveFrom, 'salary', '2999-12-31');
        $profile = SalaryProfile::updateOrCreate(['employee_id' => $this->profileEmployeeId, 'effective_from' => $this->effectiveFrom], ['components' => $rows ?: null, 'monthly_salary' => $this->monthly, 'working_days_per_month' => $this->workingDays, 'standard_daily_hours' => $this->dailyHours, 'configured_by' => auth()->id()]);
        $latest = SalaryProfile::where('employee_id', $this->profileEmployeeId)->orderByDesc('effective_from')->first();
        AttendanceEmployee::whereKey($this->profileEmployeeId)->update(['monthly_salary' => $latest->monthly_salary]);
        $audit->record('salary.profile_configured', $profile, ['monthly_salary' => (float) $this->monthly, 'effective_from' => $this->effectiveFrom, 'components' => $rows]);
        $this->profileEmployeeId = null;
        $this->toast('Salary profile saved.');
    }

    public function newAdjustment(): void
    {
        $this->reset('adjustmentEmployeeId', 'amount', 'reason');
        $this->kind = 'earning';
        $this->effectiveOn = today()->toDateString();
        $this->resetValidation();
        $this->showAdjustment = true;
    }

    public function saveAdjustment(PeopleOperationsService $people): void
    {
        $this->validate(['kind' => ['required', 'in:earning,deduction'], 'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'], 'effectiveOn' => ['required', 'date'], 'reason' => ['required', 'string', 'max:255'], 'adjustmentEmployeeId' => ['nullable', 'exists:attendance_employees,id']], [], ['effectiveOn' => 'effective date']);
        $employee = $this->user()->can('salary.adjust') && $this->adjustmentEmployeeId ? AttendanceEmployee::findOrFail($this->adjustmentEmployeeId) : $this->requireEmployee();
        $people->requestAdjustment($employee, (float) $this->amount, $this->kind, $this->effectiveOn, $this->reason, auth()->id());
        $this->showAdjustment = false;
        $this->toast('Adjustment submitted for approval.');
    }

    public function decide(int $id, bool $approve, ApprovalInbox $inbox): void
    {
        $approval = ApprovalRequest::where('approvable_type', SalaryAdjustment::class)->where('approvable_id', $id)->where('status', 'pending')->firstOrFail();
        $inbox->decide($this->user(), $approval, $approve);
        $this->toast($approve ? 'Adjustment approved.' : 'Adjustment rejected.');
    }

    public function closeModal(): void
    {
        $this->profileEmployeeId = null;
        $this->showAdjustment = false;
    }

    public function render(ApprovalInbox $inbox)
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $employees = null;
        if ($this->canManage() || $user->can('payroll.approve')) {
            $employees = AttendanceEmployee::with(['salaryProfile', 'department'])->where('is_active', true)
                ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_code', 'like', '%'.trim($this->search).'%')))
                ->orderBy('name')->paginate(15);
        }
        $adjustments = SalaryAdjustment::with('employee')
            ->when(! $user->canAny(['salary.adjust', 'payroll.manage', 'payroll.approve']), fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->latest()->limit(30)->get();

        return $this->page('livewire.payroll.salary', [
            'employees' => $employees,
            'own' => $own,
            'myProfiles' => $own ? $own->salaryProfiles()->orderByDesc('effective_from')->get() : collect(),
            'adjustments' => $adjustments,
            'decidable' => $inbox->pending($user, SalaryAdjustment::class)->pluck('approvable_id')->all(),
            'canManage' => $this->canManage(),
            'allEmployees' => $user->can('salary.adjust') ? AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'editing' => $this->profileEmployeeId ? AttendanceEmployee::find($this->profileEmployeeId) : null,
        ], 'Salary');
    }
}
