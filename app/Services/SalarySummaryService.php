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

        $month = $this->fromBreakdown($start->format('Y-m'), 'estimate', 'so far', [...$estimate['breakdown'], '_days' => $this->dayList($employee, $start, $today), '_employee_id' => $employee->id], $estimate['gross'], $estimate['net'], 0.0, [
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

    /** One payroll row as the employee sees it (payslip, payroll breakdown), with "why less than full salary". */
    public function forItem(PayrollItem $item): array
    {
        return $this->fromItem($item->loadMissing(['run', 'payments', 'payslip', 'employee']));
    }

    private function fromItem(PayrollItem $item): array
    {
        $b = (array) $item->calculation_breakdown;
        if ($item->employee && empty($b['old_sheet'])) $b['_days'] = $this->dayList($item->employee, $item->run->period_starts_on->copy(), $item->run->period_ends_on->copy());
        $b['_employee_id'] = $item->employee_id;
        $paid = round((float) $item->payments->sum('amount') + (float) ($b['old_sheet']['paid'] ?? 0), 2);

        return [...$this->fromBreakdown($item->run->period_starts_on->format('Y-m'), 'payroll', $item->run->status, $b, (float) $item->gross_amount, (float) $item->net_amount, $paid, [
            'working' => (int) $item->working_days, 'present' => (float) $item->present_days, 'paid_leave' => (float) $item->paid_leave_days, 'unpaid_leave' => (float) $item->unpaid_leave_days,
        ], (array) $item->calculation_data), 'payslip_id' => $item->payslip?->id, 'paid_on' => $item->payments->max('paid_on')?->toDateString()];
    }

    private function fromBreakdown(string $month, string $source, string $status, array $b, float $gross, float $net, float $paid, array $days, array $data): array
    {
        $money = fn ($v) => round((float) $v, 2);
        $halfDays = array_values((array) ($b['half_days'] ?? []));
        $halfNote = $halfDays ? ' ('.count($halfDays).' half '.(count($halfDays) === 1 ? 'day' : 'days').': '.collect($halfDays)->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M'))->join(', ').', paid half)' : '';
        $halfHolidays = ($data['pay_basis'] ?? 'monthly') === 'daily' ? [] : array_values((array) ($b['half_holiday_paid'] ?? []));
        $byHours = (array) ($b['hours_paid_days'] ?? []);
        $shortfall = $this->shortfall($month, $source, $b, $days, $data);
        $byHoursCut = array_sum(array_map(fn ($d) => 1 - (float) ($d['share'] ?? 1), $byHours));
        if ($byHours) $halfNote .= ' ('.self::byHoursNote($byHours).')';
        $daysPaid = (float) $days['present'] - 0.5 * count($halfDays) - $byHoursCut + (float) $days['paid_leave'] + (float) ($b['paid_holiday_days'] ?? 0) + 0.5 * count($halfHolidays);
        $earnings = [];
        $earnings[] = ['Salary for days worked', $money($b['base_salary'] ?? 0), ($data['pay_basis'] ?? 'monthly') === 'daily'
            ? number_format((float) ($data['daily_rate'] ?? 0), 2).' × '.round((float) $days['present'] - 0.5 * count($halfDays) - $byHoursCut, 2).' days present'.$halfNote
            : $days['present'].' present'.$halfNote.($days['paid_leave'] ? ' + '.$days['paid_leave'].' paid leave' : '').(($b['paid_holiday_days'] ?? 0) ? ' + '.$b['paid_holiday_days'].' paid holiday' : '').($halfHolidays ? ' + ½ day for '.count($halfHolidays).' half-day holiday not at work ('.collect($halfHolidays)->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M'))->join(', ').')' : '').' of '.$days['working'].' working days'];
        if ((float) ($b['extra_work']['amount'] ?? 0) > 0) $earnings[] = ['Extra working (off days)', $money($b['extra_work']['amount']), ($b['extra_work']['days'] ?? 0).' days · worked '.Duration::hms((int) ($b['extra_work']['seconds'] ?? 0)).' · paid '.Duration::hms((int) ($b['extra_work']['paid_seconds'] ?? $b['extra_work']['seconds'] ?? 0)).' (at most one working day a day)'];
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
        // Fines, one line each (date and reason); older breakdowns without the list show one total.
        if ($lines = (array) ($b['penalty_lines'] ?? [])) {
            foreach ($lines as $line) $deductions[] = ['Fine: '.($line['reason'] ?? 'penalty'), $money($line['amount'] ?? 0), isset($line['date']) ? Carbon::parse($line['date'])->format('d M') : null];
        } elseif ((float) ($b['penalties'] ?? 0) > 0) {
            $deductions[] = ['Penalties', $money($b['penalties']), null];
        }
        if ((float) ($b['advance_recovery'] ?? 0) > 0) $deductions[] = ['Salary advance recovery', $money($b['advance_recovery']), null];
        foreach ((array) ($b['fixed_deductions'] ?? []) as $d) if ((float) ($d['amount'] ?? 0) > 0) $deductions[] = [$d['name'] ?? 'Fixed deduction', $money($d['amount']), 'every month'];
        if ($days['unpaid_leave'] > 0) $deductions[] = ['Unpaid leave', 0.0, $days['unpaid_leave'].' days not paid (already left out of the salary for days worked)'];
        // "Why the salary is less than full" goes on to the net salary: every cut after "Salary for days worked"
        // (fines with date and reason, advance recovery, adjustments, fixed and statutory deductions), what is
        // added (reimbursements, plus adjustments), and fines still waiting for approval (not cut yet).
        if ($shortfall) {
            $shortfall['after'] = array_values(array_map(fn ($d) => ['label' => $d[0], 'amount' => (float) $d[1], 'detail' => $d[2] ?? null], array_filter($deductions, fn ($d) => (float) $d[1] > 0)));
            $shortfall['added'] = array_values(array_map(fn ($e) => ['label' => $e[0], 'amount' => (float) $e[1], 'detail' => $e[2] ?? null], array_filter($earnings, fn ($e) => ! in_array($e[0], ['Salary for days worked', 'Extra working (off days)', 'Overtime'], true) && (float) $e[1] > 0)));
            $shortfall['net'] = $money($net);
            $shortfall['pending_fines'] = $this->pendingFines($b['_employee_id'] ?? null, $month);
        }
        if ((float) ($b['reimbursements'] ?? 0) > 0) $earnings[] = ['Expense reimbursements', $money($b['reimbursements']), 'approved claims, paid on top'];

        return ['month' => $month, 'label' => Carbon::parse($month.'-01')->format('F Y'), 'source' => $source, 'status' => $status, 'days' => $days,
            'monthly_salary' => $money($data['monthly_salary'] ?? 0),
            // Pay for one day: the daily rate, or what was paid for the days worked divided by those days.
            'per_day_rate' => $money((float) ($data['daily_rate'] ?? 0) ?: ($shortfall['rate'] ?? ($daysPaid > 0 ? (float) ($b['base_salary'] ?? 0) / $daysPaid : 0))),
            // Days paid = salary for days worked ÷ the day's rate (a paid holiday inside the month's working days is not added twice).
            'days_paid' => $shortfall ? $shortfall['days_paid'] : $daysPaid,
            'shortfall' => $shortfall,
            'earnings' => $earnings, 'deductions' => $deductions,
            'gross' => $money($gross), 'deductions_total' => $money(array_sum(array_column($deductions, 1))), 'net' => $money($net),
            'paid' => $money($paid), 'remaining' => $money(max(0, $net - $paid)), 'payslip_id' => null];
    }

    /** Fines for the month still waiting for approval: shown for information, not cut until approved. */
    private function pendingFines(?int $employeeId, string $month): array
    {
        if (! $employeeId) return [];
        $from = Carbon::parse($month.'-01');

        return \App\Models\EmployeePenalty::where('employee_id', $employeeId)->where('status', 'pending')
            ->whereDate('effective_on', '>=', $from->toDateString())->whereDate('effective_on', '<=', $from->copy()->endOfMonth()->toDateString())
            ->orderBy('effective_on')->get()
            ->map(fn ($p) => ['label' => 'Fine: '.$p->reason, 'amount' => round((float) $p->amount, 2), 'detail' => $p->effective_on->format('d M').' · waiting for approval'])->values()->all();
    }

    /** Past working days absent with no leave, and working days still to come, in a period (for the reasons). */
    private function dayList(AttendanceEmployee $employee, Carbon $from, Carbon $to): array
    {
        if ($employee->joined_at && Carbon::parse($employee->joined_at)->gt($from)) $from = Carbon::parse($employee->joined_at)->startOfDay();
        if ($from->gt($to)) return ['absent' => [], 'upcoming' => []];
        $days = app(AttendanceService::class)->days($employee, $from, $to);

        return ['absent' => $days->where('status', 'absent')->pluck('date')->values()->all(), 'upcoming' => $days->where('status', 'upcoming')->pluck('date')->values()->all()];
    }

    /**
     * "Why your salary is less than full": the month's working days at the day's rate, then each cut with its
     * dates — days not present, half days, late check-ins paid by hours, half-day holidays not at work,
     * unpaid leave — down to the salary for days worked. Null for daily wage and when it cannot be shown exactly.
     *
     * @return array{rate: float, days_paid: float, working: float, full: float, full_label: string, lines: array, earned: float, estimate: bool}|null
     */
    private function shortfall(string $month, string $source, array $b, array $days, array $data): ?array
    {
        if (($data['pay_basis'] ?? 'monthly') === 'daily' || ! empty($b['old_sheet'])) return null;
        $monthly = (float) ($data['monthly_salary'] ?? 0);
        $divisor = (float) ($data['month_divisors'][$month] ?? 0) ?: (float) ($data['working_days_per_month'] ?? 0);
        $working = (float) ($days['working'] ?? 0);
        $earned = round((float) ($b['base_salary'] ?? 0), 2);
        if ($monthly <= 0 || $divisor <= 0 || $working <= 0) return null;
        $rate = $monthly / $divisor;
        $paidDays = round($earned / $rate, 4);
        $cutDays = round($working - $paidDays, 4);
        $fmtDate = fn ($d) => Carbon::parse($d)->format('d M');
        $dates = fn (array $list) => self::ranges($list);
        $num = fn (float $v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
        $lines = [];
        $left = $cutDays;
        $add = function (string $label, ?string $detail, float $d) use (&$lines, &$left, $rate) {
            if ($d <= 0) return;
            $lines[] = ['label' => $label, 'detail' => $detail, 'days' => round($d, 4), 'amount' => round($d * $rate, 2)];
            $left -= $d;
        };
        // Late check-ins: shown as hours worked and rupees (never as a fraction of a day).
        $late = [];
        foreach ((array) ($b['hours_paid_days'] ?? []) as $d => $h) {
            $share = (float) ($h['share'] ?? 1);
            $worked = (int) ($h['seconds'] ?? 0);
            $standard = (int) ($h['standard'] ?? ($share > 0 ? round($worked / $share) : 0));
            if ($share >= 0.9999) continue; // worked a full day's hours: nothing cut, nothing to explain
            $late[] = ['date' => $fmtDate($d), 'day' => Carbon::parse($d)->format('D'), 'in' => Carbon::parse($h['check_in'] ?? '00:00')->format('g:i A'), 'leave' => $h['leave'] ?? null, 'kind' => self::byHoursKind($h),
                'worked' => Duration::hm($worked), 'of' => Duration::hm($standard), 'short' => Duration::hm(max(0, $standard - $worked)),
                'paid' => round($share * $rate, 2), 'cut' => round((1 - $share) * $rate, 2)];
            $left -= 1 - $share;
        }
        $half = (array) ($b['half_days'] ?? []);
        if ($half) $add('Half day'.(count($half) === 1 ? '' : 's').' — '.$dates($half), 'came in, but less than the half-day hours at work: half of the day is paid', 0.5 * count($half));
        $halfHoliday = (array) ($b['half_holiday_paid'] ?? []);
        if ($halfHoliday) $add('Half-day holiday, not at work — '.$dates($halfHoliday), 'the holiday half is paid, the working half is not', 0.5 * count($halfHoliday));
        $unpaid = (float) ($days['unpaid_leave'] ?? 0);
        if ($unpaid > 0) $add('Unpaid leave — '.$num($unpaid).' '.($unpaid == 1 ? 'day' : 'days'), null, min($unpaid, max(0, $left)));
        if ($left > 0.005) {
            $absent = (array) ($b['_days']['absent'] ?? []);
            $upcoming = (array) ($b['_days']['upcoming'] ?? []);
            $known = count($absent) + count($upcoming);
            if ($upcoming && abs($known - $left) < 0.01) {
                if ($absent) $add('Absent, no leave — '.count($absent).' '.(count($absent) === 1 ? 'day' : 'days'), $dates($absent), (float) count($absent));
                $add('Days still to come — '.count($upcoming).' '.(count($upcoming) === 1 ? 'day' : 'days'), $dates($upcoming).' · not worked yet; paid when payroll is recalculated at month end', (float) count($upcoming));
            } else {
                $add('Absent, no leave — '.$num($left).' '.(abs($left - 1) < 0.01 ? 'day' : 'days'), count($absent) && abs(count($absent) - $left) < 0.01 ? $dates($absent) : null, $left);
            }
        }
        $estimate = $source === 'estimate';

        $standardSeconds = (int) (collect((array) ($b['hours_paid_days'] ?? []))->first()['standard'] ?? round((float) ($data['standard_daily_hours'] ?? 0) * 3600));

        return ['rate' => round($rate, 2), 'days_paid' => $paidDays, 'working' => $working, 'estimate' => $estimate,
            'late' => $late, 'late_cut' => round(array_sum(array_column($late, 'cut')), 2), 'late_title' => self::byHoursTitle(array_column($late, 'kind')),
            'hourly' => $standardSeconds > 0 ? round($rate / ($standardSeconds / 3600), 2) : null, 'day_hours' => $standardSeconds > 0 ? Duration::hm($standardSeconds) : null,
            'full' => round($working * $rate, 2),
            'full_label' => ($estimate ? 'Salary for the '.$num($working).' working days so far' : 'Full salary for '.$num($working).' working days'),
            'lines' => $lines, 'earned' => $earned,
            // Worked on a weekly off / holiday: paid on top, by the hours worked (shown after "Salary for days worked").
            'extra' => (float) ($b['extra_work']['amount'] ?? 0) > 0 ? [
                'amount' => round((float) $b['extra_work']['amount'], 2),
                'days' => (int) ($b['extra_work']['days'] ?? 0),
                // Paid hours: at most one working day a day; the hours above that are shown, not paid.
                'hours' => 'paid '.Duration::hm((int) ($b['extra_work']['paid_seconds'] ?? $b['extra_work']['seconds'] ?? 0)).((int) ($b['extra_work']['seconds'] ?? 0) - (int) ($b['extra_work']['paid_seconds'] ?? $b['extra_work']['seconds'] ?? 0) >= 60 ? ' of '.Duration::hm((int) $b['extra_work']['seconds']).' worked' : ''),
                'dates' => collect((array) ($b['extra_work']['dates'] ?? []))->map(fn ($d, $date) => Carbon::parse($date)->format('d M').' ('.Duration::hm((int) ($d['seconds'] ?? 0)).((int) ($d['seconds'] ?? 0) - (int) ($d['paid_seconds'] ?? $d['seconds'] ?? 0) >= 60 ? ', paid '.Duration::hm((int) $d['paid_seconds']).' max' : '').')')->values()->join(', '),
            ] : null];
    }

    /** "05–09 Oct, 12 Oct" from a list of dates. */
    private static function ranges(array $dates): string
    {
        sort($dates);
        $out = [];
        $start = $prev = null;
        foreach ($dates as $d) {
            $c = Carbon::parse($d);
            if ($prev && $c->copy()->subDay()->isSameDay($prev)) { $prev = $c; continue; }
            if ($start) $out[] = $start->isSameDay($prev) ? $start->format('d M') : $start->format('d').'–'.$prev->format('d M');
            $start = $prev = $c;
        }
        if ($start) $out[] = $start->isSameDay($prev) ? $start->format('d M') : $start->format('d').'–'.$prev->format('d M');

        return implode(', ', $out);
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

    /** Why a day was paid by the hours at work: late (check-in after the cut-off), half_day (under the half-day limit) or leave (half-day / short leave). */
    public static function byHoursKind(array $day): string
    {
        return ! empty($day['half_day']) ? 'half_day' : (! empty($day['leave']) ? 'leave' : 'late');
    }

    /** Title over the "paid by the hours" rows. */
    public static function byHoursTitle(array $kinds): string
    {
        $kinds = array_values(array_unique($kinds));
        if ($kinds === ['late']) return 'Came after the late check-in time — paid only for the hours worked';
        if ($kinds === ['half_day']) return 'Half day — paid only for the hours at work';
        if ($kinds === ['leave']) return 'Half-day leave — paid only for the hours worked';

        return 'Paid only for the hours worked (late check-in, half day or half-day leave)';
    }

    /** "2 late check-in days and 1 half day paid for the hours worked". */
    public static function byHoursNote(array $byHours): string
    {
        $names = ['late' => ['late check-in day', 'late check-in days'], 'half_day' => ['half day', 'half days'], 'leave' => ['half-day leave day', 'half-day leave days']];
        $counts = array_count_values(array_map(fn ($h) => self::byHoursKind((array) $h), $byHours));
        $parts = [];
        foreach ($names as $kind => [$one, $many]) if ($n = $counts[$kind] ?? 0) $parts[] = $n.' '.($n === 1 ? $one : $many);

        return implode(' and ', $parts).' paid for the hours worked';
    }
}
