<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers keep the old CMS customer number (#CUST00001 = CMS customer_id 1) and the balance carried over
 * from the CMS (dues of periods whose invoices are not in HRMS, e.g. 2024-25), like the CMS "Balance".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_customers', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_customers', 'customer_number')) $table->unsignedInteger('customer_number')->nullable()->after('id')->index();
            if (! Schema::hasColumn('billing_customers', 'opening_balance')) $table->decimal('opening_balance', 16, 2)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('billing_customers', function (Blueprint $table) {
            if (Schema::hasColumn('billing_customers', 'customer_number')) $table->dropColumn('customer_number');
            if (Schema::hasColumn('billing_customers', 'opening_balance')) $table->dropColumn('opening_balance');
        });
    }
};
