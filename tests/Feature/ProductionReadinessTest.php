<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeeLifecycleCase;
use App\Models\EmployeeFinalSettlement;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PolicyRule;
use App\Models\SalaryAdvance;
use App\Models\SalaryPayment;
use App\Models\SalaryProfile;
use App\Models\Task;
use App\Models\User;
use App\Services\FinalSettlementService;
use App\Services\LeaveAccrualService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use App\Livewire\Lifecycle\Settlements;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_accepts_legacy_password_hash_and_rehashes_to_current_algorithm(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $legacyPassword = 'LegacyLoginPass123!';
        $admin->forceFill(['password' => password_hash($legacyPassword, PASSWORD_ARGON2I)])->save();

        $this->post('/login', ['email' => $admin->email, 'password' => $legacyPassword])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame('bcrypt', password_get_info($admin->fresh()->password)['algoName']);
    }

    public function test_employer_policy_history_and_employee_overrides_are_effective_dated(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'POLICY-HISTORY', 'name' => 'Policy Employee', 'is_active' => true]);
        $service = app(PeopleOperationsService::class);

        $service->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation', ['working_days_per_month' => 20, 'attendance_proration' => true, 'include_paid_leave' => true], null, $admin->id, '2026-01-01');
        $service->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation', ['working_days_per_month' => 18, 'attendance_proration' => false, 'include_paid_leave' => false], null, $admin->id, '2026-10-01');
        $service->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation override', ['working_days_per_month' => 22], $employee->id, $admin->id, '2026-10-15');

        $resolver = app(\App\Services\PolicyConfigurationService::class);
        $this->assertSame(20, $resolver->forEmployee($employee, 'payroll.calculation', '2026-09-30')['working_days_per_month']);
        $this->assertSame(18, $resolver->forEmployee($employee, 'payroll.calculation', '2026-10-10')['working_days_per_month']);
        $this->assertSame(22, $resolver->forEmployee($employee, 'payroll.calculation', '2026-10-15')['working_days_per_month']);
    }

    public function test_payroll_statutory_components_are_configurable_and_have_no_default_legal_rates(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'STAT-1', 'name' => 'Statutory Test', 'is_active' => true]);
        SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-10-01', 'configured_by' => $admin->id, 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:00:00', 'status' => 'working']);
        $rule = PolicyRule::firstOrCreate(['rule_key' => 'payroll.statutory'], ['category' => 'payroll', 'name' => 'Statutory components', 'configuration' => ['components' => []], 'is_active' => true]);
        $this->assertSame([], $rule->configuration['components']);
        $rule->update(['configuration' => ['components' => [['key' => 'configured-fund', 'name' => 'Configured deduction', 'type' => 'deduction', 'basis' => 'basic_salary', 'rate_bps' => 500], ['key' => 'fixed-benefit', 'name' => 'Configured benefit', 'type' => 'earning', 'fixed_amount' => 100]]]]);
        $run = app(\App\Services\HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(1100.0, (float) $item->gross_amount);
        $this->assertSame(50.0, (float) $item->statutory_deductions);
        $this->assertSame(1050.0, (float) $item->net_amount);
        $this->assertSame('Configured deduction', $item->calculation_breakdown['statutory_components'][0]['name']);
    }

    public function test_leave_accrual_tracks_booked_days_employee_override_and_expiring_carry_forward(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'LEAVE-1', 'name' => 'Leave Test', 'is_active' => true]);
        // A calendar-year (January–December) leave year; the April–March default is covered in LeaveYearAndLedgerTest.
        \App\Models\PolicyRule::create(['rule_key' => 'leave.year', 'category' => 'leave', 'name' => 'Leave year', 'configuration' => ['start_month' => 1], 'is_active' => true]);
        $type = LeaveType::create(['name' => 'Carry PTO', 'annual_allowance' => 0, 'monthly_accrual' => 1, 'carry_forward' => true, 'carry_forward_limit' => 5, 'carry_forward_expiry_months' => 1, 'allow_advance' => true, 'paid' => true, 'is_active' => true]);
        LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2025, 'entitled_days' => 10, 'used_days' => 4]);
        \App\Models\EmployeePolicyOverride::create(['employee_id' => $employee->id, 'rule_key' => 'leave.accrual.'.$type->id, 'configuration' => ['days_per_month' => 2, 'carry_forward_limit' => 3, 'carry_forward_expiry_months' => 1]]);
        Carbon::setTestNow('2026-01-31');
        $service = app(LeaveAccrualService::class);
        $service->accrueThrough(2026, 1, $admin->id);
        $service->accrueThrough(2026, 1, $admin->id);
        $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2026)->firstOrFail();
        $this->assertSame(3.0, (float) $balance->carried_days);
        $this->assertSame(2.0, (float) $balance->accrued_days);
        $this->assertSame('2026-02-28', $balance->carried_expires_on->toDateString());
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-01-12', 'ends_on' => '2026-01-13', 'days' => 2, 'reason' => 'planned', 'status' => 'pending']);
        $this->assertSame(3.0, app(LeaveAccrualService::class)->balance($employee, $type, 2026)['remaining']);
        Carbon::setTestNow('2026-03-01');
        $this->assertSame(0.0, app(LeaveAccrualService::class)->balance($employee, $type, 2026)['carried']);
        Carbon::setTestNow();
    }

    public function test_csv_report_export_is_a_real_download_and_permission_checked(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        AttendanceEmployee::create(['employee_code' => 'CSV-1', 'name' => 'CSV Employee', 'is_active' => true]);
        AttendanceEmployee::create(['employee_code' => 'CSV-2', 'name' => '=HYPERLINK("https://example.invalid")', 'is_active' => true]);
        $response = $this->actingAs($admin)->get('/reports/performance/export?from=2026-10-01&to=2026-10-31');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertHeader('content-disposition');
        $content = $response->streamedContent();
        $this->assertStringContainsString('"Employee code","Employee name","Completed tasks","Attendance days","Approved leave days"', $content);
        $this->assertStringContainsString('CSV-1,"CSV Employee"', $content);
        $this->assertStringNotContainsString('CSV-2,"=HYPERLINK', $content);
        $this->assertStringContainsString("'=HYPERLINK", $content);
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get('/reports/performance/export?from=2026-10-01&to=2026-10-31')->assertForbidden();
    }

    public function test_offline_task_action_is_idempotent_and_rejects_conflicting_reuse(): void
    {
        $this->seed();
        $user = User::factory()->create(); $user->assignRole('Employee');
        $employee = AttendanceEmployee::create(['employee_code' => 'SYNC-1', 'name' => 'Sync Employee', 'is_active' => true, 'user_id' => $user->id]);
        $task = Task::create(['title' => 'Offline task', 'assignee_id' => $employee->id, 'created_by' => $user->id, 'status' => 'todo', 'priority' => 'normal']);
        $token = $user->createToken('offline-sync')->plainTextToken;
        $action = ['action_id' => 'task-action-0001', 'type' => 'task.start', 'entity_id' => $task->id, 'base_version' => 1, 'payload' => []];
        $this->withToken($token)->postJson('/api/sync/actions', ['actions' => [$action]])->assertOk()->assertJsonPath('results.0.success', true)->assertJsonPath('results.0.replayed', false);
        $this->withToken($token)->postJson('/api/sync/actions', ['actions' => [$action]])->assertOk()->assertJsonPath('results.0.replayed', true);
        $this->assertDatabaseCount('task_time_entries', 1);
        $this->assertSame(2, (int) $task->fresh()->server_version);
        $action['payload'] = ['changed' => true];
        $this->withToken($token)->postJson('/api/sync/actions', ['actions' => [$action]])->assertStatus(207)->assertJsonPath('results.0.conflict', true);
        $pause = ['action_id' => 'task-action-0002', 'type' => 'task.pause', 'entity_id' => $task->id, 'base_version' => 2, 'payload' => []];
        $this->withToken($token)->postJson('/api/sync/actions', ['actions' => [$pause]])->assertOk();
        $resume = ['action_id' => 'task-action-0003', 'type' => 'task.resume', 'entity_id' => $task->id, 'base_version' => 3, 'payload' => []];
        $this->withToken($token)->postJson('/api/sync/actions', ['actions' => [$resume]])->assertOk();
        $complete = ['action_id' => 'task-action-0004', 'type' => 'task.complete', 'entity_id' => $task->id, 'base_version' => 4, 'payload' => []];
        $this->withToken($token)->postJson('/api/sync/actions', ['actions' => [$complete]])->assertOk();
        $this->withToken($token)->postJson('/api/sync/actions', ['actions' => [$complete]])->assertOk()->assertJsonPath('results.0.replayed', true);
        $this->assertDatabaseCount('task_time_entries', 2);
        $this->assertSame('completed', $task->fresh()->status);
        $this->withToken($token)->getJson('/api/sync/status?action_ids[]=task-action-0001')->assertOk()->assertJsonPath('actions.0.status', 'processed');
        $date=today()->toDateString();
        $daily=['action_id'=>'daily-status-0001','type'=>'daily_status.submit','base_version'=>0,'payload'=>['work_date'=>$date,'summary'=>'Completed offline daily work']];
        $this->withToken($token)->postJson('/api/sync/actions',['actions'=>[$daily]])->assertOk()->assertJsonPath('results.0.server_version',1);
        $this->withToken($token)->postJson('/api/sync/actions',['actions'=>[$daily]])->assertOk()->assertJsonPath('results.0.replayed',true);
        $this->assertDatabaseCount('daily_work_statuses',1);
        $stale=$daily;$stale['action_id']='daily-status-stale';
        $this->withToken($token)->postJson('/api/sync/actions',['actions'=>[$stale]])->assertStatus(207)->assertJsonPath('results.0.conflict',true);
        $update=$daily;$update['action_id']='daily-status-update';$update['base_version']=1;$update['payload']['summary']='Updated after refresh';
        $this->withToken($token)->postJson('/api/sync/actions',['actions'=>[$update]])->assertOk()->assertJsonPath('results.0.server_version',2);
    }

    public function test_offline_sync_requires_authentication_and_task_assignment_scope(): void
    {
        $this->seed();
        $this->postJson('/api/sync/actions', ['actions' => []])->assertUnauthorized();
        $actor = User::factory()->create(); $actor->assignRole('Employee');
        $actorEmployee = AttendanceEmployee::create(['employee_code' => 'SYNC-ACTOR', 'name' => 'Sync Actor', 'is_active' => true, 'user_id' => $actor->id]);
        $otherEmployee = AttendanceEmployee::create(['employee_code' => 'SYNC-OTHER', 'name' => 'Other Employee', 'is_active' => true]);
        $task = Task::create(['title' => 'Not assigned to actor', 'assignee_id' => $otherEmployee->id, 'created_by' => null, 'status' => 'todo', 'priority' => 'normal']);
        $token = $actor->createToken('scoped-sync')->plainTextToken;
        $this->withToken($token)->postJson('/api/sync/actions', ['actions' => [['action_id' => 'foreign-task-action', 'type' => 'task.start', 'entity_id' => $task->id, 'base_version' => 1, 'payload' => []]]])->assertStatus(207)->assertJsonPath('results.0.status', 403);
        $this->assertDatabaseCount('task_time_entries', 0);
        $this->assertSame(1, (int) $task->fresh()->server_version);
    }

    public function test_compliance_assesses_configured_late_task_and_unapproved_absence_once(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'COMP-1', 'name' => 'Compliance Employee', 'is_active' => true, 'joined_at' => '2026-09-01']);
        $task = Task::create(['title' => 'Late deliverable', 'assignee_id' => $employee->id, 'created_by' => $admin->id, 'status' => 'in_progress', 'priority' => 'normal', 'due_on' => '2026-09-01']);
        $type = LeaveType::create(['name' => 'Compliance PTO', 'annual_allowance' => 10, 'monthly_accrual' => 0, 'carry_forward' => false, 'carry_forward_limit' => 0, 'allow_advance' => false, 'paid' => true, 'is_active' => true]);
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-09-02', 'ends_on' => '2026-09-02', 'days' => 1, 'reason' => 'approved absence', 'status' => 'approved']);
        PolicyRule::create(['rule_key' => 'task.late_penalty', 'category' => 'compliance', 'name' => 'Late task', 'configuration' => ['amount_per_day' => 20], 'is_active' => true]);
        PolicyRule::create(['rule_key' => 'attendance.unauthorized_absence', 'category' => 'compliance', 'name' => 'Unauthorized absence', 'configuration' => ['amount_per_day' => 15], 'is_active' => true]);
        $service = app(PeopleOperationsService::class);
        $this->assertSame(2, $service->assessCompliance('2026-09-01', '2026-09-02', $admin->id));
        $this->assertSame(0, $service->assessCompliance('2026-09-01', '2026-09-02', $admin->id));
        $this->assertDatabaseHas('employee_penalties', ['employee_id' => $employee->id, 'source_key' => 'late_task:'.$task->id.':'.$this->ruleId('task.late_penalty'), 'amount' => '20.00']);
        $this->assertDatabaseHas('employee_penalties', ['employee_id' => $employee->id, 'source_key' => 'absence:'.$employee->id.':2026-09-01:'.$this->ruleId('attendance.unauthorized_absence'), 'amount' => '15.00']);
        $this->assertDatabaseMissing('employee_penalties', ['employee_id' => $employee->id, 'effective_on' => '2026-09-02']);
    }

    public function test_absence_without_a_leave_mail_is_deducted_automatically_unless_hr_approval_is_chosen(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['email' => 'nomail@example.com']);
        $employee = AttendanceEmployee::create(['employee_code' => 'NOMAIL-1', 'name' => 'No Mail', 'email' => $user->email, 'user_id' => $user->id, 'is_active' => true, 'joined_at' => '2026-09-01']);
        $type = LeaveType::create(['name' => 'Mail PTO', 'annual_allowance' => 10, 'monthly_accrual' => 0, 'carry_forward' => false, 'carry_forward_limit' => 0, 'allow_advance' => false, 'paid' => true, 'is_active' => true]);
        // 2 Sep: leave mail sent, not yet approved: no fine.
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-09-02', 'ends_on' => '2026-09-02', 'days' => 1, 'reason' => 'sent', 'status' => 'pending']);
        PolicyRule::create(['rule_key' => 'attendance.unauthorized_absence', 'category' => 'compliance', 'name' => 'Unauthorized absence', 'configuration' => ['amount_per_day' => 500], 'is_active' => true]);
        $service = app(PeopleOperationsService::class);

        // 1 Sep: absent, no leave mail: 500 deducted straight away (approved), and the employee is told.
        $this->assertSame(1, $service->assessCompliance('2026-09-01', '2026-09-02', $admin->id));
        $penalty = \App\Models\EmployeePenalty::where('employee_id', $employee->id)->sole();
        $this->assertSame('approved', $penalty->status);
        $this->assertSame('500.00', (string) $penalty->amount);
        $this->assertSame('2026-09-01', $penalty->effective_on->toDateString());
        $this->assertStringContainsString('no leave request', $penalty->reason);
        $this->assertTrue($user->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'will be deducted in payroll')));

        // "Wait for HR approval" chosen: the next one stays pending.
        $service->setPolicy('attendance.unauthorized_absence', 'compliance', 'Unauthorized absence', ['amount_per_day' => 500, 'auto_deduct' => 0], null, $admin->id, '2026-09-01');
        $this->assertSame(1, $service->assessCompliance('2026-09-03', '2026-09-03', $admin->id));
        $this->assertSame('pending', \App\Models\EmployeePenalty::where('employee_id', $employee->id)->whereDate('effective_on', '2026-09-03')->sole()->status);
    }

    public function test_final_settlement_uses_salary_advance_and_unpaid_payroll_payment_data(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'EXIT-DATA', 'name' => 'Settlement Data', 'is_active' => true, 'joined_at' => '2026-10-01']);
        SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-10-01', 'configured_by' => $admin->id, 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:00:00', 'status' => 'working']);
        $run = PayrollRun::create(['period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'locked', 'created_by' => $admin->id]);
        $item = PayrollItem::create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'gross_amount' => 500, 'net_amount' => 500, 'calculation_data' => []]);
        SalaryPayment::create(['payroll_item_id' => $item->id, 'amount' => 200, 'paid_on' => '2026-10-01', 'method' => 'bank', 'reference' => 'partial-1', 'recorded_by' => $admin->id]);
        SalaryAdvance::create(['employee_id' => $employee->id, 'amount' => 1000, 'recovered_amount' => 0, 'disbursed_amount' => 1000, 'installment_amount' => 200, 'requested_on' => '2026-09-10', 'disbursed_on' => '2026-09-11', 'status' => 'disbursed', 'reason' => 'advance']);
        $case = EmployeeLifecycleCase::create(['employee_id' => $employee->id, 'kind' => 'offboarding', 'status' => 'open', 'effective_on' => '2026-10-05', 'opened_by' => $admin->id]);
        $this->assertSame(1, SalaryProfile::where('employee_id', $employee->id)->whereDate('effective_from', '<=', '2026-10-05')->count());
        $this->assertSame(1, AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', '>=', '2026-10-01')->whereDate('work_date', '<=', '2026-10-05')->count());
        $result = app(FinalSettlementService::class)->calculate($case, '2026-10-05');
        $this->assertSame(1300.0, $result['gross_amount']);
        $this->assertSame(1000.0, $result['deductions']);
        $this->assertSame(300.0, $result['net_amount']);
        $this->assertSame(300.0, $result['calculation_breakdown']['unpaid_locked_payroll']);
    }

    public function test_final_settlement_carries_partial_payroll_locked_before_the_period_ends_without_double_counting(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'EXIT-EARLY-LOCK', 'name' => 'Early Locked Payroll', 'is_active' => true, 'joined_at' => '2026-10-01']);
        SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-10-01', 'configured_by' => $admin->id, 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:00:00', 'status' => 'working']);
        \App\Models\OvertimeRequest::create(['employee_id' => $employee->id, 'work_date' => '2026-10-05', 'requested_minutes' => 60, 'approved_minutes' => 60, 'reason' => 'Included in locked payroll', 'status' => 'approved']);
        $run = PayrollRun::create(['period_starts_on' => '2026-10-01', 'period_ends_on' => '2026-10-31', 'status' => 'locked', 'created_by' => $admin->id, 'locked_by' => $admin->id, 'locked_at' => '2026-10-05 12:00:00']);
        $item = PayrollItem::create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'gross_amount' => 2000, 'net_amount' => 1800, 'calculation_data' => []]);
        SalaryPayment::create(['payroll_item_id' => $item->id, 'amount' => 500, 'paid_on' => '2026-10-05', 'method' => 'bank', 'reference' => 'early-lock-partial', 'recorded_by' => $admin->id]);
        SalaryAdvance::create(['employee_id' => $employee->id, 'amount' => 1000, 'recovered_amount' => 0, 'disbursed_amount' => 1000, 'installment_amount' => 250, 'requested_on' => '2026-10-01', 'disbursed_on' => '2026-10-02', 'status' => 'disbursed', 'reason' => 'outstanding recovery']);
        $case = EmployeeLifecycleCase::create(['employee_id' => $employee->id, 'kind' => 'offboarding', 'status' => 'open', 'effective_on' => '2026-10-05', 'opened_by' => $admin->id]);

        $result = app(FinalSettlementService::class)->calculate($case, '2026-10-05');

        $this->assertSame(1300.0, $result['gross_amount']);
        $this->assertSame(1000.0, $result['deductions']);
        $this->assertSame(300.0, $result['net_amount']);
        $this->assertSame(0.0, $result['calculation_breakdown']['salary_earned']);
        $this->assertSame(0, $result['calculation_breakdown']['approved_overtime_minutes']);
        $this->assertSame(1300.0, $result['calculation_breakdown']['unpaid_locked_payroll']);
    }

    public function test_final_settlement_uses_employer_calendar_work_hours_and_overtime_policy(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'SETTLE-POLICY', 'name' => 'Policy Exit', 'joined_at' => '2026-10-01', 'is_active' => true]);
        SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-10-01', 'configured_by' => $admin->id, 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:00:00', 'status' => 'working']);
        \App\Models\OvertimeRequest::create(['employee_id' => $employee->id, 'work_date' => '2026-10-05', 'requested_minutes' => 60, 'approved_minutes' => 60, 'reason' => 'approved work', 'status' => 'approved']);
        $service = app(PeopleOperationsService::class);
        $service->setPolicy('calendar.working_days', 'calendar', 'Work days', ['days' => [1,2,3,4,5], 'holidays' => []], null, $admin->id, '2026-10-01');
        $service->setPolicy('payroll.calculation', 'payroll', 'Payroll rules', ['working_days_per_month' => 20, 'standard_daily_hours' => 10], null, $admin->id, '2026-10-01');
        $service->setPolicy('overtime.multiplier', 'payroll', 'Overtime', ['multiplier' => 2], null, $admin->id, '2026-10-01');
        $case = $service->openLifecycle($employee, 'offboarding', '2026-10-05', null, $admin->id);

        $calculation = app(FinalSettlementService::class)->calculate($case, '2026-10-05');
        $this->assertSame(1320.0, $calculation['gross_amount']);
        $this->assertSame(220.0, $calculation['calculation_breakdown']['overtime_amount']);
        $this->assertSame(20, $calculation['calculation_breakdown']['employer_policy']['working_days_per_month']);
        $this->assertSame(10.0, $calculation['calculation_breakdown']['employer_policy']['standard_daily_hours']);
    }

    public function test_final_settlement_supports_authorized_partial_payments_and_prevents_overpayment(): void
    {
        $this->seed();
        $admin=User::where('email','admin@example.com')->firstOrFail();
        $employee=AttendanceEmployee::create(['employee_code'=>'SETTLE-PAY','name'=>'Settlement Payee','is_active'=>true]);
        $case=EmployeeLifecycleCase::create(['employee_id'=>$employee->id,'kind'=>'offboarding','status'=>'open','effective_on'=>today(),'opened_by'=>$admin->id]);
        $settlement=EmployeeFinalSettlement::create(['lifecycle_case_id'=>$case->id,'gross_amount'=>1000,'deductions'=>0,'net_amount'=>1000,'status'=>'approved','prepared_by'=>$admin->id,'approved_by'=>$admin->id,'approved_at'=>now(),'as_of_date'=>today()]);
        $this->actingAs($admin);
        Livewire::test(Settlements::class)->set('case',$case->id)->set('paymentAmount','400')->set('paymentReference','first-half')->call('pay')->assertHasNoErrors();
        $this->assertSame('partially_paid',$settlement->fresh()->status);
        $this->assertDatabaseHas('employee_final_settlement_payments',['final_settlement_id'=>$settlement->id,'amount'=>'400.00','reference'=>'first-half']);
        Livewire::test(Settlements::class)->set('case',$case->id)->set('paymentAmount','700')->call('pay')->assertHasErrors('settlementPaymentAmount');
        Livewire::test(Settlements::class)->set('case',$case->id)->set('paymentAmount','600')->call('pay')->assertHasNoErrors();
        Livewire::test(Settlements::class)->set('case',$case->id)->set('paymentAmount','1')->call('pay')->assertHasErrors('settlementPaymentAmount');
        $this->assertSame('paid',$settlement->fresh()->status);
        $this->assertSame(1000.0,(float)$settlement->payments()->sum('amount'));
    }

    private function ruleId(string $key): int { return (int) PolicyRule::where('rule_key', $key)->value('id'); }
}
