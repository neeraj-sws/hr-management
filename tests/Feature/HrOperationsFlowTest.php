<?php

namespace Tests\Feature;

use App\Livewire\Attendance\MyAttendance;
use App\Livewire\Leave\Requests;
use App\Livewire\Payroll\Payslips;
use App\Livewire\Payroll\Runs;
use App\Livewire\People\Departments;
use App\Livewire\People\Employees;
use App\Livewire\Work\DailyStatus;
use App\Livewire\Work\Projects;
use App\Livewire\Work\Tasks;
use App\Models\AttendanceActivityEvent;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryPayment;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * End-to-end: admin sets up people → manager assigns and approves → employee
 * works a day → accounts runs payroll → HR approves → payment → payslip.
 */
class HrOperationsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_operations_cover_employee_attendance_leave_task_payroll_and_audit_flows(): void
    {
        $this->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin);
        Carbon::setTestNow('2026-10-05 09:00:00');

        Livewire::test(Departments::class)->set('name', 'Engineering')->set('code', 'eng')->call('save')->assertHasNoErrors();
        $department = \App\Models\Department::where('code', 'ENG')->firstOrFail();

        Livewire::test(Employees::class)->call('create')
            ->set('employeeCode', 'EMP-MGR')->set('name', 'Mira Manager')->set('email', 'mira@example.com')
            ->set('password', 'ManagerPass123!')->set('monthlySalary', '45000')->set('departmentId', $department->id)->set('isManager', true)
            ->call('save')->assertHasNoErrors();
        $manager = AttendanceEmployee::where('employee_code', 'EMP-MGR')->firstOrFail();
        $managerUser = User::where('email', 'mira@example.com')->firstOrFail();
        $this->assertTrue($managerUser->hasRole('Employee'));
        $this->assertTrue($managerUser->hasRole('Manager'));

        Livewire::test(Employees::class)->call('create')
            ->set('employeeCode', 'EMP-100')->set('name', 'Asha Employee')->set('email', 'asha@example.com')
            ->set('password', 'EmployeePass123!')->set('monthlySalary', '30000')->set('departmentId', $department->id)->set('managerId', $manager->id)
            ->call('save')->assertHasNoErrors();
        Livewire::test(Employees::class)->call('create')->set('employeeCode', 'EMP-100')->set('email', 'asha@example.com')->call('save')
            ->assertHasErrors(['employeeCode', 'email', 'name', 'password', 'monthlySalary']);

        $employee = AttendanceEmployee::where('employee_code', 'EMP-100')->firstOrFail();
        $employeeUser = User::where('email', 'asha@example.com')->firstOrFail();
        $this->assertSame($employeeUser->id, $employee->user_id);
        $this->assertSame($department->id, $employee->department_id);
        $this->assertSame($manager->id, $employee->manager_id);
        $this->assertDatabaseHas('salary_profiles', ['employee_id' => $employee->id, 'monthly_salary' => '30000.00']);
        $this->assertDatabaseHas('leave_balances', ['employee_id' => $employee->id, 'year' => 2026, 'entitled_days' => '20.00']);

        // A manager may only assign work inside their own team.
        $otherEmployee = AttendanceEmployee::create(['employee_code' => 'EMP-OTHER', 'name' => 'Other Team Member', 'is_active' => true]);
        $this->actingAs($managerUser);
        Livewire::test(Tasks::class)->call('create')->set('title', 'Unauthorized cross-team task')->set('assigneeId', $otherEmployee->id)->call('save')->assertHasErrors('assignee_id');
        $this->assertDatabaseMissing('tasks', ['title' => 'Unauthorized cross-team task']);

        $this->actingAs($admin);
        Livewire::test(Projects::class)->call('create')->set('name', '')->call('save')->assertHasErrors(['name']);
        Livewire::test(Projects::class)->call('create')->set('name', 'Website refresh')->call('save')->assertHasNoErrors();
        Livewire::test(Tasks::class)->call('create')->set('title', 'Prepare homepage')->set('assigneeId', $employee->id)->set('estimatedHours', '4')->set('dueOn', '2026-10-06')->call('save')->assertHasNoErrors();
        $task = Task::where('title', 'Prepare homepage')->firstOrFail();
        $this->assertDatabaseHas('notifications', ['notifiable_type' => User::class, 'notifiable_id' => $employeeUser->id]);
        Task::create(['title' => 'External unrelated task', 'assignee_id' => $otherEmployee->id, 'created_by' => $admin->id, 'status' => 'todo', 'priority' => 'normal']);

        $this->actingAs($managerUser);
        $this->get('/work/tasks')->assertOk()->assertSee('Prepare homepage')->assertDontSee('External unrelated task');

        // Employee day: check-in → timer → break (timer pauses) → resume → status → complete → check-out.
        $this->actingAs($employeeUser);
        $this->get('/work/my-tasks')->assertOk()->assertSee('Prepare homepage')->assertDontSee('External unrelated task');
        $this->get('/work/tasks')->assertForbidden();
        Livewire::test(MyAttendance::class)->call('checkIn')->assertHasNoErrors();
        Livewire::test(MyAttendance::class)->call('checkIn')->assertHasErrors('attendance');
        $session = AttendanceSession::where('employee_id', $employee->id)->firstOrFail();
        $this->assertDatabaseHas('attendance_activity_events', ['attendance_session_id' => $session->id, 'event_type' => 'check_in']);
        Livewire::test(Tasks::class)->call('start', $task->id)->assertHasNoErrors();
        $firstTimer = TaskTimeEntry::where('task_id', $task->id)->where('status', 'running')->firstOrFail();
        Livewire::test(MyAttendance::class)->call('startBreak')->assertHasNoErrors();
        $this->assertSame('stopped', $firstTimer->fresh()->status);
        Livewire::test(Tasks::class)->call('start', $task->id)->assertHasErrors('timer');
        Carbon::setTestNow('2026-10-05 09:30:00');
        Livewire::test(MyAttendance::class)->call('resume')->assertHasNoErrors();
        Livewire::test(DailyStatus::class)->set('summary', 'Completed onboarding and homepage preparation')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('daily_work_statuses', ['employee_id' => $employee->id, 'summary' => 'Completed onboarding and homepage preparation']);

        Livewire::test(Tasks::class)->call('start', $task->id)->assertHasNoErrors();
        Livewire::test(Tasks::class)->call('start', $task->id)->assertHasErrors('timer');
        Carbon::setTestNow('2026-10-05 10:30:00');
        Livewire::test(Tasks::class)->call('complete', $task->id)->assertHasNoErrors();
        $this->assertSame('completed', $task->fresh()->status);
        Livewire::test(MyAttendance::class)->call('checkOut')->assertHasNoErrors();
        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(1800, (int) $session->break_seconds);
        $this->assertSame(3600, (int) $session->active_seconds);

        // Leave: employee requests, manager approves, employee cannot decide.
        $leaveType = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        Livewire::test(Requests::class)->call('create')->set('leaveTypeId', $leaveType->id)->set('dayChoice', 'several')->set('startsOn', '2026-11-02')->set('endsOn', '2026-11-03')->set('reason', 'Personal leave')->call('submit')->assertHasNoErrors();
        $leave = LeaveRequest::where('employee_id', $employee->id)->firstOrFail();
        Livewire::test(Requests::class)->set('decidingId', $leave->id)->call('decide', false)->assertHasErrors('decisionNote');
        Livewire::test(Requests::class)->set('decidingId', $leave->id)->set('decisionNote', 'self')->call('decide', false)->assertStatus(403);
        $this->actingAs($managerUser);
        Livewire::test(Requests::class)->set('decidingId', $leave->id)->call('decide', true)->assertHasNoErrors();
        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertDatabaseHas('leave_balances', ['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2026, 'used_days' => '2.00']);

        // Payroll: Accounts calculates/locks/pays, HR approves; neither can do the other's step.
        AttendanceSession::whereKey($session->id)->update(['work_date' => '2026-10-01', 'check_in' => '2026-10-01 09:00:00', 'check_out' => '2026-10-01 17:00:00', 'active_seconds' => 28800, 'status' => 'completed']);
        $hrUser = User::factory()->create();
        $hrUser->assignRole('HR');
        $accountsUser = User::factory()->create();
        $accountsUser->assignRole('Accounts');
        $this->actingAs($hrUser);
        Livewire::test(Runs::class)->set('periodStart', '2026-10-01')->set('periodEnd', '2026-10-31')->call('calculate')->assertStatus(403);
        $this->actingAs($accountsUser);
        Livewire::test(Runs::class)->set('periodStart', '2026-10-01')->set('periodEnd', '2026-10-31')->call('calculate')->assertHasNoErrors();
        $run = PayrollRun::firstOrFail();
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('1363.64', $item->net_amount);
        Livewire::test(Runs::class)->set('run', $run->id)->call('approve')->assertStatus(403);
        Livewire::test(Runs::class)->set('run', $run->id)->call('lock')->assertHasErrors('payroll');
        $this->actingAs($hrUser);
        Livewire::test(Runs::class)->set('run', $run->id)->call('approve')->assertHasNoErrors();
        $this->actingAs($accountsUser);
        Carbon::setTestNow('2026-11-02 10:00:00'); // a period can only be locked once it has ended
        Livewire::test(Runs::class)->set('run', $run->id)->call('lock')->assertHasNoErrors();
        Livewire::test(Runs::class)->set('run', $run->id)->call('recalculate')->assertHasErrors('payroll');
        Livewire::test(Runs::class)->set('run', $run->id)->call('openPayment', $item->id)->set('paymentAmount', '500')->set('paymentReference', 'BANK-001')->call('pay')->assertHasNoErrors();
        Livewire::test(Runs::class)->set('run', $run->id)->call('openPayment', $item->id)->set('paymentAmount', '863.64')->set('paymentReference', 'BANK-002')->call('pay')->assertHasNoErrors();
        Livewire::test(Runs::class)->set('run', $run->id)->call('openPayment', $item->id)->set('paymentAmount', '1')->call('pay')->assertHasErrors('amount');
        $this->assertSame(2, SalaryPayment::where('payroll_item_id', $item->id)->count());
        $this->assertSame('BANK-001', SalaryPayment::where('payroll_item_id', $item->id)->orderBy('id')->first()->reference);
        $this->assertSame(1, Payslip::where('payroll_item_id', $item->id)->count());
        $this->assertGreaterThan(0, AttendanceActivityEvent::where('attendance_session_id', $session->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.payment_recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.locked']);

        $this->actingAs($employeeUser);
        Livewire::test(Payslips::class)->assertSee('PS-');
        $payslip = Payslip::where('payroll_item_id', $item->id)->firstOrFail();
        $this->get(route('hr.payslips.show', $payslip))->assertOk()->assertSee('Net pay')->assertSee('Rupees')->assertSee('Total paid');
        $this->actingAs($managerUser)->get(route('hr.payslips.show', $payslip))->assertForbidden();
        Carbon::setTestNow();
    }
}
