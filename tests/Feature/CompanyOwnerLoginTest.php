<?php

namespace Tests\Feature;

use App\Livewire\Payroll\Runs;
use App\Livewire\People\Departments;
use App\Livewire\People\Employees;
use App\Livewire\System\Roles;
use App\Models\AttendanceEmployee;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Support\Access;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Company owner logins: the ITWeb owner (and the logins he gives roles to) see ITWeb only — its employees, their
 * records and its payroll — and never anything of SWS, not even the company's name. The Super Admin still sees all.
 */
class CompanyOwnerLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $itweb;
    private AttendanceEmployee $sws;
    private AttendanceEmployee $preeti;
    private AttendanceEmployee $praveen;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->itweb = Company::create(['name' => 'ITWeb']);
        AttendanceEmployee::query()->update(['is_active' => false]);
        $this->sws = $this->employee('SWS-1', 'Swati Sharma');
        $this->preeti = $this->employee('EMP-19', 'Preeti Prajapati', $this->itweb->id);
        $this->praveen = $this->employee('EMP-20', 'Praveen Vaishnav', $this->itweb->id);
        $this->owner = $this->praveen->user;
        $this->owner->assignRole('Company Owner');
        $this->owner->forceFill(['company_id' => $this->itweb->id])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, string $name, ?int $company = null): AttendanceEmployee
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('Employee');
        $employee = AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'company_id' => $company, 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => 20000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8]);

        return $employee;
    }

    private function leave(AttendanceEmployee $employee): LeaveRequest
    {
        return LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => LeaveType::query()->value('id'), 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'days' => 1, 'reason' => 'Family function', 'status' => 'pending']);
    }

    public function test_the_owner_sees_only_his_company_and_its_records(): void
    {
        $swsLeave = $this->leave($this->sws);
        $itwebLeave = $this->leave($this->preeti);
        $swsRun = PayrollRun::create(['company_id' => null, 'period_starts_on' => '2026-10-01', 'period_ends_on' => '2026-10-31', 'status' => 'draft']);
        $itwebRun = PayrollRun::create(['company_id' => $this->itweb->id, 'period_starts_on' => '2026-10-01', 'period_ends_on' => '2026-10-31', 'status' => 'draft']);

        $this->actingAs($this->owner);
        $this->assertEqualsCanonicalizing([$this->preeti->id, $this->praveen->id], AttendanceEmployee::where('is_active', true)->pluck('id')->all());
        $this->assertNull(AttendanceEmployee::find($this->sws->id));
        $this->assertSame([$itwebLeave->id], LeaveRequest::pluck('id')->all());
        $this->assertNull(LeaveRequest::find($swsLeave->id));
        $this->assertSame([$itwebRun->id], PayrollRun::pluck('id')->all());
        $this->assertNull(PayrollRun::find($swsRun->id));
        $this->assertSame(['ITWeb'], Company::pluck('name')->all(), 'not even the other company\'s name');
        $this->assertSame(0, SalaryProfile::where('employee_id', $this->sws->id)->count());
        $this->assertTrue(Access::isAdmin($this->owner), 'administrator of his own company');
        $this->assertFalse($this->owner->can('roles.manage'));
    }

    public function test_the_super_admin_still_sees_every_company(): void
    {
        $this->leave($this->sws);
        $this->leave($this->preeti);

        $this->actingAs($this->admin);
        $this->assertEqualsCanonicalizing([$this->sws->id, $this->preeti->id, $this->praveen->id], AttendanceEmployee::where('is_active', true)->pluck('id')->all());
        $this->assertSame(2, LeaveRequest::count());
        $this->assertSame(['SWS', 'ITWeb'], Company::orderBy('id')->pluck('name')->all());
    }

    public function test_pages_and_the_app_show_the_owner_his_company_only(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('people.employees'))->assertOk()->assertSee('Preeti Prajapati')->assertDontSee('Swati Sharma');
        $this->get(route('people.employees.show', $this->sws))->assertNotFound();

        Sanctum::actingAs($this->owner, ['*']);
        $names = collect($this->getJson('/api/employees')->assertOk()->json('data') ?? $this->getJson('/api/employees')->json())->flatten()->all();
        $this->assertContains('Preeti Prajapati', $names);
        $this->assertNotContains('Swati Sharma', $names);
        $this->getJson('/api/employees/'.$this->sws->id)->assertNotFound();
    }

    public function test_the_owner_makes_payroll_for_his_company_only(): void
    {
        $this->actingAs($this->owner);
        Livewire::test(Runs::class)->assertSet('calcCompany', (string) $this->itweb->id)
            ->set('periodStart', '2026-10-01')->set('periodEnd', '2026-10-31')->call('calculate')->assertHasNoErrors();
        $run = PayrollRun::withoutGlobalScopes()->sole();
        $this->assertSame($this->itweb->id, $run->company_id);
        $this->assertEqualsCanonicalizing([$this->preeti->id, $this->praveen->id], $run->items()->withoutGlobalScopes()->pluck('employee_id')->all());

        // The main company's payroll is refused, even when asked for directly.
        $this->expectException(ValidationException::class);
        app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->owner->id, Company::main()->id);
    }

    public function test_employees_the_owner_adds_are_in_his_company_and_he_cannot_change_companies(): void
    {
        $this->actingAs($this->owner);
        Livewire::test(Employees::class)->call('create')
            ->set('employeeCode', 'EMP-30')->set('name', 'Naya Banda')->set('email', 'naya@example.com')->set('password', 'StrongPass12345')
            ->set('employmentType', 'full_time')->set('dailyHours', 8)->set('payBasis', 'monthly')->set('monthlySalary', 15000)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($this->itweb->id, AttendanceEmployee::withoutGlobalScopes()->where('employee_code', 'EMP-30')->value('company_id'));

        Livewire::test(Departments::class)->assertDontSee('SWS')->call('editCompany', $this->itweb->id)->assertForbidden();
    }

    public function test_the_owner_gives_roles_to_his_own_company_logins_only(): void
    {
        $this->actingAs($this->owner);
        $page = Livewire::test(Roles::class)->assertSet('tab', 'users')->assertSee('Preeti Prajapati')->assertDontSee('Swati Sharma')->assertDontSee('SWS Admin');

        $page->call('editUser', $this->preeti->user_id)->set('userRoles', ['Employee', 'HR', 'Master Admin', 'Company Owner'])->call('saveUserRoles')->assertHasNoErrors();
        $hr = $this->preeti->user->fresh();
        $this->assertEqualsCanonicalizing(['Employee', 'HR'], $hr->getRoleNames()->all(), 'never Master Admin or Company Owner');
        $this->assertSame($this->itweb->id, $hr->company_id, 'the HR login he made sees ITWeb only');

        Livewire::test(Roles::class)->call('editUser', $this->sws->user_id)->assertNotFound();
    }

    public function test_an_hr_login_of_one_company_sees_that_company_only(): void
    {
        $hr = $this->preeti->user;
        $hr->assignRole('HR');
        $hr->forceFill(['company_id' => $this->itweb->id])->save();

        $this->actingAs($hr);
        $this->get(route('people.employees'))->assertOk()->assertSee('Praveen Vaishnav')->assertDontSee('Swati Sharma');
    }

    public function test_the_super_admin_makes_an_owner_with_a_company(): void
    {
        $this->owner->removeRole('Company Owner');
        $this->owner->forceFill(['company_id' => null])->save();

        $this->actingAs($this->admin);
        Livewire::test(Roles::class)->call('editUser', $this->owner->id)->set('userRoles', ['Employee', 'Company Owner'])->set('userCompanyId', '')
            ->call('saveUserRoles')->assertHasErrors('userCompanyId');
        Livewire::test(Roles::class)->call('editUser', $this->owner->id)->set('userRoles', ['Employee', 'Company Owner'])->set('userCompanyId', (string) $this->itweb->id)
            ->call('saveUserRoles')->assertHasNoErrors();
        $this->assertTrue($this->owner->fresh()->hasRole('Company Owner'));
        $this->assertSame($this->itweb->id, $this->owner->fresh()->company_id);
    }

    public function test_admin_only_pages_are_closed_to_the_owner(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('billing.invoices'))->assertForbidden();
        $this->get(route('system.telegram'))->assertForbidden();
        $this->get(route('system.roles'))->assertOk();
        $this->assertFalse(Navigation::canAccess($this->owner, 'billing.invoices'));
        $this->assertTrue(Navigation::canAccess($this->owner, 'payroll.runs'));
        $this->assertTrue(Navigation::canAccess($this->admin, 'billing.invoices'));
    }

    private function kinds(User $user, string $kind)
    {
        return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === $kind)->values();
    }

    public function test_the_owner_is_told_only_about_his_company(): void
    {
        $swsLeave = $this->leave($this->sws);
        $itwebLeave = $this->leave($this->preeti);
        \App\Services\Notifier::send(\App\Services\Notifier::withPermission('leave.manage'), 'LeaveRequest', $swsLeave->id, 'Swati Sharma asked for leave.');
        \App\Services\Notifier::send(\App\Services\Notifier::withPermission('leave.manage'), 'LeaveRequest', $itwebLeave->id, 'Preeti Prajapati asked for leave.');

        $this->assertSame(['Preeti Prajapati asked for leave.'], $this->kinds($this->owner, 'LeaveRequest')->pluck('data.message')->all());
        $this->assertCount(2, $this->kinds($this->admin, 'LeaveRequest'), 'the Super Admin still hears of both');

        // The daily "not checked in" list: his people only (and never himself, the owner).
        $this->praveen->update(['employment_type' => 'owner']);
        app(\App\Services\AttendanceAlertService::class)->absences(Carbon::parse('2026-11-10 12:00:00'));
        $absent = $this->kinds($this->owner, 'AttendanceAbsent')->firstWhere('data.record_id', 0);
        $this->assertNotNull($absent);
        $this->assertStringContainsString('Preeti Prajapati', $absent->data['message']);
        $this->assertStringNotContainsString('Swati Sharma', $absent->data['message']);
        $this->assertStringNotContainsString('Praveen Vaishnav', $absent->data['message']);
        $this->assertStringContainsString('Swati Sharma', $this->kinds($this->admin, 'AttendanceAbsent')->firstWhere('data.record_id', 0)->data['message']);
    }

    public function test_the_owner_is_not_on_payroll_or_attendance_alerts(): void
    {
        $this->praveen->update(['employment_type' => 'owner']);
        $this->actingAs($this->owner);
        Livewire::test(Runs::class)->set('periodStart', '2026-10-01')->set('periodEnd', '2026-10-31')->call('calculate')->assertHasNoErrors();
        $this->assertSame([$this->preeti->id], PayrollRun::sole()->items()->pluck('employee_id')->all(), 'the owner is not paid a salary');
        $this->assertFalse(AttendanceEmployee::worksHere()->whereKey($this->praveen->id)->exists());
        // Still listed under People > Employees, marked Owner.
        $this->get(route('people.employees'))->assertOk()->assertSee('Praveen Vaishnav')->assertSee('Owner');
    }

    public function test_audit_logs_and_salary_payments_of_the_other_company_are_hidden(): void
    {
        $audit = app(\App\Services\AuditLogger::class);
        $this->actingAs($this->admin);
        $audit->record('leave.approved', $this->leave($this->sws));
        $audit->record('leave.approved', $this->leave($this->preeti));
        $this->actingAs($this->sws->user);
        $audit->record('profile.updated', $this->sws);

        $swsRun = PayrollRun::create(['company_id' => null, 'period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'locked']);
        $item = \App\Models\PayrollItem::create(['payroll_run_id' => $swsRun->id, 'employee_id' => $this->sws->id, 'gross_amount' => 20000, 'net_amount' => 20000, 'calculation_data' => ['working_days' => 25]]);
        \App\Models\SalaryPayment::create(['payroll_item_id' => $item->id, 'amount' => 20000, 'paid_on' => '2026-10-01', 'method' => 'bank_transfer']);

        $this->actingAs($this->owner);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'leave.approved')->count(), 'only the ITWeb leave');
        $this->assertSame(0, \App\Models\AuditLog::where('action', 'profile.updated')->count());
        $this->assertSame(0, \App\Models\SalaryPayment::count());
        $this->get(route('security.audit'))->assertOk()->assertDontSee('Swati Sharma');
    }

    public function test_announcements_of_one_company_stay_in_it(): void
    {
        $this->actingAs($this->owner);
        Livewire::test(\App\Livewire\Company\Announcements::class)->call('edit')
            ->set('title', 'ITWeb team lunch')->set('body', 'Friday 2 pm')->set('audience', 'all')->call('save')->assertHasNoErrors();
        $mine = \App\Models\Announcement::where('title', 'ITWeb team lunch')->sole();
        $this->assertSame($this->itweb->id, $mine->company_id);
        $this->assertCount(1, $this->kinds($this->preeti->user, 'Announcement'));
        $this->assertCount(0, $this->kinds($this->sws->user, 'Announcement'), 'SWS people do not get it');

        // The admin's announcement for all companies: everyone sees it, the owner cannot change it.
        $this->actingAs($this->admin);
        $all = app(\App\Services\CompanyService::class)->saveAnnouncement(['title' => 'Diwali holiday', 'body' => 'Office closed', 'audience' => 'all', 'is_pinned' => false], $this->admin);
        $this->assertNull($all->company_id);
        $this->actingAs($this->owner);
        Livewire::test(\App\Livewire\Company\Announcements::class)->assertSee('Diwali holiday')->call('edit', $all->id)->assertForbidden();
        $this->assertSame(2, \App\Models\Announcement::visibleTo($this->preeti)->count());
        $this->assertSame(1, \App\Models\Announcement::withoutGlobalScopes()->visibleTo($this->sws)->count(), 'SWS sees only the one for all companies');
    }
}
