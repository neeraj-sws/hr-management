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
}
