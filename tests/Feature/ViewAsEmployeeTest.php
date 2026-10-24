<?php

namespace Tests\Feature;

use App\Livewire\Account\Profile;
use App\Models\AttendanceEmployee;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\WebAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** View as employee: Master Admin only, works even when the website is closed for that person, logged, personal settings locked. */
class ViewAsEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $empUser;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->empUser = User::factory()->create(['name' => 'Abheeshri Parmar', 'email' => 'abheeshri@example.com']);
        $this->empUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'VA-1', 'name' => 'Abheeshri Parmar', 'email' => 'abheeshri@example.com', 'is_active' => true, 'user_id' => $this->empUser->id, 'joined_at' => '2026-01-01']);
        // The website is closed for employees (only HR and Master Admin), as on the live site.
        app(\App\Services\PeopleOperationsService::class)->setPolicy(WebAccessService::POLICY, 'security', 'Website access', ['mode' => 'roles', 'roles' => ['HR', 'Master Admin'], 'user_ids' => []], null, $this->admin->id, today()->toDateString());
    }

    public function test_master_admin_views_as_an_employee_and_returns(): void
    {
        $this->actingAs($this->admin)->get(route('people.employees.show', $this->employee))->assertSee('View as employee');
        $this->post(route('people.view-as', $this->employee))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->empUser);
        $this->get(route('dashboard'))->assertOk()->assertSee('You are viewing as')->assertSee('Return to my account');
        $this->assertTrue(AuditLog::where('action', 'user.impersonation_started')->where('actor_id', $this->admin->id)->where('subject_id', $this->empUser->id)->exists());

        // Anything done meanwhile is logged under the admin, with whose account it was.
        app(\App\Services\AuditLogger::class)->record('test.action');
        $log = AuditLog::where('action', 'test.action')->sole();
        $this->assertSame([$this->admin->id, $this->empUser->id], [$log->actor_id, $log->metadata['viewing_as_user_id']]);

        $this->post(route('people.view-as.stop'))->assertRedirect(route('people.employees.show', $this->employee->id));
        $this->assertAuthenticatedAs($this->admin);
        $this->assertTrue(AuditLog::where('action', 'user.impersonation_ended')->where('actor_id', $this->admin->id)->exists());
        $this->assertNull(session(ImpersonationService::SESSION_KEY));

        // The employee is still kept off the website on their own.
        $this->assertFalse(app(WebAccessService::class)->allows($this->empUser->fresh()));
    }

    public function test_personal_settings_are_locked_and_log_out_returns_to_the_admin(): void
    {
        $this->actingAs($this->admin)->post(route('people.view-as', $this->employee));
        Livewire::test(Profile::class)->call('toggleTwoFactor')->assertForbidden();
        // View only: a real Livewire request that saves is refused; filters and paging still work.
        $snapshot = fn (string $method) => ['components' => [['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => $method, 'params' => []]]]]];
        $this->withHeaders(['X-Livewire' => '1'])->postJson(route('default-livewire.update'), $snapshot('checkIn'))->assertForbidden();
        $this->flushHeaders()->get(route('attendance.my'))->assertOk();
        $this->post(route('people.view-as', $this->employee))->assertRedirect(); // any other form post is refused (back with a message)
        $this->assertFalse((bool) $this->empUser->fresh()->two_factor_enabled);
        $this->post(route('logout'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_only_master_admin_and_never_for_admins_or_inactive_people(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('HR');
        $this->actingAs($hr)->post(route('people.view-as', $this->employee))->assertForbidden();
        $this->actingAs($hr)->get(route('people.employees.show', $this->employee))->assertDontSee('View as employee');

        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('Master Admin');
        $adminEmployee = AttendanceEmployee::create(['employee_code' => 'VA-A', 'name' => 'Other Admin', 'is_active' => true, 'user_id' => $otherAdmin->id, 'joined_at' => '2026-01-01']);
        $this->actingAs($this->admin)->post(route('people.view-as', $adminEmployee))->assertForbidden();

        $this->employee->update(['is_active' => false]);
        $this->actingAs($this->admin)->post(route('people.view-as', $this->employee))->assertForbidden();
        $this->post(route('people.view-as.stop'))->assertNotFound();
    }
}
