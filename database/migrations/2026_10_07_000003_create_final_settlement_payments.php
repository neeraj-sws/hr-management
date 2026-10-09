<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('employee_final_settlement_payments')) {
            Schema::create('employee_final_settlement_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('final_settlement_id')->constrained('employee_final_settlements')->cascadeOnDelete();
                $table->decimal('amount', 12, 2);
                $table->date('paid_on');
                $table->string('reference')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('employee_final_settlement_payments', 'settlement_payment_date_idx')) {
            Schema::table('employee_final_settlement_payments', fn (Blueprint $table) => $table->index(['final_settlement_id', 'paid_on'], 'settlement_payment_date_idx'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_final_settlement_payments');
    }
};
