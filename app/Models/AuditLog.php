<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'user_agent', 'created_at'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }

    /**
     * Audit categories, by action prefix. Checked in order, so the more
     * specific "employee.asset_" lands in Assets before the general
     * "employee." in Employee. Anything not listed is System Activity.
     */
    public const CATEGORIES = [
        'assets' => ['Assets', ['employee.asset_']],
        'attendance' => ['Attendance', ['attendance.', 'shift.', 'overtime.', 'screen_capture.']],
        'payroll' => ['Payroll', ['payroll.', 'salary.', 'penalty.', 'compliance.', 'settlement.', 'payment.']],
        'leave' => ['Leave', ['leave.', 'leave_type.', 'holiday.']],
        'tasks' => ['Tasks', ['task.', 'project.', 'work_status.']],
        'expenses' => ['Expenses', ['expense.']],
        'employee' => ['Employee', ['employee.', 'user.', 'team.', 'profile.', 'note.', 'performance.', 'training.', 'candidate.', 'interview.']],
        'system' => ['System Activity', []],
    ];

    public static function categoryOf(string $action): string
    {
        foreach (self::CATEGORIES as $key => [, $prefixes]) {
            foreach ($prefixes as $prefix) if (str_starts_with($action, $prefix)) return $key;
        }

        return 'system';
    }

    public function category(): string
    {
        return self::categoryOf((string) $this->action);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category()][0];
    }

    /** Field names as people say them, for "what changed" in an employee's history. */
    private const FIELD_LABELS = [
        'employee_code' => 'Employee code', 'name' => 'Name', 'email' => 'Email', 'designation' => 'Designation', 'phone' => 'Phone',
        'department_id' => 'Department', 'manager_id' => 'Manager', 'joined_at' => 'Joining date', 'work_start' => 'Work start', 'work_end' => 'Work end',
        'daily_work_hours' => 'Hours per day', 'date_of_birth' => 'Date of birth', 'employment_type' => 'Employment type', 'probation_ends_on' => 'Probation ends',
        'confirmed_on' => 'Confirmed on', 'notice_period_days' => 'Notice period (days)', 'is_active' => 'Active', 'exit_date' => 'Last working day',
        'exit_type' => 'Exit type', 'exit_reason' => 'Exit note', 'resigned_on' => 'Resigned on', 'monthly_salary' => 'Monthly salary', 'photo_path' => 'Photo',
    ];

    /**
     * The event in plain words for an employee's history: ['title' => 'Screen capture turned on', 'detail' => '…'].
     * Unknown events fall back to their action name and stored details.
     */
    public function describe(): array
    {
        $m = (array) ($this->metadata ?? []);
        $name = fn ($id) => $id ? (AttendanceEmployee::find($id)?->name ?? "employee #{$id}") : 'nobody';
        $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
        $value = function ($field, $v) use ($name) {
            if ($v === null || $v === '') return '—';
            return match ($field) {
                'manager_id' => $name($v),
                'department_id' => Department::find($v)?->name ?? '—',
                'is_active' => $v ? 'yes' : 'no',
                'employment_type' => \App\Services\EmployeeService::EMPLOYMENT_TYPES[$v] ?? $v,
                'joined_at', 'date_of_birth', 'probation_ends_on', 'confirmed_on', 'exit_date', 'resigned_on' => \Illuminate\Support\Carbon::parse($v)->format('d M Y'),
                'work_start', 'work_end' => substr((string) $v, 0, 5),
                'photo_path' => 'photo',
                default => is_scalar($v) ? (string) $v : json_encode($v),
            };
        };
        $onOff = fn (string $what) => [$what.' turned '.(str_ends_with($this->action, 'enabled_for_employee') ? 'on' : 'off'), null];

        [$title, $detail] = match ($this->action) {
            'employee.created' => ['Employee added', isset($m['employee_code']) ? 'Code '.$m['employee_code'] : null],
            'employee.updated' => ['Profile updated', ! empty($m['changes'])
                ? collect($m['changes'])->map(fn ($pair, $f) => (self::FIELD_LABELS[$f] ?? str($f)->replace('_', ' ')->ucfirst()).': '.$value($f, $pair[0] ?? null).' → '.$value($f, $pair[1] ?? null))->join(' · ')
                : 'Changed: '.collect($m['fields'] ?? [])->reject(fn ($f) => $f === 'updated_at')->map(fn ($f) => self::FIELD_LABELS[$f] ?? str($f)->replace('_', ' '))->join(', ')],
            'employee.manager_changed' => ['Manager changed', 'From '.$name($m['from_manager_id'] ?? null).' to '.$name($m['to_manager_id'] ?? null).(! empty($m['pending_approvals_moved']) ? ' · '.$m['pending_approvals_moved'].' pending approvals moved' : '')],
            'employee.deactivated' => ['Deactivated', 'Login, app tokens and attendance devices switched off'],
            'employee.reactivated' => ['Reactivated', 'Can sign in again'],
            'employee.exit_recorded' => ['Exit recorded', str((string) ($m['exit_type'] ?? 'exit'))->replace('_', ' ')->ucfirst().' · last day '.$date($m['exit_date'] ?? null).(isset($m['source']) ? ' · '.$m['source'] : '')],
            'employee.photo_changed' => ['Photo changed', null],
            'employee.photo_removed' => ['Photo removed', null],
            'employee.confirmed' => ['Employment confirmed', isset($m['confirmed_on']) ? 'From '.$date($m['confirmed_on']) : null],
            'employee.teams_changed' => ['Teams changed', null],
            'employee.removed' => ['Employee removed', null],
            'employee.work_hours_applied_to_all' => ['Company work hours applied', isset($m['daily_hours']) ? $m['daily_hours'].' h per day' : null],
            'screen_capture.enabled_for_employee', 'screen_capture.disabled_for_employee' => $onOff('Screen capture'),
            'app_usage.enabled_for_employee', 'app_usage.disabled_for_employee' => $onOff('Application usage tracking'),
            'window_titles.enabled_for_employee', 'window_titles.disabled_for_employee' => $onOff('Window-title tracking'),
            'shift.timeline_updated' => ['Shift dates changed', ! empty($m['rows']) ? collect($m['rows'])->map(fn ($r) => (\App\Models\Shift::find($r['shift_id'] ?? 0)?->name ?? 'Shift').' from '.$date($r['starts_on'] ?? null).(! empty($r['ends_on']) ? ' to '.$date($r['ends_on']) : ' onwards'))->join(' · ') : 'Shift assignment removed (company hours apply)'],
            'policy.employee_calendar_updated' => ['Working days changed', isset($m['days']) ? \App\Support\WorkCalendar::describe($m) : null],
            'holiday.weekly_off_announced' => ['Weekly off announced', null],
            default => [str($this->action)->after('.')->replace('_', ' ')->ucfirst()->toString(), collect($m)->reject(fn ($v, $k) => in_array($k, ['by'], true))->map(fn ($v, $k) => str($k)->replace('_', ' ')->ucfirst().': '.(is_scalar($v) ? $v : json_encode($v)))->join(' · ') ?: null],
        };

        return ['title' => $title, 'detail' => $detail];
    }

    /** One line on who the event was about, e.g. "Assigned to Risha Gangrade" or "Self-assigned". */
    public function summary(): ?string
    {
        $meta = (array) ($this->metadata ?? []);
        if (! isset($meta['assignee_id']) && ! isset($meta['assignee_name'])) return null;
        // Older entries only stored the id.
        $name = $meta['assignee_name'] ?? \App\Models\AttendanceEmployee::find($meta['assignee_id'])?->name;
        $self = $meta['self_assigned'] ?? ($name && $this->actor && \App\Models\AttendanceEmployee::where('id', $meta['assignee_id'] ?? 0)->value('user_id') === $this->actor_id);
        $line = $self ? 'Self-assigned' : ($name ? 'Assigned to '.$name : null);
        if ($line && ! empty($meta['previous_assignee'])) $line .= ' (was '.$meta['previous_assignee'].')';
        if ($line && ! empty($meta['task'])) $line .= ' · "'.\Illuminate\Support\Str::limit($meta['task'], 60).'"';

        return $line;
    }

    /** Filters to one category with the same precedence as categoryOf(). */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        if (! isset(self::CATEGORIES[$category])) return $query;
        $earlier = [];
        foreach (self::CATEGORIES as $key => [, $prefixes]) {
            if ($key === $category) break;
            $earlier = [...$earlier, ...$prefixes];
        }
        $own = self::CATEGORIES[$category][1];
        if ($category === 'system') $own = [];
        $all = collect(self::CATEGORIES)->flatMap(fn ($c) => $c[1])->all();

        if ($own) {
            $query->where(function (Builder $q) use ($own) {
                foreach ($own as $prefix) $q->orWhere('action', 'like', $prefix.'%');
            });
        }
        foreach ($category === 'system' ? $all : $earlier as $prefix) $query->where('action', 'not like', $prefix.'%');

        return $query;
    }
}
