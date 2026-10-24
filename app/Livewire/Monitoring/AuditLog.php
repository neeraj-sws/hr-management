<?php

namespace App\Livewire\Monitoring;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\AuditLog as Log;
use App\Models\Screenshot;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Monitoring audit log (HR / Admin): who looked at whose data, which screenshot, view or download, and capture changes. */
class AuditLog extends Component
{
    use InteractsWithHr, WithPagination;

    public const ACTIONS = [
        'screenshot.viewed' => 'Viewed a screenshot',
        'screenshot.downloaded' => 'Downloaded a screenshot',
        'monitoring.screenshot_opened' => 'Opened a screenshot',
        'monitoring.detail_viewed' => 'Opened an employee timeline',
        'monitoring.team_viewed' => 'Opened Team monitoring',
        'screen_capture.enabled_for_employee' => 'Capture turned ON',
        'screen_capture.disabled_for_employee' => 'Capture turned OFF',
        'screen_capture.requested' => 'Capture requested',
        'screen_capture.notice_sent' => 'Employee told (notice sent)',
        'screen_capture.acknowledged' => 'Employee confirmed the policy',
        'screen_capture.purged' => 'Old screenshots deleted',
    ];

    #[Url] public string $action = '';
    #[Url] public ?int $employee = null;
    #[Url] public string $from = '';
    #[Url] public string $to = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('monitoring.view'), 403);
        if (! strtotime($this->from)) $this->from = today()->subDays(30)->toDateString();
        if (! strtotime($this->to)) $this->to = today()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $shotIds = $this->employee ? Screenshot::where('employee_id', $this->employee)->pluck('id') : null;
        $logs = Log::with('actor')->whereIn('action', array_keys(self::ACTIONS))
            ->when($this->action, fn ($q, $a) => $q->where('action', $a))
            ->when($this->employee, fn ($q) => $q->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('subject_type', AttendanceEmployee::class)->where('subject_id', $this->employee))
                ->orWhere(fn ($q) => $q->where('subject_type', Screenshot::class)->whereIn('subject_id', $shotIds->all() ?: [0]))))
            ->whereBetween('created_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->latest('id')->paginate(25);
        // Whose data: the employee itself, or the screenshot's employee.
        $shots = Screenshot::with('employee')->whereIn('id', $logs->where('subject_type', Screenshot::class)->pluck('subject_id')->all() ?: [0])->get()->keyBy('id');
        $people = AttendanceEmployee::whereIn('id', $logs->where('subject_type', AttendanceEmployee::class)->pluck('subject_id')->all() ?: [0])->get()->keyBy('id');

        return $this->page('livewire.monitoring.audit-log', [
            'logs' => $logs, 'shots' => $shots, 'people' => $people,
            'employees' => AttendanceEmployee::orderBy('name')->get(['id', 'name', 'employee_code']),
        ], 'Monitoring audit log');
    }
}
