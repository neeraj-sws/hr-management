<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The bot set up in System > Telegram (a single row). The token is encrypted and never sent to a page. */
class TelegramSetting extends Model
{
    protected $fillable = ['enabled', 'bot_token', 'bot_username', 'admin_chat_id', 'updated_by'];

    protected $casts = ['enabled' => 'boolean', 'bot_token' => 'encrypted'];

    protected $hidden = ['bot_token'];

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The saved row, or null (also before the migration has run). */
    public static function current(): ?self
    {
        return rescue(fn () => static::query()->first(), null, false);
    }
}
