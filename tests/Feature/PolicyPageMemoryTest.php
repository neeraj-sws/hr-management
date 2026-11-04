<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Records;
use App\Models\AttendanceEmployee;
use App\Models\EmployeePolicyOverride;
use App\Models\EmployeeShift;
use App\Models\EmployeeTeamHistory;
use App\Models\PolicyRule;
use App\Models\Shift;
use App\Models\Team;
use App\Models\TeamPolicyOverride;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PolicyConfigurationService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Attendance Records reads each rule once per page: the answers must be exactly the ones the database gives. */
class PolicyPageMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PolicyConfigurationService::stop();
        parent::tearDown();
    }

    public function test_remembered_rules_match_the_database_for_every_date(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-30 12:00:00');
        $a = AttendanceEmployee::create(['employee_code' => 'EMP-01', 'name' => 'With history', 'is_active' => true, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'daily_work_hours' => 8]);
        $b = AttendanceEmployee::create(['employee_code' => 'EMP-02', 'name' => 'Current team only', 'is_active' => true, 'joined_at' => '2026-01-01', 'work_start' => '09:30:00']);
        $web = Team::create(['name' => 'IT Web', 'is_active' => true]);
        $ops = Team::create(['name' => 'Ops', 'is_active' => true]);
        $b->teams()->attach($web->id);
        EmployeeTeamHistory::create(['employee_id' => $a->id, 'team_id' => $ops->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-15']);
        EmployeeTeamHistory::create(['employee_id' => $a->id, 'team_id' => $web->id, 'starts_on' => '2026-10-16']);

        PolicyRule::create(['rule_key' => 'attendance.late_penalty', 'category' => 'compliance', 'name' => 'Late', 'configuration' => ['grace_minutes' => 10, 'amount_per_minute' => 1], 'is_active' => true, 'effective_from' => '2026-10-10']);
        PolicyRule::create(['rule_key' => 'attendance.late_penalty', 'category' => 'compliance', 'name' => 'Late old', 'configuration' => ['grace_minutes' => 0], 'is_active' => true, 'effective_from' => null]);
        TeamPolicyOverride::create(['team_id' => $web->id, 'rule_key' => 'attendance.late_penalty', 'configuration' => ['grace_minutes' => 60], 'effective_from' => '2026-10-20']);
        TeamPolicyOverride::create(['team_id' => $ops->id, 'rule_key' => 'attendance.late_penalty', 'configuration' => ['grace_minutes' => 30]]);
        EmployeePolicyOverride::create(['employee_id' => $a->id, 'rule_key' => 'attendance.late_penalty', 'configuration' => ['amount_per_minute' => 2], 'effective_from' => '2026-10-05', 'effective_until' => '2026-10-25']);
        EmployeePolicyOverride::create(['employee_id' => $a->id, 'rule_key' => 'work.hours', 'configuration' => ['standard_daily_hours' => 6], 'effective_from' => '2026-10-12']);
        $late = Shift::create(['name' => 'Late start', 'start_time' => '11:00', 'end_time' => '20:00', 'grace_minutes' => 15, 'is_active' => true]);
        EmployeeShift::create(['employee_id' => $b->id, 'shift_id' => $late->id, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-22']);

        $policies = app(PolicyConfigurationService::class);
        $read = function () use ($policies, $a, $b) {
            $out = [];
            for ($d = Carbon::parse('2026-09-28'); $d->lte(Carbon::parse('2026-11-02')); $d->addDay()) {
                foreach ([$a, $b] as $e) {
                    $day = $d->toDateString();
                    // Fresh services each time, so their own small caches do not hide a difference.
                    $shifts = app()->make(ShiftService::class);
                    $attendance = app()->make(AttendanceService::class, ['shifts' => $shifts]);
                    $out[] = [$day, $e->id, $policies->forEmployee($e, 'attendance.late_penalty', $day, ['none' => true]), $policies->forEmployee($e, 'work.hours', $day),
                        $attendance->rules($e, $day), $attendance->dailyHours($e, $day), app()->make(ShiftService::class)->shiftFor($e, $day)?->id];
                }
            }

            return $out;
        };

        $fromDatabase = $read();
        PolicyConfigurationService::start();
        $fromMemory = $read();
        PolicyConfigurationService::stop();

        $this->assertSame($fromDatabase, $fromMemory);
        // Sanity: the layers really differ across the month (team change, dated overrides, shift window).
        $this->assertGreaterThanOrEqual(4, collect($fromDatabase)->where(1, $a->id)->pluck(2)->unique()->count());
    }

    public function test_the_memory_is_off_again_after_the_page_is_drawn(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        Livewire::actingAs($admin)->test(Records::class)->assertOk()->set('from', today()->subDay()->toDateString());

        $this->assertFalse(PolicyConfigurationService::on());
    }
}
