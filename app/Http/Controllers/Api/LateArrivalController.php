<?php

namespace App\Http\Controllers\Api;

use App\Models\LateArrivalRequest;
use App\Services\LateArrivalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app's Late arrival screen: tell in advance (expected check-in + reason), my requests, cancel. */
class LateArrivalController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $month = $this->monthRange($request);

        return response()->json(['data' => LateArrivalRequest::where('employee_id', $employee->id)
            ->when($month, fn ($q) => $q->whereDate('work_date', '>=', $month[0])->whereDate('work_date', '<=', $month[1]))
            ->latest('work_date')->latest('id')->limit(100)->get()->map(fn ($r) => $this->present($r))->values()]);
    }

    public function store(Request $request, LateArrivalService $late): JsonResponse
    {
        $data = $request->validate(['work_date' => ['required', 'date'], 'expected_at' => ['required', 'date_format:H:i'], 'reason' => ['required', 'string', 'min:10', 'max:2000']],
            ['reason.min' => 'Give a valid reason (at least 10 characters).']);

        return $this->once($request, fn () => response()->json(['data' => $this->present($late->request($this->apiEmployee($request), $data['work_date'], $data['expected_at'], $data['reason']))], 201));
    }

    public function cancel(Request $request, LateArrivalRequest $lateArrival, LateArrivalService $late): JsonResponse
    {
        return response()->json(['data' => $this->present($late->cancel($lateArrival, $this->apiEmployee($request)))]);
    }

    private function present(LateArrivalRequest $r): array
    {
        return ['id' => $r->id, 'work_date' => $r->work_date->toDateString(), 'expected_at' => $r->expected_at, 'reason' => $r->reason, 'status' => $r->status, 'decision_note' => $r->decision_note];
    }
}
