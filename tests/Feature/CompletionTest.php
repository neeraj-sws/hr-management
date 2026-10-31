<?php

namespace Tests\Feature;

use App\Livewire\Payroll\Salary;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\PayrollItem;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Features completed after the master audit. */
class CompletionTest extends TestCase
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
        Carbon::setTestNow('2026-10-07 09:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        [$this->managerUser, $this->manager] = $this->person('CMGR', 'Comp Manager', ['Employee', 'Manager']);
        [$this->employeeUser, $this->employee] = $this->person('CEMP', 'Comp Employee', ['Employee'], $this->manager->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: AttendanceEmployee} */
    private function person(string $code, string $name, array $roles, ?int $managerId = null): array
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com', 'name' => $name]);
        $user->assignRole($roles);
        $employee = AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'manager_id' => $managerId, 'work_start' => '09:30:00', 'work_end' => '18:30:00', 'joined_at' => '2026-01-01']);

        return [$user, $employee];
    }

    /** Eleven of October's 22 weekdays present. */
    private function halfMonthPresent(AttendanceEmployee $employee): void
    {
        $count = 0;
        for ($d = Carbon::parse('2026-10-01'); $count < 11; $d->addDay()) {
            if ($d->isWeekend()) continue;
            AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => $d->toDateString(), 'check_in' => $d->toDateString().' 09:30:00', 'check_out' => $d->toDateString().' 18:30:00', 'active_seconds' => 28800, 'status' => 'completed']);
            $count++;
        }
    }

    public function test_salary_structure_components_prorate_and_feed_statutory_basic(): void
    {
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('payroll.statutory', 'payroll', 'Statutory', ['components' => [['key' => 'emp_fund', 'name' => 'Employee fund', 'type' => 'deduction', 'basis' => 'basic_salary', 'rate_bps' => 1000]]], null, $this->admin->id, '2026-01-01');
        Livewire::actingAs($this->admin)->test(Salary::class)
            ->call('editProfile', $this->employee->id)->set('monthly', '50000')->set('workingDays', '22')->set('effectiveFrom', '2026-01-01')
            ->set('components', [['name' => 'Basic', 'type' => 'earning', 'amount' => '25000'], ['name' => 'HRA', 'type' => 'earning', 'amount' => '10000'], ['name' => 'Special allowance', 'type' => 'earning', 'amount' => '14000'], ['name' => 'Canteen', 'type' => 'deduction', 'amount' => '500']])
            ->call('saveProfile')->assertHasErrors('components') // earnings 49,000 ≠ 50,000
            ->set('components.2.amount', '15000')->call('saveProfile')->assertHasNoErrors();
        $this->halfMonthPresent($this->employee);
        $this->travelTo(Carbon::parse('2026-11-02'));
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertEquals(['Basic' => 12500, 'HRA' => 5000, 'Special allowance' => 7500], $item->calculation_breakdown['salary_components']);
        $this->assertEquals(500, $item->calculation_breakdown['fixed_deductions_total']);
        $this->assertEquals(1250, (float) $item->statutory_deductions, '10% of the prorated Basic, not of the whole base');
        $this->assertEquals(25000 - 500 - 1250, (float) $item->net_amount);
        $this->actingAs($this->employeeUser)->get(route('hr.payslips.show', $item->payslip ?? \App\Models\Payslip::firstOrCreate(['payroll_item_id' => $item->id], ['employee_id' => $this->employee->id, 'reference' => 'PS-T', 'issued_at' => now()])))
            ->assertOk()->assertSee('HRA')->assertSee('Canteen');
    }

    public function test_company_template_is_used_with_balance_allowance(): void
    {
        app(PeopleOperationsService::class)->setPolicy('payroll.salary_structure', 'payroll', 'Template', ['components' => [['name' => 'Basic', 'type' => 'earning', 'percent' => 50], ['name' => 'HRA', 'type' => 'earning', 'percent' => 30], ['name' => 'Welfare', 'type' => 'deduction', 'amount' => 200]]], null, $this->admin->id, '2026-01-01');
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $this->halfMonthPresent($this->employee);
        $this->travelTo(Carbon::parse('2026-11-02'));
        $run = app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $item = PayrollItem::where('payroll_run_id', $run->id)->firstOrFail();
        $this->assertEquals(['Basic' => 5500, 'HRA' => 3300, 'Balance allowance' => 2200], $item->calculation_breakdown['salary_components']);
        $this->assertEquals(11000 - 200, (float) $item->net_amount);
    }
    public function test_compliance_detects_early_checkout_missing_task_late_entry_and_missing_status_as_pending(): void
    {
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('attendance.early_checkout', 'compliance', 'Early', ['amount_per_minute' => 5, 'grace_minutes' => 10, 'max_amount' => 500], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('task.missing_entry', 'compliance', 'No task', ['amount_per_day' => 100], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('task.late_entry', 'compliance', 'Late task', ['amount' => 50, 'grace_hours' => 12], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('daily_status.missing', 'compliance', 'Status', ['amount_per_day' => 75, 'grace_hours' => 6], null, $this->admin->id, '2026-01-01');
        // 5 Oct: left at 17:30 (60 min early → 50 after grace → 250), no task, no daily status.
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-05', 'check_in' => '2026-10-05 09:30:00', 'check_out' => '2026-10-05 17:30:00', 'active_seconds' => 28800, 'status' => 'completed']);
        // 6 Oct: full day, status submitted, but the task for that day was entered on the 8th.
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-06', 'check_in' => '2026-10-06 09:30:00', 'check_out' => '2026-10-06 18:30:00', 'active_seconds' => 32400, 'status' => 'completed']);
        \App\Models\DailyWorkStatus::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-06', 'summary' => 'Done', 'submitted_at' => '2026-10-06 18:20:00', 'server_version' => 1]);
        $this->travelTo(Carbon::parse('2026-10-08 10:00:00'));
        app(\App\Services\WorkService::class)->saveTask(null, ['title' => 'Fixed login bug', 'assignee_id' => $this->employee->id, 'priority' => 'normal', 'status' => 'completed', 'work_date' => '2026-10-06'], $this->employeeUser);

        $created = $people->assessCompliance('2026-10-05', '2026-10-06', $this->admin->id);
        $penalties = \App\Models\EmployeePenalty::orderBy('id')->get();
        $this->assertSame(4, $created);
        $this->assertEquals([250, 100, 75, 50], $penalties->pluck('amount')->map(fn ($a) => (float) $a)->all());
        // Early check-out / tasks wait for approval; the missing daily status fine is deducted at once.
        $this->assertSame(['pending', 'pending', 'approved', 'pending'], $penalties->pluck('status')->all());
        $this->assertSame(0, $people->assessCompliance('2026-10-05', '2026-10-06', $this->admin->id), 'Re-running does not duplicate');
        $this->assertTrue($this->employeeUser->notifications()->where('data->kind', 'EmployeePenalty')->exists());
        $this->artisan('compliance:assess', ['--from' => '2026-10-05', '--to' => '2026-10-06'])->assertSuccessful();
    }

    public function test_daily_status_fields_blocker_notification_and_reminder(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 18:00:00'));
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-07', 'check_in' => '2026-10-07 09:30:00', 'status' => 'working']);
        $this->artisan('work:remind-daily-status')->assertSuccessful();
        $this->artisan('work:remind-daily-status')->assertSuccessful();
        $this->assertSame(1, $this->employeeUser->notifications()->where('data->kind', 'DailyStatusReminder')->count(), 'One reminder per day');

        \Laravel\Sanctum\Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/daily-status', ['plan' => 'API work', 'summary' => 'Built the endpoint', 'blockers' => 'Waiting for API keys'])->assertCreated()->assertJsonPath('data.blockers', 'Waiting for API keys');
        $this->getJson('/api/daily-status')->assertOk()->assertJsonPath('today.plan', 'API work');
        $this->assertSame(1, $this->managerUser->notifications()->where('data->kind', 'DailyStatus')->count());
        $this->actingAs($this->managerUser)->get(route('work.daily-status'))->assertOk()->assertSee('Waiting for API keys');
    }

    public function test_employment_details_confirmation_and_exit(): void
    {
        $service = app(\App\Services\EmployeeService::class);
        $service->update($this->employee, ['employment_type' => 'contract', 'probation_ends_on' => '2026-10-20', 'notice_period_days' => 30], $this->admin);
        $this->assertSame('contract', $this->employee->fresh()->employment_type);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Probation reviews due');
        Livewire::actingAs($this->admin)->test(\App\Livewire\People\EmployeeProfile::class, ['employee' => $this->employee])->call('confirmEmployment')->assertHasNoErrors();
        $this->assertSame('2026-10-07', $this->employee->fresh()->confirmed_on->toDateString());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->confirm($this->employee->fresh(), '2026-10-08', $this->admin);
    }

    public function test_offboarding_records_exit_type_and_date(): void
    {
        app(PeopleOperationsService::class)->openLifecycle($this->employee, 'offboarding', '2026-10-31', 'Moving cities', $this->admin->id, ['type' => 'resignation', 'resigned_on' => '2026-10-01']);
        $e = $this->employee->fresh();
        $this->assertSame(['resignation', '2026-10-31', '2026-10-01', 'Moving cities'], [$e->exit_type, $e->exit_date->toDateString(), $e->resigned_on->toDateString(), $e->exit_reason]);
    }

    public function test_password_reset_by_email_link_is_for_administrators_only(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertSessionHas('status'); // same answer for unknown emails
        // An employee cannot reset their own password: no link is sent, and a token cannot be used either.
        $this->post(route('password.email'), ['email' => $this->employeeUser->email])->assertSessionHas('status');
        \Illuminate\Support\Facades\Notification::assertNotSentTo($this->employeeUser, \Illuminate\Auth\Notifications\ResetPassword::class);
        $employeeToken = \Illuminate\Support\Facades\Password::createToken($this->employeeUser);
        $this->post(route('password.update'), ['token' => $employeeToken, 'email' => $this->employeeUser->email, 'password' => 'BrandNewPass2026', 'password_confirmation' => 'BrandNewPass2026'])->assertSessionHasErrors('email');
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('BrandNewPass2026', $this->employeeUser->fresh()->password));

        // The administrator can still recover their own account by e-mail.
        $this->post(route('password.email'), ['email' => $this->admin->email])->assertSessionHas('status');
        $token = null;
        \Illuminate\Support\Facades\Notification::assertSentTo($this->admin, \Illuminate\Auth\Notifications\ResetPassword::class, function ($n) use (&$token) { $token = $n->token; return true; });
        $this->admin->createToken('phone');
        $this->get(route('password.reset', ['token' => $token, 'email' => $this->admin->email]))->assertOk()->assertSee('Choose a new password');
        $this->post(route('password.update'), ['token' => $token, 'email' => $this->admin->email, 'password' => 'BrandNewPass2026', 'password_confirmation' => 'BrandNewPass2026'])->assertRedirect(route('login'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('BrandNewPass2026', $this->admin->fresh()->password));
        $this->assertSame(0, $this->admin->tokens()->count(), 'Mobile/desktop sessions are signed out');
    }

    public function test_own_tasks_subtasks_comments_and_project_members(): void
    {
        $work = app(\App\Services\WorkService::class);
        \Laravel\Sanctum\Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/tasks', ['title' => 'For my boss', 'priority' => 'high', 'assignee_id' => $this->manager->id])->assertForbidden();
        $parent = $this->postJson('/api/tasks', ['title' => 'Release 2.0', 'priority' => 'high'])->assertCreated()->json('data');
        $this->assertSame($this->employee->id, $parent['assignee']['id'], 'Employees can only add tasks for themselves');
        $this->assertSame('2026-10-07', $parent['work_date']);
        $sub = $this->postJson('/api/tasks', ['title' => 'Write notes', 'priority' => 'normal', 'parent_id' => $parent['id']])->assertCreated()->json('data');
        $this->postJson('/api/tasks', ['title' => 'Too deep', 'priority' => 'normal', 'parent_id' => $sub['id']])->assertUnprocessable();
        $this->postJson("/api/tasks/{$parent['id']}/comments", ['body' => 'Can someone review?'])->assertCreated();
        $this->assertSame(1, $this->managerUser->notifications()->where('data->kind', 'TaskComment')->count());
        [$otherUser] = $this->person('COUT', 'Outsider', ['Employee']);
        \Laravel\Sanctum\Sanctum::actingAs($otherUser, ['*']);
        $this->getJson("/api/tasks/{$parent['id']}/comments")->assertForbidden();

        $project = \App\Models\Project::create(['name' => 'Portal', 'status' => 'active', 'manager_id' => $this->manager->id]);
        $work->setProjectMembers($project, [$this->manager->id], $this->admin);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $work->saveTask(null, ['title' => 'Members only', 'assignee_id' => $this->employee->id, 'project_id' => $project->id, 'priority' => 'normal'], $this->managerUser);
    }
    public function test_notification_preferences_mute_optional_groups_but_never_important_ones(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->employeeUser->forceFill(['notification_preferences' => ['company' => ['in_app' => false, 'email' => false], 'important' => ['in_app' => false, 'email' => true]]])->save();
        $user = $this->employeeUser->fresh();
        $this->assertSame([], (new \App\Notifications\ApprovalRequested('Announcement', 1, 'News'))->via($user), 'Muted optional group');
        $this->assertSame(['database', \App\Notifications\Channels\SafeMailChannel::class], (new \App\Notifications\ApprovalRequested('LeaveRequest', 1, 'Approved'))->via($user), 'Important is always in the app; e-mail as chosen');
        $this->assertSame(['database', \App\Notifications\Channels\SafeMailChannel::class], (new \App\Notifications\ApprovalRequested('TaskAssignment', 1, 'Task'))->via($user), 'Task e-mails are on by default');
        $this->assertSame(['database'], (new \App\Notifications\ApprovalRequested('DailyStatusReminder', 1, 'Reminder'))->via($user), 'Reminders stay in the app by default');

        Livewire::actingAs($this->employeeUser)->test(\App\Livewire\Account\Profile::class)
            ->set('prefs.work.in_app', false)->set('prefs.important.in_app', false)->call('savePreferences')->assertHasNoErrors();
        $saved = $this->employeeUser->fresh()->notification_preferences;
        $this->assertFalse($saved['work']['in_app']);
        $this->assertTrue($saved['important']['in_app'], 'Important cannot be muted');
        \Laravel\Sanctum\Sanctum::actingAs($this->employeeUser, ['*']);
        $this->getJson('/api/notifications/preferences')->assertOk()->assertJsonPath('data.0.group', 'important')->assertJsonPath('data.0.in_app', true);
    }

    public function test_team_policy_sits_between_company_and_employee(): void
    {
        $people = app(PeopleOperationsService::class);
        $team = \App\Models\Team::create(['name' => 'Night ops', 'is_active' => true]);
        $this->employee->teams()->attach($team->id);
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', ['multiplier' => 1.5, 'max_minutes_per_day' => 120], null, $this->admin->id, '2026-01-01');
        $people->setTeamPolicy('overtime.multiplier', ['multiplier' => 2.0], $team->id, $this->admin->id, '2026-01-01');
        $policies = app(\App\Services\PolicyConfigurationService::class);
        $this->assertEquals(['multiplier' => 2.0, 'max_minutes_per_day' => 120], $policies->forEmployee($this->employee, 'overtime.multiplier', '2026-10-07'));
        $this->assertEquals(1.5, $policies->forEmployee($this->manager, 'overtime.multiplier', '2026-10-07')['multiplier'], 'Other teams keep the company rule');
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', ['multiplier' => 2.5], $this->employee->id, $this->admin->id, '2026-01-01');
        $this->assertEquals(2.5, $policies->forEmployee($this->employee, 'overtime.multiplier', '2026-10-07')['multiplier'], 'Employee override wins');
        $people->setTeamPolicy('monitoring.screenshots', ['enabled' => 1, 'interval_minutes' => 15, 'active_only' => 1, 'retention_days' => 7], $team->id, $this->admin->id, '2026-01-01');
        $capture = app(\App\Services\ScreenCaptureService::class)->policy($this->employee);
        $this->assertSame([true, 15, 7], [$capture['enabled'], $capture['interval_minutes'], $capture['retention_days']]);
        $this->assertFalse(app(\App\Services\ScreenCaptureService::class)->policy($this->manager)['enabled']);
    }

    public function test_mobile_self_service_and_manager_team_endpoints(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('employee-documents/x/offer.pdf', 'pdf');
        $doc = \App\Models\EmployeeDocument::create(['employee_id' => $this->employee->id, 'category' => 'offer', 'original_name' => 'offer.pdf', 'storage_path' => 'employee-documents/x/offer.pdf']);
        \App\Models\EmployeeAsset::create(['employee_id' => $this->employee->id, 'asset_tag' => 'LT-9', 'name' => 'Laptop', 'status' => 'assigned', 'assigned_on' => '2026-01-01']);
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-07', 'check_in' => '2026-10-07 09:45:00', 'status' => 'working']);
        \App\Models\DailyWorkStatus::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-07', 'summary' => 'x', 'blockers' => 'VPN down', 'server_version' => 1]);

        \Laravel\Sanctum\Sanctum::actingAs($this->employeeUser, ['*']);
        $url = $this->getJson('/api/my/documents')->assertOk()->assertJsonPath('data.0.name', 'offer.pdf')->json('data.0.url');
        $this->getJson('/api/my/assets')->assertOk()->assertJsonPath('data.0.tag', 'LT-9');
        $this->getJson('/api/my/performance')->assertOk()->assertJsonStructure(['month', 'reviews']);
        $this->getJson('/api/team/today')->assertForbidden();
        $this->get($url)->assertOk()->assertDownload('offer.pdf');
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        \Laravel\Sanctum\Sanctum::actingAs($this->managerUser, ['*']);
        $this->getJson('/api/team/today')->assertOk()->assertJsonPath('summary.working', 1)->assertJsonPath('summary.late', 1)->assertJsonPath('people.0.blockers', 'VPN down');
    }
}
