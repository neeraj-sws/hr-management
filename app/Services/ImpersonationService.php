<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "View as employee" (Master Admin only): see the website exactly as one employee sees it, without their
 * password and without opening the website for them. Their account, password and sessions are not touched.
 * Every start and end is audit-logged, everything done meanwhile is logged under the admin's name, and
 * personal settings (2-step sign-in, contact details, photo, preferences, saved passwords) stay blocked.
 */
class ImpersonationService
{
    public const SESSION_KEY = 'sws.impersonator_id';
    public const STARTED_KEY = 'sws.impersonation_started_at';
    public const BLOCKED = 'Not available while you are viewing as an employee. Return to your own account first.';

    public function __construct(private readonly AuditLogger $audit) {}

    public static function active(): bool
    {
        return app()->bound('session') && session()->has(self::SESSION_KEY);
    }

    public static function impersonator(): ?User
    {
        return self::active() ? User::find((int) session(self::SESSION_KEY)) : null;
    }

    /** Stops a personal-settings action while viewing as someone else. */
    public static function block(): void
    {
        abort_if(self::active(), 403, self::BLOCKED);
    }

    /** Why this admin cannot view as this employee, or null when they can. */
    public function refusal(?User $admin, AttendanceEmployee $employee): ?string
    {
        $target = $employee->user;

        return match (true) {
            ! $admin || ! $admin->hasRole('Master Admin') => 'Only the Master Admin can view as an employee.',
            self::active() => 'You are already viewing as someone. Return to your own account first.',
            ! $target => 'This employee has no login.',
            ! $target->is_active || ! $employee->is_active => 'This employee is inactive.',
            $target->id === $admin->id => 'This is your own account.',
            Access::isAdmin($target) => 'An administrator account cannot be viewed this way.',
            default => null,
        };
    }

    public function start(Request $request, AttendanceEmployee $employee): void
    {
        $admin = $request->user();
        $employee->loadMissing('user');
        abort_if($reason = $this->refusal($admin, $employee), 403, $reason);
        $target = $employee->user;
        $this->audit->record('user.impersonation_started', $target, ['employee_id' => $employee->id, 'employee' => $employee->name]);

        // Signs the browser in as the employee for this session only (no "remember me", their sessions untouched).
        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $admin->id);
        $request->session()->put(self::STARTED_KEY, now()->toIso8601String());
    }

    /** Back to the admin's own account. @return int|null the employee viewed */
    public function stop(Request $request): ?int
    {
        $adminId = (int) $request->session()->get(self::SESSION_KEY);
        $startedAt = $request->session()->get(self::STARTED_KEY);
        $viewed = $request->user();
        $admin = User::where('is_active', true)->find($adminId);
        $request->session()->forget([self::SESSION_KEY, self::STARTED_KEY]);
        if (! $admin || ! $admin->hasRole('Master Admin')) {
            // The admin account was switched off meanwhile: end the session instead of returning to it.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }
        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        $this->audit->record('user.impersonation_ended', $viewed, [
            'minutes' => $startedAt ? (int) \Illuminate\Support\Carbon::parse($startedAt)->diffInMinutes(now()) : null,
        ]);

        return $viewed?->employeeProfile?->id;
    }
}
