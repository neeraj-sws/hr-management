<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeeTeamHistory;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryPayment;
use App\Models\Screenshot;
use App\Models\Team;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\PeopleOperationsService;
use App\Services\PolicyConfigurationService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Offline submissions from the phone, dated team membership and the payment / team / capture reports. */
class RemainingFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employeeUser;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-07 09:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->employeeUser = User::factory()->create(['email' => 'remp@example.com', 'name' => 'Rem Employee']);
        $this->employeeUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'REMP', 'name' => 'Rem Employee', 'is_active' => true, 'user_id' => $this->employeeUser->id, 'work_start' => '09:30:00', 'work_end' => '18:30:00', 'joined_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_offline_leave_expense_and_status_are_created_once_per_action_id(): void
    {
        Sanctum::actingAs($this->employeeUser, ['*']);
        $leave = ['leave_type_id' => LeaveType::where('name', 'Unpaid Leave')->value('id'), 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-12', 'reason' => 'Family function'];
        $first = $this->postJson('/api/leave/requests', $leave, ['X-Action-Id' => 'leave-1'])->assertCreated()->json('data.id');
        $this->postJson('/api/leave/requests', $leave, ['X-Action-Id' => 'leave-1'])->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $first);
        $this->postJson('/api/leave/requests', [...$leave, 'reason' => 'Changed'], ['X-Action-Id' => 'leave-1'])->assertStatus(409);
        $this->assertSame(1, LeaveRequest::where('employee_id', $this->employee->id)->count());

        Storage::fake('local');
        $claim = ['category' => 'Travel', 'spent_on' => '2026-10-06', 'amount' => '250', 'description' => 'Cab to client'];
        $this->post('/api/expenses', [...$claim, 'receipt' => UploadedFile::fake()->image('bill.jpg')], ['X-Action-Id' => 'exp-1', 'Accept' => 'application/json'])->assertCreated();
        $this->post('/api/expenses', [...$claim, 'receipt' => UploadedFile::fake()->image('bill.jpg')], ['X-Action-Id' => 'exp-1', 'Accept' => 'application/json'])->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, \App\Models\ExpenseClaim::where('employee_id', $this->employee->id)->count());

        // Written offline yesterday, synced today: stored against yesterday.
        $this->postJson('/api/daily-status', ['summary' => 'Finished the report', 'work_date' => '2026-10-06'], ['X-Action-Id' => 'ds-1'])->assertCreated()->assertJsonPath('data.work_date', '2026-10-06');
        $this->postJson('/api/daily-status', ['summary' => 'Too old to sync', 'work_date' => '2026-10-01'])->assertUnprocessable();
        $this->postJson('/api/daily-status', ['summary' => 'Today as usual'])->assertCreated()->assertJsonPath('data.work_date', '2026-10-07');
    }

    public function test_team_membership_is_dated_and_drives_team_policy_on_each_day(): void
    {
        $service = app(EmployeeService::class);
        $alpha = Team::create(['name' => 'Alpha', 'is_active' => true]);
        $beta = Team::create(['name' => 'Beta', 'is_active' => true]);
        $service->syncTeams($this->employee, [$alpha->id], $this->admin, '2026-01-01');
        Carbon::setTestNow('2026-10-07 10:00:00');
        $service->syncTeams($this->employee, [$beta->id], $this->admin);

        $rows = EmployeeTeamHistory::where('employee_id', $this->employee->id)->orderBy('starts_on')->get();
        $this->assertSame([[$alpha->id, '2026-01-01', '2026-10-06'], [$beta->id, '2026-10-07', null]], $rows->map(fn ($r) => [$r->team_id, $r->starts_on->toDateString(), $r->ends_on?->toDateString()])->all());

        $people = app(PeopleOperationsService::class);
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', ['multiplier' => 1.5], null, $this->admin->id, '2026-01-01');
        $people->setTeamPolicy('overtime.multiplier', ['multiplier' => 2.0], $alpha->id, $this->admin->id, '2026-01-01');
        $policies = app(PolicyConfigurationService::class);
        $this->assertEquals(2.0, $policies->forEmployee($this->employee, 'overtime.multiplier', '2026-09-15')['multiplier'], 'In Alpha in September');
        $this->assertEquals(1.5, $policies->forEmployee($this->employee, 'overtime.multiplier', '2026-10-07')['multiplier'], 'Moved to Beta (no override) today');

        // Team-side edit keeps the same history.
        $service->syncTeamMembers($beta, [], $this->admin);
        $this->assertNull(EmployeeTeamHistory::where('employee_id', $this->employee->id)->where('team_id', $beta->id)->first(), 'Added and removed on the same day leaves no history');
    }

    public function test_payment_team_and_capture_reports(): void
    {
        $reports = app(ReportService::class);
        $run = PayrollRun::create(['period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'approved']);
        $item = PayrollItem::create(['payroll_run_id' => $run->id, 'employee_id' => $this->employee->id, 'net_amount' => 30000, 'gross_amount' => 30000, 'calculation_data' => []]);
        SalaryPayment::create(['payroll_item_id' => $item->id, 'amount' => 30000, 'paid_on' => '2026-10-01', 'reference' => 'NEFT-1', 'recorded_by' => $this->admin->id]);
        $rows = $reports->rows($this->admin, 'payments', ['from' => '2026-10-01', 'to' => '2026-10-07']);
        $this->assertSame(['2026-10-01', 'salary', 'REMP', 'Sep 2026', 30000.0, 'NEFT-1', $this->admin->name], [$rows[0]['paid_on'], $rows[0]['kind'], $rows[0]['employee_code'], $rows[0]['for_period'], $rows[0]['amount'], $rows[0]['reference'], $rows[0]['recorded_by']]);

        $team = Team::create(['name' => 'Gamma', 'is_active' => true]);
        app(EmployeeService::class)->syncTeams($this->employee, [$team->id], $this->admin, '2026-01-01');
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-06', 'check_in' => '2026-10-06 09:50:00', 'check_out' => '2026-10-06 18:30:00', 'active_seconds' => 7200, 'status' => 'completed']);
        $teamRow = $reports->rows($this->admin, 'teams', ['from' => '2026-10-01', 'to' => '2026-10-07', 'team_id' => $team->id])->first();
        $this->assertSame(['Gamma', 1, 1, 1, 2.0], [$teamRow['team'], $teamRow['members'], $teamRow['attendance_days'], $teamRow['late_days'], $teamRow['active_hours']]);

        Screenshot::create(['employee_id' => $this->employee->id, 'captured_at' => '2026-10-06 10:00:00', 'path' => 'x/1.jpg', 'bytes' => 1048576]);
        Screenshot::create(['employee_id' => $this->employee->id, 'captured_at' => '2026-10-06 16:30:00', 'path' => 'x/2.jpg', 'bytes' => 1048576]);
        $capture = $reports->rows($this->admin, 'screenshots', ['from' => '2026-10-01', 'to' => '2026-10-07'])->firstWhere('employee_code', 'REMP');
        $this->assertSame([2, '10:00', '16:30', 2.0], [$capture['captures'], $capture['first_at'], $capture['last_at'], $capture['size_mb']]);

        $this->assertFalse($reports->canView($this->employeeUser, 'screenshots'));
        $this->assertFalse($reports->canView($this->employeeUser, 'payments'));
        $this->actingAs($this->admin)->get('/reports/payments')->assertOk()->assertSee('NEFT-1');
        $this->actingAs($this->admin)->get('/reports/teams/export?from=2026-10-01&to=2026-10-07')->assertOk();
    }
    public function test_phone_push_registers_sends_respects_preferences_and_forgets_dead_phones(): void
    {
        \Illuminate\Support\Facades\Http::fake(['exp.host/*' => \Illuminate\Support\Facades\Http::response(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/push-tokens', ['token' => 'not-a-token'])->assertUnprocessable();
        $this->postJson('/api/push-tokens', ['token' => 'ExponentPushToken[abc123]', 'platform' => 'android'])->assertOk()->assertJsonPath('push_enabled', false);
        $notification = new \App\Notifications\ApprovalRequested('LeaveRequest', 1, 'Your leave was approved');
        $this->assertSame(['database'], $notification->via($this->employeeUser->fresh()), 'Off until EXPO_PUSH_ENABLED');

        config(['services.expo_push.enabled' => true]);
        $this->assertContains(\App\Notifications\Channels\ExpoPushChannel::class, $notification->via($this->employeeUser->fresh()));
        $this->employeeUser->forceFill(['notification_preferences' => ['company' => ['in_app' => true, 'push' => false]]])->save();
        $this->assertNotContains(\App\Notifications\Channels\ExpoPushChannel::class, (new \App\Notifications\ApprovalRequested('Announcement', 1, 'News'))->via($this->employeeUser->fresh()));

        (new \App\Notifications\Channels\ExpoPushChannel)->send($this->employeeUser, $notification);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->url() === \App\Notifications\Channels\ExpoPushChannel::ENDPOINT && $request[0]['to'] === 'ExponentPushToken[abc123]' && $request[0]['body'] === 'Your leave was approved');
        $this->assertSame(0, \App\Models\PushToken::count(), 'Phone that uninstalled the app is forgotten');

        $this->postJson('/api/push-tokens', ['token' => 'ExponentPushToken[xyz]'])->assertOk();
        $this->postJson('/api/push-tokens/remove', ['token' => 'ExponentPushToken[xyz]'])->assertOk();
        $this->assertSame(0, \App\Models\PushToken::count());
    }
    public function test_checklist_gaps_yesterday_payment_method_overdue_alerts_and_token_expiry(): void
    {
        // Daily status keeps "yesterday".
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/daily-status', ['yesterday' => 'Fixed login bug', 'plan' => 'Reports', 'summary' => 'Reports half done'])->assertCreated()->assertJsonPath('data.yesterday', 'Fixed login bug');

        // Salary payment records method and the date it was paid.
        $run = PayrollRun::create(['period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'locked']);
        $item = PayrollItem::create(['payroll_run_id' => $run->id, 'employee_id' => $this->employee->id, 'net_amount' => 50000, 'gross_amount' => 50000, 'calculation_data' => []]);
        \Livewire\Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\Payments::class)
            ->call('openPayment', $item->id)->set('amount', '20000')->set('method', 'telepathy')->call('pay')->assertHasErrors('method')
            ->set('method', 'upi')->set('paidOn', '2026-10-05')->set('reference', 'UTR-1')->call('pay')->assertHasNoErrors();
        $payment = SalaryPayment::firstOrFail();
        $this->assertSame(['upi', '2026-10-05', 'UTR-1'], [$payment->method, $payment->paid_on->toDateString(), $payment->reference]);
        $this->assertSame('upi', app(ReportService::class)->rows($this->admin, 'payments', ['from' => '2026-10-01', 'to' => '2026-10-07'])[0]['method']);

        // Overdue task: one alert to the employee and the manager, re-armed when the due date moves.
        $managerUser = User::factory()->create(['email' => 'rmgr@example.com']);
        $manager = AttendanceEmployee::create(['employee_code' => 'RMGR', 'name' => 'Rem Manager', 'is_active' => true, 'user_id' => $managerUser->id]);
        $this->employee->update(['manager_id' => $manager->id]);
        $task = \App\Models\Task::create(['title' => 'Quarterly report', 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'priority' => 'medium', 'status' => 'pending', 'due_on' => '2026-10-05']);
        $this->artisan('work:notify-overdue-tasks')->assertSuccessful();
        $this->artisan('work:notify-overdue-tasks')->assertSuccessful();
        $this->assertSame(1, $this->employeeUser->notifications()->where('data->kind', 'TaskOverdue')->count());
        $this->assertSame(1, $managerUser->notifications()->where('data->kind', 'TaskOverdue')->count());
        $task->update(['due_on' => '2026-10-06']);
        $this->assertNull($task->fresh()->overdue_notified_at, 'New due date, new alert later');

        // Sign-in tokens from the apps expire.
        $this->app['auth']->forgetGuards();
        $pw = 'Uniq-Test-Password-42!';
        $this->employeeUser->forceFill(['password' => \Illuminate\Support\Facades\Hash::make($pw)])->save();
        $token = $this->postJson('/api/auth/token', ['email' => 'remp@example.com', 'password' => $pw, 'device_name' => 'phone'])->assertOk()->json('token');
        $this->assertTrue(\Laravel\Sanctum\PersonalAccessToken::findToken($token)->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }
    public function test_admin_controls_which_sources_an_employee_may_mark_attendance_from(): void
    {
        $attendance = app(\App\Services\AttendanceService::class);
        $people = app(PeopleOperationsService::class);
        // Default: all three allowed.
        $this->assertEqualsCanonicalizing(['web', 'mobile', 'desktop'], $attendance->allowedSources($this->employee));

        // Admin restricts this employee to desktop only.
        $people->setPolicy('attendance.sources', 'attendance', 'Attendance methods', ['allowed' => ['desktop']], $this->employee->id, $this->admin->id, '2026-01-01');
        $this->assertSame(['desktop'], $attendance->allowedSources($this->employee->fresh()));

        Carbon::setTestNow('2026-10-12 09:30:00');
        try {
            $attendance->checkIn($this->employee->fresh(), 'mobile');
            $this->fail('Mobile check-in should be blocked.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('turned off', implode(' ', $e->validator->errors()->all()));
        }
        // Desktop still works, and an HR/manager correction (admin) is always allowed.
        $session = $attendance->checkIn($this->employee->fresh(), 'desktop');
        $this->assertSame('working', $session->status);
        $attendance->checkOut($this->employee->fresh(), 'admin');

        // The mobile API advertises the allowed set so the app can hide the buttons.
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.policies.attendance_sources', ['desktop']);
    }
    public function test_application_usage_is_admin_enabled_and_totalled_per_app_per_day(): void
    {
        $usage = app(\App\Services\AppUsageService::class);
        // Off by default - nothing is stored even if the desktop reports.
        $this->assertFalse($usage->enabledFor($this->employee));
        Sanctum::actingAs($this->employeeUser, ['*']);
        $this->postJson('/api/monitoring/app-usage', ['apps' => ['Google Chrome' => 600]])->assertOk()->assertJsonPath('enabled', false);
        $this->assertSame(0, \App\Models\AppUsage::count());

        // HR cannot enable it; only monitoring.manage (admin) can.
        $hr = User::factory()->create(); $hr->assignRole('HR');
        try { $usage->setForEmployee($this->employee, true, $hr); $this->fail('HR blocked'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $usage->setForEmployee($this->employee, true, $this->admin);

        // Reports accumulate per app per day.
        $this->postJson('/api/monitoring/app-usage', ['apps' => ['Google Chrome' => 600, 'VS Code' => 1800]])->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('recorded', 2);
        $this->postJson('/api/monitoring/app-usage', ['apps' => ['Google Chrome' => 300]])->assertOk();
        $this->assertSame(900, (int) \App\Models\AppUsage::where('employee_id', $this->employee->id)->where('app_name', 'Google Chrome')->value('seconds'));

        // Admin report lists it; employees cannot view it.
        $rows = app(ReportService::class)->rows($this->admin, 'app_usage', ['from' => today()->toDateString(), 'to' => today()->toDateString()]);
        $chrome = $rows->firstWhere('application', 'Google Chrome');
        $this->assertSame([15, 0.25], [$chrome['minutes'], $chrome['hours']]);
        $this->assertFalse(app(ReportService::class)->canView($this->employeeUser, 'app_usage'));

        // The phone/desktop learns the flag from /me.
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.policies.app_usage', true);
    }
}
