<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company owner logins: a login can be limited to one company (users.company_id; empty = all companies, as every
 * login so far). The "Company Owner" role has every permission except Roles & Permissions; logins.manage lets the
 * owner give the HR / Accounts / Manager / Employee roles to his own company's people.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'company_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('client_id')->constrained('companies')->nullOnDelete();
            });
        }
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) return;
        $now = now();
        $permission = DB::table('permissions')->where('name', 'logins.manage')->where('guard_name', 'web')->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => 'logins.manage', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $owner = DB::table('roles')->where('name', 'Company Owner')->where('guard_name', 'web')->value('id')
            ?: DB::table('roles')->insertGetId(['name' => 'Company Owner', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $give = function (int $roleId, $permissionIds) {
            foreach ($permissionIds as $id) {
                if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $id)->exists()) {
                    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $id]);
                }
            }
        };
        $give($owner, DB::table('permissions')->where('guard_name', 'web')->where('name', '!=', 'roles.manage')->pluck('id'));
        if ($admin = DB::table('roles')->where('name', 'Master Admin')->where('guard_name', 'web')->value('id')) $give($admin, [$permission]);
        rescue(fn () => app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(), null, false);
    }

    public function down(): void
    {
        if (Schema::hasTable('roles')) {
            $owner = DB::table('roles')->where('name', 'Company Owner')->value('id');
            if ($owner) {
                DB::table('role_has_permissions')->where('role_id', $owner)->delete();
                DB::table('model_has_roles')->where('role_id', $owner)->delete();
                DB::table('roles')->where('id', $owner)->delete();
            }
            $ids = DB::table('permissions')->where('name', 'logins.manage')->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
        if (Schema::hasColumn('users', 'company_id')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }
    }
};
