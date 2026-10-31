<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Telegram bot set up from System > Telegram (one row): the token is stored encrypted; empty fields use .env. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->nullable();
            $table->text('bot_token')->nullable();
            $table->string('bot_username', 100)->nullable();
            $table->string('admin_chat_id', 40)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_settings');
    }
};
