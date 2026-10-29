<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram: each person's linked private chat (made through a short-lived, single-use link token), and one row per
 * message sent so a retried job never sends the same message twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('chat_id', 40)->nullable()->unique();
            $table->string('telegram_username', 100)->nullable();
            // Only a hash of the link token is kept; it is cleared once used or replaced.
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->string('target', 10);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40)->nullable();
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('telegram_links');
    }
};
