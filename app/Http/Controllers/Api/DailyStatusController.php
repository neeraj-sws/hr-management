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
        $items = DailyWorkStatus::where('employee_id', $employee->id)->latest('work_date')->limit(30)->get();

        return response()->json(['data' => $items->map(fn ($s) => $this->present($s))->values(), 'today' => optional($items->first(fn ($s) => $s->work_date->isToday()), fn ($s) => $this->present($s))]);
    }

    public function store(Request $request, AuditLogger $audit, WorkService $work): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate(['summary' => ['required', 'string', 'min:5', 'max:4000'], 'yesterday' => ['nullable', 'string', 'max:4000'], 'plan' => ['nullable', 'string', 'max:4000'], 'blockers' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000'], 'work_date' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(2)->toDateString()]]);
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

        return response()->json(['data' => $this->present($status)], 201);
        });
    }

    private function present(DailyWorkStatus $s): array
    {
        return ['id' => $s->id, 'work_date' => $s->work_date->toDateString(), 'yesterday' => $s->yesterday, 'plan' => $s->plan, 'summary' => $s->summary, 'blockers' => $s->blockers, 'notes' => $s->notes, 'submitted_at' => $s->submitted_at?->toIso8601String(), 'server_version' => (int) $s->server_version];
    }
}
