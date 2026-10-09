<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    protected $fillable = ['approvable_type', 'approvable_id', 'step', 'approver_id', 'status', 'comment', 'decided_at'];
    protected $casts = ['decided_at' => 'datetime'];
    public function approvable() { return $this->morphTo(); }
    public function approver() { return $this->belongsTo(User::class, 'approver_id'); }
}
