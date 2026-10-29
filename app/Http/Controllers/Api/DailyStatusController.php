<?php

namespace App\Http\Controllers\Api;

use App\Models\DailyWorkStatus;
use App\Services\AuditLogger;
use App\Services\WorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Today's daily status (plan / completed / blockers / notes) and history for the signed-in employee. */
class DailyStatusController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $month = $this->monthRange($request);
        $items = DailyWorkStatus::where('employee_id', $employee->id)
            ->when($month, fn ($q) => $q->whereDate('work_date', '>=', $month[0])->whereDate('work_date', '<=', $month[1]))
            ->latest('work_date')->limit($month ? 31 : 30)->get();
        // Today's status is always returned (for the form), whichever month the list shows.
        $today = DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', today())->first();
        $from = $month ? Carbon::parse($month[0]) : today()->startOfMonth();
        $to = $month ? Carbon::parse($month[1]) : today();

        return response()->json([
            'data' => $items->map(fn ($s) => [...$this->present($s), 'late' => $s->submitted_at && $s->submitted_at->toDateString() > $s->work_date->toDateString(), 'day' => $this->dayFigures($employee, $s->work_date->toDateString())])->values(),
            'today' => $today ? $this->present($today) : null,
            // The app's "Today" card: timing so far and the tasks timed today (the form can add them to Completed).
            'today_info' => $this->dayFigures($employee, today()->toDateString(), true),
            // When the automatic reminder comes (Work > Daily status > Who must write it), e.g. "6:00 PM".
            'reminder_at' => \App\Support\DailyStatusRule::reminderLabel(),
            // Month tiles and the "Missed" cards: days at work with no status written.
            'month_summary' => $this->monthSummary($employee, $from, $to->min(today())),
            'team' => $this->team($request, $employee),
            // Missed days that can still be written (reason first): the last 2 days at work with no status.
            'missed_writable' => app(WorkService::class)->missedStatusDays($employee),
        ]);
    }

    /** A missed day written later: the reason why it was not written that day, then the status. Its fine is removed. */
    public function missed(Request $request, WorkService $work): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate(['work_date' => ['required', 'date_format:Y-m-d'], 'late_reason' => ['required', 'string', 'min:5', 'max:1000'], 'summary' => ['required', 'string', 'min:5', 'max:4000'], 'plan' => ['nullable', 'string', 'max:4000'], 'blockers' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000']],
            ['late_reason.required' => 'First write why the status was not written that day.', 'late_reason.min' => 'First write why the status was not written that day (at least 5 characters).']);
        $status = $work->writeMissedStatus($employee, $data['work_date'], $data['late_reason'], $data, $this->apiUser($request));

        return response()->json(['data' => [...$this->present($status), 'late' => true]], 201);
    }

    /** Check-in, worked and break for a day, and (for today) the tasks with their timer time. */
    private function dayFigures(\App\Models\AttendanceEmployee $employee, string $date, bool $withTasks = false): array
    {
        $attendance = app(\App\Services\AttendanceService::class);
        $sessions = \App\Models\AttendanceSession::with('employee')->where('employee_id', $employee->id)->whereDate('work_date', $date)->orderBy('check_in')->get();
        $worked = $break = 0;
        foreach ($sessions as $session) {
            $f = rescue(fn () => $attendance->liveFigures($session), null, false);
            if (! $f) continue;
            $worked += (int) $f['worked'];
            $break += (int) $f['break'];
        }
        $entries = \App\Models\TaskTimeEntry::with('task:id,title,status')->where('employee_id', $employee->id)->whereDate('started_at', $date)->get();
        $seconds = fn ($e) => $e->status === 'running' ? (int) $e->started_at->diffInSeconds(now()) : (int) $e->duration_seconds;
        $tasks = $entries->groupBy('task_id')->map(fn ($g) => ['id' => $g->first()->task_id, 'title' => (string) ($g->first()->task?->title ?? 'Task'), 'status' => (string) ($g->first()->task?->status ?? ''), 'seconds' => (int) $g->sum($seconds)])->values();

        return ['check_in' => $sessions->first()?->check_in?->format('h:i A'), 'check_out' => $sessions->isNotEmpty() && $sessions->every(fn ($s) => $s->check_out) ? $sessions->last()->check_out->format('h:i A') : null,
            'worked_seconds' => $worked, 'break_seconds' => $break, 'task_count' => $tasks->count(), 'task_seconds' => (int) $tasks->sum('seconds'),
            ...($withTasks ? ['tasks' => $tasks->sortByDesc('seconds')->values()->all()] : [])];
    }

    /** Days at work in the month (up to today), how many have a status, written late, with blockers, and the missed dates. */
    private function monthSummary(\App\Models\AttendanceEmployee $employee, Carbon $from, Carbon $to): array
    {
        $worked = $from->lte($to) ? app(\App\Services\AttendanceService::class)->days($employee, $from, $to)
            ->filter(fn ($d) => $d['session'] !== null && $d['date'] < today()->toDateString())->pluck('date') : collect();
        $statuses = DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())->get();
        $written = $statuses->map(fn ($s) => $s->work_date->toDateString());

        // Days when it was not compulsory for this person (Who must write it) are not "missed".
        $missed = $worked->diff($written)->filter(fn ($d) => \App\Support\DailyStatusRule::required($employee, $d))->values();

        return ['days_at_work' => $worked->count(), 'submitted' => $statuses->count(), 'missed' => $missed->all(), 'required' => \App\Support\DailyStatusRule::required($employee),
            'late' => $statuses->filter(fn ($s) => $s->submitted_at && $s->submitted_at->toDateString() > $s->work_date->toDateString())->count(),
            'blockers' => $statuses->filter(fn ($s) => filled($s->blockers))->count()];
    }

    /** For a manager: their direct reports today — written or not, blocker or not. Empty for everyone else. */
    private function team(Request $request, \App\Models\AttendanceEmployee $employee): array
    {
        $reports = \App\Models\AttendanceEmployee::where('manager_id', $employee->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']);
        if ($reports->isEmpty()) return [];
        $statuses = DailyWorkStatus::whereIn('employee_id', $reports->pluck('id'))->whereDate('work_date', today())->get()->keyBy('employee_id');
        $in = \App\Models\AttendanceSession::whereIn('employee_id', $reports->pluck('id'))->whereDate('work_date', today())->pluck('employee_id')->flip();

        return $reports->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'checked_in' => isset($in[$r->id]), 'submitted' => $statuses->has($r->id), 'blocker' => filled($statuses->get($r->id)?->blockers) ? $statuses->get($r->id)->blockers : null])->values()->all();
    }

    /** POST /api/daily-status/remind/{employee}: a manager (or HR) reminds someone who has not written today's status. */
    public function remind(Request $request, \App\Models\AttendanceEmployee $employee, WorkService $work): JsonResponse
    {
        $user = $this->apiUser($request);
        $own = $user->employeeProfile;
        abort_unless($user->canAny(['hr.manage', 'employees.manage']) || ($own && $employee->manager_id === $own->id), 403);
        $sent = $work->remindDailyStatus(today()->toDateString(), [$employee->id], $user);

        return response()->json(['message' => $sent ? "Reminder sent to {$employee->name}." : 'Already reminded in the last hour.']);
    }

    public function store(Request $request, AuditLogger $audit, WorkService $work): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate(['summary' => ['required', 'string', 'min:5', 'max:4000'], 'yesterday' => ['nullable', 'string', 'max:4000'], 'plan' => ['nullable', 'string', 'max:4000'], 'blockers' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000'], 'pending' => ['nullable', 'string', 'max:4000'], 'tomorrow_plan' => ['nullable', 'string', 'max:4000'], 'work_date' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(2)->toDateString()]]);
        // Offline submissions carry the day they were written for (up to 2 days late).
        $day = isset($data['work_date']) ? Carbon::parse($data['work_date'])->toDateString() : today()->toDateString();
        unset($data['work_date']);

        return $this->once($request, function () use ($employee, $data, $day, $request, $audit, $work) {
            $status = DB::transaction(function () use ($employee, $data, $day, $request) {
                $status = DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', $day)->lockForUpdate()->first();
                $fields = [...$data, 'submitted_at' => now(), 'submitted_by' => $request->user()->id];
                if ($status) {
                    $status->update([...$fields, 'server_version' => (int) $status->server_version + 1]);

                    return $status;
                }

                return DailyWorkStatus::create([...$fields, 'employee_id' => $employee->id, 'work_date' => $day, 'server_version' => 1]);
        });
        $audit->record('work_status.submitted', $status, ['has_blockers' => (bool) $status->blockers]);
        $work->notifyBlocker($employee, $status);
        $work->notifyDailyStatus($employee, $status);

        return response()->json(['data' => $this->present($status)], 201);
        });
    }

    private function present(DailyWorkStatus $s): array
    {
        return ['id' => $s->id, 'work_date' => $s->work_date->toDateString(), 'yesterday' => $s->yesterday, 'plan' => $s->plan, 'summary' => $s->summary, 'blockers' => $s->blockers, 'notes' => $s->notes, 'pending' => $s->pending, 'tomorrow_plan' => $s->tomorrow_plan, 'submitted_at' => $s->submitted_at?->toIso8601String(), 'late_reason' => $s->late_reason, 'server_version' => (int) $s->server_version];
    }
}
