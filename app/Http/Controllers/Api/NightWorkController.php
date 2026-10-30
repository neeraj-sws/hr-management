<?php

namespace App\Http\Controllers\Api;

use App\Models\NightWorkRequest;
use App\Models\Task;
use App\Services\NightWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app's Night work screen: my requests (a month at a time), the tasks to choose from, ask and cancel. */
class NightWorkController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $month = $this->monthRange($request);

        return response()->json([
            'data' => NightWorkRequest::where('employee_id', $employee->id)
                ->when($month, fn ($q) => $q->whereDate('work_date', '>=', $month[0])->whereDate('work_date', '<=', $month[1]))
                ->latest('work_date')->latest('id')->limit(100)->get()->map(fn ($r) => $this->present($r))->values(),
            'tasks' => Task::with('project')->where('assignee_id', $employee->id)->where(fn ($q) => $q->where('status', '!=', 'completed')->orWhereDate('completed_at', '>=', today()->subDays(NightWorkService::DAYS_BACK)))
                ->latest('id')->limit(60)->get()->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'project' => $t->project?->name])->values(),
            'days_back' => NightWorkService::DAYS_BACK,
        ]);
    }

    public function store(Request $request, NightWorkService $nights): JsonResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i'],
            'task_ids' => ['nullable', 'array'], 'task_ids.*' => ['integer'], 'work_done' => ['required', 'string', 'min:10', 'max:3000'],
        ], ['work_done.min' => 'Write what you did (at least 10 characters).']);

        return $this->once($request, fn () => response()->json(['data' => $this->present($nights->request($this->apiEmployee($request), $data['work_date'], $data['starts_at'], $data['ends_at'], $data['task_ids'] ?? [], $data['work_done']))], 201));
    }

    public function cancel(Request $request, NightWorkRequest $nightWork, NightWorkService $nights): JsonResponse
    {
        return response()->json(['data' => $this->present($nights->cancel($nightWork, $this->apiEmployee($request)))]);
    }

    private function present(NightWorkRequest $r): array
    {
        return [
            'id' => $r->id, 'work_date' => $r->work_date->toDateString(), 'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at, 'minutes' => $r->minutes,
            'tasks' => $r->tasks()->map(fn ($t) => ['id' => $t->id, 'title' => $t->title])->values(), 'work_done' => $r->work_done,
            'status' => $r->status, 'decision_note' => $r->decision_note,
        ];
    }
}
