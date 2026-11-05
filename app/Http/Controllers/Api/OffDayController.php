<?php

namespace App\Http\Controllers\Api;

use App\Models\OffDayRequest;
use App\Services\OffDayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app's Off day requests screen: my requests, the days I can ask for, ask and cancel. */
class OffDayController extends ApiController
{
    public function index(Request $request, OffDayService $offs): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $asked = OffDayRequest::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->get()->map(fn ($r) => $r->off_date->toDateString())->all();

        return response()->json([
            'data' => OffDayRequest::where('employee_id', $employee->id)->latest('off_date')->latest('id')->limit(100)->get()->map(fn ($r) => $this->present($r))->values(),
            'needs_requests' => $offs->needsRequests($employee),
            'dates' => array_values(array_diff($offs->requestableDates($employee), $asked)),
        ]);
    }

    public function store(Request $request, OffDayService $offs): JsonResponse
    {
        $data = $request->validate(['off_date' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->once($request, fn () => response()->json(['data' => $this->present($offs->request($this->apiEmployee($request), $data['off_date'], $data['note'] ?? null))], 201));
    }

    public function cancel(Request $request, OffDayRequest $offDay, OffDayService $offs): JsonResponse
    {
        return response()->json(['data' => $this->present($offs->cancel($offDay, $this->apiEmployee($request)))]);
    }

    private function present(OffDayRequest $r): array
    {
        return ['id' => $r->id, 'off_date' => $r->off_date->toDateString(), 'note' => $r->note, 'status' => $r->status, 'decision_note' => $r->decision_note];
    }
}
