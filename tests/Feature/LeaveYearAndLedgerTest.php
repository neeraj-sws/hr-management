<?php

namespace Tests\Feature;

use App\Livewire\Company\Holidays;
use App\Livewire\Leave\Balances;
use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\SalaryAdjustment;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\GoogleSheetsSync;
use App\Services\HrWorkflowService;
use App\Services\LeaveAccrualService;
use App\Services\PeopleOperationsService;
use App\Support\LeaveYear;
use App\Support\WorkCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** April–March leave year, leave ledger, holiday types, salary adjustment kinds, attendance IP and Google Sheets sync. */
class LeaveYearAndLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-12 10:00:00'); // Monday
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->employee = AttendanceEmployee::create(['employee_code' => 'LY-1', 'name' => 'Leela Year', 'is_active' => true, 'joined_at' => '2026-01-01', 'work_start' => '09:30:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function people(): PeopleOperationsService
    {
        return app(PeopleOperationsService::class);
    }

    public function test_leave_year_runs_april_to_march_by_default(): void
    {
        $this->assertSame(4, LeaveYear::startMonth());
        $this->assertSame(2026, LeaveYear::of('2026-04-01'));
        $this->assertSame(2026, LeaveYear::of('2027-03-31'));
        $this->assertSame(2025, LeaveYear::of('2026-03-31'));
        $this->assertSame('2026-27', LeaveYear::label(2026));
        $this->assertSame('2027-03-31', LeaveYear::end(2026)->toDateString());
        $this->assertSame(7, LeaveYear::monthIndex(10));
        $this->assertSame(12, LeaveYear::monthIndex(3));

        Livewire::actingAs($this->admin)->test(Settings::class)->set('leaveYearStart', '1')->call('saveLeaveYear')->assertHasNoErrors();
        $this->assertSame(1, LeaveYear::startMonth());
        $this->assertSame('2026', LeaveYear::label(2026));
    }

    public function test_monthly_accrual_counts_months_from_april_and_is_written_to_the_ledger(): void
    {
        $type = LeaveType::create(['name' => 'Earned', 'annual_allowance' => 0, 'monthly_accrual' => 1.5, 'carry_forward' => false, 'carry_forward_limit' => 0, 'allow_advance' => false, 'paid' => true, 'is_active' => true]);
        $accrual = app(LeaveAccrualService::class);
        $accrual->accrueThrough(2026, 10, $this->admin->id);
        $accrual->accrueThrough(2026, 10, $this->admin->id); // idempotent

        $balance = LeaveBalance::where('employee_id', $this->employee->id)->where('leave_type_id', $type->id)->firstOrFail();
        $this->assertSame(2026, $balance->year);
        $this->assertSame(10.5, (float) $balance->accrued_days); // April..October = 7 months
        $this->assertSame(7, $balance->last_accrual_month);
        $this->assertSame([['accrual', 10.5]], LeaveLedgerEntry::where('leave_type_id', $type->id)->get()->map(fn ($e) => [$e->entry_type, (float) $e->days])->all());

        // February 2027 is month 11 of the same leave year.
        $accrual->accrueThrough(2027, 2, $this->admin->id);
        $this->assertSame(16.5, (float) $balance->fresh()->accrued_days);
        $this->assertSame(0, LeaveBalance::where('employee_id', $this->employee->id)->where('year', 2027)->count());
    }

    public function test_carry_forward_starts_in_april_and_expiry_is_recorded(): void
    {
        $type = LeaveType::create(['name' => 'Privilege', 'annual_allowance' => 10, 'monthly_accrual' => 0, 'carry_forward' => true, 'carry_forward_limit' => 3, 'carry_forward_expiry_months' => 3, 'allow_advance' => false, 'paid' => true, 'is_active' => true]);
        LeaveBalance::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type->id, 'year' => 2025, 'entitled_days' => 10, 'used_days' => 4]);

        Carbon::setTestNow('2026-04-01 01:00:00');
        app(LeaveAccrualService::class)->accrueThrough(2026, 4, $this->admin->id);
        $balance = LeaveBalance::where('employee_id', $this->employee->id)->where('leave_type_id', $type->id)->where('year', 2026)->firstOrFail();
        $this->assertSame(10.0, (float) $balance->entitled_days);
        $this->assertSame(3.0, (float) $balance->carried_days);
        $this->assertSame('2026-07-31', $balance->carried_expires_on->toDateString());

        Carbon::setTestNow('2026-08-01 01:00:00');
        app(LeaveAccrualService::class)->accrueThrough(2026, 8, $this->admin->id);
        $this->assertSame(0.0, (float) $balance->fresh()->carried_days);
        $this->assertSame(
            [['opening', 10.0], ['carry_forward', 3.0], ['expiry', -3.0]],
            LeaveLedgerEntry::where('leave_type_id', $type->id)->where('year', 2026)->orderBy('id')->get()->map(fn ($e) => [$e->entry_type, (float) $e->days])->all()
        );
    }

    public function test_leave_requests_follow_the_leave_year_and_usage_and_adjustments_are_in_the_ledger(): void
    {
        $type = LeaveType::create(['name' => 'Casual', 'annual_allowance' => 12, 'monthly_accrual' => 0, 'carry_forward' => false, 'carry_forward_limit' => 0, 'allow_advance' => false, 'paid' => true, 'is_active' => true]);
        $workflow = app(HrWorkflowService::class);

        try {
            $workflow->requestLeave($this->employee, ['leave_type_id' => $type->id, 'starts_on' => Carbon::parse('2027-03-31'), 'ends_on' => Carbon::parse('2027-04-01'), 'reason' => 'across years']);
            $this->fail('A leave crossing 31 March must be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2026-27', $e->getMessage());
        }

        // February 2027 still belongs to the 2026-27 leave year.
        $feb = $workflow->requestLeave($this->employee, ['leave_type_id' => $type->id, 'starts_on' => Carbon::parse('2027-02-15'), 'ends_on' => Carbon::parse('2027-02-15'), 'reason' => 'family']);
        $this->assertSame(1.0, app(LeaveAccrualService::class)->balance($this->employee, $type, 2026)['booked']);
        $workflow->decideLeave($feb, $this->admin->id, true);
        $balance = LeaveBalance::where('employee_id', $this->employee->id)->where('leave_type_id', $type->id)->where('year', 2026)->firstOrFail();
        $this->assertSame(1.0, (float) $balance->used_days);

        Livewire::actingAs($this->admin)->test(Balances::class)
            ->call('openAdjust', $this->employee->id, $type->id)->set('adjustDays', '-2')->set('adjustReason', 'Unauthorised absence on 3 Oct')->call('saveAdjustment')->assertHasNoErrors()
            ->call('openLedger', $this->employee->id)->assertSee('Manual adjustment')->assertSee('Unauthorised absence on 3 Oct')->assertSee('Leave taken');

        $entries = LeaveLedgerEntry::where('leave_type_id', $type->id)->orderBy('id')->get();
        $this->assertSame([['opening', 12.0], ['used', -1.0], ['adjustment', -2.0]], $entries->map(fn ($e) => [$e->entry_type, (float) $e->days])->all());
        $this->assertSame($this->admin->id, $entries->last()->created_by);
        $this->assertSame(9.0, app(LeaveAccrualService::class)->balance($this->employee, $type, 2026)['remaining']);
    }

    public function test_holiday_types_optional_holidays_and_paid_status(): void
    {
        $calendar = ['days' => [1, 2, 3, 4, 5], 'holidays' => ['2026-11-11', '2026-11-12'], 'holiday_types' => ['2026-11-11' => ['category' => 'optional', 'paid' => true]]];
        $this->assertTrue(WorkCalendar::isWorkday('2026-11-11', $calendar), 'An optional holiday stays a working day');
        $this->assertFalse(WorkCalendar::isWorkday('2026-11-12', $calendar), 'A holiday without a type is a normal (public) holiday');
        $this->assertSame(['category' => 'public', 'paid' => true], WorkCalendar::holidayTypeOf($calendar, '2026-11-12'));

        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('newHoliday', '2026-11-13')->set('newHolidayName', 'Founders Day')->set('newHolidayCategory', 'company')->set('newHolidayPaid', false)->call('addHoliday')
            ->set('newHoliday', '2026-10-02')->set('newHolidayName', 'Gandhi Jayanti')->set('newHolidayCategory', 'national')->call('addHoliday')
            ->call('saveCalendar')->assertHasNoErrors();
        $saved = app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'calendar.working_days', today()->toDateString(), []);
        $this->assertSame(['category' => 'company', 'paid' => false], $saved['holiday_types']['2026-11-13']);
        $this->assertSame(['category' => 'national', 'paid' => true], $saved['holiday_types']['2026-10-02']);

        Livewire::actingAs($this->admin)->test(Holidays::class)->assertSee('Founders Day')->assertSee('Company holiday')->assertSee('Unpaid')->assertSee('National holiday');
    }

    /** Monday–Friday, November 2026 has 21 weekdays; 11 Nov is a holiday. Salary 21,000 → 1,000 a day. */
    private function payrollFor(array $holidayType, int $workingDays): float
    {
        $this->people()->setPolicy('calendar.working_days', 'calendar', 'Calendar', ['days' => [1, 2, 3, 4, 5], 'holidays' => ['2026-11-11'], 'holiday_types' => ['2026-11-11' => $holidayType]], null, $this->admin->id, '2026-01-01');
        $this->people()->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => $workingDays, 'attendance_proration' => false], null, $this->admin->id, '2026-01-01');
        SalaryProfile::where('employee_id', $this->employee->id)->delete();
        SalaryProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'monthly_salary' => $workingDays * 1000, 'working_days_per_month' => $workingDays, 'standard_daily_hours' => 8]);
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-11-01', '2026-11-30', $this->admin->id);

        return (float) PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail()->calculation_breakdown['base_salary'];
    }

    public function test_paid_holiday_is_paid_and_unpaid_holiday_is_deducted(): void
    {
        $this->assertSame(21000.0, $this->payrollFor(['category' => 'national', 'paid' => true], 21));
        $this->assertSame(20000.0, $this->payrollFor(['category' => 'company', 'paid' => false], 21));
        // When the month already has the full working days, a paid holiday adds nothing and an unpaid one costs a day.
        $this->assertSame(20000.0, $this->payrollFor(['category' => 'national', 'paid' => true], 20));
        $this->assertSame(19000.0, $this->payrollFor(['category' => 'company', 'paid' => false], 20));
    }

    public function test_increment_creates_a_new_salary_revision_and_keeps_history(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'monthly_salary' => 30000, 'working_days_per_month' => 22, 'standard_daily_hours' => 8, 'components' => [['name' => 'Basic', 'type' => 'earning', 'amount' => 15000], ['name' => 'HRA', 'type' => 'earning', 'amount' => 15000], ['name' => 'Canteen', 'type' => 'deduction', 'amount' => 500]]]);
        $increment = $this->people()->requestAdjustment($this->employee, 6000, 'increment', '2026-11-01', 'Annual appraisal', $this->admin->id);
        $this->assertCount(1, $this->employee->salaryProfiles()->get(), 'Nothing changes before approval');

        $this->people()->decideFinancialRequest(SalaryAdjustment::class, $increment->id, $this->admin->id, true);
        $profiles = $this->employee->salaryProfiles()->orderBy('effective_from')->get();
        $this->assertSame([30000.0, 36000.0], $profiles->map(fn ($p) => (float) $p->monthly_salary)->all());
        $this->assertSame('2026-11-01', $profiles->last()->effective_from->toDateString());
        $this->assertSame([18000.0, 18000.0, 500.0], array_map(fn ($c) => (float) $c['amount'], $profiles->last()->components));
        $this->assertSame(36000.0, (float) $this->employee->fresh()->monthly_salary);

        $this->expectException(ValidationException::class);
        $this->people()->requestAdjustment($this->employee, 1000, 'increment', '2026-12-01', 'Recurring increment?', $this->admin->id, true);
    }

    public function test_recurring_bonus_and_performance_deduction_in_payroll(): void
    {
        $this->people()->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'attendance_proration' => false], null, $this->admin->id, '2026-01-01');
        SalaryProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'monthly_salary' => 22000, 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $bonus = $this->people()->requestAdjustment($this->employee, 500, 'bonus', '2026-10-15', 'Team lead allowance', $this->admin->id, true, '2026-11-30');
        $cut = $this->people()->requestAdjustment($this->employee, 300, 'performance_deduction', '2026-11-05', 'Missed deadlines', $this->admin->id);
        foreach ([$bonus, $cut] as $adjustment) $this->people()->decideFinancialRequest(SalaryAdjustment::class, $adjustment->id, $this->admin->id, true);

        $workflow = app(HrWorkflowService::class);
        $item = fn (string $from, string $to) => PayrollItem::where('payroll_run_id', $workflow->calculatePayroll($from, $to, $this->admin->id)->id)->where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame(500.0, (float) $item('2026-10-01', '2026-10-31')->adjustments_amount);
        $november = $item('2026-11-01', '2026-11-30');
        $this->assertSame(200.0, (float) $november->adjustments_amount);
        $this->assertSame(['Bonus', 'Performance deduction'], collect($november->calculation_breakdown['salary_adjustment_lines'])->pluck('label')->sort()->values()->all());
        $this->assertSame(0.0, (float) $item('2026-12-01', '2026-12-31')->adjustments_amount, 'The bonus ended in November');
        $this->assertNull($bonus->fresh()->payroll_item_id, 'A recurring adjustment is never claimed by one run');
        $this->assertNotNull($cut->fresh()->payroll_item_id);
    }

    public function test_salary_page_submits_a_recurring_adjustment_and_the_payslip_lists_it(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'monthly_salary' => 22000, 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\Salary::class)
            ->call('newAdjustment')->set('adjustmentEmployeeId', $this->employee->id)->set('kind', 'bonus')->set('amount', '750')->set('effectiveOn', '2026-10-01')
            ->set('reason', 'Night shift allowance')->set('recurring', true)->set('recurringUntil', '2026-12')->call('saveAdjustment')->assertHasNoErrors();
        $adjustment = SalaryAdjustment::firstOrFail();
        $this->assertTrue($adjustment->is_recurring);
        $this->assertSame('2026-12-31', $adjustment->recurring_until->toDateString());

        $this->people()->decideFinancialRequest(SalaryAdjustment::class, $adjustment->id, $this->admin->id, true);
        $this->get(route('payroll.salary'))->assertSee('Monthly until Dec 2026');
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->firstOrFail();
        $this->assertSame(750.0, (float) $item->adjustments_amount);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\Salary::class)->call('stopRecurring', $adjustment->id);
        $this->assertSame('2026-09-30', $adjustment->fresh()->recurring_until->toDateString(), 'Stopped from this month on: it no longer applies to October');
        $this->assertTrue($run->fresh()->needs_recalculation);
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $this->assertSame(0.0, (float) PayrollItem::where('payroll_run_id', $run->id)->firstOrFail()->adjustments_amount);
    }

    public function test_check_in_and_check_out_record_the_ip_address(): void
    {
        $service = app(AttendanceService::class);
        $service->checkIn($this->employee, 'web');
        Carbon::setTestNow('2026-10-12 18:00:00');
        $session = $service->checkOut($this->employee, 'web');
        $this->assertSame('127.0.0.1', $session->check_in_ip);
        $this->assertSame('127.0.0.1', $session->check_out_ip);
        $row = app(\App\Services\ReportService::class)->rows($this->admin, 'attendance', ['from' => '2026-10-12', 'to' => '2026-10-12'])->first();
        $this->assertSame('127.0.0.1', $row['check_in_ip']);
    }

    public function test_google_sheets_sync_sends_attendance_rows_with_the_secret(): void
    {
        Http::fake(['script.google.com/*' => Http::sequence()->push(['ok' => true])->push(['ok' => false, 'error' => 'Wrong secret'])]);
        $sheets = app(GoogleSheetsSync::class);
        $this->assertFalse($sheets->sync()['ok'], 'Off until configured');
        Http::assertNothingSent();

        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-12', 'check_in' => '2026-10-12 09:20:00', 'status' => 'working', 'check_in_ip' => '10.0.0.7']);
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('sheetsEnabled', true)->set('sheetsUrl', 'https://script.google.com/macros/s/abc/exec')->set('sheetsSecret', 'a-very-long-secret')->set('sheetsReports', ['attendance'])
            ->call('saveSheets')->assertHasNoErrors();
        $this->assertNotSame('a-very-long-secret', \App\Models\PolicyRule::where('rule_key', 'integrations.google_sheets')->first()->configuration['secret'], 'The secret is stored encrypted');

        $this->artisan('sheets:sync')->assertSuccessful();
        Http::assertSent(fn (Request $r) => $r['secret'] === 'a-very-long-secret' && $r['sheet'] === 'Attendance' && $r['key_columns'] === ['employee_code', 'work_date']
            && count($r['rows']) === 1 && $r['rows'][0][array_search('check_in_ip', $r['columns'], true)] === '10.0.0.7');
        $this->assertTrue($sheets->lastResult()['ok']);

        $result = $sheets->sync(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-12'));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Wrong secret', $result['message']);
    }
}
