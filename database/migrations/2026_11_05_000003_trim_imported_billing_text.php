<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first CMS import kept the space after each comma of the SQL dump, so imported
 * names and texts start with " " (e.g. " Abhishek Yadav", " Manually"). Strip it.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'billing_customers' => ['name', 'email', 'contact', 'tax_number', 'billing_name', 'billing_phone', 'billing_address', 'billing_city', 'billing_state', 'billing_country', 'billing_zip',
            'shipping_name', 'shipping_phone', 'shipping_address', 'shipping_city', 'shipping_state', 'shipping_country', 'shipping_zip'],
        'billing_products' => ['name', 'sku', 'description', 'hsn_sac', 'unit'],
        'billing_taxes' => ['name'],
        'billing_invoices' => ['ref_number', 'notes'],
        'billing_invoice_items' => ['name', 'description'],
        'billing_invoice_payments' => ['method', 'reference', 'note'],
        'billing_credit_notes' => ['description'],
        'billing_revenues' => ['category', 'payment_method', 'reference', 'description'],
        'billing_estimate_items' => ['name', 'description'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) continue;
                DB::table($table)->where($column, 'like', ' %')->update([$column => DB::raw("TRIM({$column})")]);
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: the leading spaces were never wanted.
    }
};
