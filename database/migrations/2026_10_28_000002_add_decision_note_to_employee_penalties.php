<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why a penalty was approved, reduced, rejected or removed, shown to the employee and HR on the Penalties page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_penalties', function (Blueprint $table) {
            $table->text('decision_note')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('employee_penalties', function (Blueprint $table) {
            $table->dropColumn('decision_note');
        });
    }
};
