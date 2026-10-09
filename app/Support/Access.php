<?php

namespace App\Support;

use App\Models\User;

/**
 * Evaluates the rule tokens used by the `access:` route middleware.
 * A user passes when ANY token matches:
 *   profile  – the user has an employee profile (self-service pages)
 *   approver – the user holds at least one approval permission
 *   interviewer – the user has been assigned at least one interview
 *   any      – any signed-in user
 *   <name>   – a Spatie permission
 */
class Access
{
    public const APPROVER_PERMISSIONS = [
        'approvals.manage', 'leave.manage', 'leave.approve', 'overtime.manage', 'payroll.approve',
        'salary.adjust', 'salary.advance', 'penalties.manage', 'lifecycle.manage', 'attendance.correct', 'expenses.manage',
    ];

    public static function allows(?User $user, array $rules): bool
    {
        if (! $user) return false;
        if ($rules === []) return true;

        foreach ($rules as $rule) {
            $allowed = match ($rule) {
                'any' => true,
                'profile' => $user->employeeProfile !== null,
                'approver' => $user->canAny(self::APPROVER_PERMISSIONS),
                'interviewer' => \App\Models\Interview::where('interviewer_id', $user->id)->exists(),
                default => $user->can($rule),
            };
            if ($allowed) return true;
        }

        return false;
    }
}
