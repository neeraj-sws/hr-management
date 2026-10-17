<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Mobile/desktop API contract: 401 unauthenticated, 403 forbidden, 422 invalid, 2xx success. */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $managerUser;
    private User $employeeUser;
    private AttendanceEmployee $manager;
    private AttendanceEmployee $employee;
    private AttendanceEmployee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-07 09:00:00'); // a Wednesday
        $this->managerUser = User::factory()->create(['email' => 'api.manager@example.com', 'password' => 'ManagerPass123!']);
        $this->managerUser->assignRole(['Employee', 'Manager']);
        $this->manager = AttendanceEmployee::create(['employee_code' => 'API-MGR', 'name' => 'Api Manager', 'is_active' => true, 'user_id' => $this->managerUser->id]);
        $this->employeeUser = User::factory()->create(['email' => 'api.employee@example.com', 'password' => 'EmployeePass123!']);
        $this->employeeUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'API-EMP', 'name' => 'Api Employee', 'is_active' => true, 'user_id' => $this->employeeUser->id, 'manager_id' => $this->manager->id, 'work_start' => '09:30:00']);
        $this->outsider = AttendanceEmployee::create(['employee_code' => 'API-OUT', 'name' => 'Outside Person', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Switch the bearer token; Sanctum caches the resolved user per application instance. */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function token(string $email, string $password): string
    {
        return $this->postJson('/api/auth/token', ['email' => $email, 'password' => $password, 'device_name' => 'phpunit'])->assertOk()->json('token');
    }

    public function test_authentication_status_codes(): void
    {
        foreach (['/api/me', '/api/tasks', '/api/leave/balances', '/api/approvals', '/api/payslips', '/api/notifications'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/auth/token', ['email' => 'api.employee@example.com', 'password' => 'wrong-password', 'device_name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/auth/token', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'device_name']);
        $token = $this->token('api.employee@example.com', 'EmployeePass123!');
        $this->as($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.employee.employee_code', 'API-EMP')->assertJsonPath('data.roles.0', 'Employee');
        $this->as($token)->postJson('/api/auth/revoke')->assertOk();
        $this->as($token)->getJson('/api/me')->assertUnauthorized();

        $this->employeeUser->update(['is_active' => false]);
        $this->postJson('/api/auth/token', ['email' => 'api.employee@example.com', 'password' => 'EmployeePass123!', 'device_name' => 'x'])->assertUnprocessable();
    }

    public function test_attendance_actions_and_history(): void
    {
        $token = $this->token('api.employee@example.com', 'EmployeePass123!');
        $this->as($token)->getJson('/api/attendance/today')->assertOk()->assertJsonPath('data', null);
        $this->as($token)->postJson('/api/attendance/break/start')->assertUnprocessable()->assertJsonValidationErrors('attendance');
        $this->as($token)->postJson('/api/attendance/check-in', ['source' => 'mobile'])->assertCreated()->assertJsonPath('data.status', 'working');
        $this->as($token)->postJson('/api/attendance/check-in')->assertUnprocessable();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->as($token)->postJson('/api/attendance/break/start')->assertOk()->assertJsonPath('data.status', 'on_break');
        Carbon::setTestNow('2026-10-07 12:30:00');
        $this->as($token)->postJson('/api/attendance/break/end')->assertOk()->assertJsonPath('data.break_seconds', 1800);
        $this->as($token)->postJson('/api/attendance/heartbeat')->assertOk();
        Carbon::setTestNow('2026-10-07 18:00:00');
        $this->as($token)->postJson('/api/attendance/check-out')->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.active_seconds', 8 * 3600 + 1800);
        $this->as($token)->getJson('/api/attendance/history?from=2026-10-05&to=2026-10-07')->assertOk()
            ->assertJsonPath('data.2.date', '2026-10-07')->assertJsonPath('data.2.status', 'present')->assertJsonPath('data.0.status', 'absent');
        $this->as($token)->getJson('/api/attendance/history?from=2026-01-01&to=2026-10-07')->assertUnprocessable();
    }

    public function test_tasks_scope_and_timer(): void
    {
        $managerToken = $this->token('api.manager@example.com', 'ManagerPass123!');
        $employeeToken = $this->token('api.employee@example.com', 'EmployeePass123!');
        $payload = ['title' => 'API task', 'assignee_id' => $this->employee->id, 'priority' => 'high', 'estimated_hours' => 3, 'due_on' => '2026-10-09'];
        $this->as($employeeToken)->postJson('/api/tasks', [...$payload, 'assignee_id' => $this->manager->id])->assertForbidden(); // not for someone else
        $this->as($employeeToken)->postJson('/api/tasks', [...$payload, 'title' => 'My own task'])->assertCreated()->assertJsonPath('data.assignee.id', $this->employee->id);
        // The desktop app (up to 1.9) sends "medium" for the normal priority.
        $this->as($employeeToken)->postJson('/api/tasks', ['title' => 'From the desktop app', 'priority' => 'medium'])->assertCreated()->assertJsonPath('data.priority', 'normal');
        $this->as($employeeToken)->postJson('/api/tasks', ['title' => 'Bad priority', 'priority' => 'whenever'])->assertUnprocessable()->assertJsonValidationErrors(['priority']);
        Task::whereIn('title', ['My own task', 'From the desktop app'])->delete(); // keep the counts below about assigned work
        $this->as($managerToken)->postJson('/api/tasks', ['assignee_id' => $this->employee->id])->assertUnprocessable()->assertJsonValidationErrors(['title', 'priority']);
        $this->as($managerToken)->postJson('/api/tasks', [...$payload, 'assignee_id' => $this->outsider->id])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $taskId = $this->as($managerToken)->postJson('/api/tasks', $payload)->assertCreated()->json('data.id');
        $foreign = Task::create(['title' => 'Not yours', 'assignee_id' => $this->outsider->id, 'status' => 'todo', 'priority' => 'normal']);

        $this->as($employeeToken)->getJson('/api/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'API task');
        $this->as($employeeToken)->getJson('/api/tasks?scope=team')->assertForbidden();
        $this->as($managerToken)->getJson('/api/tasks?scope=team')->assertOk()->assertJsonCount(1, 'data');
        $this->as($employeeToken)->postJson("/api/tasks/{$foreign->id}/start")->assertUnprocessable();
        $this->as($employeeToken)->postJson("/api/tasks/{$taskId}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->as($employeeToken)->postJson("/api/tasks/{$taskId}/start")->assertUnprocessable()->assertJsonValidationErrors('timer');
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->as($employeeToken)->postJson("/api/tasks/{$taskId}/pause")->assertOk()->assertJsonPath('data.logged_seconds', 3600);
        $this->as($employeeToken)->postJson("/api/tasks/{$taskId}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->as($managerToken)->patchJson("/api/tasks/{$foreign->id}", [...$payload, 'title' => 'Hijack'])->assertUnprocessable();
    }

    public function test_leave_overtime_and_approvals(): void
    {
        $managerToken = $this->token('api.manager@example.com', 'ManagerPass123!');
        $employeeToken = $this->token('api.employee@example.com', 'EmployeePass123!');
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $this->as($employeeToken)->getJson('/api/leave/types')->assertOk()->assertJsonFragment(['name' => 'Annual Leave']);
        $this->as($employeeToken)->getJson('/api/leave/balances')->assertOk()->assertJsonPath('data.0.leave_type.name', 'Annual Leave');
        $this->as($employeeToken)->postJson('/api/leave/requests', ['leave_type_id' => $annual->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-19', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $leaveId = $this->as($employeeToken)->postJson('/api/leave/requests', ['leave_type_id' => $annual->id, 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-20', 'reason' => 'Family'])->assertCreated()->assertJsonPath('data.days', 2)->json('data.id');
        $this->as($employeeToken)->postJson('/api/leave/requests', ['leave_type_id' => $annual->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'reason' => 'Overlap'])->assertUnprocessable();
        $this->as($employeeToken)->postJson('/api/overtime', ['work_date' => '2026-10-06', 'minutes' => 90, 'reason' => 'Release'])->assertCreated();
        $this->as($employeeToken)->postJson('/api/overtime', ['work_date' => '2026-10-06', 'minutes' => 30, 'reason' => 'Again'])->assertUnprocessable();
        $this->as($employeeToken)->postJson('/api/overtime', ['work_date' => '2026-12-01', 'minutes' => 30, 'reason' => 'Future'])->assertUnprocessable();

        $this->as($employeeToken)->getJson('/api/approvals')->assertOk()->assertJsonCount(0, 'data');
        $approvals = $this->as($managerToken)->getJson('/api/approvals')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $leaveApproval = collect($approvals)->firstWhere('type', 'LeaveRequest');
        $otApproval = collect($approvals)->firstWhere('type', 'OvertimeRequest');
        $this->as($employeeToken)->postJson("/api/approvals/{$leaveApproval['id']}/decision", ['approve' => true])->assertForbidden();
        $this->as($managerToken)->postJson("/api/approvals/{$otApproval['id']}/decision", ['approve' => false])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->as($managerToken)->postJson("/api/approvals/{$leaveApproval['id']}/decision", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($managerToken)->postJson("/api/approvals/{$leaveApproval['id']}/decision", ['approve' => true])->assertStatus(409); // already decided
        $this->as($managerToken)->postJson("/api/approvals/{$otApproval['id']}/decision", ['approve' => false, 'note' => 'Not planned'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->as($employeeToken)->getJson('/api/leave/requests')->assertOk()->assertJsonPath('data.0.status', 'approved');
        $this->as($employeeToken)->postJson("/api/leave/requests/{$leaveId}/cancel")->assertUnprocessable();

        $this->as($employeeToken)->getJson('/api/notifications')->assertOk()->assertJsonPath('total', 2);
        $this->as($employeeToken)->postJson('/api/notifications/read-all')->assertOk();
        $this->as($employeeToken)->getJson('/api/notifications?unread=1')->assertOk()->assertJsonPath('total', 0);
        $this->as($employeeToken)->getJson('/api/payslips')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_device_tokens_cannot_use_user_endpoints(): void
    {
        $deviceToken = $this->employee->createToken('device', ['attendance:sync'])->plainTextToken;
        $this->as($deviceToken)->getJson('/api/me')->assertForbidden();
        $this->as($deviceToken)->postJson('/api/attendance/check-in')->assertForbidden();
    }

    public function test_offline_timestamps_in_utc_are_stored_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);
        date_default_timezone_set('Asia/Kolkata');
        Carbon::setTestNow(Carbon::parse('2026-10-07 11:00:00', 'Asia/Kolkata'));
        try {
            $token = $this->token('api.employee@example.com', 'EmployeePass123!');
            $action = ['action_id' => 'tz-0001', 'type' => 'attendance.check_in', 'payload' => ['occurred_at' => '2026-10-07T04:00:00Z']]; // 09:30 IST
            $this->as($token)->postJson('/api/sync/actions', ['actions' => [$action]])->assertOk();
            $this->assertSame('2026-10-07 09:30:00', AttendanceSession::firstOrFail()->check_in->toDateTimeString());
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function test_offline_attendance_actions_replay_without_duplicates(): void
    {
        $token = $this->token('api.employee@example.com', 'EmployeePass123!');
        $checkIn = ['action_id' => 'att-0001', 'type' => 'attendance.check_in', 'payload' => ['occurred_at' => '2026-10-07T08:55:00+00:00']];
        $this->as($token)->postJson('/api/sync/actions', ['actions' => [$checkIn]])->assertOk()->assertJsonPath('results.0.replayed', false);
        $this->as($token)->postJson('/api/sync/actions', ['actions' => [$checkIn]])->assertOk()->assertJsonPath('results.0.replayed', true);
        $this->assertSame(1, AttendanceSession::where('employee_id', $this->employee->id)->count());
        $this->assertSame('2026-10-07 08:55:00', AttendanceSession::first()->check_in->toDateTimeString());
        $reused = $checkIn;
        $reused['payload']['occurred_at'] = '2026-10-07T08:56:00+00:00';
        $this->as($token)->postJson('/api/sync/actions', ['actions' => [$reused]])->assertStatus(207)->assertJsonPath('results.0.conflict', true);
        $second = ['action_id' => 'att-0002', 'type' => 'attendance.check_in', 'payload' => ['occurred_at' => '2026-10-07T08:57:00+00:00']];
        $this->as($token)->postJson('/api/sync/actions', ['actions' => [$second]])->assertOk()->assertJsonPath('results.0.ignored', true); // duplicate check-in is ignored, not an error
        $this->assertSame(1, AttendanceSession::count());
        $old = ['action_id' => 'att-old', 'type' => 'attendance.check_out', 'payload' => ['occurred_at' => '2026-10-01T18:00:00+00:00']];
        $this->as($token)->postJson('/api/sync/actions', ['actions' => [$old]])->assertUnprocessable();
        $checkOut = ['action_id' => 'att-0003', 'type' => 'attendance.check_out', 'payload' => ['occurred_at' => '2026-10-07T08:59:00+00:00']];
        $this->as($token)->postJson('/api/sync/actions', ['actions' => [$checkOut]])->assertOk()->assertJsonPath('results.0.session_status', 'completed');
        $this->assertSame(240, (int) AttendanceSession::first()->active_seconds);
    }
}
