<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Devices;
use App\Livewire\Attendance\Shifts;
use App\Livewire\Hr\Dashboard;
use App\Livewire\Leave\Overtime;
use App\Livewire\People\Employees;
use App\Models\AttendanceDevice;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\EmployeeService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use App\Services\WorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** 21 Oct batch: device revoke, on-leave list, ex-employees, daily wage, overtime off, roster off days, task audit, document types. */
class Oct21BatchTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, array $extra = []): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', ...$extra]);
    }

    public function test_revoking_a_device_left_by_a_deleted_employee_removes_it_instead_of_failing(): void
    {
        $e = $this->employee('DEV-1');
        $device = AttendanceDevice::create(['employee_id' => $e->id, 'device_id' => 'pc-orphan', 'computer_name' => 'OLD-PC', 'is_active' => true]);
        // Employee row deleted while foreign keys were off (as on the server): the device points at nobody.
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('attendance_employees')->where('id', $e->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        Livewire::actingAs($this->admin)->test(Devices::class)->call('revoke', $device->id)->assertHasNoErrors();
        $this->assertNull(AttendanceDevice::find($device->id));
    }

    public function test_on_leave_card_opens_a_list_with_designation_type_and_duration(): void
    {
        $e = $this->employee('LV-1', ['designation' => 'Accountant']);
        $type = LeaveType::where('is_active', true)->firstOrFail();
        LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-22', 'days' => 3, 'duration' => 'full', 'reason' => 'Family', 'status' => 'approved']);

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertDontSee('On leave today (')
            ->call('openOnLeave')
            ->assertSee('On leave today (1)')->assertSee('Person LV-1')->assertSee('Accountant')->assertSee($type->name)->assertSeeText('20 Oct – 22 Oct')->assertSeeText('3 days · back Fri 23 Oct');
    }

    public function test_an_ex_employee_is_added_inactive_with_exit_details_and_no_password(): void
    {
        Livewire::actingAs($this->admin)->test(Employees::class)->call('create')
            ->set('employeeCode', 'OLD-1')->set('name', 'Former Person')->set('email', 'former@example.com')
            ->set('joinedAt', '2024-04-01')->set('employmentType', EmployeeService::EX_EMPLOYEE)
            ->set('exitDate', '2026-06-30')->set('exitType', 'resignation')
            ->call('save')->assertHasNoErrors();

        $e = AttendanceEmployee::where('employee_code', 'OLD-1')->firstOrFail();
        $this->assertFalse((bool) $e->is_active);
        $this->assertSame('2026-06-30', $e->exit_date->toDateString());
        $this->assertSame('resignation', $e->exit_type);
        $this->assertFalse((bool) $e->user->is_active, 'An ex-employee cannot sign in');

        Livewire::actingAs($this->admin)->test(Employees::class)->set('status', 'left')->assertSee('Former Person')->assertSee('Left');
    }

    public function test_a_daily_wage_employee_is_paid_per_day_present(): void
    {
        Livewire::actingAs($this->admin)->test(Employees::class)->call('create')
            ->set('employeeCode', 'CLN-1')->set('name', 'Cleaning Staff')->set('email', 'cleaning@example.com')->set('password', 'StrongPassword123')
            ->set('joinedAt', '2026-09-01')->set('employmentType', 'daily_wage')->assertSet('payBasis', 'daily')
            ->set('dailyRate', '130')->call('save')->assertHasNoErrors();

        $e = AttendanceEmployee::where('employee_code', 'CLN-1')->firstOrFail();
        $profile = $e->salaryProfiles()->firstOrFail();
        $this->assertTrue($profile->isDaily());
        $this->assertSame(130.0, (float) $profile->daily_rate);

        // Present on 3 working days (Mon–Wed) in September.
        foreach (['2026-09-07', '2026-09-08', '2026-09-09'] as $day) {
            AttendanceSession::create(['employee_id' => $e->id, 'work_date' => $day, 'check_in' => "{$day} 09:00:00", 'check_out' => "{$day} 17:00:00", 'status' => 'completed']);
        }
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-09-01', '2026-09-30', $this->admin->id);
        $item = $run->items->firstWhere('employee_id', $e->id);
        $this->assertSame(390.0, (float) $item->calculation_breakdown['base_salary']);
        $this->assertSame('daily', $item->calculation_data['pay_basis']);
    }

    public function test_overtime_switched_off_hides_its_page_and_approvals(): void
    {
        $e = $this->employee('OT-1');
        app(PeopleOperationsService::class)->requestOvertime($e, '2026-10-20', 60, 'Release');
        $this->assertContains(OvertimeRequest::class, app(ApprovalInbox::class)->typesFor($this->admin));

        app(PeopleOperationsService::class)->setPolicy('overtime.multiplier', 'payroll', 'Overtime', ['enabled' => false], null, $this->admin->id, '2026-10-01');

        $this->assertNotContains(OvertimeRequest::class, app(ApprovalInbox::class)->typesFor($this->admin));
        $this->assertFalse(app(\App\Services\ReportService::class)->canView($this->admin, 'overtime'));
        Livewire::actingAs($this->admin)->test(Overtime::class)->assertStatus(404);
    }

    public function test_roster_highlights_weekly_offs(): void
    {
        $this->employee('RS-1');
        // Week of Mon 19 Oct 2026: Sunday 25 Oct is a weekly off with the default Mon–Fri calendar.
        Livewire::actingAs($this->admin)->test(Shifts::class)->set('week', '2026-10-19')
            ->assertSee('roster-off-weekly', false)->assertSee('Weekly off');
    }

    public function test_task_assignment_audit_names_the_assignee(): void
    {
        $lead = $this->employee('TK-1');
        $member = $this->employee('TK-2', ['manager_id' => $lead->id]);
        app(WorkService::class)->saveTask(null, ['title' => 'Prepare report', 'assignee_id' => $member->id], $lead->user);

        $log = AuditLog::where('action', 'task.assigned')->latest('id')->firstOrFail();
        $this->assertSame('Assigned to Person TK-2 · "Prepare report"', $log->summary());

        app(WorkService::class)->saveTask(null, ['title' => 'Own work', 'assignee_id' => $lead->id], $lead->user);
        $this->assertStringStartsWith('Self-assigned', AuditLog::where('action', 'task.assigned')->latest('id')->first()->summary());
    }

    public function test_document_types_include_appointment_noc_and_relieving_letters(): void
    {
        $categories = \App\Livewire\Lifecycle\Documents::CATEGORIES;
        $this->assertSame('Appointment letter', $categories['appointment']);
        $this->assertSame('Relieving letter', $categories['relieving']);
        $this->assertArrayHasKey('noc', $categories);
    }
}
