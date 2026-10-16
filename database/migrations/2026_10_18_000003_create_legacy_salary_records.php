<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Month-wise salary rows imported from the old Excel salary sheet (one sheet per
 * month, columns A–AM). Stored exactly as in the sheet — formula results are
 * kept unrounded and never recalculated — so the history stays as it was paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legacy_salary_records')) return;
        Schema::create('legacy_salary_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
            $table->string('employee_code', 64)->nullable();
            $table->char('payroll_month', 7); // YYYY-MM (from the sheet name, e.g. "April-2026", or a Payroll Month column)
            $table->string('name');
            $table->string('name_key'); // lower-case trimmed name: one row per person per month
            $table->decimal('s_no', 10, 2)->nullable();
            foreach (['basic_salary', 'salary_per_day', 'per_hour_salary', 'per_minute_salary', 'saturday_bonus', 'late_coming_deduction', 'late_coming_adjusted',
                'short_leave_deduction', 'without_application_deduction', 'total_payment_deduction', 'salary_before_adjustment', 'increment_bonus', 'tds',
                'salary_after_deduction', 'total_salary_with_bonus', 'salary_paid', 'salary_remains'] as $amount) {
                $table->decimal($amount, 16, 6)->nullable();
            }
            foreach (['total_monthly_days', 'total_working_days', 'present_days', 'leave_days', 'paid_leave', 'third_saturday_sunday_work',
                'without_application_leave', 'without_application_short_leave', 'total_salary_paid_days'] as $days) {
                $table->decimal($days, 8, 2)->nullable();
            }
            foreach (['total_lunch_time', 'late_coming_minutes', 'adjusted_late_coming_minutes', 'hourly_leave_minutes', 'late_and_hourly_leave_minutes', 'short_leave_minutes', 'task_email_missing'] as $count) {
                $table->decimal($count, 12, 2)->nullable();
            }
            $table->text('deduction_reason')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('payment_status', 60)->nullable();
            $table->text('comments')->nullable();
            $table->text('notes')->nullable(); // free-text lines written under the row in the sheet
            $table->json('raw')->nullable(); // the row exactly as in the file (header => value, formulas' results)
            $table->string('source_file')->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['payroll_month', 'name_key']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_salary_records');
    }
};
