<?php

namespace App\Policies;

use App\Models\AttendanceEmployee;
use App\Models\CredentialVaultEntry;
use App\Models\User;

/**
 * Who may reveal a secret, by visibility:
 *   private  – creator only
 *   hr       – creator, users with hr.manage or credentials.manage
 *   employee – creator, vault managers, and the owning employee (credentials.view)
 *   team     – creator, vault managers, and members of the team (credentials.view)
 * Listing metadata (label/category) for administration is credentials.manage.
 */
class CredentialVaultEntryPolicy
{
    public function view(User $user, CredentialVaultEntry $entry): bool
    {
        if ($entry->created_by === $user->id) return true;
        $manager = $user->canAny(['credentials.manage', 'hr.manage']);

        return match ($entry->visibility) {
            'private' => false,
            'hr' => $manager,
            'employee' => $manager || ($user->can('credentials.view') && $entry->owner_employee_id !== null && $entry->owner_employee_id === $this->employeeId($user)),
            'team' => $manager || ($user->can('credentials.view') && $entry->team_id !== null && AttendanceEmployee::where('user_id', $user->id)->whereHas('teams', fn ($q) => $q->whereKey($entry->team_id))->exists()),
            default => false,
        };
    }

    public function delete(User $user, CredentialVaultEntry $entry): bool
    {
        return $entry->created_by === $user->id || $user->can('credentials.manage');
    }

    private function employeeId(User $user): ?int
    {
        return AttendanceEmployee::where('user_id', $user->id)->value('id');
    }
}
