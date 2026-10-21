<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Employee photo (stored privately; shown to signed-in users in place of the initials). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_employees', fn (Blueprint $table) => $table->string('photo_path')->nullable());
    }

    public function down(): void
    {
        Schema::table('attendance_employees', fn (Blueprint $table) => $table->dropColumn('photo_path'));
    }
};
