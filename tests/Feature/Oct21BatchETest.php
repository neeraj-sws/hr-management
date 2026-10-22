<?php

namespace Tests\Feature;

use App\Livewire\Attendance\MyAttendance;
use App\Livewire\Attendance\Records;
use App\Livewire\People\EmployeeProfile;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LegacySalaryRecord;
use App\Models\SalaryProfile;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use App\Services\ReportService;
use App\Services\WorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** 21 Oct batch E: salary report from the old sheet, hourly-rate hours, not-counted time, attendance calendar. */
class Oct21BatchETest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function employee(string $code, array $extra = []): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2025-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8.5, ...$extra]);
    }

    public function test_salary_report_uses_the_old_sheet_for_its_months(): void
    {
        $e = $this->employee('SR-1');
        SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 10000, 'effective_from' => '2024-02-12', 'working_days_per_month' => 22, 'standard_daily_hours' => 8.5]);
        LegacySalaryRecord::create(['employee_id' => $e->id, 'employee_code' => 'SR-1', 'payroll_month' => '2025-07', 'name' => $e->name, 'name_key' => 'person sr-1',
            'basic_salary' => 11500, 'total_working_days' => 26, 'salary_per_day' => 442.31, 'total_salary_with_bonus' => 11000, 'salary_paid' => 9000, 'salary_remains' => 2000, 'payment_status' => 'Partially Paid']);

        $rows = app(ReportService::class)->rows($this->admin, 'salary', ['from' => '2025-06-01', 'to' => '2025-07-31', 'employee_id' => $e->id])->keyBy('month');
        $this->assertSame(11500.0, $rows['Jul 2025']['monthly_salary']);
        $this->assertSame(26, $rows['Jul 2025']['working_days']);
        $this->assertSame(442.31, $rows['Jul 2025']['per_day_rate']);
        $this->assertSame([11000.0, 9000.0, 2000.0, 'old sheet'], [$rows['Jul 2025']['payroll_payable'], $rows['Jul 2025']['paid'], $rows['Jul 2025']['remaining'], $rows['Jul 2025']['payroll_status']]);
        $this->assertSame('not run', $rows['Jun 2025']['payroll_status'], 'No sheet row: the salary record');
    }

    public function test_payroll_for_a_month_in_the_old_sheet_uses_the_sheet(): void
    {
        $e = $this->employee('OS-1');
        SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 10000, 'effective_from' => '2025-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-09-07', 'check_in' => '2026-09-07 10:00:00', 'check_out' => '2026-09-07 17:00:00', 'active_seconds' => 25200, 'status' => 'completed']);
        // Risha's real September 2026 row.
        LegacySalaryRecord::create(['employee_id' => $e->id, 'employee_code' => 'OS-1', 'payroll_month' => '2026-09', 'name' => $e->name, 'name_key' => 'person os-1',
            'basic_salary' => 10000, 'salary_per_day' => 400, 'per_hour_salary' => 44.444444, 'total_working_days' => 25, 'present_days' => 22, 'leave_days' => 3, 'paid_leave' => 0,
            'late_and_hourly_leave_minutes' => 320, 'late_coming_deduction' => 237.037037, 'total_payment_deduction' => 237.037037, 'salary_before_adjustment' => 8800,
            'salary_after_deduction' => 8562.962963, 'total_salary_with_bonus' => 8562.962963, 'salary_paid' => 8562.96, 'salary_remains' => 0.002963, 'payment_date' => '2026-10-12', 'payment_status' => 'Paid']);

        $item = app(HrWorkflowService::class)->calculatePayroll('2026-09-01', '2026-09-30', $this->admin->id)->items->firstWhere('employee_id', $e->id);
        $this->assertSame([8800.0, 8562.96, 25, 22.0], [(float) $item->gross_amount, (float) $item->net_amount, (int) $item->working_days, (float) $item->present_days]);
        $this->assertSame(8562.96, $item->calculation_breakdown['old_sheet']['paid']);
        $this->assertSame(237.04, $item->calculation_breakdown['old_sheet']['lines']['late_coming_deduction']);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\Runs::class)->set('run', $item->payroll_run_id)
            ->assertSee('8,562.96')->assertSee('old sheet · 12 Oct 2026')
            ->set('breakdownItemId', $item->id)->assertSee('Taken from the old salary sheet')->assertSee('Late coming deduction');
        $row = app(ReportService::class)->rows($this->admin, 'payroll', ['from' => '2026-09-01', 'to' => '2026-09-30', 'employee_id' => $e->id])->first();
        $this->assertSame([8562.96, 8562.96, 0.0], [$row['payable'], $row['paid'], $row['balance']]);
    }

    public function test_leave_cannot_be_requested_for_a_past_day(): void
    {
        $e = $this->employee('LP-1');
        $type = \App\Models\LeaveType::where('is_active', true)->firstOrFail();
        \Laravel\Sanctum\Sanctum::actingAs($e->user, ['*']);
        $this->postJson('/api/leave/requests', ['leave_type_id' => $type->id, 'duration' => 'full', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-16', 'reason' => 'Trip'])
            ->assertStatus(422)->assertJsonValidationErrors('starts_on');
        $this->postJson('/api/leave/requests', ['leave_type_id' => $type->id, 'duration' => 'full', 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-23', 'reason' => 'Trip'])
            ->assertCreated();
    }

    public function test_an_expense_claim_without_a_photo_can_be_sent_as_plain_json(): void
    {
        $e = $this->employee('EXP-1');
        \Laravel\Sanctum\Sanctum::actingAs($e->user, ['*']);
        $this->postJson('/api/expenses', ['category' => 'Travel', 'spent_on' => '2026-10-19', 'amount' => '250', 'description' => 'Cab to client'], ['X-Action-Id' => 'exp-json-1'])
            ->assertCreated()->assertJsonPath('data.description', 'Cab to client');
        $this->assertSame(1, \App\Models\ExpenseClaim::where('employee_id', $e->id)->count());
    }

    public function test_hourly_rate_hours_setting_changes_the_short_hours_deduction(): void
    {
        $e = $this->employee('HR-9');
        SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 10000, 'effective_from' => '2025-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);
        // One day 10:00–17:00 (7 h worked of 8.5 h): 90 minutes short.
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-09-07', 'check_in' => '2026-09-07 10:00:00', 'check_out' => '2026-09-07 17:00:00', 'active_seconds' => 25200, 'status' => 'completed']);
        $people = app(PeopleOperationsService::class);
        $policy = ['working_days_per_month' => 25, 'attendance_proration' => true, 'include_paid_leave' => true, 'short_hours_deduction' => true, 'short_hours_grace' => 0, 'divisor' => 'fixed'];

        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation', $policy, null, $this->admin->id, '2026-09-01');
        $shortAt85 = app(HrWorkflowService::class)->calculatePayroll('2026-09-01', '2026-09-30', $this->admin->id)->items->firstWhere('employee_id', $e->id)->calculation_breakdown['short_hours']['amount'];
        $this->assertEqualsWithDelta(400 / 8.5 * 1.5, $shortAt85, 0.01);

        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation', [...$policy, 'hourly_rate_hours' => 9], null, $this->admin->id, '2026-09-01');
        $shortAt9 = app(HrWorkflowService::class)->calculatePayroll('2026-09-01', '2026-09-30', $this->admin->id)->items->firstWhere('employee_id', $e->id)->calculation_breakdown['short_hours']['amount'];
        $this->assertEqualsWithDelta(400 / 9 * 1.5, $shortAt9, 0.01, 'Same as the old salary sheet: per-day ÷ 9');
    }

    public function test_task_time_is_capped_at_the_days_actual_working_time(): void
    {
        $e = $this->employee('TT-1');
        // In 10:00–14:00 with a 1-hour break: 3 h actual working time; the raw active field says 4 h.
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-19', 'check_in' => '2026-10-19 10:00:00', 'check_out' => '2026-10-19 14:00:00', 'active_seconds' => 14400, 'break_seconds' => 3600, 'status' => 'completed']);
        $task = app(WorkService::class)->saveTask(null, ['title' => 'Mockups', 'assignee_id' => $e->id], $this->admin);
        TaskTimeEntry::create(['task_id' => $task->id, 'employee_id' => $e->id, 'started_at' => '2026-10-19 10:00:00', 'ended_at' => '2026-10-19 15:00:00', 'duration_seconds' => 18000, 'status' => 'stopped']);

        $m = app(WorkService::class)->productivity(collect([$e->fresh()]), '2026-10-19', '2026-10-19')->first();
        $worked = app(\App\Services\AttendanceService::class)->workedSeconds(AttendanceSession::with('employee')->where('employee_id', $e->id)->first());
        $this->assertSame($worked, $m->logged_seconds, 'Counted up to the actual working time');
        $this->assertSame(18000 - $worked, $m->trimmed_seconds);
    }

    public function test_attendance_report_shows_imported_days_before_the_joining_date(): void
    {
        $e = $this->employee('JN-1', ['joined_at' => '2026-10-10']);
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-09-01', 'check_in' => '2026-09-01 09:55:00', 'check_out' => '2026-09-01 19:31:00', 'active_seconds' => 33120, 'status' => 'completed']);

        $rows = app(ReportService::class)->rows($this->admin, 'attendance', ['from' => '2026-09-01', 'to' => '2026-09-30', 'employee_id' => $e->id]);
        $this->assertSame(['2026-09-01'], $rows->pluck('work_date')->all(), 'The worked day shows; empty days before joining do not');
    }

    public function test_attendance_calendar_on_profile_my_attendance_and_records(): void
    {
        $e = $this->employee('CA-1');
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-04-02', 'check_in' => '2026-04-02 10:13:00', 'check_out' => '2026-04-02 20:12:00', 'active_seconds' => 33300, 'status' => 'completed']);

        Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $e])
            ->assertSee('Attendance · October 2026')
            ->set('attendanceMonth', '2026-04')->assertSee('Attendance · April 2026')->assertSee('att-cal-grid', false)->assertSee('10:13 AM')->assertSee('Weekly off');

        Livewire::actingAs($e->user)->test(MyAttendance::class)->set('month', '2026-04')->set('view', 'calendar')->assertSee('att-cal-grid', false)->assertSee('10:13 AM');
        Livewire::actingAs($this->admin)->test(Records::class)->set('employee', $e->id)->set('from', '2026-04-01')->set('to', '2026-04-30')->set('view', 'calendar')
            ->assertSee('April 2026')->assertSee('att-cal-grid', false)->assertSee('10:13 AM');
    }
}
