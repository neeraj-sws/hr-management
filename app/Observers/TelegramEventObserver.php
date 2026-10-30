<?php

namespace App\Observers;

use App\Models\AttendanceCorrection;
use App\Models\EmployeePenalty;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Models\SalaryPayment;
use App\Models\Task;
use App\Services\TelegramEvents;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Super Admin Telegram feed for leave, corrections, daily status, tasks, payslips, payments, advances and fines - whichever screen,
 * app or job made the change. Runs only after the save is committed (a rolled-back change sends nothing) and only
 * when the status really changed. A Telegram problem never breaks the save.
 */
class TelegramEventObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Model $model): void
    {
        rescue(fn () => match (true) {
            $model instanceof LeaveRequest => $model->status === 'pending' ? $this->events()->leave($model, 'applied') : ($model->status === 'rejected' && ! $model->decided_by ? $this->events()->leaveAutoRejected($model) : null),
            $model instanceof \App\Models\OvertimeRequest => $model->status === 'pending' ? $this->events()->overtimeRequested($model) : null,
            $model instanceof \App\Models\ExpenseClaim => $model->status === 'pending' ? $this->events()->expenseSubmitted($model) : null,
            $model instanceof \App\Models\NightWorkRequest => $model->status === 'pending' ? $this->events()->nightWorkRequested($model) : null,
            $model instanceof Task => $model->assignee_id ? $this->events()->task($model, 'assigned') : null,
            $model instanceof Payslip => $this->events()->payslip($model),
            $model instanceof SalaryPayment => $this->events()->salaryPayment($model),
            $model instanceof EmployeePenalty => $this->events()->fine($model, 'made'),
            $model instanceof AttendanceCorrection => $model->status === 'pending' ? $this->events()->correctionRequested($model) : null,
            $model instanceof \App\Models\DailyWorkStatus => $this->events()->dailyStatus($model),
            default => null,
        }, null, true);
    }

    public function updated(Model $model): void
    {
        rescue(function () use ($model) {
            if ($model instanceof LeaveRequest && $model->wasChanged('status') && in_array($model->status, ['approved', 'rejected', 'cancelled'], true)) $this->events()->leave($model, $model->status);
            if ($model instanceof AttendanceCorrection && $model->wasChanged('status') && in_array($model->status, ['approved', 'rejected'], true)) $this->events()->correction($model);
            if ($model instanceof Task && $model->wasChanged('assignee_id') && $model->assignee_id) $this->events()->task($model, 'assigned');
            if ($model instanceof Task && $model->wasChanged('status') && $model->status === 'completed') $this->events()->task($model, 'completed');
            if ($model instanceof SalaryAdvance && $model->wasChanged('disbursed_amount') && (float) $model->disbursed_amount > (float) ($model->getPrevious()['disbursed_amount'] ?? 0)) {
                $paid = (float) $model->disbursed_amount - (float) ($model->getPrevious()['disbursed_amount'] ?? 0);
                $this->events()->advancePaid($model, $paid);
                // The employee is told too (in the app, by e-mail / Telegram as they chose).
                \App\Services\Notifier::send($model->employee?->user, 'SalaryAdvance', $model->id, 'Your salary advance payment of Rs '.number_format($paid, 2).' was paid on '.now()->format('d M Y').'.', route('payroll.advances', [], false), auth()->id());
            }
            // A task changed by someone else (status, title, due date, priority): its assignee is told.
            if ($model instanceof Task && $model->assignee_id && ! $model->wasChanged('assignee_id') && auth()->id() && ($changes = $this->taskChanges($model))) {
                $model->loadMissing('assignee.user');
                if ($model->assignee?->user_id && $model->assignee->user_id !== auth()->id()) {
                    \App\Services\Notifier::send($model->assignee->user, 'TaskUpdated', $model->id, auth()->user()?->name." updated your task \"{$model->title}\": ".implode(', ', $changes).'.', route('work.my-tasks', ['task' => $model->id], false), auth()->id());
                }
            }
            if ($model instanceof EmployeePenalty && $model->wasChanged('status') && $model->status === 'waived') $this->events()->fine($model, 'removed');
        }, null, true);
    }

    /** What changed on a task, in words ("status: in progress → completed", "due date: 30 Oct"). */
    private function taskChanges(Task $task): array
    {
        $out = [];
        if ($task->wasChanged('status')) $out[] = 'status: '.str_replace('_', ' ', (string) ($task->getPrevious()['status'] ?? '')).' → '.str_replace('_', ' ', (string) $task->status);
        if ($task->wasChanged('title')) $out[] = 'new title';
        if ($task->wasChanged('due_on')) $out[] = 'due date: '.($task->due_on ? $task->due_on->format('d M Y') : 'none');
        if ($task->wasChanged('priority')) $out[] = 'priority: '.$task->priority;

        return $out;
    }

    private function events(): TelegramEvents
    {
        return app(TelegramEvents::class);
    }
}
