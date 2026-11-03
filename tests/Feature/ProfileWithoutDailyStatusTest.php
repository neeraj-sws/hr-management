<?php

namespace Tests\Feature;

use App\Livewire\People\EmployeeProfile;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\PeopleOperationsService;
use App\Support\DailyStatusRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Someone who does not write a daily status (e.g. cleaning staff) and worked a day without one: the profile opens. */
class ProfileWithoutDailyStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_opens_for_a_worked_day_without_a_daily_status_when_none_is_needed(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-11-03 12:00:00');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'CL-1', 'name' => 'Cleaning Staff', 'is_active' => true, 'joined_at' => '2026-01-01', 'daily_work_hours' => 1, 'work_start' => '08:00:00', 'work_end' => '09:00:00']);
        app(PeopleOperationsService::class)->setPolicy(DailyStatusRule::KEY, 'work', 'Daily status', ['required' => 0, 'reminder' => 0], $employee->id, $admin->id, '2026-01-01');
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => '2026-10-30', 'check_in' => '2026-10-30 08:00:00', 'check_out' => '2026-10-30 09:00:00', 'status' => 'completed']);

        Livewire::actingAs($admin)->test(EmployeeProfile::class, ['employee' => $employee])->assertOk();
        $this->actingAs($admin)->get(route('people.employees.show', $employee))->assertOk();
        Carbon::setTestNow();
    }
}
