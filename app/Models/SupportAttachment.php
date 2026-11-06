<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A screenshot, image or PDF on a support message; kept on the private disk and opened through an authorised route. */
class SupportAttachment extends Model
{
    use Concerns\BelongsToEmployeeCompany;

    /** A login limited to one company sees this only when its SupportTicket is of that company. */
    public function companyOwner(): array { return ['ticket_id', SupportTicket::class]; }

    protected $fillable = ['ticket_id', 'message_id', 'uploaded_by', 'original_name', 'storage_path', 'mime_type', 'size'];

    protected $casts = ['size' => 'integer'];

    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function message(): BelongsTo { return $this->belongsTo(SupportMessage::class, 'message_id'); }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? number_format($this->size / 1048576, 1).' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
