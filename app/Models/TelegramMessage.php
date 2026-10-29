<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Telegram message (by its key): sent once only, with the result of the last try. */
class TelegramMessage extends Model
{
    protected $fillable = ['key', 'target', 'user_id', 'event', 'status', 'attempts', 'error', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];
}
