<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\EmployeePolicyOverride;
use App\Models\OffDayRequest;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\AttendanceService;
use App\Services\OffDayService;
use App\Services\PeopleOperationsService;
use App\Services\PolicyConfigurationService;
use App\Support\Navigation;
use App\Support\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Everyone is off on the 3rd Saturday; one employee is off on the 2nd Saturday instead, but only when she asks for
 * that day (Off day request) and HR approves. Not asked for / not approved, it is a working day (absent if she
 * does not come). Everyone else's 3rd Saturday is unchanged.
 */
class OffDayRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $khushboo;
    private AttendanceEmployee $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-05 12:00:00'); // Nov 2026: Saturdays 7, 14 (2nd), 21 (3rd), 28
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        app(PeopleOperationsService::class)->setPolicy('calendar.working_days', 'calendar', 'Working calendar', WorkCalendar::normalise(['days' => [1, 2, 3, 4, 5, 6], 'holidays' => [], 'off_weeks' => ['6' => [3]]]), null, $this->admin->id, '2026-01-01');
        $this->khushboo = $this->person('EMP-41', 'Khushboo Verma');
        $this->colleague = $this->person('EMP-42', 'Riya Dev');
        EmployeePolicyOverride::create(['employee_id' => $this->khushboo->id, 'rule_key' => 'calendar.working_days', 'effective_from' => null, 'configuration' => ['off_weeks' => ['6' => [2]], 'offs_need_request' => true]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $code, string $name): AttendanceEmployee
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '18:00:00', 'daily_work_hours' => 8]);
    }

    private function workday(AttendanceEmployee $employee, string $date): bool
    {
        return app(AttendanceService::class)->isScheduledDay($employee, $date);
    }

    public function test_second_saturday_is_a_working_day_until_hr_approves_her_request(): void
    {
        // Not asked for: her 2nd Saturday is a normal working day; her pattern replaces the company 3rd Saturday.
        $this->assertTrue($this->workday($this->khushboo, '2026-11-14'));
        $this->assertTrue($this->workday($this->khushboo, '2026-11-21'));
        // Everyone else unchanged.
        $this->assertTrue($this->workday($this->colleague, '2026-11-14'));
        $this->assertFalse($this->workday($this->colleague, '2026-11-21'));

        $offs = app(OffDayService::class);
        $this->assertSame(['2026-11-14', '2026-12-12', '2027-01-09', '2027-02-13'], $offs->requestableDates($this->khushboo));
        $this->assertSame([], $offs->requestableDates($this->colleague));

        $request = $offs->request($this->khushboo, '2026-11-14', 'Taking my 2nd Saturday off');
        $this->assertSame('pending', $request->status);
        $this->assertTrue($this->workday($this->khushboo, '2026-11-14'), 'still a working day while it waits for HR');

        $approval = ApprovalRequest::where('approvable_type', OffDayRequest::class)->where('approvable_id', $request->id)->firstOrFail();
        $inbox = app(ApprovalInbox::class);
        $this->assertTrue($inbox->pending($this->admin)->contains('id', $approval->id));
        $this->assertFalse($inbox->canDecide($this->khushboo->user, $approval), 'no self-approval');
        $inbox->decide($this->admin, $approval, true);

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertFalse($this->workday($this->khushboo, '2026-11-14'), 'approved: her off');
        $this->assertTrue($this->workday($this->khushboo, '2026-12-12'), 'only the day she asked for');

        $calendar = app(PolicyConfigurationService::class)->forEmployee($this->khushboo, 'calendar.working_days', '2026-11-14');
        $this->assertSame('2nd Saturday — weekly off', WorkCalendar::specialOffReason('2026-11-14', $calendar));
        $this->assertStringContainsString('only when asked for', WorkCalendar::describe($calendar));
    }

    public function test_attendance_shows_absent_without_approval_and_weekly_off_with_it(): void
    {
        $status = fn () => app(AttendanceService::class)->days($this->khushboo, Carbon::parse('2026-11-14'), Carbon::parse('2026-11-14'))->first()['status'];
        Carbon::setTestNow('2026-11-16 12:00:00');
        $this->assertSame('absent', $status());

        $request = app(OffDayService::class)->request($this->khushboo, '2026-11-14', 'Forgot to ask before');
        app(OffDayService::class)->decide($request, $this->admin->id, true);
        $this->assertSame('weekend', $status());
    }

    public function test_wrong_days_duplicates_and_rejection(): void
    {
        $offs = app(OffDayService::class);
        foreach (['2026-11-21', '2026-11-10'] as $notHers) {
            try { $offs->request($this->khushboo, $notHers); $this->fail("{$notHers} should be refused"); } catch (ValidationException $e) { $this->assertArrayHasKey('off_date', $e->errors()); }
        }
        try { $offs->request($this->colleague, '2026-11-14'); $this->fail('colleague has no ask-for offs'); } catch (ValidationException $e) { $this->assertArrayHasKey('off_date', $e->errors()); }

        $request = $offs->request($this->khushboo, '2026-11-14');
        try { $offs->request($this->khushboo, '2026-11-14'); $this->fail('duplicate'); } catch (ValidationException $e) { $this->assertStringContainsString('already asked', $e->errors()['off_date'][0]); }

        try { $offs->decide($request, $this->admin->id, false); $this->fail('reason needed'); } catch (ValidationException $e) { $this->assertArrayHasKey('note', $e->errors()); }
        $offs->decide($request, $this->admin->id, false, 'Client delivery that day');
        $this->assertTrue($this->workday($this->khushboo, '2026-11-14'), 'rejected: a working day');

        // After a rejection she may ask again; cancelling a pending one leaves it a working day too.
        $again = $offs->request($this->khushboo, '2026-11-14');
        $offs->cancel($again, $this->khushboo);
        $this->assertSame('cancelled', $again->fresh()->status);
        $this->assertTrue($this->workday($this->khushboo, '2026-11-14'));
    }

    public function test_auto_approve_setting_approves_at_once(): void
    {
        Livewire::actingAs($this->admin)->withQueryParams(['tab' => 'calendar'])->test(\App\Livewire\System\Settings::class)
            ->set('patternEmployeeId', $this->khushboo->id)->assertSet('employeeOffsAutoApprove', false)
            ->set('employeeOffsAutoApprove', true)->call('saveEmployeePattern')->assertHasNoErrors();
        $this->assertTrue(EmployeePolicyOverride::where('employee_id', $this->khushboo->id)->firstOrFail()->configuration['offs_auto_approve']);

        $request = app(OffDayService::class)->request($this->khushboo, '2026-11-14');
        $this->assertSame('approved', $request->status);
        $this->assertNull($request->decided_by);
        $this->assertSame('Approved automatically', $request->decision_note);
        $this->assertFalse($this->workday($this->khushboo, '2026-11-14'), 'auto-approved: her off');
        $this->assertTrue($this->workday($this->khushboo, '2026-12-12'), 'still only the days she asks for');
        $this->assertFalse(app(ApprovalInbox::class)->pending($this->admin)->contains(fn ($a) => $a->approvable_type === OffDayRequest::class), 'nothing left for HR to decide');

        // Switching auto-approve off: requests wait for HR again.
        Livewire::actingAs($this->admin)->withQueryParams(['tab' => 'calendar'])->test(\App\Livewire\System\Settings::class)
            ->set('patternEmployeeId', $this->khushboo->id)->assertSet('employeeOffsAutoApprove', true)
            ->set('employeeOffsAutoApprove', false)->call('saveEmployeePattern')->assertHasNoErrors();
        $this->assertSame('pending', app(OffDayService::class)->request($this->khushboo, '2026-12-12')->status);
    }

    public function test_pages_menu_settings_and_app(): void
    {
        $khushboo = $this->khushboo->user;
        $this->assertTrue(Navigation::canAccess($khushboo, 'leave.off-days'));
        $this->assertFalse(Navigation::canAccess($this->colleague->user, 'leave.off-days'));
        $this->assertTrue(Navigation::canAccess($this->admin, 'leave.off-days'));
        $this->actingAs($this->colleague->user)->get(route('leave.off-days'))->assertForbidden();
        $this->actingAs($khushboo)->get(route('leave.off-days'))->assertOk();

        Livewire::actingAs($khushboo)->test(\App\Livewire\Leave\OffDays::class)
            ->assertSee('Sat 14 Nov')->call('openForm')->assertSet('offDate', '2026-11-14')
            ->set('note', 'Taking my off')->call('save')->assertHasNoErrors();
        $request = OffDayRequest::where('employee_id', $this->khushboo->id)->firstOrFail();

        // Not decided yet: HR sees it on the dashboard; once decided it goes.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Off day requests not decided yet (1)')->assertSee('Sat 14 Nov');
        $this->actingAs($khushboo)->get(route('dashboard'))->assertOk()->assertDontSee('Off day requests not decided yet');

        Livewire::actingAs($this->admin)->test(\App\Livewire\Leave\OffDays::class)
            ->assertSee('Khushboo Verma')->call('openDecide', $request->id, true)->call('decide')->assertHasNoErrors();
        $this->assertSame('approved', $request->fresh()->status);
        // The form starts on the next day not asked for yet; with every day asked it says why the list is empty.
        Livewire::actingAs($khushboo)->test(\App\Livewire\Leave\OffDays::class)->call('openForm')->assertSet('offDate', '2026-12-12');
        foreach (['2026-12-12', '2027-01-09', '2027-02-13'] as $d) app(OffDayService::class)->request($this->khushboo, $d);
        Livewire::actingAs($khushboo)->test(\App\Livewire\Leave\OffDays::class)->call('openForm')->assertSee('No day left to ask for')->assertSee('Sat 14 Nov is already approved');
        OffDayRequest::where('status', 'pending')->get()->each(fn ($r) => app(OffDayService::class)->cancel($r, $this->khushboo));
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertDontSee('Off day requests not decided yet');

        // Settings: the tick is shown for her and saved; unticking makes her 2nd Saturday a plain off again.
        Livewire::actingAs($this->admin)->withQueryParams(['tab' => 'calendar'])->test(\App\Livewire\System\Settings::class)
            ->set('patternEmployeeId', $this->khushboo->id)->assertSet('employeeOffsNeedRequest', true)
            ->set('employeeOffsNeedRequest', false)->call('saveEmployeePattern')->assertHasNoErrors();
        $this->assertArrayNotHasKey('offs_need_request', EmployeePolicyOverride::where('employee_id', $this->khushboo->id)->firstOrFail()->configuration);
        $this->assertFalse($this->workday($this->khushboo, '2026-12-12'));
        Livewire::actingAs($this->admin)->withQueryParams(['tab' => 'calendar'])->test(\App\Livewire\System\Settings::class)
            ->set('patternEmployeeId', $this->khushboo->id)->set('employeeOffsNeedRequest', true)->call('saveEmployeePattern')->assertHasNoErrors();
        $this->assertTrue($this->workday($this->khushboo, '2026-12-12'));

        // App: the list, the days she can ask for, and asking.
        $this->actingAs($khushboo, 'sanctum')->getJson('/api/off-days')->assertOk()->assertJsonPath('dates', ['2026-12-12', '2027-01-09', '2027-02-13'])->assertJsonFragment(['off_date' => '2026-11-14', 'status' => 'approved']);
        $this->actingAs($khushboo, 'sanctum')->postJson('/api/off-days', ['off_date' => '2026-12-12'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->actingAs($khushboo, 'sanctum')->getJson('/api/me')->assertOk()->assertJsonPath('data.offs_need_request', true);
    }
}
