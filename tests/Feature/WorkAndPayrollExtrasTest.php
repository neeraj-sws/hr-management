<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Devices;
use App\Livewire\Work\DailyStatus;
use App\Livewire\Work\Tasks;
use App\Models\AttendanceDevice;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\SalaryProfile;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Devices admin page, task progress, daily status plans, task-hours reconciliation and short-hours payroll. */
class WorkAndPayrollExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employeeUser;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->employeeUser = User::factory()->create();
        $this->employeeUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'WP-1', 'name' => 'Wasim Worker', 'is_active' => true, 'user_id' => $this->employeeUser->id, 'joined_at' => '2026-01-01', 'daily_work_hours' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_devices_page_lists_and_revokes_devices(): void
    {
        $token = $this->employee->createToken('attendance-device', ['attendance:sync']);
        $device = AttendanceDevice::create(['employee_id' => $this->employee->id, 'device_id' => 'dev-abc', 'computer_name' => 'DESK-07', 'windows_user' => 'wasim', 'app_version' => '1.4.0', 'last_seen_at' => now()->subMinutes(2), 'is_active' => true, 'personal_access_token_id' => $token->accessToken->id]);

        Livewire::actingAs($this->admin)->test(Devices::class)->assertSee('DESK-07')->assertSee('wasim')->assertSee('Online')
            ->call('revoke', $device->id)->assertSee('Revoked')->call('activate', $device->id)->assertSee('waiting for sign-in');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->actingAs($this->employeeUser)->get(route('attendance.devices'))->assertForbidden();
    }

    public function test_task_progress_is_set_or_worked_out(): void
    {
        $task = Task::create(['title' => 'Report', 'assignee_id' => $this->employee->id, 'status' => 'todo', 'priority' => 'normal', 'estimated_hours' => 4]);
        TaskTimeEntry::create(['task_id' => $task->id, 'employee_id' => $this->employee->id, 'started_at' => '2026-10-15 09:00:00', 'ended_at' => '2026-10-15 10:00:00', 'duration_seconds' => 3600, 'status' => 'stopped']);
        $this->assertSame(25, $task->progressPercent(3600), 'Auto: 1 h of 4 h estimated');

        Livewire::actingAs($this->employeeUser)->test(Tasks::class)->call('updateProgress', $task->id, 60)->assertSee('60%');
        $this->assertSame([60, 'in_progress'], [$task->fresh()->progress, $task->fresh()->status]);
        $task->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertSame(100, $task->fresh()->progressPercent());
    }

    public function test_daily_status_has_pending_work_and_tomorrows_plan(): void
    {
        Livewire::actingAs($this->employeeUser)->test(DailyStatus::class)
            ->set('summary', 'Finished the export')->set('pending', 'Excel styling')->set('tomorrowPlan', 'Start the import screen')
            ->call('save')->assertHasNoErrors()
            // The full update (pending, tomorrow) is in View details.
            ->call('view', \App\Models\DailyWorkStatus::firstOrFail()->id)->assertSee('Pending:')->assertSee('Plan for tomorrow:')->assertSee('Excel styling');
        $status = \App\Models\DailyWorkStatus::firstOrFail();
        $this->assertSame(['Excel styling', 'Start the import screen'], [$status->pending, $status->tomorrow_plan]);
    }

    public function test_reconciliation_stops_stale_timers_and_fixes_durations(): void
    {
        $task = Task::create(['title' => 'Old work', 'assignee_id' => $this->employee->id, 'status' => 'in_progress', 'priority' => 'normal']);
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-13', 'check_in' => '2026-10-13 09:00:00', 'check_out' => '2026-10-13 18:00:00', 'active_seconds' => 30000, 'status' => 'completed']);
        $stale = TaskTimeEntry::create(['task_id' => $task->id, 'employee_id' => $this->employee->id, 'started_at' => '2026-10-13 15:00:00', 'status' => 'running']);
        $wrong = TaskTimeEntry::create(['task_id' => $task->id, 'employee_id' => $this->employee->id, 'started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-12 11:00:00', 'duration_seconds' => 99, 'status' => 'stopped']);

        $this->artisan('work:reconcile-task-hours')->expectsOutputToContain('run with --fix')->assertSuccessful();
        $this->assertSame('running', $stale->fresh()->status, 'Report only without --fix');
        $this->artisan('work:reconcile-task-hours --fix')->assertSuccessful();
        $this->assertSame(['stopped', '18:00', 3 * 3600], [$stale->fresh()->status, $stale->fresh()->ended_at->format('H:i'), (int) $stale->fresh()->duration_seconds]);
        $this->assertSame(3600, (int) $wrong->fresh()->duration_seconds);
    }

    public function test_short_hours_are_deducted_only_when_turned_on(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 17600, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        // Two working days present, 6 h each = 4 short hours; hourly rate 17600 / (22 × 8) = 100.
        foreach (['2026-10-12', '2026-10-13'] as $day) {
            AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $day, 'check_in' => "{$day} 09:00:00", 'check_out' => "{$day} 15:00:00", 'active_seconds' => 6 * 3600, 'status' => 'completed']);
        }
        $workflow = app(HrWorkflowService::class);
        $off = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id)->items()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertFalse($off->calculation_breakdown['short_hours']['enabled'], 'Off by default: payroll unchanged');

        app(PeopleOperationsService::class)->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'short_hours_deduction' => true, 'short_hours_grace' => 1], null, $this->admin->id, '2026-01-01');
        $on = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id)->items()->where('employee_id', $this->employee->id)->firstOrFail();
        $short = $on->calculation_breakdown['short_hours'];
        $this->assertSame([16 * 3600, 12 * 3600, 3 * 3600, 300.0], [$short['required_seconds'], $short['actual_seconds'], $short['short_seconds'], (float) $short['amount']], '4 h short − 1 h grace = 3 h × 100');
        $this->assertEqualsWithDelta((float) $off->net_amount - 300, (float) $on->net_amount, 0.01);
        $this->actingAs($this->admin)->get(route('hr.payslips.show', \App\Models\Payslip::where('payroll_item_id', $on->id)->value('id') ?? 0))->assertStatus(\App\Models\Payslip::where('payroll_item_id', $on->id)->exists() ? 200 : 404);
    }
}
