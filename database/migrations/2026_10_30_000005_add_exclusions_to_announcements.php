<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Departments and teams an announcement leaves out (e.g. everyone except IT Web Info). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->json('excluded_department_ids')->nullable()->after('team_id');
            $table->json('excluded_team_ids')->nullable()->after('excluded_department_ids');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['excluded_department_ids', 'excluded_team_ids']);
        });
    }
};
