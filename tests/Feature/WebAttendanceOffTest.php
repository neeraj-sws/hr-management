<?php

namespace Tests\Feature;

use App\Livewire\Attendance\MyAttendance;
use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Attendance: website check-in / break / check-out can be switched off for everyone (screen time can't be tracked there). */
class WebAttendanceOffTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->staff = User::create(['name' => 'Preeti Test', 'email' => 'preeti@company.test', 'password' => bcrypt('secret-password-1'), 'is_active' => true]);
        $this->staff->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'WEB-1', 'name' => 'Preeti Test', 'email' => 'preeti@company.test', 'user_id' => $this->staff->id, 'is_active' => true, 'joined_at' => '2026-01-01', 'daily_work_hours' => 8, 'work_start' => '10:00:00', 'work_end' => '18:00:00']);
    }

    public function test_website_buttons_show_by_default(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->assertSet('webAttendance', true);
        Livewire::actingAs($this->staff)->test(MyAttendance::class)->assertSee('Check in')->assertDontSee("can't track screen and idle time");
    }

    public function test_switching_website_off_hides_the_buttons_and_blocks_web_check_in(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->set('webAttendance', false)->call('saveHours')->assertHasNoErrors();
        Livewire::actingAs($this->admin)->test(Settings::class)->assertSet('webAttendance', false);

        $attendance = app(AttendanceService::class);
        $this->assertSame(['mobile', 'desktop'], $attendance->allowedSources($this->employee));

        Livewire::actingAs($this->staff)->test(MyAttendance::class)
            ->assertSee('Check in, take breaks and check out in the Desktop app or Mobile app')
            ->assertDontSee('Start break')
            ->call('checkIn')->assertHasErrors('attendance');
        $this->assertSame(0, AttendanceSession::where('employee_id', $this->employee->id)->count());

        // The desktop app still checks in, and the page then has no break / check-out buttons either.
        $attendance->checkIn($this->employee, 'desktop');
        Livewire::actingAs($this->staff)->test(MyAttendance::class)->assertDontSee('Start break')->assertDontSee('Check out for today');

        // Back on: the buttons return.
        Livewire::actingAs($this->admin)->test(Settings::class)->set('webAttendance', true)->call('saveHours')->assertHasNoErrors();
        Livewire::actingAs($this->staff)->test(MyAttendance::class)->assertSee('Start break');
    }

    public function test_one_person_can_still_be_allowed_the_website(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->set('webAttendance', false)->call('saveHours');
        app(PeopleOperationsService::class)->setPolicy('attendance.sources', 'attendance', 'Attendance methods', ['allowed' => ['web', 'mobile', 'desktop']], $this->employee->id, $this->admin->id, today()->toDateString());

        Livewire::actingAs($this->staff)->test(MyAttendance::class)->assertSee('Check in')->assertDontSee("can't track screen and idle time")
            ->call('checkIn')->assertHasNoErrors();
        $this->assertSame(1, AttendanceSession::where('employee_id', $this->employee->id)->count());
    }

    public function test_desktop_only_for_everyone_and_mobile_for_cleaning_staff(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->set('webAttendance', false)->set('mobileAttendance', false)->call('saveHours')->assertHasNoErrors();
        Livewire::actingAs($this->admin)->test(Settings::class)->assertSet('webAttendance', false)->assertSet('mobileAttendance', false);
        $attendance = app(AttendanceService::class);
        $this->assertSame(['desktop'], $attendance->allowedSources($this->employee));

        Livewire::actingAs($this->staff)->test(MyAttendance::class)->assertSee('Check in, take breaks and check out in the Desktop app.');
        \Laravel\Sanctum\Sanctum::actingAs($this->staff, ['*']);
        $this->postJson('/api/attendance/check-in', ['source' => 'mobile'])->assertUnprocessable();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.policies.attendance_sources', ['desktop']);

        // Cleaning staff: HR turns on Mobile app in her profile → Attendance methods.
        $aunty = User::create(['name' => 'Safai Aunty', 'email' => 'safai@company.test', 'password' => bcrypt('secret-password-1'), 'is_active' => true]);
        $aunty->assignRole('Employee');
        $cleaner = AttendanceEmployee::create(['employee_code' => 'EMP-17', 'name' => 'Safai Aunty', 'email' => 'safai@company.test', 'user_id' => $aunty->id, 'is_active' => true, 'joined_at' => '2026-01-01', 'daily_work_hours' => 1, 'work_start' => '09:00:00', 'work_end' => '10:00:00']);
        Livewire::actingAs($this->admin)->test(\App\Livewire\People\EmployeeProfile::class, ['employee' => $cleaner])
            ->call('setAttendanceSources', ['desktop', 'mobile']);
        $this->assertSame(['mobile', 'desktop'], $attendance->allowedSources($cleaner));
        \Laravel\Sanctum\Sanctum::actingAs($aunty, ['*']);
        $this->postJson('/api/attendance/check-in', ['source' => 'mobile'])->assertCreated();
    }

    public function test_saving_settings_without_changing_it_adds_no_rule(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->call('saveHours')->assertHasNoErrors();
        $this->assertFalse(\App\Models\PolicyRule::where('rule_key', 'attendance.sources')->exists());
    }
}
