<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a day was when no attendance was recorded, as written in an imported
 * attendance sheet ("Third Saturday", "Weekly Off", "Holiday", "Absent"). The
 * attendance pages show it instead of guessing from the calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_day_marks')) return;
        Schema::create('attendance_day_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('status', 20); // off | holiday | absent
            $table->string('label', 100)->nullable(); // the sheet's own words, e.g. "Third Saturday"
            $table->string('source', 30)->default('import');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_day_marks');
    }
};
