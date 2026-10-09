<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-day application-usage totals (app name + seconds). No window titles or content are stored. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('app_name', 120);
            $table->unsignedInteger('seconds')->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'work_date', 'app_name'], 'app_usage_unique');
            $table->index(['work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_usage_daily');
    }
};
