<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Checked in on the mobile app and forgot to check out: the day is closed at the shift end, not at the check-in. */
class MobileForgottenCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $attendance;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-07 09:00:00');
        $user = User::factory()->create(['email' => 'mob@example.com', 'name' => 'Mobile Person']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'MOB', 'name' => 'Mobile Person', 'is_active' => true, 'user_id' => $user->id, 'work_start' => '09:30:00', 'work_end' => '18:30:00', 'joined_at' => '2026-01-01']);
        $this->attendance = app(AttendanceService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_forgotten_mobile_check_out_ends_at_the_shift_end(): void
    {
        Carbon::setTestNow('2026-10-07 09:30:00');
        $this->attendance->checkIn($this->employee, 'mobile');
        Carbon::setTestNow('2026-10-08 09:30:00');
        $this->assertSame(1, $this->attendance->closeStaleSessions());

        $session = AttendanceSession::sole();
        $this->assertTrue($session->closed_automatically);
        $this->assertSame('2026-10-07 18:30:00', $session->check_out->toDateTimeString(), 'Shift end, not the check-in time');
        $this->assertSame(9 * 3600, (int) $session->active_seconds);
        $this->assertTrue($this->employee->user->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', '06:30 PM (your shift end)')));
    }

    public function test_activity_after_the_shift_end_is_kept(): void
    {
        Carbon::setTestNow('2026-10-07 09:30:00');
        $this->attendance->checkIn($this->employee, 'mobile');
        Carbon::setTestNow('2026-10-07 19:00:00');
        $this->attendance->startBreak($this->employee, 'mobile');
        Carbon::setTestNow('2026-10-07 19:10:00');
        $this->attendance->endBreak($this->employee, 'mobile');
        Carbon::setTestNow('2026-10-08 09:30:00');
        $this->attendance->closeStaleSessions();

        $this->assertSame('2026-10-07 19:10:00', AttendanceSession::sole()->check_out->toDateTimeString(), 'Last activity was later than the shift end');
    }

    public function test_website_check_in_still_ends_at_the_last_activity(): void
    {
        Carbon::setTestNow('2026-10-07 09:30:00');
        $this->attendance->checkIn($this->employee, 'web');
        Carbon::setTestNow('2026-10-08 09:30:00');
        $this->attendance->closeStaleSessions();

        $this->assertSame('2026-10-07 09:30:00', AttendanceSession::sole()->check_out->toDateTimeString());
    }
}
