<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional selfie taken at check-in / check-out (stored privately on the local disk). Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->string('check_in_photo_path')->nullable();
            $table->string('check_out_photo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropColumn(['check_in_photo_path', 'check_out_photo_path']);
        });
    }
};
