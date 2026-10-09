<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance corrections, shifts/roster, office locations, expense claims,
 * announcements, birthdays, recruitment and training.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('requested_check_in')->nullable();
            $table->dateTime('requested_check_out')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('attendance_session_id')->nullable()->constrained('attendance_sessions')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'work_date']);
            $table->index('status');
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'starts_on']);
        });

        Schema::create('office_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters')->default(200);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->foreignId('check_in_office_id')->nullable()->constrained('office_locations')->nullOnDelete();
            $table->unsignedInteger('check_in_distance_meters')->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->foreignId('check_out_office_id')->nullable()->constrained('office_locations')->nullOnDelete();
            $table->unsignedInteger('check_out_distance_meters')->nullable();
        });

        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->string('category', 60);
            $table->date('spent_on');
            $table->decimal('amount', 12, 2);
            $table->text('description');
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('payroll_item_id')->nullable()->constrained('payroll_items')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('reimbursements_amount', 12, 2)->default(0);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('body');
            $table->string('audience', 20)->default('all');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->dateTime('published_at');
            $table->date('expires_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('published_at');
        });

        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable();
            $table->boolean('share_birthday')->default(true);
        });

        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('location', 120)->nullable();
            $table->string('employment_type', 20)->default('full_time');
            $table->unsignedSmallInteger('openings')->default(1);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('hiring_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('closes_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_opening_id')->constrained('job_openings')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->string('source', 30)->default('other');
            $table->string('stage', 20)->default('applied');
            $table->string('resume_path')->nullable();
            $table->string('resume_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('hired_employee_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['job_opening_id', 'email']);
            $table->index('stage');
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            $table->string('mode', 20)->default('video');
            $table->string('location')->nullable();
            $table->foreignId('interviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('scheduled');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('recommendation', 20)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->index(['interviewer_id', 'scheduled_at']);
        });

        Schema::create('training_courses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            $table->string('provider', 120)->nullable();
            $table->decimal('duration_hours', 6, 2)->nullable();
            $table->string('material_url', 500)->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('training_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('assigned');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamps();
            $table->unique(['training_course_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_assignments');
        Schema::dropIfExists('training_courses');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('job_openings');
        Schema::table('attendance_employees', fn (Blueprint $table) => $table->dropColumn(['date_of_birth', 'share_birthday']));
        Schema::dropIfExists('announcements');
        Schema::table('payroll_items', fn (Blueprint $table) => $table->dropColumn('reimbursements_amount'));
        Schema::dropIfExists('expense_claims');
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('check_in_office_id');
            $table->dropConstrainedForeignId('check_out_office_id');
            $table->dropColumn(['check_in_latitude', 'check_in_longitude', 'check_in_distance_meters', 'check_out_latitude', 'check_out_longitude', 'check_out_distance_meters']);
        });
        Schema::dropIfExists('office_locations');
        Schema::dropIfExists('employee_shifts');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('attendance_corrections');
    }
};
