<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\SalaryProfile;
use Illuminate\Validation\ValidationException;

/**
 * Splits a monthly salary into components (Basic, HRA, allowances…) and
 * fixed monthly deductions. Order of precedence: the employee's own salary
 * profile → the company template (Settings → Payroll) → everything as Basic.
 * No statutory percentages are built in; the employer enters every number.
 */
class SalaryStructureService
{
    public function __construct(private readonly PolicyConfigurationService $policies) {}

    /** @return array<int, array{name: string, type: string, percent?: float, amount?: float}> */
    public function template(?AttendanceEmployee $employee = null, ?string $asOf = null): array
    {
        $policy = $this->policies->forEmployee($employee, 'payroll.salary_structure', $asOf ?? today()->toDateString(), ['components' => []]);

        return array_values(array_filter($policy['components'] ?? [], fn ($c) => is_array($c) && ! empty($c['name']) && in_array($c['type'] ?? '', ['earning', 'deduction'], true)));
    }

    /**
     * Monthly amounts for a profile.
     *
     * @return array<int, array{name: string, type: string, amount: float}>
     */
    public function components(SalaryProfile $profile, ?AttendanceEmployee $employee = null, ?string $asOf = null): array
    {
        $monthly = (float) $profile->monthly_salary;
        $own = collect($profile->components ?? [])->filter(fn ($c) => ! empty($c['name']))->map(fn ($c) => ['name' => (string) $c['name'], 'type' => $c['type'] === 'deduction' ? 'deduction' : 'earning', 'amount' => round((float) ($c['amount'] ?? 0), 2)])->values();
        if ($own->where('type', 'earning')->isNotEmpty()) return $own->all();

        $rows = [];
        foreach ($this->template($employee ?? $profile->employee, $asOf) as $c) {
            $rows[] = $c['type'] === 'earning'
                ? ['name' => $c['name'], 'type' => 'earning', 'amount' => round($monthly * (float) ($c['percent'] ?? 0) / 100, 2)]
                : ['name' => $c['name'], 'type' => 'deduction', 'amount' => round((float) ($c['amount'] ?? 0), 2)];
        }
        $earned = array_sum(array_column(array_filter($rows, fn ($r) => $r['type'] === 'earning'), 'amount'));
        if (! $rows || $earned <= 0) $rows = array_merge([['name' => 'Basic', 'type' => 'earning', 'amount' => $monthly]], array_values(array_filter($rows, fn ($r) => $r['type'] === 'deduction')));
        elseif (abs($monthly - $earned) >= 0.01) $rows[] = ['name' => 'Balance allowance', 'type' => 'earning', 'amount' => round($monthly - $earned, 2)];

        return $rows;
    }

    /** Validates an employee-specific structure against the monthly salary. */
    public function validate(array $components, float $monthly, string $field = 'components'): array
    {
        $clean = collect($components)->filter(fn ($c) => trim((string) ($c['name'] ?? '')) !== '' || (string) ($c['amount'] ?? '') !== '')->values();
        foreach ($clean as $i => $c) {
            if (trim((string) ($c['name'] ?? '')) === '') throw ValidationException::withMessages(["{$field}.{$i}.name" => 'Give the component a name.']);
            if (! is_numeric($c['amount'] ?? null) || (float) $c['amount'] < 0) throw ValidationException::withMessages(["{$field}.{$i}.amount" => 'Enter an amount of 0 or more.']);
        }
        $rows = $clean->map(fn ($c) => ['name' => trim((string) $c['name']), 'type' => ($c['type'] ?? 'earning') === 'deduction' ? 'deduction' : 'earning', 'amount' => round((float) $c['amount'], 2)])->all();
        $earnings = round(array_sum(array_column(array_filter($rows, fn ($r) => $r['type'] === 'earning'), 'amount')), 2);
        if ($rows && abs($earnings - round($monthly, 2)) >= 0.01) {
            throw ValidationException::withMessages([$field => 'Earnings add up to '.number_format($earnings, 2).'; they must equal the monthly salary of '.number_format($monthly, 2).'.']);
        }

        return $rows;
    }
}
