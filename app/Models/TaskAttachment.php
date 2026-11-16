<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document attached to a task (kept on the private disk, downloaded through a checked route). */
class TaskAttachment extends Model
{
    protected $fillable = ['task_id', 'name', 'path', 'mime', 'size', 'uploaded_by'];

    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? number_format($this->size / 1048576, 1).' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
