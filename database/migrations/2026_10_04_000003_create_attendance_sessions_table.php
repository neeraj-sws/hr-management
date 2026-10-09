<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained('attendance_employees')
                ->cascadeOnDelete();
            $table->foreignId('device_id')
                ->nullable()
                ->constrained('attendance_devices')
                ->nullOnDelete();

            $table->date('work_date');
            $table->dateTime('check_in');
            $table->dateTime('check_out')->nullable();

            $table->unsignedBigInteger('active_seconds')->default(0);
            $table->unsignedBigInteger('idle_seconds')->default(0);
            $table->unsignedBigInteger('break_seconds')->default(0);

            $table->string('status')->default('working');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
