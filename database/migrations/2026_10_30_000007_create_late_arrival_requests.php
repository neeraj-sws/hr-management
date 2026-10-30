<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "I will come late": told in advance with the expected check-in time and a reason; management approves or not. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_arrival_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('expected_at', 5);
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'work_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_arrival_requests');
    }
};
