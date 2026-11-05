<?php

namespace Tests\Feature;

use App\Livewire\Compliance\Penalties;
use App\Models\AttendanceEmployee;
use App\Models\AuditLog;
use App\Models\EmployeePenalty;
use App\Models\PayrollRun;
use App\Models\SalaryAdjustment;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\DayWiseSalaryService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A fine taken back (Day-wise salary / Penalties), rejected or paid back by mistake can be restored by HR / admin:
 * it is deducted again on its own date, with its own amount and reason, and the employee is told.
 */
class RestoreFineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;
    private PeopleOperationsService $people;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->people = app(PeopleOperationsService::class);
        $user = User::factory()->create(['name' => 'Restore Person']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EMP-RF', 'name' => 'Restore Person', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 20000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fine(string $date, string $status = 'approved'): EmployeePenalty
    {
        return EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => $date, 'amount' => 500, 'reason' => 'Late: came 10:30 AM', 'requested_by' => $this->admin->id, 'status' => $status, 'source_key' => 'late:'.$this->employee->id.':'.$date]);
    }

    private function dayRow(string $month, string $date): array
    {
        return collect(app(DayWiseSalaryService::class)->month($this->employee->fresh(), $month)['rows'])->firstWhere('date', $date);
    }

    private function workflow(): HrWorkflowService
    {
        return app(HrWorkflowService::class);
    }

    public function test_a_fine_taken_back_by_mistake_is_restored_on_its_own_date(): void
    {
        $fine = $this->fine('2026-11-03');
        $this->people->removeFines([$fine->id], 'Taken back from day-wise salary', $this->admin->id);
        $this->assertSame('waived', $fine->fresh()->status);
        $this->assertSame(0.0, (float) $this->dayRow('2026-11', '2026-11-03')['fines_deducted']);

        Livewire::actingAs($this->admin)->test(Penalties::class)
            ->assertSee('Restore fine')
            ->call('openRestore', $fine->id)->call('saveRestore')->assertHasErrors('restoreNote')
            ->set('restoreNote', 'Removed by mistake')->call('saveRestore')->assertHasNoErrors()
            ->assertSet('restoringId', null)
            ->assertSee('Restored')->assertSee('Removed by mistake');

        $fine->refresh();
        $this->assertSame('approved', $fine->status);
        $this->assertSame('2026-11-03', $fine->effective_on->toDateString());
        $this->assertSame('500.00', (string) $fine->amount);
        $this->assertSame('Late: came 10:30 AM', $fine->reason);
        $this->assertNull($fine->payroll_item_id);
        $this->assertSame($this->admin->id, $fine->decided_by);
        $this->assertTrue($fine->isRestored());
        $this->assertTrue($fine->canBeRemoved(), 'It can be taken back again later');
        $this->assertFalse($fine->canBeRestored());

        $log = AuditLog::where('action', 'penalty.restored')->where('subject_id', $fine->id)->firstOrFail();
        $this->assertSame('waived', $log->metadata['from']);
        $this->assertSame($this->admin->id, $log->metadata['restored_by']);
        $this->assertSame('Removed by mistake', $log->metadata['note']);
        $this->assertStringContainsString('Taken back', $log->metadata['previous_note']);

        $notes = $this->employee->user->notifications()->where('data->kind', 'EmployeePenalty')->get();
        $this->assertCount(2, $notes, 'Told when taken back, and again when put back');
        $message = $notes->first(fn ($n) => str_contains($n->data['message'], 'put back'))->data['message'];
        $this->assertStringContainsString('fine of Rs 500.00 for 03 Nov 2026 (Late: came 10:30 AM) was put back and is deducted from your salary. Reason: Removed by mistake', $message);

        $row = $this->dayRow('2026-11', '2026-11-03');
        $this->assertSame(500.0, (float) $row['fines_deducted']);
        $this->assertTrue($row['fines'][0]['restored']);
    }

    public function test_a_rejected_fine_can_be_restored_and_a_draft_payroll_must_be_recalculated(): void
    {
        $fine = $this->fine('2026-10-06', 'pending');
        $this->people->decideFinancialRequest(EmployeePenalty::class, $fine->id, $this->admin->id, false, 'Not applicable');
        $this->assertSame('rejected', $fine->fresh()->status);
        $run = $this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $run->update(['needs_recalculation' => false]);

        $this->people->restorePenalty($fine, $this->admin->id, 'Rejected by mistake');

        $this->assertSame('approved', $fine->fresh()->status);
        $this->assertTrue((bool) $run->fresh()->needs_recalculation);
        $this->assertSame('rejected', AuditLog::where('action', 'penalty.restored')->firstOrFail()->metadata['from']);
    }

    public function test_a_paid_back_fine_restored_cancels_its_refund(): void
    {
        $fine = $this->fine('2026-10-05');
        $this->workflow()->approvePayroll($this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id), $this->admin->id);
        $this->people->removeFines([$fine->id], 'Taken back after the warning', $this->admin->id);
        $this->assertTrue($fine->fresh()->isPaidBack());
        $refund = SalaryAdjustment::where('employee_id', $this->employee->id)->where('kind', 'arrear')->firstOrFail();
        $item = $fine->fresh()->payroll_item_id;
        $this->assertNotNull($item);

        Livewire::actingAs($this->admin)->test(Penalties::class)->assertSee('Restore fine')
            ->call('openRestore', $fine->id)->set('restoreNote', 'Warning was for someone else')->call('saveRestore')->assertHasNoErrors();

        $fine->refresh();
        $this->assertSame('approved', $fine->status);
        $this->assertFalse($fine->isPaidBack());
        $this->assertSame($item, $fine->payroll_item_id, 'It stays in its locked payroll, where it was deducted');
        $this->assertSame('rejected', $refund->fresh()->status, 'The refund is cancelled');
        $this->assertSame($refund->id, AuditLog::where('action', 'penalty.restored')->firstOrFail()->metadata['refund_cancelled_id']);
        $this->assertSame(500.0, (float) $this->dayRow('2026-10', '2026-10-05')['fines_deducted']);
    }

    public function test_a_refund_already_paid_cannot_be_cancelled(): void
    {
        $fine = $this->fine('2026-10-05');
        $this->workflow()->approvePayroll($this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id), $this->admin->id);
        $this->people->removeFines([$fine->id], 'Taken back after the warning', $this->admin->id);
        $this->workflow()->approvePayroll($this->workflow()->calculatePayroll('2026-11-01', '2026-11-30', $this->admin->id), $this->admin->id);
        $refund = SalaryAdjustment::where('employee_id', $this->employee->id)->where('kind', 'arrear')->firstOrFail();
        $this->assertNotNull($refund->payroll_item_id);

        Livewire::actingAs($this->admin)->test(Penalties::class)
            ->call('openRestore', $fine->id)->set('restoreNote', 'Removed by mistake')->call('saveRestore')
            ->assertHasErrors('payroll');

        $this->assertTrue($fine->fresh()->isPaidBack());
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertFalse(AuditLog::where('action', 'penalty.restored')->exists());
    }

    public function test_a_removed_fine_of_a_locked_month_is_not_restored_and_says_why(): void
    {
        $fine = $this->fine('2026-09-08');
        $this->people->waivePenalty($fine, $this->admin->id, 'Taken back', false);
        PayrollRun::create(['period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'locked', 'created_by' => $this->admin->id]);

        try {
            $this->people->restorePenalty($fine, $this->admin->id, 'Removed by mistake');
            $this->fail('A locked month cannot change');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('The fine of 08 Sep 2026 cannot be restored: Payroll for 01 Sep', $e->errors()['payroll'][0]);
            $this->assertStringContainsString('is locked', $e->errors()['payroll'][0]);
        }
        $this->assertSame('waived', $fine->fresh()->status);
    }

    public function test_only_removed_fines_and_only_hr_can_restore(): void
    {
        $active = $this->fine('2026-11-04');
        $removed = $this->fine('2026-11-05');
        $this->people->waivePenalty($removed, $this->admin->id, 'Taken back', false);

        Livewire::actingAs($this->employee->user)->test(Penalties::class)->assertDontSee('Restore fine')
            ->call('openRestore', $removed->id)->assertForbidden();
        Livewire::actingAs($this->admin)->test(Penalties::class)->call('openRestore', $active->id)->assertForbidden();
        $this->assertSame('waived', $removed->fresh()->status);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->people->restorePenalty($active, $this->admin->id, 'Not removed at all');
    }

    public function test_a_fine_paid_back_after_an_accepted_reason_is_restored_and_its_refund_cancelled(): void
    {
        $fine = $this->fine('2026-10-05');
        $this->workflow()->approvePayroll($this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id), $this->admin->id);
        $appeal = \App\Models\PenaltyAppeal::create(['employee_penalty_id' => $fine->id, 'employee_id' => $this->employee->id, 'reason' => 'Train was late', 'status' => 'pending']);
        $this->people->decideAppeal($appeal, $this->admin->id, true);
        $refundId = $appeal->fresh()->refund_adjustment_id;
        $this->assertNotNull($refundId);
        $this->assertTrue($fine->fresh()->load('appeal.refundAdjustment')->canBeRestored());

        $this->people->restorePenalty($fine, $this->admin->id, 'Reason accepted by mistake');

        $this->assertSame('rejected', SalaryAdjustment::find($refundId)->status);
        $this->assertFalse($fine->fresh()->canBeRestored(), 'Not restored twice');
        Livewire::actingAs($this->admin)->test(Penalties::class)->assertSee('Reason accepted by mistake')->assertDontSee('Restore fine');
    }
}
