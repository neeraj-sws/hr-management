<?php

namespace App\Http\Controllers;

use App\Models\BillingEstimate;
use App\Models\BillingInvoice;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Printable GST tax invoice / estimate (the browser's "Save as PDF" makes the PDF). */
class BillingPrintController extends Controller
{
    public function invoice(Request $request, BillingInvoice $invoice, BillingService $billing): View
    {
        abort_unless($request->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $invoice->load(['customer', 'items.tax', 'payments', 'creditNotes', 'project']);

        return view('billing.print', ['doc' => $invoice, 'kind' => 'invoice', 'title' => 'Tax Invoice', 'number' => $invoice->number(), 'company' => $billing->company(), 'taxes' => $billing->taxSplit($invoice), 'interState' => $billing->isInterState($invoice->place_of_supply ?: $invoice->customer?->billing_state)]);
    }

    /** A client's own (sent) invoice from the client portal. */
    public function clientInvoice(Request $request, BillingInvoice $invoice, BillingService $billing): View
    {
        $user = $request->user();
        abort_unless($user->client_id && $invoice->status !== 'Draft' && $user->client?->billing_customer_id && $invoice->customer_id === $user->client->billing_customer_id, 403);
        $invoice->load(['customer', 'items.tax', 'payments', 'creditNotes', 'project']);

        return view('billing.print', ['doc' => $invoice, 'kind' => 'invoice', 'title' => 'Tax Invoice', 'number' => $invoice->number(), 'company' => $billing->company(), 'taxes' => $billing->taxSplit($invoice), 'interState' => $billing->isInterState($invoice->place_of_supply ?: $invoice->customer?->billing_state)]);
    }

    public function estimate(Request $request, BillingEstimate $estimate, BillingService $billing): View
    {
        abort_unless($request->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $estimate->load(['customer', 'items.tax']);

        return view('billing.print', ['doc' => $estimate, 'kind' => 'estimate', 'title' => 'Estimate', 'number' => $estimate->number(), 'company' => $billing->company(), 'taxes' => $billing->taxSplit($estimate), 'interState' => $billing->isInterState($estimate->place_of_supply ?: $estimate->customer?->billing_state)]);
    }
}
