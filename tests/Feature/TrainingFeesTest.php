<?php

namespace Tests\Feature;

use App\Livewire\Training\Fees;
use App\Models\AttendanceEmployee;
use App\Models\SalaryProfile;
use App\Models\TrainingEnrollment;
use App\Models\TrainingFeePayment;
use App\Models\User;
use App\Services\HrWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Trainees pay a course fee (in installments) and are never on payroll or salary. */
class TrainingFeesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-23 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $code, string $type): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');
        $e = AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'employment_type' => $type]);
        SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 20000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 26, 'standard_daily_hours' => 8]);

        return $e;
    }

    public function test_trainees_are_not_on_payroll_or_salary(): void
    {
        $staff = $this->person('TF-1', 'full_time');
        $trainee = $this->person('TF-2', 'trainee');

        $run = app(HrWorkflowService::class)->calculatePayroll('2026-09-01', '2026-09-30', $this->admin->id);
        $paid = $run->items()->pluck('employee_id');
        $this->assertTrue($paid->contains($staff->id));
        $this->assertFalse($paid->contains($trainee->id), 'A trainee is not paid a salary');

        Livewire::actingAs($this->admin)->test(\App\Livewire\Payroll\Salary::class)->assertSee('Person TF-1')->assertDontSee('Person TF-2');
        $rows = app(\App\Services\ReportService::class)->rows($this->admin, 'salary', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $this->assertTrue($rows->contains('employee_code', 'TF-1'));
        $this->assertFalse($rows->contains('employee_code', 'TF-2'));
    }

    public function test_fees_are_paid_in_installments_with_receipts(): void
    {
        $trainee = $this->person('TF-3', 'trainee');

        $page = Livewire::actingAs($this->admin)->test(Fees::class)
            ->call('editCourse')->set('cName', 'Web Development')->set('cDuration', '6 months')->set('cFee', '30000')->call('saveCourse')->assertHasNoErrors()
            ->call('enroll')->assertSee('Person TF-3')
            ->set('employeeId', $trainee->id)->set('feeCourseId', \App\Models\TrainingFeeCourse::first()->id)->assertSet('totalFee', '30000')
            ->set('discount', '3000')->set('installments', '3')->call('saveEnrollment')->assertHasNoErrors();
        $enrollment = TrainingEnrollment::firstOrFail();
        $this->assertSame(27000.0, $enrollment->payable());

        // First installment by UPI: the form proposes a third of the fee.
        $page->call('pay', $enrollment->id)->assertSet('amount', '9000')
            ->set('method', 'upi')->call('savePayment')->assertHasErrors('reference')
            ->set('reference', 'UPI-123')->call('savePayment')->assertHasNoErrors();
        // Second in cash, more than the installment.
        $page->call('pay', $enrollment->id)->set('amount', '12000')->call('savePayment')->assertHasNoErrors();
        // Paying more than is due is refused.
        $page->call('pay', $enrollment->id)->assertSet('amount', '6000')->set('amount', '7000')->call('savePayment')->assertHasErrors('amount');

        $this->assertSame(['FEE-2026-0001', 'FEE-2026-0002'], TrainingFeePayment::orderBy('id')->pluck('receipt_no')->all());
        $this->assertSame([1, 2], TrainingFeePayment::orderBy('id')->pluck('installment_no')->all());
        $this->assertSame(6000.0, $enrollment->fresh()->balance());
        $page->assertSeeText('2 of 3 paid');
        $page->set('tab', 'payments')->assertSee('FEE-2026-0001');
        $page->assertSee('UPI-123');
        $page->assertSee('21,000.00');

        // The trainee sees their own fees and receipts.
        Livewire::actingAs($trainee->user)->test(\App\Livewire\Training\MyTraining::class)->assertSee('My course fees')->assertSee('FEE-2026-0002')->assertSee('due 6,000.00');
        // Ordinary employees cannot open the fees page.
        $this->actingAs($trainee->user)->get(route('training.fees'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('training.fees'))->assertOk()->assertSee('Course fees');
    }
}
