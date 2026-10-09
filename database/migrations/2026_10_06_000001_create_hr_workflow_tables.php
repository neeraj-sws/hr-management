<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->foreignId('manager_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
            $table->date('joined_at')->nullable();
            $table->index(['manager_id', 'is_active'], 'att_emp_manager_active_idx');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->index(['status', 'ends_on'], 'projects_status_end_idx');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('assignee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->string('status', 24)->default('todo');
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['assignee_id', 'status', 'due_on'], 'tasks_assignee_state_due_idx');
        });

        Schema::create('task_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('status', 16)->default('running');
            $table->timestamps();
            $table->index(['employee_id', 'status'], 'task_time_employee_state_idx');
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('annual_allowance', 6, 2)->default(0);
            $table->boolean('carry_forward')->default(false);
            $table->decimal('carry_forward_limit', 6, 2)->default(0);
            $table->boolean('paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled_days', 6, 2)->default(0);
            $table->decimal('used_days', 6, 2)->default(0);
            $table->decimal('carried_days', 6, 2)->default(0);
            $table->unique(['employee_id', 'leave_type_id', 'year'], 'leave_balance_employee_type_year_uq');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('days', 6, 2);
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'starts_on'], 'leave_employee_state_start_idx');
        });

        Schema::create('salary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('attendance_employees')->cascadeOnDelete();
            $table->decimal('monthly_salary', 12, 2);
            $table->date('effective_from');
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('approvable_type', 100);
            $table->unsignedBigInteger('approvable_id');
            $table->unsignedSmallInteger('step')->default(1);
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['approvable_type', 'approvable_id', 'status'], 'approvals_subject_state_idx');
            $table->index(['approver_id', 'status'], 'approvals_user_state_idx');
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->unique(['period_starts_on', 'period_ends_on'], 'payroll_period_unique');
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('attendance_employees')->restrictOnDelete();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('adjustments', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->json('calculation_data');
            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_item_run_employee_uq');
        });

        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained('payroll_items')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['payroll_item_id', 'paid_on'], 'salary_payments_item_date_idx');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id'], 'audit_subject_idx');
            $table->index(['actor_id', 'created_at'], 'audit_actor_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('salary_payments');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('salary_profiles');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('task_time_entries');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('projects');

        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->dropIndex('att_emp_manager_active_idx');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('joined_at');
        });

        Schema::dropIfExists('departments');
    }
};
