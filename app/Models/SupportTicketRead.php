<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** When a person last opened a ticket (for unread markers and "Seen"). */
class SupportTicketRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['ticket_id', 'user_id', 'last_read_at'];

    protected $casts = ['last_read_at' => 'datetime'];
}
