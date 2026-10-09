<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support for the hard UAT cases: auto-closed sessions, break corrections,
 * stale payroll runs, leave/attendance conflict resolutions, manager history,
 * lost assets and desktop screenshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->boolean('closed_automatically')->default(false);
        });
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->unsignedSmallInteger('requested_break_minutes')->nullable();
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->boolean('needs_recalculation')->default(false);
        });
        Schema::table('employee_assets', function (Blueprint $table) {
            $table->text('resolution_note')->nullable();
            $table->foreignId('recovery_adjustment_id')->nullable()->constrained('salary_adjustments')->nullOnDelete();
        });

        Schema::create('leave_conflict_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('resolution', 20);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
        });

        Schema::create('employee_manager_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['manager_id', 'starts_on']);
        });

        Schema::create('screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('attendance_session_id')->nullable()->constrained('attendance_sessions')->nullOnDelete();
            $table->dateTime('captured_at');
            $table->string('path');
            $table->unsignedInteger('bytes')->default(0);
            $table->timestamps();
            $table->index(['employee_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenshots');
        Schema::dropIfExists('employee_manager_history');
        Schema::dropIfExists('leave_conflict_resolutions');
        Schema::table('employee_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recovery_adjustment_id');
            $table->dropColumn('resolution_note');
        });
        Schema::table('payroll_runs', fn (Blueprint $table) => $table->dropColumn('needs_recalculation'));
        Schema::table('attendance_corrections', fn (Blueprint $table) => $table->dropColumn('requested_break_minutes'));
        Schema::table('attendance_sessions', fn (Blueprint $table) => $table->dropColumn('closed_automatically'));
    }
};
