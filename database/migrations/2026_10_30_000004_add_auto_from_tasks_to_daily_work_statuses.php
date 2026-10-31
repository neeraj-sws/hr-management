<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A daily status the system wrote from the day's tasks because the person did not write one by the evening. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->boolean('auto_from_tasks')->default(false)->after('late_reason');
        });
    }

    public function down(): void
    {
        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->dropColumn('auto_from_tasks');
        });
    }
};
