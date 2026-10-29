<?php

namespace App\Http\Controllers;

use App\Models\PayslipRequest;
use App\Models\User;
use App\Services\PayslipRequestService;
use Illuminate\Http\Request;

/** Opens a requested payslip: on the website (signed in), or from the app through a short-lived signed link. */
class PayslipRequestController extends Controller
{
    public function show(Request $request, PayslipRequest $payslipRequest, PayslipRequestService $service): mixed
    {
        abort_unless(PayslipRequestService::canOpen($request->user(), $payslipRequest->load('employee')), 403);

        return $service->document($payslipRequest);
    }

    public function signed(Request $request, PayslipRequest $payslipRequest, PayslipRequestService $service): mixed
    {
        $user = User::where('is_active', true)->find((int) $request->query('user'));
        abort_unless(PayslipRequestService::canOpen($user, $payslipRequest->load('employee')), 403);

        return $service->document($payslipRequest);
    }
}
