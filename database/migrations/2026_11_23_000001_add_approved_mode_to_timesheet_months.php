<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a project has a monthly hours cap the client approves either every billable hour ("all") or only the cap ("cap"). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('timesheet_months', 'approved_mode')) {
            Schema::table('timesheet_months', fn (Blueprint $table) => $table->string('approved_mode', 10)->nullable()->after('approved_by'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('timesheet_months', 'approved_mode')) Schema::table('timesheet_months', fn (Blueprint $table) => $table->dropColumn('approved_mode'));
    }
};
