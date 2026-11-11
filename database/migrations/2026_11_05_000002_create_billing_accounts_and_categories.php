<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banking like the old CMS: bank / cash accounts that payments and revenue go into,
 * income / expense categories, and an attached receipt on payments and revenue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_bank_accounts')) {
            Schema::create('billing_bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('holder_name');
                $table->string('bank_name');
                $table->string('account_number')->nullable();
                $table->string('ifsc')->nullable();
                $table->decimal('opening_balance', 16, 2)->default(0);
                $table->string('contact')->nullable();
                $table->text('address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('legacy_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
            DB::table('billing_bank_accounts')->insert(['holder_name' => 'Cash', 'bank_name' => 'Cash', 'opening_balance' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        if (! Schema::hasTable('billing_categories')) {
            Schema::create('billing_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type', 20)->default('income'); // income | expense | product
                $table->string('color', 7)->nullable();
                $table->unsignedBigInteger('legacy_id')->nullable()->index();
                $table->timestamps();
            });
        }

        Schema::table('billing_revenues', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_revenues', 'account_id')) $table->foreignId('account_id')->nullable()->after('amount')->constrained('billing_bank_accounts')->nullOnDelete();
            if (! Schema::hasColumn('billing_revenues', 'category_id')) $table->foreignId('category_id')->nullable()->after('customer_id')->constrained('billing_categories')->nullOnDelete();
            if (! Schema::hasColumn('billing_revenues', 'receipt_path')) $table->string('receipt_path')->nullable()->after('reference');
        });

        Schema::table('billing_invoice_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_invoice_payments', 'account_id')) $table->foreignId('account_id')->nullable()->after('amount')->constrained('billing_bank_accounts')->nullOnDelete();
            if (! Schema::hasColumn('billing_invoice_payments', 'receipt_path')) $table->string('receipt_path')->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoice_payments', function (Blueprint $table) {
            if (Schema::hasColumn('billing_invoice_payments', 'account_id')) $table->dropConstrainedForeignId('account_id');
            if (Schema::hasColumn('billing_invoice_payments', 'receipt_path')) $table->dropColumn('receipt_path');
        });
        Schema::table('billing_revenues', function (Blueprint $table) {
            if (Schema::hasColumn('billing_revenues', 'account_id')) $table->dropConstrainedForeignId('account_id');
            if (Schema::hasColumn('billing_revenues', 'category_id')) $table->dropConstrainedForeignId('category_id');
            if (Schema::hasColumn('billing_revenues', 'receipt_path')) $table->dropColumn('receipt_path');
        });
        Schema::dropIfExists('billing_categories');
        Schema::dropIfExists('billing_bank_accounts');
    }
};
