<?php

namespace App\Http\Controllers;

use App\Models\BillingCustomer;
use App\Models\BillingEstimate;
use App\Models\BillingInvoice;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Printable GST tax invoice / estimate (the browser's "Save as PDF" makes the PDF). */
class BillingPrintController extends Controller
{
    public function invoice(Request $request, BillingInvoice $invoice, BillingService $billing): View
    {
        abort_unless($request->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $invoice->load(['customer', 'items.tax', 'payments', 'creditNotes', 'project']);

        return view('billing.print', ['doc' => $invoice, 'kind' => 'invoice', 'title' => 'Tax Invoice', 'number' => $invoice->number(), 'company' => $billing->companyFor($invoice->company_id), 'taxes' => $billing->taxSplit($invoice), 'interState' => $billing->isInterState($invoice->place_of_supply ?: $invoice->customer?->billing_state)]);
    }

    /** A client's own (sent) invoice from the client portal. */
    public function clientInvoice(Request $request, BillingInvoice $invoice, BillingService $billing): View
    {
        $user = $request->user();
        abort_unless($user->client_id && $invoice->status !== 'Draft' && $user->client?->billing_customer_id && $invoice->customer_id === $user->client->billing_customer_id, 403);
        $invoice->load(['customer', 'items.tax', 'payments', 'creditNotes', 'project']);

        return view('billing.print', ['doc' => $invoice, 'kind' => 'invoice', 'title' => 'Tax Invoice', 'number' => $invoice->number(), 'company' => $billing->companyFor($invoice->company_id), 'taxes' => $billing->taxSplit($invoice), 'interState' => $billing->isInterState($invoice->place_of_supply ?: $invoice->customer?->billing_state)]);
    }

    public function estimate(Request $request, BillingEstimate $estimate, BillingService $billing): View
    {
        abort_unless($request->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $estimate->load(['customer', 'items.tax']);

        return view('billing.print', ['doc' => $estimate, 'kind' => 'estimate', 'title' => 'Estimate', 'number' => $estimate->number(), 'company' => $billing->companyFor($estimate->company_id), 'taxes' => $billing->taxSplit($estimate), 'interState' => $billing->isInterState($estimate->place_of_supply ?: $estimate->customer?->billing_state)]);
    }

    public function exportCustomers(Request $request): Response
    {
        abort_unless($request->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $rows = BillingCustomer::orderBy('name')->get();
        $csv = "Name,Email,Contact,Tax Number,Billing Address,Billing City,Billing State,Active\n";
        foreach ($rows as $c) {
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', [
                $c->name, $c->email, $c->contact, $c->tax_number,
                $c->billing_address, $c->billing_city, $c->billing_state, $c->is_active ? 'Yes' : 'No',
            ]))."\n";
        }
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="customers.csv"']);
    }

    public function exportInvoices(Request $request): Response
    {
        abort_unless($request->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $rows = BillingInvoice::with(['customer', 'payments'])->orderByDesc('issue_date')->get();
        $csv = "Invoice No,Customer,Issue Date,Due Date,Status,Total,Paid,Due\n";
        foreach ($rows as $inv) {
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', [
                $inv->number(), $inv->customer?->name, $inv->issue_date?->format('d M Y'),
                $inv->due_date?->format('d M Y'), $inv->status,
                number_format($inv->total(), 2), number_format($inv->paidAmount(), 2), number_format(max(0, $inv->due()), 2),
            ]))."\n";
        }
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="invoices.csv"']);
    }

    public function exportEstimates(Request $request): Response
    {
        abort_unless($request->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $rows = BillingEstimate::with('customer')->orderByDesc('issue_date')->get();
        $csv = "Estimate No,Customer,Issue Date,Expiry Date,Status,Total\n";
        foreach ($rows as $est) {
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', [
                $est->number(), $est->customer?->name, $est->issue_date?->format('d M Y'),
                $est->expiry_date?->format('d M Y'), $est->status, number_format($est->total(), 2),
            ]))."\n";
        }
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="estimates.csv"']);
    }
}
