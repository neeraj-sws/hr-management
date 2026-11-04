<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A course fee's installment plan: each installment's amount and due date (HR can change them), and which
 * reminders were already sent. Empty = equal monthly installments from the start date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('training_enrollments', 'schedule')) return;
        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->json('schedule')->nullable()->after('installments');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('training_enrollments', 'schedule')) return;
        Schema::table('training_enrollments', fn (Blueprint $table) => $table->dropColumn('schedule'));
    }
};
