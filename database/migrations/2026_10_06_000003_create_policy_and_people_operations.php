<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('salary_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('working_days_per_month')->default(22);
            $table->decimal('standard_daily_hours', 5, 2)->default(8);
        });
        Schema::table('leave_types', function (Blueprint $table) {
            $table->decimal('monthly_accrual', 6, 3)->default(0);
            $table->boolean('allow_advance')->default(false);
        });
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('accrued_days', 7, 3)->default(0);
            $table->decimal('adjusted_days', 7, 3)->default(0);
            $table->unsignedTinyInteger('last_accrual_month')->nullable();
        });
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('working_days')->default(0);
            $table->decimal('present_days', 7, 2)->default(0);
            $table->decimal('paid_leave_days', 7, 2)->default(0);
            $table->decimal('unpaid_leave_days', 7, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('overtime_amount', 12, 2)->default(0);
            $table->decimal('penalties_amount', 12, 2)->default(0);
            $table->decimal('advances_amount', 12, 2)->default(0);
            $table->decimal('recovery_amount', 12, 2)->default(0);
            $table->decimal('adjustments_amount', 12, 2)->default(0);
            $table->json('calculation_breakdown')->nullable();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('employee_team', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['team_id', 'employee_id']);
        });

        Schema::create('policy_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 100)->unique();
            $table->string('category', 32);
            $table->string('name');
            $table->json('configuration');
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['category', 'is_active']);
        });
        Schema::create('employee_policy_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->string('rule_key', 100);
            $table->json('configuration');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'rule_key']);
        });
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedSmallInteger('requested_minutes');
            $table->unsignedSmallInteger('approved_minutes')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'work_date', 'status']);
        });
        Schema::create('salary_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('effective_on');
            $table->decimal('amount', 12, 2);
            $table->string('kind', 16);
            $table->string('status', 20)->default('pending');
            $table->string('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('payroll_item_id')->nullable()->constrained('payroll_items')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_on', 'status']);
        });
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('recovered_amount', 12, 2)->default(0);
            $table->decimal('installment_amount', 12, 2);
            $table->date('requested_on');
            $table->string('status', 20)->default('pending');
            $table->string('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });
        Schema::create('salary_advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_advance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['salary_advance_id', 'payroll_item_id'], 'advance_repayment_item_uq');
        });
        Schema::create('employee_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('policy_rule_id')->nullable()->constrained('policy_rules')->nullOnDelete();
            $table->date('effective_on');
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->string('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('payroll_item_id')->nullable()->constrained('payroll_items')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_on', 'status']);
        });

        Schema::create('employee_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->string('visibility', 20)->default('personal');
            $table->string('title');
            $table->text('body');
            $table->timestamps();
            $table->index(['visibility', 'employee_id']);
            $table->index(['team_id', 'visibility']);
        });
        Schema::create('credential_vault_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_employee_id')->nullable()->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('label');
            $table->string('username')->nullable();
            $table->text('secret_value');
            $table->text('url')->nullable();
            $table->string('visibility', 20)->default('private');
            $table->timestamps();
            $table->index(['owner_employee_id', 'visibility']);
            $table->index(['team_id', 'visibility']);
        });
        Schema::create('employee_lifecycle_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('open');
            $table->date('effective_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'kind', 'status']);
        });
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 40);
            $table->string('original_name');
            $table->string('storage_path');
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'category']);
        });
        Schema::create('employee_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->string('asset_tag')->unique();
            $table->string('name');
            $table->string('status', 20)->default('assigned');
            $table->date('assigned_on')->nullable();
            $table->date('returned_on')->nullable();
            $table->text('condition_notes')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });
        Schema::create('employee_clearance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lifecycle_case_id')->constrained('employee_lifecycle_cases')->cascadeOnDelete();
            $table->string('item');
            $table->string('status', 20)->default('pending');
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_final_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lifecycle_case_id')->unique()->constrained('employee_lifecycle_cases')->cascadeOnDelete();
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        foreach (['notifications','employee_final_settlements','employee_clearance_items','employee_assets','employee_documents','employee_lifecycle_cases','credential_vault_entries','employee_notes','employee_penalties','salary_advance_repayments','salary_advances','salary_adjustments','overtime_requests','employee_policy_overrides','policy_rules','employee_team'] as $table) Schema::dropIfExists($table);
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_employee_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['name', 'description', 'is_active']);
        });
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn(['working_days','present_days','paid_leave_days','unpaid_leave_days','overtime_hours','overtime_amount','penalties_amount','advances_amount','recovery_amount','adjustments_amount','calculation_breakdown']);
        });
        Schema::table('leave_balances', function (Blueprint $table) { $table->dropColumn(['accrued_days','adjusted_days','last_accrual_month']); });
        Schema::table('leave_types', function (Blueprint $table) { $table->dropColumn(['monthly_accrual','allow_advance']); });
        Schema::table('salary_profiles', function (Blueprint $table) { $table->dropColumn(['working_days_per_month','standard_daily_hours']); });
    }
};
