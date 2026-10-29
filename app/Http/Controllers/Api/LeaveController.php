<?php

namespace App\Http\Controllers\Api;

use App\Models\ApprovalRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\HrWorkflowService;
use App\Services\LeaveAccrualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveController extends ApiController
{
    public function types(Request $request): JsonResponse
    {
        $this->apiUser($request);

        return response()->json(['data' => LeaveType::where('is_active', true)->orderBy('name')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'paid' => $t->paid, 'allow_advance' => $t->allow_advance, 'annual_allowance' => (float) $t->annual_allowance, 'monthly_accrual' => (float) $t->monthly_accrual])]);
    }

    public function balances(Request $request, LeaveAccrualService $accrual): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? \App\Support\LeaveYear::current());

        return response()->json(['data' => LeaveType::where('is_active', true)->orderBy('name')->get()->map(fn ($t) => ['leave_type' => ['id' => $t->id, 'name' => $t->name, 'paid' => $t->paid], 'year' => $year, ...$accrual->balance($employee, $t, $year)])]);
    }

    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        $month = $this->monthRange($request);
        // ?date= one day (requests covering it); ?status= e.g. all pending requests, whatever their month.
        $day = $this->dayFilter($request);
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected', 'cancelled'], true) ? $request->query('status') : null;
        if ($day) $month = [$day, $day];

        return response()->json(LeaveRequest::with('leaveType')->where('employee_id', $employee->id)
            ->when($month, fn ($q) => $q->whereDate('starts_on', '<=', $month[1])->whereDate('ends_on', '>=', $month[0]))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('starts_on')->paginate($month || $status ? 100 : 30)->through(fn ($l) => $this->present($l)));
    }

    public function store(Request $request, HrWorkflowService $workflow): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate(['leave_type_id' => ['required', 'exists:leave_types,id'], 'duration' => ['nullable', 'in:full,half,short'], 'starts_on' => ['required', 'date', 'after_or_equal:'.today()->subMonthNoOverflow()->startOfMonth()->toDateString()], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'reason' => ['required', 'string', 'max:2000']], ['starts_on.after_or_equal' => 'Leave can be asked for back to '.today()->subMonthNoOverflow()->startOfMonth()->format('d M Y').' at the earliest; for older days ask HR.']);
        return $this->once($request, function () use ($workflow, $employee, $data) {
            $leave = $workflow->requestLeave($employee, ['leave_type_id' => $data['leave_type_id'], 'duration' => $data['duration'] ?? 'full', 'starts_on' => Carbon::parse($data['starts_on']), 'ends_on' => Carbon::parse($data['ends_on']), 'reason' => $data['reason']]);

            return response()->json(['data' => $this->present($leave->load('leaveType'))], 201);
        });
    }

    public function cancel(Request $request, LeaveRequest $leave, AuditLogger $audit): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        abort_unless($leave->employee_id === $employee->id, 403);
        DB::transaction(function () use ($leave, $audit) {
            $leave = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if ($leave->status !== 'pending') throw ValidationException::withMessages(['leave' => 'Only pending requests can be cancelled.']);
            $leave->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_note' => 'Cancelled by employee']);
            ApprovalRequest::where('approvable_type', LeaveRequest::class)->where('approvable_id', $leave->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
            $audit->record('leave.cancelled', $leave);
        });

        return response()->json(['data' => $this->present($leave->fresh('leaveType'))]);
    }

    /** Own pending request: another leave type and / or reason (the approver is told). */
    public function update(Request $request, LeaveRequest $leave, HrWorkflowService $workflow): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate(['leave_type_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $leave = $workflow->changeLeaveType($leave, $employee, (int) $data['leave_type_id'], $data['reason'] ?? null, $this->apiUser($request)->id);

        return response()->json(['data' => $this->present($leave)]);
    }

    private function present(LeaveRequest $leave): array
    {
        return ['id' => $leave->id, 'leave_type' => $leave->leaveType?->name, 'leave_type_id' => $leave->leave_type_id, 'duration' => $leave->duration, 'starts_on' => $leave->starts_on->toDateString(), 'ends_on' => $leave->ends_on->toDateString(), 'days' => (float) $leave->days, 'reason' => $leave->reason, 'status' => $leave->status, 'decision_note' => $leave->decision_note, 'decided_at' => $leave->decided_at?->toIso8601String(), 'backdated' => $leave->isBackdated()];
    }
}
