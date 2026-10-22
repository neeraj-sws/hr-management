<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceCorrection;
use App\Services\AttendanceCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorrectionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        $month = $this->monthRange($request);

        return response()->json(AttendanceCorrection::where('employee_id', $employee->id)
            ->when($month, fn ($q) => $q->whereDate('work_date', '>=', $month[0])->whereDate('work_date', '<=', $month[1]))
            ->latest('id')->paginate($month ? 100 : 30)->through(fn ($c) => $this->present($c)));
    }

    public function store(Request $request, AttendanceCorrectionService $corrections): JsonResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $correction = $corrections->request($this->apiEmployee($request), $data['work_date'], $data['check_in'] ?? null, $data['check_out'] ?? null, $data['reason'], isset($data['break_minutes']) ? (int) $data['break_minutes'] : null);

        return response()->json(['data' => $this->present($correction)], 201);
    }

    public function cancel(Request $request, AttendanceCorrection $correction, AttendanceCorrectionService $corrections): JsonResponse
    {
        return response()->json(['data' => $this->present($corrections->cancel($correction, $this->apiEmployee($request)))]);
    }

    private function present(AttendanceCorrection $c): array
    {
        return [
            'id' => $c->id, 'work_date' => $c->work_date->toDateString(),
            'check_in' => $c->requested_check_in?->toIso8601String(), 'check_out' => $c->requested_check_out?->toIso8601String(),
            'break_minutes' => $c->requested_break_minutes, 'reason' => $c->reason, 'status' => $c->status, 'decision_note' => $c->decision_note,
        ];
    }
}
