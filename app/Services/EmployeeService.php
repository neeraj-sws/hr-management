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
    public const EMPLOYMENT_TYPES = ['full_time' => 'Full time', 'part_time' => 'Part time', 'contract' => 'Contract', 'intern' => 'Intern'];

    public function __construct(private readonly HrWorkflowService $workflow, private readonly AuditLogger $audit) {}

    /**
     * Creates the login, employee record, effective-dated salary profile and
     * leave balances in one transaction.
     */
    public function create(array $data, User $actor): AttendanceEmployee
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'is_active' => true]);
            $user->assignRole('Employee');
            if (! empty($data['is_manager'])) $user->assignRole('Manager');

            $employee = $this->workflow->createEmployee([
                ...array_intersect_key($data, array_flip(self::PROFILE_FIELDS)),
                'is_active' => true,
                'user_id' => $user->id,
                'daily_work_hours' => $data['daily_work_hours'] ?? 8,
                'monthly_salary' => $data['monthly_salary'] ?? 0,
            ], $actor->id);
            $this->syncTeams($employee, $data['team_ids'] ?? [], $actor, ($employee->joined_at ?? today())->toDateString());
            if ($employee->manager_id) EmployeeManagerHistory::create(['employee_id' => $employee->id, 'manager_id' => $employee->manager_id, 'starts_on' => $employee->joined_at ?? today(), 'changed_by' => $actor->id]);
            $this->audit->record('user.employee_account_created', $user, ['employee_id' => $employee->id]);

            return $employee;
        });
    }

    public function update(AttendanceEmployee $employee, array $data, User $actor): AttendanceEmployee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            if (isset($data['manager_id']) && (int) $data['manager_id'] === $employee->id) $data['manager_id'] = null;
            $previousManager = $employee->manager_id;
            $employee->update(array_intersect_key($data, array_flip(self::PROFILE_FIELDS)));
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
        $this->audit->record('employee.manager_changed', $employee, ['from_manager_id' => $previousManagerId, 'to_manager_id' => $employee->manager_id, 'pending_approvals_moved' => $moved]);
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
