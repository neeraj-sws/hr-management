<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invoices carry the hours billed (the old CMS kept it as an invoice custom field "Hours"). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('billing_invoices', 'hours')) {
            Schema::table('billing_invoices', fn (Blueprint $table) => $table->decimal('hours', 10, 2)->nullable()->after('ref_number'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing_invoices', 'hours')) {
            Schema::table('billing_invoices', fn (Blueprint $table) => $table->dropColumn('hours'));
        }
    }
};
