<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });

        // Backfill company_id from each asset's employee
        DB::statement('UPDATE employee_assets ea
            JOIN attendance_employees ae ON ae.id = ea.employee_id
            SET ea.company_id = ae.company_id');

        Schema::table('employee_assets', function (Blueprint $table) {
            // Allow assets in inventory that are not yet assigned to anyone
            $table->dropForeign(['employee_id']);
            $table->foreignId('employee_id')->nullable()->change();
            $table->foreign('employee_id')->references('id')->on('attendance_employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_assets', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
            $table->dropForeign(['employee_id']);
            $table->foreignId('employee_id')->nullable(false)->change();
            $table->foreign('employee_id')->references('id')->on('attendance_employees')->cascadeOnDelete();
        });
    }
};
