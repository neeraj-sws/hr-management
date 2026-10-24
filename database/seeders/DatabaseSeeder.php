<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use App\Models\PolicyRule;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public const PERMISSIONS = [
        'hr.manage', 'employees.manage', 'teams.manage', 'projects.manage', 'tasks.manage', 'attendance.view',
        'leave.manage', 'leave.approve', 'overtime.manage', 'approvals.manage',
        'payroll.manage', 'payroll.approve', 'salary.adjust', 'salary.advance',
        'penalties.manage', 'policies.manage', 'performance.review', 'reports.view', 'audit.view',
        'notes.manage', 'credentials.manage', 'credentials.view', 'lifecycle.manage', 'roles.manage',
        'attendance.correct', 'shifts.manage', 'expenses.manage', 'announcements.manage', 'recruitment.manage', 'training.manage', 'training.assign', 'monitoring.view', 'monitoring.manage', 'monitoring.team', 'monitoring.capture',
    ];

    public const ROLE_PERMISSIONS = [
        'HR' => ['employees.manage', 'teams.manage', 'projects.manage', 'tasks.manage', 'attendance.view', 'leave.manage', 'overtime.manage', 'approvals.manage', 'payroll.approve', 'salary.adjust', 'salary.advance', 'penalties.manage', 'policies.manage', 'performance.review', 'reports.view', 'audit.view', 'notes.manage', 'credentials.view', 'lifecycle.manage', 'attendance.correct', 'shifts.manage', 'expenses.manage', 'announcements.manage', 'recruitment.manage', 'training.manage', 'training.assign', 'monitoring.view', 'monitoring.team', 'monitoring.capture'],
        'Accounts' => ['payroll.manage', 'salary.adjust', 'salary.advance', 'penalties.manage', 'reports.view', 'audit.view', 'credentials.view', 'expenses.manage'],
        'Manager' => ['projects.manage', 'tasks.manage', 'attendance.view', 'leave.approve', 'overtime.manage', 'approvals.manage', 'performance.review', 'reports.view', 'notes.manage', 'credentials.view', 'training.assign', 'monitoring.team'],
        'Employee' => ['credentials.view'],
    ];

    /** Roles, permissions, the first administrator and baseline configuration. */
    public function run(): void
    {
        $seedPassword = (string) env('SEED_USER_PASSWORD', '');
        if (strlen($seedPassword) < 12) {
            throw new \RuntimeException('Set SEED_USER_PASSWORD to a unique value of at least 12 characters before seeding.');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = collect(self::PERMISSIONS)->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        Role::firstOrCreate(['name' => 'Master Admin', 'guard_name' => 'web'])->syncPermissions($permissions);
        foreach (self::ROLE_PERMISSIONS as $roleName => $names) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])->syncPermissions($permissions->whereIn('name', $names));
        }

        $admin = User::query()->firstOrCreate(
            ['email' => env('SEED_USER_EMAIL', 'admin@example.com')],
            ['name' => env('SEED_USER_NAME', 'SWS Admin'), 'password' => Hash::make($seedPassword), 'is_active' => true]
        );
        $admin->assignRole('Master Admin');

        LeaveType::firstOrCreate(['name' => 'Annual Leave'], ['annual_allowance' => 20, 'carry_forward' => true, 'carry_forward_limit' => 5, 'paid' => true, 'is_active' => true]);
        LeaveType::firstOrCreate(['name' => 'Unpaid Leave'], ['annual_allowance' => 0, 'carry_forward' => false, 'carry_forward_limit' => 0, 'paid' => false, 'is_active' => true]);

        // Employer-configurable defaults; adjust under Settings. No statutory rates are seeded.
        PolicyRule::firstOrCreate(['rule_key' => 'overtime.multiplier'], ['category' => 'overtime', 'name' => 'Overtime multiplier', 'configuration' => ['multiplier' => 1.5], 'is_active' => true]);
        PolicyRule::firstOrCreate(['rule_key' => 'leave.advance_limit'], ['category' => 'leave', 'name' => 'Advance leave limit', 'configuration' => ['max_days' => 5], 'is_active' => true]);
        PolicyRule::firstOrCreate(['rule_key' => 'attendance.late_penalty'], ['category' => 'compliance', 'name' => 'Late attendance penalty', 'configuration' => ['amount_per_minute' => 0, 'grace_minutes' => 0, 'max_amount' => 0], 'is_active' => true]);
        PolicyRule::firstOrCreate(['rule_key' => 'payroll.statutory'], ['category' => 'payroll', 'name' => 'Statutory components', 'configuration' => ['components' => []], 'is_active' => true]);

        // Demo data: on by default for APP_ENV=local, opt-in elsewhere; SEED_DEMO_DATA=false always skips it.
        $demo = env('SEED_DEMO_DATA');
        if ($demo === null ? app()->environment('local') : filter_var($demo, FILTER_VALIDATE_BOOL)) {
            $this->call(DemoDataSeeder::class);
            $this->call(HrmsExtensionsDemoSeeder::class);
        }
    }
}
