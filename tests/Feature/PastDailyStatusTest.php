<?php

namespace Tests\Feature;

use App\Livewire\People\EmployeeProfile;
use App\Livewire\Work\DailyStatus;
use App\Models\AttendanceEmployee;
use App\Models\AuditLog;
use App\Models\DailyWorkStatus;
use App\Models\User;
use App\Support\PastDailyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Daily status for earlier days": HR lets chosen people write a missed daily status for days in previous months.
 * Everyone else keeps the 2-day limit. Never today or later, never before the joining date.
 */
class PastDailyStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, string $joined = '2026-01-01'): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@company.test']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Emp {$code}", 'is_active' => true, 'user_id' => $user->id, 'joined_at' => $joined,
            'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
    }

    private function writeViaApi(AttendanceEmployee $e, string $date)
    {
        \Laravel\Sanctum\Sanctum::actingAs($e->user, ['*']);

        return $this->postJson('/api/daily-status/missed', ['work_date' => $date, 'late_reason' => 'Was on site with no laptop', 'summary' => 'Finished the payroll export']);
    }

    public function test_hr_turns_it_on_and_off_from_the_profile_and_it_is_audited(): void
    {
        $employee = $this->employee('PS1');

        Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $employee])
            ->set('pastStatusBy', 'months')->set('pastStatusMonths', 2)->set('pastStatusUntil', '2026-11-30')
            ->call('savePastStatus')->assertHasNoErrors();

        // 2 months back: from 1 Sep 2026; allowed until 30 Nov.
        $this->assertSame('2026-09-01', PastDailyStatus::earliest($employee));
        $this->assertSame('2026-11-30', PastDailyStatus::rule($employee)['until']);
        $log = AuditLog::where('action', 'daily_status.past_days_set')->where('subject_id', $employee->id)->firstOrFail();
        $this->assertSame(2, $log->metadata['months'] ?? null);

        Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $employee])->call('stopPastStatus');
        $this->assertNull(PastDailyStatus::rule($employee));
        $this->assertTrue(AuditLog::where('action', 'daily_status.past_days_off')->where('subject_id', $employee->id)->exists());
    }

    public function test_an_employee_cannot_turn_it_on_for_themself(): void
    {
        $employee = $this->employee('PS2');

        Livewire::actingAs($employee->user)->test(EmployeeProfile::class, ['employee' => $employee])
            ->set('pastStatusMonths', 3)->call('savePastStatus')->assertForbidden();
        $this->assertNull(PastDailyStatus::rule($employee));
    }

    public function test_allowed_employee_can_write_a_day_in_a_previous_month(): void
    {
        $employee = $this->employee('PS3');
        PastDailyStatus::set($employee, 2, null, null, $this->admin->id);

        // Website: pick the day, then reason and status.
        Livewire::actingAs($employee->user)->test(DailyStatus::class)
            ->assertSee('Add status for this day')
            ->set('pastDate', '2026-09-15')->call('openPastDay')->assertHasNoErrors()->assertSet('missed', '2026-09-15')
            ->set('lateReason', 'Was on site with no laptop')->call('missedNext')
            ->set('missedSummary', 'Finished the payroll export')->call('saveMissed')->assertHasNoErrors();
        $status = DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', '2026-09-15')->firstOrFail();
        $this->assertSame('Was on site with no laptop', $status->late_reason);

        // App: the same through the API, and the app is told how far back it may go.
        $this->writeViaApi($employee, '2026-10-05')->assertCreated();
        $this->getJson('/api/daily-status')->assertOk()->assertJsonPath('past_status_from', '2026-09-01');

        // Still not: before the allowed range, today, the future, or a day already written.
        $this->writeViaApi($employee, '2026-08-31')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->writeViaApi($employee, '2026-11-04')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->writeViaApi($employee, '2026-11-10')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->writeViaApi($employee, '2026-10-05')->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_never_before_the_joining_date(): void
    {
        $employee = $this->employee('PS4', '2026-10-10');
        PastDailyStatus::set($employee, 6, null, null, $this->admin->id);

        $this->assertSame('2026-10-10', PastDailyStatus::earliest($employee));
        $this->writeViaApi($employee, '2026-10-09')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->writeViaApi($employee, '2026-10-10')->assertCreated();
    }

    public function test_others_keep_the_two_day_limit(): void
    {
        $allowed = $this->employee('PS5');
        PastDailyStatus::set($allowed, 3, null, null, $this->admin->id);
        $other = $this->employee('PS6');

        Livewire::actingAs($other->user)->test(DailyStatus::class)
            ->assertDontSee('Add status for this day')
            ->set('pastDate', '2026-09-15')->call('openPastDay')->assertHasErrors('pastDate');
        $this->writeViaApi($other, '2026-09-15')->assertStatus(422)->assertJsonValidationErrors('date');
        $this->getJson('/api/daily-status')->assertOk()->assertJsonPath('past_status_from', null);
        $this->assertFalse(DailyWorkStatus::where('employee_id', $other->id)->exists());
    }

    public function test_it_stops_after_the_until_date(): void
    {
        $employee = $this->employee('PS7');
        PastDailyStatus::set($employee, null, '2026-08-01', '2026-11-05', $this->admin->id);
        $this->assertSame('2026-08-01', PastDailyStatus::earliest($employee));

        Carbon::setTestNow('2026-11-06 10:00:00');
        $this->assertNull(PastDailyStatus::earliest($employee));
        $this->writeViaApi($employee, '2026-09-15')->assertStatus(422)->assertJsonValidationErrors('date');
    }
}
