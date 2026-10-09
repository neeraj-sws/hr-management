<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('policy_rules', function (Blueprint $table) {
            if (Schema::hasIndex('policy_rules', 'policy_rules_rule_key_unique')) $table->dropUnique('policy_rules_rule_key_unique');
            if (! Schema::hasIndex('policy_rules', 'policy_rules_effective_lookup_idx')) {
                $table->index(['rule_key', 'effective_from', 'effective_until', 'is_active'], 'policy_rules_effective_lookup_idx');
            }
        });

        Schema::table('employee_policy_overrides', function (Blueprint $table) {
            if (! Schema::hasIndex('employee_policy_overrides', 'employee_policy_employee_fk_idx')) $table->index('employee_id', 'employee_policy_employee_fk_idx');
            if (Schema::hasIndex('employee_policy_overrides', 'employee_policy_overrides_employee_id_rule_key_unique')) $table->dropUnique('employee_policy_overrides_employee_id_rule_key_unique');
            if (! Schema::hasIndex('employee_policy_overrides', 'employee_policy_effective_lookup_idx')) $table->index(['employee_id', 'rule_key', 'effective_from', 'effective_until'], 'employee_policy_effective_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employee_policy_overrides', function (Blueprint $table) {
            $table->dropIndex('employee_policy_effective_lookup_idx');
            $table->unique(['employee_id', 'rule_key']);
        });

        Schema::table('policy_rules', function (Blueprint $table) {
            $table->dropIndex('policy_rules_effective_lookup_idx');
            $table->unique('rule_key');
        });
    }
};
