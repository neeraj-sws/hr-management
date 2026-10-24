<?php

namespace App\Support;

use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Services\EmployeeService;

/**
 * What someone is, as badges shown the same way everywhere (header, people lists, profile,
 * attendance): Master Admin, HR, Accounts, Manager, Team lead, Employee or Trainee.
 * A trainee shows "Trainee" instead of "Employee"; someone with direct reports but no Manager /
 * HR / admin role shows "Team lead"; an employee without a login shows "No login".
 */
class PersonBadges
{
    /** role => colour, in the order they are shown */
    public const ROLES = ['Master Admin' => 'navy', 'HR' => 'blue', 'Accounts' => 'amber', 'Manager' => 'green'];

    /** @return array<int, array{label: string, tone: string}> */
    public static function for(?User $user, ?AttendanceEmployee $employee = null): array
    {
        $employee ??= $user?->employeeProfile;
        $user ??= $employee?->user;
        $badges = [];
        $roles = $user ? ($user->relationLoaded('roles') ? $user->roles->pluck('name') : $user->getRoleNames()) : collect();
        foreach (self::ROLES as $role => $tone) {
            if ($roles->contains($role)) $badges[] = ['label' => $role, 'tone' => $tone];
        }
        // Other roles a company added itself.
        foreach ($roles->reject(fn ($r) => isset(self::ROLES[$r]) || $r === 'Employee') as $role) $badges[] = ['label' => $role, 'tone' => 'blue'];

        $leads = $employee && ! $roles->intersect(['Master Admin', 'HR', 'Manager'])->count()
            && ($employee->relationLoaded('directReports') ? $employee->directReports->isNotEmpty() : $employee->directReports()->exists());
        if ($leads) $badges[] = ['label' => 'Team lead', 'tone' => 'green'];

        if ($employee?->employment_type === EmployeeService::TRAINEE) $badges[] = ['label' => 'Trainee', 'tone' => 'violet'];
        elseif ($badges === [] && ($employee || $roles->contains('Employee'))) $badges[] = ['label' => 'Employee', 'tone' => 'gray'];

        if ($employee && ! $user) $badges[] = ['label' => 'No login', 'tone' => 'gray'];

        return $badges;
    }

    /** The main one, e.g. under the name in the header. */
    public static function primary(?User $user, ?AttendanceEmployee $employee = null): string
    {
        return self::for($user, $employee)[0]['label'] ?? 'Employee';
    }
}
