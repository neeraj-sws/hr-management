<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each company's own rules (Settings: payroll, working calendar and holidays, attendance, fines, leave rules,
 * screen capture, letterhead): a rule with a company replaces the rule for all companies for that company's people.
 * Empty = the rule for all companies (every rule saved so far, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('policy_rules', 'company_id')) {
            Schema::table('policy_rules', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->cascadeOnDelete();
                $table->index(['company_id', 'rule_key'], 'policy_rules_company_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('policy_rules', 'company_id')) {
            Schema::table('policy_rules', function (Blueprint $table) {
                $table->dropIndex('policy_rules_company_lookup_idx');
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }
};
