<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Break started: the manager gets a bell notification and a phone push, not an e-mail. */
class BreakNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $bossUser;
    private User $user;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-27 13:15:00');
        $this->bossUser = User::factory()->create(['email' => 'boss@company.test']);
        $this->bossUser->assignRole('Manager');
        $boss = AttendanceEmployee::create(['employee_code' => 'BR-M', 'name' => 'Boss Person', 'is_active' => true, 'user_id' => $this->bossUser->id, 'joined_at' => '2026-01-01']);
        $this->user = User::factory()->create(['email' => 'riya@company.test']);
        $this->user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'BR-1', 'name' => 'Riya Sharma', 'is_active' => true, 'user_id' => $this->user->id, 'manager_id' => $boss->id, 'joined_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manager_is_told_when_a_break_starts(): void
    {
        $attendance = app(AttendanceService::class);
        Carbon::setTestNow('2026-10-27 10:00:00');
        $attendance->checkIn($this->employee, 'web');
        Carbon::setTestNow('2026-10-27 13:15:00');
        $attendance->startBreak($this->employee, 'web');

        $note = $this->bossUser->notifications()->first();
        $this->assertNotNull($note, 'The manager has a bell notification');
        $this->assertSame('AttendanceBreak', $note->data['kind']);
        $this->assertSame('Riya Sharma started a break at 01:15 PM.', $note->data['message']);
        $this->assertSame(0, $this->user->notifications()->count(), 'The employee is not notified');

        // Bell + push, never e-mail; the manager can switch it off under "Late arrival, absence and break alerts".
        $channels = (new ApprovalRequested('AttendanceBreak', 1, 'x', null, false))->via($this->bossUser);
        $this->assertContains('database', $channels);
        $this->assertNotContains(\App\Notifications\Channels\SafeMailChannel::class, $channels);
        $this->bossUser->forceFill(['notification_preferences' => ['attendance' => ['in_app' => false, 'push' => false, 'email' => false]]])->save();
        $this->assertSame([], (new ApprovalRequested('AttendanceBreak', 1, 'x', null, false))->via($this->bossUser->fresh()));
    }

    public function test_a_break_sent_late_by_an_offline_phone_is_not_announced(): void
    {
        $attendance = app(AttendanceService::class);
        Carbon::setTestNow('2026-10-27 10:00:00');
        $attendance->checkIn($this->employee, 'web');
        Carbon::setTestNow('2026-10-27 15:00:00');
        $attendance->startBreak($this->employee, 'web', Carbon::parse('2026-10-27 13:00:00'));

        $this->assertSame(0, $this->bossUser->notifications()->count());
    }

    public function test_manager_is_told_when_the_break_ends_and_long_breaks_alert_once(): void
    {
        $attendance = app(AttendanceService::class);
        Carbon::setTestNow('2026-10-27 10:00:00');
        $attendance->checkIn($this->employee, 'web');
        Carbon::setTestNow('2026-10-27 13:00:00');
        $attendance->startBreak($this->employee, 'web');

        // 59 minutes: nothing yet; 61 minutes: employee and manager told; again later: not repeated.
        $alerts = app(\App\Services\AttendanceAlertService::class);
        Carbon::setTestNow('2026-10-27 13:59:00');
        $this->assertSame(0, $alerts->longBreaks());
        Carbon::setTestNow('2026-10-27 14:01:00');
        $this->assertSame(1, $alerts->longBreaks());
        $this->artisan('attendance:reminders')->assertSuccessful();
        $this->assertSame(1, $this->bossUser->notifications()->where('data->kind', 'AttendanceLongBreak')->count());
        $this->assertSame('Riya Sharma has been on break for 1 h 1 min (since 01:00 PM).', $this->bossUser->notifications()->where('data->kind', 'AttendanceLongBreak')->first()->data['message']);
        $this->assertStringContainsString('Press Resume', $this->user->notifications()->where('data->kind', 'AttendanceLongBreak')->first()->data['message']);

        Carbon::setTestNow('2026-10-27 14:10:00');
        $attendance->endBreak($this->employee, 'web');
        $this->assertSame('Riya Sharma is back from break at 02:10 PM · break of 1 h 10 min.', $this->bossUser->notifications()->where('data->kind', 'AttendanceBreak')->latest()->first()->data['message']);
    }

    public function test_break_alerts_can_be_switched_off(): void
    {
        app(\App\Services\PeopleOperationsService::class)->setPolicy('attendance.alerts', 'attendance', 'Late and absence alerts', ['enabled' => true, 'absent_after' => '11:00', 'break_alerts' => false, 'long_break_minutes' => 0], null, User::where('email', 'admin@example.com')->value('id'), '2026-01-01');
        $attendance = app(AttendanceService::class);
        Carbon::setTestNow('2026-10-27 10:00:00');
        $attendance->checkIn($this->employee, 'web');
        Carbon::setTestNow('2026-10-27 13:00:00');
        $attendance->startBreak($this->employee, 'web');
        Carbon::setTestNow('2026-10-27 16:00:00');
        $this->assertSame(0, app(\App\Services\AttendanceAlertService::class)->longBreaks());
        $attendance->endBreak($this->employee, 'web');
        $this->assertSame(0, $this->bossUser->notifications()->count());
    }

    public function test_hr_and_admins_also_get_break_alerts(): void
    {
        $hr = User::factory()->create(['email' => 'hr@company.test']);
        $hr->assignRole('HR');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $attendance = app(AttendanceService::class);
        Carbon::setTestNow('2026-10-27 10:00:00');
        $attendance->checkIn($this->employee, 'web');
        Carbon::setTestNow('2026-10-27 13:15:00');
        $attendance->startBreak($this->employee, 'web');

        foreach ([$this->bossUser, $hr, $admin] as $who) {
            $this->assertSame(1, $who->notifications()->where('data->kind', 'AttendanceBreak')->count(), $who->email.' is told once');
        }
        $this->assertSame(0, $this->user->notifications()->count(), 'The employee is not told about their own break');
    }
}
