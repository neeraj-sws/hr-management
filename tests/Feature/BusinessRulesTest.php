<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\EmployeeFinalSettlement;
use App\Models\EmployeeLifecycleCase;
use App\Models\PayrollItem;
use App\Models\SalaryAdjustment;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\FinalSettlementService;
use App\Services\HrWorkflowService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_deduction_adjustments_reduce_net_pay(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'DED-1', 'name' => 'Deduction Test', 'is_active' => true]);
        SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-10-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:00:00', 'status' => 'completed']);
        SalaryAdjustment::create(['employee_id' => $employee->id, 'effective_on' => '2026-10-05', 'amount' => 300, 'kind' => 'deduction', 'reason' => 'Damaged equipment', 'status' => 'approved']);
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(1000.0, (float) $item->gross_amount);
        $this->assertSame(700.0, (float) $item->net_amount);
        $this->assertSame(-300.0, (float) $item->adjustments_amount);
    }

    public function test_payroll_calculator_cannot_approve_own_run_and_lock_blocks_changes(): void
    {
        $accounts = User::factory()->create();
        $accounts->givePermissionTo(['payroll.manage', 'payroll.approve']);
        $workflow = app(HrWorkflowService::class);
        $run = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $accounts->id);
        try {
            $workflow->approvePayroll($run, $accounts->id);
            $this->fail('Self-approval should be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payroll', $e->errors());
        }
        $hr = User::factory()->create();
        $workflow->approvePayroll($run, $hr->id);
        $workflow->lockPayroll($run, $accounts->id);
        $this->expectException(ValidationException::class);
        $workflow->calculatePayroll('2026-10-01', '2026-10-31', $accounts->id);
    }

    public function test_settlement_preparer_cannot_approve_it(): void
    {
        $preparer = User::factory()->create();
        $employee = AttendanceEmployee::create(['employee_code' => 'SET-SOD', 'name' => 'Leaver', 'is_active' => true]);
        $case = EmployeeLifecycleCase::create(['employee_id' => $employee->id, 'kind' => 'offboarding', 'status' => 'open', 'effective_on' => '2026-10-05']);
        $settlement = app(FinalSettlementService::class)->prepare($case, $preparer->id);
        $this->assertSame('pending', $settlement->status);
        $this->expectException(ValidationException::class);
        app(FinalSettlementService::class)->approve($settlement, $preparer->id);
    }

    public function test_report_export_matches_database_and_respects_scope(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $managerUser = User::factory()->create();
        $managerUser->assignRole('Manager');
        $manager = AttendanceEmployee::create(['employee_code' => 'REP-MGR', 'name' => 'Report Manager', 'is_active' => true, 'user_id' => $managerUser->id]);
        $mine = AttendanceEmployee::create(['employee_code' => 'REP-1', 'name' => 'Team Member', 'is_active' => true, 'manager_id' => $manager->id, 'work_start' => '09:00:00']);
        $other = AttendanceEmployee::create(['employee_code' => 'REP-2', 'name' => 'Other Department', 'is_active' => true]);
        foreach (['2026-10-01', '2026-10-02'] as $day) {
            AttendanceSession::create(['employee_id' => $mine->id, 'work_date' => $day, 'check_in' => "{$day} 09:20:00", 'check_out' => "{$day} 18:00:00", 'active_seconds' => 28800, 'status' => 'completed']);
            AttendanceSession::create(['employee_id' => $other->id, 'work_date' => $day, 'check_in' => "{$day} 09:00:00", 'status' => 'working']);
        }

        $csv = $this->actingAs($admin)->get('/reports/attendance/export?from=2026-10-01&to=2026-10-31')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertSame(implode(',', app(ReportService::class)->columns('attendance')), $lines[0]);
        $this->assertCount(1 + AttendanceSession::whereBetween('work_date', ['2026-10-01', '2026-10-31'])->count(), $lines);
        $this->assertStringContainsString('REP-1,"Team Member",,2026-10-02,09:20,18:00,8,0,0,0,0,20,0,late,completed', $csv);

        $filtered = $this->get('/reports/attendance/export?from=2026-10-01&to=2026-10-31&employee_id='.$other->id)->streamedContent();
        $this->assertStringNotContainsString('REP-1', $filtered);

        $scoped = $this->actingAs($managerUser)->get('/reports/attendance/export?from=2026-10-01&to=2026-10-31')->assertOk()->streamedContent();
        $this->assertStringContainsString('REP-1', $scoped);
        $this->assertStringNotContainsString('REP-2', $scoped);
        $this->get('/reports/payroll/export?from=2026-10-01&to=2026-10-31')->assertForbidden();
        $this->get('/reports/attendance/export?from=2026-10-31&to=2026-10-01')->assertSessionHasErrors('to');
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported', 'actor_id' => $managerUser->id]);
    }

    public function test_audit_export_includes_actor_action_and_record(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin);
        app(\App\Services\AuditLogger::class)->record('demo.event', $admin, ['k' => 'v']);
        $csv = $this->get('/reports/audit/export?from='.today()->toDateString().'&to='.today()->toDateString())->assertOk()->streamedContent();
        $this->assertStringContainsString('admin@example.com,demo.event,User,'.$admin->id, $csv);
    }

    public function test_attendance_classification_late_half_day_and_absent(): void
    {
        Carbon::setTestNow('2026-10-09 20:00:00');
        $employee = AttendanceEmployee::create(['employee_code' => 'CLS-1', 'name' => 'Classified', 'is_active' => true, 'work_start' => '09:30:00', 'daily_work_hours' => 8, 'joined_at' => '2026-10-01']);
        \App\Models\PolicyRule::create(['rule_key' => 'attendance.rules', 'category' => 'attendance', 'name' => 'Rules', 'configuration' => ['grace_minutes' => 10, 'half_day_hours' => 4], 'is_active' => true]);
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:35:00', 'check_out' => '2026-10-05 18:00:00', 'active_seconds' => 8 * 3600, 'status' => 'completed']);
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => '2026-10-06', 'check_in' => '2026-10-06 09:45:00', 'check_out' => '2026-10-06 18:00:00', 'active_seconds' => 8 * 3600, 'status' => 'completed']);
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => '2026-10-07', 'check_in' => '2026-10-07 09:00:00', 'check_out' => '2026-10-07 12:00:00', 'active_seconds' => 3 * 3600, 'status' => 'completed']);
        $days = app(\App\Services\AttendanceService::class)->days($employee, Carbon::parse('2026-10-04'), Carbon::parse('2026-10-09'))->pluck('status', 'date');
        $this->assertSame(['2026-10-04' => 'weekend', '2026-10-05' => 'present', '2026-10-06' => 'late', '2026-10-07' => 'half_day', '2026-10-08' => 'absent', '2026-10-09' => 'upcoming'], $days->all());
        Carbon::setTestNow();
    }

    public function test_security_headers_and_inactive_accounts(): void
    {
        $response = $this->get('/login');
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy');
        $user = User::factory()->create(['password' => 'InactivePass123!', 'is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'InactivePass123!'])->assertSessionHasErrors('email');
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $this->assertSame(0, AuditLog::where('action', 'like', 'credential.%')->count());
    }

    public function test_holidays_are_added_with_a_date_picker_and_saved(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        Livewire::actingAs($admin)->test(\App\Livewire\System\Settings::class)
            ->set('holidays', '')
            ->call('addHoliday')->assertHasErrors('newHoliday')
            ->set('newHoliday', '2026-12-25')->call('addHoliday')
            ->set('newHoliday', '2026-11-08')->call('addHoliday')
            ->set('newHoliday', '2026-12-25')->call('addHoliday')
            ->assertSet('holidays', "2026-11-08\n2026-12-25")
            ->call('removeHoliday', '2026-11-08')
            ->assertSet('holidays', '2026-12-25')
            ->call('saveCalendar')->assertHasNoErrors();

        $calendar = app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'calendar.working_days', today()->toDateString(), []);
        $this->assertSame(['2026-12-25'], $calendar['holidays']);
    }
}
