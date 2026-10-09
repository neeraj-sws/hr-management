<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Desktop "screen time": seconds the computer was in use vs. untouched while checked in (no content is recorded). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->unsignedInteger('screen_active_seconds')->default(0);
            $table->unsignedInteger('screen_idle_seconds')->default(0);
            $table->dateTime('screen_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', fn (Blueprint $table) => $table->dropColumn(['screen_active_seconds', 'screen_idle_seconds', 'screen_reported_at']));
    }
};
