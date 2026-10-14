<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holiday rules engine: recurring/fixed holiday definitions, the per-employee
 * holidays they generate, manual adjustments (shift / cancel / add), and
 * comp-off earned by working on a holiday. Scope follows the same priority as
 * the rest of the app: company -> department -> team -> employee (most specific
 * wins). Single company for now; the scope columns keep it tenant-ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);                        // weekly | nth_weekday | fixed_date
            $table->unsignedTinyInteger('weekday')->nullable(); // 1=Mon..7=Sun (weekly, nth_weekday)
            $table->json('weeks')->nullable();                  // nth_weekday: [1..5] and/or -1 (last)
            $table->unsignedTinyInteger('month')->nullable();   // fixed_date
            $table->unsignedTinyInteger('day')->nullable();     // fixed_date
            $table->string('scope_type', 16)->default('company'); // company | department | team | employee
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id', 'is_active']);
        });

        Schema::create('employee_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('date');
            $table->string('name')->default('Holiday');
            $table->string('source', 16)->default('rule');     // rule | override | manual
            $table->foreignId('holiday_rule_id')->nullable()->constrained('holiday_rules')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index('date');
        });

        Schema::create('holiday_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 16)->default('company');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('original_date');
            $table->string('action', 12);                      // shift | cancel | add
            $table->date('new_date')->nullable();              // for shift
            $table->string('name')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id']);
            $table->index('original_date');
        });

        Schema::create('comp_off_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('earned_on');
            $table->decimal('days', 4, 2)->default(1);
            $table->string('status', 12)->default('available'); // available | used | expired
            $table->date('expires_on')->nullable();
            $table->foreignId('used_leave_id')->nullable()->constrained('leave_requests')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'earned_on']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_off_credits');
        Schema::dropIfExists('holiday_overrides');
        Schema::dropIfExists('employee_holidays');
        Schema::dropIfExists('holiday_rules');
    }
};
