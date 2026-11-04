<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** GST details for a proper tax invoice: HSN/SAC per line, place of supply, and the project an invoice bills. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['billing_products', 'billing_invoice_items', 'billing_estimate_items'] as $table) {
            if (! Schema::hasColumn($table, 'hsn_sac')) Schema::table($table, fn (Blueprint $t) => $t->string('hsn_sac', 20)->nullable());
        }
        foreach (['billing_invoices', 'billing_estimates'] as $table) {
            if (! Schema::hasColumn($table, 'place_of_supply')) Schema::table($table, fn (Blueprint $t) => $t->string('place_of_supply', 60)->nullable());
        }
        if (! Schema::hasColumn('billing_invoices', 'project_id')) {
            Schema::table('billing_invoices', fn (Blueprint $t) => $t->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing_invoices', 'project_id')) Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        foreach (['billing_invoices', 'billing_estimates'] as $table) if (Schema::hasColumn($table, 'place_of_supply')) Schema::table($table, fn (Blueprint $t) => $t->dropColumn('place_of_supply'));
        foreach (['billing_products', 'billing_invoice_items', 'billing_estimate_items'] as $table) if (Schema::hasColumn($table, 'hsn_sac')) Schema::table($table, fn (Blueprint $t) => $t->dropColumn('hsn_sac'));
    }
};
