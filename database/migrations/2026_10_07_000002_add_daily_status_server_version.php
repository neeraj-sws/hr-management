<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->unsignedInteger('server_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('daily_work_statuses', fn (Blueprint $table) => $table->dropColumn('server_version'));
    }
};
