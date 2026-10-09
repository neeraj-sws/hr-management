<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('carry_forward_expiry_months')->nullable()->after('carry_forward_limit');
        });
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->date('carried_expires_on')->nullable()->after('carried_days');
        });
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('statutory_earnings', 12, 2)->default(0);
            $table->decimal('statutory_deductions', 12, 2)->default(0);
        });
        Schema::table('employee_final_settlements', function (Blueprint $table) {
            $table->date('as_of_date')->nullable();
            $table->json('calculation_breakdown')->nullable();
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('server_version')->default(1);
        });
        Schema::create('offline_action_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 80);
            $table->unsignedBigInteger('actor_id');
            $table->string('action_id', 100);
            $table->char('payload_hash', 64);
            $table->string('status', 20)->default('processed');
            $table->json('response_data')->nullable();
            $table->timestamps();
            $table->unique(['actor_type', 'actor_id', 'action_id'], 'offline_action_actor_key_uq');
            $table->index(['actor_type', 'actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_action_receipts');
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('server_version'));
        Schema::table('employee_final_settlements', fn (Blueprint $table) => $table->dropColumn(['as_of_date', 'calculation_breakdown']));
        Schema::table('payroll_items', fn (Blueprint $table) => $table->dropColumn(['statutory_earnings', 'statutory_deductions']));
        Schema::table('leave_balances', fn (Blueprint $table) => $table->dropColumn('carried_expires_on'));
        Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn('carry_forward_expiry_months'));
    }
};
