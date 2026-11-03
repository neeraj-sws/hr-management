<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\SalarySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** A month that has ended stays in the salary history while its payroll is not approved yet (October in early November). */
class EndedMonthSalaryTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceEmployee $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-03 12:00:00');
        $user = User::factory()->create(['email' => 'oct@company.test']);
        $user->assignRole('Employee');
        $this->e = AttendanceEmployee::create(['employee_code' => 'OC-1', 'name' => 'Oct Person', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '18:30:00', 'daily_work_hours' => 8.5]);
        SalaryProfile::create(['employee_id' => $this->e->id, 'monthly_salary' => 10000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);
        foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $d) {
            AttendanceSession::create(['employee_id' => $this->e->id, 'work_date' => $d, 'check_in' => "$d 10:00:00", 'check_out' => "$d 18:30:00", 'status' => 'completed']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_october_is_shown_before_any_payroll_is_run(): void
    {
        $history = app(SalarySummaryService::class)->history($this->e);
        $this->assertSame('2026-10', $history[0]['month']);
        $this->assertTrue($history[0]['waiting']);
        $this->assertGreaterThan(0, $history[0]['net']);
        $this->assertEquals($history[0]['net'], $history[0]['remaining'], 'Not paid yet: shown as due');
        $this->assertSame('2026-11', app(SalarySummaryService::class)->soFar($this->e)['month']);
    }

    public function test_october_comes_from_the_draft_payroll_then_the_approved_one(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $admin->id);
        $history = app(SalarySummaryService::class)->history($this->e);
        $this->assertSame('2026-10', $history[0]['month']);
        $this->assertSame('draft, waiting for approval', $history[0]['status']);
        $item = $run->items()->where('employee_id', $this->e->id)->firstOrFail();
        $this->assertEquals((float) $item->net_amount, $history[0]['net']);

        app(HrWorkflowService::class)->approvePayroll($run->fresh(), $admin->id);
        $history = app(SalarySummaryService::class)->history($this->e);
        $this->assertSame('2026-10', $history[0]['month']);
        $this->assertArrayNotHasKey('waiting', $history[0]);
        $this->assertCount(1, collect($history)->where('month', '2026-10'), 'Shown once');
    }
}
