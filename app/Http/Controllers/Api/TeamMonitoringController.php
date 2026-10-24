<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceEmployee;
use App\Services\TeamMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/monitoring/team?date=&status=&department_id=&manager_id=&q=   the list (managers: their team only)
 * GET /api/monitoring/team/{employee}?date=                              one person's day, timeline and screenshots
 * The scope is checked on every call: changing the id or the parameters never reaches someone outside the team.
 */
class TeamMonitoringController extends ApiController
{
    public function __construct(private readonly TeamMonitoringService $monitoring) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $data = $request->validate(['date' => ['nullable', 'date', 'before_or_equal:today'], 'status' => ['nullable', 'in:working,break,idle,meeting,checked_out,not_in'], 'department_id' => ['nullable', 'integer'], 'manager_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:80']]);
        $scope = $this->monitoring->scopeIds($user);
        $date = $data['date'] ?? today()->toDateString();
        $term = trim((string) ($data['q'] ?? ''));
        $people = AttendanceEmployee::with('department')->where('is_active', true)
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope->all() ?: [0]))
            // Department / manager filters are for HR only; a manager's list never widens.
            ->when($scope === null && ! empty($data['department_id']), fn ($q) => $q->where('department_id', $data['department_id']))
            ->when($scope === null && ! empty($data['manager_id']), fn ($q) => $q->where('manager_id', $data['manager_id']))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('employee_code', 'like', "%{$term}%")))
            ->inCodeOrder()->limit(300)->get();
        app(\App\Services\AuditLogger::class)->record('monitoring.team_viewed', null, ['date' => $date, 'via' => 'api']);
        $rows = collect($this->monitoring->rows($people, $date))
            ->when(! empty($data['status']), fn ($c) => $c->where('status', $data['status']))
            ->map(fn ($r) => $this->present($r))->values();

        return response()->json(['date' => $date, 'data' => $rows]);
    }

    public function show(Request $request, AttendanceEmployee $employee): JsonResponse
    {
        $user = $this->apiUser($request);
        $this->monitoring->assertCanSee($user, $employee);
        $data = $request->validate(['date' => ['nullable', 'date', 'before_or_equal:today']]);
        $date = $data['date'] ?? today()->toDateString();
        $this->monitoring->logView($user, $employee, 'detail_viewed', ['date' => $date, 'via' => 'api']);
        $row = $this->monitoring->rows(collect([$employee]), $date)[$employee->id];

        return response()->json([
            ...$this->present($row),
            'capture_detail' => $this->monitoring->captureState($employee),
            'screenshots' => $this->monitoring->screenshots($employee, $date)->flatten()->map(fn ($s) => ['id' => $s->id, 'captured_at' => $s->captured_at->toIso8601String()])->values(),
            'week' => $this->monitoring->week($employee, $date),
        ]);
    }

    private function present(array $r): array
    {
        $e = $r['employee'];

        return ['employee' => ['id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code, 'department' => $e->department?->name],
            'status' => $r['status'], 'status_label' => $r['status_label'], 'check_in' => $r['check_in']?->toIso8601String(), 'check_out' => $r['check_out']?->toIso8601String(),
            'worked_seconds' => $r['worked'], 'break_seconds' => $r['break'], 'idle_seconds' => $r['idle'], 'meeting_seconds' => $r['meeting'],
            'screen_active_seconds' => $r['active'], 'untracked_seconds' => $r['untracked'], 'timeline' => $r['timeline'], 'capture' => $r['capture'], 'screenshots_today' => $r['screenshots']];
    }
}
