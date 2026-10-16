<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A leave, attendance correction or overtime approved after its month's payroll
 * was approved/locked does not reopen that payroll: the pay difference is added
 * to the next payroll as an arrear (or recovery). This links that adjustment to
 * the payroll item it corrects, so repeated changes only carry the remaining difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->foreignId('carried_from_payroll_item_id')->nullable()->after('payroll_item_id')->constrained('payroll_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carried_from_payroll_item_id');
        });
    }
};
