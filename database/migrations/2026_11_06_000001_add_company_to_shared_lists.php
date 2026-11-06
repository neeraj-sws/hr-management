<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company's own list items. Departments, teams, leave types, shifts, offices and letter templates: empty = shared
 * by all companies (everything so far). Projects, clients and job openings: empty = the main company (everything so
 * far is SWS's).
 */
return new class extends Migration
{
    private const TABLES = ['departments', 'teams', 'leave_types', 'shifts', 'office_locations', 'letter_templates', 'projects', 'clients', 'job_openings'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'company_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'company_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
            }
        }
    }
};
