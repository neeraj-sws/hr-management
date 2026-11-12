<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases and banking like the old CMS: vendors (suppliers), bills with items and payments,
 * direct expenses / payments, debit notes, and transfers between bank / cash accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_bank_transfers')) {
            Schema::create('billing_bank_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('from_account_id')->constrained('billing_bank_accounts')->cascadeOnDelete();
                $table->foreignId('to_account_id')->constrained('billing_bank_accounts')->cascadeOnDelete();
                $table->decimal('amount', 16, 2);
                $table->date('date');
                $table->string('reference')->nullable();
                $table->text('description')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('billing_vendors')) {
            Schema::create('billing_vendors', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('contact')->nullable();
                $table->string('tax_number')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('billing_name')->nullable();
                $table->string('billing_phone')->nullable();
                $table->string('billing_address')->nullable();
                $table->string('billing_city')->nullable();
                $table->string('billing_state')->nullable();
                $table->string('billing_country')->nullable();
                $table->string('billing_zip')->nullable();
                $table->unsignedBigInteger('legacy_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('billing_bills')) {
            Schema::create('billing_bills', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('number');
                $table->foreignId('vendor_id')->constrained('billing_vendors')->cascadeOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('billing_categories')->nullOnDelete();
                $table->date('bill_date');
                $table->date('due_date')->nullable();
                $table->string('ref_number')->nullable();
                $table->string('status', 20)->default('Draft'); // Draft | Sent (received) | Unpaid | Partially Paid | Paid
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('legacy_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique('number');
            });
            Schema::create('billing_bill_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bill_id')->constrained('billing_bills')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('billing_products')->nullOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('quantity', 12, 2)->default(1);
                $table->decimal('price', 16, 2)->default(0);
                $table->decimal('discount', 16, 2)->default(0);
                $table->foreignId('tax_id')->nullable()->constrained('billing_taxes')->nullOnDelete();
                $table->timestamps();
            });
            Schema::create('billing_bill_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bill_id')->constrained('billing_bills')->cascadeOnDelete();
                $table->date('date');
                $table->decimal('amount', 16, 2);
                $table->foreignId('account_id')->nullable()->constrained('billing_bank_accounts')->nullOnDelete();
                $table->string('method')->nullable();
                $table->string('reference')->nullable();
                $table->text('note')->nullable();
                $table->string('receipt_path')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
            Schema::create('billing_debit_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bill_id')->nullable()->constrained('billing_bills')->nullOnDelete();
                $table->foreignId('vendor_id')->nullable()->constrained('billing_vendors')->nullOnDelete();
                $table->decimal('amount', 16, 2);
                $table->date('date');
                $table->text('description')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('billing_expenses')) {
            Schema::create('billing_expenses', function (Blueprint $table) {
                $table->id();
                $table->date('date');
                $table->decimal('amount', 16, 2);
                $table->foreignId('account_id')->nullable()->constrained('billing_bank_accounts')->nullOnDelete();
                $table->foreignId('vendor_id')->nullable()->constrained('billing_vendors')->nullOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('billing_categories')->nullOnDelete();
                $table->string('payment_method')->nullable();
                $table->string('reference')->nullable();
                $table->text('description')->nullable();
                $table->string('receipt_path')->nullable();
                $table->unsignedBigInteger('legacy_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['billing_expenses', 'billing_debit_notes', 'billing_bill_payments', 'billing_bill_items', 'billing_bills', 'billing_vendors', 'billing_bank_transfers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
