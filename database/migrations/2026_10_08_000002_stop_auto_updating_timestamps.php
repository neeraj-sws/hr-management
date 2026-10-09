<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MariaDB/MySQL (explicit_defaults_for_timestamp=OFF) silently gives the first
 * NOT NULL TIMESTAMP column of a table "ON UPDATE CURRENT_TIMESTAMP", so every
 * update overwrote a timer's start time and a payslip's issue date. DATETIME
 * has no implicit behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_time_entries', fn (Blueprint $table) => $table->dateTime('started_at')->change());
        Schema::table('payslips', fn (Blueprint $table) => $table->dateTime('issued_at')->change());
    }

    public function down(): void
    {
        Schema::table('task_time_entries', fn (Blueprint $table) => $table->timestamp('started_at')->change());
        Schema::table('payslips', fn (Blueprint $table) => $table->timestamp('issued_at')->change());
    }
};
