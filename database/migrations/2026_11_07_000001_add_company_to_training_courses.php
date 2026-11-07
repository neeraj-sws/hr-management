<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A training course belongs to one company. Empty = the main company (every course made so far is SWS's); what the
 * ITWeb owner adds is ITWeb's and is never shown with SWS's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('training_courses') && ! Schema::hasColumn('training_courses', 'company_id')) {
            Schema::table('training_courses', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('training_courses') && Schema::hasColumn('training_courses', 'company_id')) {
            Schema::table('training_courses', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }
    }
};
