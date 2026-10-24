<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function record(string $action, ?Model $subject = null, array $metadata = [], ?Request $request = null): AuditLog
    {
        $request ??= app()->bound('request') ? request() : null;

        // Viewing as an employee: the admin is the actor; the account used is noted.
        $impersonator = ImpersonationService::active() ? (int) session(ImpersonationService::SESSION_KEY) : null;
        if ($impersonator && $action !== 'user.impersonation_started') $metadata = [...$metadata, 'viewing_as_user_id' => Auth::id()];

        return AuditLog::create([
            'actor_id' => $impersonator ?: Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
