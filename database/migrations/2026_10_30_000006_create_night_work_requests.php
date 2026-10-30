<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Work done at night (after the day, e.g. 10 PM - 1 AM): asked for with the tasks and what was done; paid by the hour once approved. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('night_work_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->unsignedInteger('minutes');
            $table->json('task_ids')->nullable();
            $table->text('work_done');
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
        Schema::dropIfExists('night_work_requests');
    }
};
