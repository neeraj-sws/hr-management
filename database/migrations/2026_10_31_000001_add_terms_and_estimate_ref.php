<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Terms & conditions on invoices and estimates, plus a reference number on estimates. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            if (! Schema::hasColumn('billing_invoices', 'terms')) $t->text('terms')->nullable();
        });
        Schema::table('billing_estimates', function (Blueprint $t) {
            if (! Schema::hasColumn('billing_estimates', 'ref_number')) $t->string('ref_number')->nullable();
            if (! Schema::hasColumn('billing_estimates', 'terms')) $t->text('terms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn('terms'));
        Schema::table('billing_estimates', function (Blueprint $t) {
            foreach (['ref_number', 'terms'] as $c) if (Schema::hasColumn('billing_estimates', $c)) $t->dropColumn($c);
        });
    }
};
