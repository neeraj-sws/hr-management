<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Tasks, projects, daily status, documents, compliance, notes, overtime switch and notifications. */
class ReviewRoundThreeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function employee(string $code, array $extra = []): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', ...$extra]);
    }

    private function kinds(User $user, string $kind)
    {
        return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? '') === $kind)->values();
    }

    public function test_subtask_form_replaces_the_task_view_and_returns_to_it(): void
    {
        $e = $this->employee('EMP-1');
        $parent = Task::create(['title' => 'Build login', 'assignee_id' => $e->id, 'status' => 'todo', 'priority' => 'normal', 'created_by' => $this->admin->id]);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Tasks::class)
            ->call('open', $parent->id)->assertSee('Add subtask')
            ->call('create', $parent->id)->assertSet('showForm', true)->assertSet('openId', null)->assertSet('parentId', $parent->id)
            ->set('title', 'Password reset screen')->call('save')->assertHasNoErrors()
            ->assertSet('showForm', false)->assertSet('openId', $parent->id)->assertSee('Password reset screen');
        $this->assertSame($parent->id, Task::where('title', 'Password reset screen')->value('parent_id'));
    }

    public function test_tasks_can_be_filtered_by_a_custom_due_date_range(): void
    {
        $e = $this->employee('EMP-1');
        foreach (['Early' => '2026-10-02', 'Middle' => '2026-10-10', 'Late' => '2026-10-25'] as $title => $due) {
            Task::create(['title' => "{$title} task", 'assignee_id' => $e->id, 'status' => 'todo', 'priority' => 'normal', 'due_on' => $due]);
        }
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Tasks::class)
            ->set('due', 'custom')->set('dueFrom', '2026-10-05')->set('dueTo', '2026-10-20')
            ->assertSee('Middle task')->assertDontSee('Early task')->assertDontSee('Late task')
            ->set('due', '')->assertSet('dueFrom', '')->assertSee('Early task');
    }

    public function test_projects_show_client_and_member_count(): void
    {
        $a = $this->employee('EMP-1');
        $b = $this->employee('EMP-2');
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Projects::class)
            ->call('create')->set('name', 'Portal')->set('clientName', 'BCI Industries')->call('save')->assertHasNoErrors();
        $project = Project::where('name', 'Portal')->firstOrFail();
        $this->assertSame('BCI Industries', $project->client_name);
        $project->members()->attach([$a->id, $b->id]);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Projects::class)
            ->assertSee('BCI Industries')->assertSee('2 members')
            ->set('search', 'BCI')->assertSee('Portal');
    }

    public function test_daily_status_is_saved_task_wise_without_a_separate_summary(): void
    {
        $e = $this->employee('EMP-1');
        $project = Project::create(['name' => 'Atlas', 'status' => 'active']);
        $task = Task::create(['title' => 'Build login', 'assignee_id' => $e->id, 'project_id' => $project->id, 'status' => 'in_progress', 'priority' => 'normal']);

        Livewire::actingAs($e->user)->test(\App\Livewire\Work\DailyStatus::class)
            ->call('save')->assertHasErrors('summary')
            ->set('taskNotes.'.$task->id, 'Finished validation')->call('save')->assertHasNoErrors();

        $status = \App\Models\DailyWorkStatus::firstOrFail();
        $this->assertSame('Atlas › Build login: Finished validation', $status->summary);
        $this->assertSame('Finished validation', $status->project_updates[0]['tasks'][0]['note']);
        Livewire::actingAs($e->user)->test(\App\Livewire\Work\DailyStatus::class)->assertSet('taskNotes.'.$task->id, 'Finished validation')->assertSee('Finished validation');
    }

    public function test_compliance_rules_with_dotted_keys_save_the_entered_values(): void
    {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Compliance\Policies::class)
            ->set('values.daily_status__missing.amount_per_day', '50')->set('values.daily_status__missing.grace_hours', '4')
            ->call('saveRule', 'daily_status.missing')->assertHasNoErrors()
            ->assertSee('amount per day: 50');
        $config = app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'daily_status.missing', '2026-10-15', []);
        $this->assertEquals(['amount_per_day' => 50.0, 'grace_hours' => 4.0], $config);
    }

    public function test_my_notes_and_team_notes_are_separate(): void
    {
        $e = $this->employee('EMP-1');
        $team = \App\Models\Team::create(['name' => 'Web', 'is_active' => true]);
        app(\App\Services\EmployeeService::class)->syncTeamMembers($team, [$e->id], $this->admin);
        \App\Models\EmployeeNote::create(['author_id' => $e->user_id, 'visibility' => 'personal', 'title' => 'Private idea', 'body' => 'x']);
        \App\Models\EmployeeNote::create(['author_id' => $e->user_id, 'visibility' => 'team', 'team_id' => $team->id, 'title' => 'Team plan', 'body' => 'y']);

        $this->actingAs($e->user)->get(route('notes.my'))->assertSee('Private idea')->assertDontSee('Team plan');
        $this->actingAs($e->user)->get(route('notes.team'))->assertSee('Team plan')->assertDontSee('Private idea');
    }

    public function test_penalties_tell_the_approvers_and_then_the_employee(): void
    {
        $e = $this->employee('EMP-1');
        $people = app(\App\Services\PeopleOperationsService::class);
        $hr = User::factory()->create(); $hr->assignRole('HR');
        $penalty = $people->requestPenalty($e, 250, 'Missed client call', $this->admin->id);
        $approvers = \App\Services\Notifier::withPermission('penalties.manage');
        $other = $approvers->first(fn ($u) => $u->id !== $this->admin->id);
        if ($other) $this->assertStringContainsString('Penalty of 250.00 for Person EMP-1 needs approval: Missed client call', $this->kinds($other, 'EmployeePenalty')->first()->data['message']);
        $this->assertCount(0, $this->kinds($this->admin, 'EmployeePenalty'), 'The person who raised it is not notified');

        $people->decideFinancialRequest(\App\Models\EmployeePenalty::class, $penalty->id, ($other ?? $this->admin)->id, true);
        $this->assertStringContainsString('Your penalty of 250.00 was approved', $this->kinds($e->user, 'EmployeePenalty')->first()->data['message']);
    }

    public function test_overtime_can_be_switched_off_and_goes_to_admins_when_the_manager_is_away(): void
    {
        $boss = $this->employee('EMP-9');
        $e = $this->employee('EMP-1', ['manager_id' => $boss->id]);
        $boss->update(['is_active' => false]);
        $people = app(\App\Services\PeopleOperationsService::class);
        $ot = $people->requestOvertime($e, '2026-10-14', 60, 'Release');
        $this->assertNull(\App\Models\ApprovalRequest::where('approvable_id', $ot->id)->where('approvable_type', \App\Models\OvertimeRequest::class)->value('approver_id'), 'Inactive manager: admins decide');

        Livewire::actingAs($this->admin)->test(\App\Livewire\System\Settings::class)->set('tab', 'payroll')->set('otEnabled', false)->call('savePayroll')->assertHasNoErrors();
        $this->assertFalse($people->overtimeEnabled($e));
        $this->actingAs($e->user)->get(route('attendance.my'))->assertOk()->assertDontSee('href="'.route('leave.overtime').'"', false);
        $this->assertFalse(\App\Support\Navigation::canAccess($this->admin, 'leave.overtime'), 'Hidden from the menu for everyone while switched off');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $people->requestOvertime($e, '2026-10-14', 30, 'More');
    }

    public function test_password_reset_notifies_the_employee(): void
    {
        $e = $this->employee('EMP-1');
        app(\App\Services\EmployeeService::class)->resetPassword($e, 'NewPassword123', $this->admin);
        $message = $this->kinds($e->user, 'PasswordChanged')->first()->data['message'];
        $this->assertStringContainsString('changed your login password', $message);
        $this->assertStringNotContainsString('NewPassword123', $message);
    }

    /** Mon–Sat working, only the 3rd Saturday off (plus Sunday) — as the company works. */
    private function thirdSaturdayCalendar(): void
    {
        app(\App\Services\PeopleOperationsService::class)->setPolicy('calendar.working_days', 'calendar', 'Working calendar', \App\Support\WorkCalendar::normalise(['days' => [1, 2, 3, 4, 5, 6], 'holidays' => [], 'off_weeks' => ['6' => [3]]]), null, $this->admin->id, '2026-01-01');
    }

    public function test_work_on_third_saturday_sunday_or_leave_is_extra_working_but_other_saturdays_are_normal_days(): void
    {
        $this->thirdSaturdayCalendar();
        $e = $this->employee('EMP-90', ['name' => 'Khushboo Verma']);
        \App\Models\SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 26000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 26, 'standard_daily_hours' => 8]);
        $people = app(\App\Services\PeopleOperationsService::class);
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', ['multiplier' => 1.5, 'holiday_multiplier' => 2], null, $this->admin->id, '2026-01-01');
        $work = fn (string $day, string $in, string $out) => \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => $day, 'check_in' => "{$day} {$in}:00", 'check_out' => "{$day} {$out}:00", 'status' => 'completed', 'active_seconds' => Carbon::parse("{$day} {$in}")->diffInSeconds(Carbon::parse("{$day} {$out}"))]);
        $work('2026-09-12', '10:00', '18:00'); // 2nd Saturday: a normal working day
        $work('2026-09-19', '10:00', '14:00'); // 3rd Saturday (off): 4 h extra
        $work('2026-09-20', '10:00', '13:00'); // Sunday: 3 h extra
        $work('2026-09-22', '10:00', '12:00'); // on a full-day leave: 2 h extra
        $work('2026-09-23', '10:00', '22:00'); // normal day until night: extra only via overtime request
        \App\Models\LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => \App\Models\LeaveType::where('name', 'Unpaid Leave')->value('id'), 'starts_on' => '2026-09-22', 'ends_on' => '2026-09-22', 'days' => 1, 'reason' => 'x', 'status' => 'approved']);
        $ot = $people->requestOvertime($e, '2026-09-19', 60, 'Sat'); // overtime asked for an extra day: not paid twice
        $people->decideOvertime($ot, $this->admin->id, true);
        Carbon::setTestNow('2026-10-02 10:00:00');

        $run = app(\App\Services\HrWorkflowService::class)->calculatePayroll('2026-09-01', '2026-09-30', $this->admin->id);
        $b = $run->items()->where('employee_id', $e->id)->firstOrFail()->calculation_breakdown;
        $this->assertSame(['2026-09-19' => 'off', '2026-09-20' => 'off', '2026-09-22' => 'leave'], array_map(fn ($d) => $d['type'], $b['extra_work']['dates']), 'Only the 3rd Saturday, Sunday and the leave day are extra; the 2nd Saturday is a normal day');
        $this->assertEquals([3, 9 * 3600, 2.0], [$b['extra_work']['days'], $b['extra_work']['seconds'], $b['extra_work']['multiplier']]);
        $this->assertEquals(round(26000 / (26 * 8) * 9 * 2, 2), $b['extra_work']['amount'], '9 h × hourly rate × the weekly-off multiplier');
        $this->assertSame(60, $b['extra_work']['overtime_minutes_not_paid_twice']);
        $this->assertEquals(0, (float) $b['overtime_amount'], 'The night on 23 Sep is not paid without an overtime request');
        $this->assertSame(1, \App\Models\LeaveRequest::where('employee_id', $e->id)->where('status', 'approved')->count(), 'The leave stays');

        $days = app(\App\Services\AttendanceService::class)->days($e, Carbon::parse('2026-09-12'), Carbon::parse('2026-09-23'))->keyBy('date');
        $this->assertSame([false, true, true, true, false], [$days['2026-09-12']['extra'], $days['2026-09-19']['extra'], $days['2026-09-20']['extra'], $days['2026-09-22']['extra'], $days['2026-09-23']['extra']]);
        $this->actingAs($e->user)->get(route('attendance.my', ['month' => '2026-09']))->assertOk();
        $this->get(route('leave.overtime', ['date' => '2026-09-23', 'minutes' => 240]))->assertOk()->assertSee('Worked beyond my shift on 23 Sep 2026');
    }

    public function test_extra_working_can_be_switched_off(): void
    {
        $this->thirdSaturdayCalendar();
        $e = $this->employee('EMP-91');
        \App\Models\SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 26000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 26, 'standard_daily_hours' => 8]);
        \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-17', 'check_in' => '2026-10-17 10:00:00', 'check_out' => '2026-10-17 14:00:00', 'status' => 'completed', 'active_seconds' => 4 * 3600]); // 3rd Saturday of October
        Livewire::actingAs($this->admin)->test(\App\Livewire\System\Settings::class)->set('tab', 'payroll')->assertSet('extraWorkAuto', true)->set('extraWorkAuto', false)->call('savePayroll')->assertHasNoErrors();
        // The switch applies from the day it is saved (15 Oct), so October's payroll uses it.
        Carbon::setTestNow('2026-11-02 10:00:00');
        $b = app(\App\Services\HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id)->items()->where('employee_id', $e->id)->firstOrFail()->calculation_breakdown;
        $this->assertSame([false, 1, 0.0], [$b['extra_work']['enabled'], $b['extra_work']['days'], (float) $b['extra_work']['amount']], 'Recorded, but not paid');
    }

    public function test_payroll_cannot_be_locked_while_requests_of_the_period_are_pending(): void
    {
        $e = $this->employee('EMP-1');
        \App\Models\SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $leave = \App\Models\LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => \App\Models\LeaveType::where('name', 'Unpaid Leave')->value('id'), 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-12', 'days' => 1, 'reason' => 'x', 'status' => 'pending']);
        Carbon::setTestNow('2026-11-02 10:00:00');
        $workflow = app(\App\Services\HrWorkflowService::class);
        $run = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $workflow->approvePayroll($run, $this->admin->id);
        try {
            $workflow->lockPayroll($run->fresh(), $this->admin->id);
            $this->fail('Pending leave blocks the lock');
        } catch (\Illuminate\Validation\ValidationException $err) {
            $this->assertStringContainsString('1 leave request', $err->getMessage());
        }
        $leave->update(['status' => 'rejected']);
        $this->assertSame('locked', $workflow->lockPayroll($run->fresh(), $this->admin->id)->status);
    }

    public function test_thirteen_column_attendance_template_downloads_and_imports(): void
    {
        foreach (['EMP-001', 'EMP-002', 'EMP-003', 'EMP-004'] as $code) $this->employee($code);
        $response = $this->actingAs($this->admin)->get(route('imports.template', 'attendance'));
        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $rows = \App\Support\XlsxReader::rows($path);
        $this->assertSame(array_column(\App\Services\ImportService::ATTENDANCE_SHEET, 0), $rows[0], 'Exactly the 13 columns');
        $zip = new \ZipArchive(); $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<f>IF(D2=&quot;&quot;,&quot;&quot;,MAX(0,ROUND((D2-TIME(8,30,0))*1440,0)))</f>', $sheet, 'Late minutes formula');
        $this->assertStringContainsString('Present,Half Day,Leave,Absent,Holiday,Weekly Off', $sheet, 'Status list');

        // The template itself (Excel dates and times) imports: Present / Half Day rows become attendance, Leave / Holiday rows do not.
        $file = new \Illuminate\Http\UploadedFile($path, 'attendance.xlsx', null, null, true);
        $result = app(\App\Services\ImportService::class)->attendance($file, $this->admin->id);
        $this->assertSame(3, $result['created'], json_encode($result));
        $this->assertSame([], $result['errors']);
        $session = \App\Models\AttendanceSession::whereHas('employee', fn ($q) => $q->where('employee_code', 'EMP-002'))->firstOrFail();
        $this->assertSame(['08:47', '18:30', 35 * 60], [$session->check_in->format('H:i'), $session->check_out->format('H:i'), (int) $session->break_seconds], 'Lunch Out − Lunch In is the break');
        $this->assertStringContainsString('EMP-004 01 Oct 2026 — saved as approved Unpaid Leave', implode(' ', $result['notes']));

        $csv = $this->get(route('imports.template', ['attendance', 'format' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('EMP-002,2026-10-01,Present,08:47,13:15,13:50,18:30,09:43,00:35,09:08,17,2026-10-02,1', $csv);
        $this->assertStringContainsString('EMP-004,2026-10-01,Leave,,,,,,,,,,', $csv);
    }

    public function test_attendance_sheet_with_day_offs_zero_lunch_totals_row_and_unknown_code(): void
    {
        $e = $this->employee('EMP-7');
        // An unrelated later month is locked: older history (no payroll run) can still be imported.
        \App\Models\PayrollRun::create(['period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'locked', 'created_by' => $this->admin->id]);
        $csv = "Employee ID,Date,Status,Punch In Time,Lunch In Time,Lunch Out Time,Punch Out Time,Total Working Hours,Total Lunch Minutes,Out Time (After Deduct Lunch Time),Late Coming Minute,Email Send Date,Email Sent Delay\n"
            ."EMP-7,2026-04-01,Present,10:38,14:58,15:50,20:26,,,,,\"April 3, 2026\",2\n"
            ."EMP-7,2026-04-02,Present,12:00,00:00,00:00,16:00,,,,,-,-\n"
            ."EMP-7,2026-04-04,Third Saturday,0,0,0,0,0,0,0,0,-,-\n"
            ."EMP-7,2026-04-05,Weekly Off,,,,,,,,,,\n"
            ."EMP-7,2026-04-06,Absent,,,,,,,,,,\n"
            ."EMP-001,2026-04-07,Present,09:00,,,18:00,,,,,,\n"
            ."EMP-001,2026-04-08,Present,09:00,,,18:00,,,,,,\n"
            .",,,,,,,,,,751,,\n";
        $path = tempnam(sys_get_temp_dir(), 'att').'.csv';
        file_put_contents($path, $csv);
        $r = app(\App\Services\ImportService::class)->attendance(new \Illuminate\Http\UploadedFile($path, 'khushboo.csv', null, null, true), $this->admin->id);

        $this->assertSame([2, 2], [$r['created'], $r['skipped']], json_encode($r));
        $this->assertSame(['No employee with Employee ID "EMP-001" (row 7, 8). Put the employee\'s code exactly as shown on the Employees page (their e-mail or full name also works) — or, for one person\'s sheet, choose them in "Import for employee" and import again.'], $r['errors']);
        $this->assertSame(0, (int) \App\Models\AttendanceSession::whereDate('work_date', '2026-04-02')->value('break_seconds'), '00:00 lunch = no lunch');
        $this->assertStringContainsString('"Third Saturday" — no attendance created', implode(' ', $r['notes']));
        $this->assertSame(2, \App\Models\AttendanceSession::where('employee_id', $e->id)->count());

        // A file with rows of several people is not imported for one chosen employee.
        $khushboo = $this->employee('SWS-120');
        $r = app(\App\Services\ImportService::class)->attendance(new \Illuminate\Http\UploadedFile($path, 'khushboo.csv', null, null, true), $this->admin->id, false, $khushboo->id);
        $this->assertSame(0, $r['created']);
        $this->assertStringContainsString('This file has rows for several employees (Employee ID "EMP-7", "EMP-001")', $r['errors'][0]);
        $this->assertSame(0, \App\Models\AttendanceSession::where('employee_id', $khushboo->id)->count());
    }

    public function test_one_persons_sheet_with_a_template_code_is_matched_by_the_file_name(): void
    {
        $k = $this->employee('SWS-130', ['name' => 'Khushboo Sharma']);
        $path = tempnam(sys_get_temp_dir(), 'att').'.csv';
        file_put_contents($path, "Employee ID,Date,Status,Punch In Time,Punch Out Time\nEMP-001,2026-04-01,Present,10:00,19:00\nEMP-001,2026-04-02,Absent,,\n");
        $r = app(\App\Services\ImportService::class)->attendance(new \Illuminate\Http\UploadedFile($path, 'attendance-import-khushboo-april-2026.csv', null, null, true), $this->admin->id);
        $this->assertSame([1, []], [$r['created'], $r['errors']], json_encode($r));
        $this->assertStringContainsString('imported for Khushboo Sharma (SWS-130)', implode(' ', $r['notes']));
        $this->assertSame(1, \App\Models\AttendanceSession::where('employee_id', $k->id)->count());

        // No name in the file name: still the clear error.
        $r = app(\App\Services\ImportService::class)->attendance(new \Illuminate\Http\UploadedFile($path, 'april.csv', null, null, true), $this->admin->id);
        $this->assertStringContainsString('No employee with Employee ID "EMP-001"', $r['errors'][0]);
    }

    public function test_unknown_employee_id_can_be_assigned_from_the_result_without_uploading_again(): void
    {
        $sana = $this->employee('SWS-104');
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('attendance-april.csv', "Employee ID,Date,Status,Punch In Time,Punch Out Time\nEMP-001,2026-04-01,Present,10:00,19:00\nEMP-001,2026-04-03,Present,09:30,18:30\n");
        $this->actingAs($this->admin)->from(route('attendance.records'))->post(route('attendance.records.import'), ['file' => $file])->assertRedirect();
        $pending = session('import_result')['pending'];
        $this->assertSame('EMP-001', $pending['code']);
        $this->get(route('attendance.records'))->assertSee('Import for this employee')->assertSee('Whose sheet is');

        $this->from(route('attendance.records'))->post(route('attendance.records.import-for'), ['token' => $pending['token'], 'name' => $pending['name'], 'employee_id' => $sana->id])->assertRedirect();
        $this->assertSame(2, session('import_result')['created']);
        $this->assertSame(2, \App\Models\AttendanceSession::where('employee_id', $sana->id)->count());
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->files('import-pending'), 'Kept file is removed after import');
    }

    public function test_import_from_the_employee_profile_saves_for_that_employee_and_opens_the_imported_days(): void
    {
        $k = $this->employee('SWS-140', ['name' => 'Khushboo Rao']);
        $this->actingAs($this->admin)->get(route('people.employees.show', $k))->assertOk()->assertSee('Import for Khushboo Rao');
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('april.csv', "Employee ID,Date,Status,Punch In Time,Lunch In Time,Lunch Out Time,Punch Out Time\nEMP-001,2026-04-01,Present,10:38,14:58,15:50,20:26\nEMP-001,2026-04-02,Absent,,,,\nEMP-001,2026-04-03,Present,10:56,,,21:05\n");
        $this->from(route('people.employees.show', $k))->post(route('attendance.records.import'), ['file' => $file, 'employee_id' => $k->id])
            ->assertRedirect(route('attendance.records', ['from' => '2026-04-01', 'to' => '2026-04-03', 'employee' => $k->id]));
        $this->assertSame(2, \App\Models\AttendanceSession::where('employee_id', $k->id)->count());
        $this->get(route('attendance.records', ['from' => '2026-04-01', 'to' => '2026-04-03', 'employee' => $k->id]))
            ->assertSee('The imported days are listed below')->assertSee('Khushboo Rao')->assertSee('01 Apr 2026');
    }

    public function test_old_history_goes_into_locked_payroll_months_only_when_an_admin_asks(): void
    {
        $e = $this->employee('SWS-150');
        $run = \App\Models\PayrollRun::create(['period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'locked', 'created_by' => $this->admin->id]);
        $csv = "Employee ID,Date,Status,Punch In Time,Punch Out Time\n".collect(range(1, 5))->map(fn ($d) => "SWS-150,2026-09-0{$d},Present,10:00,19:00")->join("\n")."\nSWS-150,2026-08-31,Present,10:00,19:00\n";
        $upload = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('history.csv', $csv);

        $hr = User::factory()->create(); $hr->assignRole('HR');
        $this->actingAs($hr)->from(route('attendance.records'))->post(route('attendance.records.import'), ['file' => $upload(), 'into_locked' => 1]);
        $r = session('import_result');
        $this->assertSame([1, 5], [$r['created'], $r['skipped']], 'Only an administrator may write into a locked month');
        $this->assertSame(['Payroll for 01 Sep – 30 Sep 2026 is locked, so rows 2–6 (5 rows) were not imported. For old history an administrator can tick "Old data: also import into locked payroll months" (the locked payroll stays as it is), or reopen that payroll first.'], $r['errors']);

        $this->actingAs($this->admin)->get(route('attendance.records'))->assertSee('Old data: also import into locked payroll months');
        $this->from(route('attendance.records'))->post(route('attendance.records.import'), ['file' => $upload(), 'into_locked' => 1]);
        $r = session('import_result');
        $this->assertSame([5, 1, 0], [$r['created'], $r['kept'], $r['skipped']], json_encode($r));
        $this->assertStringContainsString('locked payroll period(s) 01 Sep – 30 Sep 2026; those locked payrolls were not recalculated', $r['notes'][0]);
        $this->assertSame('locked', $run->fresh()->status);
    }

    public function test_leave_rows_in_an_attendance_sheet_become_approved_leave(): void
    {
        $e = $this->employee('SWS-170', ['name' => 'Khushboo Verma']);
        $csv = "Employee ID,Date,Status,Punch In Time,Punch Out Time\n"
            ."SWS-170,2026-04-01,Present,10:00,19:00\n"
            ."SWS-170,2026-04-02,Leave,,\n"
            ."SWS-170,2026-04-03,Absent,,\n"
            ."SWS-170,2026-04-04,Third Saturday,,\n"
            ."SWS-170,2026-04-06,Annual Leave,,\n";
        $r = app(\App\Services\ImportService::class)->attendance(\Illuminate\Http\UploadedFile::fake()->createWithContent('k.csv', $csv), $this->admin->id);

        $this->assertSame(['present' => 1, 'leave' => 1, 'absent' => 1, 'off' => 1], $r['day_counts'], json_encode($r));
        $leave = \App\Models\LeaveRequest::with('leaveType')->where('employee_id', $e->id)->firstOrFail();
        $this->assertSame(['approved', 'Unpaid Leave', '2026-04-02'], [$leave->status, $leave->leaveType->name, $leave->starts_on->toDateString()], 'Plain "Leave" uses Unpaid Leave');
        $this->assertStringContainsString('Row 6: Annual Leave could not be saved — Leave balance is not configured', $r['errors'][0] ?? '', 'A status naming a paid type uses that type and its balance check');
        $this->assertSame(0, \App\Models\LeaveRequest::where('employee_id', $e->id)->whereDate('starts_on', '2026-04-06')->count(), 'Nothing half-saved');
        $this->assertSame(0, $e->user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? '') === 'LeaveRequest')->count(), 'No message per imported day');
        $days = app(\App\Services\AttendanceService::class)->days($e, \Illuminate\Support\Carbon::parse('2026-04-01'), \Illuminate\Support\Carbon::parse('2026-04-03'))->pluck('status', 'date')->all();
        $this->assertSame(['on_leave', 'absent'], [$days['2026-04-02'], $days['2026-04-03']], 'The leave day shows as On leave, the absent day as Absent');
    }

    public function test_work_on_an_off_day_is_extra_working_automatically_but_extra_hours_need_overtime(): void
    {
        $e = $this->employee('SWS-180');
        \App\Models\SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $people = app(\App\Services\PeopleOperationsService::class);
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'short_hours_deduction' => true, 'short_hours_grace' => 0], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', ['multiplier' => 1.5, 'holiday_multiplier' => 2], null, $this->admin->id, '2026-01-01');
        // Saturday 17 Oct (weekly off): 4 h worked. Monday 12 Oct: a 12 h day (4 h beyond the shift) — not requested.
        \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-17', 'check_in' => '2026-10-17 10:00:00', 'check_out' => '2026-10-17 14:00:00', 'status' => 'completed', 'active_seconds' => 4 * 3600]);
        \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-12', 'check_in' => '2026-10-12 09:00:00', 'check_out' => '2026-10-12 21:00:00', 'status' => 'completed', 'active_seconds' => 12 * 3600]);
        // An overtime request for the Saturday too: it must not be paid twice.
        \App\Models\OvertimeRequest::create(['employee_id' => $e->id, 'work_date' => '2026-10-17', 'requested_minutes' => 240, 'approved_minutes' => 240, 'reason' => 'x', 'status' => 'approved']);
        Carbon::setTestNow('2026-11-02 10:00:00');
        $run = app(\App\Services\HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $b = \App\Models\PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $e->id)->firstOrFail()->calculation_breakdown;

        $hourly = 22000 / (22 * 8);
        $this->assertSame(['2026-10-17'], array_keys($b['extra_work']['dates']), 'Only the off day is extra working');
        $this->assertEquals(round($hourly * 4 * 2, 2), $b['extra_work']['amount'], '4 h × hourly × weekly-off multiplier 2');
        $this->assertSame(240, $b['extra_work']['overtime_minutes_not_paid_twice']);
        $this->assertEquals(0, $b['overtime_amount'], 'No overtime: the Saturday is already extra, the Monday was not requested');
        $this->assertSame(8 * 3600, $b['short_hours']['required_seconds'], 'Short hours look at the Monday only — the Saturday is never "short"');

        $day = collect(app(\App\Services\AttendanceService::class)->days($e, Carbon::parse('2026-10-17'), Carbon::parse('2026-10-17')))->first();
        $this->assertTrue($day['extra']);
        Carbon::setTestNow('2026-10-20 12:00:00');
        Livewire::actingAs($e->user)->test(\App\Livewire\Attendance\MyAttendance::class)->assertSee('Extra working')->assertSee('Request overtime');
    }

    public function test_the_desktop_app_computer_is_listed_under_devices_and_can_be_revoked(): void
    {
        $e = $this->employee('SWS-190', ['name' => 'Shweta Pathak']);
        $token = $e->user->createToken('SWS HR Desktop on SHWETA-PC')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'X-Computer-Name' => 'SHWETA-PC', 'X-Windows-User' => 'shweta', 'X-App-Version' => '1.11.0'];

        $this->postJson('/api/attendance/heartbeat', ['screen_active_seconds' => 60], $headers)->assertOk();
        $device = \App\Models\AttendanceDevice::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame(['SHWETA-PC', 'shweta', '1.11.0', true], [$device->computer_name, $device->windows_user, $device->app_version, (bool) $device->is_active]);
        $this->postJson('/api/attendance/heartbeat', [], $headers)->assertOk();
        $this->assertSame(1, \App\Models\AttendanceDevice::where('employee_id', $e->id)->count(), 'One row per computer');

        Livewire::actingAs($this->admin)->test(\App\Livewire\Attendance\Devices::class)->assertSee('SHWETA-PC')->assertSee('Shweta Pathak')
            ->call('revoke', $device->id);
        $this->assertFalse((bool) $device->fresh()->is_active);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/attendance/heartbeat', [], $headers)->assertUnauthorized();
    }

    public function test_per_day_salary_can_use_each_months_working_days_from_the_calendar(): void
    {
        $e = $this->employee('SWS-200');
        \App\Models\SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 35000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 9]);
        $people = app(\App\Services\PeopleOperationsService::class);
        // Mon–Sat working, every 3rd Saturday off (Sundays off) — like the old salary sheet.
        $people->setPolicy('calendar.working_days', 'calendar', 'Calendar', ['days' => [1, 2, 3, 4, 5, 6], 'holidays' => [], 'off_weeks' => ['6' => [3]]], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'divisor' => 'calendar'], null, $this->admin->id, '2026-01-01');
        // Present on 20 of September's 25 working days.
        $worked = 0;
        for ($d = Carbon::parse('2026-09-01'); $d->lte(Carbon::parse('2026-09-30')) && $worked < 20; $d->addDay()) {
            if (! \App\Support\WorkCalendar::isWorkday($d->toDateString(), ['days' => [1, 2, 3, 4, 5, 6], 'off_weeks' => ['6' => [3]]])) continue;
            \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => $d->toDateString(), 'check_in' => $d->toDateString().' 09:30:00', 'check_out' => $d->toDateString().' 18:30:00', 'status' => 'completed', 'active_seconds' => 9 * 3600]);
            $worked++;
        }
        Carbon::setTestNow('2026-10-05 10:00:00');
        $run = app(\App\Services\HrWorkflowService::class)->calculatePayroll('2026-09-01', '2026-09-30', $this->admin->id);
        $item = \App\Models\PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $e->id)->firstOrFail();

        $this->assertSame(25, (int) $item->working_days, 'September: 30 days − 4 Sundays − the 3rd Saturday = 25');
        $this->assertSame(['2026-09' => 25], $item->calculation_data['month_divisors']);
        $this->assertEquals(28000.0, round((float) $item->calculation_breakdown['base_salary'], 2), '35000 ÷ 25 × 20 days = 28,000 (as in the sheet)');
    }

    public function test_calendar_working_days_leave_out_holidays_and_a_full_month_still_pays_in_full(): void
    {
        $e = $this->employee('SWS-201');
        \App\Models\SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 35000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 9]);
        $people = app(\App\Services\PeopleOperationsService::class);
        $calendar = ['days' => [1, 2, 3, 4, 5, 6], 'holidays' => ['2026-10-20'], 'off_weeks' => ['6' => [3]]];
        $people->setPolicy('calendar.working_days', 'calendar', 'Calendar', $calendar, null, $this->admin->id, '2026-01-01');
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'divisor' => 'calendar'], null, $this->admin->id, '2026-01-01');
        $workflow = app(\App\Services\HrWorkflowService::class);
        $this->assertSame(25, $workflow->salaryDaysFor($e, '2026-10-22')['days'], 'October: 31 − 4 Sundays − 3rd Saturday − Dussehra = 25');

        // Present on every working day: the paid holiday is still paid, so the full salary.
        for ($d = Carbon::parse('2026-10-01'); $d->lte(Carbon::parse('2026-10-31')); $d->addDay()) {
            if (! \App\Support\WorkCalendar::isWorkday($d->toDateString(), $calendar)) continue;
            \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => $d->toDateString(), 'check_in' => $d->toDateString().' 09:30:00', 'check_out' => $d->toDateString().' 18:30:00', 'status' => 'completed', 'active_seconds' => 9 * 3600]);
        }
        Carbon::setTestNow('2026-11-05 10:00:00');
        $run = $workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = \App\Models\PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $e->id)->firstOrFail();
        $this->assertSame(['2026-10' => 25], $item->calculation_data['month_divisors']);
        $this->assertEquals(35000.0, round((float) $item->calculation_breakdown['base_salary'], 2));
    }

    public function test_remind_on_daily_status_always_reaches_people_who_have_not_submitted(): void
    {
        $e = $this->employee('SWS-210', ['name' => 'Fahad Shaikh']);
        \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-15', 'check_in' => '2026-10-15 09:30:00', 'status' => 'working']);
        // The automatic evening reminder already went out today …
        app(\App\Services\WorkService::class)->remindDailyStatus('2026-10-15');
        $this->assertSame(1, $e->user->notifications()->count());

        // … HR's Remind still reaches him, by e-mail too.
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\DailyStatus::class)->assertSee('Fahad Shaikh')->call('remindMissing')
            ->assertDispatched('toast', fn ($name, $p) => str_contains(json_encode($p), 'Reminder sent to 1 person (in the app and by e-mail)'));
        $note = $e->user->notifications()->get()->first(fn ($n) => ($n->data['kind'] ?? '') === 'DailyStatusRequested');
        $this->assertStringContainsString('reminds you to submit your daily work status for 15 Oct', $note->data['message']);
        $this->assertSame('work', \App\Notifications\ApprovalRequested::groupFor('DailyStatusRequested'), 'Work group: e-mailed by default');

        // Pressing again within the hour does not spam.
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\DailyStatus::class)->call('remindMissing')
            ->assertDispatched('toast', fn ($name, $p) => str_contains(json_encode($p), 'already reminded in the last hour'));
    }

    public function test_daily_status_and_dashboard_show_own_work_to_employees_and_the_team_to_managers(): void
    {
        $khushboo = $this->employee('EMP-02', ['name' => 'Khushboo Verma']);
        $risha = $this->employee('EMP-01', ['name' => 'Risha Gangrade', 'manager_id' => $khushboo->id]);
        $vijay = $this->employee('EMP-03', ['name' => 'Vijay Choudhary']);
        \App\Models\DailyWorkStatus::create(['employee_id' => $risha->id, 'work_date' => '2026-10-15', 'summary' => 'Risha tested the HRMS module', 'submitted_at' => now()]);
        \App\Models\DailyWorkStatus::create(['employee_id' => $vijay->id, 'work_date' => '2026-10-15', 'summary' => 'Vijay built the payroll screen', 'submitted_at' => now()]);
        Task::create(['title' => 'Vijay secret task', 'assignee_id' => $vijay->id, 'status' => 'in_progress', 'priority' => 'normal']);

        // A manager opening the notification link sees that report's status first.
        $this->actingAs($khushboo->user)->get(route('work.daily-status', ['employee' => $risha->id, 'date' => '2026-10-15']))->assertOk()
            ->assertSee('Team updates')->assertSeeInOrder(['Risha tested the HRMS module', 'Plan for today'])->assertDontSee('Vijay built the payroll screen');
        Livewire::actingAs($khushboo->user)->test(\App\Livewire\Work\DailyStatus::class)->assertSee('Risha tested the HRMS module')->assertDontSee('Vijay built the payroll screen');

        // An employee sees only their own — even with another person's id in the link.
        $this->actingAs($vijay->user)->get(route('work.daily-status', ['employee' => $risha->id]))->assertOk()
            ->assertSee('My history')->assertDontSee('Risha tested the HRMS module');
        $this->actingAs($risha->user)->get(route('dashboard'))->assertOk()->assertDontSee('Vijay secret task')->assertDontSee('Vijay built the payroll screen');

        // A project manager also sees the members of their project.
        $project = Project::create(['name' => 'Payroll', 'status' => 'active', 'manager_id' => $vijay->id]);
        $project->members()->attach($risha->id);
        $this->actingAs($vijay->user)->get(route('work.daily-status'))->assertSee('Risha tested the HRMS module');
    }

    public function test_daily_status_project_filter_shows_only_that_projects_part(): void
    {
        $risha = $this->employee('EMP-01', ['name' => 'Risha Gangrade']);
        $vijay = $this->employee('EMP-03', ['name' => 'Vijay Choudhary']);
        $hrms = Project::create(['name' => 'HRMS Module', 'status' => 'active']);
        $pay = Project::create(['name' => 'Payanyfee.in', 'status' => 'active']);
        \App\Models\DailyWorkStatus::create(['employee_id' => $risha->id, 'work_date' => '2026-10-15', 'summary' => 'Two projects', 'submitted_at' => now(), 'project_updates' => [
            ['project_id' => $hrms->id, 'project' => 'HRMS Module', 'update' => 'Tested attendance import', 'tasks' => [], 'seconds' => 0],
            ['project_id' => $pay->id, 'project' => 'Payanyfee.in', 'update' => 'No work done', 'tasks' => [], 'seconds' => 0],
        ]]);
        \App\Models\DailyWorkStatus::create(['employee_id' => $vijay->id, 'work_date' => '2026-10-15', 'summary' => 'Only payanyfee', 'submitted_at' => now(), 'project_updates' => [
            ['project_id' => $pay->id, 'project' => 'Payanyfee.in', 'update' => 'Built checkout', 'tasks' => [], 'seconds' => 0],
        ]]);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\DailyStatus::class)
            ->set('project', $hrms->id)
            ->assertSee('Tested attendance import')->assertDontSee('No work done')->assertDontSee('Built checkout')
            ->set('project', $pay->id)
            ->assertSee('Built checkout')->assertSee('No work done')->assertDontSee('Tested attendance import');
    }

    public function test_late_and_early_penalties_can_use_the_employees_salary_per_minute(): void
    {
        $e = $this->employee('SWS-220', ['name' => 'Risha Gangrade', 'work_start' => '09:30', 'work_end' => '18:30']);
        \App\Models\SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 10000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 9]);
        $people = app(\App\Services\PeopleOperationsService::class);
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 25], null, $this->admin->id, '2026-01-01');

        Livewire::actingAs($this->admin)->test(\App\Livewire\Compliance\Policies::class)
            ->assertSee('salary per minute')
            ->set('values.attendance__late_penalty.salary_rate', '1')->set('values.attendance__late_penalty.amount_per_minute', '0')
            ->set('values.attendance__late_penalty.grace_minutes', '0')->set('values.attendance__late_penalty.max_amount', '0')
            ->call('saveRule', 'attendance.late_penalty')->assertHasNoErrors();

        // 10:10 instead of 09:30 = 40 late minutes × (10,000 ÷ 25 ÷ 9 ÷ 60 = 0.7407) = 29.63
        \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-15', 'check_in' => '2026-10-15 10:10:00', 'check_out' => '2026-10-15 18:30:00', 'status' => 'completed']);
        Carbon::setTestNow('2026-10-16 10:00:00');
        $people->assessCompliance('2026-10-15', '2026-10-15', $this->admin->id);
        $penalty = \App\Models\EmployeePenalty::where('employee_id', $e->id)->firstOrFail();
        $this->assertEquals(round(40 * 10000 / 25 / 9 / 60, 2), (float) $penalty->amount);
        $this->assertSame('Late by 40 minutes', $penalty->reason);
    }

    public function test_a_settled_leave_conflict_is_shown_to_the_employee(): void
    {
        $e = $this->employee('SWS-230', ['name' => 'Risha Gangrade']);
        $annual = \App\Models\LeaveType::where('name', 'Annual Leave')->firstOrFail();
        \App\Models\LeaveBalance::create(['employee_id' => $e->id, 'leave_type_id' => $annual->id, 'year' => \App\Support\LeaveYear::of('2026-10-14'), 'entitled_days' => 20, 'used_days' => 1]);
        $leave = \App\Models\LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $annual->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14', 'days' => 1, 'reason' => 'have work', 'status' => 'approved', 'decided_by' => $this->admin->id]);
        \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-14', 'check_in' => '2026-10-14 09:30:00', 'check_out' => '2026-10-14 18:30:00', 'status' => 'completed']);

        app(\App\Services\LeaveConflictService::class)->resolve($leave, '2026-10-14', 'worked', $this->admin->id);

        $this->assertStringContainsString('HR counted it as a working day and the leave day was returned to your balance', $e->user->notifications()->get()->last()->data['message']);
        Livewire::actingAs($e->user)->test(\App\Livewire\Leave\Requests::class)->assertSee('14 Oct: worked — counted as a working day, leave day returned to balance');
    }

    public function test_a_manager_sees_team_reports_and_an_employee_none(): void
    {
        $boss = $this->employee('EMP-02', ['name' => 'Khushboo Verma']);
        $risha = $this->employee('EMP-01', ['name' => 'Risha Gangrade', 'manager_id' => $boss->id]);
        $vijay = $this->employee('EMP-03', ['name' => 'Vijay Choudhary']);
        foreach ([$risha, $vijay] as $p) \App\Models\AttendanceSession::create(['employee_id' => $p->id, 'work_date' => '2026-10-14', 'check_in' => '2026-10-14 09:30:00', 'check_out' => '2026-10-14 18:30:00', 'status' => 'completed']);

        $this->actingAs($boss->user)->get(route('dashboard'))->assertSee(route('reports.show', 'attendance'), false)->assertDontSee(route('reports.show', 'payroll'), false);
        $this->get(route('reports.show', ['type' => 'attendance', 'from' => '2026-10-01', 'to' => '2026-10-15']))->assertOk()->assertSee('Risha Gangrade')->assertDontSee('Vijay Choudhary');
        $this->get(route('reports.show', 'payroll'))->assertForbidden();

        $this->actingAs($vijay->user)->get(route('reports.show', 'attendance'))->assertForbidden();
        $this->assertFalse(app(\App\Services\ReportService::class)->canView($vijay->user, 'productivity'), 'An employee without a team has no reports');
    }

    public function test_an_employees_sheet_is_never_imported_into_another_employee(): void
    {
        $neha = $this->employee('SWS-102', ['name' => 'Neha Joshi']);
        $arjun = $this->employee('SWS-101', ['name' => 'Arjun Verma']);
        $file = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('neha.csv', "Employee ID,Date,Status,Punch In Time,Punch Out Time\nSWS-102,2026-04-01,Present,10:00,19:00\nSWS-102,2026-04-02,Present,10:00,19:00\n");
        $import = app(\App\Services\ImportService::class);

        $r = $import->attendance($file(), $this->admin->id, false, $arjun->id);
        $this->assertSame('This sheet belongs to Neha Joshi (SWS-102), not Arjun Verma, so nothing was imported. Choose Neha Joshi — or fix the Employee ID in the file.', $r['errors'][0]);
        $this->assertSame(0, \App\Models\AttendanceSession::where('employee_id', $arjun->id)->count());

        $r = $import->attendance($file(), $this->admin->id, false, $neha->id);
        $this->assertSame(2, $r['created'], 'Her own code is fine');
    }

    public function test_an_employees_shift_dates_can_be_edited_on_the_roster(): void
    {
        $e = $this->employee('SWS-101', ['name' => 'Arjun Verma']);
        $general = \App\Models\Shift::create(['name' => 'General', 'start_time' => '09:30', 'end_time' => '18:30', 'is_active' => true]);
        $night = \App\Models\Shift::create(['name' => 'Night', 'start_time' => '21:00', 'end_time' => '06:00', 'is_active' => true]);
        $shifts = app(\App\Services\ShiftService::class);
        $shifts->assign([$e->id], $general, '2026-06-01', null, $this->admin->id);
        $shifts->assign([$e->id], $night, '2026-10-15', null, $this->admin->id);
        $shifts->assign([$e->id], $night, '2026-10-15', '2026-10-28', $this->admin->id);
        $this->assertSame([['General', '2026-06-01', '2026-10-14'], ['Night', '2026-10-15', null]],
            \App\Models\EmployeeShift::with('shift')->where('employee_id', $e->id)->orderBy('starts_on')->get()->map(fn ($a) => [$a->shift->name, $a->starts_on->toDateString(), $a->ends_on?->toDateString()])->all(),
            'Back-to-back Night periods are one period');

        // Edit: Night only 15–28 Oct, then General again.
        Livewire::actingAs($this->admin)->test(\App\Livewire\Attendance\Shifts::class)
            ->call('openTimeline', $e->id)->assertSee('Shift dates · Arjun Verma')->assertCount('shiftRows', 2)
            ->set('shiftRows.1.ends_on', '2026-10-28')->call('addShiftRow')->assertSet('shiftRows.2.starts_on', '2026-10-29')
            ->set('shiftRows.2.shift_id', (string) $general->id)->call('saveTimeline')->assertHasNoErrors();
        $fresh = new \App\Services\ShiftService(app(\App\Services\AuditLogger::class));
        $this->assertSame(['General', 'Night', 'Night', 'General'], [$fresh->shiftFor($e, '2026-10-14')?->name, $fresh->shiftFor($e, '2026-10-15')?->name, $fresh->shiftFor($e, '2026-10-28')?->name, $fresh->shiftFor($e, '2026-11-02')?->name]);
        $this->assertStringContainsString('updated your shifts: General 01 Jun–14 Oct 2026; Night 15 Oct–28 Oct 2026; General 29 Oct onwards', $e->user->notifications()->get()->last(fn ($n) => ($n->data['kind'] ?? '') === 'Shift')->data['message']);

        // Overlapping periods are refused with a clear message.
        Livewire::actingAs($this->admin)->test(\App\Livewire\Attendance\Shifts::class)
            ->call('openTimeline', $e->id)->set('shiftRows.1.ends_on', '2026-11-05')->call('saveTimeline')->assertHasErrors('shiftRows');
    }

    /** A workbook laid out like the old "Salary Sheet 2026-2027.xlsx": one sheet per month, columns A–AM, notes under rows. */
    private function oldSalaryWorkbook(): string
    {
        $headers = array_map(fn ($c) => [$c[1], 'text', 12], array_values(array_filter(\App\Services\LegacySalaryImport::COLUMNS, fn ($c) => ! in_array($c[0], ['AN', 'AO'], true))));
        $row = fn (array $v) => array_map(fn ($k) => $v[$k] ?? '', array_keys(array_filter(\App\Services\LegacySalaryImport::COLUMNS, fn ($c) => ! in_array($c[0], ['AN', 'AO'], true))));
        $khushboo = ['s_no' => 1, 'name' => 'Khushboo Verma', 'basic_salary' => 35000, 'salary_per_day' => 1400, 'total_monthly_days' => 30, 'total_working_days' => 25, 'per_hour_salary' => 155.5555556, 'per_minute_salary' => 2.592592593, 'present_days' => 14, 'leave_days' => 11, 'paid_leave' => 0, 'late_coming_minutes' => 751, 'late_and_hourly_leave_minutes' => 751, 'late_coming_deduction' => 1947.037037, 'total_payment_deduction' => 1947.037037, 'total_salary_paid_days' => 14, 'salary_before_adjustment' => 19600, 'salary_after_deduction' => 17652.96296, 'total_salary_with_bonus' => 17652.96296, 'salary_paid' => 13591.48, 'salary_remains' => 4061.482963, 'payment_date' => 46151, 'payment_status' => 'Partially Paid'];
        $abheeshri = ['s_no' => 2, 'name' => 'Abheeshri', 'basic_salary' => 8000, 'present_days' => 24, 'total_salary_paid_days' => 24, 'salary_paid' => 6562.96, 'salary_remains' => 0, 'payment_status' => 'Paid'];
        $water = ['s_no' => 3, 'name' => 'Drinking Water', 'total_working_days' => 25, 'leave_days' => 25, 'salary_paid' => 0, 'payment_status' => 'Unpaid'];
        $note = array_fill(0, 39, ''); $note[2] = 'Abhishree : 640 (2 Days salary deducted)';
        $side = array_fill(0, 39, ''); $side[35] = '3000 paid 12 Oct';

        return \App\Support\XlsxWorkbook::toTempFile([
            ['name' => 'April-2026', 'columns' => $headers, 'rows' => [$row($khushboo), $row($abheeshri), $note, $row($water)]],
            ['name' => 'May-2026', 'columns' => $headers, 'rows' => [$row(['name' => 'Khushboo Verma', 's_no' => 1, 'basic_salary' => 35000, 'present_days' => 18, 'salary_paid' => 25000, 'payment_status' => 'Paid']), $side]],
        ]);
    }

    public function test_old_salary_workbook_imports_every_month_as_written(): void
    {
        $k = $this->employee('EMP-11', ['name' => 'Khushboo Verma']);
        $a = $this->employee('EMP-12', ['name' => 'Abheeshri Parmar']);
        $file = fn () => new \Illuminate\Http\UploadedFile($this->oldSalaryWorkbook(), 'Salary Sheet 2026-2027.xlsx', null, null, true);
        $r = app(\App\Services\LegacySalaryImport::class)->import($file(), $this->admin->id);

        $this->assertSame([3, 1, ['2026-04', '2026-05']], [$r['created'], $r['skipped'], $r['months']], json_encode($r));
        $this->assertSame(['code' => 0, 'name' => 3, 'none' => 1], $r['matched'], 'Khushboo (twice) and "Abheeshri" → Abheeshri Parmar');
        $this->assertStringContainsString('"Drinking Water" is not an HRMS employee — 1 row(s) not imported (April-2026 row 5)', $r['errors'][0]);
        $april = \App\Models\LegacySalaryRecord::where('payroll_month', '2026-04')->where('employee_id', $k->id)->firstOrFail();
        $this->assertEquals([35000, 1947.037037, 4061.482963, '2026-05-09', 'Partially Paid'], [(float) $april->basic_salary, round((float) $april->late_coming_deduction, 6), round((float) $april->salary_remains, 6), $april->payment_date->toDateString(), $april->payment_status], 'Stored as written, not rounded');
        $this->assertSame('751', $april->raw['Late coming minutes']);
        $this->assertSame('Abhishree : 640 (2 Days salary deducted)', \App\Models\LegacySalaryRecord::where('employee_id', $a->id)->value('notes'), 'A line under a row is that row\'s note');
        $this->assertSame('3000 paid 12 Oct', \App\Models\LegacySalaryRecord::where('payroll_month', '2026-05')->value('notes'));
        $this->assertSame(0, \App\Models\LegacySalaryRecord::where('name', 'Drinking Water')->count(), 'Only HRMS employees are saved');
        $this->assertSame(0, \App\Models\LegacySalaryRecord::whereNull('employee_id')->count());

        $again = app(\App\Services\LegacySalaryImport::class)->import($file(), $this->admin->id);
        $this->assertSame([0, 3], [$again['created'], $again['kept']], 'Re-import keeps rows unless asked to replace');
        $this->actingAs($this->admin)->get(route('payroll.legacy'))->assertOk()->assertSee('Khushboo Verma')->assertSee('Apr 2026')->assertSee('4,061.48');
        Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\LegacySalary::class)->call('open', $april->id)->assertSee('per minute salary', false)->assertSee('2.592593');
    }

    public function test_old_salary_sheet_uploaded_on_the_salary_page_goes_to_legacy_salary(): void
    {
        $this->employee('EMP-11', ['name' => 'Khushboo Verma']);
        $this->employee('EMP-12', ['name' => 'Abheeshri Parmar']);
        $file = new \Illuminate\Http\UploadedFile($this->oldSalaryWorkbook(), 'Salary Sheet 2026-2027.xlsx', null, null, true);
        $this->actingAs($this->admin)->from(route('payroll.salary'))->post(route('payroll.salary.import'), ['file' => $file])->assertRedirect(route('payroll.salary'));
        $r = session('import_result');
        $this->assertSame(['Salary', 3], [$r['kind'], $r['created']], json_encode($r));
        $this->assertStringContainsString('old month-wise salary sheet', $r['notes'][0]);
        $this->assertSame(0, \App\Models\SalaryProfile::count(), 'Salary profiles are not touched');
        $this->get(route('payroll.salary'))->assertSee('Open Legacy Salary');
    }

    public function test_legacy_salary_can_be_filtered_by_leave(): void
    {
        $a = $this->employee('EMP-81', ['name' => 'Asha']);
        $b = $this->employee('EMP-82', ['name' => 'Bina']);
        $base = ['payroll_month' => '2026-04', 'basic_salary' => 10000];
        \App\Models\LegacySalaryRecord::create($base + ['employee_id' => $a->id, 'name' => 'Asha', 'name_key' => 'asha', 'leave_days' => 3, 'without_application_leave' => 2]);
        \App\Models\LegacySalaryRecord::create($base + ['employee_id' => $b->id, 'name' => 'Bina', 'name_key' => 'bina', 'leave_days' => 0, 'without_application_leave' => 0]);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\LegacySalary::class)
            ->assertSee('Asha')->assertSee('Bina')->assertSee('Leave days')
            ->set('leave', 'without_application')->assertViewHas('records', fn ($r) => $r->pluck('name')->all() === ['Asha'])
            ->set('leave', 'none')->assertViewHas('records', fn ($r) => $r->pluck('name')->all() === ['Bina'])
            ->set('leave', 'taken')->assertViewHas('records', fn ($r) => $r->pluck('name')->all() === ['Asha']);
    }

    public function test_legacy_salary_template_csv_and_flat_month_column(): void
    {
        foreach (['Khushboo Verma', 'Vijay Choudhary', 'Risha Gangrade'] as $i => $n) $this->employee('EMP-'.(60 + $i), ['name' => $n]);
        $this->actingAs($this->admin)->get(route('imports.template', 'legacy-salary'))->assertOk();
        $csv = $this->get(route('imports.template', ['legacy-salary', 'format' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('Khushboo Verma', $csv);
        $this->assertStringContainsString('Salary par day (C/F)', $csv);
        $this->assertStringContainsString(',2026-04', $csv);

        $path = tempnam(sys_get_temp_dir(), 'leg').'.csv';
        $bad = array_fill(0, 41, ''); $bad[0] = 4; $bad[1] = 'Bad Month'; $bad[2] = 1000; $bad[40] = '2026-13';
        $badNumber = array_fill(0, 41, ''); $badNumber[0] = 5; $badNumber[1] = 'Bad Number'; $badNumber[2] = 'abc'; $badNumber[40] = '2026-04';
        file_put_contents($path, $csv.implode(',', $bad)."\n".implode(',', $badNumber)."\n");
        $r = app(\App\Services\LegacySalaryImport::class)->import(new \Illuminate\Http\UploadedFile($path, 'flat.csv', null, null, true), $this->admin->id);
        $this->assertSame(3, $r['created'], json_encode($r));
        $errors = implode(' ', $r['errors']);
        $this->assertStringContainsString('Payroll Month "2026-13" must be YYYY-MM', $errors);
        $this->assertStringContainsString('Basic salary "abc" is not a number', $errors);
        $this->assertSame(1947.037037, round((float) \App\Models\LegacySalaryRecord::where('name', 'Khushboo Verma')->value('late_coming_deduction'), 6));
    }

    public function test_productivity_does_not_count_a_timer_left_running_for_days(): void
    {
        $e = $this->employee('EMP-1');
        $task = Task::create(['title' => 'Stale', 'assignee_id' => $e->id, 'status' => 'in_progress', 'priority' => 'normal']);
        \App\Models\AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-12', 'check_in' => '2026-10-12 09:00:00', 'check_out' => '2026-10-12 17:00:00', 'status' => 'completed', 'active_seconds' => 8 * 3600]);
        \App\Models\TaskTimeEntry::create(['task_id' => $task->id, 'employee_id' => $e->id, 'started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-14 10:00:00', 'duration_seconds' => 48 * 3600, 'status' => 'stopped']);
        $row = app(\App\Services\WorkService::class)->productivity(collect([$e]), '2026-10-01', '2026-10-15')->first();
        $this->assertSame(8 * 3600, $row->logged_seconds, 'Capped at the attendance of that day');
        $this->assertSame(40 * 3600, $row->trimmed_seconds);
    }

    public function test_document_upload_form_submits_to_the_server_and_preselects_the_filtered_employee(): void
    {
        $e = $this->employee('EMP-1');
        Livewire::actingAs($this->admin)->withQueryParams(['employee' => $e->id])->test(\App\Livewire\Lifecycle\Documents::class)
            ->call('create')->assertSet('employeeId', $e->id)
            ->assertSeeHtml('wire:submit="saveDocument"')
            ->call('saveDocument')->assertHasErrors(['file']);
    }
}
