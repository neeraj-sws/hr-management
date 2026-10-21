<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeManagerHistory;
use App\Models\EmployeeTeamHistory;
use App\Notifications\ApprovalRequested;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/** Employee directory: onboarding with a login account, profile updates and (de)activation. */
class EmployeeService
{
    public const PROFILE_FIELDS = ['employee_code', 'name', 'email', 'designation', 'phone', 'department_id', 'manager_id', 'joined_at', 'work_start', 'work_end', 'daily_work_hours', 'date_of_birth', 'employment_type', 'probation_ends_on', 'confirmed_on', 'notice_period_days'];
    public const EMPLOYMENT_TYPES = ['full_time' => 'Full time', 'part_time' => 'Part time', 'contract' => 'Contract', 'intern' => 'Intern', 'daily_wage' => 'Daily wage', 'ex_employee' => 'Ex-employee (left the company)'];
    public const EXIT_TYPES = ['resignation' => 'Resignation', 'termination' => 'Termination', 'end_of_contract' => 'End of contract', 'retirement' => 'Retirement'];
    /** Someone who has already left: kept for old attendance / salary records, with no working login. */
    public const EX_EMPLOYEE = 'ex_employee';

    public function __construct(private readonly HrWorkflowService $workflow, private readonly AuditLogger $audit) {}

    /**
     * Creates the login, employee record, effective-dated salary profile and
     * leave balances in one transaction.
     */
    public function create(array $data, User $actor): AttendanceEmployee
    {
        $left = ($data['employment_type'] ?? null) === self::EX_EMPLOYEE;
        $employee = DB::transaction(function () use ($data, $actor, $left) {
            // An ex-employee gets an inactive login with a random password: their records are kept, they cannot sign in.
            $password = $left && empty($data['password']) ? \Illuminate\Support\Str::password(32) : $data['password'];
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($password), 'is_active' => ! $left]);
            $user->assignRole('Employee');
            if (! empty($data['is_manager']) && ! $left) $user->assignRole('Manager');

            $employee = $this->workflow->createEmployee([
                ...array_intersect_key($data, array_flip(self::PROFILE_FIELDS)),
                ...($left ? $this->exitFields($data) : []),
                'is_active' => ! $left,
                'user_id' => $user->id,
                'daily_work_hours' => $data['daily_work_hours'] ?? 8,
                'monthly_salary' => $data['monthly_salary'] ?? 0,
                'pay_basis' => $data['pay_basis'] ?? 'monthly',
                'daily_rate' => $data['daily_rate'] ?? null,
            ], $actor->id);
            $this->syncTeams($employee, $data['team_ids'] ?? [], $actor, ($employee->joined_at ?? today())->toDateString());
            if ($employee->manager_id) EmployeeManagerHistory::create(['employee_id' => $employee->id, 'manager_id' => $employee->manager_id, 'starts_on' => $employee->joined_at ?? today(), 'changed_by' => $actor->id]);
            $this->audit->record('user.employee_account_created', $user, ['employee_id' => $employee->id]);
            if ($left) $this->audit->record('employee.exit_recorded', $employee, ['exit_type' => $employee->exit_type, 'exit_date' => $employee->exit_date?->toDateString(), 'source' => 'added as ex-employee']);

            return $employee;
        });
        // Nobody is told about someone who has already left.
        if ($left) return $employee;
        // Sent after the employee is saved: a notification or e-mail problem can no longer turn a
        // successful save into a "500 Server Error" (the employee used to be created anyway).
        $this->notifyManagerAssigned($employee, $actor);
        // Joining while screen capture is on (company-wide): the new employee is told at once.
        rescue(fn () => app(ScreenCaptureService::class)->notifyIfNeeded($employee->fresh('user')), null, true);

        return $employee;
    }

    public function update(AttendanceEmployee $employee, array $data, User $actor): AttendanceEmployee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            if (isset($data['manager_id']) && (int) $data['manager_id'] === $employee->id) $data['manager_id'] = null;
            $previousManager = $employee->manager_id;
            $leaving = ($data['employment_type'] ?? null) === self::EX_EMPLOYEE;
            $employee->update([...array_intersect_key($data, array_flip(self::PROFILE_FIELDS)), ...($leaving ? $this->exitFields($data) : [])]);
            // Marked as an ex-employee: deactivate (login, tokens and devices revoked) like any leaver.
            if ($leaving && $employee->is_active) $this->setActive($employee, false);
            // Keep the current salary record on the same working day, so Payroll never disagrees with the employee record.
            if ($employee->wasChanged('daily_work_hours')) {
                $employee->salaryProfiles()->orderByDesc('effective_from')->orderByDesc('id')->first()?->update(['standard_daily_hours' => $employee->daily_work_hours]);
            }
            if (array_key_exists('manager_id', $data) && (int) $previousManager !== (int) $employee->manager_id) $this->managerChanged($employee, $previousManager, $actor);
            if (array_key_exists('team_ids', $data)) $this->syncTeams($employee, $data['team_ids'] ?? [], $actor);
            if ($employee->user) {
                $employee->user->update(['name' => $employee->name, 'email' => $employee->email ?? $employee->user->email]);
                if (array_key_exists('is_manager', $data)) {
                    $data['is_manager'] ? $employee->user->assignRole('Manager') : $employee->user->removeRole('Manager');
                }
            }
            $this->audit->record('employee.updated', $employee, ['fields' => array_keys($employee->getChanges())]);

            return $employee->fresh();
        });
    }

    /** Exit details for someone recorded as an ex-employee (last working day, how they left). */
    private function exitFields(array $data): array
    {
        $type = array_key_exists($data['exit_type'] ?? '', self::EXIT_TYPES) ? $data['exit_type'] : 'resignation';

        return ['exit_date' => $data['exit_date'] ?? null, 'exit_type' => $type, 'exit_reason' => $data['exit_reason'] ?? null,
            'resigned_on' => $type === 'resignation' ? ($data['resigned_on'] ?? $data['exit_date'] ?? null) : null];
    }

    /**
     * Sets the employee's teams and keeps the dated membership history:
     * a team that is removed ends yesterday, a team that is added starts today.
     */
    public function syncTeams(AttendanceEmployee $employee, array $teamIds, User $actor, ?string $startsOn = null): void
    {
        $startsOn ??= today()->toDateString();
        $changes = $employee->teams()->sync(array_map('intval', $teamIds));
        if (! $changes['attached'] && ! $changes['detached']) return;
        foreach ($changes['detached'] as $teamId) $this->endMembership($employee->id, $teamId, $startsOn);
        foreach ($changes['attached'] as $teamId) {
            EmployeeTeamHistory::create(['employee_id' => $employee->id, 'team_id' => $teamId, 'starts_on' => $startsOn, 'changed_by' => $actor->id]);
        }
        $this->audit->record('employee.teams_changed', $employee, ['added' => $changes['attached'], 'removed' => $changes['detached'], 'effective_from' => $startsOn]);
    }

    /** Same as syncTeams, from the team's side (team edit screen / API). */
    public function syncTeamMembers(\App\Models\Team $team, array $employeeIds, User $actor): void
    {
        $today = today()->toDateString();
        $changes = $team->members()->sync(array_map('intval', $employeeIds));
        foreach ($changes['detached'] as $employeeId) $this->endMembership($employeeId, $team->id, $today);
        foreach ($changes['attached'] as $employeeId) {
            EmployeeTeamHistory::create(['employee_id' => $employeeId, 'team_id' => $team->id, 'starts_on' => $today, 'changed_by' => $actor->id]);
        }
    }

    private function endMembership(int $employeeId, int $teamId, string $from): void
    {
        // Added and removed on the same day: it never really started.
        EmployeeTeamHistory::where('employee_id', $employeeId)->where('team_id', $teamId)->whereNull('ends_on')->whereDate('starts_on', '>=', $from)->delete();
        EmployeeTeamHistory::where('employee_id', $employeeId)->where('team_id', $teamId)->whereNull('ends_on')->update(['ends_on' => \Illuminate\Support\Carbon::parse($from)->subDay()->toDateString()]);
    }

    /**
     * Keeps the reporting history (who managed whom, when) and moves pending
     * approvals that were waiting for the old manager to the new one.
     * Already-decided approvals keep their original approver.
     */
    private function managerChanged(AttendanceEmployee $employee, ?int $previousManagerId, User $actor): void
    {
        $today = today()->toDateString();
        if (! EmployeeManagerHistory::where('employee_id', $employee->id)->exists() && $previousManagerId) {
            EmployeeManagerHistory::create(['employee_id' => $employee->id, 'manager_id' => $previousManagerId, 'starts_on' => $employee->joined_at ?? $employee->created_at ?? today(), 'changed_by' => $actor->id]);
        }
        EmployeeManagerHistory::where('employee_id', $employee->id)->whereNull('ends_on')->update(['ends_on' => today()->subDay()->toDateString()]);
        EmployeeManagerHistory::where('employee_id', $employee->id)->whereDate('starts_on', $today)->delete();
        if ($employee->manager_id) EmployeeManagerHistory::create(['employee_id' => $employee->id, 'manager_id' => $employee->manager_id, 'starts_on' => $today, 'changed_by' => $actor->id]);

        $oldUserId = $previousManagerId ? AttendanceEmployee::whereKey($previousManagerId)->value('user_id') : null;
        $newUserId = $employee->manager_id ? AttendanceEmployee::whereKey($employee->manager_id)->value('user_id') : null;
        $moved = 0;
        if ($oldUserId) {
            $inbox = app(ApprovalInbox::class);
            foreach (ApprovalRequest::with('approvable')->where('status', 'pending')->where('approver_id', $oldUserId)->get() as $approval) {
                if ($inbox->subjectEmployee($approval->approvable)?->id !== $employee->id) continue;
                $approval->update(['approver_id' => $newUserId]);
                $moved++;
            }
            if ($moved && $newUserId) User::find($newUserId)?->notify(new ApprovalRequested('ManagerChange', $employee->id, "{$moved} pending request(s) from {$employee->name} now need your review."));
        }
        $this->notifyManagerAssigned($employee, $actor);
        $this->audit->record('employee.manager_changed', $employee, ['from_manager_id' => $previousManagerId, 'to_manager_id' => $employee->manager_id, 'pending_approvals_moved' => $moved]);
    }

    /** Admin-only password reset for an employee's login; revokes their API tokens. */
    public function resetPassword(AttendanceEmployee $employee, string $password, User $actor): void
    {
        abort_unless(\App\Support\Access::isAdmin($actor), 403, 'Only an administrator can change an employee password.');
        abort_unless((bool) $employee->user, 422, 'This employee has no login account.');
        $employee->user->update(['password' => Hash::make($password)]);
        $employee->user->tokens()->delete();
        $this->audit->record('user.password_reset', $employee->user, ['by' => $actor->id, 'employee_id' => $employee->id]);
        // The person is told (in the app and by e-mail) — never the password itself.
        Notifier::send($employee->user, 'PasswordChanged', $employee->user->id, "{$actor->name} changed your login password on ".now()->format('d M Y, h:i A').'. You were signed out of the mobile and desktop apps; sign in with the new password. If you did not ask for this, contact HR.', route('account.profile', [], false));
    }

    /** Tells a newly-assigned manager that this employee now reports to them, and by whom. */
    private function notifyManagerAssigned(AttendanceEmployee $employee, ?User $actor = null): void
    {
        if (! $employee->manager_id) return;
        $manager = AttendanceEmployee::with('user')->find($employee->manager_id);
        if (! $manager?->user) return;
        $by = $actor ? " (assigned by {$actor->name})" : '';
        Notifier::send($manager->user, 'ManagerAssigned', $employee->id, "You are now the manager of {$employee->name}{$by}. Their leave, overtime, attendance corrections and expense claims will come to you for approval.", route('people.employees.show', $employee, false), $actor?->id);
        if ($employee->user && $actor) Notifier::send($employee->user, 'ManagerAssigned', $employee->id, "{$manager->name} is now your manager{$by}.", route('account.profile', [], false), $actor->id);
    }

    /** Ends probation: records the confirmation date and tells the employee. */
    public function confirm(AttendanceEmployee $employee, string $date, User $actor): AttendanceEmployee
    {
        if ($employee->confirmed_on) throw \Illuminate\Validation\ValidationException::withMessages(['confirm' => 'Already confirmed on '.$employee->confirmed_on->format('d M Y').'.'])->status(409);
        $employee->update(['confirmed_on' => $date]);
        $this->audit->record('employee.confirmed', $employee, ['confirmed_on' => $date, 'by' => $actor->id]);
        $employee->user?->notify(new ApprovalRequested('Employee', $employee->id, 'Congratulations — your employment is confirmed from '.\Illuminate\Support\Carbon::parse($date)->format('d M Y').'.', route('account.profile', [], false)));

        return $employee->fresh();
    }

    /**
     * Records that make an employee part of the company's history. While any
     * exist the employee cannot be removed — deactivate them instead, so pay,
     * attendance and audit records stay complete.
     *
     * @return array<string, int> label => count (only non-zero)
     */
    public function historyCounts(AttendanceEmployee $employee): array
    {
        $counts = [
            'attendance days' => \App\Models\AttendanceSession::where('employee_id', $employee->id)->count(),
            'payroll entries' => \App\Models\PayrollItem::where('employee_id', $employee->id)->count(),
            'leave requests' => \App\Models\LeaveRequest::where('employee_id', $employee->id)->count(),
            'task time entries' => \App\Models\TaskTimeEntry::where('employee_id', $employee->id)->count(),
            'expense claims' => \App\Models\ExpenseClaim::where('employee_id', $employee->id)->count(),
            'people reporting to them' => AttendanceEmployee::where('manager_id', $employee->id)->count(),
        ];

        return array_filter($counts);
    }

    /**
     * Administrator only: permanently removes an employee added by mistake
     * (and their login), when they have no history. Audited.
     */
    public function remove(AttendanceEmployee $employee, User $actor): void
    {
        abort_unless(\App\Support\Access::isAdmin($actor), 403, 'Only an administrator can remove an employee.');
        if ($employee->user_id === $actor->id) throw \Illuminate\Validation\ValidationException::withMessages(['employee' => 'You cannot remove your own account.']);
        if ($history = $this->historyCounts($employee)) {
            $what = collect($history)->map(fn ($n, $label) => "{$n} {$label}")->join(', ');
            throw \Illuminate\Validation\ValidationException::withMessages(['employee' => "{$employee->name} has records ({$what}), so they cannot be removed. Deactivate them instead — their history stays intact."]);
        }
        DB::transaction(function () use ($employee, $actor) {
            $snapshot = $employee->only(['employee_code', 'name', 'email', 'department_id', 'manager_id', 'joined_at']);
            $user = $employee->user;
            $employee->tokens()->delete();
            // Remove their attendance computers too, so no device is left pointing at a missing employee.
            \Laravel\Sanctum\PersonalAccessToken::whereIn('id', $employee->devices()->whereNotNull('personal_access_token_id')->pluck('personal_access_token_id'))->delete();
            $employee->devices()->delete();
            $employee->delete();
            if ($user) { $user->tokens()->delete(); $user->delete(); }
            $this->audit->record('employee.removed', null, [...$snapshot, 'removed_by' => $actor->name]);
        });
    }

    public function setActive(AttendanceEmployee $employee, bool $active): AttendanceEmployee
    {
        return DB::transaction(function () use ($employee, $active) {
            $employee->update(['is_active' => $active]);
            $employee->user?->update(['is_active' => $active]);
            if (! $active) {
                $employee->user?->tokens()->delete();
                $tokenIds = $employee->devices()->whereNotNull('personal_access_token_id')->pluck('personal_access_token_id');
                if ($tokenIds->isNotEmpty()) PersonalAccessToken::whereIn('id', $tokenIds)->delete();
                $employee->devices()->update(['is_active' => false, 'personal_access_token_id' => null]);
            }
            $this->audit->record($active ? 'employee.reactivated' : 'employee.deactivated', $employee);

            return $employee->fresh();
        });
    }
}
