<?php

namespace App\Http\Controllers\Api;

use App\Models\OvertimeRequest;
use App\Services\PeopleOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OvertimeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        return response()->json(OvertimeRequest::where('employee_id', $employee->id)->latest('work_date')->paginate(30)->through(fn ($o) => $this->present($o)));
    }

    public function store(Request $request, PeopleOperationsService $people): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate(['work_date' => ['required', 'date', 'before_or_equal:today'], 'minutes' => ['required', 'integer', 'min:15', 'max:720'], 'reason' => ['required', 'string', 'max:1000']]);
        if (OvertimeRequest::where('employee_id', $employee->id)->whereDate('work_date', $data['work_date'])->whereIn('status', ['pending', 'approved'])->exists()) {
            throw ValidationException::withMessages(['work_date' => 'You already have a pending or approved overtime request for this date.']);
        }
        $overtime = $people->requestOvertime($employee, $data['work_date'], (int) $data['minutes'], $data['reason']);

        return response()->json(['data' => $this->present($overtime)], 201);
    }

    private function present(OvertimeRequest $o): array
    {
        return ['id' => $o->id, 'work_date' => $o->work_date->toDateString(), 'requested_minutes' => $o->requested_minutes, 'approved_minutes' => $o->approved_minutes, 'reason' => $o->reason, 'status' => $o->status, 'decision_note' => $o->decision_note];
    }
}
