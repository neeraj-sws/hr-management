<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Company profile: code, logo, address, contact, status. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'code')) $table->string('code', 20)->nullable()->unique()->after('name');
            if (! Schema::hasColumn('companies', 'logo')) $table->string('logo', 255)->nullable()->after('code');
            if (! Schema::hasColumn('companies', 'address')) $table->string('address', 500)->nullable()->after('logo');
            if (! Schema::hasColumn('companies', 'phone')) $table->string('phone', 30)->nullable()->after('address');
            if (! Schema::hasColumn('companies', 'email')) $table->string('email', 120)->nullable()->after('phone');
            if (! Schema::hasColumn('companies', 'website')) $table->string('website', 255)->nullable()->after('email');
            if (! Schema::hasColumn('companies', 'owner_name')) $table->string('owner_name', 120)->nullable()->after('website');
            if (! Schema::hasColumn('companies', 'is_active')) $table->boolean('is_active')->default(true)->after('is_main');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['code', 'logo', 'address', 'phone', 'email', 'website', 'owner_name', 'is_active'] as $col) {
                if (Schema::hasColumn('companies', $col)) $table->dropColumn($col);
            }
        });
    }
};
