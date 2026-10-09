<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Team-level policy values: company rule → team override → employee override. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_policy_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('rule_key', 100);
            $table->json('configuration');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'rule_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_policy_overrides');
    }
};
