<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The attendance / salary rules, case by case (8.5-hour day, starts 10:00, half day under 4:30 at work,
 * salary 25,500 ÷ 25 days = 1,020 a day):
 *  net hours = check-out − check-in − breaks taken; idle is never taken off;
 *  under 4:30 net = Half Day (0.5 day); 4:30 or more = full day;
 *  check-in after 12:00 = paid by net hours ÷ 8.5 (at most one day), attendance status unchanged.
 */
class WorkingHoursRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceService $attendance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-26 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->attendance = app(AttendanceService::class);
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('attendance.rules', 'attendance', 'Attendance rules', ['grace_minutes' => 16, 'half_day_hours' => 4.5], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('calendar.working_days', 'calendar', 'Working calendar', ['days' => [1, 2, 3, 4, 5], 'holidays' => []], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 25, 'half_day_hours_only' => false], null, $this->admin->id, '2026-01-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $code, string $start = '10:00', string $end = '18:30'): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');
        $e = AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'daily_work_hours' => 8.5, 'work_start' => $start, 'work_end' => $end]);
        SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 25500, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'half_day_hours_only' => false, 'standard_daily_hours' => 8.5]);

        return $e;
    }

    /** in / out as H:i; breakMin = break actually taken; activeH = keyboard/mouse hours (rest was idle). */
    private function day(AttendanceEmployee $e, string $date, string $in, string $out, int $breakMin = 0, ?float $activeH = null): AttendanceSession
    {
        $span = Carbon::parse("{$date} {$in}")->diffInSeconds(Carbon::parse("{$date} {$out}"));

        return AttendanceSession::create(['employee_id' => $e->id, 'work_date' => $date, 'check_in' => "{$date} {$in}:00", 'check_out' => "{$date} {$out}:00", 'break_seconds' => $breakMin * 60,
            'active_seconds' => $activeH !== null ? (int) ($activeH * 3600) : $span - $breakMin * 60, 'status' => 'completed']);
    }

    private function dayStatus(AttendanceEmployee $e, string $date): string
    {
        return $this->attendance->days($e, Carbon::parse($date), Carbon::parse($date))->first()['status'];
    }

    private function payroll(): \Illuminate\Support\Collection
    {
        return app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id)->items()->get()->keyBy('employee_id');
    }

    public function test_net_hours_half_day_late_check_in_and_idle_case_by_case(): void
    {
        $e = $this->person('WH-1');
        $cases = [
            // date        in       out      break idle-hours  net     status      days paid
            ['2026-10-05', '10:00', '19:30', 45, null, '08:45:00', 'present', 1],        // A normal day, 45 min break
            ['2026-10-06', '10:00', '19:30', 90, null, '08:00:00', 'present', 1],        // B long break (1:30) taken off, still full
            ['2026-10-07', '10:00', '14:00', 0, null, '04:00:00', 'half_day', 0.5],      // C under 4:30 = half day
            ['2026-10-08', '10:00', '14:45', 15, null, '04:30:00', 'present', 1],        // D exactly 4:30 = not a half day
            ['2026-10-09', '12:30', '19:30', 30, null, '06:30:00', 'late', 0.7647],      // E in after 12:00: 6.5 / 8.5
            ['2026-10-12', '14:00', '17:00', 0, null, '03:00:00', 'half_day', 0.3529],   // F in after 12:00 and under 4:30: by hours (3 / 8.5)
            ['2026-10-13', '12:00', '19:30', 30, null, '07:00:00', 'late', 1],           // G in at 12:00 exactly: not "after 12"
            ['2026-10-14', '10:00', '19:30', 45, 3.0, '08:45:00', 'present', 1],         // H mostly idle (meetings, calls): not cut
            ['2026-10-15', '13:00', '23:00', 0, null, '10:00:00', 'late', 1],            // I in after 12:00, 10 h: at most one day
        ];
        foreach ($cases as [$date, $in, $out, $break, $active]) $this->day($e, $date, $in, $out, $break, $active);

        foreach ($cases as [$date, , , , , $net, $status]) {
            $session = AttendanceSession::where('employee_id', $e->id)->whereDate('work_date', $date)->first();
            $this->assertSame($net, \App\Support\Duration::hms($this->attendance->officeSeconds($session)), "{$date}: net hours");
            $this->assertSame($status, $this->dayStatus($e, $date), "{$date}: attendance status");
        }

        $item = $this->payroll()->get($e->id);
        $b = $item->calculation_breakdown;
        $this->assertSame(['2026-10-07'], $b['half_days'], 'Only C is paid as a half day');
        $this->assertSame(['2026-10-09', '2026-10-12', '2026-10-15'], array_keys($b['hours_paid_days']), 'E, F, I are paid by hours; G (12:00 exactly) is not');
        $this->assertSame([0.7647, 0.3529, 1.0], array_map(fn ($d) => (float) $d['share'], array_values($b['hours_paid_days'])));
        $expected = 1020 * array_sum(array_column($cases, 7));
        $this->assertEqualsWithDelta($expected, (float) $b['base_salary'], 0.05, 'Salary = 1,020 × days paid');
        $this->assertSame(0.0, (float) $b['short_hours']['amount'], 'Idle never cut (short hours off)');
        $this->assertSame(9.0, (float) $item->present_days, 'Attendance count separate: 9 days present');
    }

    public function test_afternoon_shift_is_not_paid_by_hours_and_half_day_leave_pays_hours_worked(): void
    {
        $afternoon = $this->person('WH-2', '14:00', '22:30');
        $this->day($afternoon, '2026-10-05', '14:05', '22:30', 30);
        $leaveTaker = $this->person('WH-3');
        $casual = LeaveType::create(['name' => 'Casual', 'annual_allowance' => 12, 'paid' => true, 'is_active' => true]);
        $this->day($leaveTaker, '2026-10-05', '14:00', '19:00');
        LeaveRequest::create(['employee_id' => $leaveTaker->id, 'leave_type_id' => $casual->id, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'duration' => 'half', 'days' => 0.5, 'reason' => 'Morning off', 'status' => 'approved']);

        $items = $this->payroll();
        $this->assertSame([], $items->get($afternoon->id)->calculation_breakdown['hours_paid_days'], 'Own day starts after 12:00: normal day');
        $this->assertEqualsWithDelta(1020, (float) $items->get($afternoon->id)->calculation_breakdown['base_salary'], 0.01);
        // Approved half-day leave (morning off), at work 2:00–7:00 PM = 5 h of 8 h 30 m: only those hours are paid.
        $this->assertSame(['2026-10-05'], array_keys($items->get($leaveTaker->id)->calculation_breakdown['hours_paid_days']));
        $this->assertEqualsWithDelta(1020 * 0.5882, (float) $items->get($leaveTaker->id)->calculation_breakdown['base_salary'], 0.01);
    }

    public function test_late_check_in_rule_can_be_switched_off_or_moved(): void
    {
        $e = $this->person('WH-4');
        $this->day($e, '2026-10-09', '12:30', '19:30', 30);
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 25, 'half_day_hours_only' => false, 'late_checkin_hours_pay' => false], null, $this->admin->id, '2026-01-01');
        $this->assertEqualsWithDelta(1020, (float) $this->payroll()->get($e->id)->calculation_breakdown['base_salary'], 0.01, 'Off: full day');
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 25, 'half_day_hours_only' => false, 'late_checkin_after' => '13:00'], null, $this->admin->id, '2026-01-01');
        $this->assertEqualsWithDelta(1020, (float) $this->payroll()->get($e->id)->calculation_breakdown['base_salary'], 0.01, 'Cut-off 13:00: 12:30 is not late enough');
    }

    public function test_why_less_than_full_salary_lists_every_cut_and_adds_up(): void
    {
        $e = $this->person('WH-6');
        $this->day($e, '2026-10-05', '10:00', '19:30', 45);
        $this->day($e, '2026-10-06', '10:00', '19:30', 45);
        $this->day($e, '2026-10-07', '10:00', '14:00');            // half day
        $this->day($e, '2026-10-08', '10:00', '19:30', 45);
        $this->day($e, '2026-10-09', '12:30', '19:30', 30);        // after 12:00: 0.76 day
        $item = $this->payroll()->get($e->id);                     // calculated on 26 Oct (mid-month)
        $s = app(\App\Services\SalarySummaryService::class)->forItem($item)['shortfall'];

        $this->assertSame(22.0, (float) $s['working']);
        $this->assertEqualsWithDelta(22 * 1020, $s['full'], 0.01);
        // Late check-in in hours and rupees, never as a fraction of a day: 6h 30m of 8h 30m, ₹780 paid, ₹240 not paid.
        $this->assertSame([['date' => '09 Oct', 'day' => 'Fri', 'in' => '12:30 PM', 'leave' => null, 'kind' => 'late', 'worked' => '6h 30m', 'of' => '8h 30m', 'short' => '2h 00m', 'paid' => 779.99, 'cut' => 240.01]], $s['late']);
        $this->assertSame(120.0, $s['hourly'], '₹1,020 a day ÷ 8h 30m');
        $labels = array_column($s['lines'], 'label');
        $this->assertSame(['Half day — 07 Oct', 'Absent, no leave — 12 days', 'Days still to come — 5 days'], $labels);
        $this->assertSame('01–02 Oct, 12–16 Oct, 19–23 Oct', $s['lines'][1]['detail']);
        $this->assertEqualsWithDelta($s['full'] - $s['late_cut'] - array_sum(array_column($s['lines'], 'amount')), $s['earned'], 0.05, 'Full − cuts = salary for days worked');
        $this->assertEqualsWithDelta((float) $item->calculation_breakdown['base_salary'], $s['earned'], 0.01);

        // Shown in the payroll breakdown and on the calendar ("paid for 6h 30m of 8h 30m"); no day fractions anywhere.
        \Livewire\Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\Runs::class)->set('run', $item->payroll_run_id)->set('breakdownItemId', $item->id)
            ->assertSee('Why the salary is less than full')->assertSee('Came after the late check-in time — paid only for the hours worked')
            ->assertSee('6h 30m')->assertSee('−₹240.01')->assertSee('Days still to come — 5 days')->assertDontSee('0.76 day');
        \Livewire\Livewire::actingAs($this->admin)->test(\App\Livewire\People\EmployeeProfile::class, ['employee' => $e])->assertSee('paid for 6h 30m of 8h 30m')->assertDontSee('0.76 day');
    }

    public function test_salary_page_and_payslip_explain_days_paid_by_hours(): void
    {
        $e = $this->person('WH-5');
        $this->day($e, '2026-10-09', '12:30', '19:30', 30);
        $item = $this->payroll()->get($e->id);
        $month = collect(app(\App\Services\SalarySummaryService::class)->history($e))->firstWhere('month', '2026-10')
            ?? app(\App\Services\SalarySummaryService::class)->soFar($e);
        $this->assertStringContainsString('1 late check-in day paid for the hours worked', $month['earnings'][0][2]);
        \Livewire\Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\Runs::class)->set('run', $item->payroll_run_id)->set('breakdownItemId', $item->id)
            ->assertSee('Came after the late check-in time, paid for hours worked')->assertSee('09 Oct: came 12:30 PM, worked 6h 30m of 8h 30m');
    }

    public function test_short_day_person_is_not_a_half_day_for_working_their_full_short_day(): void
    {
        // Cleaning job 09:15-10:15, 1 hour a day. The company half-day limit is 4:30.
        $e = $this->person('SHORT', '09:15', '10:15');
        $e->update(['daily_work_hours' => 1]);
        $full = AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-20', 'check_in' => '2026-10-20 09:15:00', 'check_out' => '2026-10-20 10:15:00', 'status' => 'completed']);
        $short = AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-21', 'check_in' => '2026-10-21 09:15:00', 'check_out' => '2026-10-21 09:35:00', 'status' => 'completed']);
        $this->assertFalse($this->attendance->isHalfDay($full->fresh('employee')), 'Worked the whole 1-hour day');
        $this->assertTrue($this->attendance->isHalfDay($short->fresh('employee')), '20 minutes of a 1-hour day is a half day');

        // A normal 8:30 day still uses the 4:30 limit.
        $n = $this->person('NORM');
        $s = AttendanceSession::create(['employee_id' => $n->id, 'work_date' => '2026-10-20', 'check_in' => '2026-10-20 10:00:00', 'check_out' => '2026-10-20 14:20:00', 'status' => 'completed']);
        $this->assertTrue($this->attendance->isHalfDay($s->fresh('employee')), '4:20 of 8:30 is a half day');
    }
}
