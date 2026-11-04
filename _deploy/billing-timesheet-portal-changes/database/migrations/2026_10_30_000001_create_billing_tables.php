<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing / Invoicing module (ported from the SWS CMS): customers, products &
 * services, taxes, estimates (quotes), invoices with line items, payments,
 * credit notes and revenue. Single company; `billing_` prefix keeps it clear
 * of the HR tables. Invoice/estimate numbers reset each financial year
 * (Apr–Mar) and display as PREFIX/FY/NNNNN, e.g. SWS/2026-27/00018.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_customers', function (Blueprint $table) {
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
            $table->string('shipping_name')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('shipping_zip')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index(); // id in the old CMS, for migration
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('billing_taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 8, 2)->default(0); // percent
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('billing_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('type')->default('service'); // product | service
            $table->decimal('sale_price', 16, 2)->default(0);
            $table->decimal('purchase_price', 16, 2)->default(0);
            $table->foreignId('tax_id')->nullable()->constrained('billing_taxes')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number');              // sequence within the financial year
            $table->string('financial_year', 9);            // e.g. 2026-27
            $table->foreignId('customer_id')->constrained('billing_customers')->cascadeOnDelete();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->date('send_date')->nullable();
            $table->string('ref_number')->nullable();
            $table->string('status', 20)->default('Draft'); // Draft | Sent | Unpaid | Partially Paid | Paid
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['financial_year', 'number']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('billing_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('billing_products')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->foreignId('tax_id')->nullable()->constrained('billing_taxes')->nullOnDelete();
            $table->timestamps();
            $table->index('invoice_id');
        });

        Schema::create('billing_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 16, 2);
            $table->string('method')->nullable();   // Cash / Bank / UPI / …
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('invoice_id');
        });

        Schema::create('billing_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('billing_customers')->nullOnDelete();
            $table->decimal('amount', 16, 2);
            $table->date('date');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('billing_revenues', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->decimal('amount', 16, 2);
            $table->foreignId('customer_id')->nullable()->constrained('billing_customers')->nullOnDelete();
            $table->string('category')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('billing_estimates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number');
            $table->string('financial_year', 9);
            $table->foreignId('customer_id')->constrained('billing_customers')->cascadeOnDelete();
            $table->date('issue_date');
            $table->date('expiry_date')->nullable();
            $table->string('status', 20)->default('Draft'); // Draft | Sent | Accepted | Declined | Converted
            $table->foreignId('converted_invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['financial_year', 'number']);
        });

        Schema::create('billing_estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('billing_estimates')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('billing_products')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->foreignId('tax_id')->nullable()->constrained('billing_taxes')->nullOnDelete();
            $table->timestamps();
            $table->index('estimate_id');
        });
    }

    public function down(): void
    {
        foreach ([
            'billing_estimate_items', 'billing_estimates', 'billing_revenues', 'billing_credit_notes',
            'billing_invoice_payments', 'billing_invoice_items', 'billing_invoices',
            'billing_products', 'billing_taxes', 'billing_customers',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
