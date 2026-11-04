<?php

namespace Tests\Feature;

use App\Livewire\Payroll\Salary;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeePenalty;
use App\Models\EmployeePolicyOverride;
use App\Models\PayrollItem;
use App\Models\Project;
use App\Models\SalaryProfile;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use App\Services\SalarySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Payroll > Salary > "Pay only for project days": an employee paid only for the days they have a task of chosen
 * projects. Other days: no pay, and their short time and fines are not cut either.
 */
class ProjectDaysPayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;
    private Project $skc;
    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $people = app(PeopleOperationsService::class);
        // Mon–Sat, 3rd Saturday off, Dussehra a paid holiday: October 2026 has 25 working days (₹10,000 ÷ 25 = ₹400 a day).
        $people->setPolicy('calendar.working_days', 'calendar', 'Calendar', ['days' => [1, 2, 3, 4, 5, 6], 'holidays' => ['2026-10-20'], 'holiday_types' => ['2026-10-20' => ['category' => 'public', 'paid' => true]], 'off_weeks' => ['6' => [3]]], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('work.hours', 'attendance', 'Work hours', ['start_time' => '10:00', 'end_time' => '19:30', 'standard_daily_hours' => 8.5], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation', ['working_days_per_month' => 25, 'divisor' => 'calendar', 'short_hours_deduction' => true, 'half_day_pay' => false, 'late_checkin_hours_pay' => false, 'half_leave_hours_only' => false, 'extra_work_auto' => true, 'hourly_rate_hours' => 9], null, $this->admin->id, '2026-01-01');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'VV-1', 'name' => 'Vaibhav Verma', 'is_active' => true, 'joined_at' => '2026-01-01', 'daily_work_hours' => 8.5]);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 10000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);
        $this->skc = Project::create(['name' => 'SKC World', 'status' => 'active']);
        $this->other = Project::create(['name' => 'Print Shop', 'status' => 'active']);

        // Five days at work. 5 Oct: SKC task. 6 Oct: SKC timer. 7 Oct: SKC task, 1 h short. 8 Oct: Print Shop only, 2 h short. 9 Oct: no task.
        foreach (['2026-10-05' => '19:30', '2026-10-06' => '19:30', '2026-10-07' => '18:30', '2026-10-08' => '17:30', '2026-10-09' => '19:30'] as $date => $out) {
            AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $date, 'check_in' => "{$date} 10:00:00", 'check_out' => "{$date} {$out}:00", 'break_seconds' => 3600, 'active_seconds' => 0, 'status' => 'completed']);
        }
        Task::create(['project_id' => $this->skc->id, 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'title' => 'SKC Banner', 'status' => 'completed', 'priority' => 'medium', 'work_date' => '2026-10-05']);
        $timed = Task::create(['project_id' => $this->skc->id, 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'title' => 'SKC Slider', 'status' => 'completed', 'priority' => 'medium', 'work_date' => '2026-10-20']);
        TaskTimeEntry::create(['task_id' => $timed->id, 'employee_id' => $this->employee->id, 'started_at' => '2026-10-06 11:00:00', 'ended_at' => '2026-10-06 13:00:00', 'duration_seconds' => 7200, 'status' => 'stopped']);
        Task::create(['project_id' => $this->skc->id, 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'title' => 'SKC Menu', 'status' => 'completed', 'priority' => 'medium', 'work_date' => '2026-10-07']);
        Task::create(['project_id' => $this->other->id, 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'title' => 'Print', 'status' => 'completed', 'priority' => 'medium', 'work_date' => '2026-10-08']);
        // A fine on an SKC day and one on a day that is not paid.
        EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-07', 'amount' => 100, 'reason' => 'Late', 'status' => 'approved']);
        EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-09', 'amount' => 500, 'reason' => 'No daily status', 'status' => 'approved']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_project_days_are_paid_and_only_their_cuts_apply(): void
    {
        Livewire::actingAs($this->admin)->test(Salary::class)
            ->call('newProjectRule')->assertSet('prFrom', '2026-10-01')
            ->set('prEmployeeId', $this->employee->id)->set('prProjects', [$this->skc->id])->set('prReason', 'On training; paid only for SKC work')
            ->call('saveProjectRule')->assertHasNoErrors()
            ->assertSeeText('Pay only for project days')->assertSeeText('SKC World')->assertSeeText('On training; paid only for SKC work');
        $this->assertSame([$this->skc->id], EmployeePolicyOverride::where('rule_key', 'payroll.project_days')->firstOrFail()->configuration['projects']);

        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
        $b = $item->calculation_breakdown;

        $this->assertEquals(3, (float) $item->present_days, '5, 6 (timer) and 7 Oct are SKC days');
        $this->assertEqualsWithDelta(1200, $b['base_salary'], 0.01, '3 days × ₹400');
        $this->assertSame(['2026-10-07'], array_keys($b['short_hours']['days']), 'Short time only on the paid day 7 Oct, not on 8 Oct');
        $this->assertEqualsWithDelta(44.44, $b['short_hours']['amount'], 0.01, '60 min × ₹400 ÷ 9 ÷ 60');
        $this->assertEquals(100, (float) $item->penalties_amount, 'Only the fine of an SKC day; 9 Oct is not paid, so its fine is not cut');
        $this->assertEqualsWithDelta(1200 - 44.44 - 100, (float) $item->net_amount, 0.01);
        $this->assertSame(['2026-10-08', '2026-10-09'], $b['project_days']['not_paid']);
        $this->assertEquals(500, $b['project_days']['fines_not_cut']);
        $this->assertNull(EmployeePenalty::where('effective_on', '2026-10-09')->value('payroll_item_id'), 'The fine of an unpaid day is not taken by this payroll');

        $s = app(SalarySummaryService::class)->forItem($item)['shortfall'];
        $line = collect($s['lines'])->first(fn ($l) => str_starts_with($l['label'], 'Not on project work'));
        $this->assertSame('Not on project work (SKC World) — 2 days', $line['label']);
        $this->assertStringContainsString('08–09 Oct', $line['detail']);
        $this->assertStringContainsString('₹500.00 not cut', $line['detail']);
    }

    public function test_without_the_rule_every_day_is_paid_and_the_rule_can_be_stopped(): void
    {
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertEquals(5, (float) $item->present_days);
        $this->assertNull($item->calculation_breakdown['project_days']);

        app(PeopleOperationsService::class)->setPolicy('payroll.project_days', 'payroll', 'Pay only for project days', ['projects' => [$this->skc->id], 'reason' => 'x'], $this->employee->id, $this->admin->id, '2026-10-01');
        $row = EmployeePolicyOverride::where('rule_key', 'payroll.project_days')->firstOrFail();
        Livewire::actingAs($this->admin)->test(Salary::class)->call('stopProjectRule', $row->id)->assertHasNoErrors();
        $this->assertSame('2026-10-31', EmployeePolicyOverride::find($row->id)->effective_until->toDateString(), 'October keeps it; from November the full salary applies');
    }
}
