<?php

namespace App\Http\Controllers\Api;

use App\Models\TimesheetEntry;
use App\Services\TimesheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app's My Timesheet screen: my lines on the projects I work on (a month at a time), add, edit and remove my own. */
class TimesheetController extends ApiController
{
    public function index(Request $request, TimesheetService $timesheets): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $month = $this->monthRange($request) ?? [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()];
        $projects = $timesheets->employeeProjects($employee)->with('client:id,name')->orderByRaw('client_id IS NULL')->orderBy('name')->get(['id', 'name', 'client_id']);

        // Finished task-timer time on my projects becomes timesheet lines too (same as the website).
        $ym = substr($month[0], 0, 7);
        foreach ($projects as $project) $timesheets->syncFromTimers($project, $ym);

        $lines = TimesheetEntry::with('project:id,name')->where('employee_id', $employee->id)->whereIn('project_id', $projects->pluck('id'))
            ->whereDate('work_date', '>=', $month[0])->whereDate('work_date', '<=', $month[1])
            ->latest('work_date')->orderByDesc('start_time')->latest('id')->limit(300)->get();

        return response()->json([
            'data' => $lines->map(fn ($l) => $this->present($l, $timesheets, $employee))->values(),
            'projects' => $projects->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'client' => $p->client?->name])->values(),
            'total_minutes' => (int) $lines->sum('minutes'),
            'statuses' => ['Done', 'In progress', 'Not done', 'Local', 'React', 'Live'],
        ]);
    }

    public function store(Request $request, TimesheetService $timesheets): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $this->validated($request);

        return $this->once($request, fn () => response()->json(['data' => $this->present($timesheets->saveEmployeeLine($employee, $data, null, $request->user()->id), $timesheets, $employee)], 201));
    }

    public function update(Request $request, TimesheetEntry $entry, TimesheetService $timesheets): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        abort_unless($entry->employee_id === $employee->id, 404);

        return response()->json(['data' => $this->present($timesheets->saveEmployeeLine($employee, $this->validated($request), $entry, $request->user()->id), $timesheets, $employee)]);
    }

    public function destroy(Request $request, TimesheetEntry $entry, TimesheetService $timesheets): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        abort_unless($entry->employee_id === $employee->id, 404);
        $timesheets->deleteEmployeeLine($employee, $entry);

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'integer'], 'work_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i'], 'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'description' => ['required', 'string', 'min:3', 'max:5000'], 'status' => ['nullable', 'string', 'max:40'], 'remarks' => ['nullable', 'string', 'max:2000'],
        ], ['description.required' => 'Task / kaam kya kiya likho.']);
    }

    private function present(TimesheetEntry $l, TimesheetService $timesheets, $employee): array
    {
        return [
            'id' => $l->id, 'project_id' => $l->project_id, 'project' => $l->project?->name, 'work_date' => $l->work_date->toDateString(),
            'start_time' => $l->start_time ? substr($l->start_time, 0, 5) : null, 'end_time' => $l->end_time ? substr($l->end_time, 0, 5) : null,
            'minutes' => (int) $l->minutes, 'description' => (string) $l->description, 'status' => $l->status, 'remarks' => $l->remarks,
            'source' => $l->source, 'editable' => $timesheets->employeeCanEdit($employee, $l),
        ];
    }
}
