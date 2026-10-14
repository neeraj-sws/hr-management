<?php

namespace App\Console\Commands;

use App\Models\AttendanceSession;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Checks task timer entries against attendance and fixes what is clearly wrong,
 * so a task's actual (logged) hours stay correct:
 *  - a timer still running on a completed task, or after the employee checked
 *    out, or from an earlier day → stopped at completion / check-out / last
 *    activity of that day;
 *  - more than one timer running for one person → only the newest keeps running;
 *  - a stored duration that does not match its start and end → recalculated;
 *  - an end before the start → duration 0.
 * Overlapping entries are only reported (a person cannot work on two tasks at once,
 * but which one is right needs a human). Without --fix it only reports.
 */
class ReconcileTaskHours extends Command
{
    protected $signature = 'work:reconcile-task-hours {--fix : apply the corrections} {--days=30 : how many days back to check}';
    protected $description = 'Check task timer entries against attendance and fix stale timers and wrong durations';

    public function handle(AuditLogger $audit): int
    {
        $fix = (bool) $this->option('fix');
        $since = now()->subDays(max(1, (int) $this->option('days')))->startOfDay();
        $rows = [];
        $fixed = 0;

        // 1. More than one running timer per person: keep the newest.
        TaskTimeEntry::where('status', 'running')->get()->groupBy('employee_id')->each(function ($entries) use (&$rows, &$fixed, $fix) {
            foreach ($entries->sortByDesc('started_at')->slice(1) as $entry) {
                $end = $entries->sortByDesc('started_at')->first()->started_at;
                $rows[] = [$entry->id, $entry->task_id, 'second running timer', $entry->started_at->format('d M H:i'), 'stop at '.$end->format('d M H:i')];
                if ($fix) { $this->stop($entry, $end); $fixed++; }
            }
        });

        // 2. Running timers that should have stopped.
        foreach (TaskTimeEntry::with('task')->where('status', 'running')->get() as $entry) {
            $end = null;
            $why = null;
            if ($entry->task?->status === 'completed' && $entry->task->completed_at) {
                [$end, $why] = [$entry->task->completed_at, 'task already completed'];
            } else {
                $session = AttendanceSession::where('employee_id', $entry->employee_id)->whereDate('work_date', $entry->started_at->toDateString())->latest('id')->first();
                if ($session?->check_out && $session->check_out->gt($entry->started_at)) {
                    [$end, $why] = [$session->check_out, 'employee checked out'];
                } elseif ($entry->started_at->lt(today())) {
                    $last = collect([$session?->last_sync_at, $session?->screen_reported_at])->filter(fn ($t) => $t && $t->gt($entry->started_at))->max();
                    [$end, $why] = [$last ?? $entry->started_at->copy()->endOfDay()->min($entry->started_at->copy()->addHours(8)), 'left running from an earlier day'];
                }
            }
            if (! $end) continue;
            $end = Carbon::parse($end)->max($entry->started_at);
            $rows[] = [$entry->id, $entry->task_id, $why, $entry->started_at->format('d M H:i'), 'stop at '.$end->format('d M H:i')];
            if ($fix) { $this->stop($entry, $end); $fixed++; }
        }

        // 3. Stopped entries whose duration does not match start/end.
        TaskTimeEntry::where('status', '!=', 'running')->where('started_at', '>=', $since)->whereNotNull('ended_at')->chunkById(500, function ($entries) use (&$rows, &$fixed, $fix) {
            foreach ($entries as $entry) {
                $expected = $entry->ended_at->lt($entry->started_at) ? 0 : (int) $entry->started_at->diffInSeconds($entry->ended_at);
                if ((int) $entry->duration_seconds === $expected) continue;
                $rows[] = [$entry->id, $entry->task_id, 'duration mismatch', (int) $entry->duration_seconds.' s', $expected.' s'];
                if ($fix) { $entry->forceFill(['duration_seconds' => $expected])->saveQuietly(); $fixed++; }
            }
        });

        // 4. Overlaps (report only).
        $overlaps = 0;
        TaskTimeEntry::where('started_at', '>=', $since)->whereNotNull('ended_at')->orderBy('employee_id')->orderBy('started_at')->get()->groupBy('employee_id')->each(function ($entries) use (&$rows, &$overlaps) {
            $previous = null;
            foreach ($entries as $entry) {
                if ($previous && $entry->started_at->lt($previous->ended_at)) {
                    $rows[] = [$entry->id, $entry->task_id, 'overlaps entry #'.$previous->id, $entry->started_at->format('d M H:i'), 'check manually'];
                    $overlaps++;
                }
                $previous = $entry->ended_at && (! $previous || $entry->ended_at->gt($previous->ended_at)) ? $entry : $previous;
            }
        });

        if ($rows) $this->table(['Entry', 'Task', 'Problem', 'Found', $fix ? 'Fixed' : 'Proposed'], $rows);
        $this->info(count($rows).' issue(s) found'.($fix ? ", {$fixed} fixed" : ' (run with --fix to correct them)').($overlaps ? ", {$overlaps} overlap(s) need a manual check." : '.'));
        if ($fix && $fixed) $audit->record('task.hours_reconciled', null, ['fixed' => $fixed, 'overlaps' => $overlaps]);

        return self::SUCCESS;
    }

    private function stop(TaskTimeEntry $entry, Carbon $end): void
    {
        $entry->forceFill(['ended_at' => $end, 'duration_seconds' => (int) $entry->started_at->diffInSeconds($end), 'status' => 'stopped'])->saveQuietly();
        Task::whereKey($entry->task_id)->increment('server_version');
    }
}
