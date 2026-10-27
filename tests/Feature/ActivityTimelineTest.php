<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Records;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Support\ActivityTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Screen activity details: the day's timeline from state changes, breaks and "I was working" answers. */
class ActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_and_panel(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-26 20:00:00');
        $e = AttendanceEmployee::create(['employee_code' => 'AT-1', 'name' => 'Khushboo Verma', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $s = AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-26', 'check_in' => '2026-10-26 09:30:00', 'check_out' => '2026-10-26 18:00:00', 'status' => 'completed']);
        $ev = fn ($type, $time, $meta = [], $dur = 0) => $s->events()->create(['event_type' => $type, 'event_time' => "2026-10-26 {$time}", 'duration_seconds' => $dur, 'metadata' => $meta]);
        $ev('screen_state', '09:30:05', ['state' => 'active']);
        $ev('screen_state', '09:52:00', ['state' => 'meeting']);
        $ev('screen_state', '10:14:00', ['state' => 'active']);
        $ev('screen_state', '11:07:00', ['state' => 'idle']);
        $ev('screen_state', '11:12:00', ['state' => 'active']);
        $ev('break_start', '12:56:00');
        $ev('break_end', '13:07:00', [], 660);
        $ev('away_worked', '16:42:00', ['from' => '2026-10-26 16:32:00', 'note' => 'Client call on phone'], 600);

        $t = collect(ActivityTimeline::for($s->fresh()));
        $this->assertSame(['work', 'meeting', 'work', 'idle', 'work', 'break', 'work', 'away_work', 'work'], $t->pluck('kind')->all());
        $this->assertSame('09:52 AM', $t[1]['start']->format('h:i A'));
        $this->assertSame(22 * 60, $t[1]['seconds']);
        $this->assertSame('Client call on phone', $t[7]['note']);
        $this->assertSame('06:00 PM', $t->last()['end']->format('h:i A'));

        // A day without desktop data: one "no desktop app data" stretch, breaks still shown.
        $plain = AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-24', 'check_in' => '2026-10-24 10:00:00', 'check_out' => '2026-10-24 12:00:00', 'status' => 'completed']);
        $this->assertSame(['untracked'], collect(ActivityTimeline::for($plain))->pluck('kind')->all());

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        Livewire::actingAs($admin)->test(Records::class, ['from' => '2026-10-01', 'to' => '2026-10-26'])
            ->assertSee('View activity (7)')
            ->call('openDetail', $s->id, 'timeline')->assertSee('Screen activity details')->assertSee('In an online meeting')->assertSee('Working away: Client call on phone')
            ->set('detailTab', 'apps')->assertSee('No app usage recorded')
            ->set('detailTab', 'screenshots')->assertSee('No screenshots for this day')
            ->set('detailTab', 'details')->assertSee('Check-in')
            ->call('openDetail', $plain->id)->assertSee('24 Oct 2026');
    }
}
