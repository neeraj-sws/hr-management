<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Time in Teams / Zoom / Meet calls, reported by the desktop app: working time, shown apart from idle. */
class MeetingTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_time_is_stored_shown_and_not_counted_as_idle(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-22 10:00:00');
        $user = User::factory()->create(['email' => 'mt-1@example.com']);
        $user->assignRole('Employee');
        $e = AttendanceEmployee::create(['employee_code' => 'MT-1', 'name' => 'Person MT-1', 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8.5]);
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-22', 'check_in' => '2026-10-22 10:00:00', 'status' => 'working', 'last_sync_at' => now()]);
        Sanctum::actingAs($user, ['*']);

        // An hour later: 20 min typing, 35 min in a call (no mouse/keyboard), 5 min untouched.
        Carbon::setTestNow('2026-10-22 11:00:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 1200, 'screen_meeting_seconds' => 2100, 'screen_idle_seconds' => 300])
            ->assertOk()->assertJsonPath('data.screen_meeting_seconds', 2100)->assertJsonPath('data.screen_idle_seconds', 300);
        $session = AttendanceSession::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame(300, $session->idleSeconds(), 'Only the untouched 5 minutes are idle');

        Livewire::actingAs($user)->test(\App\Livewire\Attendance\MyAttendance::class)->assertSee('Meeting')->assertSee('00:35:00');
        $row = app(ReportService::class)->rows(User::where('email', 'admin@example.com')->first(), 'attendance', ['from' => '2026-10-22', 'to' => '2026-10-22', 'employee_id' => $e->id])->first();
        $this->assertSame('00:35:00', $row['screen_meeting_time']);
        $this->assertSame('00:05:00', $row['screen_idle_time']);

        // Older desktop apps send no meeting time: still accepted.
        Carbon::setTestNow('2026-10-22 11:01:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 60, 'screen_idle_seconds' => 0])->assertOk()->assertJsonPath('data.screen_meeting_seconds', 2100);
    }

    public function test_time_the_desktop_app_missed_counts_once_a_correction_is_approved(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-22 19:00:00');
        $admin = User::where('email', 'admin@example.com')->first();
        $user = User::factory()->create(['email' => 'mt-2@example.com']);
        $user->assignRole('Employee');
        $e = AttendanceEmployee::create(['employee_code' => 'MT-2', 'name' => 'Person MT-2', 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8.5]);
        // 10:00–18:30 worked, but the desktop app stopped for 2.5 hours: it measured only 6 hours, and the gap was booked as a break.
        $s = AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-22', 'check_in' => '2026-10-22 10:00:00', 'check_out' => '2026-10-22 18:30:00', 'status' => 'completed',
            'break_seconds' => 9000, 'screen_active_seconds' => 5 * 3600 + 50 * 60, 'screen_idle_seconds' => 600, 'last_sync_at' => now()]);
        $this->assertSame(0, $s->correctedScreenSeconds(), 'No correction yet: nothing is added');

        // The employee asks for "no break that day"; the admin approves.
        $svc = app(\App\Services\AttendanceCorrectionService::class);
        $svc->decide($svc->request($e, '2026-10-22', null, null, 'Desktop app stopped; I was working', 0), $admin->id, true);
        $s->refresh();
        $this->assertSame(9000, $s->correctedScreenSeconds(), '8:30 in–out − 6:00 the app measured = 2:30 added');

        Livewire::actingAs($admin)->test(\App\Livewire\Attendance\Dashboard::class)->assertSee('02:30:00 added by approved correction')->assertSeeText('98% working');
        $row = app(ReportService::class)->rows($admin, 'attendance', ['from' => '2026-10-22', 'to' => '2026-10-22', 'employee_id' => $e->id])->first();
        $this->assertSame('02:30:00', $row['screen_corrected_time']);
        Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/attendance/today')->assertOk()->assertJsonPath('data.screen_corrected_seconds', 9000);
    }

    public function test_away_time_answered_as_working_is_meeting_time_with_the_same_figures_everywhere(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-23 10:00:00');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['email' => 'mt-3@example.com']);
        $user->assignRole('Employee');
        $e = AttendanceEmployee::create(['employee_code' => 'MT-3', 'name' => 'Person MT-3', 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8.5]);
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-23', 'check_in' => '2026-10-23 10:00:00', 'status' => 'working', 'last_sync_at' => now()]);
        Sanctum::actingAs($user, ['*']);

        // 10 min typing, then a 30-minute meeting away from the computer: the app saw it as idle.
        Carbon::setTestNow('2026-10-23 10:40:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 600, 'screen_idle_seconds' => 1800])->assertOk();
        // "Are you sitting idle?" -> "No, I am working" for 25 of those minutes.
        $this->postJson('/api/attendance/away', ['from' => '2026-10-23 10:10:00', 'to' => '2026-10-23 10:35:00', 'reason' => 'idle', 'as' => 'work'])->assertOk()
            ->assertJsonPath('data.screen_meeting_seconds', 1500)->assertJsonPath('data.screen_idle_seconds', 300);

        $session = AttendanceSession::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame(['active' => 600, 'meeting' => 1500, 'idle' => 300, 'corrected' => 0, 'untracked' => 0], $session->screenTime());
        $this->assertSame(300, $session->idleSeconds(), 'The Idle column matches the screen time');
        $this->getJson('/api/attendance/today')->assertJsonPath('data.screen_idle_seconds', 300)->assertJsonPath('data.idle_counted_seconds', 300);

        // Admin list and employee page show the same figures.
        Livewire::actingAs($admin)->test(\App\Livewire\Attendance\Dashboard::class)->assertSeeText('00:25:00 in meetings')->assertSeeText('00:05:00 idle')->assertDontSee('00:30:00 idle');
        Livewire::actingAs($user)->test(\App\Livewire\Attendance\MyAttendance::class)->assertSee('00:25:00');
        $row = app(ReportService::class)->rows($admin, 'attendance', ['from' => '2026-10-23', 'to' => '2026-10-23', 'employee_id' => $e->id])->first();
        $this->assertSame(['00:25:00', '00:05:00'], [$row['screen_meeting_time'], $row['screen_idle_time']]);

        // The app was closed for 6 minutes (e.g. while being updated): shown as "not tracked", counted as worked,
        // so active + meetings + idle + not tracked = in-out less break, on every page.
        Carbon::setTestNow('2026-10-23 10:49:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 180])->assertOk()->assertJsonPath('data.screen_untracked_seconds', 360);
        Livewire::actingAs($admin)->test(\App\Livewire\Attendance\Dashboard::class)->assertSeeText('00:06:00 not tracked');
        Livewire::actingAs($user)->test(\App\Livewire\Attendance\MyAttendance::class)->assertSeeText('not tracked');

        // One moment, one set of figures: phone/desktop API, history, admin list and employee page.
        // In-out 49:00 - break 0 - idle 05:00 = worked 44:00.
        $today = $this->getJson('/api/attendance/today')->assertOk()
            ->assertJsonPath('data.worked_seconds', 2640)->assertJsonPath('data.idle_counted_seconds', 300)
            ->assertJsonPath('data.screen_active_seconds', 780)->assertJsonPath('data.screen_meeting_seconds', 1500)->assertJsonPath('data.screen_idle_seconds', 300);
        $this->getJson('/api/attendance/history?from=2026-10-23&to=2026-10-23')->assertOk()
            ->assertJsonPath('data.0.session.worked_seconds', 2640)->assertJsonPath('data.0.session.idle_counted_seconds', 300);
        Livewire::actingAs($admin)->test(\App\Livewire\Attendance\Dashboard::class)
            ->assertSee('00:44:00')->assertSeeText('00:13:00 in use')->assertSeeText('00:25:00 in meetings')->assertSeeText('00:05:00 idle')->assertSee('swsLive', false);
        Livewire::actingAs($user)->test(\App\Livewire\Attendance\MyAttendance::class)
            ->assertSee('00:44:00')->assertSee('00:05:00')->assertSee('00:25:00');
    }
    public function test_app_that_ran_offline_is_not_a_break_but_an_app_that_was_off_is(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-23 10:00:00');
        $user = User::factory()->create(['email' => 'mt-4@example.com']);
        $user->assignRole('Employee');
        $e = AttendanceEmployee::create(['employee_code' => 'MT-4', 'name' => 'Person MT-4', 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01']);
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-23', 'check_in' => '2026-10-23 10:00:00', 'status' => 'working', 'last_sync_at' => now()]);
        Sanctum::actingAs($user, ['*']);
        Carbon::setTestNow('2026-10-23 10:05:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 300])->assertOk();

        // The app kept running but could not reach the server for 49 minutes, then sends what it measured.
        Carbon::setTestNow('2026-10-23 10:54:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 2700, 'screen_idle_seconds' => 240])->assertOk()
            ->assertJsonPath('data.break_seconds', 0)->assertJsonPath('data.screen_active_seconds', 3000);

        // The computer was really off for 30 minutes: the app reports nothing for that time -> a break.
        Carbon::setTestNow('2026-10-23 11:24:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 20])->assertOk()->assertJsonPath('data.break_seconds', 1800);
    }
    public function test_live_figures_count_the_reported_state_the_same_everywhere(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-23 10:00:00');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['email' => 'mt-5@example.com']);
        $user->assignRole('Employee');
        $e = AttendanceEmployee::create(['employee_code' => 'MT-5', 'name' => 'Person MT-5', 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01']);
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-23', 'check_in' => '2026-10-23 10:00:00', 'status' => 'working', 'last_sync_at' => now()]);
        Sanctum::actingAs($user, ['*']);

        // 10:30 report: 25 min in use, 5 min idle, and the computer is idle now.
        Carbon::setTestNow('2026-10-23 10:30:00');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 1500, 'screen_idle_seconds' => 300, 'screen_state' => 'idle'])->assertOk();

        // 20 s later the idle state keeps counting: idle 05:20, and worked stands still at 25:00 (30:20 - 05:20).
        Carbon::setTestNow('2026-10-23 10:30:20');
        $this->getJson('/api/attendance/today')->assertOk()
            ->assertJsonPath('data.idle_counted_seconds', 320)->assertJsonPath('data.worked_seconds', 1500)->assertJsonPath('data.screen_idle_seconds', 320)
            ->assertJsonPath('data.live.state', 'idle')->assertJsonPath('data.live.tick_for', 40)->assertJsonPath('data.live.in_out_seconds', 1820);
        Livewire::actingAs($admin)->test(\App\Livewire\Attendance\Dashboard::class)->assertSee('swsLive', false)->assertSee('00:25:00')->assertSee('00:05:20');
        Livewire::actingAs($user)->test(\App\Livewire\Attendance\MyAttendance::class)->assertSee('00:25:00')->assertSee('00:05:20')->assertSee('Screen time')->assertSeeText('00:25:00 in use');

        // More than a minute without a report: the state is no longer assumed, worked counts on.
        Carbon::setTestNow('2026-10-23 10:32:00');
        $this->getJson('/api/attendance/today')->assertJsonPath('data.live.state', null)->assertJsonPath('data.idle_counted_seconds', 300);
    }
}
