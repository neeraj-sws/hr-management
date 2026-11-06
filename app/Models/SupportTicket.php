<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An employee's support request with its chat (messages), files, history and read markers. */
class SupportTicket extends Model
{
    use Concerns\BelongsToEmployeeCompany;
    protected $fillable = ['employee_id', 'created_by', 'category_id', 'subject', 'description', 'priority', 'status', 'assigned_to', 'assigned_at',
        'first_response_at', 'last_message_at', 'last_message_by', 'resolved_at', 'resolved_by', 'closed_at', 'closed_by', 'reopen_count'];

    protected $casts = ['assigned_at' => 'datetime', 'first_response_at' => 'datetime', 'last_message_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'reopen_count' => 'integer'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function category(): BelongsTo { return $this->belongsTo(SupportCategory::class, 'category_id'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
    public function messages(): HasMany { return $this->hasMany(SupportMessage::class, 'ticket_id'); }
    public function attachments(): HasMany { return $this->hasMany(SupportAttachment::class, 'ticket_id'); }
    public function events(): HasMany { return $this->hasMany(SupportTicketEvent::class, 'ticket_id'); }
    public function reads(): HasMany { return $this->hasMany(SupportTicketRead::class, 'ticket_id'); }

    /** "SR-00042": the number shown everywhere. */
    public function number(): string
    {
        return self::numberFor((int) $this->id);
    }

    public static function numberFor(int $id): string
    {
        return 'SR-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /** "SR-00042", "sr42", "#42" or "42" → 42; anything else → null. */
    public static function idFromSearch(string $term): ?int
    {
        return preg_match('/^\s*(?:sr-?|#)?0*(\d{1,9})\s*$/i', $term, $m) ? (int) $m[1] : null;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['resolved', 'closed'], true);
    }
}
