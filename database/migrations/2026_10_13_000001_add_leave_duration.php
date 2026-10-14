<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A leave day can now be a full day, a half day or a short leave (part of a day).
 * Half and short leave are always a single day; the fractional weight is stored
 * in the existing `days` column, so balances already deduct the right amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('duration', 10)->default('full')->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('duration');
        });
    }
};
