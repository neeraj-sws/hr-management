<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task progress (%), set by the assignee or the person who assigned it; when it is
 * empty the progress is worked out from subtasks or logged vs. estimated time.
 * Daily status gets "pending work" and "plan for tomorrow". Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->nullable();
        });
        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->text('pending')->nullable();
            $table->text('tomorrow_plan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->dropColumn(['pending', 'tomorrow_plan']);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};
