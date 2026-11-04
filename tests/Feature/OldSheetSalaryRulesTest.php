<?php

namespace Tests\Feature;

use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\PayrollItem;
use App\Models\PolicyRule;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use App\Services\PolicyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Salary worked out like the old Excel salary sheet (Settings > Payroll > "Use old salary sheet rules"):
 * per day = salary ÷ the month's working days, per minute = per day ÷ 9 ÷ 60, days paid = present + paid leave,
 * and every minute under the 8h 30m day (check-in to check-out less breaks) is cut at the per-minute rate.
 */
class OldSheetSalaryRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $people = app(PeopleOperationsService::class);
        // Mon–Sat, 3rd Saturday off, Dussehra a paid holiday: October 2026 has 25 working days.
        $people->setPolicy('calendar.working_days', 'calendar', 'Calendar', ['days' => [1, 2, 3, 4, 5, 6], 'holidays' => ['2026-10-20'], 'holiday_types' => ['2026-10-20' => ['category' => 'public', 'paid' => true]], 'off_weeks' => ['6' => [3]]], null, $this->admin->id, '2026-01-01');
        // Office 10:00–19:30 (8h 30m), 16 minutes grace: late after 10:16.
        $people->setPolicy('work.hours', 'attendance', 'Work hours', ['start_time' => '10:00', 'end_time' => '19:30', 'standard_daily_hours' => 8.5], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('attendance.rules', 'attendance', 'Attendance rules', ['grace_minutes' => 16, 'half_day_hours' => 4.5], null, $this->admin->id, '2026-01-01');
        // What the live site had before: late check-in after 10:16 and half days paid by hours, no short-time cut.
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation', ['working_days_per_month' => 25, 'divisor' => 'calendar', 'short_hours_deduction' => false, 'half_day_pay' => true, 'half_day_pay_from' => '2026-10-01', 'late_checkin_hours_pay' => true, 'late_checkin_after' => '10:16', 'extra_work_auto' => true], null, $this->admin->id, '2026-10-30');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'KV-1', 'name' => 'Khushboo Verma', 'is_active' => true, 'joined_at' => '2026-01-01', 'daily_work_hours' => 8.5]);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 35000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(string $date, string $in, string $out, int $breakMinutes): void
    {
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $date, 'check_in' => "{$date} {$in}:00", 'check_out' => "{$date} {$out}:00", 'break_seconds' => $breakMinutes * 60, 'active_seconds' => 0, 'status' => 'completed']);
    }

    public function test_settings_fill_the_old_sheet_rules_and_apply_them_from_october(): void
    {
        $page = Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'payroll')
            ->assertSee('How salary is worked out')->assertSee('Use old salary sheet rules')
            ->call('useOldSheetRules')
            ->assertSet('payrollFrom', '2026-10-01')->assertSet('shortHoursDeduction', true)->assertSet('shortHoursLateOnly', false)->assertSet('lateCheckinPay', false)->assertSet('halfDayPay', false)->assertSet('hourlyRateHours', '9')
            // Each month's own working days: no fixed-days box, the real days instead (Oct 25, Nov 24, Dec 26).
            ->assertDontSee('Working days per month (salary divisor)')->assertSeeText('Oct 2026 = 25 days')->assertSeeText('Nov 2026 = 24 days')->assertSeeText('Dec 2026 = 26 days')
            ->assertSeeText('October 2026: ₹35,000 ÷ 25')
            ->assertSee('₹1,400.00')->assertSee('₹155.56 an hour')->assertSee('Each day present')->assertSee('751 min × ₹2.59 = ₹1,947.04');
        $page->call('savePayroll')->assertHasNoErrors();

        // The rule saved on 30 Oct is replaced, so all of October uses the sheet rules.
        $this->assertFalse((bool) PolicyRule::where('rule_key', 'payroll.calculation')->whereDate('effective_from', '2026-10-30')->value('is_active'));
        $october = app(PolicyConfigurationService::class)->forEmployee($this->employee, 'payroll.calculation', '2026-10-31', []);
        $this->assertTrue($october['short_hours_deduction']);
        $this->assertFalse($october['short_hours_late_only'], 'Every day must have 8h 30m, on time or late');
        $this->assertFalse($october['late_checkin_hours_pay']);
        $this->assertFalse($october['half_day_pay']);
        $this->assertEquals(9, $october['hourly_rate_hours']);
    }

    public function test_a_month_is_paid_like_the_old_sheet(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'payroll')->call('useOldSheetRules')->call('savePayroll')->assertHasNoErrors();

        // 14 days present (the sheet's Khushboo row): 11 full days of 8h 30m and three short ones.
        $full = ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10'];
        foreach ($full as $date) $this->day($date, '10:00', '19:30', 60);
        $this->day('2026-10-12', '10:38', '20:26', 52);  // in late but 8h 56m at work: nothing cut (sheet, 1 Apr)
        $this->day('2026-10-13', '10:00', '19:30', 60);
        $this->day('2026-10-14', '10:30', '13:30', 0);   // in late, left at 1:30 PM: 3 h at work, 330 min short (no half-day rule)
        $this->day('2026-10-15', '10:05', '13:05', 0);   // in on time but left early: 8h 30m not done, 330 min short too
        $this->day('2026-10-16', '11:31', '19:30', 60);  // in late: 6h 59m at work, 91 min short (not paid by hours)

        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
        $b = $item->calculation_breakdown;

        $this->assertSame(25, (int) $item->working_days);
        $this->assertEquals(14, (float) $item->present_days);
        $this->assertSame([], $b['hours_paid_days'], 'No day is paid by hours');
        $this->assertSame([], $b['half_days'], 'No half days');
        $this->assertEqualsWithDelta(19600, $b['base_salary'], 0.01, '14 days × ₹1,400');
        $this->assertSame(751 * 60, $b['short_hours']['short_seconds'], '330 + 330 + 91 minutes under 8h 30m');
        $this->assertEqualsWithDelta(1947.04, $b['short_hours']['amount'], 0.01, '751 min × ₹2.5926 (₹1,400 ÷ 9 ÷ 60)');
        $this->assertEqualsWithDelta(17652.96, (float) $item->net_amount, 0.01, 'Same as the old sheet: ₹19,600 − ₹1,947.04');

        // Shown date by date: each short day with its times, minutes and rupees (a table on the website, a note in the app).
        $this->assertSame(['2026-10-14', '2026-10-15', '2026-10-16'], array_keys($b['short_hours']['days']));
        $s = app(\App\Services\SalarySummaryService::class)->forItem($item)['shortfall'];
        $this->assertSame(['date' => '16 Oct', 'day' => 'Fri', 'in' => '11:31:00 AM', 'out' => '7:30:00 PM', 'break' => '01:00:00', 'worked' => '06:59:00', 'of' => '08:30:00', 'short' => '91 min', 'amount' => 235.92], $s['short_days'][2]);
        $note = collect($s['after'])->firstWhere('label', 'Short working hours')['detail'];
        $this->assertStringContainsString('751 min below the working day', $note);
        $this->assertStringContainsString('14 Oct: 10:30:00 AM–1:30:00 PM, break 00:00:00, at work 03:00:00 of 08:30:00 = 330 min short, ₹855.56', $note);
        // Day-wise salary: each short day shows its cut on its own row, and the days add up to the net salary.
        $dayWise = app(\App\Services\DayWiseSalaryService::class)->month($this->employee, '2026-10');
        $row = collect($dayWise['rows'])->firstWhere('date', '2026-10-16');
        $this->assertSame([235.92, 91 * 60, 1164.08], [$row['short'], $row['short_seconds'], $row['net']], '₹1,400 − 91 min (₹235.92) = ₹1,164.08 that day');
        $this->assertEqualsWithDelta(1947.04, $dayWise['totals']['short'], 0.01);
        $this->assertEqualsWithDelta(17652.96, $dayWise['totals']['net'], 0.01, 'Days total = net salary');
        $this->assertFalse(collect($dayWise['items'])->contains('label', 'Short hours'), 'No separate month line any more');

        Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\Runs::class)->set('run', $run->id)->set('breakdownItemId', $item->id)
            ->assertSeeText('Fri, 16 Oct')->assertSeeText('11:31:00 AM – 7:30:00 PM')->assertSeeText('91 min')->assertSeeText('−₹235.92'); // 855.56 + 855.56 + 235.92 = 1,947.04, the total
    }

    public function test_approved_or_locked_payroll_is_not_re_priced_by_an_earlier_apply_from_date(): void
    {
        $workflow = app(HrWorkflowService::class);
        $run = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $run->update(['status' => 'approved']);

        Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'payroll')->call('useOldSheetRules')->call('savePayroll')->assertHasErrors('payroll');
        $this->assertTrue((bool) PolicyRule::where('rule_key', 'payroll.calculation')->whereDate('effective_from', '2026-10-30')->value('is_active'), 'Nothing changed');
    }

    public function test_the_app_day_list_shows_the_short_time_cut_and_fines(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'payroll')->call('useOldSheetRules')->call('savePayroll')->assertHasNoErrors();
        $user = User::factory()->create(['email' => 'kv@example.com']);
        $user->assignRole('Employee');
        $this->employee->update(['user_id' => $user->id]);
        $this->day('2026-10-14', '10:30', '13:30', 0); // 330 min short
        \App\Models\EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-14', 'amount' => 500, 'reason' => 'Late', 'status' => 'approved']);

        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        $days = collect($this->getJson('/api/attendance/history?from=2026-10-01&to=2026-10-31')->assertOk()->json('data'))->keyBy('date');
        $this->assertSame('330 min short · −₹855.56 · Fine −₹500.00', $days['2026-10-14']['pay_note']);
        $this->assertNull($days['2026-10-13']['pay_note']);
    }
}
