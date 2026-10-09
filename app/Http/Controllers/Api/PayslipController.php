<?php

namespace App\Http\Controllers\Api;

use App\Models\Payslip;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayslipController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        return response()->json(Payslip::with(['item.run', 'item.payments'])->where('employee_id', $employee->id)->latest('issued_at')->paginate(24)->through(fn ($p) => $this->present($p, false)));
    }

    public function show(Request $request, Payslip $payslip, AuditLogger $audit): JsonResponse
    {
        $user = $this->apiUser($request);
        $payslip->load(['employee', 'item.run', 'item.payments']);
        abort_unless($payslip->employee?->user_id === $user->id || $user->canAny(['payroll.manage', 'payroll.approve']), 403);
        $audit->record('payroll.payslip_viewed', $payslip, ['reference' => $payslip->reference, 'channel' => 'api']);

        return response()->json(['data' => $this->present($payslip, true)]);
    }

    private function present(Payslip $p, bool $detail): array
    {
        $item = $p->item;
        $paid = (float) $item->payments->sum('amount');
        $data = ['id' => $p->id, 'reference' => $p->reference, 'issued_at' => $p->issued_at->toIso8601String(), 'period_start' => $item->run->period_starts_on->toDateString(), 'period_end' => $item->run->period_ends_on->toDateString(), 'gross' => (float) $item->gross_amount, 'net' => (float) $item->net_amount, 'paid' => round($paid, 2), 'balance' => round((float) $item->net_amount - $paid, 2)];
        if ($detail) {
            $data['breakdown'] = $item->calculation_breakdown;
            $data['payments'] = $item->payments->map(fn ($pay) => ['paid_on' => $pay->paid_on->toDateString(), 'amount' => (float) $pay->amount, 'reference' => $pay->reference])->values();
        }

        return $data;
    }
}
