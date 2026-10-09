<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employment details, task work date / subtasks / comments / project members,
 * structured daily status and notification preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->string('employment_type', 20)->default('full_time');
            $table->date('probation_ends_on')->nullable();
            $table->date('confirmed_on')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->date('resigned_on')->nullable();
            $table->date('exit_date')->nullable();
            $table->string('exit_type', 20)->nullable();
            $table->text('exit_reason')->nullable();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->date('work_date')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->index(['assignee_id', 'work_date']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['task_id', 'created_at']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->string('role', 40)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'employee_id']);
        });

        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->text('plan')->nullable();
            $table->text('blockers')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('submitted_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_preferences'));
        Schema::table('daily_work_statuses', fn (Blueprint $table) => $table->dropColumn(['plan', 'blockers', 'notes', 'submitted_at']));
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('task_comments');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['assignee_id', 'work_date']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('work_date');
        });
        Schema::table('attendance_employees', fn (Blueprint $table) => $table->dropColumn(['employment_type', 'probation_ends_on', 'confirmed_on', 'notice_period_days', 'resigned_on', 'exit_date', 'exit_type', 'exit_reason']));
    }
};
