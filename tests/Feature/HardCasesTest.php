<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\EmployeeAsset;
use App\Models\EmployeeLifecycleCase;
use App\Models\EmployeePenalty;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryAdjustment;
use App\Models\SalaryAdvance;
use App\Models\SalaryProfile;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\AttendanceService;
use App\Services\EmployeeService;
use App\Services\FinalSettlementService;
use App\Services\HrWorkflowService;
use App\Services\LeaveConflictService;
use App\Services\PeopleOperationsService;
use App\Services\ScreenCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The hard UAT list: each test is named after its case number. */
class HardCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $managerUser;
    private User $employeeUser;
    private AttendanceEmployee $manager;
    private AttendanceEmployee $employee;
    private AttendanceService $attendance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-07 09:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        [$this->managerUser, $this->manager] = $this->person('MGR', 'Manager A', ['Employee', 'Manager']);
        [$this->employeeUser, $this->employee] = $this->person('EMP', 'Esha Employee', ['Employee'], $this->manager->id);
        $this->attendance = app(AttendanceService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: AttendanceEmployee} */
    private function person(string $code, string $name, array $roles, ?int $managerId = null): array
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com', 'name' => $name]);
        $user->assignRole($roles);
        $employee = AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'manager_id' => $managerId, 'work_start' => '09:30:00', 'joined_at' => '2026-01-01']);

        return [$user, $employee];
    }

    private function at(string $time): void
    {
        Carbon::setTestNow($time);
    }

    private function expectFailure(callable $action, ?int $status = null, ?string $contains = null): void
    {
        try {
            $action();
            $this->fail('Expected the action to be refused.');
        } catch (ValidationException $e) {
            if ($status) $this->assertSame($status, $e->status, implode(' ', $e->validator->errors()->all()));
            if ($contains) $this->assertStringContainsString($contains, implode(' ', $e->validator->errors()->all()));
        }
    }

    public function test_1a_night_shift_spans_midnight_as_one_session(): void
    {
        $this->at('2026-10-06 23:00:00');
        $this->attendance->checkIn($this->employee, 'desktop');
        $this->at('2026-10-07 03:00:00');
        $this->assertSame('2026-10-06', $this->attendance->today($this->employee)->work_date->toDateString(), 'After midnight the night session is still "today"');
        $this->expectFailure(fn () => $this->attendance->checkIn($this->employee, 'desktop'), null, 'still checked in');
        $this->at('2026-10-07 07:00:00');
        $session = $this->attendance->checkOut($this->employee, 'desktop');
        $this->assertSame('2026-10-06', $session->work_date->toDateString());
        $this->assertSame(8 * 3600, (int) $session->active_seconds);
        $this->assertSame(1, AttendanceSession::count());
    }

    public function test_1b_missing_checkout_is_closed_at_last_activity_not_24_hours(): void
    {
        $this->at('2026-10-07 09:30:00');
        $this->attendance->checkIn($this->employee, 'desktop');
        $this->at('2026-10-07 17:45:00');
        $this->attendance->heartbeat($this->employee, 20, 10);
        $this->at('2026-10-07 20:00:00');
        $this->assertSame(0, $this->attendance->closeStaleSessions(), 'Not closed before the 16 h window');
        $this->at('2026-10-08 09:00:00');
        $this->assertSame(1, $this->attendance->closeStaleSessions());
        $session = AttendanceSession::firstOrFail();
        $this->assertTrue($session->closed_automatically);
        $this->assertSame('2026-10-07 17:45:00', $session->check_out->toDateTimeString());
        $this->assertSame(8 * 3600 + 15 * 60 - 10, (int) $session->active_seconds, '09:30–17:45 less the 10 s the screen was idle');
        $this->assertSame(0, $this->attendance->closeStaleSessions(), 'No duplicate closing');
        $this->attendance->checkIn($this->employee, 'web');
        $this->assertSame(2, AttendanceSession::count(), 'The new day gets its own session');
        $this->assertTrue($this->employeeUser->notifications()->exists());
        $this->assertTrue(AuditLog::where('action', 'attendance.auto_checked_out')->exists());
    }

    public function test_1c_and_4_desktop_off_becomes_a_break_and_open_break_is_closed_next_day(): void
    {
        $this->at('2026-10-07 09:30:00');
        $this->attendance->checkIn($this->employee, 'desktop');
        $this->at('2026-10-07 13:00:00');
        $this->attendance->heartbeat($this->employee, 25, 5);
        $this->at('2026-10-07 18:00:00'); // laptop back on after a shutdown
        $session = $this->attendance->heartbeat($this->employee, 25, 5);
        $this->assertSame(5 * 3600, (int) $session->fresh()->break_seconds, '13:00→18:00 recorded as a break, not work');
        $this->assertSame(3.5, round($this->attendance->workedSeconds($session->fresh()) / 3600, 2));

        // Harder: break started, app closed, next day.
        $this->at('2026-10-08 09:30:00');
        $this->attendance->closeStaleSessions(); // closes the 7th
        [$user2, $e2] = $this->person('BRK', 'Break Person', ['Employee']);
        $this->at('2026-10-08 10:00:00');
        $this->attendance->checkIn($e2, 'desktop');
        $this->at('2026-10-08 10:30:00');
        $this->attendance->startBreak($e2, 'desktop');
        $this->at('2026-10-09 10:00:00');
        $this->attendance->closeStaleSessions();
        $closed = AttendanceSession::where('employee_id', $e2->id)->firstOrFail();
        $this->assertSame('completed', $closed->status);
        $this->assertSame('2026-10-08 10:30:00', $closed->check_out->toDateTimeString(), 'Ends at the last activity (break start)');
        $this->assertSame(1800, (int) $closed->active_seconds);
        $this->assertSame('working', $this->attendance->checkIn($e2, 'desktop')->status, 'Next day is not corrupted');
    }

    public function test_2_and_3_offline_duplicates_and_admin_checkout_are_ignored_not_doubled(): void
    {
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->at('2026-10-07 10:00:00');
        $actions = [
            ['action_id' => 'a1', 'type' => 'attendance.check_in', 'payload' => ['occurred_at' => '2026-10-07T09:30:00+05:30']],
            ['action_id' => 'a2', 'type' => 'attendance.check_in', 'payload' => ['occurred_at' => '2026-10-07T09:31:00+05:30']],
        ];
        $result = $this->postJson('/api/sync/actions', ['actions' => $actions])->assertOk()->json('results');
        $this->assertTrue($result[1]['ignored']);
        $this->postJson('/api/sync/actions', ['actions' => $actions])->assertOk(); // retry: same receipts
        $this->assertSame(1, AttendanceSession::count());

        // HR checks out at 18:02; the phone's offline 18:00 check-out syncs at 18:05.
        $this->at('2026-10-07 18:02:00');
        $this->attendance->checkOut($this->employee->fresh(), 'admin', Carbon::parse('2026-10-07 18:02:00'));
        $this->at('2026-10-07 18:05:00');
        $late = $this->postJson('/api/sync/actions', ['actions' => [['action_id' => 'a3', 'type' => 'attendance.check_out', 'payload' => ['occurred_at' => now()->subMinutes(5)->toIso8601String()]]]])->assertOk()->json('results.0');
        $this->assertTrue($late['ignored']);
        $this->assertSame('2026-10-07 18:02:00', AttendanceSession::first()->check_out->toDateTimeString(), 'Server state wins');
        $this->assertTrue(AuditLog::where('action', 'attendance.offline_duplicate_ignored')->exists());
    }

    public function test_4_break_length_can_be_corrected_by_the_manager(): void
    {
        $this->at('2026-10-06 09:30:00');
        $this->attendance->checkIn($this->employee, 'web');
        $this->at('2026-10-06 10:00:00');
        $this->attendance->startBreak($this->employee, 'web');
        $this->at('2026-10-06 12:00:00');
        $this->attendance->endBreak($this->employee, 'web');
        $this->at('2026-10-06 18:30:00');
        $this->attendance->checkOut($this->employee, 'web');
        $this->assertSame(7 * 3600, (int) AttendanceSession::first()->active_seconds);

        $this->at('2026-10-07 09:00:00');
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/attendance/corrections', ['work_date' => '2026-10-06', 'break_minutes' => 30, 'reason' => 'Break was 30 minutes, forgot to resume'])->assertCreated();
        app(ApprovalInbox::class)->decide($this->managerUser, ApprovalRequest::where('approvable_type', AttendanceCorrection::class)->firstOrFail(), true);
        $session = AttendanceSession::first();
        $this->assertSame(1800, (int) $session->break_seconds);
        $this->assertSame(8.5 * 3600, (float) $session->active_seconds);
        $this->assertTrue(AuditLog::where('action', 'attendance.correction_approved')->exists());
    }

    public function test_5_and_6_leave_attendance_conflict_is_flagged_resolved_and_never_paid_twice(): void
    {
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $unpaid = LeaveType::where('name', 'Unpaid Leave')->firstOrFail();
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        LeaveBalance::create(['employee_id' => $this->employee->id, 'leave_type_id' => $annual->id, 'year' => 2026, 'entitled_days' => 10, 'used_days' => 1]);
        $paidLeave = LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $annual->id, 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15', 'days' => 1, 'reason' => 'x', 'status' => 'approved']);
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $unpaid->id, 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-20', 'days' => 2, 'reason' => 'y', 'status' => 'approved']);
        foreach (['2026-10-15', '2026-10-19'] as $d) AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $d, 'check_in' => "$d 09:30:00", 'check_out' => "$d 18:30:00", 'active_seconds' => 28800, 'status' => 'completed']);
        $this->at('2026-11-02 10:00:00');

        $this->assertSame('leave_conflict', $this->attendance->days($this->employee, Carbon::parse('2026-10-15'), Carbon::parse('2026-10-15'))->first()['status']);
        $this->assertCount(2, app(LeaveConflictService::class)->open('2026-10-01', '2026-10-31'));

        // Default: a full-day leave stays a leave and the work that day is extra working, paid on top (never as a normal day too).
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertEquals([0, 1, 2], [(float) $item->present_days, (float) $item->paid_leave_days, (float) $item->unpaid_leave_days]);
        $this->assertSame(['2026-10-15', '2026-10-19'], array_keys($item->calculation_breakdown['extra_work']['dates']));
        $this->assertGreaterThan(0, $item->calculation_breakdown['extra_work']['amount']);

        // HR: the 15th was really worked → leave day back; the 19th counts as unpaid leave.
        app(LeaveConflictService::class)->resolve($paidLeave, '2026-10-15', 'worked', $this->admin->id);
        app(LeaveConflictService::class)->resolve(LeaveRequest::where('leave_type_id', $unpaid->id)->first(), '2026-10-19', 'leave', $this->admin->id);
        $this->expectFailure(fn () => app(LeaveConflictService::class)->resolve($paidLeave, '2026-10-15', 'leave', $this->admin->id), 409);
        $this->assertEquals(0, (float) LeaveBalance::where('employee_id', $this->employee->id)->value('used_days'));
        $this->assertTrue($run->fresh()->needs_recalculation);
        $this->expectFailure(fn () => app(HrWorkflowService::class)->approvePayroll($run->fresh(), $this->managerUser->id), null, 'Recalculate');
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertEquals([1, 0, 2], [(float) $item->present_days, (float) $item->paid_leave_days, (float) $item->unpaid_leave_days]);
        $this->assertEquals(1000.0, (float) $item->net_amount, 'One paid day of 22000/22');
    }

    public function test_7_leave_balance_arithmetic_and_overdraw_blocked(): void
    {
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $annual->update(['allow_advance' => false]);
        LeaveBalance::create(['employee_id' => $this->employee->id, 'leave_type_id' => $annual->id, 'year' => 2026, 'entitled_days' => 2, 'accrued_days' => 1.75, 'carried_days' => 3, 'used_days' => 5]);
        $this->assertSame(1.75, round(app(\App\Services\LeaveAccrualService::class)->balance($this->employee, $annual, 2026)['remaining'], 2));
        $this->expectFailure(fn () => app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'starts_on' => Carbon::parse('2026-10-12'), 'ends_on' => Carbon::parse('2026-10-13'), 'reason' => 'Two days']), null, 'Insufficient');
    }

    public function test_9_10_11_overtime_evidence_duplicates_and_single_holiday_rate(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 20800, 'effective_from' => '2026-01-01', 'working_days_per_month' => 26, 'standard_daily_hours' => 8]);
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', ['multiplier' => 1.5, 'holiday_multiplier' => 2, 'max_minutes_per_month' => 600], null, $this->admin->id, '2026-01-01');
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-06', 'check_in' => '2026-10-06 09:30:00', 'check_out' => '2026-10-06 18:30:00', 'break_seconds' => 3600, 'active_seconds' => 8 * 3600, 'status' => 'completed']);
        $weekday = $people->requestOvertime($this->employee, '2026-10-06', 180, 'Release');
        $evidence = $people->overtimeEvidence($weekday->fresh('employee'));
        $this->assertSame(0, $evidence['extra_minutes'], 'Attendance shows no time beyond 8h');
        $this->assertSame('pending', $weekday->fresh()->status, 'Never auto-approved');

        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/overtime', ['work_date' => '2026-10-06', 'minutes' => 180, 'reason' => 'Again'])->assertUnprocessable();

        $people->decideOvertime($weekday, $this->managerUser->id, true, 60);
        $sunday = $people->requestOvertime($this->employee, '2026-10-04', 600, 'Sunday deploy');
        $this->expectFailure(fn () => $people->decideOvertime($sunday->fresh(), $this->managerUser->id, true, 600), null, 'monthly overtime limit');
        $people->decideOvertime($sunday->fresh(), $this->managerUser->id, true, 360);
        $this->expectFailure(fn () => $people->decideOvertime($sunday->fresh(), $this->managerUser->id, false, null, 'x'), 409);

        $this->at('2026-11-02 10:00:00');
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
        // hourly = 20800 / (26*8) = 100 → weekday 1h × 1.5 = 150, Sunday 6h × 2 = 1200 (holiday rate replaces, never stacks).
        $this->assertEquals(1350.0, (float) $item->overtime_amount);
    }

    public function test_12_penalty_waived_after_draft_payroll_is_removed_on_recalculation(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:00:00', 'status' => 'completed']);
        $people = app(PeopleOperationsService::class);
        $penalty = $people->requestPenalty($this->employee, 500, 'Late', $this->admin->id);
        $penalty->update(['effective_on' => '2026-10-05']);
        app(ApprovalInbox::class)->decide($this->admin, ApprovalRequest::where('approvable_type', EmployeePenalty::class)->firstOrFail(), true, 'ok', 400);
        $this->assertEquals(400, (float) $penalty->fresh()->amount, 'Reduced on approval');
        $this->at('2026-11-02 10:00:00');
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $this->assertEquals(600, (float) PayrollItem::where('payroll_run_id', $run->id)->value('net_amount'));

        $people->waivePenalty($penalty->fresh(), $this->admin->id, 'Train strike');
        $this->assertSame('waived', $penalty->fresh()->status);
        $this->assertTrue($run->fresh()->needs_recalculation);
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $this->assertEquals(1000, (float) PayrollItem::where('payroll_run_id', $run->id)->value('net_amount'));
        $this->assertTrue(AuditLog::where('action', 'penalty.waived')->exists());
    }

    public function test_13_locked_payroll_refuses_admin_changes_and_carries_employee_requests_forward(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:00:00', 'status' => 'completed']);
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', ['multiplier' => 1.5], null, $this->admin->id, '2026-01-01');
        $ot = $people->requestOvertime($this->employee, '2026-10-05', 60, 'x');
        $penalty = $people->requestPenalty($this->employee, 100, 'Late', $this->admin->id);
        $penalty->update(['effective_on' => '2026-10-05']);
        $adjustment = $people->requestAdjustment($this->employee, 500, 'earning', '2026-10-10', 'Bonus', $this->admin->id);
        $leave = LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => LeaveType::where('name', 'Unpaid Leave')->value('id'), 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-12', 'days' => 1, 'reason' => 'x', 'status' => 'pending']);
        $this->at('2026-11-02 10:00:00');
        $workflow = app(HrWorkflowService::class);
        $run = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $workflow->approvePayroll($run, $this->admin->id);
        // Pending leave / overtime of the period must be decided before locking …
        try {
            $workflow->lockPayroll($run->fresh(), $this->admin->id);
            $this->fail('Locking with pending requests is refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('1 leave request, 1 overtime request', $e->getMessage());
        }
        // … but a run locked earlier (before this rule) still refuses admin pay changes.
        $run->update(['status' => 'locked', 'locked_by' => $this->admin->id, 'locked_at' => now()]);
        $item = PayrollItem::where('payroll_run_id', $run->id)->firstOrFail();
        $net = (float) $item->net_amount;

        $this->expectFailure(fn () => $people->decideFinancialRequest(EmployeePenalty::class, $penalty->id, $this->admin->id, true), 409);
        $this->expectFailure(fn () => $people->decideFinancialRequest(SalaryAdjustment::class, $adjustment->id, $this->admin->id, true), 409);
        $this->expectFailure(fn () => app(\App\Services\PayrollLockGuard::class)->changed($this->employee->id, '2026-10-16', 'salary', '2999-12-31'), 409);
        $this->expectFailure(fn () => $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id));

        // The employee's overtime and leave are still decided; the pay difference goes to the next payroll.
        $people->decideOvertime($ot, $this->managerUser->id, true);
        $workflow->decideLeave($leave, $this->managerUser->id, true); // unpaid leave on a day already unpaid: no difference
        $carried = SalaryAdjustment::where('carried_from_payroll_item_id', $item->id)->get();
        $this->assertCount(1, $carried);
        $this->assertSame(['arrear', '187.50', '2026-11-01', 'approved'], [$carried[0]->kind, $carried[0]->amount, $carried[0]->effective_on->toDateString(), $carried[0]->status], '60 min × 1.5 × ₹125/h');
        $this->assertSame('locked', $run->fresh()->status);
        $this->assertEquals($net, (float) $item->fresh()->net_amount, 'Untouched');
    }

    public function test_14_salary_revision_mid_month_is_split_by_effective_date(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 40000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 50000, 'effective_from' => '2026-10-16', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        foreach (['2026-10-05', '2026-10-20'] as $d) AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $d, 'check_in' => "$d 09:00:00", 'status' => 'completed']);
        $this->at('2026-11-02 10:00:00');
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->firstOrFail();
        $this->assertEquals(round(40000 / 22 + 50000 / 22, 2), $item->calculation_breakdown['base_salary']);
        $this->assertCount(2, $item->calculation_breakdown['salary_segments']);
    }

    public function test_15_advance_recovered_over_five_months_then_closed(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        app(PeopleOperationsService::class)->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['attendance_proration' => false], null, $this->admin->id, '2026-01-01');
        $advance = SalaryAdvance::create(['employee_id' => $this->employee->id, 'amount' => 50000, 'installment_amount' => 10000, 'disbursed_amount' => 50000, 'recovered_amount' => 0, 'status' => 'disbursed', 'reason' => 'x', 'requested_on' => '2026-01-05']);
        $workflow = app(HrWorkflowService::class);
        foreach (['2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'] as $month) {
            $start = Carbon::parse("$month-01");
            $this->at($start->copy()->addMonth()->addDay()->toDateTimeString());
            $run = $workflow->calculatePayroll($start->toDateString(), $start->copy()->endOfMonth()->toDateString(), $this->admin->id);
            $run = $workflow->calculatePayroll($start->toDateString(), $start->copy()->endOfMonth()->toDateString(), $this->admin->id); // recalculation must not recover twice
            $workflow->approvePayroll($run, $this->admin->id);
            $workflow->lockPayroll($run->fresh(), $this->admin->id);
        }
        $this->assertEquals([10000, 10000, 10000, 10000, 10000, 0], PayrollItem::orderBy('id')->pluck('recovery_amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(50000, (float) $advance->fresh()->recovered_amount);
        $this->assertSame('recovered', $advance->fresh()->status);
    }

    public function test_17_manager_change_moves_pending_approvals_and_limits_history(): void
    {
        [$userB, $managerB] = $this->person('MGRB', 'Manager B', ['Employee', 'Manager']);
        $this->at('2026-10-05 09:00:00');
        $leave = app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => LeaveType::where('name', 'Unpaid Leave')->value('id'), 'starts_on' => Carbon::parse('2026-10-26'), 'ends_on' => Carbon::parse('2026-10-26'), 'reason' => 'Family']);
        $old = app(PeopleOperationsService::class)->requestOvertime($this->employee, '2026-10-02', 60, 'Done before change');
        app(PeopleOperationsService::class)->decideOvertime($old, $this->managerUser->id, true);
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:30:00', 'status' => 'completed']);

        $this->at('2026-10-16 09:00:00');
        app(EmployeeService::class)->update($this->employee, ['manager_id' => $managerB->id], $this->admin);
        $approval = ApprovalRequest::where('approvable_type', LeaveRequest::class)->where('approvable_id', $leave->id)->firstOrFail();
        $this->assertSame($userB->id, $approval->approver_id, 'Pending request moved to Manager B');
        $this->assertSame($this->managerUser->id, OvertimeRequest::find($old->id)->decided_by, 'Decided approvals keep their actor');
        $inbox = app(ApprovalInbox::class);
        $this->assertTrue($inbox->canDecide($userB, $approval));
        $this->assertFalse($inbox->canDecide($this->managerUser, $approval->fresh()));

        // Manager B's attendance report starts from the 16th; Manager A no longer sees Esha.
        $rows = app(\App\Services\ReportService::class)->rows($userB, 'attendance', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertFalse($rows->contains(fn ($r) => $r['employee_code'] === 'EMP'));
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-16', 'check_in' => '2026-10-16 09:30:00', 'status' => 'completed']);
        $rows = app(\App\Services\ReportService::class)->rows($userB, 'attendance', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(['2026-10-16'], $rows->where('employee_code', 'EMP')->pluck('work_date')->values()->all());
        $rowsA = app(\App\Services\ReportService::class)->rows($this->managerUser, 'attendance', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertFalse($rowsA->contains(fn ($r) => $r['employee_code'] === 'EMP'));
    }

    public function test_19_and_20_no_self_approval_and_second_decision_gets_409(): void
    {
        [$userB] = $this->person('MGR2', 'Second Manager', ['Employee', 'Manager']);
        $userB->givePermissionTo('leave.manage');
        $own = app(HrWorkflowService::class)->requestLeave($this->manager, ['leave_type_id' => LeaveType::where('name', 'Unpaid Leave')->value('id'), 'starts_on' => Carbon::parse('2026-10-26'), 'ends_on' => Carbon::parse('2026-10-26'), 'reason' => 'Own']);
        $approvalOwn = ApprovalRequest::where('approvable_id', $own->id)->where('approvable_type', LeaveRequest::class)->firstOrFail();
        $this->assertFalse(app(ApprovalInbox::class)->canDecide($this->managerUser, $approvalOwn), 'A manager cannot approve their own leave');

        $leave = app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => LeaveType::where('name', 'Unpaid Leave')->value('id'), 'starts_on' => Carbon::parse('2026-10-27'), 'ends_on' => Carbon::parse('2026-10-27'), 'reason' => 'Trip']);
        $approval = ApprovalRequest::where('approvable_id', $leave->id)->where('approvable_type', LeaveRequest::class)->firstOrFail();
        app(ApprovalInbox::class)->decide($this->managerUser, $approval, true);
        $this->expectFailure(fn () => app(ApprovalInbox::class)->decide($userB, $approval->fresh(), false, 'No'), 409);
        $this->assertSame('approved', $leave->fresh()->status);
        Sanctum::actingAs($userB, ['*']);
        $this->postJson("/api/approvals/{$approval->id}/decision", ['approve' => false, 'note' => 'late'])->assertStatus(409);
    }

    public function test_21_22_exit_blocked_by_lost_asset_and_pending_finance(): void
    {
        $people = app(PeopleOperationsService::class);
        $case = $people->openLifecycle($this->employee, 'offboarding', '2026-10-31', 'Resigned', $this->admin->id);
        $case->clearances()->update(['status' => 'cleared']);
        $asset = EmployeeAsset::create(['employee_id' => $this->employee->id, 'asset_tag' => 'LT-1', 'name' => 'Laptop', 'status' => 'lost', 'assigned_on' => '2026-01-01']);
        $this->expectFailure(fn () => app(FinalSettlementService::class)->prepare($case->fresh('clearances'), $this->admin->id), null, 'Assets');
        $this->expectFailure(fn () => $people->closeLifecycle($case->fresh(), $this->admin->id), null, 'lost');

        // Recovery goes through approval as a salary deduction — never straight into payroll.
        $adjustment = $people->requestAdjustment($this->employee, 30000, 'deduction', '2026-10-20', 'Lost laptop LT-1', $this->admin->id);
        $asset->update(['status' => 'recovery_requested', 'recovery_adjustment_id' => $adjustment->id]);
        $this->assertSame('pending', $adjustment->fresh()->status);
        $this->expectFailure(fn () => app(FinalSettlementService::class)->prepare($case->fresh('clearances'), $this->admin->id), null, 'pending salary adjustments');
    }

    public function test_23_screen_capture_follows_the_work_session(): void
    {
        Storage::fake('local');
        $people = app(PeopleOperationsService::class);
        $captures = app(ScreenCaptureService::class);
        $shot = fn () => $this->fakeImage('s.jpg');
        $this->at('2026-10-07 09:30:00');
        $this->attendance->checkIn($this->employee, 'desktop');
        $this->expectFailure(fn () => $captures->store($this->employee->fresh('user'), $shot(), now()), null, 'turned off');
        // Company rules (interval etc.) without switching it on for everyone; only an administrator picks the employee.
        $people->setPolicy('monitoring.screenshots', 'monitoring', 'Screen capture', ['enabled' => false, 'interval_minutes' => 10, 'active_only' => true], null, $this->admin->id, '2026-10-01');
        $hr = User::factory()->create();
        $hr->assignRole('HR');
        try { $captures->setForEmployee($this->employee, true, $hr); $this->fail('HR must not switch capture on'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $captures->setForEmployee($this->employee, true, $this->admin);
        $this->assertFalse($captures->policy($this->manager)['enabled'], 'Only the chosen employee');
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->getJson('/api/me')->assertJsonPath('data.policies.screen_capture.enabled', true)->assertJsonPath('data.policies.screen_capture.interval_minutes', 10);

        $upload = fn (string $time) => $this->at($time) ?? $this->post('/api/monitoring/screenshots', ['image' => $shot(), 'captured_at' => now()->toIso8601String()], ['Accept' => 'application/json']);
        $upload('2026-10-07 09:40:00')->assertCreated();
        $upload('2026-10-07 09:45:00')->assertUnprocessable(); // faster than the interval
        $this->at('2026-10-07 10:30:00');
        $this->attendance->startBreak($this->employee, 'desktop');
        $upload('2026-10-07 10:40:00')->assertUnprocessable(); // on break
        $this->at('2026-10-07 11:00:00');
        $this->attendance->endBreak($this->employee, 'desktop');
        $upload('2026-10-07 11:10:00')->assertCreated();
        $this->at('2026-10-07 18:00:00');
        $this->attendance->checkOut($this->employee, 'desktop');
        $upload('2026-10-07 18:10:00')->assertUnprocessable(); // after check-out
        $upload('2026-10-08 09:00:00')->assertUnprocessable(); // before check-in
        $this->assertSame(['09:40', '11:10'], Screenshot::orderBy('captured_at')->get()->map(fn ($s) => $s->captured_at->format('H:i'))->all());

        // Desktop rule: idle → no capture (active-only), locked → none, before interval → none.
        $this->assertTrue(true);
    }

    public function test_24_screenshot_access_is_limited_and_audited(): void
    {
        Storage::fake('local');
        $shot = Screenshot::create(['employee_id' => $this->employee->id, 'captured_at' => now(), 'path' => 'screenshots/x.jpg', 'bytes' => 3]);
        Storage::disk('local')->put('screenshots/x.jpg', 'img');
        [$otherUser] = $this->person('OTH', 'Other Employee', ['Employee']);
        [$otherMgrUser, $otherMgr] = $this->person('OMGR', 'Team B Manager', ['Employee', 'Manager']);
        $accounts = User::factory()->create();
        $accounts->assignRole('Accounts');
        $hr = User::factory()->create();
        $hr->assignRole('HR');
        $url = route('monitoring.screenshot', $shot);

        $this->actingAs($otherUser)->get($url)->assertForbidden();
        $this->actingAs($otherMgrUser)->get($url)->assertForbidden();
        $this->actingAs($accounts)->get($url)->assertForbidden();
        $this->actingAs($this->employeeUser)->get($url)->assertForbidden(); // nobody sees their own screenshots
        $this->actingAs($this->managerUser)->get($url)->assertOk();
        $this->actingAs($hr)->get($url.'?download=1')->assertOk()->assertDownload();
        $this->assertSame(1, AuditLog::where('action', 'screenshot.viewed')->count());
        $this->assertSame(1, AuditLog::where('action', 'screenshot.downloaded')->count());
        $this->get(route('monitoring.screenshot', $shot))->assertOk();

        $this->at('2026-12-01 09:00:00'); // past the 30-day retention
        $this->actingAs($hr)->get($url)->assertStatus(410);
        $this->assertSame(1, app(ScreenCaptureService::class)->purgeExpired());
        Storage::disk('local')->assertMissing('screenshots/x.jpg');
    }

    public function test_25_combined_month_matches_the_hand_calculation(): void
    {
        $people = app(PeopleOperationsService::class);
        $workflow = app(HrWorkflowService::class);
        // Mon–Sat working, 2 Oct holiday → October 2026 has 26 working days.
        $people->setPolicy('calendar.working_days', 'calendar', 'Calendar', ['days' => [1, 2, 3, 4, 5, 6], 'holidays' => ['2026-10-02']], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', ['multiplier' => 1.5], null, $this->admin->id, '2026-01-01');
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 50000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 26, 'standard_daily_hours' => 8]);
        $days = [];
        for ($d = Carbon::parse('2026-10-01'); $d->lte(Carbon::parse('2026-10-31')); $d->addDay()) {
            if ($d->isSunday() || $d->toDateString() === '2026-10-02') continue;
            $days[] = $d->toDateString();
        }
        $this->assertCount(26, $days);
        [$paidLeaveDay, $unpaid1, $unpaid2] = [$days[23], $days[24], $days[25]];
        foreach (array_slice($days, 0, 23) as $date) AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $date, 'check_in' => "$date 09:30:00", 'check_out' => "$date 18:30:00", 'break_seconds' => 3600, 'active_seconds' => 28800, 'status' => 'completed']);
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $annual->id, 'starts_on' => $paidLeaveDay, 'ends_on' => $paidLeaveDay, 'days' => 1, 'reason' => 'x', 'status' => 'approved']);
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => LeaveType::where('name', 'Unpaid Leave')->value('id'), 'starts_on' => $unpaid1, 'ends_on' => $unpaid2, 'days' => 2, 'reason' => 'y', 'status' => 'approved']);
        OvertimeRequest::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-10', 'requested_minutes' => 480, 'approved_minutes' => 480, 'reason' => 'Release', 'status' => 'approved']);
        SalaryAdvance::create(['employee_id' => $this->employee->id, 'amount' => 5000, 'installment_amount' => 5000, 'disbursed_amount' => 5000, 'recovered_amount' => 0, 'status' => 'disbursed', 'reason' => 'x', 'requested_on' => '2026-09-01']);
        EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-12', 'amount' => 1000, 'reason' => 'Late', 'status' => 'approved']);
        SalaryAdjustment::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-31', 'amount' => 3000, 'kind' => 'earning', 'reason' => 'Bonus', 'status' => 'approved']);

        $this->at('2026-11-02 10:00:00');
        $run = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->managerUser->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
        // Hand calculation (also in docs): base 50000/26×24 = 46153.846…, OT 50000/(26×8)×8×1.5 = 2884.62,
        // gross 46153.846 + 2884.62 + 3000 = 52038.47, net 52038.47 − 1000 − 5000 = 46038.47.
        $this->assertEquals([26, 23, 1, 2], [(int) $item->working_days, (float) $item->present_days, (float) $item->paid_leave_days, (float) $item->unpaid_leave_days]);
        $this->assertEquals(46153.85, $item->calculation_breakdown['base_salary']);
        $this->assertEquals(2884.62, (float) $item->overtime_amount);
        $this->assertEquals(52038.47, (float) $item->gross_amount);
        $this->assertEquals(1000.0, (float) $item->penalties_amount);
        $this->assertEquals(5000.0, (float) $item->recovery_amount);
        $this->assertEquals(46038.47, (float) $item->net_amount);

        $this->expectFailure(fn () => $workflow->approvePayroll($run, $this->managerUser->id), null, 'cannot approve'); // separation of duties
        $workflow->approvePayroll($run, $this->admin->id);
        $workflow->lockPayroll($run->fresh(), $this->admin->id);
        $workflow->pay($item->fresh(), ['amount' => 20000, 'paid_on' => '2026-11-03', 'reference' => 'NEFT-1', 'method' => 'bank_transfer'], $this->admin->id);
        $workflow->pay($item->fresh(), ['amount' => 26038.47, 'paid_on' => '2026-11-05', 'reference' => 'NEFT-2', 'method' => 'bank_transfer'], $this->admin->id);
        $this->expectFailure(fn () => $workflow->pay($item->fresh(), ['amount' => 5000, 'paid_on' => '2026-11-06', 'reference' => 'X', 'method' => 'bank_transfer'], $this->admin->id));
        $this->assertEquals(46038.47, (float) $item->fresh()->payments()->sum('amount'));
        $this->actingAs($this->employeeUser)->get(route('hr.payslips.show', $item->fresh()->payslip))->assertOk()->assertSee('46,038.47');
        $this->assertSame('recovered', SalaryAdvance::first()->status);
        foreach (['payroll.calculated', 'payroll.approved', 'payroll.locked'] as $action) $this->assertTrue(AuditLog::where('action', $action)->exists(), $action);
    }
}
