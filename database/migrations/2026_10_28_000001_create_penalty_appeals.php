<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An employee's reason for a late-arrival fine; HR / admin accept it (the fine is removed) or not (it stays). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalty_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_penalty_id')->unique()->constrained('employee_penalties')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('refund_adjustment_id')->nullable()->constrained('salary_adjustments')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalty_appeals');
    }
};
