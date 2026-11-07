<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\Company;
use App\Models\PayrollItem;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use App\Support\CompanyAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Settings > Payroll > Per-day salary: "Every day of the month". A company that chooses it pays the month's every day
 * (30 / 31, weekly offs and holidays included) and cuts only a working day not at work; another company keeps its own
 * way (the working days of the calendar, as SWS does).
 */
class MonthDaysSalaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $itweb;
    private AttendanceEmployee $swsPerson;
    private AttendanceEmployee $itwebPerson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-12-02 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->itweb = Company::create(['name' => 'ITWeb']);
        AttendanceEmployee::query()->update(['is_active' => false]);
        $this->swsPerson = $this->person('SWS-9', 'Swati Sharma', null);
        $this->itwebPerson = $this->person('ITW-9', 'Ishan Tiwari', $this->itweb->id);
        $people = app(PeopleOperationsService::class);
        // Mon-Sat work in both companies (Sunday off); no half-day / late rules in the way.
        $calendar = ['days' => [1, 2, 3, 4, 5, 6], 'holidays' => []];
        $people->setPolicy('calendar.working_days', 'calendar', 'Calendar', $calendar, null, $this->admin->id, '2026-01-01');
        // ITWeb alone: every day of the month is paid.
        CompanyAccess::withRulesCompany($this->itweb->id, function () use ($people, $calendar) {
            $people->setPolicy('calendar.working_days', 'calendar', 'Calendar', $calendar, null, $this->admin->id, '2026-01-01');
            $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['divisor' => 'month_days', 'attendance_proration' => true, 'half_day_hours_only' => false, 'half_day_pay' => false, 'late_checkin_hours_pay' => false, 'short_hours_deduction' => false, 'extra_work_auto' => false], null, $this->admin->id, '2026-01-01');
        });
        // SWS keeps working days of the calendar.
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['divisor' => 'calendar', 'attendance_proration' => true, 'half_day_hours_only' => false, 'half_day_pay' => false, 'late_checkin_hours_pay' => false, 'short_hours_deduction' => false, 'extra_work_auto' => false], null, $this->admin->id, '2026-01-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $code, string $name, ?int $companyId): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');
        $employee = AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'company_id' => $companyId]);
        SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => 30000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 26, 'standard_daily_hours' => 8]);

        return $employee;
    }

    /** Present on every Mon-Sat of November 2026 except the dates given. */
    private function attend(AttendanceEmployee $employee, array $absent = []): void
    {
        for ($d = Carbon::parse('2026-11-01'); $d->lte(Carbon::parse('2026-11-30')); $d->addDay()) {
            if ($d->isSunday() || in_array($d->toDateString(), $absent, true)) continue;
            $date = $d->toDateString();
            AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => $date, 'check_in' => "$date 09:00:00", 'check_out' => "$date 18:00:00", 'break_seconds' => 0, 'active_seconds' => 9 * 3600, 'status' => 'completed']);
        }
    }

    /** Payroll is made per company: SWS (the main company) and ITWeb each have their own run. */
    private function payroll(): void
    {
        $workflow = app(HrWorkflowService::class);
        $workflow->calculatePayroll('2026-11-01', '2026-11-30', $this->admin->id, Company::main()->id);
        $workflow->calculatePayroll('2026-11-01', '2026-11-30', $this->admin->id, $this->itweb->id);
    }

    private function base(AttendanceEmployee $employee): float
    {
        $item = PayrollItem::where('employee_id', $employee->id)->firstOrFail();

        return (float) $item->calculation_breakdown['base_salary'];
    }

    public function test_every_day_of_the_month_is_paid_and_only_absent_days_are_cut(): void
    {
        $this->attend($this->itwebPerson, ['2026-11-10', '2026-11-11']); // two working days not at work
        $this->attend($this->swsPerson, ['2026-11-10', '2026-11-11']);
        $this->payroll();
        // ITWeb: 30,000 ÷ 30 days = 1,000 a day; 30 days - 2 absent = 28 days = 28,000 (the Sundays are paid).
        $this->assertEqualsWithDelta(28000, $this->base($this->itwebPerson), 0.01);
        // SWS: November 2026 has 25 working days (Mon-Sat): 30,000 ÷ 25 × 23 present = 27,600.
        $this->assertEqualsWithDelta(27600, $this->base($this->swsPerson), 0.01);
    }

    public function test_a_full_attendance_month_pays_the_whole_salary(): void
    {
        $this->attend($this->itwebPerson);
        $this->payroll();
        $this->assertEqualsWithDelta(30000, $this->base($this->itwebPerson), 0.01);
    }

    public function test_the_per_day_rate_follows_the_days_of_the_month(): void
    {
        // December has 31 days: 31,000 ÷ 31 = 1,000 a day.
        $this->assertSame(31, app(HrWorkflowService::class)->salaryDaysFor($this->itwebPerson, '2026-12-01')['days']);
        $this->assertSame(30, app(HrWorkflowService::class)->salaryDaysFor($this->itwebPerson, '2026-11-01')['days']);
        // SWS is still the working days of the calendar.
        $this->assertSame(27, app(HrWorkflowService::class)->salaryDaysFor($this->swsPerson, '2026-12-01')['days']);
    }

    public function test_the_company_profile_and_settings_show_the_choice(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('company.profile', $this->itweb))->assertOk()->assertSee('every day is paid, only absent days are cut');
        \Livewire\Livewire::withQueryParams(['company' => (string) $this->itweb->id, 'tab' => 'payroll'])->test(\App\Livewire\System\Settings::class)
            ->assertSet('salaryDivisor', 'month_days')->assertSee('Every day of the month')->assertSee('Dec 2026');
    }
}
