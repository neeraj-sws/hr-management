<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee asks for a month's payslip (website or app). A payslip made by this system is given at once; for older
 * months HR sends it from the old salary sheet (Legacy Salary) or uploads the file, or says why not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->text('note')->nullable();
            $table->string('status', 12)->default('pending'); // pending | sent | rejected
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->foreignId('legacy_record_id')->nullable()->constrained('legacy_salary_records')->nullOnDelete();
            $table->string('file_path')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'month']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_requests');
    }
};
