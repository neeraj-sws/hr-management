<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project can be billed up to a fixed number of hours a month (e.g. Source India: 46 hours); whatever the team
 * worked beyond that is carried into the next month instead of being invoiced now.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'monthly_cap_minutes')) {
            Schema::table('projects', fn (Blueprint $table) => $table->unsignedInteger('monthly_cap_minutes')->nullable()->after('hourly_rate'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'monthly_cap_minutes')) Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('monthly_cap_minutes'));
    }
};
