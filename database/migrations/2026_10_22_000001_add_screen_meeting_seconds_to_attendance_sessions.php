<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time in an online meeting (Teams / Zoom / Google Meet: microphone or camera in use, or a meeting
 * window in front), reported by the desktop app. It is working time, kept apart from idle time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', fn (Blueprint $table) => $table->unsignedInteger('screen_meeting_seconds')->default(0)->after('screen_idle_seconds'));
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', fn (Blueprint $table) => $table->dropColumn('screen_meeting_seconds'));
    }
};
