<?php

namespace Tests\Feature;

use App\Livewire\People\Employees;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Employees, departments, teams, managers and team attendance list improvements. */
class AdminListsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, string $joined = '2026-01-01', array $extra = []): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => $joined, ...$extra]);
    }

    public function test_employees_are_in_employee_number_order_filtered_by_joining_and_removable_by_admin(): void
    {
        $this->employee('EMP-9', '2026-03-01');
        $this->employee('EMP-10', '2026-09-01');
        $old = $this->employee('EMP-2', '2025-01-01');
        AttendanceSession::create(['employee_id' => $old->id, 'work_date' => '2026-10-14', 'check_in' => '2026-10-14 09:00:00', 'status' => 'working']);

        Livewire::actingAs($this->admin)->test(Employees::class)
            ->assertSeeInOrder(['EMP-2', 'EMP-9', 'EMP-10'])
            ->assertSee('Copy email')
            ->set('joinedFrom', '2026-02-01')->set('joinedTo', '2026-06-30')->assertSee('EMP-9')->assertDontSee('EMP-10')->assertDontSee('EMP-2');

        $new = AttendanceEmployee::where('employee_code', 'EMP-10')->first();
        Livewire::actingAs($this->admin)->test(Employees::class)->call('remove', $new->id)->assertHasNoErrors();
        $this->assertNull(AttendanceEmployee::find($new->id));
        $this->assertNull(User::where('email', 'emp-10@example.com')->first());
        $this->assertSame('EMP-10', AuditLog::where('action', 'employee.removed')->first()->metadata['employee_code']);

        Livewire::actingAs($this->admin)->test(Employees::class)->call('remove', $old->id)->assertHasErrors('employee');
        $this->assertNotNull($old->fresh(), 'An employee with attendance is kept (deactivate instead)');

        $hr = User::factory()->create(); $hr->assignRole('HR');
        Livewire::actingAs($hr)->test(Employees::class)->assertDontSee('>Remove<', false)->call('remove', $old->id)->assertForbidden();
    }

    public function test_removing_an_employee_removes_their_case_and_an_orphaned_case_does_not_crash_the_page(): void
    {
        $new = $this->employee('EMP-20', '2026-10-01');
        $case = \App\Models\EmployeeLifecycleCase::create(['employee_id' => $new->id, 'kind' => 'onboarding', 'status' => 'open', 'effective_on' => '2026-10-01']);
        \App\Models\EmployeeClearanceItem::create(['lifecycle_case_id' => $case->id, 'item' => 'Laptop', 'status' => 'pending']);

        Livewire::actingAs($this->admin)->test(Employees::class)->call('remove', $new->id)->assertHasNoErrors();
        $this->assertNull($case->fresh(), 'The case goes with the employee');
        $this->assertSame(0, \App\Models\EmployeeClearanceItem::where('lifecycle_case_id', $case->id)->count());

        // A server without foreign keys (production) left a case pointing at a deleted employee.
        $gone = $this->employee('EMP-21', '2026-10-01');
        $orphan = \App\Models\EmployeeLifecycleCase::create(['employee_id' => $gone->id, 'kind' => 'onboarding', 'status' => 'open', 'effective_on' => '2026-10-01']);
        \Illuminate\Support\Facades\Schema::withoutForeignKeyConstraints(fn () => \Illuminate\Support\Facades\DB::table('attendance_employees')->where('id', $gone->id)->delete());

        $this->actingAs($this->admin)->get(route('lifecycle.onboarding', ['case' => $orphan->id]))->assertOk()->assertDontSee('Person EMP-21');
        Livewire::actingAs($this->admin)->withQueryParams(['case' => $orphan->id])->test(\App\Livewire\Lifecycle\Cases::class)->assertOk()->assertDontSee('Person EMP-21');
    }

    public function test_mail_is_not_attempted_to_reserved_example_domains(): void
    {
        $this->mock(\Illuminate\Notifications\Channels\MailChannel::class, fn ($mock) => $mock->shouldNotReceive('send'));
        $this->admin->notify(new \App\Notifications\ApprovalRequested('Leave', 1, 'Test', '/approvals'));
        $this->assertFalse(AuditLog::where('action', 'notification.email_failed')->exists(), 'No failure logged for admin@example.com');

        foreach (['a@example.com', 'a@mail.example.org', 'a@pc.test', 'a@x.invalid'] as $email) $this->assertTrue(\App\Notifications\Channels\SafeMailChannel::undeliverable($email), $email);
        foreach (['a@sohamsolution.com', 'a@examples.com', 'a@gmail.com'] as $email) $this->assertFalse(\App\Notifications\Channels\SafeMailChannel::undeliverable($email), $email);
    }

    public function test_departments_show_counts_and_only_empty_ones_can_be_removed(): void
    {
        $full = \App\Models\Department::create(['name' => 'Engineering', 'code' => 'ENG', 'is_active' => true]);
        $empty = \App\Models\Department::create(['name' => 'Legacy', 'code' => 'LEG', 'is_active' => true]);
        $this->employee('EMP-1', '2026-01-01', ['department_id' => $full->id]);

        Livewire::actingAs($this->admin)->test(\App\Livewire\People\Departments::class)
            ->assertSee('2 departments')->assertSee('1 employees assigned')
            ->call('remove', $full->id)->assertHasErrors('department')->assertSee('still has 1 employee(s)')
            ->call('remove', $empty->id)->assertHasNoErrors();
        $this->assertNull($empty->fresh());
        $this->assertNotNull($full->fresh());
    }

    public function test_manager_count_opens_that_managers_employees(): void
    {
        $boss = $this->employee('EMP-50');
        $this->employee('EMP-51', '2026-01-01', ['manager_id' => $boss->id]);
        $this->employee('EMP-52', '2026-01-01', ['manager_id' => $boss->id]);
        $this->employee('EMP-53');

        $this->actingAs($this->admin)->get(route('people.managers'))->assertOk()->assertSee('Without a manager')
            ->assertSee(route('people.employees', ['manager' => $boss->id]), false);
        Livewire::actingAs($this->admin)->withQueryParams(['manager' => $boss->id])->test(Employees::class)
            ->assertSee('Showing people who report to')->assertSee('EMP-51')->assertSee('EMP-52')->assertDontSee('EMP-53');
    }

    public function test_running_month_cannot_be_locked_and_admin_can_reopen_a_locked_payroll(): void
    {
        $e = $this->employee('EMP-7');
        \App\Models\SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $workflow = app(\App\Services\HrWorkflowService::class);
        $run = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $workflow->approvePayroll($run, $this->admin->id);
        try {
            $workflow->lockPayroll($run, $this->admin->id);
            $this->fail('October cannot be locked on 15 October');
        } catch (\Illuminate\Validation\ValidationException $err) {
            $this->assertStringContainsString('can be locked from 01 Nov 2026', $err->getMessage());
        }

        // A run locked earlier (as on the UAT database) blocks corrections, until an admin reopens it.
        $run->update(['status' => 'locked', 'locked_at' => now()]);
        $session = AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-15', 'check_in' => '2026-10-15 09:00:00', 'status' => 'working']);
        Livewire::actingAs($this->admin)->test(\App\Livewire\Attendance\Dashboard::class)
            ->call('openClose', $session->id)->set('closeTime', '11:00')->set('closeReason', 'forgot')->call('closeSession')
            ->assertHasErrors('payroll')->assertSee('an administrator can reopen it');

        Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\Runs::class)->set('run', $run->id)->assertSee('Reopen')
            ->call('openReopen')->set('reopenReason', 'Locked by mistake mid-month')->call('reopen')->assertHasNoErrors();
        $this->assertSame('draft', $run->fresh()->status);
        Livewire::actingAs($this->admin)->test(\App\Livewire\Attendance\Dashboard::class)
            ->call('openClose', $session->id)->set('closeTime', '11:00')->set('closeReason', 'forgot')->call('closeSession')->assertHasNoErrors();
        $this->assertNotNull($session->fresh()->check_out);
    }

    public function test_correction_goes_to_admin_when_the_manager_is_unavailable_and_form_opens_once(): void
    {
        $boss = $this->employee('EMP-20');
        $e = $this->employee('EMP-21', '2026-01-01', ['manager_id' => $boss->id]);
        $annual = \App\Models\LeaveType::where('name', 'Annual Leave')->firstOrFail();
        \App\Models\LeaveRequest::create(['employee_id' => $boss->id, 'leave_type_id' => $annual->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-16', 'days' => 3, 'reason' => 'Trip', 'status' => 'approved']);

        $correction = app(\App\Services\AttendanceCorrectionService::class)->request($e, '2026-10-14', '09:30', '18:30', 'Forgot to check in');
        $approval = \App\Models\ApprovalRequest::where('approvable_type', \App\Models\AttendanceCorrection::class)->where('approvable_id', $correction->id)->firstOrFail();
        $this->assertNull($approval->approver_id, 'Manager on leave: assigned to the administrators');
        $this->assertTrue($this->admin->notifications()->get()->contains(fn ($n) => ($n->data['kind'] ?? '') === 'AttendanceCorrection'));
        $this->assertFalse($boss->user->notifications()->get()->contains(fn ($n) => ($n->data['kind'] ?? '') === 'AttendanceCorrection'), 'Manager on leave is not asked');
        $this->assertSame(1, app(\App\Services\ApprovalInbox::class)->pending($this->admin, \App\Models\AttendanceCorrection::class)->count());

        // Coming from a "Request correction" link opens the form once; the link is then cleared, so a refresh does not reopen it.
        Livewire::actingAs($e->user)->withQueryParams(['date' => '2026-10-13'])->test(\App\Livewire\Attendance\Corrections::class)
            ->assertSet('showForm', true)->assertSet('prefillDate', '');
    }

    public function test_shift_assignment_uses_the_clicked_day_replaces_overlaps_and_notifies(): void
    {
        $e = $this->employee('EMP-30');
        $day = \App\Models\Shift::create(['name' => 'Day', 'start_time' => '09:30', 'end_time' => '18:30', 'is_active' => true]);
        $night = \App\Models\Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'is_active' => true]);
        $shifts = app(\App\Services\ShiftService::class);
        $shifts->assign([$e->id], $day, '2026-10-01', '2026-10-31', $this->admin->id);

        // Clicking Thursday 15 Oct in the roster starts the change on that day.
        Livewire::actingAs($this->admin)->test(\App\Livewire\Attendance\Shifts::class)
            ->call('openAssign', null, $e->id, '2026-10-15')->assertSet('startsOn', '2026-10-15')->assertSet('endsOn', '2026-10-15')->assertSet('shiftId', (string) $day->id)
            ->set('shiftId', (string) $night->id)->set('endsOn', '2026-10-17')->call('assign')->assertHasNoErrors();

        $fresh = new \App\Services\ShiftService(app(\App\Services\AuditLogger::class));
        $this->assertSame(['Day', 'Night', 'Night', 'Day'], [$fresh->shiftFor($e, '2026-10-14')?->name, $fresh->shiftFor($e, '2026-10-15')?->name, $fresh->shiftFor($e, '2026-10-17')?->name, $fresh->shiftFor($e, '2026-10-18')?->name], 'The new shift wins on its days; the old one continues after');
        $grid = $fresh->roster(collect([$e]), ['2026-10-14', '2026-10-15', '2026-10-18']);
        $this->assertSame(['Day', 'Night', 'Day'], array_map(fn ($s) => $s?->name, array_values($grid[$e->id])));
        $this->assertTrue($e->user->notifications()->get()->contains(fn ($n) => ($n->data['kind'] ?? '') === 'Shift' && str_contains($n->data['message'], 'Night shift (22:00–06:00) from Thu 15 Oct 2026 to Sat 17 Oct 2026')), 'The employee is told the new shift and its dates');
    }

    public function test_team_attendance_is_in_employee_number_order(): void
    {
        $this->employee('EMP-9');
        $this->employee('EMP-12');
        $this->employee('EMP-3');
        Livewire::actingAs($this->admin)->test(\App\Livewire\Attendance\Dashboard::class)->assertSeeInOrder(['EMP-3', 'EMP-9', 'EMP-12']);
    }

    public function test_teams_show_counts_and_unused_teams_can_be_removed(): void
    {
        $used = \App\Models\Team::create(['name' => 'Night ops', 'is_active' => true]);
        $unused = \App\Models\Team::create(['name' => 'Typo team', 'is_active' => true]);
        app(\App\Services\EmployeeService::class)->syncTeamMembers($used, [$this->employee('EMP-1')->id], $this->admin);

        Livewire::actingAs($this->admin)->test(\App\Livewire\People\Teams::class)
            ->assertSee('2 active')->assertSee('1 people in teams')
            ->call('remove', $used->id)->assertHasErrors('team')->assertSee('Archive it instead')
            ->call('remove', $unused->id)->assertHasNoErrors();
        $this->assertNull($unused->fresh());
        $this->assertNotNull($used->fresh());
    }
}
