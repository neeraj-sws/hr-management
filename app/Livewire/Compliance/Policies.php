<?php

namespace App\Livewire\Compliance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\EmployeePolicyOverride;
use App\Models\PolicyRule;
use App\Services\PeopleOperationsService;
use App\Services\PolicyConfigurationService;
use Livewire\Component;

/**
 * Configurable compliance rules. Amounts are employer-defined; zero disables a
 * rule. Assessments create pending penalties that still need approval.
 */
class Policies extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    public const RULES = [
        'attendance.late_penalty' => ['Late attendance', ['salary_rate' => 'Deduct at the employee\'s salary per minute', 'amount_per_minute' => 'Amount per late minute', 'grace_minutes' => 'Grace minutes', 'max_amount' => 'Maximum per day', 'fixed_amount' => 'Fixed amount per late day (0 = per minute above)', 'deduct_now' => 'Deduct automatically', 'hours_cut' => 'Also cut the hours not worked']],
        'attendance.unauthorized_absence' => ['Unauthorized absence (no leave mail)', ['amount_per_day' => 'Amount per absent workday', 'also_fine' => 'Also fine on top of the unpaid day', 'auto_deduct' => 'Deduct automatically']],
        'task.late_penalty' => ['Late / overdue tasks', ['amount_per_day' => 'Amount per overdue workday', 'max_amount' => 'Maximum per task']],
        'attendance.early_checkout' => ['Early check-out', ['salary_rate' => 'Deduct at the employee\'s salary per minute', 'amount_per_minute' => 'Amount per early minute', 'grace_minutes' => 'Grace minutes', 'max_amount' => 'Maximum per day']],
        'task.missing_entry' => ['No task entered for a worked day', ['amount_per_day' => 'Amount per day']],
        'task.late_entry' => ['Task entered late (after the day it was for)', ['amount' => 'Amount per late task', 'grace_hours' => 'Grace hours after the day ends']],
        'daily_status.missing' => ['Daily status not submitted', ['amount_per_day' => 'Amount per day', 'grace_hours' => 'Grace hours after the day ends']],
        // Leave asked for too late: fewer days before it than the notice (a past day is always late). Pending: needs approval.
        'leave.late_application' => ['Leave applied late (short notice or after the day)', ['amount' => 'Amount per late leave request', 'notice_days' => 'Days of notice needed (0 = only past days)']],
    ];

    /** Values a field starts with when the rule has never been saved with it. */
    public const DEFAULTS = ['auto_deduct' => '1', 'also_fine' => '0', 'deduct_now' => '0', 'hours_cut' => '0'];

    /** Rules that can also be set per team or per employee (compliance rules plus OT, leave advance and screen capture). */
    public const OVERRIDABLE = self::RULES + [
        'overtime.multiplier' => ['Overtime', ['multiplier' => 'Multiplier', 'holiday_multiplier' => 'Holiday / weekly-off multiplier', 'max_minutes_per_day' => 'Max minutes per day', 'max_minutes_per_month' => 'Max minutes per month']],
        'leave.advance_limit' => ['Advance paid leave', ['max_days' => 'Maximum advance days']],
        // Field / mobile staff (calls, visits, work on the phone): the computer standing untouched is not
        // taken off their working time; idle is still shown as information. See AttendanceSession::isFieldWork().
        \App\Models\AttendanceSession::WORK_TYPE_POLICY => ['Work type (desk / field-mobile)', ['field' => 'Work type']],
    ];

    /** Form values keyed by slot() (rule keys contain dots, which Livewire would read as nesting). */
    public array $values = [];
    public string $overrideScope = 'employee';
    public ?int $overrideTeamId = null;
    public string $assessFrom = '';
    public string $assessTo = '';
    public ?int $overrideEmployeeId = null;
    /** "Several employees": the same rule saved for each one ticked. */
    public array $overrideEmployeeIds = [];
    /** Editing an existing employee / team rule in the form ("employee:5" or "team:2"); empty = a new rule. */
    public string $editing = '';
    /** Stopping an employee / team rule from a date ("employee:5" or "team:2"). */
    public string $stopping = '';
    public string $stopFrom = '';
    public string $overrideKey = 'attendance.late_penalty';
    public array $overrideValues = [];
    public string $overrideFrom = '';

    public function mount(PolicyConfigurationService $policies): void
    {
        foreach (self::RULES as $key => [, $fields]) {
            $config = $policies->forEmployee(null, $key, today()->toDateString(), []);
            foreach ($fields as $field => $label) $this->values[self::slot($key)][$field] = (string) ($config[$field] ?? self::DEFAULTS[$field] ?? '0');
        }
        $this->assessFrom = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $this->assessTo = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $this->overrideFrom = today()->toDateString();
    }

    /** 'daily_status.missing' => 'daily_status__missing' */
    public static function slot(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function saveRule(string $key, PeopleOperationsService $people): void
    {
        abort_unless($this->user()->can('policies.manage'), 403);
        abort_unless(isset(self::RULES[$key]), 404);
        $rules = [];
        $slot = self::slot($key);
        foreach (self::RULES[$key][1] as $field => $label) $rules["values.{$slot}.{$field}"] = ['required', 'numeric', 'min:0', 'max:1000000'];
        $this->validate($rules, [], collect(self::RULES[$key][1])->mapWithKeys(fn ($label, $field) => ["values.{$slot}.{$field}" => strtolower($label)])->all());
        $people->setPolicy($key, 'compliance', self::RULES[$key][0], array_map('floatval', array_intersect_key($this->values[$slot], self::RULES[$key][1])), null, auth()->id(), today()->toDateString());
        $this->toast(self::RULES[$key][0].' rule saved (effective today).');
    }

    /** "employee:5" / "team:2" -> the override row. */
    private function overrideRow(string $ref): EmployeePolicyOverride|\App\Models\TeamPolicyOverride
    {
        [$kind, $id] = array_pad(explode(':', $ref, 2), 2, 0);

        return $kind === 'team' ? \App\Models\TeamPolicyOverride::findOrFail((int) $id) : EmployeePolicyOverride::findOrFail((int) $id);
    }

    /** Loads a rule into the form to change its values or start date. */
    public function editOverride(string $ref): void
    {
        abort_unless($this->user()->can('policies.manage'), 403);
        $row = $this->overrideRow($ref);
        abort_unless(isset(self::OVERRIDABLE[$row->rule_key]), 404);
        $team = $row instanceof \App\Models\TeamPolicyOverride;
        $this->resetValidation();
        $this->fill([
            'editing' => $ref, 'overrideScope' => $team ? 'team' : 'employee', 'overrideKey' => $row->rule_key,
            'overrideTeamId' => $team ? $row->team_id : null, 'overrideEmployeeId' => $team ? null : $row->employee_id, 'overrideEmployeeIds' => [],
            'overrideFrom' => $row->effective_from?->toDateString() ?? today()->toDateString(),
            'overrideValues' => collect(self::OVERRIDABLE[$row->rule_key][1])->mapWithKeys(fn ($label, $field) => [$field => (string) (float) ($row->configuration[$field] ?? self::DEFAULTS[$field] ?? 0)])->all(),
        ]);
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'overrideEmployeeId', 'overrideEmployeeIds', 'overrideTeamId', 'overrideValues');
        $this->overrideFrom = today()->toDateString();
        $this->resetValidation();
    }

    public function openStop(string $ref): void
    {
        abort_unless($this->user()->can('policies.manage'), 403);
        $this->overrideRow($ref);
        $this->fill(['stopping' => $ref, 'stopFrom' => today()->toDateString()]);
        $this->resetValidation();
    }

    public function saveStop(PeopleOperationsService $people): void
    {
        abort_unless($this->user()->can('policies.manage'), 403);
        $this->validate(['stopFrom' => ['required', 'date']], [], ['stopFrom' => 'date']);
        $people->stopOverride($this->overrideRow($this->stopping), $this->stopFrom, auth()->id());
        if ($this->editing === $this->stopping) $this->cancelEdit();
        $this->stopping = '';
        $this->toast('Stopped from '.\Illuminate\Support\Carbon::parse($this->stopFrom)->format('d M Y').': the global rule applies again from that day.');
    }

    public function closeStop(): void
    {
        $this->stopping = '';
    }

    public function saveOverride(PeopleOperationsService $people): void
    {
        abort_unless($this->user()->can('policies.manage'), 403);
        if ($this->editing) $this->overrideKey = $this->overrideRow($this->editing)->rule_key;
        abort_unless(isset(self::OVERRIDABLE[$this->overrideKey]), 404);
        $rules = match ($this->overrideScope) {
            'team' => ['overrideTeamId' => ['required', 'exists:teams,id'], 'overrideFrom' => ['required', 'date']],
            'employees' => ['overrideEmployeeIds' => ['required', 'array', 'min:1'], 'overrideEmployeeIds.*' => ['integer', 'exists:attendance_employees,id'], 'overrideFrom' => ['required', 'date']],
            default => ['overrideEmployeeId' => ['required', 'exists:attendance_employees,id'], 'overrideFrom' => ['required', 'date']],
        };
        foreach (self::OVERRIDABLE[$this->overrideKey][1] as $field => $label) $rules["overrideValues.{$field}"] = in_array($field, ['field', 'deduct_now', 'hours_cut'], true) ? ['required', 'in:0,1'] : ['required', 'numeric', 'min:0', 'max:1000000'];
        $this->validate($rules, ['overrideEmployeeIds.required' => 'Tick at least one employee.'], ['overrideEmployeeId' => 'employee', 'overrideEmployeeIds' => 'employees', 'overrideTeamId' => 'team']);
        $config = array_map('floatval', array_intersect_key($this->overrideValues, self::OVERRIDABLE[$this->overrideKey][1]));
        if ($this->editing) {
            $people->updateOverride($this->overrideRow($this->editing), $config, $this->overrideFrom, auth()->id());
            $this->cancelEdit();
            $this->toast('Rule changed.');
            return;
        }
        if ($this->overrideScope === 'team') {
            $people->setTeamPolicy($this->overrideKey, $config, (int) $this->overrideTeamId, auth()->id(), $this->overrideFrom);
            $this->reset('overrideTeamId', 'overrideValues');
            $this->toast('Team rule saved. Employee overrides still take priority.');
            return;
        }
        $ids = $this->overrideScope === 'employees' ? array_values(array_unique(array_map('intval', $this->overrideEmployeeIds))) : [(int) $this->overrideEmployeeId];
        foreach ($ids as $id) $people->setPolicy($this->overrideKey, 'compliance', self::OVERRIDABLE[$this->overrideKey][0], $config, $id, auth()->id(), $this->overrideFrom);
        $this->reset('overrideEmployeeId', 'overrideEmployeeIds', 'overrideValues');
        $this->toast(count($ids) > 1 ? 'Employee-specific rule saved for '.count($ids).' employees.' : 'Employee-specific rule saved.');
    }

    public function assess(PeopleOperationsService $people): void
    {
        abort_unless($this->user()->canAny(['policies.manage', 'penalties.manage']), 403);
        $this->validate(['assessFrom' => ['required', 'date'], 'assessTo' => ['required', 'date', 'after_or_equal:assessFrom', 'before_or_equal:today']], [], ['assessFrom' => 'from date', 'assessTo' => 'to date']);
        $count = $people->assessCompliance($this->assessFrom, $this->assessTo, auth()->id());
        $this->toast($count ? "{$count} penalty requests created and sent for approval." : 'No new violations found for this period.');
    }

    public function render()
    {
        return $this->page('livewire.compliance.policies', [
            'rules' => self::RULES,
            // Overtime switched off in Settings > Payroll: no per-person overtime rule to set.
            'overridable' => app(PeopleOperationsService::class)->overtimeEnabled() ? self::OVERRIDABLE : array_diff_key(self::OVERRIDABLE, ['overtime.multiplier' => true]),
            'teamOverrides' => \App\Models\TeamPolicyOverride::with('team')->whereIn('rule_key', array_keys(self::OVERRIDABLE))->latest()->get(),
            'teams' => \App\Models\Team::orderBy('name')->get(['id', 'name']),
            'canConfigure' => $this->user()->can('policies.manage'),
            'overrides' => EmployeePolicyOverride::whereIn('rule_key', array_keys(self::OVERRIDABLE))->with('employee')->latest()->paginate(15, ['*'], 'overridesPage'),
            'history' => PolicyRule::whereIn('rule_key', array_keys(self::RULES))->latest('effective_from')->latest('id')->limit(15)->get(),
            'employees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ], 'Compliance');
    }
}
