<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Company > Instructions (website) and More > Instructions (app): the rules, with today's settings. */
class InstructionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_everyone_sees_the_instructions_with_current_settings(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('attendance.rules', 'attendance', 'Attendance rules', ['grace_minutes' => 16, 'half_day_hours' => 4.5], null, $admin->id, '2026-01-01');
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['late_checkin_after' => '12:00'], null, $admin->id, '2026-01-01');
        $user = User::factory()->create(['email' => 'reader@example.com']);
        $user->assignRole('Employee');
        AttendanceEmployee::create(['employee_code' => 'IN-1', 'name' => 'Ira Reader', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'daily_work_hours' => 8.5, 'work_start' => '10:00', 'work_end' => '19:30']);

        $this->actingAs($user)->get(route('company.instructions'))->assertOk()
            ->assertSee('Instructions')->assertSee('10:00 AM to 7:30 PM')->assertSee('+ 16 min grace')
            ->assertSee('Less than 4 h 30 min at work in a day = Half day')->assertSee('Checking in after 12:00 PM')
            ->assertSee('does NOT reduce your salary')->assertSee('Why the salary is less than full')->assertSee('Check Spam');
        $this->actingAs($user)->get(route('dashboard'))->assertSee(route('company.instructions', [], false));

        Sanctum::actingAs($user);
        $data = $this->getJson('/api/instructions')->assertOk()->json('data');
        $this->assertSame(['attendance', 'breaks', 'half-day', 'leave', 'salary', 'work', 'emails', 'apps', 'corrections', 'security'], array_column($data, 'key'));
        $this->assertSame('Half day', $data[2]['items'][1]['title']);
    }
}
