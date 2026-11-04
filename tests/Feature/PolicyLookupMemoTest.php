<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\EmployeePolicyOverride;
use App\Models\PolicyRule;
use App\Services\PolicyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Attendance records reads settings once per render: the remembered answers equal the database answers. */
class PolicyLookupMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_remembered_lookups_match_the_database_on_every_date(): void
    {
        $employee = AttendanceEmployee::create(['employee_code' => 'EMP-01', 'name' => 'A', 'is_active' => true, 'joined_at' => '2026-01-01']);
        PolicyRule::create(['rule_key' => 'attendance.rules', 'category' => 'attendance', 'name' => 'Old', 'configuration' => ['grace_minutes' => 5], 'is_active' => true]);
        PolicyRule::create(['rule_key' => 'attendance.rules', 'category' => 'attendance', 'name' => 'New', 'configuration' => ['grace_minutes' => 15], 'is_active' => true, 'effective_from' => '2026-10-15']);
        EmployeePolicyOverride::create(['employee_id' => $employee->id, 'rule_key' => 'attendance.rules', 'configuration' => ['half_day_hours' => 3], 'effective_from' => '2026-10-20', 'effective_until' => '2026-10-25']);

        $plain = new PolicyConfigurationService;
        $remembered = (new PolicyConfigurationService)->remember();
        foreach (['2026-10-01', '2026-10-15', '2026-10-20', '2026-10-25', '2026-10-26'] as $date) {
            $this->assertSame($plain->forEmployee($employee, 'attendance.rules', $date, ['x' => 1]), $remembered->forEmployee($employee, 'attendance.rules', $date, ['x' => 1]), $date);
            $this->assertSame($plain->forEmployee(null, 'attendance.rules', $date), $remembered->forEmployee(null, 'attendance.rules', $date), $date);
        }
        $this->assertSame(['y' => 2], $remembered->forEmployee($employee, 'nothing.set', '2026-10-01', ['y' => 2]));
    }

    public function test_attendance_records_forgets_after_rendering(): void
    {
        $this->seed();
        $admin = \App\Models\User::where('email', 'admin@example.com')->firstOrFail();
        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Attendance\Records::class)->assertOk();
        $this->assertFalse(app()->resolved(PolicyConfigurationService::class) && (fn () => $this->memo !== null)->call(app(PolicyConfigurationService::class)));
    }
}
