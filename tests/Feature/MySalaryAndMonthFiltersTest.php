<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\ExpenseClaim;
use App\Models\LegacySalaryRecord;
use App\Models\SalaryProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** The employee's own salary (this month so far + history with deductions) and month-by-month history lists for the app. */
class MySalaryAndMonthFiltersTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceEmployee $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-22 12:00:00');
        $user = User::factory()->create(['email' => 'ms-1@example.com']);
        $user->assignRole('Employee');
        $this->e = AttendanceEmployee::create(['employee_code' => 'MS-1', 'name' => 'Person MS-1', 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8.5]);
        SalaryProfile::create(['employee_id' => $this->e->id, 'monthly_salary' => 10000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);
    }

    public function test_my_salary_shows_this_month_so_far_and_history_with_deductions(): void
    {
        // Two days this month, one of them 1.5 h short.
        AttendanceSession::create(['employee_id' => $this->e->id, 'work_date' => '2026-10-19', 'check_in' => '2026-10-19 10:00:00', 'check_out' => '2026-10-19 19:00:00', 'active_seconds' => 30600, 'status' => 'completed']);
        AttendanceSession::create(['employee_id' => $this->e->id, 'work_date' => '2026-10-21', 'check_in' => '2026-10-21 10:00:00', 'check_out' => '2026-10-21 17:00:00', 'active_seconds' => 25200, 'status' => 'completed']);
        app(\App\Services\PeopleOperationsService::class)->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation', ['working_days_per_month' => 25, 'attendance_proration' => true, 'include_paid_leave' => true, 'short_hours_deduction' => true, 'short_hours_grace' => 0, 'divisor' => 'fixed'], null, User::where('email', 'admin@example.com')->first()->id, '2026-10-01');
        LegacySalaryRecord::create(['employee_id' => $this->e->id, 'employee_code' => 'MS-1', 'payroll_month' => '2026-09', 'name' => 'Person MS-1', 'name_key' => 'person ms-1',
            'basic_salary' => 10000, 'salary_per_day' => 400, 'total_working_days' => 25, 'present_days' => 22, 'leave_days' => 3, 'total_salary_paid_days' => 22, 'salary_before_adjustment' => 8800,
            'late_and_hourly_leave_minutes' => 320, 'late_coming_deduction' => 237.04, 'total_payment_deduction' => 237.04, 'salary_after_deduction' => 8562.96, 'total_salary_with_bonus' => 8562.96,
            'salary_paid' => 6000, 'salary_remains' => 2562.96, 'payment_date' => '2026-10-12', 'payment_status' => 'Partially Paid']);

        Sanctum::actingAs($this->e->user, ['*']);
        $json = $this->getJson('/api/my/salary')->assertOk()->json();
        $this->assertSame('estimate', $json['current']['source']);
        $this->assertSame(800.0, (float) $json['current']['gross'], '2 days × 400');
        $this->assertSame('Short working hours', $json['current']['deductions'][0][0]);
        $this->assertEqualsWithDelta(400 / 8.5 * 1.5, $json['current']['deductions'][0][1], 0.01);
        $sep = $json['history'][0];
        $this->assertSame(['September 2026', 'old_sheet', 8562.96, 6000.0, 2562.96], [$sep['label'], $sep['source'], (float) $sep['net'], (float) $sep['paid'], (float) $sep['remaining']]);
        $this->assertSame('Late coming', $sep['deductions'][0][0]);
        // The app's month card: days paid × day rate and the payment date.
        $this->assertSame([22.0, 400.0, '2026-10-12'], [(float) $sep['days_paid'], (float) $sep['per_day_rate'], $sep['paid_on']]);
        $this->assertNull($sep['payslip_url'], 'No payslip for an old-sheet month');
        $this->assertEqualsWithDelta(400.0, $json['current']['per_day_rate'], 0.01, '10000 / 25 working days');

        Livewire::actingAs($this->e->user)->test(\App\Livewire\Payroll\Salary::class)
            ->assertSee('This month so far')->assertSee('Short working hours')->assertSee('Salary history')->assertSee('September 2026')->assertSee('Still due');
        Livewire::actingAs(User::where('email', 'admin@example.com')->first())->test(\App\Livewire\People\EmployeeProfile::class, ['employee' => $this->e])
            ->assertSee('Salary month by month')->assertSee('Late coming');
    }

    public function test_history_lists_come_one_month_at_a_time(): void
    {
        ExpenseClaim::create(['employee_id' => $this->e->id, 'category' => 'Travel', 'spent_on' => '2026-09-10', 'amount' => 100, 'description' => 'September cab', 'status' => 'pending']);
        ExpenseClaim::create(['employee_id' => $this->e->id, 'category' => 'Food', 'spent_on' => '2026-10-05', 'amount' => 50, 'description' => 'October lunch', 'status' => 'pending']);
        \App\Models\DailyWorkStatus::create(['employee_id' => $this->e->id, 'work_date' => '2026-09-15', 'summary' => 'September work', 'submitted_at' => '2026-09-15 19:00:00']);
        \App\Models\DailyWorkStatus::create(['employee_id' => $this->e->id, 'work_date' => '2026-10-22', 'summary' => 'Today work', 'submitted_at' => '2026-10-22 11:00:00']);
        Sanctum::actingAs($this->e->user, ['*']);

        $this->assertSame(['September cab'], collect($this->getJson('/api/expenses?month=2026-09')->json('data'))->pluck('description')->all());
        $this->assertSame(['October lunch'], collect($this->getJson('/api/expenses?month=2026-10')->json('data'))->pluck('description')->all());
        $this->assertCount(2, $this->getJson('/api/expenses')->json('data'), 'Older app versions: no month = everything');

        $september = $this->getJson('/api/daily-status?month=2026-09')->json();
        $this->assertSame(['September work'], collect($september['data'])->pluck('summary')->all());
        $this->assertSame('Today work', $september['today']['summary'], "Today's status is always there for the form");
    }
}
