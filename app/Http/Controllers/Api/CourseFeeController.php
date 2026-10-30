<?php

namespace App\Http\Controllers\Api;

use App\Models\TrainingEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A trainee's own courses in the app: the fee, discount, what is paid and still due, and each payment (receipt). */
class CourseFeeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        return response()->json(['data' => TrainingEnrollment::with('payments')->where('employee_id', $employee->id)->latest('id')->get()->map(fn (TrainingEnrollment $e) => [
            'id' => $e->id, 'course' => $e->course_name, 'status' => $e->status, 'status_label' => TrainingEnrollment::STATUSES[$e->status] ?? $e->status,
            'starts_on' => $e->starts_on?->toDateString(), 'ends_on' => $e->ends_on?->toDateString(),
            'fee' => (float) $e->total_fee, 'discount' => (float) $e->discount, 'payable' => $e->payable(), 'paid' => $e->paid(), 'due' => round(max(0, $e->payable() - $e->paid()), 2),
            'installments' => (int) $e->installments,
            'payments' => $e->payments->map(fn ($p) => ['date' => $p->paid_on ? \Illuminate\Support\Carbon::parse($p->paid_on)->toDateString() : null, 'amount' => (float) $p->amount, 'receipt' => $p->receipt_no, 'method' => $p->method, 'installment' => $p->installment_no])->values(),
        ])->values()]);
    }
}
