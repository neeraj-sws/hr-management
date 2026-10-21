<?php

namespace Tests\Feature;

use App\Livewire\Training\Courses;
use App\Models\Announcement;
use App\Models\ApprovalRequest;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\EmployeeLifecycleCase;
use App\Models\ExpenseClaim;
use App\Models\Interview;
use App\Models\JobOpening;
use App\Models\OfficeLocation;
use App\Models\PayrollItem;
use App\Models\SalaryProfile;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\TrainingAssignment;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\AttendanceService;
use App\Services\CompanyService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use App\Services\RecruitmentService;
use App\Services\ShiftService;
use App\Services\TrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Corrections, shifts, office check-in, away time, expenses, company info, recruitment and training. */
class HrmsExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $managerUser;
    private User $employeeUser;
    private AttendanceEmployee $manager;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-07 10:00:00'); // Wednesday
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->managerUser = User::factory()->create(['email' => 'ext.manager@example.com']);
        $this->managerUser->assignRole(['Employee', 'Manager']);
        $this->manager = AttendanceEmployee::create(['employee_code' => 'EXT-MGR', 'name' => 'Mona Manager', 'is_active' => true, 'user_id' => $this->managerUser->id]);
        $this->employeeUser = User::factory()->create(['email' => 'ext.employee@example.com']);
        $this->employeeUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EXT-EMP', 'name' => 'Esha Employee', 'is_active' => true, 'user_id' => $this->employeeUser->id, 'manager_id' => $this->manager->id, 'work_start' => '09:30:00', 'joined_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_attendance_correction_is_approved_by_the_manager_and_updates_the_day(): void
    {
        $session = AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-06', 'check_in' => '2026-10-06 09:20:00', 'status' => 'working']);
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/attendance/corrections', ['work_date' => '2026-10-06', 'check_out' => '18:30', 'reason' => 'Forgot to check out'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/attendance/corrections', ['work_date' => '2026-10-06', 'check_out' => '18:40', 'reason' => 'Again please'])->assertUnprocessable();
        $this->postJson('/api/attendance/corrections', ['work_date' => '2026-10-09', 'check_out' => '18:40', 'reason' => 'Future day'])->assertUnprocessable();

        $correction = AttendanceCorrection::firstOrFail();
        $approval = ApprovalRequest::where('approvable_type', AttendanceCorrection::class)->firstOrFail();
        $inbox = app(ApprovalInbox::class);
        $this->assertFalse($inbox->canDecide($this->employeeUser, $approval), 'Employees cannot approve their own correction');
        $this->assertTrue($inbox->canDecide($this->managerUser, $approval));
        $this->assertSame(1, $inbox->pending($this->managerUser, AttendanceCorrection::class)->count());

        $inbox->decide($this->managerUser, $approval, true);
        $session->refresh();
        $this->assertSame('2026-10-06 18:30:00', $session->check_out->toDateTimeString());
        $this->assertSame('completed', $session->status);
        $this->assertSame(9 * 3600 + 10 * 60, (int) $session->active_seconds);
        $this->assertSame('approved', $correction->fresh()->status);
        $this->assertTrue($session->events()->where('event_type', 'correction')->exists());
    }

    public function test_correction_for_a_missed_day_creates_the_session_and_rejection_needs_a_note(): void
    {
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/attendance/corrections', ['work_date' => '2026-10-05', 'check_out' => '18:00', 'reason' => 'Was at client site'])->assertUnprocessable()->assertJsonValidationErrors('checkIn');
        $this->postJson('/api/attendance/corrections', ['work_date' => '2026-10-05', 'check_in' => '09:30', 'check_out' => '18:00', 'reason' => 'Was at client site'])->assertCreated();
        $approval = ApprovalRequest::where('approvable_type', AttendanceCorrection::class)->firstOrFail();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            app(ApprovalInbox::class)->decide($this->managerUser, $approval, false, '');
        } finally {
            app(ApprovalInbox::class)->decide($this->managerUser, $approval->fresh(), true);
            $this->assertSame('2026-10-05 18:00:00', AttendanceSession::where('employee_id', $this->employee->id)->whereDate('work_date', '2026-10-05')->firstOrFail()->check_out->toDateTimeString());
        }
    }

    public function test_shift_sets_the_start_time_used_for_late_marking(): void
    {
        $attendance = app(AttendanceService::class);
        $session = AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-07', 'check_in' => '2026-10-07 09:45:00', 'status' => 'working']);
        $this->assertTrue($attendance->isLate($session->fresh('employee')), 'Own start 09:30 → late at 09:45');

        $night = Shift::create(['name' => 'Late start', 'start_time' => '10:00', 'end_time' => '19:00', 'grace_minutes' => 5, 'is_active' => true]);
        app(ShiftService::class)->assign([$this->employee->id], $night, '2026-10-01', null, $this->admin->id);
        $fresh = app(AttendanceService::class);
        $this->assertSame('10:00', $fresh->rules($this->employee, '2026-10-07')['work_start']);
        $this->assertSame('Late start', $fresh->rules($this->employee, '2026-10-07')['shift']);
        $this->assertFalse($fresh->isLate($session->fresh('employee')));
        $this->assertNull($fresh->rules($this->employee, '2026-09-30')['shift'], 'No shift before the assignment starts');
    }

    public function test_mobile_check_in_must_be_at_an_office_when_required(): void
    {
        $office = OfficeLocation::create(['name' => 'Test Office', 'latitude' => 12.9716, 'longitude' => 77.5946, 'radius_meters' => 200, 'is_active' => true]);
        Sanctum::actingAs($this->employeeUser, ['*']);
        // Not required: position is optional and stored for reference.
        $this->postJson('/api/attendance/check-in', ['source' => 'mobile'])->assertCreated();
        AttendanceSession::query()->delete();

        app(PeopleOperationsService::class)->setPolicy('attendance.location', 'attendance', 'Mobile check-in location', ['required_for_mobile' => true], null, $this->admin->id, '2026-10-07');
        $this->postJson('/api/attendance/check-in', ['source' => 'mobile'])->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->postJson('/api/attendance/check-in', ['source' => 'mobile', 'latitude' => 12.99, 'longitude' => 77.60])->assertUnprocessable()->assertJsonPath('errors.location.0', fn ($m) => str_contains($m, 'Test Office'));
        $this->postJson('/api/attendance/check-in', ['source' => 'mobile', 'latitude' => 12.9720, 'longitude' => 77.5950, 'accuracy' => 15])->assertCreated();
        $session = AttendanceSession::firstOrFail();
        $this->assertSame($office->id, $session->check_in_office_id);
        $this->assertLessThan(200, $session->check_in_distance_meters);
        // Desktop/web are not location-checked.
        $this->postJson('/api/attendance/check-out', ['source' => 'desktop'])->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.policies.location_required', true);
    }

    public function test_away_time_becomes_a_break_and_splits_the_task_timer(): void
    {
        $task = Task::create(['title' => 'Write report', 'assignee_id' => $this->employee->id, 'status' => 'todo', 'priority' => 'normal']);
        Carbon::setTestNow('2026-10-07 09:00:00');
        $attendance = app(AttendanceService::class);
        $attendance->checkIn($this->employee, 'desktop');
        app(HrWorkflowService::class)->startTask($this->employee, $task);
        Carbon::setTestNow('2026-10-07 11:00:00');

        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/attendance/away', ['from' => '2026-10-07 10:00:00', 'to' => '2026-10-07 10:30:00', 'reason' => 'locked'])->assertOk()->assertJsonPath('data.status', 'working');
        $session = AttendanceSession::firstOrFail();
        $this->assertSame(1800, (int) $session->break_seconds);
        $entries = TaskTimeEntry::orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(3600, (int) $entries[0]->duration_seconds);
        $this->assertSame('2026-10-07 10:30:00', $entries[1]->started_at->toDateTimeString());
        $this->assertSame('running', $entries[1]->status);
        $this->postJson('/api/attendance/away', ['from' => '2026-10-07 10:15:00', 'to' => '2026-10-07 10:45:00', 'reason' => 'idle'])->assertUnprocessable()->assertJsonValidationErrors('away');
        $this->postJson('/api/attendance/away', ['from' => '2026-10-07 10:50:00', 'to' => '2026-10-07 10:55:00', 'reason' => 'gaming'])->assertUnprocessable();
        $this->assertSame(2 * 3600 - 1800, $attendance->workedSeconds($session->fresh()), 'two hours since check-in minus a 30 minute break');
    }

    public function test_desktop_screen_time_is_recorded_and_capped(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');
        app(AttendanceService::class)->checkIn($this->employee, 'desktop');
        Sanctum::actingAs($this->employeeUser, ['*']);
        Carbon::setTestNow('2026-10-07 09:00:30');
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 25, 'screen_idle_seconds' => 5])->assertOk()
            ->assertJsonPath('data.screen_active_seconds', 25)->assertJsonPath('data.screen_idle_seconds', 5);
        Carbon::setTestNow('2026-10-07 09:01:00');
        // A client cannot report more time than has passed (30 s + 15 s slack).
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 900, 'screen_idle_seconds' => 0])->assertOk()->assertJsonPath('data.screen_active_seconds', 25 + 45);
        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => -5])->assertUnprocessable();

        $this->actingAs($this->admin)->get(route('attendance.dashboard'))->assertOk()->assertSee('00:01:10 active', false); // 70 s, shown as HH:MM:SS
        $this->actingAs($this->admin)->get(route('reports.show', 'attendance').'?from=2026-10-07&to=2026-10-07')->assertOk()->assertSee('screen active time', false);
    }

    public function test_expense_claim_is_approved_and_reimbursed_in_payroll(): void
    {
        Storage::fake('local');
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:00:00', 'status' => 'completed']);
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/expenses', ['category' => 'Travel', 'spent_on' => '2026-10-10', 'amount' => 500, 'description' => 'Future'])->assertUnprocessable();
        $this->post('/api/expenses', ['category' => 'Travel', 'spent_on' => '2026-10-06', 'amount' => '750.50', 'description' => 'Cab to client', 'receipt' => $this->fakeImage('bill.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.has_receipt', true)->assertJsonPath('data.status', 'pending');
        $claim = ExpenseClaim::firstOrFail();
        Storage::disk('local')->assertExists($claim->receipt_path);

        // Receipt is private: owner yes, an unrelated employee no.
        $this->actingAs($this->employeeUser)->get(route('expenses.receipt', $claim))->assertOk();
        $other = User::factory()->create();
        $other->assignRole('Employee');
        $this->actingAs($other)->get(route('expenses.receipt', $claim))->assertForbidden();

        $approval = ApprovalRequest::where('approvable_type', ExpenseClaim::class)->firstOrFail();
        app(ApprovalInbox::class)->decide($this->managerUser, $approval, true);
        $this->assertSame('approved', $claim->fresh()->displayStatus());

        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertEquals(750.50, (float) $item->reimbursements_amount);
        $this->assertEquals(1000 + 750.50, (float) $item->net_amount, 'one paid day (22000/22) plus the reimbursement');
        $this->assertSame('paid', $claim->fresh()->displayStatus());
        // Recalculating the draft run does not pay the claim twice.
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $this->assertEquals(750.50, (float) PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->value('reimbursements_amount'));
    }

    public function test_announcements_reach_only_their_audience(): void
    {
        $eng = Department::create(['name' => 'Engineering', 'code' => 'ENG', 'is_active' => true]);
        $this->employee->update(['department_id' => $eng->id]);
        $company = app(CompanyService::class);
        $company->saveAnnouncement(['title' => 'All hands', 'body' => 'Friday 4 PM', 'audience' => 'all'], $this->admin);
        $company->saveAnnouncement(['title' => 'Eng only', 'body' => 'Code freeze', 'audience' => 'department', 'department_id' => $eng->id], $this->admin);

        $this->assertSame(['All hands', 'Eng only'], Announcement::visibleTo($this->employee->fresh())->orderBy('id')->pluck('title')->all());
        $this->assertSame(['All hands'], Announcement::visibleTo($this->manager)->pluck('title')->all());
        $this->assertSame(2, $this->employeeUser->notifications()->count());
        $this->assertSame(1, $this->managerUser->notifications()->count());
        Sanctum::actingAs($this->managerUser, ['*']);
        $this->getJson('/api/announcements')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->employeeUser)->get(route('company.announcements'))->assertOk()->assertSee('Eng only');
    }

    public function test_birthdays_respect_privacy_and_greetings_are_sent_once(): void
    {
        $this->employee->update(['date_of_birth' => '1995-10-07']);
        $this->manager->update(['date_of_birth' => '1990-10-09', 'share_birthday' => false, 'joined_at' => '2023-10-08']);
        $events = app(CompanyService::class)->celebrations(7);
        $this->assertSame(['birthday', 'anniversary'], $events->pluck('type')->all());
        $this->assertTrue($events[0]['today']);
        $this->assertSame(3, $events[1]['years']);

        $this->artisan('hr:celebrations')->assertSuccessful();
        $this->artisan('hr:celebrations')->assertSuccessful();
        $this->assertSame(1, $this->managerUser->notifications()->where('data->kind', 'Celebration')->count());
        $this->assertSame(0, $this->employeeUser->notifications()->where('data->kind', 'Celebration')->count(), 'No greeting to the birthday person about themselves');
    }

    public function test_holidays_are_listed_with_names(): void
    {
        app(PeopleOperationsService::class)->setPolicy('calendar.working_days', 'calendar', 'Working calendar', ['days' => [1, 2, 3, 4, 5], 'holidays' => ['2026-12-25', '2026-10-02'], 'holiday_names' => ['2026-12-25' => 'Christmas']], null, $this->admin->id, '2026-01-01');
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->getJson('/api/holidays?year=2026')->assertOk()
            ->assertJsonPath('data.0.date', '2026-10-02')->assertJsonPath('data.0.name', 'Holiday')
            ->assertJsonPath('data.1.name', 'Christmas')->assertJsonPath('weekly_off', ['Saturday', 'Sunday']);
        $this->actingAs($this->employeeUser)->get(route('company.holidays'))->assertOk()->assertSee('Christmas');
    }

    public function test_recruitment_pipeline_from_candidate_to_hired_employee(): void
    {
        $recruitment = app(RecruitmentService::class);
        $job = JobOpening::create(['title' => 'QA Engineer', 'employment_type' => 'full_time', 'openings' => 1, 'status' => 'open', 'hiring_manager_id' => $this->managerUser->id]);
        $candidate = $recruitment->addCandidate(['job_opening_id' => $job->id, 'name' => 'Cara Candidate', 'email' => 'cara@example.com', 'source' => 'referral'], null, $this->admin->id);
        $interview = $recruitment->scheduleInterview($candidate, ['scheduled_at' => '2026-10-08 11:00:00', 'duration_minutes' => 45, 'mode' => 'video', 'interviewer_id' => $this->managerUser->id]);
        $this->assertSame('interview', $candidate->fresh()->stage);
        $this->assertSame(1, $this->managerUser->notifications()->where('data->kind', 'Interview')->count());

        // The interviewer (not a recruiter) can open their interviews page; others cannot.
        $this->actingAs($this->managerUser)->get(route('recruitment.interviews'))->assertOk()->assertSee('Cara Candidate');
        $this->actingAs($this->employeeUser)->get(route('recruitment.interviews'))->assertForbidden();
        $this->expectsFeedbackOnlyFromInterviewer($interview);

        $recruitment->moveStage($candidate, 'offer');
        $employee = $recruitment->hire($candidate->fresh(), ['employee_code' => 'NEW-001', 'password' => 'NewJoinerPass123', 'joined_at' => '2026-10-15', 'monthly_salary' => 30000], $this->admin);
        $this->assertSame('hired', $candidate->fresh()->stage);
        $this->assertSame('cara@example.com', $employee->user->email);
        $this->assertTrue($employee->user->hasRole('Employee'));
        $this->assertTrue(EmployeeLifecycleCase::where('employee_id', $employee->id)->where('kind', 'onboarding')->exists());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $recruitment->hire($candidate->fresh(), ['employee_code' => 'NEW-002', 'password' => 'NewJoinerPass123', 'monthly_salary' => 1], $this->admin);
    }

    private function expectsFeedbackOnlyFromInterviewer(Interview $interview): void
    {
        $recruitment = app(RecruitmentService::class);
        try {
            $recruitment->submitFeedback($interview, $this->employeeUser, ['rating' => 5, 'recommendation' => 'hire', 'feedback' => 'Not my interview']);
            $this->fail('Only the interviewer or a recruiter may give feedback');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $recruitment->submitFeedback($interview, $this->managerUser, ['rating' => 4, 'recommendation' => 'hire', 'feedback' => 'Solid testing mindset.']);
        $this->assertSame(4.0, Candidate::find($interview->candidate_id)->load('interviews')->averageRating());
    }

    public function test_training_is_assigned_completed_and_managers_only_assign_their_team(): void
    {
        $course = TrainingCourse::create(['title' => 'Security basics', 'is_mandatory' => true, 'is_active' => true]);
        $outsider = AttendanceEmployee::create(['employee_code' => 'EXT-OUT', 'name' => 'Other Team', 'is_active' => true]);

        Livewire::actingAs($this->managerUser)->test(Courses::class)
            ->call('openAssign', $course->id)->set('employeeIds', [(string) $outsider->id])->set('dueOn', '2026-10-20')->call('assign')->assertForbidden();
        Livewire::actingAs($this->managerUser)->test(Courses::class)
            ->call('openAssign', $course->id)->set('employeeIds', [(string) $this->employee->id])->set('dueOn', '2026-10-20')->call('assign')->assertHasNoErrors();
        $this->assertSame(1, TrainingAssignment::count());
        $this->assertSame(1, app(TrainingService::class)->assign($course, [$this->employee->id, $outsider->id], null, $this->admin->id), 'Existing assignments are kept, not duplicated');

        $assignment = TrainingAssignment::where('employee_id', $this->employee->id)->firstOrFail();
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->getJson('/api/training')->assertOk()->assertJsonPath('data.0.title', 'Security basics')->assertJsonPath('data.0.status', 'assigned');
        $this->postJson("/api/training/{$assignment->id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/training/{$assignment->id}/complete", ['note' => 'Done'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/training/{$assignment->id}/complete")->assertUnprocessable();
        $foreign = TrainingAssignment::where('employee_id', $outsider->id)->firstOrFail();
        $this->postJson("/api/training/{$foreign->id}/start")->assertForbidden();
    }

    public function test_new_pages_open_for_the_right_people(): void
    {
        foreach (['company.announcements', 'company.holidays', 'company.celebrations', 'company.org-chart', 'attendance.corrections', 'expenses.index', 'training.my'] as $route) {
            $this->actingAs($this->employeeUser)->get(route($route))->assertOk();
        }
        foreach (['attendance.shifts', 'recruitment.jobs', 'recruitment.candidates', 'training.courses'] as $route) {
            $this->actingAs($this->employeeUser)->get(route($route))->assertForbidden();
        }
        $hr = User::factory()->create();
        $hr->assignRole('HR');
        foreach (['attendance.shifts', 'recruitment.jobs', 'recruitment.candidates', 'recruitment.interviews', 'training.courses'] as $route) {
            $this->actingAs($hr)->get(route($route))->assertOk();
        }
        $this->actingAs($this->employeeUser)->get(route('company.org-chart'))->assertOk()->assertSee('Mona Manager')->assertSee('Esha Employee');
        $this->actingAs($this->admin)->get(route('reports.show', 'expenses'))->assertOk();
        $this->actingAs($this->admin)->get(route('reports.show', 'training'))->assertOk();
    }
}
