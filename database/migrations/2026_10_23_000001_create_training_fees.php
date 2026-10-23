<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Course fees paid by trainees (Training > Course fees): the courses offered with their fee, who is
 * enrolled in which course (fee, discount, number of installments) and every payment received.
 * Trainees are an employment type: they have attendance but are never on payroll or salary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_fee_courses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('duration', 60)->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('training_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('fee_course_id')->nullable()->constrained('training_fee_courses')->nullOnDelete();
            $table->string('course_name');
            $table->decimal('total_fee', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->unsignedSmallInteger('installments')->default(1);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('training_fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('training_enrollments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->string('receipt_no', 30)->unique();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('reference', 120)->nullable();
            $table->unsignedSmallInteger('installment_no')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['paid_on']);
        });

        // Everyone in the Training department is a trainee (pays a fee, no salary).
        $training = DB::table('departments')->whereRaw('LOWER(name) = ?', ['training'])->pluck('id');
        if ($training->isNotEmpty()) {
            DB::table('attendance_employees')->whereIn('department_id', $training)
                ->where(fn ($q) => $q->whereNull('employment_type')->orWhere('employment_type', '!=', 'ex_employee'))
                ->update(['employment_type' => 'trainee']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_fee_payments');
        Schema::dropIfExists('training_enrollments');
        Schema::dropIfExists('training_fee_courses');
        DB::table('attendance_employees')->where('employment_type', 'trainee')->update(['employment_type' => 'full_time']);
    }
};
