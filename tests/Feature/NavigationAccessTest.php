<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Navigation;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Renders every sidebar link for every demo role over real HTTP, so a menu
 * item can never point at a page that errors or is forbidden.
 */
class NavigationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(DemoDataSeeder::class);
    }

    public static function roles(): array
    {
        return [
            'master admin' => ['admin@example.com', 40],
            'hr' => ['hr@example.com', 30],
            'accounts' => ['accounts@example.com', 15],
            'manager' => ['manager1@example.com', 20],
            'employee' => ['employee1@example.com', 10],
        ];
    }

    #[DataProvider('roles')]
    public function test_every_visible_menu_link_renders_for_the_role(string $email, int $minimumLinks): void
    {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user);
        $links = collect(Navigation::for($user))->flatMap(fn ($group) => $group['items'])->pluck('url')->unique();
        $this->assertGreaterThanOrEqual($minimumLinks, $links->count(), "{$email} sees too few menu items");

        foreach ($links as $url) {
            $response = $this->get($url);
            $this->assertSame(200, $response->getStatusCode(), "{$email} → {$url} returned {$response->getStatusCode()}");
            $response->assertDontSee('Coming soon');
        }
        foreach (['/search?q=an', '/profile', '/notifications'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_employee_menu_hides_and_routes_block_admin_modules(): void
    {
        $employee = User::where('email', 'employee1@example.com')->firstOrFail();
        $this->actingAs($employee);
        $urls = collect(Navigation::for($employee))->flatMap(fn ($group) => $group['items'])->pluck('route');
        foreach (['people.employees', 'payroll.runs', 'system.roles', 'system.settings', 'security.audit', 'approvals.center', 'lifecycle.onboarding'] as $route) {
            $this->assertFalse($urls->contains($route), "Employee should not see {$route}");
        }
        foreach (['/people/employees', '/people/departments', '/attendance/records', '/work/tasks', '/payroll/runs', '/payroll/payments', '/approvals', '/compliance', '/lifecycle/onboarding', '/reports/payroll', '/reports/audit', '/security/audit', '/system/roles', '/system/settings'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/reports/payroll/export?from=2026-01-01&to=2026-01-31')->assertForbidden();
    }

    public function test_manager_sees_only_their_team(): void
    {
        $manager = User::where('email', 'manager2@example.com')->firstOrFail();
        $this->actingAs($manager)->get('/attendance/team')->assertOk()->assertSee('Sana Qureshi')->assertDontSee('Arjun Verma');
        $this->actingAs($manager)->get('/work/tasks?status=')->assertOk()->assertSee('Marketing site mockups')->assertDontSee('Invoice download feature');
        $this->actingAs($manager)->get('/people/employees/'.\App\Models\AttendanceEmployee::where('employee_code', 'SWS-101')->value('id'))->assertForbidden();
    }

    public function test_demo_data_is_consistent(): void
    {
        $this->assertSame(10, User::count());
        foreach (['Master Admin' => 'admin@example.com', 'HR' => 'hr@example.com', 'Accounts' => 'accounts@example.com', 'Manager' => 'manager1@example.com', 'Employee' => 'employee5@example.com'] as $role => $email) {
            $this->assertTrue(User::where('email', $email)->first()->hasRole($role), "{$email} should have {$role}");
        }
        $run = \App\Models\PayrollRun::firstOrFail();
        $this->assertSame('locked', $run->status);
        $this->assertGreaterThan(0, \App\Models\Payslip::count());
        $this->assertGreaterThan(0, \App\Models\SalaryAdvanceRepayment::count());
        $this->assertGreaterThan(0, \App\Models\AuditLog::count());
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('notifications')->count());
        $this->assertNotSame('demo-only-not-a-real-secret', \App\Models\CredentialVaultEntry::first()->getRawOriginal('secret_value'));
    }
}
