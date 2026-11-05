<?php

namespace Tests\Feature;

use App\Livewire\Payroll\Runs;
use App\Livewire\People\Departments;
use App\Livewire\People\Employees;
use App\Models\AttendanceEmployee;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PayrollLockGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Employees of two companies (SWS, the main one, and ITWeb): payroll is calculated, approved and locked per company,
 * and a locked payroll of one company does not freeze the other company's days.
 */
class CompanyPayrollTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $itweb;
    private AttendanceEmployee $sws;
    private AttendanceEmployee $preeti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->itweb = Company::create(['name' => 'ITWeb']);
        // Only these two are on payroll in this test.
        AttendanceEmployee::query()->update(['is_active' => false]);
        $this->sws = $this->employee('SWS-1', 'Main Person');
        $this->preeti = $this->employee('EMP-19', 'Preeti Prajapati', $this->itweb->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, string $name, ?int $company = null): AttendanceEmployee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee = AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'company_id' => $company, 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => 20000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8]);

        return $employee;
    }

    private function workflow(): HrWorkflowService
    {
        return app(HrWorkflowService::class);
    }

    public function test_the_main_company_exists_and_everyone_without_a_company_is_in_it(): void
    {
        $main = Company::main();
        $this->assertSame('SWS', $main->name);
        $this->assertNull($this->sws->payrollCompanyId());
        $this->assertSame($this->itweb->id, $this->preeti->payrollCompanyId());
        $this->assertSame([$this->sws->id], AttendanceEmployee::where('is_active', true)->inPayrollCompany(null)->pluck('id')->all());
        $this->assertSame([$this->preeti->id], AttendanceEmployee::where('is_active', true)->inPayrollCompany($this->itweb->id)->pluck('id')->all());
    }

    public function test_each_company_has_its_own_payroll_for_the_same_month(): void
    {
        $sws = $this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $itweb = $this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id, $this->itweb->id);

        $this->assertNotSame($sws->id, $itweb->id);
        $this->assertNull($sws->company_id);
        $this->assertSame($this->itweb->id, $itweb->company_id);
        $this->assertSame([$this->sws->id], $sws->items->pluck('employee_id')->all());
        $this->assertSame([$this->preeti->id], $itweb->items->pluck('employee_id')->all());
        // Choosing the main company by its id is the same main payroll (recalculated, not a second one).
        $again = $this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id, Company::main()->id);
        $this->assertSame($sws->id, $again->id);
        $this->assertSame(2, PayrollRun::count());
        // Overlapping dates are still refused inside one company.
        $this->expectException(ValidationException::class);
        $this->workflow()->calculatePayroll('2026-10-15', '2026-11-05', $this->admin->id, $this->itweb->id);
    }

    public function test_a_locked_payroll_of_one_company_does_not_freeze_the_other(): void
    {
        $run = $this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id, $this->itweb->id);
        $this->workflow()->approvePayroll($run, $this->admin->id);
        $this->workflow()->lockPayroll($run, $this->admin->id);

        app(PayrollLockGuard::class)->assertOpen($this->sws->id, '2026-10-12', 'attendance');
        try {
            app(PayrollLockGuard::class)->assertOpen($this->preeti->id, '2026-10-12', 'attendance');
            $this->fail('ITWeb October is locked');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payroll', $e->errors());
        }
        // SWS October can still be calculated, approved and locked on its own.
        $sws = $this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $this->assertSame('draft', $sws->status);
    }

    public function test_pending_requests_of_another_company_do_not_hold_up_a_lock(): void
    {
        $type = LeaveType::firstOrFail();
        LeaveRequest::create(['employee_id' => $this->sws->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'days' => 1, 'duration' => 'full', 'reason' => 'Fever', 'status' => 'pending']);
        $itweb = $this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id, $this->itweb->id);
        $this->workflow()->approvePayroll($itweb, $this->admin->id);
        $this->assertSame('locked', $this->workflow()->lockPayroll($itweb, $this->admin->id)->status);

        $sws = $this->workflow()->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id);
        $this->workflow()->approvePayroll($sws, $this->admin->id);
        $this->expectException(ValidationException::class);
        $this->workflow()->lockPayroll($sws, $this->admin->id);
    }

    public function test_payroll_page_filters_and_calculates_by_company(): void
    {
        Livewire::actingAs($this->admin)->test(Runs::class)
            ->assertSee('All companies')
            ->set('calcCompany', (string) $this->itweb->id)->set('periodStart', '2026-10-01')->set('periodEnd', '2026-10-31')
            ->call('calculate')->assertHasNoErrors()
            ->assertSeeHtml('ITWeb · <!--[if ENDBLOCK]><![endif]-->01 Oct 2026')->assertSee('Preeti Prajapati')->assertDontSee('Main Person');
        $itweb = PayrollRun::forCompany($this->itweb->id)->firstOrFail();

        Livewire::actingAs($this->admin)->test(Runs::class)
            ->set('calcCompany', (string) Company::main()->id)->set('periodStart', '2026-10-01')->set('periodEnd', '2026-10-31')
            ->call('calculate')->assertHasNoErrors()->assertSee('Main Person');
        $sws = PayrollRun::forCompany(null)->firstOrFail();

        Livewire::actingAs($this->admin)->test(Runs::class)->set('company', (string) $this->itweb->id)
            ->assertViewHas('runs', fn ($runs) => $runs->pluck('id')->all() === [$itweb->id])
            ->set('company', (string) Company::main()->id)
            ->assertViewHas('runs', fn ($runs) => $runs->pluck('id')->all() === [$sws->id])
            ->set('company', '')
            ->assertViewHas('runs', fn ($runs) => $runs->count() === 2);
    }

    public function test_one_company_shows_no_company_choices(): void
    {
        $this->itweb->employees()->update(['company_id' => null]);
        $this->itweb->delete();
        Livewire::actingAs($this->admin)->test(Runs::class)->assertDontSee('All companies');
    }

    public function test_companies_are_added_on_departments_and_chosen_on_the_employee(): void
    {
        Livewire::actingAs($this->admin)->test(Departments::class)
            ->assertSee('Companies')->assertSee('ITWeb')
            ->set('companyName', 'ITWeb')->call('saveCompany')->assertHasErrors('companyName')
            ->set('companyName', 'Other Co')->call('saveCompany')->assertHasNoErrors()
            ->assertSee('Other Co');
        $other = Company::where('name', 'Other Co')->firstOrFail();

        Livewire::actingAs($this->admin)->test(Employees::class)
            ->call('edit', $this->sws->id)->assertSet('companyId', null)
            ->set('companyId', $other->id)->call('save')->assertHasNoErrors();
        $this->assertSame($other->id, $this->sws->fresh()->company_id);

        // Back to the main company: stored as "no company".
        Livewire::actingAs($this->admin)->test(Employees::class)
            ->call('edit', $this->sws->id)->assertSet('companyId', $other->id)
            ->set('companyId', Company::main()->id)->call('save')->assertHasNoErrors();
        $this->assertNull($this->sws->fresh()->company_id);
    }
}
