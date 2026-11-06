<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Activity history of a ticket: created, status, priority, assigned (from → to, by whom), category, reopened, message hidden. */
class SupportTicketEvent extends Model
{
    use Concerns\BelongsToEmployeeCompany;

    /** A login limited to one company sees this only when its SupportTicket is of that company. */
    public function companyOwner(): array { return ['ticket_id', SupportTicket::class]; }

    public const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'user_id', 'type', 'from_value', 'to_value', 'note', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
