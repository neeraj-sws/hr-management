<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's linked Telegram private chat (or a pending link token waiting for /start in the bot). */
class TelegramLink extends Model
{
    protected $fillable = ['user_id', 'chat_id', 'telegram_username', 'token_hash', 'token_expires_at', 'linked_at'];

    protected $casts = ['token_expires_at' => 'datetime', 'linked_at' => 'datetime'];

    protected $hidden = ['token_hash', 'chat_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLinked(): bool
    {
        return $this->chat_id !== null && $this->chat_id !== '';
    }
}
