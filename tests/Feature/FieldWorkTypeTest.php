<?php

namespace Tests\Feature;

use App\Livewire\Compliance\Policies;
use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Field / mobile work type: idle is shown but not taken off; "I was working" carries a note; the idle prompt settings reach the app. */
class FieldWorkTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;
    private AttendanceEmployee $employee;
    private AttendanceService $attendance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->user = User::factory()->create();
        $this->user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'FW-1', 'name' => 'Vikas Field', 'is_active' => true, 'user_id' => $this->user->id, 'joined_at' => '2026-01-01']);
        $this->attendance = app(AttendanceService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dayWithIdle(): AttendanceSession
    {
        $this->attendance->checkIn($this->employee, 'desktop');
        Carbon::setTestNow('2026-10-14 13:00:00');
        $this->attendance->heartbeat($this->employee, 2 * 3600, 2 * 3600);   // 2 h untouched (on the phone)
        Carbon::setTestNow('2026-10-14 18:00:00');

        return $this->attendance->checkOut($this->employee, 'desktop');
    }

    public function test_desk_work_takes_idle_off_and_field_work_does_not(): void
    {
        $session = $this->dayWithIdle();
        $this->assertSame(7 * 3600, $this->attendance->workedSeconds($session), 'Desk: 9 h less 2 h idle');
        $this->actingAs($this->user, 'sanctum')->getJson('/api/me')->assertJsonPath('data.policies.work_type', 'desk');

        // Set to field / mobile from the start of the month: the closed day counts in-out less break, idle is only shown.
        Livewire::actingAs($this->admin)->test(Policies::class)
            ->set('overrideScope', 'employee')->set('overrideEmployeeId', $this->employee->id)
            ->set('overrideKey', AttendanceSession::WORK_TYPE_POLICY)->assertSee('Field / mobile (idle not taken off)')
            ->set('overrideValues', ['field' => '1'])->set('overrideFrom', '2026-10-01')->call('saveOverride')->assertHasNoErrors()
            ->assertSee('Vikas Field · Work type (desk / field-mobile)')->assertSee('Field / mobile');

        $session = AttendanceSession::firstOrFail();
        $this->assertTrue($session->isFieldWork());
        $this->assertSame(2 * 3600, $session->idleSeconds(), 'Still shown');
        $this->assertSame(9 * 3600, $this->attendance->workedSeconds($session));
        $this->assertSame(0, $this->attendance->liveFigures($session)['idle']);
        $this->assertFalse($this->attendance->liveFigures($session)['idle_deducted']);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/me')->assertJsonPath('data.policies.work_type', 'field');
        Livewire::actingAs($this->user)->test(\App\Livewire\Attendance\MyAttendance::class)->assertSee('Field / mobile')->assertSee('idle shown only, not taken off');
    }

    public function test_a_team_can_be_set_to_field_work(): void
    {
        $team = \App\Models\Team::create(['name' => 'Marketing', 'is_active' => true]);
        $team->members()->attach($this->employee->id);
        Livewire::actingAs($this->admin)->test(Policies::class)
            ->set('overrideScope', 'team')->set('overrideTeamId', $team->id)
            ->set('overrideKey', AttendanceSession::WORK_TYPE_POLICY)->set('overrideValues', ['field' => '1'])->set('overrideFrom', '2026-10-01')
            ->call('saveOverride')->assertHasNoErrors();
        $this->assertSame(9 * 3600, $this->attendance->workedSeconds($this->dayWithIdle()));

        Livewire::actingAs($this->admin)->test(Policies::class)
            ->set('overrideScope', 'team')->set('overrideTeamId', $team->id)
            ->set('overrideKey', AttendanceSession::WORK_TYPE_POLICY)->set('overrideValues', ['field' => '7'])->call('saveOverride')->assertHasErrors('overrideValues.field');
    }

    public function test_i_was_working_keeps_the_note_for_the_manager(): void
    {
        $this->attendance->checkIn($this->employee, 'desktop');
        Carbon::setTestNow('2026-10-14 11:00:00');
        $this->attendance->heartbeat($this->employee, 3600, 3600);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/attendance/away', ['from' => '2026-10-14 10:00:00', 'to' => '2026-10-14 11:00:00', 'reason' => 'idle', 'as' => 'work', 'note' => 'Client call on phone'])->assertOk();

        $session = AttendanceSession::firstOrFail();
        $this->assertSame(0, $session->idleSeconds());
        $this->assertSame('Client call on phone', $session->events()->where('event_type', 'away_worked')->first()->metadata['note']);
        $this->actingAs($this->admin)->get(route('attendance.records'))->assertOk()->assertSee('10:00 AM–11:00 AM working: Client call on phone');
        $this->postJson('/api/attendance/away', ['from' => '2026-10-14 10:00:00', 'to' => '2026-10-14 11:00:00', 'reason' => 'idle', 'as' => 'work', 'note' => str_repeat('x', 201)])->assertUnprocessable();
    }

    public function test_the_idle_prompt_setting_reaches_the_app_and_the_label_is_plain_text(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'hours')
            ->assertSee("ask 'Are you sitting idle?' after (minutes)")->assertDontSeeHtml('&amp;quot;')
            ->set('idleMinutes', '1')->set('idleAfterMinutes', '1')->call('saveHours')->assertHasNoErrors();
        $this->actingAs($this->user, 'sanctum')->getJson('/api/me')
            ->assertJsonPath('data.policies.away_prompt_minutes', 1)->assertJsonPath('data.policies.idle_after_minutes', 1);
    }
}
