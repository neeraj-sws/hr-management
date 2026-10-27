<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Records;
use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Ex-employee: no days after the leaving month in Attendance records; days after the last day are "Left", never absent. */
class ExEmployeeDaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_days_after_leaving(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-27 12:00:00');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $e = AttendanceEmployee::create(['employee_code' => 'EX-1', 'name' => 'Lalit Baingne', 'is_active' => false, 'joined_at' => '2026-01-01', 'exit_date' => '2026-09-12', 'exit_type' => 'resigned']);

        $days = app(AttendanceService::class)->days($e, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-16'))->keyBy('date');
        $this->assertSame('absent', $days['2026-09-11']['status'], 'Before the last day: counted as usual');
        $this->assertSame('left', $days['2026-09-14']['status'], 'After the last day: Left, not absent');

        // October (after the leaving month): no rows at all.
        Livewire::actingAs($admin)->test(Records::class, ['from' => '2026-10-01', 'to' => '2026-10-31', 'employee' => $e->id])
            ->assertViewHas('dayRows', [])->assertSee('left the company on 12 Sep 2026');
        // September and October: the list stops at 30 Sep.
        Livewire::actingAs($admin)->test(Records::class, ['from' => '2026-09-01', 'to' => '2026-10-31', 'employee' => $e->id])
            ->assertViewHas('dayRows', fn ($rows) => $rows[0]['date'] === '2026-09-30' && count($rows) === 30)
            ->assertSee('After leaving the company');
    }

    public function test_employee_dropdown_hides_ex_employees_and_trainees(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $staff = AttendanceEmployee::create(['employee_code' => 'DD-1', 'name' => 'Current Staff', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $trainee = AttendanceEmployee::create(['employee_code' => 'DD-2', 'name' => 'New Trainee', 'is_active' => true, 'joined_at' => '2026-01-01', 'employment_type' => 'trainee']);
        $ex = AttendanceEmployee::create(['employee_code' => 'DD-3', 'name' => 'Gone Person', 'is_active' => false, 'joined_at' => '2026-01-01', 'exit_date' => '2026-09-12', 'employment_type' => 'ex_employee']);

        $names = fn ($test) => $test->viewData('employees')->pluck('name')->all();
        $list = $names(Livewire::actingAs($admin)->test(Records::class));
        $this->assertContains('Current Staff', $list);
        $this->assertNotContains('New Trainee', $list);
        $this->assertNotContains('Gone Person', $list);
        // Opened from a link with the ex-employee chosen: still listed, so the filter shows who it is.
        $this->assertContains('Gone Person', $names(Livewire::actingAs($admin)->test(Records::class, ['employee' => $ex->id])));
    }
}
