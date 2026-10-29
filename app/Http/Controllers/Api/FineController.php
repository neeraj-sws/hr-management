<?php

namespace App\Http\Controllers\Api;

use App\Models\EmployeePenalty;
use App\Services\PeopleOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The employee's own fines in the mobile app: why each was made, their reason for it and HR's decision
 * (the same as Compliance > Penalties on the website). A reason accepted by HR / admin removes that day's fine.
 */
class FineController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $month = $this->monthRange($request);

        return response()->json(EmployeePenalty::with(['appeal.decider', 'decider'])->where('employee_id', $employee->id)
            ->when($month, fn ($q) => $q->whereDate('effective_on', '>=', $month[0])->whereDate('effective_on', '<=', $month[1]))
            ->latest('effective_on')->latest('id')->paginate($month ? 100 : 30)->through(fn ($p) => $this->present($p)));
    }

    public function reason(Request $request, EmployeePenalty $penalty, PeopleOperationsService $people): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], ['reason.min' => 'Write the reason (at least 5 characters).']);
        $people->appealPenalty($penalty->load('employee'), $this->apiUser($request), $data['reason']);

        return response()->json(['data' => $this->present($penalty->fresh(['appeal.decider', 'decider']))], 201);
    }

    private function present(EmployeePenalty $p): array
    {
        $a = $p->appeal;

        return [
            'id' => $p->id, 'date' => $p->effective_on->toDateString(), 'amount' => (float) $p->amount, 'reason' => $p->reason, 'status' => $p->status,
            'late_fine' => $p->isLateFine(), 'can_give_reason' => $p->canAppeal(),
            // Why it was removed / rejected (Waive, Reject, Reduce): who and when.
            'decision_note' => $p->decision_note, 'decided_by' => $p->decider?->name, 'decided_at' => $p->decided_at?->toIso8601String(),
            'my_reason' => $a ? [
                'reason' => $a->reason, 'sent_at' => $a->created_at?->toIso8601String(), 'status' => $a->status,
                'decided_by' => $a->decider?->name, 'decided_at' => $a->decided_at?->toIso8601String(), 'note' => $a->decision_note,
                'paid_back_next_payroll' => (bool) $a->refund_adjustment_id,
            ] : null,
        ];
    }
}
