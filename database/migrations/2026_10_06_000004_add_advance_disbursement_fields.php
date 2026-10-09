<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->decimal('disbursed_amount', 12, 2)->default(0);
            $table->date('disbursed_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->dropColumn(['disbursed_amount', 'disbursed_on']);
        });
    }
};
