<?php

namespace Tests\Feature;

use App\Livewire\Payroll\DayWise;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeePenalty;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\DayWiseSalaryService;
use App\Services\HrWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Payroll > Day-wise salary: each day's pay, extra working and fines add up to what payroll pays; admin / HR see
 * everyone, a manager only their team, and admin, HR and the manager can take back a fine with a reason.
 */
class DayWiseSalaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $manager;
    private AttendanceEmployee $employee;
    private AttendanceEmployee $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->manager = $this->person('MGR-1', 'Praveen Owner');
        $this->employee = $this->person('EMP-21', 'Riya Dev', $this->manager->id);
        $this->other = $this->person('EMP-22', 'Someone Else');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $code, string $name, ?int $managerId = null): AttendanceEmployee
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('Employee');
        $e = AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'manager_id' => $managerId, 'work_start' => '10:00:00', 'work_end' => '18:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);

        return $e;
    }

    private function day(AttendanceEmployee $e, string $date, string $in, string $out): void
    {
        $seconds = Carbon::parse("{$date} {$in}")->diffInSeconds(Carbon::parse("{$date} {$out}"));
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => $date, 'check_in' => "{$date} {$in}:00", 'check_out' => "{$date} {$out}:00", 'active_seconds' => (int) $seconds, 'status' => 'completed']);
    }

    public function test_days_add_up_to_what_payroll_pays(): void
    {
        foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'] as $d) $this->day($this->employee, $d, '10:00', '18:00');
        $this->day($this->employee, '2026-10-11', '10:00', '20:00'); // Sunday: extra working, 10 h paid as 8 h
        EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-06', 'amount' => 500, 'reason' => 'Late: came 10:30 AM', 'requested_by' => $this->admin->id, 'status' => 'approved', 'source_key' => 'late:'.$this->employee->id.':2026-10-06']);

        $data = app(DayWiseSalaryService::class)->month($this->employee, '2026-10');
        $rows = collect($data['rows'])->keyBy('date');
        $this->assertCount(31, $data['rows']);
        $this->assertSame('Present', $rows['2026-10-05']['status']);
        $this->assertEqualsWithDelta(1000, $rows['2026-10-05']['pay'], 0.01, '22000 / 22 days');
        $this->assertEqualsWithDelta(500, $rows['2026-10-06']['net'], 0.01, 'The day less its 500 fine');
        $this->assertSame('Extra working', $rows['2026-10-11']['status']);
        $this->assertEqualsWithDelta(1000, $rows['2026-10-11']['extra'], 0.01, 'At most one working day');
        $this->assertSame('Absent', $rows['2026-10-09']['status']);

        $estimate = app(HrWorkflowService::class)->payEstimate($this->employee, '2026-10-01', '2026-10-31');
        $this->assertEqualsWithDelta($estimate['net'], $data['net'], 0.01);
        $this->assertEqualsWithDelta($data['net'], $data['totals']['net'] + array_sum(array_column($data['items'], 'amount')), 0.01, 'Days + month items = net salary');
    }

    public function test_manager_sees_only_their_team_and_can_take_back_a_fine(): void
    {
        $fine = EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-06', 'amount' => 500, 'reason' => 'Late: came 10:30 AM', 'requested_by' => $this->admin->id, 'status' => 'approved', 'source_key' => 'late:'.$this->employee->id.':2026-10-06']);
        $boss = $this->manager->user;

        $this->actingAs($boss)->get(route('payroll.day-wise', ['employee' => $this->employee->id, 'month' => '2026-10']))->assertOk()->assertSee('Riya Dev')->assertDontSee('Someone Else');
        Livewire::actingAs($boss)->test(DayWise::class, ['employee' => $this->other->id])->assertSet('employee', $this->employee->id);
        $this->actingAs($this->employee->user)->get(route('payroll.day-wise'))->assertForbidden();

        Livewire::actingAs($boss)->test(DayWise::class)->set('month', '2026-10')->set('employee', $this->employee->id)
            ->call('openRemove', [$fine->id])->assertSet('removing', [$fine->id])
            ->call('saveRemove')->assertHasErrors('removeNote')
            ->set('removeNote', 'Client visit in the morning, informed me')->call('saveRemove')->assertHasNoErrors();
        $this->assertSame('waived', $fine->fresh()->status);
        $this->assertSame('Taken back: Client visit in the morning, informed me', $fine->fresh()->decision_note);
        $this->assertStringContainsString('were taken back', $this->employee->user->notifications()->latest()->first()->data['message']);
    }

    public function test_admin_sees_everyone_and_cannot_take_back_another_employees_fine_through_this_page(): void
    {
        $fine = EmployeePenalty::create(['employee_id' => $this->other->id, 'effective_on' => '2026-10-06', 'amount' => 500, 'reason' => 'Late', 'requested_by' => $this->admin->id, 'status' => 'approved', 'source_key' => 'late:'.$this->other->id.':2026-10-06']);
        $this->actingAs($this->admin)->get(route('payroll.day-wise', ['employee' => $this->other->id, 'month' => '2026-10']))->assertOk()->assertSee('Someone Else')->assertSee('Remove fine');

        // The page only takes back fines of the employee shown.
        Livewire::actingAs($this->admin)->test(DayWise::class)->set('month', '2026-10')->set('employee', $this->employee->id)->call('openRemove', [$fine->id])->assertSet('removing', []);
        $this->assertSame('approved', $fine->fresh()->status);
    }
}
