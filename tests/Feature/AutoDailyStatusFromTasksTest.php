<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\DailyWorkStatus;
use App\Models\EmployeePenalty;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Notifications\DailyStatusSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Forgot the daily status but worked on tasks (added in the desktop app, timer run): at the evening run a status is
 * written from the day's tasks (status, progress, time), the end-of-day mail goes out with it, and no "not
 * submitted" fine stays for that day.
 */
class AutoDailyStatusFromTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-29 20:30:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['name' => 'Abheeshri Parmar']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EMP-11', 'name' => 'Abheeshri Parmar', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:30:00']);
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-29', 'check_in' => '2026-10-29 10:05:00', 'check_out' => '2026-10-29 19:00:00', 'status' => 'completed']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function task(string $title, string $status, int $minutes, ?int $progress = null): Task
    {
        $task = Task::create(['title' => $title, 'assignee_id' => $this->employee->id, 'created_by' => $this->employee->user_id, 'status' => $status, 'priority' => 'medium', 'work_date' => '2026-10-29', 'progress' => $progress]);
        TaskTimeEntry::create(['task_id' => $task->id, 'employee_id' => $this->employee->id, 'started_at' => '2026-10-29 11:00:00', 'ended_at' => Carbon::parse('2026-10-29 11:00:00')->addMinutes($minutes), 'duration_seconds' => $minutes * 60, 'status' => 'stopped']);

        return $task;
    }

    public function test_forgotten_status_is_written_from_the_tasks_and_mailed(): void
    {
        Notification::fake();
        $this->task('Search filter fix', 'completed', 120);
        $this->task('Category page slider', 'in_progress', 90, 60);

        // 8:30 PM: still before her deadline (7:30 PM shift end + 3 h grace = 10:30 PM) - nothing written yet.
        app(\App\Services\PeopleOperationsService::class)->setPolicy('daily_status.missing', 'compliance', 'Daily status', ['amount_per_day' => 500, 'grace_hours' => 3], null, $this->admin->id, '2026-01-01');
        $this->artisan('work:daily-status-summary')->assertSuccessful();
        $this->artisan('work:daily-status-deadline')->assertSuccessful();
        $this->assertSame(0, DailyWorkStatus::count());
        $this->assertSame('2026-10-29 22:30', app(\App\Services\PeopleOperationsService::class)->statusDeadline($this->employee, '2026-10-29')->format('Y-m-d H:i'));

        // 10:40 PM: past the deadline - written from the tasks and mailed once.
        Carbon::setTestNow('2026-10-29 22:40:00');
        $this->artisan('work:daily-status-deadline')->assertSuccessful();
        $this->artisan('work:daily-status-deadline')->assertSuccessful();
        Notification::assertSentToTimes($this->employee->user, DailyStatusSummary::class, 1);

        $status = DailyWorkStatus::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertTrue($status->auto_from_tasks);
        $this->assertStringContainsString('Completed: Search filter fix.', $status->summary);
        $this->assertStringContainsString('Category page slider (In progress, 60%)', $status->summary);
        $this->assertSame('Category page slider', $status->pending);
        $this->assertSame(7200, $status->project_updates[0]['tasks'][0]['seconds']);
        Notification::assertSentTo($this->employee->user, DailyStatusSummary::class);

        // The night's fine check: a status exists (written from the tasks) - no "not submitted" fine.
        Carbon::setTestNow('2026-10-30 03:00:00');
        $this->artisan('compliance:assess')->assertSuccessful();
        $this->assertSame(0, EmployeePenalty::where('employee_id', $this->employee->id)->where('source_key', 'like', 'no_status:%')->whereIn('status', ['pending', 'approved'])->count());
    }

    public function test_a_fine_already_made_is_removed_and_own_status_is_never_replaced(): void
    {
        $fine = EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-29', 'amount' => 200, 'reason' => 'No daily status for 29 Oct', 'requested_by' => $this->admin->id, 'status' => 'approved', 'source_key' => 'no_status:'.$this->employee->id.':2026-10-29']);
        $this->task('Search filter fix', 'completed', 60);
        app(\App\Services\WorkService::class)->autoStatusFromTasks($this->employee, '2026-10-29');
        $this->assertSame('waived', $fine->fresh()->status);

        // Written by the person: left alone; editing the automatic one makes it their own.
        $this->assertNull(app(\App\Services\WorkService::class)->autoStatusFromTasks($this->employee, '2026-10-29'));
        $status = DailyWorkStatus::first();
        $status->update(['summary' => 'Fixed the search filter and tested it']);
        $this->assertFalse($status->fresh()->auto_from_tasks);
    }

    public function test_no_tasks_means_nothing_is_written(): void
    {
        $this->assertNull(app(\App\Services\WorkService::class)->autoStatusFromTasks($this->employee, '2026-10-29'));
        $this->assertSame(0, DailyWorkStatus::count());
    }
}
