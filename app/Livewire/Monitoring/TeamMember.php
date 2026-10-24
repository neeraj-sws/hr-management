<?php

namespace App\Livewire\Monitoring;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\MonitoringRequest;
use App\Models\Screenshot;
use App\Services\TeamMonitoringService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One person's monitoring day: summary, timeline, screenshots grouped by hour (each open / download logged),
 * the week, and — for HR / Admin — screen capture on / off; managers can ask HR to turn it on.
 */
class TeamMember extends Component
{
    use InteractsWithHr;

    #[Locked] public int $employeeId;
    #[Url] public string $date = '';
    #[Url] public string $tab = 'day';
    /** Capture dialog (turn on / off, or a manager's request). */
    public ?string $captureDialog = null;
    public string $captureReason = '';
    public string $captureUntil = '';

    public function mount(AttendanceEmployee $employee, TeamMonitoringService $monitoring): void
    {
        $monitoring->assertCanSee($this->user(), $employee);
        $this->employeeId = $employee->id;
        if (! strtotime($this->date) || $this->date > today()->toDateString()) $this->date = today()->toDateString();
        if (! in_array($this->tab, ['day', 'week'], true)) $this->tab = 'day';
        $monitoring->logView($this->user(), $employee, 'detail_viewed', ['date' => $this->date]);
    }

    private function employee(TeamMonitoringService $monitoring): AttendanceEmployee
    {
        $employee = AttendanceEmployee::with(['department', 'manager', 'user.roles'])->findOrFail($this->employeeId);
        $monitoring->assertCanSee($this->user(), $employee);

        return $employee;
    }

    public function stepDay(int $direction, TeamMonitoringService $monitoring): void
    {
        $next = \Illuminate\Support\Carbon::parse($this->date)->addDays($direction > 0 ? 1 : -1);
        if ($next->isFuture()) return;
        $this->date = $next->toDateString();
        $monitoring->logView($this->user(), $this->employee($monitoring), 'detail_viewed', ['date' => $this->date]);
    }

    public function updatedDate(TeamMonitoringService $monitoring): void
    {
        if (! strtotime($this->date) || $this->date > today()->toDateString()) $this->date = today()->toDateString();
        $monitoring->logView($this->user(), $this->employee($monitoring), 'detail_viewed', ['date' => $this->date]);
    }

    /** Opening a screenshot in the viewer (the image itself is logged again when it loads). */
    public function logScreenshot(int $id, TeamMonitoringService $monitoring): void
    {
        $shot = Screenshot::where('employee_id', $this->employeeId)->findOrFail($id);
        $monitoring->logView($this->user(), $this->employee($monitoring), 'screenshot_opened', ['screenshot_id' => $shot->id]);
    }

    public function openCapture(string $mode, TeamMonitoringService $monitoring): void
    {
        abort_unless(in_array($mode, ['on', 'off', 'request'], true), 404);
        abort_unless($mode === 'request' ? $monitoring->canUse($this->user()) : $monitoring->canToggle($this->user()), 403);
        $this->reset('captureReason', 'captureUntil');
        $this->resetValidation();
        $this->captureDialog = $mode;
    }

    public function closeCapture(): void
    {
        $this->captureDialog = null;
    }

    public function saveCapture(TeamMonitoringService $monitoring): void
    {
        $employee = $this->employee($monitoring);
        $this->validate(['captureReason' => [$this->captureDialog === 'off' ? 'nullable' : 'required', 'string', 'max:500'], 'captureUntil' => ['nullable', 'date', 'after_or_equal:today']],
            ['captureReason.required' => 'Write the reason.', 'captureUntil.after_or_equal' => 'The end date cannot be in the past.']);
        try {
            match ($this->captureDialog) {
                'on' => $monitoring->setCapture($employee, true, $this->captureReason, $this->captureUntil ?: null, $this->user()),
                'off' => $monitoring->setCapture($employee, false, $this->captureReason, null, $this->user()),
                'request' => $monitoring->requestCapture($employee, $this->captureReason, $this->captureUntil ?: null, $this->user()),
                default => null,
            };
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) $this->addError(in_array($field, ['captureReason', 'captureUntil'], true) ? $field : 'captureReason', $messages[0]);

            return;
        }
        $this->toast(match ($this->captureDialog) {
            'on' => "Screen capture is on for {$employee->name}. They were told in the app and by e-mail.",
            'off' => "Screen capture is off for {$employee->name}. They were told.",
            default => 'Request sent to HR (Approvals).',
        });
        $this->captureDialog = null;
    }

    public function render(TeamMonitoringService $monitoring)
    {
        $employee = $this->employee($monitoring);
        $row = $monitoring->rows(collect([$employee]), $this->date)[$employee->id];
        $capture = $monitoring->captureState($employee);

        return $this->page('livewire.monitoring.team-member', [
            'employee' => $employee,
            'row' => $row,
            'capture' => $capture,
            'shots' => $this->tab === 'day' ? $monitoring->screenshots($employee, $this->date) : collect(),
            'week' => $this->tab === 'week' ? $monitoring->week($employee, $this->date) : [],
            'canToggle' => $monitoring->canToggle($this->user()),
            'pendingRequest' => MonitoringRequest::where('employee_id', $employee->id)->where('status', 'pending')->latest()->first(),
            'isToday' => $this->date === today()->toDateString(),
        ], 'Team monitoring');
    }
}
