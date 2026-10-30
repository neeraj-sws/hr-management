<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeePenalty;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * An employee's salary day by day for a month, from the same figures payroll uses (HrWorkflowService::payFigures):
 * each day's status, time at work, the day's pay, extra working / overtime, its fines, and the day's net. Items that
 * belong to the month and not to a day (short hours, adjustments, advance recovery, statutory, reimbursements) are
 * listed after the days, with one line that lines the days up with the month's calculation (holiday top-up, the cap
 * of days a month pays), so everything adds up to exactly what payroll pays. Nothing is written.
 */
class DayWiseSalaryService
{
    public function __construct(private readonly HrWorkflowService $workflow, private readonly AttendanceService $attendance) {}

    /** @return array{rows: list<array>, totals: array, items: list<array>, net: float, gross: float, month: string}|null (null = no salary set up) */
    public function month(AttendanceEmployee $employee, string $month): ?array
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $f = $this->workflow->payFigures($employee, $start->toDateString(), $end->toDateString());
        if ($f === null) return null;

        $sessions = AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->whereNotNull('check_in')->orderBy('check_in')->get()->groupBy(fn ($s) => $s->work_date->toDateString());
        $fines = EmployeePenalty::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved', 'waived'])->whereDate('effective_on', '>=', $start)->whereDate('effective_on', '<=', $end)->orderBy('id')->get()->groupBy(fn ($p) => $p->effective_on->toDateString());
        $pendingLeave = LeaveRequest::with('leaveType')->where('employee_id', $employee->id)->where('status', 'pending')->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)->get();
        $overtime = collect($f['approvedOvertime'])->groupBy(fn ($o) => Carbon::parse($o->work_date)->toDateString());
        $joined = $employee->joined_at?->toDateString();
        $left = $employee->exit_date?->toDateString();

        $rows = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $session = $sessions->get($date)?->last();
            $workday = ($f['isWorkday'])($date);
            $rate = $this->dayRate($f, $date);
            $share = 0.0;
            [$status, $tone, $note] = ['Absent', 'red', null];

            if (($joined && $date < $joined) || ($left && $date > $left)) {
                [$status, $tone] = [$joined && $date < $joined ? 'Before joining' : 'After leaving', 'gray'];
            } elseif (isset($f['extraWork']['dates'][$date])) {
                $d = $f['extraWork']['dates'][$date];
                [$status, $tone, $note] = ['Extra working', 'green', ($d['type'] === 'leave' ? 'on a leave day' : 'on an off day').((int) $d['seconds'] - (int) ($d['paid_seconds'] ?? $d['seconds']) >= 60 ? ' · paid '.\App\Support\Duration::hm((int) $d['paid_seconds']).' max' : '')];
            } elseif (! $workday) {
                if (isset($f['paidHolidaySet'][$date])) [$status, $tone] = ['Holiday (paid)', 'violet'];
                elseif (isset($f['unpaidHolidaySet'][$date])) [$status, $tone] = ['Holiday (unpaid)', 'gray'];
                else [$status, $tone] = ['Weekly off', 'gray'];
            } elseif (isset($f['presentSet'][$date])) {
                if (isset($f['hoursPaidSet'][$date])) {
                    $h = $f['hoursPaidSet'][$date];
                    $share = (float) $h['share'];
                    [$status, $tone, $note] = [! empty($h['half_day']) ? 'Half day' : (! empty($h['leave']) ? 'Part leave' : 'Late check-in'), 'amber', (! empty($h['leave']) ? $h['leave'].' · ' : 'paid by the hours at work · ').\App\Support\Duration::hm((int) $h['seconds']).' of '.\App\Support\Duration::hm((int) $h['standard'])];
                } elseif (isset($f['halfDaySet'][$date])) {
                    [$share, $status, $tone] = [0.5, 'Half day', 'amber'];
                } else {
                    $share = 1.0;
                    $late = $session && rescue(fn () => $this->attendance->isLate($session), false, false);
                    [$status, $tone, $note] = [$late ? 'Late' : 'Present', $late ? 'amber' : 'green', $late ? 'late by '.$this->attendance->lateMinutes($session).' min' : null];
                }
            } elseif (isset($f['paidLeaveSet'][$date])) {
                $share = $f['includePaidLeave'] ? 1.0 : 0.0;
                [$status, $tone] = ['Paid leave', 'blue'];
            } elseif (isset($f['unpaidLeaveSet'][$date])) {
                [$status, $tone] = ['Unpaid leave', 'gray'];
            } elseif (isset($f['halfHolidayAbsent'][$date])) {
                [$share, $status, $tone] = [0.5, 'Half-day holiday', 'violet'];
            } elseif ($pendingLeave->first(fn ($l) => $l->starts_on->toDateString() <= $date && $l->ends_on->toDateString() >= $date)) {
                [$status, $tone, $note] = ['Leave pending', 'amber', 'not paid until approved'];
            } elseif ($date > today()->toDateString()) {
                [$status, $tone] = ['Upcoming', 'gray'];
            }
            // Pay not prorated by attendance (Settings > Payroll): every working day is paid.
            if ($workday && ! $f['attendanceProration'] && ! in_array($status, ['Before joining', 'After leaving'], true)) $share = 1.0;

            $pay = $workday ? round($rate * $share, 2) : 0.0;
            $extra = isset($f['extraWork']['dates'][$date]) && $f['extraAuto'] ? round($f['hourly'] * (int) ($f['extraWork']['dates'][$date]['paid_seconds'] ?? $f['extraWork']['dates'][$date]['seconds']) / 3600 * $f['extraWork']['multiplier'], 2) : 0.0;
            $ot = 0.0;
            if (! isset($f['extraWork']['dates'][$date]) || ! $f['extraAuto']) {
                $minutes = (int) collect($overtime->get($date, []))->sum('approved_minutes');
                $ot = $minutes ? round($f['hourly'] * $minutes / 60 * ($workday ? $f['multiplier'] : $f['holidayMultiplier']), 2) : 0.0;
            }
            $dayFines = collect($fines->get($date, []))->map(fn (EmployeePenalty $p) => ['id' => $p->id, 'amount' => (float) $p->amount, 'reason' => (string) $p->reason, 'status' => $p->status, 'paid_back' => $p->isPaidBack(), 'note' => $p->decision_note, 'can_remove' => $p->canBeRemoved()])->values()->all();
            $deducted = (float) collect($dayFines)->where('status', 'approved')->where('paid_back', false)->sum('amount');

            $rows[] = ['date' => $date, 'day' => $day->format('D'), 'status' => $status, 'tone' => $tone, 'note' => $note, 'workday' => $workday,
                'in' => $session?->check_in, 'out' => $session?->check_out, 'at_work' => $session && $session->check_out ? (int) $this->attendance->officeSeconds($session) : null,
                'rate' => $workday ? round($rate, 2) : null, 'share' => $share, 'pay' => $pay, 'extra' => $extra, 'overtime' => $ot, 'fines' => $dayFines, 'fines_deducted' => $deducted,
                'net' => round($pay + $extra + $ot - $deducted, 2)];
        }

        $totals = ['pay' => round(array_sum(array_column($rows, 'pay')), 2), 'extra' => round(array_sum(array_column($rows, 'extra')), 2), 'overtime' => round(array_sum(array_column($rows, 'overtime')), 2), 'fines' => round(array_sum(array_column($rows, 'fines_deducted')), 2), 'net' => round(array_sum(array_column($rows, 'net')), 2)];

        // Month items (not tied to one day), so the total is exactly what payroll pays.
        $items = [];
        $match = round((float) $f['basePay'] + (float) $f['overtimeAmount'] + (float) ($f['extraWork']['amount'] ?? 0) - $totals['pay'] - $totals['extra'] - $totals['overtime'], 2);
        if (abs($match) >= 0.01) $items[] = ['label' => 'Month adjustment', 'detail' => 'paid holidays topping up a short month, or the most days a month pays', 'amount' => $match];
        // Fines the month deducts that are not on a listed day (e.g. a fine dated in this month but already counted).
        $fineGap = round($totals['fines'] - (float) $f['penalties'], 2);
        if (abs($fineGap) >= 0.01) $items[] = ['label' => 'Fines already settled elsewhere', 'detail' => 'counted in another payroll', 'amount' => $fineGap];
        if ((float) $f['shortAmount'] > 0) $items[] = ['label' => 'Short hours', 'detail' => \App\Support\Duration::hm((int) $f['short']['short_seconds']).' below the working day this month', 'amount' => -(float) $f['shortAmount']];
        foreach ($f['adjustmentLines'] as $line) $items[] = ['label' => $line['label'], 'detail' => $line['reason'], 'amount' => (float) $line['amount']];
        if ((float) $f['statutory']['earnings'] > 0) $items[] = ['label' => 'Statutory earnings', 'detail' => null, 'amount' => (float) $f['statutory']['earnings']];
        if ((float) $f['statutory']['deductions'] > 0) $items[] = ['label' => 'Statutory deductions', 'detail' => null, 'amount' => -(float) $f['statutory']['deductions']];
        foreach ($f['fixedDeductions'] as $c) $items[] = ['label' => $c['name'], 'detail' => 'fixed monthly deduction', 'amount' => -(float) $c['amount']];
        if ((float) $f['advanceRecovery'] > 0) $items[] = ['label' => 'Advance recovery', 'detail' => null, 'amount' => -(float) $f['advanceRecovery']];
        if ((float) $f['reimbursements'] > 0) $items[] = ['label' => 'Expense reimbursements', 'detail' => 'approved expense claims', 'amount' => (float) $f['reimbursements']];

        return ['rows' => $rows, 'totals' => $totals, 'items' => $items, 'gross' => (float) $f['gross'], 'net' => (float) $f['net'], 'month' => $start->format('F Y'),
            'monthly_salary' => (float) $f['profile']->monthly_salary, 'daily' => $f['profile']->isDaily(), 'hourly' => round((float) $f['hourly'], 2)];
    }

    /** One working day's pay on a date: daily wage, or the monthly salary ÷ the month's working days (as payroll). */
    private function dayRate(array $f, string $date): float
    {
        $profile = ($f['profileOn'])($date);
        if (! $profile) return 0.0;
        if ($profile->isDaily()) return (float) $profile->daily_rate;
        $monthKey = substr($date, 0, 7);
        $sheet = $this->workflow->sheetWorkingDays($monthKey);
        $divisor = ($f['calendarDivisor'] || $sheet) ? (int) ($f['monthDivisors'][$monthKey] ?? 0) : (int) ($f['payrollPolicy']['working_days_per_month'] ?? $profile->working_days_per_month);

        return (float) $profile->monthly_salary / max(1, $divisor);
    }
}
