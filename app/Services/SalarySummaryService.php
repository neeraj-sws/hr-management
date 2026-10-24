<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\LegacySalaryRecord;
use App\Models\PayrollItem;
use App\Support\Duration;
use Illuminate\Support\Carbon;

/**
 * An employee's own salary, as they can follow it:
 *  - this month so far (what payroll would pay if the month ended today), and
 *  - month-by-month history (approved / locked payroll, and months from the old salary sheet),
 * each with every earning and deduction on its own line.
 *
 * A month: ['month' => 'Y-m', 'label', 'source' => estimate|payroll|old_sheet, 'status', 'days' => [...],
 *           'earnings' => [[label, amount, note]], 'deductions' => [[label, amount, note]],
 *           'gross', 'deductions_total', 'net', 'paid', 'remaining', 'payslip_id'].
 */
class SalarySummaryService
{
    public function __construct(private readonly HrWorkflowService $payroll) {}

    /** This month from the 1st up to today, as payroll would work it out now. Null without a salary record. */
    public function soFar(AttendanceEmployee $employee, ?Carbon $today = null): ?array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $start = $today->copy()->startOfMonth();
        $estimate = $this->payroll->payEstimate($employee, $start->toDateString(), $today->toDateString());
        if (! $estimate) return null;

        $month = $this->fromBreakdown($start->format('Y-m'), 'estimate', 'so far', $estimate['breakdown'], $estimate['gross'], $estimate['net'], 0.0, [
            'working' => $estimate['working_days'], 'present' => $estimate['present_days'], 'paid_leave' => $estimate['paid_leave_days'], 'unpaid_leave' => $estimate['unpaid_leave_days'],
        ], $estimate);
        $month['up_to'] = $today->toDateString();

        return $month;
    }

    /** Past months, newest first: approved / locked payroll, else the old salary sheet. */
    public function history(AttendanceEmployee $employee, int $months = 24): array
    {
        $items = PayrollItem::with(['run', 'payments', 'payslip'])->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'locked']))->get()
            ->keyBy(fn ($i) => $i->run->period_starts_on->format('Y-m'));
        $sheets = LegacySalaryRecord::where('employee_id', $employee->id)->get()->keyBy('payroll_month');
        $keys = $items->keys()->merge($sheets->keys())->unique()->sortDesc()->take($months);

        return $keys->map(function ($key) use ($items, $sheets) {
            $item = $items->get($key);
            // A payroll item built from the old sheet shows the sheet's own lines.
            if ($item && empty($item->calculation_breakdown['old_sheet'])) return $this->fromItem($item);
            $sheet = $sheets->get($key);

            return $sheet ? $this->fromSheet($sheet, $item) : $this->fromItem($item);
        })->values()->all();
    }

    private function fromItem(PayrollItem $item): array
    {
        $b = (array) $item->calculation_breakdown;
        $paid = round((float) $item->payments->sum('amount') + (float) ($b['old_sheet']['paid'] ?? 0), 2);

        return [...$this->fromBreakdown($item->run->period_starts_on->format('Y-m'), 'payroll', $item->run->status, $b, (float) $item->gross_amount, (float) $item->net_amount, $paid, [
            'working' => (int) $item->working_days, 'present' => (float) $item->present_days, 'paid_leave' => (float) $item->paid_leave_days, 'unpaid_leave' => (float) $item->unpaid_leave_days,
        ], (array) $item->calculation_data), 'payslip_id' => $item->payslip?->id, 'paid_on' => $item->payments->max('paid_on')?->toDateString()];
    }

    private function fromBreakdown(string $month, string $source, string $status, array $b, float $gross, float $net, float $paid, array $days, array $data): array
    {
        $money = fn ($v) => round((float) $v, 2);
        $daysPaid = (float) $days['present'] + (float) $days['paid_leave'] + (float) ($b['paid_holiday_days'] ?? 0);
        $earnings = [];
        $earnings[] = ['Salary for days worked', $money($b['base_salary'] ?? 0), ($data['pay_basis'] ?? 'monthly') === 'daily'
            ? number_format((float) ($data['daily_rate'] ?? 0), 2).' × '.$days['present'].' days present'
            : $days['present'].' present'.($days['paid_leave'] ? ' + '.$days['paid_leave'].' paid leave' : '').(($b['paid_holiday_days'] ?? 0) ? ' + '.$b['paid_holiday_days'].' paid holiday' : '').' of '.$days['working'].' working days'];
        if ((float) ($b['extra_work']['amount'] ?? 0) > 0) $earnings[] = ['Extra working (off days)', $money($b['extra_work']['amount']), ($b['extra_work']['days'] ?? 0).' days · '.Duration::hms((int) ($b['extra_work']['seconds'] ?? 0))];
        if ((float) ($b['overtime_amount'] ?? 0) > 0) $earnings[] = ['Overtime', $money($b['overtime_amount']), null];
        $deductions = [];
        foreach ((array) ($b['salary_adjustment_lines'] ?? []) as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if ($amount > 0) $earnings[] = [$line['label'] ?? 'Adjustment', $money($amount), $line['reason'] ?? null];
            elseif ($amount < 0) $deductions[] = [$line['label'] ?? 'Adjustment', $money(-$amount), $line['reason'] ?? null];
        }
        foreach ((array) ($b['statutory_components'] ?? []) as $c) {
            if ((float) ($c['amount'] ?? 0) <= 0) continue;
            if (($c['type'] ?? '') === 'earning') $earnings[] = [$c['name'], $money($c['amount']), null]; else $deductions[] = [$c['name'], $money($c['amount']), null];
        }
        if ((float) ($b['short_hours']['amount'] ?? 0) > 0) $deductions[] = ['Short working hours', $money($b['short_hours']['amount']), Duration::hms((int) ($b['short_hours']['short_seconds'] ?? 0)).' below your working hours'];
        if ((float) ($b['penalties'] ?? 0) > 0) $deductions[] = ['Penalties', $money($b['penalties']), null];
        if ((float) ($b['advance_recovery'] ?? 0) > 0) $deductions[] = ['Salary advance recovery', $money($b['advance_recovery']), null];
        foreach ((array) ($b['fixed_deductions'] ?? []) as $d) if ((float) ($d['amount'] ?? 0) > 0) $deductions[] = [$d['name'] ?? 'Fixed deduction', $money($d['amount']), 'every month'];
        if ($days['unpaid_leave'] > 0) $deductions[] = ['Unpaid leave', 0.0, $days['unpaid_leave'].' days not paid (already left out of the salary for days worked)'];
        if ((float) ($b['reimbursements'] ?? 0) > 0) $earnings[] = ['Expense reimbursements', $money($b['reimbursements']), 'approved claims, paid on top'];

        return ['month' => $month, 'label' => Carbon::parse($month.'-01')->format('F Y'), 'source' => $source, 'status' => $status, 'days' => $days,
            'monthly_salary' => $money($data['monthly_salary'] ?? 0),
            // Pay for one day: the daily rate, or what was paid for the days worked divided by those days.
            'per_day_rate' => $money((float) ($data['daily_rate'] ?? 0) ?: ($daysPaid > 0 ? (float) ($b['base_salary'] ?? 0) / $daysPaid : 0)),
            'days_paid' => $daysPaid,
            'earnings' => $earnings, 'deductions' => $deductions,
            'gross' => $money($gross), 'deductions_total' => $money(array_sum(array_column($deductions, 1))), 'net' => $money($net),
            'paid' => $money($paid), 'remaining' => $money(max(0, $net - $paid)), 'payslip_id' => null];
    }

    private function fromSheet(LegacySalaryRecord $s, ?PayrollItem $item): array
    {
        $money = fn ($v) => round((float) $v, 2);
        $deductions = array_values(array_filter([
            ['Late coming', $money($s->late_coming_deduction), $s->late_and_hourly_leave_minutes ? (float) $s->late_and_hourly_leave_minutes.' min late / hourly leave' : null],
            ['Late coming adjusted', -$money($s->late_coming_adjusted), null],
            ['Leave without application', $money($s->without_application_deduction), $s->without_application_leave ? (float) $s->without_application_leave.' days' : null],
            ['Short leave without application', $money($s->short_leave_deduction), null],
            ['TDS', $money($s->tds), null],
        ], fn ($l) => $l[1] != 0));
        $earnings = array_values(array_filter([
            ['Salary for days paid', $money($s->salary_before_adjustment), (float) $s->total_salary_paid_days.' days × '.number_format((float) $s->salary_per_day, 2)],
            ['Increment / bonus', $money($s->increment_bonus), null],
        ], fn ($l) => $l[1] != 0));
        $net = $money($s->total_salary_with_bonus ?? $s->salary_after_deduction);
        $paid = $money($s->salary_paid);

        return ['month' => $s->payroll_month, 'label' => Carbon::parse($s->payroll_month.'-01')->format('F Y'), 'source' => 'old_sheet', 'status' => $s->payment_status ?: 'old sheet',
            'days' => ['working' => (float) $s->total_working_days, 'present' => (float) $s->present_days, 'paid_leave' => (float) $s->paid_leave, 'unpaid_leave' => max(0, (float) $s->leave_days - (float) $s->paid_leave)],
            'monthly_salary' => $money($s->basic_salary), 'per_day_rate' => $money($s->salary_per_day), 'days_paid' => (float) $s->total_salary_paid_days, 'earnings' => $earnings, 'deductions' => $deductions,
            'gross' => $money((float) $s->salary_before_adjustment + (float) $s->increment_bonus), 'deductions_total' => $money($s->total_payment_deduction), 'net' => $net,
            'paid' => $paid, 'remaining' => $money(max(0, (float) $s->salary_remains)), 'payslip_id' => $item?->payslip?->id, 'paid_on' => $s->payment_date?->toDateString()];
    }
}
