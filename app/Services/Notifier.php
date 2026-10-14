<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\ApprovalRequested;

/**
 * Sends one in-app (and, per preferences, e-mail/push) notification to a set
 * of people. Each person is notified once, the person who did the action is
 * skipped, and a failure for one recipient never undoes the action itself.
 */
class Notifier
{
    /** @param iterable<User|null>|User|null $users */
    public static function send(iterable|User|null $users, string $kind, int $recordId, string $message, ?string $url = null, ?int $exceptUserId = null): int
    {
        $users = collect($users instanceof User ? [$users] : ($users ?? []))
            ->filter(fn ($user) => $user instanceof User && ($user->is_active ?? true))
            ->unique('id')
            ->reject(fn (User $user) => $exceptUserId !== null && $user->id === $exceptUserId);

        $sent = 0;
        foreach ($users as $user) {
            if (rescue(fn () => $user->notify(new ApprovalRequested($kind, $recordId, $message, $url)) ?? true, false, true) !== false) $sent++;
        }

        return $sent;
    }

    /** Active users holding any of the given permissions (empty when a permission does not exist yet). */
    public static function withPermission(string ...$permissions): \Illuminate\Support\Collection
    {
        return collect($permissions)->flatMap(fn ($permission) => rescue(fn () => User::permission($permission)->where('is_active', true)->get(), collect(), false))->unique('id')->values();
    }
}
