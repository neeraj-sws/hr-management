<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-day active-window title totals (app + title + seconds). Enabled separately by an admin; off by default. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_titles_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('app_name', 120);
            $table->string('title', 255);
            $table->unsignedInteger('seconds')->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'work_date', 'app_name', 'title'], 'activity_title_unique');
            $table->index(['work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_titles_daily');
    }
};
