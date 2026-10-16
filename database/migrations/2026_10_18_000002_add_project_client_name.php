<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'client_name')) {
            Schema::table('projects', fn (Blueprint $table) => $table->string('client_name')->nullable()->after('name'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'client_name')) {
            Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('client_name'));
        }
    }
};
