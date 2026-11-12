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
use App\Livewire\Concerns\WithPaginationNoUrl as WithPagination;

class Salary extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';

    public ?int $profileEmployeeId = null;
    public string $monthly = '';
    /** monthly | daily (daily wage paid per working day attended). */
    public string $payBasis = 'monthly';
    public string $dailyRate = '';
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
    public bool $recurring = false;
    /** Last month (Y-m) a recurring adjustment applies; empty = until stopped. */
    public string $recurringUntil = '';

    private function canManage(): bool
    {
        return $this->user()->canAny(['payroll.manage', 'hr.manage']);
    }

    /** True when the form is correcting the current salary in place (vs adding a new effective-dated revision). */
    public bool $correcting = false;

    public function editProfile(int $employeeId): void
    {
        $this->loadProfile($employeeId, correct: false);
    }

    /**
     * Edit the current salary record in place to fix a wrong entry: loads the
     * latest profile with its actual effective date, so saving overwrites that
     * same record instead of creating a new revision.
     */
    public function correctProfile(int $employeeId): void
    {
        $this->loadProfile($employeeId, correct: true);
    }

    private function loadProfile(int $employeeId, bool $correct): void
    {
        abort_unless($this->canManage(), 403);
        $employee = AttendanceEmployee::findOrFail($employeeId);
        $current = $employee->salaryProfiles()->orderByDesc('effective_from')->first();
        if ($correct && ! $current) {
            $this->toast('No salary record to edit yet. Use Revise to set one.');

            return;
        }
        $this->correcting = $correct;
        $this->fill(['profileEmployeeId' => $employee->id, 'monthly' => (string) ($current?->monthly_salary ?? ''), 'payBasis' => $current?->pay_basis ?: 'monthly', 'dailyRate' => (string) ($current?->daily_rate ?? ''), 'workingDays' => (string) ($current?->working_days_per_month ?? 22), 'dailyHours' => (string) app(\App\Services\AttendanceService::class)->dailyHours($employee),
            'effectiveFrom' => $correct ? $current->effective_from->toDateString() : today()->startOfMonth()->toDateString(),
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
        if (AttendanceEmployee::find($this->profileEmployeeId)?->isTrainee()) throw ValidationException::withMessages(['monthly' => 'This person is a trainee: trainees get no salary.']);
        $daily = $this->payBasis === 'daily';
        if ($daily) {
            $this->validate(['dailyRate' => ['required', 'numeric', 'gt:0', 'max:1000000']], [], ['dailyRate' => 'per-day salary']);
            // Daily wage: the monthly figure is only an estimate for lists; payroll pays rate × days present.
            $this->monthly = (string) round((float) $this->dailyRate * max(1, (int) $this->workingDays), 2);
            $this->components = [];
        }
        $this->validate(['payBasis' => ['required', 'in:monthly,daily'], 'monthly' => ['required', 'numeric', 'min:0', 'max:100000000'], 'workingDays' => ['required', 'integer', 'min:1', 'max:31'], 'dailyHours' => ['required', 'numeric', 'gt:0', 'max:24'], 'effectiveFrom' => ['required', 'date']], [], ['monthly' => 'monthly salary', 'workingDays' => 'working days', 'dailyHours' => 'daily hours', 'effectiveFrom' => 'effective date']);
        $lockedThrough = PayrollRun::where('status', 'locked')->closingFor((int) $this->profileEmployeeId)->max('period_ends_on');
        if ($lockedThrough && $this->effectiveFrom <= substr((string) $lockedThrough, 0, 10)) {
            throw ValidationException::withMessages(['effectiveFrom' => 'Payroll is locked through '.substr((string) $lockedThrough, 0, 10).'. Choose a later effective date.']);
        }
        $rows = app(\App\Services\SalaryStructureService::class)->validate($this->components, (float) $this->monthly);
        app(\App\Services\PayrollLockGuard::class)->changed((int) $this->profileEmployeeId, $this->effectiveFrom, 'salary', '2999-12-31');
        $values = ['components' => $rows ?: null, 'monthly_salary' => $this->monthly, 'pay_basis' => $daily ? 'daily' : 'monthly', 'daily_rate' => $daily ? $this->dailyRate : null,'working_days_per_month' => $this->workingDays, 'standard_daily_hours' => $this->dailyHours, 'configured_by' => auth()->id()];
        // Match on the date part (robust across MySQL/SQLite) so editing the same effective date overwrites that record.
        $profile = SalaryProfile::where('employee_id', $this->profileEmployeeId)->whereDate('effective_from', $this->effectiveFrom)->first();
        if ($profile) {
            $profile->update($values);
        } else {
            $profile = SalaryProfile::create(['employee_id' => $this->profileEmployeeId, 'effective_from' => $this->effectiveFrom, ...$values]);
        }
        $latest = SalaryProfile::where('employee_id', $this->profileEmployeeId)->orderByDesc('effective_from')->first();
        // The current record's hours are the employee's working day everywhere (attendance, payroll, settings).
        AttendanceEmployee::whereKey($this->profileEmployeeId)->update(['monthly_salary' => $latest->monthly_salary, 'daily_work_hours' => $latest->standard_daily_hours]);
        $audit->record('salary.profile_configured', $profile, ['monthly_salary' => (float) $this->monthly, 'effective_from' => $this->effectiveFrom, 'components' => $rows]);
        $employee = AttendanceEmployee::with('user')->find($this->profileEmployeeId);
        \App\Services\Notifier::send($employee?->user, 'SalaryProfile', $profile->id, 'Your salary '.($this->correcting ? 'record was corrected' : 'was updated').': '.($daily ? number_format((float) $this->dailyRate, 2).' per day' : number_format((float) $this->monthly, 2).' per month').' from '.\Illuminate\Support\Carbon::parse($this->effectiveFrom)->format('d M Y').'.', null, auth()->id());
        $message = $this->correcting ? 'Salary corrected. The employee was notified.' : 'Salary profile saved. The employee was notified.';
        $this->profileEmployeeId = null;
        $this->correcting = false;
        $this->toast($message);
    }

    public function newAdjustment(): void
    {
        $this->reset('adjustmentEmployeeId', 'amount', 'reason', 'recurring', 'recurringUntil');
        $this->kind = 'bonus';
        $this->effectiveOn = today()->toDateString();
        $this->resetValidation();
        $this->showAdjustment = true;
    }

    public function saveAdjustment(PeopleOperationsService $people): void
    {
        $this->validate(['kind' => ['required', 'in:'.implode(',', array_keys(SalaryAdjustment::KINDS))], 'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'], 'effectiveOn' => ['required', 'date'], 'reason' => ['required', 'string', 'max:255'], 'adjustmentEmployeeId' => ['nullable', 'exists:attendance_employees,id'], 'recurringUntil' => ['nullable', 'date_format:Y-m']], [], ['effectiveOn' => 'effective date', 'recurringUntil' => 'last month']);
        $employee = $this->user()->can('salary.adjust') && $this->adjustmentEmployeeId ? AttendanceEmployee::findOrFail($this->adjustmentEmployeeId) : $this->requireEmployee();
        $recurring = $this->recurring && $this->kind !== 'increment';
        $until = $recurring && $this->recurringUntil !== '' ? \Illuminate\Support\Carbon::parse($this->recurringUntil.'-01')->endOfMonth()->toDateString() : null;
        $people->requestAdjustment($employee, (float) $this->amount, $this->kind, $this->effectiveOn, $this->reason, auth()->id(), $recurring, $until);
        $this->showAdjustment = false;
        $this->toast('Adjustment submitted for approval.');
    }

    public function decide(int $id, bool $approve, ApprovalInbox $inbox): void
    {
        $approval = ApprovalRequest::where('approvable_type', SalaryAdjustment::class)->where('approvable_id', $id)->where('status', 'pending')->firstOrFail();
        $inbox->decide($this->user(), $approval, $approve);
        $this->toast($approve ? 'Adjustment approved.' : 'Adjustment rejected.');
    }

    /** Ends a running recurring adjustment after the given month (from the next payroll month on, it no longer applies). */
    public function stopRecurring(int $id, AuditLogger $audit): void
    {
        abort_unless($this->user()->canAny(['salary.adjust', 'payroll.manage']), 403);
        $adjustment = SalaryAdjustment::where('is_recurring', true)->where('status', 'approved')->findOrFail($id);
        $lastMonth = today()->subMonth()->endOfMonth();
        $lockedThrough = PayrollRun::where('status', 'locked')->closingFor($adjustment->employee_id)->max('period_ends_on');
        if ($lockedThrough && substr((string) $lockedThrough, 0, 10) > $lastMonth->toDateString()) $lastMonth = \Illuminate\Support\Carbon::parse(substr((string) $lockedThrough, 0, 10))->endOfMonth();
        // Stopped before its first month: the last month falls before the start, so it never applies.
        app(\App\Services\PayrollLockGuard::class)->changed($adjustment->employee_id, $lastMonth->copy()->addDay()->toDateString(), 'salary adjustment', '2999-12-31');
        $adjustment->update(['recurring_until' => $lastMonth->toDateString()]);
        $audit->record('salary.adjustment_stopped', $adjustment, ['last_month' => $lastMonth->format('Y-m')]);
        $this->toast('Recurring adjustment stopped after '.$lastMonth->format('M Y').'.');
    }

    /** "Pay only for project days": who, which projects, from / until and why (see HrWorkflowService::projectDays). */
    public bool $showProjectRule = false;
    public ?int $prEmployeeId = null;
    /** @var array<int, int|string> */
    public array $prProjects = [];
    public string $prFrom = '';
    public string $prUntil = '';
    public string $prReason = '';

    public const PROJECT_DAYS_RULE = 'payroll.project_days';

    public function newProjectRule(): void
    {
        abort_unless($this->canManage(), 403);
        $this->fill(['showProjectRule' => true, 'prEmployeeId' => null, 'prProjects' => [], 'prFrom' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'prUntil' => '', 'prReason' => '']);
        $this->resetValidation();
    }

    /**
     * Saves the rule for the employee from the date (until the date, if given): payroll then pays only the days the
     * employee has a task of these projects. Draft payroll in that time is marked for recalculation; an approved or
     * locked payroll is refused (an administrator can reopen it).
     */
    public function saveProjectRule(PeopleOperationsService $people, AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate([
            'prEmployeeId' => ['required', 'exists:attendance_employees,id'],
            'prProjects' => ['required', 'array', 'min:1'], 'prProjects.*' => ['integer', 'exists:projects,id'],
            'prFrom' => ['required', 'date'], 'prUntil' => ['nullable', 'date', 'after_or_equal:prFrom'],
            'prReason' => ['required', 'string', 'min:3', 'max:255'],
        ], ['prProjects.required' => 'Tick at least one project.'], ['prEmployeeId' => 'employee', 'prProjects' => 'projects', 'prFrom' => 'from date', 'prUntil' => 'until date', 'prReason' => 'reason']);
        $until = $this->prUntil !== '' ? $this->prUntil : null;
        app(\App\Services\PayrollLockGuard::class)->changed((int) $this->prEmployeeId, $this->prFrom, 'salary', $until ?? '2999-12-31');
        $config = ['projects' => array_values(array_unique(array_map('intval', $this->prProjects))), 'reason' => trim($this->prReason)];
        $people->setPolicy(self::PROJECT_DAYS_RULE, 'payroll', 'Pay only for project days', $config, (int) $this->prEmployeeId, auth()->id(), $this->prFrom, $until);
        $employee = AttendanceEmployee::with('user')->find($this->prEmployeeId);
        $audit->record('payroll.project_days_set', $employee, ['projects' => $config['projects'], 'from' => $this->prFrom, 'until' => $until, 'reason' => $config['reason']]);
        $this->showProjectRule = false;
        $this->toast("{$employee->name} is now paid only for project days. Recalculate the draft payroll to see it.");
    }

    /** Stops the rule from today's month on (earlier months keep it); a rule that starts later is removed. */
    public function stopProjectRule(int $id, PeopleOperationsService $people): void
    {
        abort_unless($this->canManage(), 403);
        $row = \App\Models\EmployeePolicyOverride::where('rule_key', self::PROJECT_DAYS_RULE)->findOrFail($id);
        $from = today()->startOfMonth()->toDateString();
        app(\App\Services\PayrollLockGuard::class)->changed($row->employee_id, $from, 'salary', '2999-12-31');
        $people->stopOverride($row, $from, auth()->id());
        $this->toast('Stopped: from '.today()->format('M Y').' the full salary applies again.');
    }

    public function closeModal(): void
    {
        $this->profileEmployeeId = null;
        $this->correcting = false;
        $this->showAdjustment = false;
        $this->showProjectRule = false;
    }

    public function render(ApprovalInbox $inbox)
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        if ($own?->isOwner()) $own = null; // a company owner is not an employee: no "My salary", pending amount or salary history for him
        $employees = null;
        if ($this->canManage() || $user->can('payroll.approve')) {
            $employees = AttendanceEmployee::with(['salaryProfile', 'department'])->where('is_active', true)->onPayroll()
                ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_code', 'like', '%'.trim($this->search).'%')))
                ->inCodeOrder()->paginate(15);
        }
        $adjustments = SalaryAdjustment::with('employee')
            ->when(! $user->canAny(['salary.adjust', 'payroll.manage', 'payroll.approve']), fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->latest()->limit(30)->get();

        return $this->page('livewire.payroll.salary', [
            'employees' => $employees,
            'own' => $own,
            'myProfiles' => $own ? $own->salaryProfiles()->orderByDesc('effective_from')->get() : collect(),
            'mySoFar' => $own ? app(\App\Services\SalarySummaryService::class)->soFar($own) : null,
            'myHistory' => $own ? app(\App\Services\SalarySummaryService::class)->history($own) : [],
            'adjustments' => $adjustments,
            'projectRules' => $this->canManage() ? \App\Models\EmployeePolicyOverride::with('employee')->where('rule_key', self::PROJECT_DAYS_RULE)->orderByDesc('effective_from')->get() : collect(),
            'projectEmployees' => $this->canManage() ? AttendanceEmployee::where('is_active', true)->onPayroll()->orderBy('name')->get(['id', 'name']) : collect(),
            'projectNames' => $this->canManage() ? \App\Models\Project::orderBy('name')->pluck('name', 'id') : collect(),
            'decidable' => $inbox->pending($user, SalaryAdjustment::class)->pluck('approvable_id')->all(),
            'canManage' => $this->canManage(),
            'allEmployees' => $user->can('salary.adjust') ? AttendanceEmployee::where('is_active', true)->onPayroll()->orderBy('name')->get(['id', 'name']) : collect(),
            'editing' => $this->profileEmployeeId ? AttendanceEmployee::find($this->profileEmployeeId) : null,
            // Days one month's salary is divided by — auto from the calendar every month, or the fixed number.
            'salaryDays' => $employees ? $employees->getCollection()->filter->salaryProfile->mapWithKeys(fn ($e) => [$e->id => app(\App\Services\HrWorkflowService::class)->salaryDaysFor($e, today()->toDateString(), $e->salaryProfile)])->all() : [],
            'autoDays' => $this->profileEmployeeId && ($e = AttendanceEmployee::find($this->profileEmployeeId)) && ($d = app(\App\Services\HrWorkflowService::class)->salaryDaysFor($e, today()->toDateString()))['auto'] ? $d : null,
        ], 'Salary');
    }
}
