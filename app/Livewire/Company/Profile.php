<?php

namespace App\Livewire\Company;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Company;
use App\Models\LeaveType;
use App\Models\PolicyRule;
use App\Services\PolicyConfigurationService;
use App\Support\CompanyAccess;
use Livewire\Component;

/**
 * Company profile: one page per company with the rules it works by (salary, calendar, penalties, leave types) as they
 * stand today, and whether each is the company's own or the rule for all companies. Read only: "Edit rules" opens
 * Settings on that company. The Super Admin gets the list of companies first; a login limited to one company gets its own.
 */
class Profile extends Component
{
    use InteractsWithHr;

    public ?int $companyId = null;

    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function mount(?Company $company = null): void
    {
        $limited = CompanyAccess::limitedCompanyId();
        $this->companyId = $limited ?? $company?->id;
    }

    /** True when this company has its own saved rule for any of the keys (otherwise it follows the rule for all companies). */
    private function hasOwn(int $companyId, array $keys): bool
    {
        return PolicyRule::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('rule_key', $keys)->where('is_active', true)->exists();
    }

    private function ordinal(int $n): string
    {
        return $n.match ($n) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
    }

    /** @return array<int, array{title: string, own: bool, tab: string, lines: array<int, array{0: string, 1: string}>}> */
    private function sections(Company $company): array
    {
        $policies = app(PolicyConfigurationService::class);
        $today = today()->toDateString();
        $rule = fn (string $key, array $fallback = []) => $policies->forCompany($company->id, $key, $today, $fallback);
        $rs = fn ($v) => '₹'.number_format((float) $v, 2);
        $yes = fn ($v) => $v ? 'Yes' : 'No';

        $pay = $rule('payroll.calculation');
        $divisor = $pay['divisor'] ?? 'fixed';
        $salary = [
            ['Per-day salary', match ($divisor) {
                'month_days' => 'Monthly salary ÷ the days of the month (30 / 31): every day is paid, only absent days are cut',
                'calendar' => "Monthly salary ÷ that month's working days from the calendar",
                default => 'Monthly salary ÷ '.(int) ($pay['working_days_per_month'] ?? 26).' days (fixed)',
            }],
            ['Absent days cut the pay', $yes($pay['attendance_proration'] ?? true)],
            ['Approved paid leave counts as paid', $yes($pay['include_paid_leave'] ?? true)],
            ['Half day pays half a day', $yes($pay['half_day_pay'] ?? false)],
            ['Late check-in paid by hours worked', $yes($pay['late_checkin_hours_pay'] ?? false)],
            ['Short hours deducted', $yes($pay['short_hours_deduction'] ?? false)],
            ['Extra working paid automatically', $yes($pay['extra_work_auto'] ?? true)],
        ];
        $ot = $rule('overtime.multiplier');
        $salary[] = ['Overtime', ! empty($ot['multiplier']) ? 'Paid at '.$ot['multiplier'].' × the hourly rate' : 'Not set'];
        $statutory = (array) ($rule('payroll.statutory', ['components' => []])['components'] ?? []);
        $salary[] = ['PF / ESI / TDS and other components', $statutory ? collect($statutory)->pluck('name')->filter()->join(', ') : 'None'];

        $calendar = $rule('calendar.working_days', ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        $working = collect((array) ($calendar['days'] ?? []))->map(fn ($d) => self::DAYS[(int) $d] ?? null)->filter()->join(', ');
        $offWeeks = collect((array) ($calendar['off_weeks'] ?? []))->map(fn ($weeks, $day) => collect((array) $weeks)->map(fn ($n) => $this->ordinal((int) $n))->join(', ').' '.(self::DAYS[(int) $day] ?? '').' of the month')->values();
        $calendarLines = [
            ['Working days', $working ?: '—'],
            ['Weekly offs that follow a week of the month', $offWeeks->isNotEmpty() ? $offWeeks->join('; ').' off' : 'None (no 2nd / 3rd Saturday rule)'],
            ['Holidays listed', (string) count((array) ($calendar['holidays'] ?? []))],
        ];

        $late = $rule('attendance.late_penalty');
        $early = $rule('attendance.early_checkout');
        $absence = $rule('attendance.unauthorized_absence');
        $fine = function (array $r, string $per) use ($rs) {
            if ((float) ($r['fixed_amount'] ?? 0) > 0) return $rs($r['fixed_amount']).' a day';
            if (! empty($r['salary_rate'])) return "Salary per {$per} minute";
            if ((float) ($r['amount_per_minute'] ?? 0) > 0) return $rs($r['amount_per_minute']).' a '.$per.' minute';

            return 'None';
        };
        $penalty = [
            ['Late attendance', $fine($late, 'late').((int) ($late['grace_minutes'] ?? 0) ? ', after '.(int) $late['grace_minutes'].' min grace' : '')],
            ['Early check-out', $fine($early, 'early').((int) ($early['grace_minutes'] ?? 0) ? ', after '.(int) $early['grace_minutes'].' min grace' : '')],
            ['Absent without leave', (float) ($absence['amount_per_day'] ?? 0) > 0 ? $rs($absence['amount_per_day']).' fine a day, on top of the unpaid day' : 'The day is not paid, no extra fine'],
        ];

        $types = LeaveType::withoutGlobalScopes()->where('is_active', true)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->orderBy('name')->get(['name', 'company_id', 'annual_allowance', 'paid']);
        $leave = $types->map(fn ($t) => [$t->name.($t->company_id ? '' : ' (shared)'), rtrim(rtrim(number_format((float) $t->annual_allowance, 2), '0'), '.').' days a year · '.($t->paid ? 'paid' : 'unpaid')])->all() ?: [['—', 'No leave types']];

        return [
            ['title' => 'Salary', 'own' => $this->hasOwn($company->id, ['payroll.calculation', 'overtime.multiplier', 'payroll.statutory', 'payroll.salary_structure']), 'tab' => 'payroll', 'lines' => $salary],
            ['title' => 'Working calendar', 'own' => $this->hasOwn($company->id, ['calendar.working_days']), 'tab' => 'calendar', 'lines' => $calendarLines],
            ['title' => 'Penalties (fines)', 'own' => $this->hasOwn($company->id, ['attendance.late_penalty', 'attendance.early_checkout', 'attendance.unauthorized_absence']), 'tab' => 'payroll', 'lines' => $penalty],
            ['title' => 'Leave types', 'own' => $types->contains(fn ($t) => $t->company_id !== null), 'tab' => 'leave', 'lines' => $leave],
        ];
    }

    public function render()
    {
        $company = $this->companyId ? Company::find($this->companyId) : null;
        abort_if($this->companyId && ! $company, 404);
        $canEdit = $this->user()->can('policies.manage');

        if (! $company) {
            // The Super Admin: every company, with whether it has rules of its own.
            $companies = Company::orderByDesc('is_main')->orderBy('name')->get()->each(function (Company $c) {
                $c->setAttribute('employees', AttendanceEmployee::withoutGlobalScopes()->where('is_active', true)->where(fn ($q) => $c->is_main ? $q->whereNull('company_id')->orWhere('company_id', $c->id) : $q->where('company_id', $c->id))->count());
                $c->setAttribute('own_rules', $this->hasOwn($c->id, PolicyConfigurationService::COMPANY_KEYS));
            });

            return $this->page('livewire.company.profile', ['company' => null, 'companies' => $companies, 'sections' => [], 'canEdit' => $canEdit, 'single' => false], 'Companies');
        }

        return $this->page('livewire.company.profile', ['company' => $company, 'companies' => collect(), 'sections' => $this->sections($company), 'canEdit' => $canEdit, 'single' => CompanyAccess::isLimited($this->user())], $company->name);
    }
}
