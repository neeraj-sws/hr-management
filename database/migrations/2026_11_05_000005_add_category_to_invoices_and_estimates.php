<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invoices and proposals (estimates) have an income category, like the old CMS. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['billing_invoices', 'billing_estimates'] as $t) {
            if (! Schema::hasColumn($t, 'category_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->foreignId('category_id')->nullable()->after('customer_id')->constrained('billing_categories')->nullOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (['billing_invoices', 'billing_estimates'] as $t) {
            if (Schema::hasColumn($t, 'category_id')) Schema::table($t, fn (Blueprint $table) => $table->dropConstrainedForeignId('category_id'));
        }
    }
};
