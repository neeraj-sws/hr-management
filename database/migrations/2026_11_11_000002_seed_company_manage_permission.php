<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::firstOrCreate(['name' => 'company.manage', 'guard_name' => 'web']);
        $masterAdmin = Role::where('name', 'Master Admin')->where('guard_name', 'web')->first();
        if ($masterAdmin && ! $masterAdmin->hasPermissionTo('company.manage')) {
            $masterAdmin->givePermissionTo($permission);
        }
        // Ensure Company Owner does NOT have this permission.
        $owner = Role::where('name', 'Company Owner')->where('guard_name', 'web')->first();
        $owner?->revokePermissionTo($permission);
    }

    public function down(): void
    {
        Permission::where('name', 'company.manage')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
