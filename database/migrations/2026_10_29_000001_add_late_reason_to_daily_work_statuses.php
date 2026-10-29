<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why a missed day's status was written late (given before writing it); its "not submitted" fine is then removed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->text('late_reason')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->dropColumn('late_reason');
        });
    }
};
