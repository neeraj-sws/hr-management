<?php

namespace Tests\Feature;

use App\Livewire\Work\DailyStatus;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\DailyWorkStatus;
use App\Models\EmployeePenalty;
use App\Models\User;
use App\Services\PeopleOperationsService;
use App\Support\DailyStatusRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A daily status not written: Rs 500 fine and a next-day notice. Within 2 days the person gives the reason first, then
 * writes that day's status: the fine is removed at once, everyone concerned is told and the day's e-mail goes out.
 * "Not compulsory" people are never fined.
 */
class MissedDailyStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private PeopleOperationsService $people;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->people = app(PeopleOperationsService::class);
        $this->people->setPolicy('daily_status.missing', 'compliance', 'Daily status', ['amount_per_day' => 500, 'grace_hours' => 3], null, $this->admin->id, '2026-01-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, ?AttendanceEmployee $manager = null): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@company.test']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Emp {$code}", 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'manager_id' => $manager?->id,
            'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
    }

    private function worked(AttendanceEmployee $e, string $date): void
    {
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => $date, 'check_in' => "{$date} 10:00:00", 'check_out' => "{$date} 18:00:00", 'active_seconds' => 8 * 3600, 'status' => 'completed']);
    }

    private function fine(AttendanceEmployee $e, string $date): ?EmployeePenalty
    {
        return EmployeePenalty::where('employee_id', $e->id)->whereDate('effective_on', $date)->where('source_key', 'like', 'no_status:%')->first();
    }

    public function test_missed_status_fine_is_removed_once_written_with_a_reason(): void
    {
        $manager = $this->employee('MGR');
        $employee = $this->employee('MS1', $manager);
        $this->worked($employee, '2026-10-19');
        $this->people->assessCompliance('2026-10-19', '2026-10-19', $this->admin->id);

        // The fine and the next-day notice with a link to write the day.
        $fine = $this->fine($employee, '2026-10-19');
        $this->assertSame(['500.00', 'pending'], [(string) $fine->amount, $fine->status]);
        $notice = $employee->user->notifications()->where('data->kind', 'DailyStatusMissed')->firstOrFail();
        $this->assertStringContainsString('You did not write your daily status for 19 Oct', $notice->data['message']);
        $this->assertStringContainsString('missed=2026-10-19', (string) $notice->data['url']);

        // App: the day is offered; the reason comes first.
        \Laravel\Sanctum\Sanctum::actingAs($employee->user, ['*']);
        $this->assertSame(['2026-10-19'], $this->getJson('/api/daily-status')->assertOk()->json('missed_writable'));
        $this->postJson('/api/daily-status/missed', ['work_date' => '2026-10-19', 'summary' => 'Finished the invoice screen'])->assertStatus(422)->assertJsonValidationErrors('late_reason');
        $this->postJson('/api/daily-status/missed', ['work_date' => '2026-10-19', 'late_reason' => 'Left early for the doctor and forgot', 'summary' => 'Finished the invoice screen'])->assertCreated();

        $status = DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', '2026-10-19')->firstOrFail();
        $this->assertSame('Left early for the doctor and forgot', $status->late_reason);
        $this->assertSame('waived', $fine->fresh()->status, 'The fine is removed at once, no HR decision');
        $this->assertSame(['approved', null], [$fine->fresh()->appeal->status, $fine->fresh()->appeal->decided_by]);
        $this->assertStringContainsString('Why it was late: "Left early for the doctor and forgot". The fine of 500.00 was removed.', $manager->user->notifications()->where('data->kind', 'DailyStatusSubmitted')->firstOrFail()->data['message']);
        $this->assertSame([], $this->getJson('/api/daily-status')->json('missed_writable'));

        // Running the check again makes no new fine ("written late" with a reason is not late).
        $this->people->assessCompliance('2026-10-19', '2026-10-19', $this->admin->id);
        $this->assertSame(1, EmployeePenalty::where('employee_id', $employee->id)->count());
    }

    public function test_website_two_steps_and_the_two_day_limit(): void
    {
        $employee = $this->employee('MS2');
        foreach (['2026-10-16', '2026-10-18', '2026-10-19'] as $d) $this->worked($employee, $d);
        $this->assertSame(['2026-10-19', '2026-10-18'], app(\App\Services\WorkService::class)->missedStatusDays($employee), 'Only the last 2 days');

        Livewire::actingAs($employee->user)->test(DailyStatus::class)
            ->assertSee('Write the missed day')
            ->call('openMissed', '2026-10-18')->assertSee('Step 1 of 2')
            ->call('missedNext')->assertHasErrors('lateReason')
            ->set('lateReason', 'Power cut at home in the evening')->call('missedNext')->assertSet('missedStep', 2)->assertSee('Step 2 of 2')
            ->set('missedSummary', 'Reviewed three pull requests')->call('saveMissed')->assertHasNoErrors()->assertSet('missed', '');
        $this->assertSame('Power cut at home in the evening', DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', '2026-10-18')->value('late_reason'));

        \Laravel\Sanctum\Sanctum::actingAs($employee->user, ['*']);
        $this->postJson('/api/daily-status/missed', ['work_date' => '2026-10-16', 'late_reason' => 'Too late now', 'summary' => 'Old work day'])->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_not_compulsory_means_no_fine(): void
    {
        $employee = $this->employee('MS3');
        $this->people->setPolicy(DailyStatusRule::KEY, 'work', 'Daily status', ['required' => false], $employee->id, $this->admin->id, '2026-01-01');
        $this->worked($employee, '2026-10-19');
        $this->people->assessCompliance('2026-10-19', '2026-10-19', $this->admin->id);
        $this->assertNull($this->fine($employee, '2026-10-19'));
        $this->assertSame([], app(\App\Services\WorkService::class)->missedStatusDays($employee));
    }
}
