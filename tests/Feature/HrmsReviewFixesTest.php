<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Dashboard as TeamAttendance;
use App\Livewire\Hr\Dashboard;
use App\Livewire\Lifecycle\Assets;
use App\Livewire\Lifecycle\Documents;
use App\Livewire\People\Employees;
use App\Livewire\Work\Tasks;
use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\EmployeeAsset;
use App\Models\EmployeeDocument;
use App\Models\EmployeePolicyOverride;
use App\Models\ExpenseClaim;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\SalaryProfile;
use App\Models\Task;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\AttendanceCorrectionService;
use App\Services\AttendanceService;
use App\Services\ExpenseService;
use App\Services\HrWorkflowService;
use App\Services\ImportService;
use App\Services\PeopleOperationsService;
use App\Services\WorkService;
use App\Support\Duration;
use App\Support\WorkCalendar;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** The HRMS review fixes, module by module (one test per area of the review). */
class HrmsReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $hr;
    private User $leadUser;
    private AttendanceEmployee $lead;
    private User $employeeUser;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-14 15:00:00'); // Wednesday
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->hr = User::factory()->create(['email' => 'rv.hr@example.com']);
        $this->hr->assignRole('HR');
        // A team lead with only the Employee role: approver for their reports.
        $this->leadUser = User::factory()->create(['email' => 'rv.lead@example.com', 'name' => 'Lata Lead']);
        $this->leadUser->assignRole('Employee');
        $this->lead = AttendanceEmployee::create(['employee_code' => 'RV-LEAD', 'name' => 'Lata Lead', 'email' => 'rv.lead@example.com', 'is_active' => true, 'user_id' => $this->leadUser->id, 'joined_at' => '2026-01-01']);
        $this->employeeUser = User::factory()->create(['email' => 'rv.emp@example.com', 'name' => 'Ravi Report']);
        $this->employeeUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'RV-EMP', 'name' => 'Ravi Report', 'email' => 'rv.emp@example.com', 'is_active' => true, 'user_id' => $this->employeeUser->id, 'manager_id' => $this->lead->id, 'joined_at' => '2026-01-01', 'work_start' => '09:30:00', 'daily_work_hours' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function notificationsOf(User $user, string $kind): \Illuminate\Support\Collection
    {
        return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === $kind)->values();
    }

    // 1. Employee management -------------------------------------------------------------

    public function test_employee_list_shows_email_and_creation_never_500s_when_email_fails(): void
    {
        // An SMTP failure after the employee is saved used to surface as "500 Server Error".
        $this->mock(MailChannel::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new \RuntimeException('SMTP connection refused')));

        Livewire::actingAs($this->admin)->test(Employees::class)
            ->call('create')
            ->set('employeeCode', 'RV-NEW')->set('name', 'Nina New')->set('email', 'rv.new@example.com')->set('password', 'Password12345')
            ->set('managerId', $this->lead->id)->set('monthlySalary', '30000')->set('dailyHours', '9')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('rv.new@example.com')   // email column
            ->assertSee('09:00:00');            // working hours column

        $new = AttendanceEmployee::where('employee_code', 'RV-NEW')->firstOrFail();
        $this->assertSame(9.0, (float) $new->salaryProfile->standard_daily_hours, 'Hours entered at creation reach the salary record');
        $this->assertCount(1, $this->notificationsOf($this->leadUser, 'ManagerAssigned'), 'The new manager is told (in-app) even though e-mail failed');
        $this->assertTrue(AuditLog::where('action', 'notification.email_failed')->exists(), 'The e-mail failure is recorded for Settings > E-mail');
    }

    public function test_employee_hours_win_over_the_company_default_in_attendance_and_payroll(): void
    {
        app(PeopleOperationsService::class)->setPolicy('work.hours', 'attendance', 'Work hours', ['standard_daily_hours' => 8], null, $this->admin->id, '2026-01-01');
        $this->employee->update(['daily_work_hours' => 9]);
        $attendance = app(AttendanceService::class);
        $this->assertSame(9.0, $attendance->dailyHours($this->employee));
        $this->assertSame(9 * 3600, $attendance->rules($this->employee, '2026-10-14')['daily_seconds']);

        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = $run->items()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame(9.0, (float) $item->calculation_breakdown['employer_policy']['standard_daily_hours'], 'Payroll uses the same working day as attendance');

        // Editing the employee keeps the current salary record in step.
        app(\App\Services\EmployeeService::class)->update($this->employee->fresh(), ['daily_work_hours' => 7.5], $this->admin);
        $this->assertSame(7.5, (float) $this->employee->salaryProfiles()->latest('effective_from')->first()->standard_daily_hours);
    }

    // 2. Manager assignment, tasks and projects ------------------------------------------

    public function test_task_assignment_shows_assigner_and_completion_notifies_them_and_stays_listed(): void
    {
        $task = app(WorkService::class)->saveTask(null, ['title' => 'Prepare release notes', 'assignee_id' => $this->employee->id, 'priority' => 'high', 'status' => 'todo', 'due_on' => '2026-10-14'], $this->leadUser);
        $assigned = $this->notificationsOf($this->employeeUser, 'TaskAssignment')->first();
        $this->assertStringContainsString('Lata Lead assigned you a task', $assigned->data['message']);

        // It is in My Tasks and in the Task Timer picker straight away.
        Livewire::actingAs($this->employeeUser)->withQueryParams([])->test(Tasks::class)->assertSee('Prepare release notes')->assertSee('Assigned by Lata Lead');
        Livewire::actingAs($this->employeeUser)->test(\App\Livewire\Work\Timer::class)->assertSee('Prepare release notes');

        app(HrWorkflowService::class)->completeTask($this->employee, $task);
        $done = $this->notificationsOf($this->leadUser, 'TaskCompleted')->first();
        $this->assertNotNull($done, 'The person who assigned the task is told');
        $this->assertStringContainsString('completed the task "Prepare release notes"', $done->data['message']);

        // Completed work stays visible with a Completed badge (default filter is "All"), with its history.
        Livewire::actingAs($this->employeeUser)->test(Tasks::class)->assertSee('Prepare release notes')->assertSee('Completed')
            ->call('open', $task->id)->assertSee('History')->assertSee('Completed');
        $this->assertTrue(AuditLog::where('subject_type', Task::class)->where('subject_id', $task->id)->where('action', 'task.completed')->exists());
    }

    public function test_project_members_are_notified_and_employees_see_their_projects(): void
    {
        $project = app(WorkService::class)->saveProject(null, ['name' => 'Atlas', 'manager_id' => $this->lead->id, 'status' => 'active'], $this->admin);
        $this->assertCount(1, $this->notificationsOf($this->leadUser, 'ProjectManager'), 'The assigned project manager is told');
        app(WorkService::class)->setProjectMembers($project, [$this->employee->id], $this->admin);
        $this->assertStringContainsString('added you to the project "Atlas"', $this->notificationsOf($this->employeeUser, 'ProjectMember')->first()->data['message']);
        $this->assertNotEmpty($this->notificationsOf($this->leadUser, 'ProjectMember'), 'The project manager hears about the new member');

        $this->actingAs($this->employeeUser)->get(route('work.my-projects'))->assertOk()->assertSee('Atlas');
        $this->actingAs($this->employeeUser)->get(route('work.projects'))->assertForbidden();
    }

    // 3. Dashboard & time display ----------------------------------------------------------

    public function test_dashboard_counts_checked_in_out_and_lunch_and_todays_task_percentage(): void
    {
        $other = AttendanceEmployee::create(['employee_code' => 'RV-O', 'name' => 'Omar', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $third = AttendanceEmployee::create(['employee_code' => 'RV-T', 'name' => 'Tara', 'is_active' => true, 'joined_at' => '2026-01-01']);
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-14', 'check_in' => '2026-10-14 09:30:00', 'status' => 'working']);
        AttendanceSession::create(['employee_id' => $other->id, 'work_date' => '2026-10-14', 'check_in' => '2026-10-14 09:00:00', 'check_out' => '2026-10-14 13:00:00', 'active_seconds' => 14400, 'status' => 'completed']);
        AttendanceSession::create(['employee_id' => $third->id, 'work_date' => '2026-10-14', 'check_in' => '2026-10-14 09:00:00', 'status' => 'on_break']);
        Task::create(['title' => 'Done today', 'assignee_id' => $this->employee->id, 'status' => 'completed', 'completed_at' => '2026-10-14 11:00:00', 'priority' => 'normal']);
        Task::create(['title' => 'Due today', 'assignee_id' => $this->employee->id, 'status' => 'todo', 'due_on' => '2026-10-14', 'priority' => 'normal']);
        Task::create(['title' => 'Due next week', 'assignee_id' => $this->employee->id, 'status' => 'todo', 'due_on' => '2026-10-21', 'priority' => 'normal']);

        $metrics = Livewire::actingAs($this->admin)->test(Dashboard::class)->assertSee('Checked out today')->assertSee('On lunch / break now')->viewData('metrics');
        $this->assertSame([3, 1, 1, 50], [$metrics['checked_in'], $metrics['checked_out'], $metrics['on_break'], $metrics['task_completion_pct']]);
    }

    public function test_durations_are_hh_mm_ss_and_idle_follows_the_desktop_heartbeat(): void
    {
        $this->assertSame('30:00:05', Duration::hms(30 * 3600 + 5), 'Hours never wrap at 24');
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-13', 'check_in' => '2026-10-13 09:00:00', 'check_out' => '2026-10-13 17:00:00', 'active_seconds' => 27000, 'break_seconds' => 1800, 'screen_idle_seconds' => 125, 'status' => 'completed']);
        $this->actingAs($this->admin)->get(route('attendance.records', ['from' => '2026-10-13', 'to' => '2026-10-13']))->assertOk()->assertSee('07:30:00')->assertSee('00:02:05');
        $row = app(\App\Services\ReportService::class)->rows($this->admin, 'attendance', ['from' => '2026-10-13', 'to' => '2026-10-13'])->first();
        $this->assertSame(['07:30:00', '00:02:05', '00:30:00'], [$row['active_time'], $row['idle_time'], $row['break_time']], 'Reports match the attendance screens');
    }

    // 4. Corrections & forced check-out ----------------------------------------------------

    public function test_attendance_correction_reaches_the_admin_and_the_manager(): void
    {
        app(AttendanceCorrectionService::class)->request($this->employee, '2026-10-13', '09:30', '18:30', 'Forgot to check in on the app');
        $this->assertNotEmpty($this->notificationsOf($this->admin, 'AttendanceCorrection'), 'Admin is notified');
        $this->assertNotEmpty($this->notificationsOf($this->leadUser, 'AttendanceCorrection'), 'Manager is notified');
        $this->assertSame(1, app(ApprovalInbox::class)->pending($this->admin, \App\Models\AttendanceCorrection::class)->count(), 'It is in the admin approval inbox');
        $this->assertSame(1, app(ApprovalInbox::class)->pending($this->leadUser, \App\Models\AttendanceCorrection::class)->count(), 'And in the team lead inbox');
    }

    public function test_forced_check_out_needs_a_reason_is_audited_notified_and_final(): void
    {
        Carbon::setTestNow('2026-10-14 09:30:00');
        $session = app(AttendanceService::class)->checkIn($this->employee, 'web');
        Carbon::setTestNow('2026-10-14 19:00:00');

        Livewire::actingAs($this->admin)->test(TeamAttendance::class)->call('openClose', $session->id)->set('closeTime', '18:30')->call('closeSession')->assertHasErrors(['closeReason']);
        Livewire::actingAs($this->admin)->test(TeamAttendance::class)->call('openClose', $session->id)->set('closeTime', '18:30')->set('closeReason', 'other')->call('closeSession')->assertHasErrors(['closeNote']);
        Livewire::actingAs($this->admin)->test(TeamAttendance::class)->call('openClose', $session->id)->set('closeTime', '18:30')->set('closeReason', 'forgot')->call('closeSession')->assertHasNoErrors();

        $session->refresh();
        $this->assertSame(['18:30', 'forgot', $this->admin->id], [$session->check_out->format('H:i'), $session->forced_checkout_reason, $session->forced_checkout_by]);
        $audit = AuditLog::where('action', 'attendance.forced_checkout')->firstOrFail();
        $this->assertSame([$this->admin->name, 'Ravi Report', 'Employee forgot to check out', '2026-10-14 18:30:00'], [$audit->metadata['performed_by'], $audit->metadata['employee'], $audit->metadata['reason'], $audit->metadata['check_out']]);
        $this->assertStringContainsString('Reason: Employee forgot to check out', $this->notificationsOf($this->employeeUser, 'AttendanceSession')->first()->data['message']);
        $this->assertStringContainsString('checked out Ravi Report at', $this->notificationsOf($this->leadUser, 'AttendanceSession')->first()->data['message']);

        try {
            app(AttendanceService::class)->checkOut($this->employee, 'web');
            $this->fail('A second check-out must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('You cannot check out again today', $e->getMessage());
        }
        $this->actingAs($this->employeeUser)->get(route('attendance.my'))->assertOk()->assertSee('Reason: Employee forgot to check out');
    }

    // 5. Leave & weekly offs ---------------------------------------------------------------

    public function test_third_saturday_employee_second_saturday_and_moved_weekly_off(): void
    {
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('calendar.working_days', 'calendar', 'Working calendar', WorkCalendar::normalise(['days' => [1, 2, 3, 4, 5, 6], 'holidays' => [], 'off_weeks' => ['6' => [3]], 'shifts' => [['from' => '2026-10-25', 'to' => '2026-10-26', 'note' => 'Release weekend']]]), null, $this->admin->id, '2026-01-01');
        $calendar = app(\App\Services\PolicyConfigurationService::class)->forEmployee($this->employee, 'calendar.working_days', '2026-10-14');
        $this->assertTrue(WorkCalendar::isWorkday('2026-10-10', $calendar), '2nd Saturday is a working day for the company');
        $this->assertFalse(WorkCalendar::isWorkday('2026-10-17', $calendar), '3rd Saturday is off');
        $this->assertTrue(WorkCalendar::isWorkday('2026-10-25', $calendar), 'Sunday moved to a working day');
        $this->assertFalse(WorkCalendar::isWorkday('2026-10-26', $calendar), 'Monday is the moved off day');

        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $leave = app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-16'), 'ends_on' => Carbon::parse('2026-10-19'), 'reason' => 'Family function']);
        $this->assertEquals(2, (float) $leave->days, 'Fri + Mon; the 3rd Saturday and Sunday are not charged');

        // This employee is also off on the 2nd Saturday.
        EmployeePolicyOverride::create(['employee_id' => $this->lead->id, 'rule_key' => 'calendar.working_days', 'effective_from' => null, 'configuration' => ['off_weeks' => ['6' => [2, 3]]]]);
        $leadLeave = app(HrWorkflowService::class)->requestLeave($this->lead, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-09'), 'ends_on' => Carbon::parse('2026-10-10'), 'reason' => 'Trip']);
        $this->assertEquals(1, (float) $leadLeave->days, 'Only Friday: their 2nd Saturday is off');

        // Settings page shows and saves the weekly-off rules.
        Livewire::actingAs($this->admin)->withQueryParams(['tab' => 'calendar'])->test(\App\Livewire\System\Settings::class)->assertSee('Weekly offs on some weeks only')->assertSee('Release weekend')->assertSee('Lata Lead');
    }

    public function test_weekly_off_checkboxes_are_independent_and_employees_are_told(): void
    {
        // Ticking only the 3rd Saturday saves only the 3rd Saturday (it used to tick every week).
        Livewire::actingAs($this->admin)->withQueryParams(['tab' => 'calendar'])->test(\App\Livewire\System\Settings::class)
            ->set('workdays', [1, 2, 3, 4, 5, 6])->assertSet('offWeeks.6', [])
            ->set('offWeeks.6', ['3'])->call('saveCalendar')->assertHasNoErrors()->assertSet('offWeeks.6', ['3']);
        $calendar = app(\App\Services\PolicyConfigurationService::class)->forEmployee($this->employee, 'calendar.working_days', '2026-10-14');
        $this->assertSame(['6' => [3]], $calendar['off_weeks']);

        $told = $this->notificationsOf($this->employeeUser, 'WeeklyOff')->first();
        $this->assertStringContainsString('off on the 3rd Saturday', $told->data['message']);
        $this->assertStringContainsString('Sat 17 Oct', $told->data['message']);

        // Shown on the Holidays page and in My Attendance, with a reminder the evening before.
        $this->actingAs($this->employeeUser)->get(route('company.holidays'))->assertOk()->assertSee('Upcoming weekly offs')->assertSee('3rd Saturday — weekly off');
        Carbon::setTestNow('2026-10-16 17:00:00');
        $this->assertSame(2, app(\App\Services\CompanyService::class)->remindTomorrowOff(), 'The two employees with a login are reminded');
        $this->assertTrue($this->notificationsOf($this->employeeUser, 'WeeklyOff')->contains(fn ($n) => str_contains($n->data['message'], 'tomorrow, Saturday 17 Oct, is your day off')));
        $this->assertSame(0, app(\App\Services\CompanyService::class)->remindTomorrowOff(), 'Only once');
        Carbon::setTestNow('2026-10-19 10:00:00');
        $this->actingAs($this->employeeUser)->get(route('attendance.my', ['month' => '2026-10']))->assertSee('Weekly off')->assertSee('3rd Saturday — weekly off');
    }

    public function test_leave_in_a_locked_period_without_pay_for_the_employee_is_approved_without_carry_over(): void
    {
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $leave = app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-09-10'), 'ends_on' => Carbon::parse('2026-09-10'), 'reason' => 'Doctor']);
        Carbon::setTestNow('2026-10-14 15:00:00');
        PayrollRun::create(['period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'locked', 'created_by' => $this->admin->id]);
        $this->assertSame('approved', app(HrWorkflowService::class)->decideLeave($leave, $this->leadUser->id, true)->status);
        $this->assertSame(0, \App\Models\SalaryAdjustment::where('employee_id', $this->employee->id)->count(), 'Not in that payroll: nothing to carry');
    }

    // 6. Imports -----------------------------------------------------------------------------

    public function test_salary_excel_import_keeps_existing_records_and_explains_errors(): void
    {
        $file = fn (array $rows) => new UploadedFile(XlsxWriter::toTempFile(ImportService::SALARY_COLUMNS, $rows), 'salary.xlsx', null, null, true);
        $import = app(ImportService::class);

        $first = $import->salary($file([['RV-EMP', '30000', '01-09-2026', '', '9'], ['NOPE', '1000', '2026-09-01', '', ''], ['RV-LEAD', '40000', '31-02-2026', '', '']]), $this->admin->id);
        $this->assertSame([1, 0, 2], [$first['created'], $first['kept'], $first['skipped']]);
        $this->assertStringContainsString('No employee with Employee ID "NOPE"', implode(' ', $first['errors']));
        $this->assertStringContainsString('effective_from "31-02-2026" is not a valid date', implode(' ', $first['errors']));
        $this->assertSame(9.0, (float) $this->employee->fresh()->daily_work_hours);

        $again = $import->salary($file([['RV-EMP', '35000', '2026-09-01', '', '']]), $this->admin->id);
        $this->assertSame([0, 0, 1], [$again['created'], $again['updated'], $again['kept']], 'Existing record kept by default');
        $this->assertSame(30000.0, (float) SalaryProfile::where('employee_id', $this->employee->id)->first()->monthly_salary);

        $update = $import->salary($file([['RV-EMP', '35000', '2026-09-01', '', '']]), $this->admin->id, true);
        $this->assertSame(1, $update['updated']);
        $this->assertSame(35000.0, (float) SalaryProfile::where('employee_id', $this->employee->id)->first()->monthly_salary);
    }

    public function test_attendance_csv_import_understands_indian_dates_and_am_pm_times(): void
    {
        $csv = "Employee Code,Date,In Time,Out Time,Break\nRV-EMP,05/10/2026,9:30 AM,6:30 PM,00:45\nRV-EMP,06-10-2026,25:00,18:00,\n";
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $csv);
        $result = app(ImportService::class)->attendance(new UploadedFile($path, 'attendance.csv', 'text/csv', null, true), $this->admin->id);
        $this->assertSame([1, 1], [$result['created'], $result['skipped']]);
        $this->assertStringContainsString('check_in "25:00" is not a valid time', $result['errors'][0]);
        $session = AttendanceSession::where('employee_id', $this->employee->id)->whereDate('work_date', '2026-10-05')->firstOrFail();
        $this->assertSame(['09:30', '18:30', 8 * 3600 + 15 * 60], [$session->check_in->format('H:i'), $session->check_out->format('H:i'), (int) $session->active_seconds]);

        $this->actingAs($this->admin)->get(route('imports.template', 'attendance'))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($this->admin)->post(route('attendance.records.import'), ['file' => UploadedFile::fake()->create('old.xls', 10)])->assertSessionHas('import_result', fn ($r) => str_contains($r['errors'][0], '.xlsx'));
    }

    // 7. Project-wise daily status ----------------------------------------------------------

    public function test_daily_status_is_project_wise_and_notifies_manager_and_project_manager(): void
    {
        $pmUser = User::factory()->create(['email' => 'rv.pm@example.com']);
        $pmUser->assignRole('Employee');
        $pm = AttendanceEmployee::create(['employee_code' => 'RV-PM', 'name' => 'Priya PM', 'is_active' => true, 'user_id' => $pmUser->id, 'joined_at' => '2026-01-01']);
        $project = Project::create(['name' => 'Atlas', 'manager_id' => $pm->id, 'status' => 'active']);
        $project->members()->attach($this->employee->id);
        Task::create(['title' => 'Build login', 'assignee_id' => $this->employee->id, 'project_id' => $project->id, 'status' => 'in_progress', 'priority' => 'normal']);

        Livewire::actingAs($this->employeeUser)->test(\App\Livewire\Work\DailyStatus::class)
            ->assertSee('Project &amp; task-wise status', false)->assertSee('Atlas')->assertSee('Build login')
            ->set('summary', 'Login screen mostly done')->set('projectUpdates.'.$project->id, 'Finished the form validation')
            ->call('save')->assertHasNoErrors();

        $status = \App\Models\DailyWorkStatus::firstOrFail();
        $this->assertSame(['Atlas', 'Finished the form validation', 'Build login'], [$status->project_updates[0]['project'], $status->project_updates[0]['update'], $status->project_updates[0]['tasks'][0]['title']]);
        $this->assertStringContainsString('Atlas: Finished the form validation', $this->notificationsOf($this->leadUser, 'DailyStatusSubmitted')->first()->data['message']);
        $this->assertNotEmpty($this->notificationsOf($pmUser, 'DailyStatusSubmitted'), 'The project manager is told');
        $this->assertSame('work', \App\Notifications\ApprovalRequested::groupFor('DailyStatusSubmitted'), 'E-mailed by default (work group)');

        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\DailyStatus::class)->set('project', $project->id)->assertSee('Finished the form validation');
    }

    // 8. Announcements ----------------------------------------------------------------------

    public function test_deleted_announcement_disappears_from_notifications_and_empty_state_is_clear(): void
    {
        $announcement = app(\App\Services\CompanyService::class)->saveAnnouncement(['title' => 'Diwali party', 'body' => 'Friday 5 PM', 'audience' => 'all'], $this->admin);
        $this->assertCount(1, $this->notificationsOf($this->employeeUser, 'Announcement'));
        app(\App\Services\CompanyService::class)->deleteAnnouncement($announcement);
        $this->assertCount(0, $this->notificationsOf($this->employeeUser, 'Announcement'));
        $this->actingAs($this->employeeUser)->get(route('dashboard'))->assertOk()->assertSee('No announcements');
    }

    // 9. Passwords ---------------------------------------------------------------------------

    public function test_only_an_administrator_can_reset_an_employee_password(): void
    {
        Livewire::actingAs($this->hr)->test(Employees::class)->assertDontSee('Reset password')->call('openPassword', $this->employee->id)->assertForbidden();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\EmployeeService::class)->resetPassword($this->employee, 'NewPassword12345', $this->hr);
    }

    // 11. Documents --------------------------------------------------------------------------

    public function test_document_upload_validates_clearly_and_then_succeeds(): void
    {
        Storage::fake('local');
        Livewire::actingAs($this->admin)->test(Documents::class)
            ->call('create')->call('saveDocument')->assertHasErrors(['employeeId', 'file'])->assertSee('Choose the employee')->assertSee('Choose a file to upload')
            ->set('employeeId', $this->employee->id)->set('file', UploadedFile::fake()->create('contract.exe', 10))->assertHasErrors(['file'])
            ->set('file', UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'))->call('saveDocument')->assertHasNoErrors()->assertSet('showForm', false);
        $document = EmployeeDocument::firstOrFail();
        $this->assertSame('contract.pdf', $document->original_name);
        Storage::disk('local')->assertExists($document->storage_path);
    }

    // 12. Assets -----------------------------------------------------------------------------

    public function test_returned_asset_keeps_its_history_for_employee_and_admin(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Assets::class)
            ->call('create')->set('employeeId', $this->employee->id)->set('name', 'Dell Latitude')->set('assetTag', 'LAP-77')->call('assign')->assertHasNoErrors();
        $asset = EmployeeAsset::where('asset_tag', 'LAP-77')->firstOrFail();
        $component->call('openReturn', $asset->id)->set('returnNotes', 'Good condition')->call('markReturned')->assertSee('LAP-77')->assertSee('Returned');
        Livewire::actingAs($this->employeeUser)->test(Assets::class)->assertSee('LAP-77')->assertSee('Returned');

        Livewire::actingAs($this->admin)->test(Assets::class)->call('openReissue', $asset->id)->set('reissueEmployeeId', $this->lead->id)->call('reissue')->assertHasNoErrors();
        Livewire::actingAs($this->employeeUser)->test(Assets::class)->assertSee('LAP-77')->assertSee('by you earlier');
        $this->assertSame(['assigned', 'returned', 'assigned'], $asset->events()->reorder('id')->pluck('action')->all());
    }

    // 13. Expenses ---------------------------------------------------------------------------

    public function test_expense_claim_reaches_manager_and_admin_team_lead_can_act_and_cancel_is_notified(): void
    {
        $claim = app(ExpenseService::class)->submit($this->employee, ['category' => 'Travel', 'spent_on' => '2026-10-13', 'amount' => 450, 'description' => 'Client visit cab']);
        $this->assertNotEmpty($this->notificationsOf($this->leadUser, 'ExpenseClaim'), 'Manager notified');
        $this->assertNotEmpty($this->notificationsOf($this->admin, 'ExpenseClaim'), 'Admin notified');

        // The team lead holds no approval permission but is the named approver: no more 403.
        $this->actingAs($this->leadUser)->get(route('approvals.pending'))->assertOk()->assertSee('Ravi Report');
        $this->actingAs($this->leadUser)->get(route('expenses.index', ['tab' => 'approvals']))->assertOk()->assertSee('Client visit cab');
        $this->assertTrue(app(ExpenseService::class)->canView($this->leadUser, $claim));

        $second = app(ExpenseService::class)->submit($this->employee, ['category' => 'Meals', 'spent_on' => '2026-10-13', 'amount' => 120, 'description' => 'Team lunch']);
        app(ExpenseService::class)->cancel($second, $this->employee);
        $this->assertTrue($this->notificationsOf($this->leadUser, 'ExpenseClaim')->contains(fn ($n) => str_contains($n->data['message'], 'cancelled their expense claim')));

        $approval = ApprovalRequest::where('approvable_type', ExpenseClaim::class)->where('approvable_id', $claim->id)->firstOrFail();
        app(ApprovalInbox::class)->decide($this->leadUser, $approval, true);
        $this->assertSame('approved', $claim->fresh()->status);
    }

    // 14. Audit logs -------------------------------------------------------------------------

    public function test_audit_logs_are_grouped_by_category_with_filters(): void
    {
        foreach (['employee.asset_returned', 'employee.updated', 'attendance.forced_checkout', 'payroll.locked', 'task.completed', 'leave.approved', 'expense.submitted', 'policy.updated'] as $action) {
            AuditLog::create(['actor_id' => $this->admin->id, 'action' => $action, 'created_at' => now()]);
        }
        $this->assertSame(['employee.asset_returned'], AuditLog::query()->category('assets')->where('action', 'not like', 'user.%')->pluck('action')->all());
        $this->assertNotContains('employee.asset_returned', AuditLog::query()->category('employee')->pluck('action')->all());
        $this->assertContains('policy.updated', AuditLog::query()->category('system')->pluck('action')->all());
        Livewire::actingAs($this->admin)->test(\App\Livewire\Security\AuditLogs::class)->call('setCategory', 'payroll')->assertSee('payroll.locked')->assertDontSee('leave.approved')
            ->call('setCategory', '')->set('search', 'leave')->assertSee('leave.approved')->assertDontSee('payroll.locked');
    }

    // 16. Offboarding ------------------------------------------------------------------------

    public function test_offboarding_can_be_cancelled_within_the_window_with_an_audit_trail(): void
    {
        $people = app(PeopleOperationsService::class);
        $case = $people->openLifecycle($this->employee, 'offboarding', '2026-10-31', 'Opened by mistake', $this->admin->id, ['type' => 'resignation', 'resigned_on' => '2026-10-14']);
        $this->assertTrue($people->canCorrectOffboarding($this->hr, $case), 'HR may fix it inside the window');

        Carbon::setTestNow('2026-10-20 15:00:00'); // past the default 72-hour window
        $this->assertFalse($people->canCorrectOffboarding($this->hr, $case->fresh()), 'After the window only an administrator may');
        $this->assertTrue($people->canCorrectOffboarding($this->admin, $case->fresh()));

        $people->cancelOffboarding($case->fresh(), 'Wrong employee selected', $this->admin);
        $this->assertSame('cancelled', $case->fresh()->status);
        $this->assertNull($this->employee->fresh()->exit_type);
        $this->assertSame('Wrong employee selected', AuditLog::where('action', 'employee.offboarding_cancelled')->firstOrFail()->metadata['reason']);

        // A closed case reversed inside the window re-activates the employee.
        $closed = $people->openLifecycle($this->lead, 'offboarding', '2026-10-20', null, $this->admin->id, ['type' => 'termination']);
        $closed->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->admin->id]);
        $this->lead->update(['is_active' => false]);
        $this->leadUser->update(['is_active' => false]);
        $people->cancelOffboarding($closed->fresh(), 'Closed by mistake', $this->hr);
        $this->assertTrue((bool) $this->lead->fresh()->is_active);
        $this->assertTrue((bool) $this->leadUser->fresh()->is_active);
    }

    // 17/20. Pagination ----------------------------------------------------------------------

    public function test_reports_and_application_usage_are_paginated(): void
    {
        for ($day = 1; $day <= 30; $day++) {
            \App\Models\AppUsage::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-'.str_pad((string) min($day, 14), 2, '0', STR_PAD_LEFT), 'app_name' => "App {$day}", 'seconds' => 60 * $day]);
        }
        Livewire::actingAs($this->admin)->withQueryParams(['from' => '2026-10-01', 'to' => '2026-10-14'])->test(\App\Livewire\Reports\Report::class, ['type' => 'app_usage'])
            ->assertSee('Showing 1–25 of 30')->call('gotoPage', 2)->assertSee('Showing 26–30 of 30');
    }
}
