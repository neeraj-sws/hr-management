<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('salary_profiles', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropUnique('salary_profiles_employee_id_unique');
            $table->unique(['employee_id','effective_from'],'salary_employee_effective_uq');
            $table->index(['effective_from','employee_id'],'salary_effective_employee_idx');
            $table->foreign('employee_id')->references('id')->on('attendance_employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salary_profiles', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropIndex('salary_effective_employee_idx');
            $table->dropUnique('salary_employee_effective_uq');
            $table->unique('employee_id');
            $table->foreign('employee_id')->references('id')->on('attendance_employees')->cascadeOnDelete();
        });
    }
};
