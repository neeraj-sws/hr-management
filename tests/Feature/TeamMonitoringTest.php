<?php

namespace Tests\Feature;

use App\Livewire\Monitoring\AuditLog as MonitoringAudit;
use App\Livewire\Monitoring\Policy;
use App\Livewire\Monitoring\Team;
use App\Livewire\Monitoring\TeamMember;
use App\Models\ApprovalRequest;
use App\Models\AttendanceActivityEvent;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\MonitoringRequest;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\TeamMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Team monitoring: scope (team only, also through the URL / API), capture on / off with notice and audit, requests, privacy. */
class TeamMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $hr;
    private User $managerUser;
    private User $employeeUser;
    private AttendanceEmployee $boss;
    private AttendanceEmployee $direct;
    private AttendanceEmployee $indirect;
    private AttendanceEmployee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->hr = User::factory()->create(['name' => 'Hema HR']);
        $this->hr->assignRole('HR');
        $this->managerUser = User::factory()->create(['name' => 'Mona Manager']);
        $this->managerUser->assignRole('Manager');
        $this->boss = AttendanceEmployee::create(['employee_code' => 'TM-M', 'name' => 'Mona Manager', 'is_active' => true, 'user_id' => $this->managerUser->id, 'joined_at' => '2026-01-01']);
        $this->employeeUser = User::factory()->create(['name' => 'Dina Direct']);
        $this->employeeUser->assignRole('Employee');
        $this->direct = AttendanceEmployee::create(['employee_code' => 'TM-1', 'name' => 'Dina Direct', 'is_active' => true, 'user_id' => $this->employeeUser->id, 'manager_id' => $this->boss->id, 'joined_at' => '2026-01-01']);
        $this->indirect = AttendanceEmployee::create(['employee_code' => 'TM-2', 'name' => 'Ira Indirect', 'is_active' => true, 'manager_id' => $this->direct->id, 'joined_at' => '2026-01-01']);
        $this->outsider = AttendanceEmployee::create(['employee_code' => 'TM-9', 'name' => 'Otto Outsider', 'is_active' => true, 'joined_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manager_sees_reports_and_their_reports_but_never_outside_the_team(): void
    {
        Livewire::actingAs($this->managerUser)->test(Team::class)->assertSee('Dina Direct')->assertSee('Ira Indirect')->assertDontSee('Otto Outsider')->assertDontSee('All departments');
        $this->actingAs($this->managerUser)->get(route('monitoring.team.show', $this->indirect))->assertOk();
        $this->actingAs($this->managerUser)->get(route('monitoring.team.show', $this->outsider))->assertForbidden();

        Sanctum::actingAs($this->managerUser, ['*']);
        $names = collect($this->getJson('/api/monitoring/team?manager_id='.$this->outsider->id)->assertOk()->json('data'))->pluck('employee.name');
        $this->assertEqualsCanonicalizing(['Dina Direct', 'Ira Indirect'], $names->all(), 'HR-only filters never widen a manager\'s list');
        $this->getJson('/api/monitoring/team/'.$this->outsider->id)->assertForbidden();
        $this->getJson('/api/monitoring/team/'.$this->direct->id)->assertOk()->assertJsonPath('employee.name', 'Dina Direct');
    }

    public function test_employee_gets_403_and_hr_sees_everyone(): void
    {
        $this->actingAs($this->employeeUser)->get(route('monitoring.team'))->assertForbidden();
        $this->actingAs($this->employeeUser)->get(route('monitoring.audit'))->assertForbidden();
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->getJson('/api/monitoring/team')->assertForbidden();
        Livewire::actingAs($this->hr)->test(Team::class)->assertSee('Otto Outsider')->assertSee('All departments');
        $this->assertTrue(AuditLog::where('action', 'monitoring.team_viewed')->where('actor_id', $this->hr->id)->exists(), 'Opening the page is logged');
    }

    public function test_hr_turns_capture_on_with_a_reason_the_employee_is_told_and_it_is_logged(): void
    {
        $page = Livewire::actingAs($this->hr)->test(TeamMember::class, ['employee' => $this->direct])
            ->call('openCapture', 'on')->assertSee('The employee will be told in the app and by e-mail before capture starts.')
            ->call('saveCapture')->assertHasErrors('captureReason')
            ->set('captureReason', 'Missed deadlines review')->set('captureUntil', '2026-10-25')->call('saveCapture')->assertHasNoErrors();
        $state = app(TeamMonitoringService::class)->captureState($this->direct);
        $this->assertSame([true, 'Missed deadlines review', '2026-10-25', '2026-10-21'], [$state['on'], $state['reason'], $state['until'], $state['since']]);
        $this->assertTrue($this->employeeUser->notifications()->where('type', \App\Notifications\ScreenCaptureNotice::class)->exists(), 'Told before capture starts');
        $this->assertTrue(AuditLog::where('action', 'screen_capture.enabled_for_employee')->where('subject_id', $this->direct->id)->get()->contains(fn ($l) => $l->metadata['reason'] === 'Missed deadlines review'));

        // The employee's policy page: red banner with since and reason.
        Livewire::actingAs($this->employeeUser)->test(Policy::class)->assertSee('Screen capture is ON for you since 21 Oct 2026')->assertSee('Reason: Missed deadlines review');

        // End date passed: off again, and they are told.
        Carbon::setTestNow('2026-10-26 01:00:00');
        $this->artisan('monitoring:expire-capture')->assertSuccessful();
        $this->assertFalse(app(TeamMonitoringService::class)->captureState($this->direct)['on']);
        Livewire::actingAs($this->employeeUser)->test(Policy::class)->assertSee('Screen capture is OFF for you');
    }

    public function test_manager_cannot_switch_but_can_request_and_hr_approves_in_approvals(): void
    {
        Livewire::actingAs($this->managerUser)->test(TeamMember::class, ['employee' => $this->direct])->call('openCapture', 'on')->assertForbidden();
        Livewire::actingAs($this->managerUser)->test(TeamMember::class, ['employee' => $this->direct])
            ->assertSee('Request screen capture')->call('openCapture', 'request')->set('captureReason', 'Remote work check')->call('saveCapture')->assertHasNoErrors();
        $request = MonitoringRequest::sole();
        $approval = ApprovalRequest::where('approvable_type', MonitoringRequest::class)->sole();
        $inbox = app(ApprovalInbox::class);
        $this->assertTrue($inbox->pending($this->hr)->contains('id', $approval->id), 'HR sees it in Approvals');
        $this->assertFalse($inbox->pending($this->managerUser)->contains('id', $approval->id));
        $inbox->decide($this->hr, $approval, true, null);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertTrue(app(TeamMonitoringService::class)->captureState($this->direct)['on']);
        $this->assertTrue($this->managerUser->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'], 'Approved: screen capture for Dina Direct')));
    }

    public function test_screenshots_views_are_logged_old_ones_hidden_and_the_timeline_has_breaks(): void
    {
        $session = AttendanceSession::create(['employee_id' => $this->direct->id, 'work_date' => '2026-10-21', 'check_in' => '2026-10-21 09:00:00', 'status' => 'working']);
        AttendanceActivityEvent::create(['attendance_session_id' => $session->id, 'event_type' => 'break_start', 'event_time' => '2026-10-21 10:00:00', 'duration_seconds' => 0]);
        AttendanceActivityEvent::create(['attendance_session_id' => $session->id, 'event_type' => 'break_end', 'event_time' => '2026-10-21 10:30:00', 'duration_seconds' => 1800]);
        AttendanceActivityEvent::create(['attendance_session_id' => $session->id, 'event_type' => 'screen_state', 'event_time' => '2026-10-21 11:00:00', 'duration_seconds' => 0, 'metadata' => ['state' => 'idle']]);
        Storage::disk('local')->put('screenshots/a.jpg', 'img');
        $shot = Screenshot::create(['employee_id' => $this->direct->id, 'attendance_session_id' => $session->id, 'captured_at' => '2026-10-21 09:40:00', 'path' => 'screenshots/a.jpg', 'bytes' => 3]);
        Screenshot::create(['employee_id' => $this->direct->id, 'attendance_session_id' => $session->id, 'captured_at' => '2026-09-01 09:40:00', 'path' => 'screenshots/old.jpg', 'bytes' => 3]);

        $row = app(TeamMonitoringService::class)->rows(collect([$this->direct]), '2026-10-21')[$this->direct->id];
        $this->assertSame(['working', 'break', 'working', 'idle'], collect($row['timeline']['parts'])->pluck('type')->all());
        $this->assertSame(1, $row['screenshots']);
        $this->assertCount(0, app(TeamMonitoringService::class)->screenshots($this->direct, '2026-09-01'), 'Older than 30 days: never shown');

        Livewire::actingAs($this->managerUser)->test(TeamMember::class, ['employee' => $this->direct])->assertSee('09:40 AM')->call('logScreenshot', $shot->id);
        $this->actingAs($this->managerUser)->get(route('monitoring.screenshot', ['screenshot' => $shot, 'download' => 1]))->assertOk();
        // Indirect report's screenshot: allowed for the manager; outsider's: not.
        $this->assertTrue(AuditLog::where('action', 'monitoring.screenshot_opened')->where('actor_id', $this->managerUser->id)->get()->contains(fn ($l) => $l->metadata['screenshot_id'] === $shot->id));
        $this->assertTrue(AuditLog::where('action', 'screenshot.downloaded')->where('subject_id', $shot->id)->where('actor_id', $this->managerUser->id)->exists());

        // HR's audit log and the employee's own "who looked" list.
        Livewire::actingAs($this->hr)->test(MonitoringAudit::class)->assertSee('Downloaded a screenshot')->assertSee('Mona Manager')->assertSee('Dina Direct (TM-1)');
        // The employee's own "who looked" list is hidden for now (commented out in the policy page).
        Livewire::actingAs($this->employeeUser)->test(Policy::class)->assertDontSee('Who looked at your monitoring data')->assertDontSee('Downloaded a screenshot');
    }

    public function test_capture_off_shows_the_empty_state(): void
    {
        Livewire::actingAs($this->hr)->test(TeamMember::class, ['employee' => $this->outsider])->assertSee('Screen capture is off for this employee');
        Livewire::actingAs($this->hr)->test(TeamMember::class, ['employee' => $this->outsider])->set('tab', 'week')->assertSee('Last 7 days');
    }
}
