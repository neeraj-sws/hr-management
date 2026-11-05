<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements of one company: a company owner's announcement goes to his company only. Empty = all companies
 * (every announcement made so far, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('announcements', 'company_id')) {
            Schema::table('announcements', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('announcements', 'company_id')) {
            Schema::table('announcements', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }
    }
};
