<?php

namespace Tests\Feature;

use App\Livewire\Attendance\MyAttendance;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Actual working time = in–out less break less idle, the same on the employee's table, reports and payroll. */
class ActualWorkingTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AttendanceEmployee $employee;
    private AttendanceService $attendance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->user = User::factory()->create();
        $this->user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'AW-1', 'name' => 'Asha Worker', 'is_active' => true, 'user_id' => $this->user->id, 'joined_at' => '2026-01-01']);
        $this->attendance = app(AttendanceService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_day_table_shows_in_out_minus_break_minus_idle(): void
    {
        $this->attendance->checkIn($this->employee, 'desktop');
        Carbon::setTestNow('2026-10-14 13:00:00');
        $this->attendance->heartbeat($this->employee, 3 * 3600, 45 * 60);   // 45 min screen idle so far
        $this->attendance->startBreak($this->employee, 'desktop');
        Carbon::setTestNow('2026-10-14 13:30:00');
        $this->attendance->endBreak($this->employee, 'desktop');
        Carbon::setTestNow('2026-10-14 18:00:00');
        $session = $this->attendance->checkOut($this->employee, 'desktop');

        // 09:00–18:00 = 9 h, less 30 min break, less 45 min idle = 7 h 45 min.
        $this->assertSame(7 * 3600 + 45 * 60, $this->attendance->workedSeconds($session));
        $this->assertSame(7 * 3600 + 45 * 60, (int) $session->active_seconds);
        Livewire::actingAs($this->user)->test(MyAttendance::class)
            ->assertSee('In–out')->assertSee('Worked')
            ->assertSeeInOrder(['09:00 AM', '06:00 PM', '09:00:00', '− 00:30:00', '− 00:45:00', '07:45:00']);
    }

    public function test_answered_away_time_is_not_counted_as_idle_twice(): void
    {
        $this->attendance->checkIn($this->employee, 'desktop');
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->attendance->heartbeat($this->employee, 2 * 3600, 3600);   // 1 h idle: 10:00–10:20 and 11:00–11:40 away
        $this->actingAs($this->user, 'sanctum');
        // "Yes, a break" for 20 min, "No, I was in a meeting" for 40 min.
        $this->postJson('/api/attendance/away', ['from' => '2026-10-14 10:00:00', 'to' => '2026-10-14 10:20:00', 'reason' => 'idle'])->assertOk();
        $this->postJson('/api/attendance/away', ['from' => '2026-10-14 11:00:00', 'to' => '2026-10-14 11:40:00', 'reason' => 'locked', 'as' => 'work'])->assertOk()->assertJsonPath('data.break_seconds', 1200);

        $session = AttendanceSession::firstOrFail();
        $this->assertSame(0, $session->idleSeconds(), 'All the idle time was answered: 20 min break + 40 min working');
        $this->assertSame(1200, (int) $session->break_seconds);
        $this->assertSame(3 * 3600 - 1200, $this->attendance->workedSeconds($session), '09:00–12:00 less the 20 min break');
    }
}
