<?php

namespace Tests\Feature;

use App\Livewire\Compliance\Penalties;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeePenalty;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A fixed late fine (e.g. 500) after the start time + 15 minutes, with a start time per employee; the fine can
 * apply to everyone or to chosen employees only. The employee sees why, gives a reason, and HR / admin accept it
 * (the fine is removed) or not (it stays, with why) — both sides see the whole story on the Penalties page.
 */
class LateFineAppealTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private PeopleOperationsService $people;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->people = app(PeopleOperationsService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, string $start): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@company.test']);
        $user->assignRole('Employee');
        $employee = AttendanceEmployee::create(['employee_code' => $code, 'name' => "Emp {$code}", 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01',
            'work_start' => $start, 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => 30000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);

        return $employee;
    }

    private function day(AttendanceEmployee $employee, string $date, string $in, string $out = '19:00'): void
    {
        $active = Carbon::parse("{$date} {$in}")->diffInSeconds(Carbon::parse("{$date} {$out}"));
        AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => $date, 'check_in' => "{$date} {$in}:00", 'check_out' => "{$date} {$out}:00", 'active_seconds' => (int) $active, 'status' => 'completed']);
    }

    private function fine(AttendanceEmployee $employee, string $date): ?EmployeePenalty
    {
        return EmployeePenalty::where('employee_id', $employee->id)->whereDate('effective_on', $date)->first();
    }

    public function test_fixed_fine_after_own_start_time_plus_15_minutes(): void
    {
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['amount_per_minute' => 0, 'grace_minutes' => 15, 'max_amount' => 0, 'fixed_amount' => 500, 'deduct_now' => 1], null, $this->admin->id, '2026-01-01');
        $ten = $this->employee('A1', '10:00:00');
        $eleven = $this->employee('B1', '11:00:00');
        $this->day($ten, '2026-10-12', '10:15');    // inside the 15 minutes
        $this->day($ten, '2026-10-13', '10:16');    // 1 minute past: fined
        $this->day($eleven, '2026-10-13', '11:10'); // own start 11:00: not late
        $this->day($eleven, '2026-10-14', '11:40'); // own start 11:00 + 15: fined
        $this->people->assessCompliance('2026-10-12', '2026-10-14', $this->admin->id);

        $this->assertNull($this->fine($ten, '2026-10-12'));
        $this->assertNull($this->fine($eleven, '2026-10-13'));
        $fine = $this->fine($ten, '2026-10-13');
        $this->assertSame('500.00', (string) $fine->amount);
        $this->assertSame('approved', $fine->status, 'Deduct automatically');
        $this->assertSame('Late: came 10:16 AM (start 10:00 AM + 15 min allowed)', $fine->reason);
        $this->assertSame('Late: came 11:40 AM (start 11:00 AM + 15 min allowed)', $this->fine($eleven, '2026-10-14')->reason);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $ten->user_id]);
    }

    public function test_fine_only_for_a_chosen_employee(): void
    {
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['amount_per_minute' => 0, 'grace_minutes' => 15, 'max_amount' => 0, 'fixed_amount' => 0, 'deduct_now' => 0], null, $this->admin->id, '2026-01-01');
        $chosen = $this->employee('C1', '10:00:00');
        $other = $this->employee('D1', '10:00:00');
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['amount_per_minute' => 0, 'grace_minutes' => 15, 'max_amount' => 0, 'fixed_amount' => 500, 'deduct_now' => 1], $chosen->id, $this->admin->id, '2026-01-01');
        $this->day($chosen, '2026-10-13', '10:30');
        $this->day($other, '2026-10-13', '10:30');
        $this->people->assessCompliance('2026-10-13', '2026-10-13', $this->admin->id);

        $this->assertSame('500.00', (string) $this->fine($chosen, '2026-10-13')->amount);
        $this->assertNull($this->fine($other, '2026-10-13'));
    }

    public function test_reason_accepted_reverts_the_fine_and_both_sides_see_why(): void
    {
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['amount_per_minute' => 0, 'grace_minutes' => 15, 'max_amount' => 0, 'fixed_amount' => 500, 'deduct_now' => 1], null, $this->admin->id, '2026-01-01');
        $employee = $this->employee('E1', '10:00:00');
        $this->day($employee, '2026-10-13', '10:45');
        $this->people->assessCompliance('2026-10-13', '2026-10-13', $this->admin->id);
        $fine = $this->fine($employee, '2026-10-13');

        Livewire::actingAs($employee->user)->test(Penalties::class)
            ->assertSee('Why applied:')->assertSee('came 10:45 AM')->assertSee('Give reason')
            ->call('openAppeal', $fine->id)->set('appealReason', 'Metro stopped for 30 minutes')->call('saveAppeal')->assertHasNoErrors()
            ->assertSee('Metro stopped for 30 minutes')->assertSee('Reason waiting for HR')->assertDontSee('Give reason');

        $appeal = $fine->fresh()->appeal;
        Livewire::actingAs($this->admin)->test(Penalties::class)
            ->assertSee('Metro stopped for 30 minutes')->assertSee('Accept reason')
            ->call('openReview', $appeal->id, true)->set('reviewNote', 'Metro delay confirmed')->call('saveReview')->assertHasNoErrors();

        $this->assertSame('waived', $fine->fresh()->status);
        $this->assertSame('approved', $appeal->fresh()->status);
        Livewire::actingAs($employee->user)->test(Penalties::class)
            ->assertSee('Why applied:')->assertSee('Reverted:')->assertSee('the fine is removed')->assertSee('Metro delay confirmed');
    }

    public function test_reason_not_accepted_needs_a_note_and_the_fine_stays(): void
    {
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['amount_per_minute' => 0, 'grace_minutes' => 15, 'max_amount' => 0, 'fixed_amount' => 500, 'deduct_now' => 1], null, $this->admin->id, '2026-01-01');
        $employee = $this->employee('F1', '10:00:00');
        $this->day($employee, '2026-10-13', '10:45');
        $this->people->assessCompliance('2026-10-13', '2026-10-13', $this->admin->id);
        $fine = $this->fine($employee, '2026-10-13');
        $appeal = $this->people->appealPenalty($fine->load('employee'), $employee->user, 'Overslept, sorry');

        Livewire::actingAs($this->admin)->test(Penalties::class)
            ->call('openReview', $appeal->id, false)->call('saveReview')->assertHasErrors('reviewNote')
            ->set('reviewNote', 'Oversleeping is not a valid reason')->call('saveReview')->assertHasNoErrors();

        $this->assertSame('approved', $fine->fresh()->status);
        Livewire::actingAs($employee->user)->test(Penalties::class)
            ->assertSee('Reason not accepted')->assertSee('Oversleeping is not a valid reason')->assertSee('the fine stays');
    }

    public function test_one_rule_for_several_chosen_employees_with_hours_cut_others_keep_the_normal_rule(): void
    {
        // Everyone: the normal per-minute late cut (Rs 2 a minute after 15 minutes), no hours cut.
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['salary_rate' => 0, 'amount_per_minute' => 2, 'grace_minutes' => 15, 'max_amount' => 0, 'fixed_amount' => 0, 'deduct_now' => 1, 'hours_cut' => 0], null, $this->admin->id, '2026-01-01');
        $late = [$this->employee('L1', '10:00:00'), $this->employee('L2', '10:00:00'), $this->employee('L3', '10:00:00')];
        $normal = $this->employee('N1', '10:00:00');

        Livewire::actingAs($this->admin)->test(\App\Livewire\Compliance\Policies::class)
            ->set('overrideScope', 'employees')->set('overrideKey', 'attendance.late_penalty')->call('saveOverride')->assertHasErrors('overrideEmployeeIds')
            ->set('overrideEmployeeIds', array_map(fn ($e) => (string) $e->id, $late))
            ->set('overrideValues', ['salary_rate' => '0', 'amount_per_minute' => '0', 'grace_minutes' => '15', 'max_amount' => '0', 'fixed_amount' => '500', 'deduct_now' => '1', 'hours_cut' => '1'])
            ->set('overrideFrom', '2026-10-01')->call('saveOverride')->assertHasNoErrors();
        $this->assertSame(3, \App\Models\EmployeePolicyOverride::where('rule_key', 'attendance.late_penalty')->whereIn('employee_id', array_map(fn ($e) => $e->id, $late))->count());

        foreach ([...$late, $normal] as $e) $this->day($e, '2026-10-13', '10:45', '18:00'); // 45 min late, worked 7 h 15 m of 8 h
        // 20 minutes idle on the computer: shown for productivity only, never cut from the salary.
        \App\Models\AttendanceSession::where('employee_id', $late[1]->id)->update(['idle_seconds' => 1200, 'active_seconds' => 7 * 3600 + 15 * 60 - 1200]);
        $this->people->assessCompliance('2026-10-13', '2026-10-13', $this->admin->id);
        foreach ($late as $e) $this->assertSame('500.00', (string) $this->fine($e, '2026-10-13')->amount);
        $this->assertSame('60.00', (string) $this->fine($normal, '2026-10-13')->amount, 'Normal rule: (45 - 15) minutes x 2');

        // Payroll: the chosen ones also lose the time short of the working day; the others do not.
        $run = app(\App\Services\HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $short = fn ($e) => $run->items()->where('employee_id', $e->id)->firstOrFail()->calculation_breakdown['short_hours'];
        foreach ($late as $e) $this->assertSame(45 * 60, $short($e)['short_seconds'], '45 minutes short of the 8-hour day; idle is not cut');
        $this->assertGreaterThan(0, (float) $short($late[0])['amount']);
        $this->assertSame(0.0, (float) $short($normal)['amount']);
    }

    public function test_an_own_rule_can_be_edited_and_stopped_from_a_date(): void
    {
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['salary_rate' => 0, 'amount_per_minute' => 2, 'grace_minutes' => 15, 'max_amount' => 0, 'fixed_amount' => 0, 'deduct_now' => 1, 'hours_cut' => 0], null, $this->admin->id, '2026-01-01');
        $employee = $this->employee('S1', '10:00:00');
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['salary_rate' => 0, 'amount_per_minute' => 0, 'grace_minutes' => 16, 'max_amount' => 0, 'fixed_amount' => 500, 'deduct_now' => 1, 'hours_cut' => 1], $employee->id, $this->admin->id, '2026-10-01');
        $override = \App\Models\EmployeePolicyOverride::where('employee_id', $employee->id)->firstOrFail();

        // Edit: 500 -> 300; who and which rule stay the same.
        Livewire::actingAs($this->admin)->test(\App\Livewire\Compliance\Policies::class)
            ->call('editOverride', 'employee:'.$override->id)->assertSet('overrideEmployeeId', $employee->id)->assertSet('overrideValues.fixed_amount', '500')->assertSee('Save changes')
            ->set('overrideValues.fixed_amount', '300')->call('saveOverride')->assertHasNoErrors()->assertSet('editing', '');
        $this->assertSame(300.0, (float) $override->fresh()->configuration['fixed_amount']);
        $this->assertSame(1, \App\Models\EmployeePolicyOverride::where('employee_id', $employee->id)->count(), 'Changed in place, not a second rule');

        // Stop from 14 Oct: 13 Oct keeps her rule, 14 Oct follows the global rule again.
        Livewire::actingAs($this->admin)->test(\App\Livewire\Compliance\Policies::class)
            ->call('openStop', 'employee:'.$override->id)->set('stopFrom', '2026-10-14')->call('saveStop')->assertHasNoErrors()->assertSee('until 13 Oct 2026')->assertSee('stopped');
        $this->day($employee, '2026-10-13', '10:45');
        $this->day($employee, '2026-10-14', '10:45');
        $this->people->assessCompliance('2026-10-13', '2026-10-14', $this->admin->id);
        $this->assertSame('300.00', (string) $this->fine($employee, '2026-10-13')->amount);
        $this->assertSame('60.00', (string) $this->fine($employee, '2026-10-14')->amount, 'Global rule: (45 - 15) x 2');

        // Stopping from its own start date removes it completely.
        $this->people->stopOverride($override->fresh(), '2026-10-01', $this->admin->id);
        $this->assertSame(0, \App\Models\EmployeePolicyOverride::where('employee_id', $employee->id)->count());
    }

    public function test_with_late_check_in_at_1016_the_own_500_rule_still_fines_on_top_of_the_hours(): void
    {
        // As on the server: Settings > Payroll "Late check-in after" 10:16 pays a late day by the hours at work.
        $this->people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 25, 'late_checkin_hours_pay' => true, 'late_checkin_after' => '10:16', 'half_day_pay_from' => '2026-10-01'], null, $this->admin->id, '2026-01-01');
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['salary_rate' => 1, 'amount_per_minute' => 0, 'grace_minutes' => 16, 'max_amount' => 500, 'fixed_amount' => 0, 'deduct_now' => 1, 'hours_cut' => 0], null, $this->admin->id, '2026-01-01');
        $own = $this->employee('O1', '10:00:00');
        $other = $this->employee('O2', '10:00:00');
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['salary_rate' => 0, 'amount_per_minute' => 0, 'grace_minutes' => 16, 'max_amount' => 0, 'fixed_amount' => 500, 'deduct_now' => 1, 'hours_cut' => 1], $own->id, $this->admin->id, '2026-10-01');
        foreach ([$own, $other] as $e) $this->day($e, '2026-10-27', '10:30', '18:15'); // 7 h 45 m at work of 8 h
        // Approved half-day leave that day: being away was allowed, so no Rs 500.
        $this->day($own, '2026-10-26', '14:00', '19:00');
        \App\Models\LeaveRequest::create(['employee_id' => $own->id, 'leave_type_id' => \App\Models\LeaveType::firstOrFail()->id, 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-26', 'days' => 0.5, 'duration' => 'half', 'status' => 'approved', 'reason' => 'Doctor']);
        $this->people->assessCompliance('2026-10-26', '2026-10-27', $this->admin->id);

        $this->assertSame('500.00', (string) $this->fine($own, '2026-10-27')->amount, 'Own rule: Rs 500 on top of being paid by the hours');
        $this->assertNull($this->fine($other, '2026-10-27'), 'Everyone else: paid by the hours only, no fine (never cut twice)');
        $this->assertNull($this->fine($own, '2026-10-26'), 'Approved half-day leave: no Rs 500');

        $item = app(\App\Services\HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id)->items()->where('employee_id', $own->id)->firstOrFail();
        $this->assertArrayHasKey('2026-10-27', $item->calculation_breakdown['hours_paid_days'], 'The late day is still paid by the hours');
        $this->assertEqualsWithDelta(500.0, (float) $item->penalties_amount, 0.01);
    }

    public function test_the_mobile_app_lists_my_fines_and_sends_a_reason(): void
    {
        $this->people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['amount_per_minute' => 0, 'grace_minutes' => 16, 'max_amount' => 0, 'fixed_amount' => 500, 'deduct_now' => 1], null, $this->admin->id, '2026-01-01');
        $employee = $this->employee('M1', '10:00:00');
        $other = $this->employee('M2', '10:00:00');
        $this->day($employee, '2026-10-13', '10:45');
        $this->day($other, '2026-10-13', '10:45');
        $this->people->assessCompliance('2026-10-13', '2026-10-13', $this->admin->id);
        $fine = $this->fine($employee, '2026-10-13');

        \Laravel\Sanctum\Sanctum::actingAs($employee->user, ['*']);
        $list = $this->getJson('/api/my/fines?month=2026-10')->assertOk()->json('data');
        $this->assertCount(1, $list, 'Only my own fines');
        $this->assertSame([500.0, true, 'Late: came 10:45 AM (start 10:00 AM + 16 min allowed)'], [(float) $list[0]['amount'], $list[0]['can_give_reason'], $list[0]['reason']]);

        $this->postJson("/api/my/fines/{$fine->id}/reason", ['reason' => 'abc'])->assertStatus(422);
        $this->postJson('/api/my/fines/'.$this->fine($other, '2026-10-13')->id.'/reason', ['reason' => 'Not my fine at all'])->assertForbidden();
        $sent = $this->postJson("/api/my/fines/{$fine->id}/reason", ['reason' => 'Bus broke down on the way'])->assertCreated()->json('data');
        $this->assertSame(['pending', false], [$sent['my_reason']['status'], $sent['can_give_reason']]);

        // HR decides from the app's approvals: the fine is removed and the app shows why.
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $approval = collect($this->getJson('/api/approvals')->assertOk()->json('data'))->firstWhere('type', 'PenaltyAppeal');
        $this->assertStringContainsString('Bus broke down on the way', $approval['summary']);
        $this->postJson("/api/approvals/{$approval['id']}/decision", ['approve' => true])->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($employee->user, ['*']);
        $after = $this->getJson('/api/my/fines?month=2026-10')->json('data.0');
        $this->assertSame(['waived', 'approved'], [$after['status'], $after['my_reason']['status']]);
    }

    public function test_a_reason_can_be_given_for_any_fine_absence_and_manual(): void
    {
        $this->people->setPolicy('attendance.unauthorized_absence', 'compliance', 'Absence', ['amount_per_day' => 300, 'auto_deduct' => 1, 'also_fine' => 1], null, $this->admin->id, '2026-01-01');
        $employee = $this->employee('A9', '10:00:00');
        $this->people->assessCompliance('2026-10-12', '2026-10-12', $this->admin->id); // Monday, no check-in, no leave
        $absence = EmployeePenalty::where('employee_id', $employee->id)->where('source_key', 'like', 'absence:%')->firstOrFail();
        $this->assertSame(['approved', true], [$absence->status, $absence->canAppeal()]);
        $manual = $this->people->requestPenalty($employee, 200, 'Laptop left unlocked', $this->admin->id);
        $this->assertTrue($manual->fresh()->canAppeal());

        // Website: the employee gives a reason for the absence fine; HR accepts it and the fine is removed.
        Livewire::actingAs($employee->user)->test(Penalties::class)->assertSee('Give reason')
            ->call('openAppeal', $absence->id)->assertSee('Give your reason')->set('appealReason', 'Was in hospital, mailed HR from there')->call('saveAppeal')->assertHasNoErrors();
        Livewire::actingAs($this->admin)->test(Penalties::class)->assertSee('Was in hospital, mailed HR from there')
            ->call('openReview', $absence->fresh()->appeal->id, true)->call('saveReview')->assertHasNoErrors();
        $this->assertSame('waived', $absence->fresh()->status);

        // App: the same for the manual fine; HR does not accept it, the fine stays with the note.
        \Laravel\Sanctum\Sanctum::actingAs($employee->user, ['*']);
        $this->postJson("/api/my/fines/{$manual->id}/reason", ['reason' => 'It locks by itself after 5 minutes'])->assertCreated();
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $approval = collect($this->getJson('/api/approvals')->json('data'))->firstWhere('type', 'PenaltyAppeal');
        $this->assertSame('Fine reason', $approval['label']);
        $this->postJson("/api/approvals/{$approval['id']}/decision", ['approve' => false, 'note' => 'Policy says lock it yourself'])->assertOk();
        $this->assertSame(['pending', 'rejected', 'Policy says lock it yourself'], [$manual->fresh()->status, $manual->fresh()->appeal->status, $manual->fresh()->appeal->decision_note]);
    }
}
