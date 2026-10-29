<?php

namespace App\Http\Controllers\Api;

use App\Models\PayslipRequest;
use App\Services\PayslipRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** The app's payslip requests: ask for a month, see the answer and open the payslip (short-lived signed link). */
class PayslipRequestController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $userId = $this->apiUser($request)->id;

        return response()->json(['data' => PayslipRequest::with('decider')->where('employee_id', $employee->id)->latest('id')->limit(50)->get()->map(fn ($r) => $this->present($r, $userId))->values()]);
    }

    public function store(Request $request, PayslipRequestService $service): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate(['month' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:1000']]);
        $created = $service->request($employee, $data['month'], $data['note'] ?? null, $this->apiUser($request));

        return response()->json(['data' => $this->present($created->load('decider'), $this->apiUser($request)->id)], 201);
    }

    private function present(PayslipRequest $r, int $userId): array
    {
        return ['id' => $r->id, 'month' => $r->month, 'month_label' => $r->monthLabel(), 'note' => $r->note, 'status' => $r->status, 'decision_note' => $r->decision_note,
            'decided_by' => $r->decider?->name, 'decided_at' => $r->decided_at?->toIso8601String(),
            'url' => $r->hasDocument() ? URL::temporarySignedRoute('files.payslip-request', now()->addMinutes(10), ['payslipRequest' => $r->id, 'user' => $userId]) : null];
    }
}
