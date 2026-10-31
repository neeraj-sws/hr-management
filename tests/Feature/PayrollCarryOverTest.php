<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\SalaryAdjustment;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\AttendanceCorrectionService;
use App\Services\HrWorkflowService;
use App\Services\PayrollCarryOver;
use App\Services\PayrollLockGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Leave and attendance corrections approved after a payroll was locked: the difference goes to the next payroll. */
class PayrollCarryOverTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $managerUser;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->managerUser = User::factory()->create();
        $this->managerUser->assignRole(['Employee', 'Manager']);
        $manager = AttendanceEmployee::create(['employee_code' => 'CO-M', 'name' => 'Carry Manager', 'is_active' => true, 'user_id' => $this->managerUser->id, 'joined_at' => '2026-01-01']);
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'CO-E', 'name' => 'Carry Employee', 'is_active' => true, 'user_id' => $user->id, 'manager_id' => $manager->id, 'joined_at' => '2026-01-01']);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        // September: every weekday worked except Thu 10 and Fri 11.
        for ($day = Carbon::parse('2026-09-01'); $day->lte(Carbon::parse('2026-09-30')); $day->addDay()) {
            if ($day->isWeekend() || in_array($day->day, [10, 11], true)) continue;
            $d = $day->toDateString();
            AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $d, 'check_in' => "$d 09:00:00", 'check_out' => "$d 17:00:00", 'active_seconds' => 8 * 3600, 'status' => 'completed']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lockSeptember(): PayrollItem
    {
        $workflow = app(HrWorkflowService::class);
        $run = $workflow->calculatePayroll('2026-09-01', '2026-09-30', $this->admin->id);
        $workflow->approvePayroll($run, $this->admin->id);
        $workflow->lockPayroll($run->fresh(), $this->admin->id);

        return PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
    }

    public function test_leave_and_correction_after_lock_become_arrears_in_the_next_payroll(): void
    {
        $item = $this->lockSeptember();
        $net = (float) $item->net_amount;
        $workflow = app(HrWorkflowService::class);
        $guard = app(PayrollLockGuard::class);
        $this->assertStringContainsString('already processed', (string) $guard->closedNotice($this->employee->id, '2026-09-10'));
        $this->assertNull($guard->closedNotice($this->employee->id, '2026-10-05'));

        // Paid leave for the absent Thursday, asked and approved after the lock.
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $leave = $workflow->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-09-10'), 'ends_on' => Carbon::parse('2026-09-10'), 'reason' => 'Fever']);
        $this->assertSame('approved', $workflow->decideLeave($leave, $this->managerUser->id, true, 'Was ill, informed by phone')->status);
        // A forgotten check-in on the absent Friday, corrected after the lock.
        $corrections = app(AttendanceCorrectionService::class);
        $correction = $corrections->request($this->employee, '2026-09-11', '09:00', '17:00', 'Forgot to check in');
        $corrections->decide($correction, $this->managerUser->id, true);

        $carried = SalaryAdjustment::where('carried_from_payroll_item_id', $item->id)->orderBy('id')->get();
        $this->assertSame([['arrear', '1000.00', '2026-10-01'], ['arrear', '1000.00', '2026-10-01']], $carried->map(fn ($a) => [$a->kind, $a->amount, $a->effective_on->toDateString()])->all(), 'One day each at ₹22,000 ÷ 22');
        $this->assertStringContainsString('after the payroll for 01 Sep – 30 Sep 2026 was locked', $carried[0]->reason);
        $this->assertEquals($net, (float) $item->fresh()->net_amount, 'The locked payroll is not changed');
        $this->assertCount(0, app(PayrollCarryOver::class)->settle($this->employee->id, '2026-09-01', '2026-09-30', 'again'), 'Each difference is carried once');
        $this->assertTrue($this->employee->user->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'will be added to your next salary')));

        // October's payroll pays both arrears.
        Carbon::setTestNow('2026-11-02 10:00:00');
        $october = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $octoberItem = PayrollItem::where('payroll_run_id', $october->id)->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertEquals(2000, (float) $octoberItem->adjustments_amount);
        $this->assertSame(2, SalaryAdjustment::where('payroll_item_id', $octoberItem->id)->whereIn('kind', ['arrear', 'arrear_recovery'])->count());
    }

    public function test_a_change_that_lowers_pay_is_recovered_from_the_next_payroll(): void
    {
        $item = $this->lockSeptember();
        $unpaid = LeaveType::where('name', 'Unpaid Leave')->firstOrFail();
        // Worked on Mon 14 Sep, but it was really an unpaid leave day counted as leave.
        $workflow = app(HrWorkflowService::class);
        $leave = $workflow->requestLeave($this->employee, ['leave_type_id' => $unpaid->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-09-14'), 'ends_on' => Carbon::parse('2026-09-14'), 'reason' => 'Family function']);
        $workflow->decideLeave($leave, $this->managerUser->id, true, 'Informed by phone');
        app(\App\Services\LeaveConflictService::class)->resolve($leave, '2026-09-14', 'leave', $this->admin->id);

        $carried = SalaryAdjustment::where('carried_from_payroll_item_id', $item->id)->get();
        $this->assertCount(1, $carried, 'Approving the leave alone changes nothing (the day counts as worked); resolving it as leave does');
        $this->assertSame(['arrear_recovery', '1000.00'], [$carried[0]->kind, $carried[0]->amount]);
        $this->assertEquals(-1000, $carried[0]->signedAmount());
    }

    public function test_employee_sees_actual_working_time_and_the_payroll_notice_when_asking(): void
    {
        $this->lockSeptember();
        $user = $this->employee->user;
        \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Attendance\Corrections::class)
            ->call('create', '2026-09-14')
            ->assertSee('Recorded on Mon 14 Sep 2026')->assertSee('Worked')->assertSee('08:00:00')
            ->assertSee('Salary for 01 Sep – 30 Sep 2026 is already processed')
            ->set('workDate', '2026-10-05')->assertDontSee('is already processed');
        \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Leave\Requests::class)
            ->call('create')->set('startsOn', '2026-09-21')->set('endsOn', '2026-09-21')->assertSee('is already processed');
        \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Attendance\MyAttendance::class)->set('month', '2026-09')
            ->assertSee('Worked')->assertSee('160:00:00')->assertSee('of 160:00:00 required on 20 days')->assertSee('In–out time 160:00:00');
        $correction = app(AttendanceCorrectionService::class)->request($this->employee, '2026-09-14', null, '18:00', 'Stayed late');
        \Livewire\Livewire::actingAs($this->managerUser)->test(\App\Livewire\Attendance\Corrections::class)
            ->call('openDecision', $correction->id)->assertSee('actual working time 08:00:00')->assertSee('After approval')->assertSee('09:00:00')
            ->assertSee('the difference is added to (or recovered from) the next salary');
    }

    public function test_member_lists_show_a_count_that_opens_the_names(): void
    {
        $project = \App\Models\Project::create(['name' => 'Portal', 'status' => 'active']);
        $project->members()->attach([$this->employee->id]);
        $this->actingAs($this->employee->user)->get(route('work.my-projects'))->assertOk()
            ->assertSee('1 member')->assertSee('x-teleport="body"', false)->assertSee('Members · Portal')->assertSee('CO-E');
        $team = \App\Models\Team::create(['name' => 'Alpha', 'is_active' => true]);
        $team->members()->attach($this->employee->id);
        $this->actingAs($this->admin)->get(route('people.teams'))->assertOk()->assertSee('1 member')->assertSee('Team · Alpha')->assertDontSeeHtml('<span class="pill">Carry Employee</span>');
        $this->actingAs($this->admin)->get(route('people.managers'))->assertOk()->assertSee('1 direct report')->assertSee('Reports to Carry Manager');
    }

    public function test_leave_on_a_weekly_off_explains_why_no_leave_is_needed(): void
    {
        app(\App\Services\PeopleOperationsService::class)->setPolicy('calendar.working_days', 'calendar', 'Working calendar', \App\Support\WorkCalendar::normalise(['days' => [1, 2, 3, 4, 5, 6], 'holidays' => [], 'off_weeks' => ['6' => [3]]]), null, $this->admin->id, '2026-01-01');
        $sick = LeaveType::where('name', 'Unpaid Leave')->firstOrFail();
        $ask = fn (string $from, string $to, string $duration = 'full') => \Livewire\Livewire::actingAs($this->employee->user)->test(\App\Livewire\Leave\Requests::class)
            ->call('create')->set('leaveTypeId', $sick->id)->set('duration', $duration)->set('startsOn', $from)->set('endsOn', $to)->set('reason', 'Fever')->call('submit');
        $ask('2026-10-17', '2026-10-17')->assertSee('Sat 17 Oct 2026 is not a working day for you (3rd Saturday — weekly off), so no leave is needed.');
        $ask('2026-10-18', '2026-10-18', 'half')->assertSee('Sun 18 Oct 2026 is not a working day for you (Sunday — weekly off)');
        $ask('2026-10-17', '2026-10-18')->assertSee('17 Oct – 18 Oct 2026 has no working days for you');
        $ask('2026-10-24', '2026-10-24')->assertHasNoErrors(); // the 4th Saturday is a working day
    }
}
