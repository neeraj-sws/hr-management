<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finished days keep their worked / idle / break figures on screen: every row that uses the
 * per-second counter (x-text="hms(v...)") must carry its own x-data, or the browser blanks the numbers.
 */
class FinishedDayFiguresVisibleTest extends TestCase
{
    use RefreshDatabase;

    public function test_finished_rows_carry_their_figures_for_the_counter(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee = AttendanceEmployee::create(['employee_code' => 'FD-1', 'name' => 'Finished Day', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01']);
        $day = today()->subDay()->toDateString();
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => $day, 'check_in' => "$day 09:00:00", 'check_out' => "$day 18:00:00", 'break_seconds' => 1800, 'active_seconds' => 8 * 3600 + 1800, 'status' => 'completed']);

        foreach ([[$admin, route('attendance.records', ['from' => $day, 'to' => $day])], [$user, route('attendance.my')]] as [$who, $url]) {
            $html = $this->actingAs($who)->get($url)->assertOk()->getContent();
            preg_match_all('/<tr\b[^>]*>(?:(?!<\/tr>).)*x-text="[^"]*hms\(v\./s', $html, $rows);
            $this->assertNotEmpty($rows[0], $url);
            foreach ($rows[0] as $row) $this->assertStringContainsString('x-data="swsLive(', $row, "Row without its own figures on $url");
        }
    }
}
