<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** One laptop, one person a day: a second employee cannot check in on a computer someone else already used today. */
class OneComputerOnePersonTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $code, string $name): array
    {
        $user = User::create(['name' => $name, 'email' => strtolower($code).'@company.test', 'password' => bcrypt('secret-password-1'), 'is_active' => true]);
        $user->assignRole('Employee');
        $employee = AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'email' => $user->email, 'user_id' => $user->id, 'is_active' => true, 'joined_at' => '2026-01-01', 'daily_work_hours' => 8, 'work_start' => '10:00:00', 'work_end' => '18:00:00']);

        return [$user, $employee];
    }

    private function checkIn(User $user, string $computer, string $source = 'desktop')
    {
        Sanctum::actingAs($user, ['*']);

        return $this->postJson('/api/attendance/check-in', ['source' => $source], ['X-Computer-Name' => $computer]);
    }

    public function test_second_person_on_the_same_computer_is_refused(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-11-04 10:00:00');
        [$preetiUser, $preeti] = $this->person('EMP-19', 'Preeti Prajapati');
        [$koushikiUser, $koushiki] = $this->person('EMP-20', 'Koushiki Solanki');

        $this->checkIn($preetiUser, 'LAPTOP-PREETI')->assertCreated();

        // Koushiki signs in on Preeti's laptop and tries to check in.
        Carbon::setTestNow('2026-11-04 10:05:00');
        $this->checkIn($koushikiUser, 'LAPTOP-PREETI')->assertUnprocessable()
            ->assertJsonPath('errors.attendance.0', 'This computer (LAPTOP-PREETI) was already used today by Preeti Prajapati to check in. Each person checks in on their own computer. Ask HR to correct your attendance if this is a mistake.');
        $this->assertFalse(AttendanceSession::where('employee_id', $koushiki->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'attendance.shared_computer_blocked')->exists());

        // Her own laptop works.
        $this->checkIn($koushikiUser, 'LAPTOP-KOUSHIKI')->assertCreated();

        // The next day the laptop is free again.
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->checkIn($koushikiUser, 'LAPTOP-PREETI')->assertCreated();
        $this->assertSame(2, AttendanceSession::where('employee_id', $koushiki->id)->count());
        $this->assertSame(1, AttendanceSession::where('employee_id', $preeti->id)->count());
    }

    public function test_mobile_check_in_is_not_affected(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-11-04 10:00:00');
        [$a] = $this->person('EMP-31', 'First Person');
        [$b] = $this->person('EMP-32', 'Second Person');

        $this->checkIn($a, 'SHARED-PC')->assertCreated();
        $this->checkIn($b, 'SHARED-PC', 'mobile')->assertCreated();
    }
}
