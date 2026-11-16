<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The client can mark each timesheet line as reviewed / OK (not only object to it or approve the whole month). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('timesheet_entries', 'client_approved_at')) $table->timestamp('client_approved_at')->nullable()->after('visible_to_client');
            if (! Schema::hasColumn('timesheet_entries', 'client_approved_by')) $table->unsignedBigInteger('client_approved_by')->nullable()->after('client_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            foreach (['client_approved_by', 'client_approved_at'] as $c) if (Schema::hasColumn('timesheet_entries', $c)) $table->dropColumn($c);
        });
    }
};
