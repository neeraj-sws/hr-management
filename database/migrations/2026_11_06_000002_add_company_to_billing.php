<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing per company: customers, products, invoices, estimates, credit notes and revenue belong to one company
 * (empty = the main company, i.e. everything so far is SWS's); taxes may be shared (empty) or a company's own.
 * Invoice and estimate numbers run per company and financial year, so every invoice gets its company (SWS for
 * all existing ones) and the unique number is per company.
 */
return new class extends Migration
{
    private const TABLES = ['billing_customers', 'billing_taxes', 'billing_products', 'billing_invoices', 'billing_credit_notes', 'billing_revenues', 'billing_estimates'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'company_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                });
            }
        }
        $main = DB::table('companies')->where('is_main', true)->orderBy('id')->value('id');
        foreach (['billing_invoices', 'billing_estimates'] as $name) {
            if ($main) DB::table($name)->whereNull('company_id')->update(['company_id' => $main]);
            Schema::table($name, function (Blueprint $table) use ($name) {
                if (Schema::hasIndex($name, $name.'_financial_year_number_unique')) $table->dropUnique($name.'_financial_year_number_unique');
                if (! Schema::hasIndex($name, $name.'_company_year_number_unique')) $table->unique(['company_id', 'financial_year', 'number'], $name.'_company_year_number_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['billing_invoices', 'billing_estimates'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropUnique($name.'_company_year_number_unique');
                $table->unique(['financial_year', 'number']);
            });
        }
        foreach (self::TABLES as $name) {
            if (Schema::hasColumn($name, 'company_id')) Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }
    }
};
