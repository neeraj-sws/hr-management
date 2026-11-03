<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One message in a support chat. Internal notes (is_internal) are for staff only; nobody edits or deletes messages. */
class SupportMessage extends Model
{
    protected $fillable = ['ticket_id', 'user_id', 'sender_type', 'body', 'is_internal', 'hidden_at', 'hidden_by', 'hidden_reason'];

    protected $casts = ['is_internal' => 'boolean', 'hidden_at' => 'datetime'];

    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function hider(): BelongsTo { return $this->belongsTo(User::class, 'hidden_by'); }
    public function attachments(): HasMany { return $this->hasMany(SupportAttachment::class, 'message_id'); }
}
