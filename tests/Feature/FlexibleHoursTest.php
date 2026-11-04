<?php

namespace Tests\Feature;

use App\Livewire\People\Employees;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeePenalty;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Flexible hours (e.g. cleaning staff with no fixed time): no late / early fines, no half day, nothing cut by the minute. */
class FlexibleHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_flexible_hours_switch_stops_every_time_cut_and_removes_open_fines(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['amount_per_minute' => 2, 'grace_minutes' => 0, 'max_amount' => 0], null, $admin->id, '2026-01-01');
        $people->setPolicy('attendance.early_checkout', 'compliance', 'Early', ['amount_per_minute' => 1, 'grace_minutes' => 0, 'max_amount' => 0], null, $admin->id, '2026-01-01');
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['short_hours_deduction' => true, 'short_hours_grace' => 0], null, $admin->id, '2026-01-01');
        $e = AttendanceEmployee::create(['employee_code' => 'SF-1', 'name' => 'Safai Staff', 'email' => 'safai@company.test', 'is_active' => true, 'joined_at' => '2026-01-01', 'daily_work_hours' => 1, 'work_start' => '08:00:00', 'work_end' => '09:00:00']);
        SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 3000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 1]);
        $day = fn ($d, $in, $out) => AttendanceSession::create(['employee_id' => $e->id, 'work_date' => $d, 'check_in' => "$d $in:00", 'check_out' => "$d $out:00", 'status' => 'completed']);
        $day('2026-10-12', '08:20', '09:10'); // late 20 min
        $day('2026-10-13', '08:00', '08:45'); // early 15 min, short 15 min
        $day('2026-10-14', '08:00', '08:10'); // 10 min: a half day
        $people->assessCompliance('2026-10-12', '2026-10-14', $admin->id);
        $this->assertGreaterThan(0, EmployeePenalty::where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])->count(), 'Fixed hours: fined');

        // HR switches on "Flexible hours" in People > Employees > Edit.
        Livewire::actingAs($admin)->test(Employees::class)->call('edit', $e->id)->assertSet('flexibleHours', false)
            ->set('flexibleHours', true)->call('save')->assertHasNoErrors();
        $this->assertTrue(app(AttendanceService::class)->flexibleHours($e->fresh()));
        $this->assertSame(0, EmployeePenalty::where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])->count(), 'Open fines removed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.flexible_hours_on']);

        $people->assessCompliance('2026-10-12', '2026-10-14', $admin->id);
        $this->assertSame(0, EmployeePenalty::where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])->count(), 'No new fines');
        $attendance = app(AttendanceService::class);
        $s = AttendanceSession::where('employee_id', $e->id)->whereDate('work_date', '2026-10-14')->first();
        $this->assertFalse($attendance->isHalfDay($s));
        $this->assertFalse($attendance->isLate(AttendanceSession::where('employee_id', $e->id)->whereDate('work_date', '2026-10-12')->first()));

        // Pay: each present day is a full day - no half day, nothing by the hours, no short hours.
        $item = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $admin->id)->items()->where('employee_id', $e->id)->firstOrFail();
        $b = $item->calculation_breakdown;
        $this->assertSame([], array_values((array) ($b['half_days'] ?? [])));
        $this->assertSame([], (array) ($b['hours_paid_days'] ?? []));
        $this->assertEquals(0, (float) ($b['short_hours']['amount'] ?? 0));
        $this->assertEqualsWithDelta(3000 / $item->working_days * 3, (float) $b['base_salary'], 0.05);

        Livewire::actingAs($admin)->test(Employees::class)->call('edit', $e->id)->assertSet('flexibleHours', true)->set('flexibleHours', false)->call('save');
        $this->assertFalse(app(AttendanceService::class)->flexibleHours($e->fresh()));
        Carbon::setTestNow();
    }
}
