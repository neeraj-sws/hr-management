<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskComment extends Model
{
    use Concerns\BelongsToEmployeeCompany;

    /** A login limited to one company sees this only when its Task is of that company. */
    public function companyOwner(): array { return ['task_id', Task::class]; }

    protected $fillable = ['task_id', 'user_id', 'body'];

    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
