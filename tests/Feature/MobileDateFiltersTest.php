<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Mobile app: "pick a date" on Tasks and Leave, all pending leave, and the desktop idle setting. */
class MobileDateFiltersTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-23 12:00:00');
        $user = User::factory()->create(['email' => 'df-1@example.com']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'DF-1', 'name' => 'Date Filter', 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2025-01-01']);
        Sanctum::actingAs($user, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tasks_of_one_day_with_the_time_logged_that_day(): void
    {
        $timed = Task::create(['title' => 'Report draft', 'assignee_id' => $this->employee->id, 'status' => 'completed', 'priority' => 'normal', 'completed_at' => '2026-02-12 18:00:00']);
        TaskTimeEntry::create(['task_id' => $timed->id, 'employee_id' => $this->employee->id, 'started_at' => '2026-02-10 10:00:00', 'ended_at' => '2026-02-10 11:30:00', 'duration_seconds' => 5400, 'status' => 'stopped']);
        TaskTimeEntry::create(['task_id' => $timed->id, 'employee_id' => $this->employee->id, 'started_at' => '2026-02-11 10:00:00', 'ended_at' => '2026-02-11 11:00:00', 'duration_seconds' => 3600, 'status' => 'stopped']);
        Task::create(['title' => 'Planned for that day', 'assignee_id' => $this->employee->id, 'status' => 'todo', 'priority' => 'normal', 'work_date' => '2026-02-10']);
        Task::create(['title' => 'Another day', 'assignee_id' => $this->employee->id, 'status' => 'todo', 'priority' => 'normal', 'work_date' => '2026-02-15']);

        $response = $this->getJson('/api/tasks?date=2026-02-10')->assertOk()->assertJsonCount(2, 'data');
        $byTitle = collect($response->json('data'))->keyBy('title');
        $this->assertSame(5400, $byTitle['Report draft']['day_seconds'], 'Only the time logged on 10 Feb');
        $this->assertSame(0, $byTitle['Planned for that day']['day_seconds']);
        $this->getJson('/api/tasks?date=2026-02-12')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Report draft');
    }

    public function test_leave_of_one_day_and_all_pending_whatever_the_month(): void
    {
        $type = LeaveType::where('is_active', true)->firstOrFail();
        $base = ['employee_id' => $this->employee->id, 'leave_type_id' => $type->id, 'days' => 1, 'reason' => 'x'];
        LeaveRequest::create([...$base, 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'status' => 'pending']);
        LeaveRequest::create([...$base, 'starts_on' => '2026-03-04', 'ends_on' => '2026-03-06', 'days' => 3, 'status' => 'approved']);

        $this->getJson('/api/leave/requests?month=2026-10')->assertJsonCount(0, 'data');
        $this->getJson('/api/leave/requests?status=pending')->assertJsonCount(1, 'data')->assertJsonPath('data.0.starts_on', '2026-11-20');
        $this->getJson('/api/leave/requests?month=2026-11')->assertJsonCount(1, 'data');
        $this->getJson('/api/leave/requests?date=2026-03-05')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'approved');
        $this->getJson('/api/leave/requests?date=2026-03-07')->assertJsonCount(0, 'data');
    }

    public function test_desktop_gets_the_idle_limit_from_settings(): void
    {
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.policies.idle_after_minutes', 5)->assertJsonPath('data.policies.away_prompt_minutes', 10);
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\System\Settings::class)
            ->set('idleAfterMinutes', '10')->call('saveHours')->assertHasNoErrors();
        $this->getJson('/api/me')->assertJsonPath('data.policies.idle_after_minutes', 10)->assertJsonPath('data.policies.away_prompt_minutes', 10);
    }
}
