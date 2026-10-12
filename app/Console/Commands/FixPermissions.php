<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Re-creates every permission and gives the Master Admin role all of them,
 * then clears the permission cache. No password or seed-data requirement, so
 * it is safe to run on a live site to repair a role that is missing
 * permissions (e.g. monitoring.view, roles.manage).
 */
class FixPermissions extends Command
{
    protected $signature = 'sws:fix-permissions';
    protected $description = 'Ensure all permissions exist and Master Admin has every one of them';

    public function handle(PermissionRegistrar $registrar): int
    {
        $registrar->forgetCachedPermissions();

        $permissions = collect(DatabaseSeeder::PERMISSIONS)
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        $this->info('Permissions present: '.$permissions->count());

        $admin = Role::firstOrCreate(['name' => 'Master Admin', 'guard_name' => 'web']);
        $admin->syncPermissions($permissions);
        $this->info('Master Admin now has: '.$admin->permissions()->count().' permissions.');

        // Re-apply the other roles' permission sets too.
        foreach (DatabaseSeeder::ROLE_PERMISSIONS as $role => $names) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions($permissions->whereIn('name', $names));
        }

        $registrar->forgetCachedPermissions();

        $admins = User::role('Master Admin')->pluck('email');
        $this->info('Master Admin users: '.($admins->isEmpty() ? '(none)' : $admins->join(', ')));
        $this->info('Done. Reload the site.');

        return self::SUCCESS;
    }
}
