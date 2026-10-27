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

/** Default: a half day (under the half-day limit) is paid only for the hours at work, not a fixed half day. */
class HalfDayHoursOnlyTest extends TestCase
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

    public function test_a_half_day_is_paid_only_for_the_hours_at_work(): void
    {
        $this->day('2026-10-12', '09:00', '18:00');
        $this->day('2026-10-13', '09:00', '12:00'); // 3 h at work of an 8 h day: half day
        $item = $this->item();
        $b = $item->calculation_breakdown;
        $this->assertSame([], $b['half_days'], 'Not a fixed half day');
        $this->assertSame(0.375, (float) $b['hours_paid_days']['2026-10-13']['share']);
        $this->assertEqualsWithDelta(1375, (float) $b['base_salary'], 0.01, '1 day + 3 h of 8 h (375), not 500');

        // Attendance still says Half day; the salary page explains it in hours and rupees.
        $this->assertTrue(app(AttendanceService::class)->isHalfDay(AttendanceSession::whereDate('work_date', '2026-10-13')->first()));
        $s = app(\App\Services\SalarySummaryService::class)->soFar($this->employee);
        $this->assertSame('Half day — paid only for the hours at work', $s['shortfall']['late_title'] ?? $s['shortfall']['late_title']);
        $this->assertSame('3h 00m', $s['shortfall']['late'][0]['worked']);
        $this->assertSame('half_day', $s['shortfall']['late'][0]['kind']);
        $this->assertStringContainsString('1 half day paid for the hours worked', $s['earnings'][0][2]);
    }

    public function test_untick_pays_a_fixed_half_day_again(): void
    {
        app(PeopleOperationsService::class)->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'half_day_hours_only' => false], null, $this->admin->id, '2026-01-01');
        $this->day('2026-10-13', '09:00', '12:00');
        $this->assertEqualsWithDelta(500, (float) $this->item()->calculation_breakdown['base_salary'], 0.01);
    }

    public function test_extra_working_on_an_off_day_is_shown_where_gross_is_explained(): void
    {
        $this->day('2026-10-12', '09:00', '18:00');
        $this->day('2026-10-18', '09:00', '13:00'); // Sunday (weekly off): 4 h extra working
        $item = $this->item();
        $extra = (float) $item->calculation_breakdown['extra_work']['amount'];
        $this->assertGreaterThan(0, $extra);
        $this->assertEqualsWithDelta((float) $item->calculation_breakdown['base_salary'] + $extra, (float) $item->gross_amount, 0.01, 'Gross = salary for days worked + extra working');

        $s = app(\App\Services\SalarySummaryService::class)->soFar($this->employee)['shortfall'];
        $this->assertSame($extra, $s['extra']['amount']);
        $this->assertSame('4h 00m', $s['extra']['hours']);

        $admin = $this->admin;
        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Payroll\Runs::class)->set('run', $item->payroll_run_id)->set('breakdownItemId', $item->id)
            ->assertSee('Extra working on off days')->assertSee('18 Oct: 4h 00m')->assertSee('+'.number_format($extra, 2));
    }

    public function test_late_check_in_on_a_working_day_is_paid_at_most_a_full_day(): void
    {
        $this->day('2026-10-13', '12:30', '22:30'); // in after 12:00, 10 h at work on an 8 h day
        $this->assertSame(1.0, (float) $this->item()->calculation_breakdown['hours_paid_days']['2026-10-13']['share'], 'Never more than one day');
        \Livewire\Livewire::actingAs($this->admin)->test(\App\Livewire\People\EmployeeProfile::class, ['employee' => $this->employee])
            ->assertSee('paid full day (8h 00m max)')->assertDontSee('paid for 10h 00m');
    }
}
