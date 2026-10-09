<?php

namespace App\Livewire\Work;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\DailyWorkStatus;
use App\Models\Task;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DailyStatus extends Component
{
    use InteractsWithHr, WithPagination;

    public string $summary = '';
    public string $yesterday = '';
    public string $plan = '';
    public string $blockers = '';
    public string $notes = '';
    #[Url] public string $date = '';
    #[Url] public ?int $employee = null;

    public function mount(): void
    {
        $today = $this->ownEmployee() ? DailyWorkStatus::where('employee_id', $this->ownEmployee()->id)->whereDate('work_date', today())->first() : null;
        $this->fill(['summary' => (string) $today?->summary, 'yesterday' => (string) $today?->yesterday, 'plan' => (string) $today?->plan, 'blockers' => (string) $today?->blockers, 'notes' => (string) $today?->notes]);
    }

    public function save(AuditLogger $audit): void
    {
        $employee = $this->requireEmployee();
        $this->validate(['summary' => ['required', 'string', 'min:5', 'max:4000'], 'yesterday' => ['nullable', 'string', 'max:4000'], 'plan' => ['nullable', 'string', 'max:4000'], 'blockers' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000']], ['summary.required' => 'Write what you completed today.'], ['summary' => 'completed work']);
        $fields = ['summary' => $this->summary, 'yesterday' => $this->yesterday ?: null, 'plan' => $this->plan ?: null, 'blockers' => $this->blockers ?: null, 'notes' => $this->notes ?: null, 'submitted_at' => now()];
        $status = DB::transaction(function () use ($employee, $fields) {
            $status = DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', today())->lockForUpdate()->first();
            if ($status) {
                $status->update([...$fields, 'submitted_by' => auth()->id(), 'server_version' => (int) $status->server_version + 1]);
            } else {
                $status = DailyWorkStatus::create([...$fields, 'employee_id' => $employee->id, 'work_date' => today()->toDateString(), 'submitted_by' => auth()->id(), 'server_version' => 1]);
            }

            return $status;
        });
        $audit->record('work_status.submitted', $status, ['has_blockers' => (bool) $status->blockers]);
        app(\App\Services\WorkService::class)->notifyBlocker($employee, $status);
        $this->toast('Daily status saved.');
    }

    /** Manager/HR nudges people who checked in but have not written today's status. */
    public function remindMissing(): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'employees.manage', 'attendance.view']), 403);
        $sent = app(\App\Services\WorkService::class)->remindDailyStatus(today()->toDateString(), $this->user()->canAny(['hr.manage', 'employees.manage']) ? null : $this->teamEmployeeIds()->all());
        $this->toast("Reminder sent to {$sent} ".str('person')->plural($sent).'.');
    }

    public function render()
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $orgWide = $user->canAny(['hr.manage', 'employees.manage']);
        $canSeeTeam = $orgWide || $user->can('attendance.view');
        $scope = $orgWide ? null : $this->teamEmployeeIds();
        $statuses = DailyWorkStatus::with('employee')
            ->when(! $canSeeTeam, fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($canSeeTeam && $scope, fn ($q) => $q->whereIn('employee_id', $scope->all() ?: [0]))
            ->when($this->date, fn ($q, $d) => $q->whereDate('work_date', $d))
            ->when($this->employee, fn ($q, $id) => $q->where('employee_id', $id))
            ->latest('work_date')->latest('id')->paginate(15);

        $missing = collect();
        if ($canSeeTeam && today()->isWeekday()) {
            $submitted = DailyWorkStatus::whereDate('work_date', today())->pluck('employee_id');
            $missing = AttendanceEmployee::where('is_active', true)->when($scope, fn ($q) => $q->whereIn('id', $scope->all() ?: [0]))
                ->whereNotIn('id', $submitted)->whereHas('sessions', fn ($q) => $q->whereDate('work_date', today()))->orderBy('name')->get();
        }

        return $this->page('livewire.work.daily-status', [
            'statuses' => $statuses,
            'own' => $own,
            'canSeeTeam' => $canSeeTeam,
            'missing' => $missing,
            'todayTasks' => $own ? Task::where('assignee_id', $own->id)->where(fn ($q) => $q->whereDate('completed_at', today())->orWhere('status', 'in_progress'))->get() : collect(),
            'people' => $canSeeTeam ? AttendanceEmployee::where('is_active', true)->when($scope, fn ($q) => $q->whereIn('id', $scope->all() ?: [0]))->orderBy('name')->get(['id', 'name']) : collect(),
        ], 'Daily Work Status');
    }
}
