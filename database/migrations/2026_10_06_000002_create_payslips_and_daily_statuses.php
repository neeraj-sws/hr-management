<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_item_id')->unique()->constrained('payroll_items')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->timestamp('issued_at');
            $table->string('reference')->unique();
            $table->timestamps();
        });

        Schema::create('daily_work_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->text('summary');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date'], 'daily_status_employee_date_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_work_statuses');
        Schema::dropIfExists('payslips');
    }
};
