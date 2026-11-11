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

        // ?scope=team: a manager sees their team's lines (read-only), optionally one person (?member=).
        $team = $timesheets->teamOf($employee);
        $teamView = $request->query('scope') === 'team' && $team->isNotEmpty();
        if ($teamView) {
            $people = $request->integer('member') ? $team->where('id', $request->integer('member')) : $team->concat([$employee]);
            $teamProjects = $timesheets->projectsOf($team->pluck('id')->push($employee->id)->all())->pluck('id');
            foreach ($teamProjects->diff($projects->pluck('id')) as $id) $timesheets->syncFromTimers(\App\Models\Project::find($id), $ym);
            $lines = TimesheetEntry::with(['project:id,name', 'employee:id,name', 'employee.teams:id,name'])->whereIn('project_id', $teamProjects)
                ->where(fn ($q) => $q->whereIn('employee_id', $people->pluck('id'))->orWhere(fn ($q) => $q->whereNull('employee_id')->whereNotNull('developer_name')))
                ->whereDate('work_date', '>=', $month[0])->whereDate('work_date', '<=', $month[1])
                ->latest('work_date')->orderByDesc('start_time')->latest('id')->limit(1000)->get()
                ->filter(fn ($l) => $timesheets->lineOfAny($l, $people))->take(300)->values();
        } else {
            $lines = TimesheetEntry::with('project:id,name')->where('employee_id', $employee->id)->whereIn('project_id', $projects->pluck('id'))
                ->whereDate('work_date', '>=', $month[0])->whereDate('work_date', '<=', $month[1])
                ->latest('work_date')->orderByDesc('start_time')->latest('id')->limit(300)->get();
        }

        return response()->json([
            'data' => $lines->map(fn ($l) => $this->present($l, $timesheets, $employee))->values(),
            'projects' => $projects->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'client' => $p->client?->name])->values(),
            'total_minutes' => (int) $lines->sum('minutes'),
            'statuses' => TimesheetEntry::STATUSES,
            'stages' => TimesheetEntry::STAGES,
            'scope' => $teamView ? 'team' : 'mine',
            'team' => $team->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'teams' => $m->teams->pluck('name')->values()])->values(),
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
            'description' => ['required', 'string', 'min:3', 'max:5000'], 'status' => ['nullable', 'string', 'max:40'], 'stage' => ['nullable', 'string', 'max:40'], 'remarks' => ['nullable', 'string', 'max:2000'],
        ], ['description.required' => 'Task / kaam kya kiya likho.']);
    }

    private function present(TimesheetEntry $l, TimesheetService $timesheets, $employee): array
    {
        return [
            'id' => $l->id, 'project_id' => $l->project_id, 'project' => $l->project?->name, 'work_date' => $l->work_date->toDateString(),
            'start_time' => $l->start_time ? substr($l->start_time, 0, 5) : null, 'end_time' => $l->end_time ? substr($l->end_time, 0, 5) : null,
            'minutes' => (int) $l->minutes, 'description' => (string) $l->description, 'status' => $l->status, 'stage' => $l->stage, 'remarks' => $l->remarks,
            'source' => $l->source, 'editable' => $timesheets->employeeCanEdit($employee, $l),
            'developer' => $l->employee_id === $employee->id ? null : $l->developer(),
            'team' => $l->relationLoaded('employee') && $l->employee ? $l->employee->teams->pluck('name')->join(', ') ?: null : null,
        ];
    }
}
