<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->string('designation', 120)->nullable()->after('email');
            $table->string('phone', 40)->nullable()->after('designation');
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->unsignedTinyInteger('rating');
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->json('metrics')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->text('employee_comment')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'period_ends_on'], 'perf_review_employee_period_idx');
        });

        // The unused `employees` table duplicated `attendance_employees`; it never held data.
        Schema::dropIfExists('employees');
    }

    public function down(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
        Schema::dropIfExists('performance_reviews');
        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->dropColumn(['designation', 'phone']);
        });
    }
};
