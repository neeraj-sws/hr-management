<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - leave_ledger_entries: every change to a leave balance (accrual, carry-forward,
 *   expiry, manual +/- adjustment, leave taken) as an append-only audit trail.
 * - salary_adjustments: recurring adjustments (applied every month until an end month).
 * - attendance_sessions: the IP address seen at check-in and check-out.
 * - leave year: an install that already has leave balances keeps the calendar
 *   year (January) so its balances stay valid; new installs use April–March.
 *   The start month can be changed in Settings > Leave types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');                // leave year (the year it starts in)
            $table->string('entry_type', 20);                    // opening | accrual | carry_forward | expiry | adjustment | used | reversal
            $table->decimal('days', 8, 3);                       // signed: + adds to the balance, - takes from it
            $table->string('reason')->nullable();
            $table->nullableMorphs('reference');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('kind');
            $table->date('recurring_until')->nullable()->after('is_recurring');
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->string('check_in_ip', 45)->nullable();
            $table->string('check_out_ip', 45)->nullable();
        });

        if (DB::table('leave_balances')->exists() && ! DB::table('policy_rules')->where('rule_key', 'leave.year')->exists()) {
            DB::table('policy_rules')->insert([
                'rule_key' => 'leave.year', 'category' => 'leave', 'name' => 'Leave year',
                'configuration' => json_encode(['start_month' => 1]), 'is_active' => true,
                'effective_from' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', fn (Blueprint $table) => $table->dropColumn(['check_in_ip', 'check_out_ip']));
        Schema::table('salary_adjustments', fn (Blueprint $table) => $table->dropColumn(['is_recurring', 'recurring_until']));
        Schema::dropIfExists('leave_ledger_entries');
    }
};
