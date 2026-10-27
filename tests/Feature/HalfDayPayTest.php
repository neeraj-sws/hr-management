<?php

namespace Tests\Feature;

use App\Livewire\Payroll\Runs;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryAdjustment;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Half day pays half a day (from 01 Oct 2026, Settings > Payroll); idle and meetings never make a half day;
 * one payroll run per period; a draft run can be deleted and gives back what it had taken.
 */
class HalfDayPayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;
    private HrWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-26 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['email' => 'half@example.com']);
        $user->assignRole('Employee');
        // 8-hour day: the half-day limit is 4 hours.
        $this->employee = AttendanceEmployee::create(['employee_code' => 'HD-1', 'name' => 'Hema Half', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'daily_work_hours' => 8]);
        // Per-day salary 22,000 ÷ 22 = 1,000.
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $this->workflow = app(HrWorkflowService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(string $date, string $in, string $out, ?int $activeHours = null, int $breakSeconds = 0): AttendanceSession
    {
        $seconds = Carbon::parse("{$date} {$in}")->diffInSeconds(Carbon::parse("{$date} {$out}"));

        return AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $date, 'check_in' => "{$date} {$in}:00", 'check_out' => "{$date} {$out}:00", 'active_seconds' => $activeHours !== null ? $activeHours * 3600 : $seconds - $breakSeconds, 'break_seconds' => $breakSeconds, 'status' => 'completed']);
    }

    private function item(string $start = '2026-10-01', string $end = '2026-10-31'): PayrollItem
    {
        return $this->workflow->calculatePayroll($start, $end, $this->admin->id)->items()->where('employee_id', $this->employee->id)->firstOrFail();
    }

    public function test_a_half_day_pays_half_a_day(): void
    {
        $this->day('2026-10-12', '09:00', '18:00');
        $this->day('2026-10-13', '09:00', '12:25'); // 3 h 25 min: half day
        $item = $this->item();
        $this->assertSame(['2026-10-13'], $item->calculation_breakdown['half_days']);
        $this->assertEqualsWithDelta(1500, (float) $item->calculation_breakdown['base_salary'], 0.01, '1 day + ½ day at 1,000');
        $this->assertSame(2.0, (float) $item->present_days, 'Days attended stay 2; the half day is paid half');

        // The salary page, profile and app say so too.
        $month = app(\App\Services\SalarySummaryService::class)->soFar($this->employee);
        $this->assertSame(1.5, (float) $month['days_paid']);
        $this->assertStringContainsString('1 half day: 13 Oct', $month['earnings'][0][2]);
    }

    public function test_idle_time_and_meetings_never_make_a_half_day(): void
    {
        // In the office 09:00–18:00 with a 1-hour break, but only 2 hours of keyboard/mouse use (meetings, reading).
        $session = $this->day('2026-10-14', '09:00', '18:00', 2, 3600);
        $this->assertFalse(app(AttendanceService::class)->isHalfDay($session), '8 h at work: not a half day');
        $this->assertSame([], $this->item()->calculation_breakdown['half_days']);
        // A long break does count: 09:00–14:00 with 2 h break = 3 h at work.
        $short = $this->day('2026-10-15', '09:00', '14:00', null, 2 * 3600);
        $this->assertTrue(app(AttendanceService::class)->isHalfDay($short));
    }

    public function test_calendar_and_lists_show_the_time_at_work_that_decides_a_half_day(): void
    {
        // 16:25–21:09 with 21 min idle: worked 4:22:33, at work 4:44 (limit 4 h): not a half day.
        $this->day('2026-10-14', '16:25', '21:09', null, 0)->update(['active_seconds' => 4 * 3600 + 22 * 60 + 33]);
        Livewire::actingAs($this->admin)->test(\App\Livewire\People\EmployeeProfile::class, ['employee' => $this->employee])
            ->assertSee('at work 04:44:00');
        Livewire::actingAs($this->employee->user)->test(\App\Livewire\Attendance\MyAttendance::class)
            ->assertSee('at work 04:44:00')->assertSee('At work (half-day check)');
        Livewire::actingAs($this->admin)->test(\App\Livewire\System\Settings::class)->set('tab', 'hours')->assertSee('Half day if time at work is less than (hours)');
    }

    public function test_todays_timeline_says_what_happened_and_ends_with_now(): void
    {
        Carbon::setTestNow('2026-10-26 15:47:00');
        $s = AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-26', 'check_in' => '2026-10-26 10:10:00', 'status' => 'working']);
        foreach ([['check_in', '10:10:00', null], ['screen_state', '10:10:05', 'active'], ['screen_state', '12:58:00', 'idle'], ['screen_state', '12:58:30', 'active'],
            ['screen_state', '13:00:00', 'idle'], ['screen_state', '13:15:00', 'active']] as [$type, $time, $state]) {
            \App\Models\AttendanceActivityEvent::create(['attendance_session_id' => $s->id, 'event_type' => $type, 'event_time' => "2026-10-26 {$time}", 'duration_seconds' => 0, 'metadata' => $state ? ['state' => $state] : []]);
        }
        $rows = collect(\App\Support\DayTimeline::for($s->fresh('events')));
        $this->assertSame(['Check in', 'Idle started (no keyboard or mouse)', 'Back on the computer', 'Now · Working on the computer'], $rows->pluck('label')->all(), 'A 30-second blip is not shown');
        $this->assertSame('idle 00:15:00', $rows[2]['detail']);
        $this->assertSame('since 01:15 PM (02:32:00)', $rows[3]['detail']);
        Livewire::actingAs($this->employee->user)->test(\App\Livewire\Attendance\MyAttendance::class)->set('tab', 'today')
            ->assertSee('Back on the computer')->assertSee('Working on the computer')->assertDontSee('Screen state');
    }

    public function test_approved_half_day_leave_pays_only_the_hours_worked(): void
    {
        $paid = LeaveType::create(['name' => 'Casual', 'annual_allowance' => 12, 'paid' => true, 'is_active' => true]);
        $unpaid = LeaveType::create(['name' => 'Unpaid', 'annual_allowance' => 0, 'paid' => false, 'is_active' => true]);
        $this->day('2026-10-13', '09:00', '13:00', 3);
        $this->day('2026-10-14', '09:00', '12:00');
        $this->day('2026-10-15', '09:00', '12:00');
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $paid->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14', 'duration' => 'half', 'days' => 0.5, 'reason' => 'Doctor', 'status' => 'approved']);
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $unpaid->id, 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15', 'duration' => 'half', 'days' => 0.5, 'reason' => 'Personal', 'status' => 'approved']);
        $item = $this->item();
        // 13 Oct: 4 h at work = a full day (limit is under 4 h). 14 and 15 Oct: approved half-day leave (paid or
        // unpaid type) = only the 3 h worked are paid: 3 ÷ 8 × 1,000 = 375 each.
        $this->assertSame([], $item->calculation_breakdown['half_days']);
        $this->assertSame(['2026-10-14', '2026-10-15'], array_keys($item->calculation_breakdown['hours_paid_days']));
        $this->assertSame('Half-day leave · Casual', $item->calculation_breakdown['hours_paid_days']['2026-10-14']['leave']);
        $this->assertEqualsWithDelta(1750, (float) $item->calculation_breakdown['base_salary'], 0.01);

        // Settings > Payroll switch off: as before (a paid half-day leave = full day, an unpaid one = half day).
        app(PeopleOperationsService::class)->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'half_leave_hours_only' => false], null, $this->admin->id, '2026-01-01');
        $this->assertEqualsWithDelta(2500, (float) $this->item()->calculation_breakdown['base_salary'], 0.01);
    }

    public function test_days_before_the_start_date_and_the_switch_off_pay_as_before(): void
    {
        $this->day('2026-09-15', '09:00', '12:00');
        $sep = $this->item('2026-09-01', '2026-09-30');
        $this->assertSame([], $sep->calculation_breakdown['half_days'], 'September is before 01 Oct: paid in full as before');
        $this->assertEqualsWithDelta(1000, (float) $sep->calculation_breakdown['base_salary'], 0.01);

        $this->day('2026-10-13', '09:00', '12:00');
        app(PeopleOperationsService::class)->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'half_day_pay' => false], null, $this->admin->id, '2026-01-01');
        $this->assertEqualsWithDelta(1000, (float) $this->item()->calculation_breakdown['base_salary'], 0.01, 'Switched off: a half day is paid in full');
    }

    public function test_daily_wage_half_day_is_half_the_rate(): void
    {
        SalaryProfile::where('employee_id', $this->employee->id)->update(['pay_basis' => 'daily', 'daily_rate' => 800]);
        $this->day('2026-10-12', '09:00', '18:00');
        $this->day('2026-10-13', '09:00', '11:00');
        $this->assertEqualsWithDelta(1200, (float) $this->item()->calculation_breakdown['base_salary'], 0.01);
    }

    public function test_short_hours_never_cut_a_half_day_twice(): void
    {
        app(PeopleOperationsService::class)->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'short_hours_deduction' => true], null, $this->admin->id, '2026-01-01');
        $this->day('2026-10-13', '09:00', '12:00');
        $item = $this->item();
        $this->assertSame(0.0, (float) $item->calculation_breakdown['short_hours']['amount']);
        $this->assertEqualsWithDelta(500, (float) $item->calculation_breakdown['base_salary'], 0.01);
    }

    public function test_overlapping_payroll_is_refused_and_a_draft_can_be_deleted(): void
    {
        $adjustment = SalaryAdjustment::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-05', 'amount' => 5000, 'kind' => 'bonus', 'is_recurring' => false, 'status' => 'approved', 'reason' => 'Festival', 'requested_by' => $this->admin->id]);
        $part = $this->workflow->calculatePayroll('2026-10-01', '2026-10-13', $this->admin->id);
        $this->assertNotNull($adjustment->fresh()->payroll_item_id, 'The part-month run took the bonus');

        try {
            $this->workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
            $this->fail('An overlapping run must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('01 Oct – 13 Oct 2026', $e->errors()['period'][0]);
        }

        // Delete the part-month draft (needs a reason), then the full month gets the bonus.
        Livewire::actingAs($this->admin)->test(Runs::class)->set('run', $part->id)->call('openDelete')->set('deleteReason', 'x')->call('deleteRun')->assertHasErrors('deleteReason');
        Livewire::actingAs($this->admin)->test(Runs::class)->set('run', $part->id)->call('openDelete')->set('deleteReason', 'Made for the wrong dates')->call('deleteRun')->assertHasNoErrors();
        $this->assertNull(PayrollRun::find($part->id));
        $this->assertNull($adjustment->fresh()->payroll_item_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.deleted']);
        $full = $this->item();
        $this->assertEqualsWithDelta(5000, (float) $full->adjustments_amount, 0.01);

        // Approved payroll cannot be deleted.
        $full->run->update(['status' => 'approved']);
        $this->expectException(ValidationException::class);
        $this->workflow->deleteDraftPayroll($full->run, $this->admin, 'Trying to delete approved');
    }

    public function test_compare_shows_the_change_without_saving(): void
    {
        $this->day('2026-10-12', '09:00', '18:00');
        $run = $this->workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $this->day('2026-10-13', '09:00', '12:00'); // after the draft was calculated
        $before = (float) $run->items()->first()->net_amount;
        Livewire::actingAs($this->admin)->test(Runs::class)->set('run', $run->id)->call('openCompare')
            ->assertSee('Nothing is saved')->assertSee('13 Oct')->assertSee('+500.00');
        $this->assertSame($before, (float) $run->items()->first()->net_amount, 'Compare saves nothing');
    }

    public function test_so_far_matches_the_draft_payroll_and_claims_are_not_deductions(): void
    {
        // Calendar divisor with a paid holiday (20 Oct): Oct has 21 working days without it.
        app(PeopleOperationsService::class)->setPolicy('calendar.working_days', 'calendar', 'Working calendar', ['days' => [1, 2, 3, 4, 5], 'holidays' => ['2026-10-20'], 'holiday_names' => ['2026-10-20' => 'Dussehra']], null, $this->admin->id, '2026-01-01');
        app(PeopleOperationsService::class)->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'divisor' => 'calendar'], null, $this->admin->id, '2026-01-01');
        $this->day('2026-10-12', '09:00', '18:00');
        SalaryAdjustment::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-05', 'amount' => 5000, 'kind' => 'bonus', 'is_recurring' => false, 'status' => 'approved', 'reason' => 'Festival', 'requested_by' => $this->admin->id]);
        \App\Models\ExpenseClaim::create(['employee_id' => $this->employee->id, 'category' => 'Travel', 'spent_on' => '2026-10-10', 'amount' => 43, 'description' => 'Auto', 'status' => 'approved']);
        $item = $this->item();
        $this->assertEqualsWithDelta(22000 / 21 + 5000 + 43, (float) $item->net_amount, 0.01);

        // The profile's "so far" (up to 26 Oct) shows the same: the holiday is not paid twice mid-month, and the
        // bonus and claim the draft took still count.
        $soFar = app(\App\Services\SalarySummaryService::class)->soFar($this->employee);
        $this->assertEqualsWithDelta((float) $item->net_amount, (float) $soFar['net'], 0.01);

        // The run's Deductions column does not show the claim as a minus deduction.
        Livewire::actingAs($this->admin)->test(Runs::class)->set('run', $item->payroll_run_id)
            ->assertSee('+ claims 43.00 in net')->assertDontSee('-43.00');
    }

    public function test_salary_report_reads_the_full_month_run(): void
    {
        $this->day('2026-10-12', '09:00', '18:00');
        // An older part-month run made first (as on the live site, before overlaps were refused).
        $part = PayrollRun::create(['period_starts_on' => '2026-08-01', 'period_ends_on' => '2026-08-13', 'created_by' => $this->admin->id, 'status' => 'draft']);
        PayrollItem::create(['payroll_run_id' => $part->id, 'employee_id' => $this->employee->id, 'gross_amount' => 5454.55, 'net_amount' => 5454.55, 'adjustments' => 0, 'working_days' => 9, 'present_days' => 1, 'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'calculation_data' => [], 'calculation_breakdown' => []]);
        $full = $this->item();
        $part->update(['period_starts_on' => '2026-10-01', 'period_ends_on' => '2026-10-13']);
        $rows = app(ReportService::class)->rows($this->admin, 'salary', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $row = $rows->firstWhere('employee_code', 'HD-1');
        $this->assertEqualsWithDelta((float) $full->net_amount, (float) $row['payroll_payable'], 0.01);
    }
}
