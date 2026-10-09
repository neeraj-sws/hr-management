<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Daily status "yesterday", payment method on salary / settlement payments, one-time overdue task alerts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_work_statuses', fn (Blueprint $table) => $table->text('yesterday')->nullable()->after('work_date'));
        Schema::table('salary_payments', fn (Blueprint $table) => $table->string('method', 20)->nullable()->after('paid_on'));
        Schema::table('employee_final_settlement_payments', fn (Blueprint $table) => $table->string('method', 20)->nullable()->after('paid_on'));
        Schema::table('tasks', fn (Blueprint $table) => $table->timestamp('overdue_notified_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('overdue_notified_at'));
        Schema::table('employee_final_settlement_payments', fn (Blueprint $table) => $table->dropColumn('method'));
        Schema::table('salary_payments', fn (Blueprint $table) => $table->dropColumn('method'));
        Schema::table('daily_work_statuses', fn (Blueprint $table) => $table->dropColumn('yesterday'));
    }
};
