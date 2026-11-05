<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Off day requests: an employee whose week-of-month offs (e.g. 2nd Saturday) count only when asked for
 * tells HR "I am taking my off on this day"; once HR approves it, that day is an off, otherwise a working day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('off_day_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('off_date');
            $table->string('note', 1000)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'off_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('off_day_requests');
    }
};
