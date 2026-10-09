<?php

namespace App\Livewire\Work;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Services\HrWorkflowService;
use Livewire\Component;

class Timer extends Component
{
    use InteractsWithHr;

    public ?int $taskId = null;

    public function start(HrWorkflowService $workflow): void
    {
        $this->validate(['taskId' => ['required', 'integer']], [], ['taskId' => 'task']);
        $employee = $this->requireEmployee();
        $workflow->startTask($employee, Task::where('assignee_id', $employee->id)->findOrFail($this->taskId));
        $this->reset('taskId');
        $this->toast('Timer started.');
    }

    public function pause(HrWorkflowService $workflow): void
    {
        $employee = $this->requireEmployee();
        $workflow->stopTaskTimer($employee, TaskTimeEntry::where('employee_id', $employee->id)->where('status', 'running')->firstOrFail());
        $this->toast('Timer paused.');
    }

    public function complete(HrWorkflowService $workflow): void
    {
        $employee = $this->requireEmployee();
        $entry = TaskTimeEntry::where('employee_id', $employee->id)->where('status', 'running')->firstOrFail();
        $workflow->stopTaskTimer($employee, $entry, true);
        $this->toast('Task completed.');
    }

    public function render()
    {
        $employee = $this->requireEmployee();
        $running = TaskTimeEntry::with('task.project')->where('employee_id', $employee->id)->where('status', 'running')->first();
        $today = TaskTimeEntry::with('task')->where('employee_id', $employee->id)->whereDate('started_at', today())->latest('started_at')->get();
        $todaySeconds = (int) $today->sum(fn ($e) => $e->status === 'running' ? $e->started_at->diffInSeconds(now()) : (int) $e->duration_seconds);

        return $this->page('livewire.work.timer', [
            'running' => $running,
            'entries' => $today,
            'todaySeconds' => $todaySeconds,
            'openTasks' => Task::where('assignee_id', $employee->id)->where('status', '!=', 'completed')->orderByRaw('due_on IS NULL')->orderBy('due_on')->get(),
            'session' => \App\Models\AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', today())->first(),
        ], 'Task Timer');
    }
}
