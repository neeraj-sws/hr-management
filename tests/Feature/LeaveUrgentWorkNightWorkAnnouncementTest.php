<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EmployeePenalty;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\NightWorkRequest;
use App\Models\SalaryProfile;
use App\Models\Task;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\CompanyService;
use App\Services\HrWorkflowService;
use App\Services\NightWorkService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Urgent work on a leave day is paid by the hours with no late rules; an announcement can leave out departments /
 * teams; night work is asked for with its tasks, approved by the manager and paid by the hour.
 */
class LeaveUrgentWorkNightWorkAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $manager;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->manager = $this->person('MGR-9', 'Praveen Owner');
        $this->employee = $this->person('EMP-31', 'Riya Dev', $this->manager->id);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $code, string $name, ?int $managerId = null, ?int $departmentId = null): AttendanceEmployee
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'manager_id' => $managerId, 'department_id' => $departmentId, 'work_start' => '10:00:00', 'work_end' => '18:00:00', 'daily_work_hours' => 8]);
    }

    public function test_urgent_work_on_a_leave_day_is_paid_by_hours_with_no_late_fine(): void
    {
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['fixed_amount' => 500, 'grace_minutes' => 16, 'deduct_now' => 1, 'hours_cut' => 1], null, $this->admin->id, '2026-01-01');
        $type = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-10-28', 'ends_on' => '2026-10-28', 'days' => 1, 'duration' => 'full', 'reason' => 'Family function', 'status' => 'approved']);
        // On leave, but came in at 3 PM for 1.5 hours of urgent work.
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-28', 'check_in' => '2026-10-28 15:00:00', 'check_out' => '2026-10-28 16:30:00', 'active_seconds' => 5400, 'status' => 'completed']);

        $people->assessCompliance('2026-10-28', '2026-10-28', $this->admin->id);
        $this->assertSame(0, EmployeePenalty::where('employee_id', $this->employee->id)->count(), 'No late / early fine on a leave day');
        $this->assertTrue($people->urgentWorkOnLeave($this->employee, '2026-10-28'));

        $extra = app(HrWorkflowService::class)->payEstimate($this->employee, '2026-10-01', '2026-10-31')['breakdown']['extra_work'];
        $this->assertSame(5400, $extra['dates']['2026-10-28']['seconds']);
        $this->assertEqualsWithDelta(187.5, $extra['amount'], 0.01, '1.5 h x 125 an hour');
    }

    public function test_announcement_can_leave_out_a_department(): void
    {
        $itWeb = Department::create(['name' => 'IT Web Info', 'code' => 'ITW']);
        $outsider = $this->person('ITW-1', 'Web Info Staff', null, $itWeb->id);
        $announcement = app(CompanyService::class)->saveAnnouncement(['title' => 'Diwali party', 'body' => 'Friday 6 PM', 'audience' => 'all', 'excluded_department_ids' => [$itWeb->id]], $this->admin);

        $audience = app(CompanyService::class)->audienceUsers($announcement)->pluck('id');
        $this->assertTrue($audience->contains($this->employee->user_id));
        $this->assertFalse($audience->contains($outsider->user_id));
        $this->assertSame(0, Announcement::visibleTo($outsider)->count());
        $this->assertSame(1, Announcement::visibleTo($this->employee)->count());
        $this->assertSame('Everyone except IT Web Info', $announcement->fresh()->audienceLabel());
    }

    public function test_announcement_also_sent_on_telegram_to_the_audience(): void
    {
        config(['services.telegram' => ['enabled' => true, 'bot_token' => '123456:TEST', 'bot_username' => 'sws_hrms_bot', 'admin_chat_id' => '1001', 'webhook_secret' => 'x', 'queue_connection' => 'sync', 'link_minutes' => 10]]);
        \Illuminate\Support\Facades\Http::fake(['api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        \App\Models\TelegramLink::create(['user_id' => $this->employee->user_id, 'chat_id' => '555', 'linked_at' => now()]);
        $itWeb = Department::create(['name' => 'IT Web Info', 'code' => 'ITW']);
        $outsider = $this->person('ITW-2', 'Left Out', null, $itWeb->id);
        \App\Models\TelegramLink::create(['user_id' => $outsider->user_id, 'chat_id' => '777', 'linked_at' => now()]);

        \Livewire\Livewire::actingAs($this->admin)->test(\App\Livewire\Company\Announcements::class)->call('edit')
            ->set('title', 'Diwali party')->set('body', 'Friday 6 PM at the office terrace')->set('excludeDepartments', [(string) $itWeb->id])->set('sendTelegram', true)->call('save')->assertHasNoErrors();

        $sent = collect(\Illuminate\Support\Facades\Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), '/sendMessage'));
        $toEmployee = $sent->first(fn ($r) => (string) $r['chat_id'] === '555');
        $this->assertNotNull($toEmployee);
        $this->assertStringContainsString('ANNOUNCEMENT', $toEmployee['text']);
        $this->assertStringContainsString('Friday 6 PM at the office terrace', $toEmployee['text']);
        $this->assertNull($sent->first(fn ($r) => (string) $r['chat_id'] === '777'), 'Left-out department gets nothing');
    }

    public function test_night_work_is_asked_with_tasks_approved_by_the_manager_and_paid(): void
    {
        $task = Task::create(['title' => 'Live search bug', 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'status' => 'in_progress', 'priority' => 'high']);
        $nights = app(NightWorkService::class);
        $this->assertSame(210, NightWorkService::minutes('22:00', '01:30'));

        $request = $nights->request($this->employee, '2026-10-29', '22:00', '01:30', [$task->id], 'Fixed the live search bug for the client and uploaded it');
        $this->assertSame('pending', $request->status);
        $this->assertStringContainsString('Night work by Riya Dev needs review', $this->manager->user->notifications()->first()->data['message']);
        // Same night again: refused.
        $this->expectExceptionObject(\Illuminate\Validation\ValidationException::withMessages(['starts_at' => 'You already asked for night work at this time that day.']));
        try {
            $nights->request($this->employee, '2026-10-29', '23:00', '00:30', [], 'Second request for the same night');
        } finally {
            $approval = \App\Models\ApprovalRequest::where('approvable_type', NightWorkRequest::class)->firstOrFail();
            app(ApprovalInbox::class)->decide($this->manager->user, $approval, true);
            $this->assertSame('approved', $request->fresh()->status);
            $night = app(HrWorkflowService::class)->payEstimate($this->employee, '2026-10-01', '2026-10-31')['breakdown']['night_work'];
            $this->assertSame(210, $night['minutes']);
            $this->assertEqualsWithDelta(437.5, $night['amount'], 0.01, '3.5 h x 125 an hour');
            $this->assertStringContainsString('is approved and will be paid', $this->employee->user->notifications()->latest('created_at')->first()->data['message']);
        }
    }

    public function test_app_night_work_screen(): void
    {
        $task = Task::create(['title' => 'Live search bug', 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'status' => 'in_progress', 'priority' => 'high']);
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->getJson('/api/night-work')->assertOk()->assertJsonPath('tasks.0.title', 'Live search bug');
        $id = $this->postJson('/api/night-work', ['work_date' => '2026-10-29', 'starts_at' => '22:00', 'ends_at' => '23:30', 'task_ids' => [$task->id], 'work_done' => 'Fixed the live search bug'])->assertCreated()->assertJsonPath('data.minutes', 90)->json('data.id');
        $this->postJson("/api/night-work/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/night-work', ['work_date' => '2026-10-29', 'starts_at' => '22:00', 'ends_at' => '23:30', 'work_done' => 'short'])->assertStatus(422);
        $this->actingAs($this->employee->user)->get(route('leave.night-work'))->assertOk()->assertSee('Night work');
    }
}
