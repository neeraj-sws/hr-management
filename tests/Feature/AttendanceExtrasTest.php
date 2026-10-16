<?php

namespace Tests\Feature;

use App\Livewire\Attendance\MyAttendance;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\AttendanceAlertService;
use App\Services\AttendanceService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Login by employee ID, late/absent alerts, check-in selfie and website location. */
class AttendanceExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $managerUser;
    private User $employeeUser;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-14 08:00:00'); // Wednesday
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->managerUser = User::factory()->create(['email' => 'ax.mgr@example.com']);
        $this->managerUser->assignRole('Employee');
        $manager = AttendanceEmployee::create(['employee_code' => 'AX-MGR', 'name' => 'Mona Manager', 'is_active' => true, 'user_id' => $this->managerUser->id, 'joined_at' => '2026-01-01']);
        $this->employeeUser = User::factory()->create(['email' => 'ax.emp@example.com', 'password' => Hash::make('Password12345')]);
        $this->employeeUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EMP-042', 'name' => 'Esha Employee', 'is_active' => true, 'user_id' => $this->employeeUser->id, 'manager_id' => $manager->id, 'joined_at' => '2026-01-01', 'work_start' => '09:30:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kinds(User $user, string $kind)
    {
        return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === $kind)->values();
    }

    public function test_sign_in_with_employee_id_on_web_and_app(): void
    {
        $this->post('/login', ['email' => 'emp-042', 'password' => 'Password12345'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->employeeUser);
        $this->post('/logout');
        $this->post('/login', ['email' => 'EMP-042', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'ax.emp@example.com', 'password' => 'Password12345'])->assertRedirect(route('dashboard'));
        $this->post('/logout');
        $this->postJson('/api/auth/token', ['email' => 'EMP-042', 'password' => 'Password12345', 'device_name' => 'phone'])->assertOk()->assertJsonStructure(['token']);
        $this->get('/login')->assertSee('Email or Employee ID');
    }

    public function test_late_check_in_alerts_employee_and_manager(): void
    {
        Carbon::setTestNow('2026-10-14 10:05:00');
        app(AttendanceService::class)->checkIn($this->employee, 'web');
        $this->assertStringContainsString('You checked in late today at 10:05 AM (00:35:00', $this->kinds($this->employeeUser, 'AttendanceLate')->first()->data['message']);
        $this->assertStringContainsString('Esha Employee checked in late', $this->kinds($this->managerUser, 'AttendanceLate')->first()->data['message']);
    }

    public function test_absence_alert_after_the_cut_off_once_a_day_and_not_when_on_leave(): void
    {
        $alerts = app(AttendanceAlertService::class);
        $this->assertSame(0, $alerts->absences(Carbon::parse('2026-10-14 10:30:00')), 'Nothing before 11:00');
        $this->assertSame(2, $alerts->absences(Carbon::parse('2026-10-14 11:15:00')), 'Employee and manager have not checked in');
        $this->assertCount(1, $this->kinds($this->employeeUser, 'AttendanceAbsent'));
        $this->assertTrue($this->kinds($this->managerUser, 'AttendanceAbsent')->contains(fn ($n) => str_contains($n->data['message'], 'Esha Employee has not checked in')));
        $this->assertNotEmpty($this->kinds($this->admin, 'AttendanceAbsent'), 'HR/admin gets a summary');
        $this->assertSame(0, $alerts->absences(Carbon::parse('2026-10-14 11:30:00')), 'Sent once per day');

        Carbon::setTestNow('2026-10-17 12:00:00'); // Saturday — a weekly off
        $this->assertSame(0, $alerts->absences(Carbon::parse('2026-10-17 12:00:00')));
        $this->artisan('attendance:absence-alerts')->assertSuccessful();
    }

    public function test_selfie_is_required_when_turned_on_and_visible_to_the_manager(): void
    {
        Storage::fake('local');
        app(PeopleOperationsService::class)->setPolicy('attendance.selfie', 'attendance', 'Selfie', ['required_web' => true, 'required_mobile' => true], null, $this->admin->id, '2026-01-01');
        Carbon::setTestNow('2026-10-14 09:20:00');
        try {
            app(AttendanceService::class)->checkIn($this->employee, 'web');
            $this->fail('A selfie is required');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Take a selfie', $e->getMessage());
        }
        $this->assertTrue(app(AttendanceService::class)->checkIn($this->employee, 'desktop')->exists, 'The desktop app is not asked for a selfie');
        AttendanceSession::query()->delete();

        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->getJson('/api/me')->assertJsonPath('data.policies.selfie_required', true);
        $this->post('/api/attendance/check-in', ['source' => 'mobile', 'photo' => UploadedFile::fake()->image('selfie.jpg', 400, 400)], ['Accept' => 'application/json'])->assertCreated();
        $session = AttendanceSession::firstOrFail();
        Storage::disk('local')->assertExists($session->check_in_photo_path);

        $this->actingAs($this->managerUser)->get(route('attendance.photo', [$session, 'in']))->assertOk();
        $stranger = User::factory()->create();
        $stranger->assignRole('Employee');
        $this->actingAs($stranger)->get(route('attendance.photo', [$session, 'in']))->assertForbidden();
    }

    public function test_timer_left_running_yesterday_no_longer_blocks_today(): void
    {
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-13', 'check_in' => '2026-10-13 10:00:00', 'status' => 'working', 'last_sync_at' => '2026-10-13 19:00:00']);
        $task = \App\Models\Task::create(['title' => 'Stats API', 'assignee_id' => $this->employee->id, 'status' => 'in_progress', 'priority' => 'normal']);
        $old = \App\Models\TaskTimeEntry::create(['task_id' => $task->id, 'employee_id' => $this->employee->id, 'started_at' => '2026-10-13 14:00:00', 'status' => 'running']);
        Carbon::setTestNow('2026-10-14 10:30:00');
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/attendance/check-in', ['source' => 'desktop'])->assertCreated();
        $this->assertSame(['stopped', '19:00', 5 * 3600], [$old->fresh()->status, $old->fresh()->ended_at->format('H:i'), (int) $old->fresh()->duration_seconds], 'Stopped at the last activity of that day');
        $this->postJson('/api/tasks/'.$task->id.'/start')->assertSuccessful();
    }

    public function test_website_check_in_records_location_and_can_require_an_office(): void
    {
        Carbon::setTestNow('2026-10-14 09:20:00');
        OfficeLocation::create(['name' => 'Indore HQ', 'latitude' => 22.7196, 'longitude' => 75.8577, 'radius_meters' => 200, 'is_active' => true]);
        app(PeopleOperationsService::class)->setPolicy('attendance.location', 'attendance', 'Location', ['required_for_web' => true], null, $this->admin->id, '2026-01-01');

        Livewire::actingAs($this->employeeUser)->test(MyAttendance::class)->call('checkIn')->assertHasErrors('location');
        Livewire::actingAs($this->employeeUser)->test(MyAttendance::class)->call('checkIn', 22.7197, 75.8578, 20)->assertHasNoErrors();
        $session = AttendanceSession::firstOrFail();
        $this->assertSame('Indore HQ', $session->checkInOffice->name);
        $this->get(route('attendance.my'))->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self)');
    }
}
