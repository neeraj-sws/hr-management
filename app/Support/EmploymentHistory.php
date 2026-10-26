<?php

namespace App\Support;

use App\Models\AttendanceEmployee;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\EmployeeManagerHistory;
use App\Models\EmployeeTeamHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * An employee's journey for the profile's History tab: what matters to HR only (joining, team, manager,
 * department / designation, salary, work hours / shift, login on/off, confirmation, exit), newest first.
 * Photo changes, monitoring switches and small edits stay in Security > Audit log (nothing is deleted).
 */
class EmploymentHistory
{
    /** Profile fields that are part of someone's employment story; other edits (phone, birthday) are left out. */
    private const KEY_FIELDS = ['employee_code', 'designation', 'department_id', 'employment_type', 'joined_at', 'work_start', 'work_end',
        'daily_work_hours', 'probation_ends_on', 'confirmed_on', 'notice_period_days', 'exit_date', 'exit_type', 'resigned_on'];

    /** Audit events shown as they are (the rest of what is recorded about a person is in the audit log). */
    private const EVENTS = ['employee.updated', 'employee.deactivated', 'employee.reactivated', 'employee.exit_recorded', 'employee.confirmed',
        'shift.timeline_updated', 'policy.employee_calendar_updated', 'employee.work_hours_applied_to_all'];

    /** kind => icon name and tone for the timeline dot. */
    public const KINDS = ['join' => ['user-plus', 'green'], 'team' => ['users', 'blue'], 'manager' => ['user', 'blue'], 'role' => ['briefcase', 'violet'],
        'salary' => ['wallet', 'amber'], 'hours' => ['clock', 'blue'], 'login' => ['lock', 'red'], 'exit' => ['logout', 'red'], 'confirm' => ['check', 'green']];

    /**
     * @return array{now: array<int, array{label: string, value: string, since: ?string}>, events: Collection<int, array{date: Carbon, kind: string, title: string, detail: ?string, by: ?string}>}
     */
    public static function for(AttendanceEmployee $employee, User $viewer, bool $showSalary): array
    {
        $events = collect();
        $add = function (?Carbon $date, string $kind, string $title, ?string $detail = null, ?string $by = null) use ($events) {
            if ($date) $events->push(['date' => $date, 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'by' => $by]);
        };

        $add($employee->joined_at ? Carbon::parse($employee->joined_at) : $employee->created_at, 'join', 'Joined', $employee->employee_code ? 'Code '.$employee->employee_code : null);

        $teams = EmployeeTeamHistory::with('team')->where('employee_id', $employee->id)->orderBy('starts_on')->get();
        foreach ($teams as $t) {
            $add($t->starts_on, 'team', 'Added to team '.($t->team?->name ?? 'removed team'));
            if ($t->ends_on) $add($t->ends_on, 'team', 'Left team '.($t->team?->name ?? 'removed team'));
        }
        $managers = EmployeeManagerHistory::with('manager')->where('employee_id', $employee->id)->orderBy('starts_on')->get();
        foreach ($managers as $m) {
            $add($m->starts_on, 'manager', $m->manager ? 'Manager: '.$m->manager->name : 'No manager');
        }

        if ($showSalary) {
            $previous = null;
            foreach ($employee->salaryProfiles()->orderBy('effective_from')->orderBy('id')->get() as $p) {
                $amount = $p->isDaily() ? '₹'.number_format((float) $p->daily_rate, 2).' per day' : '₹'.number_format((float) $p->monthly_salary, 2).' a month';
                if ($previous === $amount) continue;
                $add($p->effective_from, 'salary', $previous ? 'Salary changed' : 'Salary set', $previous ? $previous.' → '.$amount : $amount);
                $previous = $amount;
            }
        }

        if ($viewer->canAny(['employees.manage', 'hr.manage', 'audit.view'])) {
            $logs = AuditLog::with('actor')->where('subject_type', $employee::class)->where('subject_id', $employee->id)->whereIn('action', self::EVENTS)->latest('id')->limit(200)->get();
            foreach ($logs as $log) {
                $by = $log->actor?->name;
                $when = $log->created_at;
                if ($log->action === 'employee.updated') {
                    foreach (self::profileChanges($log) as [$kind, $title, $detail]) $add($when, $kind, $title, $detail, $by);
                    continue;
                }
                $what = $log->describe();
                $kind = match ($log->action) {
                    'employee.deactivated', 'employee.reactivated' => 'login',
                    'employee.exit_recorded' => 'exit',
                    'employee.confirmed' => 'confirm',
                    default => 'hours',
                };
                $title = match ($log->action) {
                    'employee.deactivated' => 'Login switched off',
                    'employee.reactivated' => 'Login switched on again',
                    'shift.timeline_updated' => 'Shift changed',
                    default => $what['title'],
                };
                $add($when, $kind, $title, $what['detail'], $by);
            }
        }

        $current = [];
        $teamNow = $teams->whereNull('ends_on')->map(fn ($t) => $t->team?->name)->filter()->join(', ');
        $teamSince = $teams->whereNull('ends_on')->min('starts_on');
        if ($teamNow) $current[] = ['label' => 'Team', 'value' => $teamNow, 'since' => $teamSince ? Carbon::parse($teamSince)->format('d M Y') : null];
        $managerNow = $managers->whereNull('ends_on')->last();
        $current[] = ['label' => 'Manager', 'value' => $employee->manager?->name ?? 'None', 'since' => $managerNow?->starts_on?->format('d M Y')];
        if ($employee->department) $current[] = ['label' => 'Department', 'value' => $employee->department->name, 'since' => null];
        if ($employee->joined_at) {
            $joined = Carbon::parse($employee->joined_at);
            $current[] = ['label' => 'With the company', 'value' => self::serviceLength($joined), 'since' => $joined->format('d M Y')];
        }

        return ['now' => $current, 'events' => $events->sortByDesc(fn ($e) => $e['date']->format('YmdHis'))->values()];
    }

    /** The parts of a profile edit that matter, as [kind, title, detail] rows (none for phone / birthday-only edits). */
    private static function profileChanges(AuditLog $log): array
    {
        $m = (array) ($log->metadata ?? []);
        $changes = array_intersect_key((array) ($m['changes'] ?? []), array_flip(self::KEY_FIELDS));
        if (! $changes) {
            // Older entries recorded only which fields changed.
            $fields = array_values(array_intersect((array) ($m['fields'] ?? []), self::KEY_FIELDS));
            if (! $fields) return [];
            $labels = collect($fields)->map(fn ($f) => str($f)->replace(['_id', '_'], ['', ' '])->ucfirst())->join(', ');

            return [[self::kindOf($fields), 'Profile changed', 'Changed: '.$labels]];
        }
        $value = fn ($field, $v) => match (true) {
            $v === null || $v === '' => '—',
            $field === 'department_id' => Department::find($v)?->name ?? '—',
            $field === 'employment_type' => \App\Services\EmployeeService::EMPLOYMENT_TYPES[$v] ?? $v,
            in_array($field, ['joined_at', 'probation_ends_on', 'confirmed_on', 'exit_date', 'resigned_on'], true) => Carbon::parse($v)->format('d M Y'),
            in_array($field, ['work_start', 'work_end'], true) => substr((string) $v, 0, 5),
            default => is_scalar($v) ? (string) $v : json_encode($v),
        };
        $rows = [];
        $hours = [];
        foreach ($changes as $field => $pair) {
            [$from, $to] = [$value($field, $pair[0] ?? null), $value($field, $pair[1] ?? null)];
            match ($field) {
                'designation' => $rows[] = ['role', 'Designation changed', $from.' → '.$to],
                'department_id' => $rows[] = ['role', 'Department changed', $from.' → '.$to],
                'employment_type' => $rows[] = ['role', 'Employment type changed', $from.' → '.$to],
                'work_start', 'work_end', 'daily_work_hours' => $hours[] = ['work_start' => 'Start', 'work_end' => 'End', 'daily_work_hours' => 'Hours per day'][$field].' '.$from.' → '.$to,
                'exit_date', 'exit_type', 'resigned_on' => $rows[] = ['exit', ['exit_date' => 'Last working day', 'exit_type' => 'Exit type', 'resigned_on' => 'Resigned on'][$field].' set', $to],
                default => $rows[] = ['role', str($field)->replace(['_id', '_'], ['', ' '])->ucfirst().' changed', $from.' → '.$to],
            };
        }
        if ($hours) $rows[] = ['hours', 'Work hours changed', implode(' · ', $hours)];

        return $rows;
    }

    private static function kindOf(array $fields): string
    {
        if (array_intersect($fields, ['work_start', 'work_end', 'daily_work_hours'])) return 'hours';
        if (array_intersect($fields, ['exit_date', 'exit_type', 'resigned_on'])) return 'exit';

        return 'role';
    }

    private static function serviceLength(Carbon $joined): string
    {
        $diff = $joined->diff(today());
        $parts = array_filter([$diff->y ? $diff->y.' '.str('year')->plural($diff->y) : null, $diff->m ? $diff->m.' '.str('month')->plural($diff->m) : null]);

        return $parts ? implode(' ', $parts) : max(0, (int) $joined->diffInDays(today())).' days';
    }
}
