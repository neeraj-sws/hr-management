<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PayslipController extends Controller
{
    public function show(Request $request, Payslip $payslip, AuditLogger $audit): View
    {
        $payslip->load(['employee.department','item.run','item.payments']);
        $user=$request->user();
        abort_unless($user->canAny(['payroll.manage','payroll.approve']) || $payslip->employee?->user_id===$user->id,403);
        $audit->record('payroll.payslip_viewed',$payslip,['reference'=>$payslip->reference]);

        return view('hr.payslip',compact('payslip'));
    }
}
