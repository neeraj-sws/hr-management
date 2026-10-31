<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeePenalty;
use App\Models\LeaveType;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\AttendanceCorrectionService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deductions are found first, cut only after the rule and its approval, and never twice for one thing:
 * no late / early fine on a day already paid by the hours, an absence fine only when chosen, fines follow later
 * corrections and leave decisions, late leave can be fined, past-date leave needs a reason, HR can change the
 * time with a reason, and "Why the salary is less than full" lists every cut down to the net salary.
 */
class FairDeductionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;
    private AttendanceEmployee $employee;
    private PeopleOperationsService $people;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->user = User::factory()->create(['email' => 'fd@company.test']);
        $this->user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'FD-1', 'name' => 'Fair Deal', 'is_active' => true, 'user_id' => $this->user->id, 'joined_at' => '2026-01-01',
            'work_start' => '10:00:00', 'work_end' => '18:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $this->people = app(PeopleOperationsService::class);
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['amount_per_minute' => 2, 'grace_minutes' => 0, 'max_amount' => 0], null, $this->admin->id, '2026-01-01');
        $this->people->setPolicy('attendance.early_checkout', 'compliance', 'Early', ['amount_per_minute' => 1, 'grace_minutes' => 0, 'max_amount' => 0], null, $this->admin->id, '2026-01-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(string $date, string $in, string $out): AttendanceSession
    {
        return AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $date, 'check_in' => "{$date} {$in}:00", 'check_out' => "{$date} {$out}:00", 'status' => 'completed']);
    }

    private function fines(string $date): array
    {
        return EmployeePenalty::where('employee_id', $this->employee->id)->whereDate('effective_on', $date)->whereIn('status', ['pending', 'approved'])->pluck('reason')->all();
    }

    public function test_no_late_or_early_fine_on_a_day_already_paid_by_the_hours(): void
    {
        $this->day('2026-10-12', '10:30', '18:00'); // late 30 min, full day: fined
        $this->day('2026-10-13', '12:30', '18:00'); // after 12:00: paid by the hours, no late fine
        $this->day('2026-10-14', '10:00', '13:00'); // half day: paid by the hours, no early fine
        $this->day('2026-10-15', '10:00', '17:30'); // 30 min early, full day: fined
        $this->people->assessCompliance('2026-10-12', '2026-10-15', $this->admin->id);

        $this->assertSame(['Late by 30 minutes'], $this->fines('2026-10-12'));
        $this->assertSame([], $this->fines('2026-10-13'), 'Check-in after 12:00 is already paid only for the hours');
        $this->assertSame([], $this->fines('2026-10-14'), 'A half day is already paid only for the hours');
        $this->assertSame(['Checked out 30 minutes early'], $this->fines('2026-10-15'));
    }

    public function test_absence_fine_only_when_chosen_and_it_follows_the_leave_decision(): void
    {
        $this->people->setPolicy('attendance.unauthorized_absence', 'compliance', 'Absence', ['amount_per_day' => 500, 'auto_deduct' => 1], null, $this->admin->id, '2026-01-01');
        $this->people->assessCompliance('2026-10-12', '2026-10-12', $this->admin->id);
        $this->assertSame([], $this->fines('2026-10-12'), 'Default: the unpaid day is enough (one cut)');

        $this->people->setPolicy('attendance.unauthorized_absence', 'compliance', 'Absence', ['amount_per_day' => 500, 'auto_deduct' => 1, 'also_fine' => 1], null, $this->admin->id, '2026-01-02');
        // A pending leave for 13 Oct: no fine yet. Rejected: the absence fine comes at once.
        $workflow = app(HrWorkflowService::class);
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $leave = $workflow->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-13'), 'ends_on' => Carbon::parse('2026-10-13'), 'reason' => 'Fever'], false);
        $this->people->assessCompliance('2026-10-13', '2026-10-13', $this->admin->id);
        $this->assertSame([], $this->fines('2026-10-13'));
        $workflow->decideLeave($leave, $this->admin->id, false, 'No proof');
        $this->assertCount(1, $this->fines('2026-10-13'), 'Rejected leave: absent with no approved leave');

        // 14 Oct: fined as absent, then HR adds the attendance (with a reason): the fine is waived.
        $this->people->assessCompliance('2026-10-14', '2026-10-14', $this->admin->id);
        $this->assertCount(1, $this->fines('2026-10-14'));
        app(AttendanceCorrectionService::class)->adminChange($this->employee, '2026-10-14', '10:00', '18:00', null, 'Biometric was down, manager confirmed', $this->admin);
        $this->assertSame([], $this->fines('2026-10-14'), 'The day is worked now: no absence fine');
    }

    public function test_late_leave_fine_and_past_date_leave_needs_a_reason(): void
    {
        $this->people->setPolicy('leave.late_application', 'compliance', 'Late leave', ['amount' => 200, 'notice_days' => 2], null, $this->admin->id, '2026-01-01');
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        \Laravel\Sanctum\Sanctum::actingAs($this->user);
        // Tomorrow: only 1 day of notice (2 needed) → pending fine.
        $this->postJson('/api/leave/requests', ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-21', 'reason' => 'Family'])->assertCreated();
        $this->assertSame('pending', EmployeePenalty::where('source_key', 'like', 'late_leave:%')->value('status'));
        // A past day: the employee cannot apply for it.
        $this->postJson('/api/leave/requests', ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => '2026-10-16', 'ends_on' => '2026-10-16', 'reason' => 'Was ill'])->assertStatus(422);
        // A past-date leave that still reaches the approver (e.g. an old request): approving it needs a reason.
        $leave = app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => \Illuminate\Support\Carbon::parse('2026-10-16'), 'ends_on' => \Illuminate\Support\Carbon::parse('2026-10-16'), 'reason' => 'Was ill']);
        $this->assertTrue($leave->isBackdated());
        try {
            app(HrWorkflowService::class)->decideLeave($leave, $this->admin->id, true);
            $this->fail('Approving a past-date leave needs a reason');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('decisionNote', $e->errors());
        }
        $this->assertSame('approved', app(HrWorkflowService::class)->decideLeave($leave, $this->admin->id, true, 'Doctor note seen')->status);
    }

    public function test_hr_changes_time_with_a_reason_from_attendance_records(): void
    {
        $session = $this->day('2026-10-15', '10:00', '13:00');
        $page = Livewire::actingAs($this->admin)->test(\App\Livewire\Attendance\Records::class, ['from' => '2026-10-01', 'to' => '2026-10-20', 'employee' => $this->employee->id])
            ->call('openEdit', $this->employee->id, '2026-10-15')->assertSet('editIn', '10:00')->assertSet('editOut', '13:00')
            ->set('editOut', '18:00')->call('saveEdit')->assertHasErrors('editReason');
        $page->set('editReason', 'Forgot to check out, left at 6 PM')->call('saveEdit')->assertHasNoErrors();
        $this->assertSame('18:00', $session->fresh()->check_out->format('H:i'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.changed_by_admin']);
        $this->assertTrue($this->user->notifications()->where('data->kind', 'AttendanceCorrection')->exists(), 'The employee is told');
        // An employee cannot.
        Livewire::actingAs($this->user)->test(\App\Livewire\Attendance\Records::class)->call('openEdit', $this->employee->id, '2026-10-15')->assertForbidden();
    }

    public function test_why_less_lists_every_cut_down_to_the_net_salary(): void
    {
        $this->day('2026-10-12', '10:30', '18:00');
        $this->people->assessCompliance('2026-10-12', '2026-10-12', $this->admin->id);
        $fine = EmployeePenalty::where('employee_id', $this->employee->id)->firstOrFail();
        $this->day('2026-10-15', '10:00', '17:30');
        $this->people->assessCompliance('2026-10-15', '2026-10-15', $this->admin->id); // stays pending
        app(PeopleOperationsService::class)->decideFinancialRequest(EmployeePenalty::class, $fine->id, $this->admin->id, true, 'ok');

        $s = app(\App\Services\SalarySummaryService::class)->soFar($this->employee)['shortfall'];
        $this->assertSame('Fine: Late by 30 minutes', $s['after'][0]['label']);
        $this->assertEquals(60, $s['after'][0]['amount']);
        $this->assertSame('12 Oct', $s['after'][0]['detail']);
        $this->assertEqualsWithDelta($s['earned'] - 60, $s['net'], 0.01, 'Net = salary for days worked − the fine');
        $this->assertSame('Fine: Checked out 30 minutes early', $s['pending_fines'][0]['label'], 'Pending: shown, not cut');
    }
}
