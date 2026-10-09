<?php

namespace App\Http\Controllers\Api;

use App\Models\ExpenseClaim;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $claims = ExpenseClaim::where('employee_id', $employee->id)->latest('spent_on')->latest('id')->paginate(30)->through(fn ($c) => $this->present($c));

        return response()->json([...$claims->toArray(), 'categories' => ExpenseClaim::CATEGORIES]);
    }

    /** multipart/form-data: category, spent_on, amount, description, receipt (optional file). */
    public function store(Request $request, ExpenseService $expenses): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(ExpenseClaim::CATEGORIES)],
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'receipt' => ExpenseService::RECEIPT_RULES,
        ]);
        $claim = $expenses->submit($this->apiEmployee($request), $data, $request->file('receipt'));

        return response()->json(['data' => $this->present($claim)], 201);
    }

    public function cancel(Request $request, ExpenseClaim $claim, ExpenseService $expenses): JsonResponse
    {
        return response()->json(['data' => $this->present($expenses->cancel($claim, $this->apiEmployee($request)))]);
    }

    private function present(ExpenseClaim $c): array
    {
        return [
            'id' => $c->id, 'category' => $c->category, 'spent_on' => $c->spent_on->toDateString(), 'amount' => (float) $c->amount,
            'description' => $c->description, 'has_receipt' => (bool) $c->receipt_path, 'status' => $c->displayStatus(), 'decision_note' => $c->decision_note,
        ];
    }
}
