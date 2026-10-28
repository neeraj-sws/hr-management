<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Forgot to check out: the desktop app checks out people who left the computer after their shift. */
class AutoCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-23 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->user = User::factory()->create(['email' => 'ac-1@example.com']);
        $this->user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'AC-1', 'name' => 'Person AC-1', 'email' => $this->user->email, 'is_active' => true, 'user_id' => $this->user->id,
            'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8.5]);
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-23', 'check_in' => '2026-10-23 10:00:00', 'status' => 'working', 'last_sync_at' => now()]);
        Sanctum::actingAs($this->user, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_app_knows_the_shift_end_and_the_setting(): void
    {
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.policies.auto_checkout.enabled', true)->assertJsonPath('data.policies.auto_checkout.idle_minutes', 30)
            ->assertJsonPath('data.policies.shift_end', Carbon::parse('2026-10-23 19:00:00')->toIso8601String());

        Livewire::actingAs($this->admin)->test(\App\Livewire\System\Settings::class)->set('autoCheckout', false)->set('autoCheckoutMinutes', '45')->call('saveHours')->assertHasNoErrors();
        Sanctum::actingAs($this->user, ['*']);
        $this->getJson('/api/me')->assertJsonPath('data.policies.auto_checkout.enabled', false)->assertJsonPath('data.policies.auto_checkout.idle_minutes', 45);
        // Turned off: the app's request is refused.
        Carbon::setTestNow('2026-10-23 19:45:00');
        $this->postJson('/api/attendance/auto-check-out', ['last_activity_at' => '2026-10-23 19:10:00'])->assertUnprocessable();
    }

    public function test_left_the_computer_after_the_shift_is_checked_out_at_the_last_use(): void
    {
        // The app reported until 19:10 in use, then 30 minutes untouched (the employee went home at 19:10).
        Carbon::setTestNow('2026-10-23 19:10:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 32400, 'screen_state' => 'active'])->assertOk();
        Carbon::setTestNow('2026-10-23 19:40:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_idle_seconds' => 1800, 'screen_state' => 'idle'])->assertOk();

        $this->postJson('/api/attendance/auto-check-out', ['last_activity_at' => '2026-10-23 19:10:00'])->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $session = AttendanceSession::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('2026-10-23 19:10:00', $session->check_out->format('Y-m-d H:i:s'), 'Checked out when the computer was last used, not now');
        $this->assertSame(0, (int) $session->screen_idle_seconds, 'The evening idle is not part of the day');
        $this->assertTrue($session->autoCheckedOut());
        $this->assertSame(9 * 3600 + 600, app(\App\Services\AttendanceService::class)->workedSeconds($session), '10:00 to 19:10, no break, no idle');

        // The employee is told, and the records say what happened.
        $this->assertTrue($this->user->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'checked out automatically at 07:10 PM')));
        Livewire::actingAs($this->admin)->test(\App\Livewire\Attendance\Records::class)->assertSee('auto check-out (left the computer)');
        Livewire::actingAs($this->user)->test(\App\Livewire\Attendance\MyAttendance::class)->assertSee('auto check-out (left the computer)');
    }

    public function test_server_checks_out_when_the_app_could_not(): void
    {
        $session = AttendanceSession::firstOrFail();
        $service = app(\App\Services\AttendanceService::class);
        // In use all day; the app reports "idle" at 19:20 (5 min idle limit: last used 19:15) and keeps running.
        $session->update(['screen_reported_at' => '2026-10-23 19:50:00', 'last_sync_at' => '2026-10-23 19:50:00']);
        $session->events()->create(['event_type' => 'screen_state', 'event_time' => '2026-10-23 10:00:30', 'metadata' => ['state' => 'active']]);
        $session->events()->create(['event_type' => 'screen_state', 'event_time' => '2026-10-23 19:20:00', 'metadata' => ['state' => 'idle']]);

        // 19:30 = shift end 19:00 + 30 min, but only 15 min unused: nothing yet.
        $this->assertSame(0, $service->autoCheckOutIdleSessions(Carbon::parse('2026-10-23 19:30:00')));
        $this->assertNull($session->fresh()->check_out);
        // 19:50: unused for 35 min → checked out at the last use, 19:15.
        Carbon::setTestNow('2026-10-23 19:50:00');
        $this->artisan('attendance:reminders')->assertSuccessful();
        $this->assertSame('2026-10-23 19:15:00', $session->fresh()->check_out->toDateTimeString());
        $this->assertTrue($this->user->notifications()->where('data->kind', 'AutoCheckout')->exists());
    }

    public function test_server_checks_out_when_the_computer_was_switched_off(): void
    {
        $session = AttendanceSession::firstOrFail();
        // The app's last report was at 18:40 (computer switched off), still "in use" then.
        $session->update(['screen_reported_at' => '2026-10-23 18:40:00', 'last_sync_at' => '2026-10-23 18:40:00']);
        $session->events()->create(['event_type' => 'screen_state', 'event_time' => '2026-10-23 10:00:30', 'metadata' => ['state' => 'active']]);
        $service = app(\App\Services\AttendanceService::class);
        $this->assertSame(0, $service->autoCheckOutIdleSessions(Carbon::parse('2026-10-23 19:20:00')), 'Before shift end + 30 min');
        Carbon::setTestNow('2026-10-23 19:35:00');
        $this->assertSame(1, $service->autoCheckOutIdleSessions());
        $this->assertSame('2026-10-23 18:40:00', $session->fresh()->check_out->toDateTimeString());
    }

    public function test_server_leaves_people_who_are_still_working_or_in_a_meeting(): void
    {
        $session = AttendanceSession::firstOrFail();
        Carbon::setTestNow('2026-10-23 20:30:00');
        $session->update(['screen_reported_at' => now(), 'last_sync_at' => now()]);
        $session->events()->create(['event_type' => 'screen_state', 'event_time' => '2026-10-23 19:00:00', 'metadata' => ['state' => 'meeting']]);
        $this->assertSame(0, app(\App\Services\AttendanceService::class)->autoCheckOutIdleSessions());
        // A mobile-only day (no desktop data at all) is never closed this way.
        $session->events()->delete();
        $session->update(['screen_reported_at' => null]);
        $this->assertSame(0, app(\App\Services\AttendanceService::class)->autoCheckOutIdleSessions());
        $this->assertNull($session->fresh()->check_out);
    }
}
