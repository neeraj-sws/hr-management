<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employees of more than one company (e.g. SWS and ITWeb): each employee belongs to a company, and payroll is
 * calculated, approved and locked per company. No company set = the main company (everyone today, and every
 * payroll run made so far).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                $table->boolean('is_main')->default(false);
                $table->timestamps();
            });
            DB::table('companies')->insert(['name' => 'SWS', 'is_main' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! Schema::hasColumn('attendance_employees', 'company_id')) {
            Schema::table('attendance_employees', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('department_id')->constrained('companies')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('payroll_runs', 'company_id')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            });
            // The same dates can now have one payroll per company.
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->dropUnique('payroll_period_unique');
                $table->unique(['company_id', 'period_starts_on', 'period_ends_on'], 'payroll_company_period_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_runs', 'company_id')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->dropUnique('payroll_company_period_unique');
                $table->dropConstrainedForeignId('company_id');
                $table->unique(['period_starts_on', 'period_ends_on'], 'payroll_period_unique');
            });
        }
        if (Schema::hasColumn('attendance_employees', 'company_id')) {
            Schema::table('attendance_employees', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }
        Schema::dropIfExists('companies');
    }
};
