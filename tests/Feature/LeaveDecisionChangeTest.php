<?php

namespace Tests\Feature;

use App\Livewire\Leave\Requests;
use App\Models\AttendanceEmployee;
use App\Models\EmployeePenalty;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A leave approved or rejected by mistake: HR / an administrator changes the decision with a reason. The balance,
 * the days (on leave / absent), the absence fines and the salary follow; everyone concerned is told.
 */
class LeaveDecisionChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;
    private LeaveType $annual;
    private HrWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['email' => 'priya@company.test', 'name' => 'Priya Verma']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'LV1', 'name' => 'Priya Verma', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 25000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8]);
        $this->annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        LeaveBalance::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'year' => \App\Support\LeaveYear::of('2026-10-13'), 'entitled_days' => 10, 'used_days' => 0]);
        $this->workflow = app(HrWorkflowService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function leave(): \App\Models\LeaveRequest
    {
        return $this->workflow->requestLeave($this->employee, ['leave_type_id' => $this->annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-13'), 'ends_on' => Carbon::parse('2026-10-14'), 'reason' => 'Family function'], false);
    }

    private function used(): float
    {
        return (float) LeaveBalance::where('employee_id', $this->employee->id)->where('leave_type_id', $this->annual->id)->value('used_days');
    }

    private function dayStatus(string $date): string
    {
        return app(AttendanceService::class)->days($this->employee, Carbon::parse($date), Carbon::parse($date))->first()['status'];
    }

    public function test_approved_by_mistake_changed_to_rejected_and_back(): void
    {
        $leave = $this->leave();
        $this->workflow->decideLeave($leave, $this->admin->id, true, 'Ok, backdated');
        $this->assertSame(2.0, $this->used());
        $this->assertSame('on_leave', $this->dayStatus('2026-10-13'));

        // Reason is required.
        Livewire::actingAs($this->admin)->test(Requests::class, ['tab' => 'all'])
            ->assertSee('Change decision')->call('openChange', $leave->id)->assertSee('Change to Rejected')
            ->call('saveChange')->assertHasErrors('changeReason')
            ->set('changeReason', 'Approved by mistake - deadline week')->call('saveChange')->assertHasNoErrors();

        $leave->refresh();
        $this->assertSame('rejected', $leave->status);
        $this->assertStringContainsString('Decision changed from approved to rejected: Approved by mistake - deadline week', $leave->decision_note);
        $this->assertSame(0.0, $this->used(), 'The 2 days go back to the balance');
        $this->assertSame('absent', $this->dayStatus('2026-10-13'));
        $this->assertStringContainsString('changed the decision on your leave (13 Oct – 14 Oct 2026): it is now rejected. Reason: Approved by mistake - deadline week', $this->employee->user->notifications()->where('data->kind', 'LeaveRequest')->get()->pluck('data.message')->join(' | '));
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.decision_changed']);

        // And back again (rejected by mistake): balance used, the days are leave, the absence fine is removed.
        app(PeopleOperationsService::class)->setPolicy('attendance.unauthorized_absence', 'compliance', 'Absence', ['amount_per_day' => 300, 'auto_deduct' => 1, 'also_fine' => 1], null, $this->admin->id, '2026-01-01');
        app(PeopleOperationsService::class)->assessCompliance('2026-10-13', '2026-10-13', $this->admin->id);
        $fine = EmployeePenalty::where('employee_id', $this->employee->id)->where('source_key', 'like', 'absence:%')->firstOrFail();
        $this->workflow->changeLeaveDecision($leave, $this->admin, true, 'Rejected by mistake, it was agreed earlier');
        $this->assertSame(['approved', 2.0, 'on_leave', 'waived'], [$leave->fresh()->status, $this->used(), $this->dayStatus('2026-10-13'), $fine->fresh()->status]);
    }

    public function test_only_hr_or_admin_and_only_a_decided_leave(): void
    {
        $leave = $this->leave();
        Livewire::actingAs($this->employee->user)->test(Requests::class, ['tab' => 'mine'])->assertDontSee('Change decision')->call('openChange', $leave->id)->assertForbidden();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->workflow->changeLeaveDecision($leave, $this->admin, true, 'Still pending, cannot change');
    }

    public function test_processed_salary_is_corrected_in_the_next_salary(): void
    {
        $leave = $this->leave();
        $this->workflow->decideLeave($leave, $this->admin->id, true, 'Agreed by phone');
        $run = $this->workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $this->workflow->approvePayroll($run, $this->admin->id);

        $this->workflow->changeLeaveDecision($leave, $this->admin, false, 'Approved by mistake after payroll');
        $this->assertTrue(\App\Models\SalaryAdjustment::where('employee_id', $this->employee->id)->where('reason', 'like', '%decision changed%')->exists(), 'The 2 paid days are recovered from the next salary');
        $this->assertStringContainsString('corrected in your next salary', $this->employee->user->notifications()->where('data->kind', 'LeaveRequest')->get()->pluck('data.message')->join(' | '));
    }

    public function test_employee_changes_the_leave_type_of_a_pending_request(): void
    {
        $leave = $this->leave();
        $unpaid = LeaveType::where('paid', false)->where('is_active', true)->where('is_other', false)->first() ?? LeaveType::create(['name' => 'Unpaid Leave', 'annual_allowance' => 0, 'paid' => false, 'is_active' => true]);

        Livewire::actingAs($this->employee->user)->test(Requests::class, ['tab' => 'mine'])
            ->assertSee('Edit')->call('openEdit', $leave->id)->assertSee('Edit leave request')
            ->set('editTypeId', $unpaid->id)->set('editReason', 'Family function, unpaid is fine')->call('saveEdit')->assertHasNoErrors();
        $this->assertSame([$unpaid->id, 'Family function, unpaid is fine', 'pending'], [$leave->fresh()->leave_type_id, $leave->fresh()->reason, $leave->fresh()->status]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.type_changed']);
        $this->assertStringContainsString('changed their pending leave request (13 Oct – 14 Oct 2026) from Annual Leave to '.$unpaid->name, $this->admin->notifications()->where('data->kind', 'LeaveRequest')->get()->pluck('data.message')->join(' | '));

        // App: back to Annual Leave; a paid type needs the balance (10 days here, so fine).
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->patchJson("/api/leave/requests/{$leave->id}", ['leave_type_id' => $this->annual->id])->assertOk()->assertJsonPath('data.leave_type', 'Annual Leave');
        LeaveBalance::where('employee_id', $this->employee->id)->update(['entitled_days' => 1]);
        $this->annual->update(['allow_advance' => false]);
        $this->patchJson("/api/leave/requests/{$leave->id}", ['leave_type_id' => $unpaid->id])->assertOk();
        $this->patchJson("/api/leave/requests/{$leave->id}", ['leave_type_id' => $this->annual->id])->assertStatus(422)->assertJsonValidationErrors('leave_type_id');

        // Someone else's request: refused; a decided request: refused (HR uses Change decision).
        $other = User::factory()->create();
        $other->assignRole('Employee');
        AttendanceEmployee::create(['employee_code' => 'LV2', 'name' => 'Other', 'is_active' => true, 'user_id' => $other->id, 'joined_at' => '2026-01-01']);
        \Laravel\Sanctum\Sanctum::actingAs($other, ['*']);
        $this->patchJson("/api/leave/requests/{$leave->id}", ['leave_type_id' => $this->annual->id])->assertForbidden();
        $this->workflow->decideLeave($leave, $this->admin->id, false, 'Not now');
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->patchJson("/api/leave/requests/{$leave->id}", ['leave_type_id' => $this->annual->id])->assertStatus(422);
    }

    public function test_others_leave_type_with_the_kind_written_by_the_employee(): void
    {
        $others = LeaveType::where('is_other', true)->firstOrFail();
        $this->assertSame(['Others', false], [$others->name, (bool) $others->paid]);

        // Website: "Others" needs the kind; then it shows as "Others: Exam" everywhere.
        Livewire::actingAs($this->employee->user)->test(Requests::class, ['tab' => 'mine'])
            ->call('create')->set('leaveTypeId', $others->id)->assertSee('What kind of leave?')
            ->set('dayChoice', 'one')->set('startsOn', '2026-10-27')->set('reason', 'Semester exam')->call('submit')->assertHasErrors('otherType')
            ->set('otherType', 'Exam / Educational')->call('submit')->assertHasNoErrors();
        $leave = \App\Models\LeaveRequest::latest('id')->firstOrFail();
        $this->assertSame(['Exam / Educational', 'Others: Exam / Educational'], [$leave->other_type, $leave->typeName()]);
        $this->assertStringContainsString('Others: Exam / Educational', app(\App\Services\ApprovalInbox::class)->summary(\App\Models\ApprovalRequest::where('approvable_id', $leave->id)->firstOrFail()));

        // App: the type list says which one is "Others"; a request without the kind is refused.
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->assertTrue(collect($this->getJson('/api/leave/types')->json('data'))->firstWhere('id', $others->id)['is_other']);
        $this->postJson('/api/leave/requests', ['leave_type_id' => $others->id, 'starts_on' => '2026-10-29', 'ends_on' => '2026-10-29', 'reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('other_type');
        $this->postJson('/api/leave/requests', ['leave_type_id' => $others->id, 'other_type' => 'Moving house', 'starts_on' => '2026-10-29', 'ends_on' => '2026-10-29', 'reason' => 'Shifting'])->assertCreated()->assertJsonPath('data.leave_type', 'Others: Moving house');

        // Changing to another type drops the written kind.
        $this->patchJson("/api/leave/requests/{$leave->id}", ['leave_type_id' => $this->annual->id])->assertOk()->assertJsonPath('data.leave_type', 'Annual Leave')->assertJsonPath('data.other_type', null);
    }
}
