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

    /** A receipt for one payment received against an invoice. */
    public function receipt(Request $request, \App\Models\BillingInvoicePayment $payment, BillingService $billing): View
    {
        abort_unless($request->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $invoice = $payment->invoice()->with(['customer', 'items.tax', 'payments', 'creditNotes'])->firstOrFail();

        return view('billing.receipt', ['payment' => $payment, 'invoice' => $invoice, 'company' => $billing->company()]);
    }

    /** The customer's copy from the shared link (signed URL, no sign-in). Drafts are not shown. */
    public function publicInvoice(BillingInvoice $invoice, BillingService $billing): View
    {
        abort_if($invoice->status === 'Draft', 404);
        $invoice->load(['customer', 'items.tax', 'payments.account', 'creditNotes']);

        return view('billing.public-invoice', ['invoice' => $invoice, 'company' => $billing->company(),
            'selfUrl' => app(\App\Services\InvoiceActions::class)->publicUrl($invoice),
            'downloadUrl' => \Illuminate\Support\Facades\URL::signedRoute('billing.invoices.public.download', ['invoice' => $invoice->id])]);
    }

    /** "Download" on the customer's page: the printable invoice, opening the print / save-as-PDF dialog. */
    public function publicInvoiceDownload(BillingInvoice $invoice, BillingService $billing): View
    {
        abort_if($invoice->status === 'Draft', 404);
        $invoice->load(['customer', 'items.tax', 'payments', 'creditNotes', 'project']);

        return view('billing.print', ['doc' => $invoice, 'kind' => 'invoice', 'title' => 'Invoice', 'number' => $invoice->number(), 'autoPrint' => true, 'company' => $billing->company(), 'taxes' => $billing->taxSplit($invoice), 'interState' => $billing->isInterState($invoice->place_of_supply ?: $invoice->customer?->billing_state)]);
    }

    /**
     * A customer's account statement for a period: invoices (debit), payments and credit notes (credit),
     * opening balance from everything before the period and a running balance. Drafts are left out.
     */
    public function statement(Request $request, \App\Models\BillingCustomer $customer, BillingService $billing): View
    {
        abort_unless($request->user()->canAny(['hr.manage', 'payroll.manage']), 403);

        return view('billing.statement', self::statementData($customer, $request->query('from'), $request->query('to'), $billing));
    }

    /** The customer's statement from the e-mailed link (signed URL, no sign-in, fixed period). */
    public function publicStatement(Request $request, \App\Models\BillingCustomer $customer, BillingService $billing): View
    {
        return view('billing.statement', self::statementData($customer, $request->query('from'), $request->query('to'), $billing) + ['public' => true]);
    }

    /** Statement lines for a period: opening balance, invoices (debit), payments / credit notes (credit), closing. */
    public static function statementData(\App\Models\BillingCustomer $customer, $from, $to, BillingService $billing): array
    {
        $valid = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
        $from = $valid($from) ? $from : today()->subMonths(6)->startOfMonth()->toDateString();
        $to = $valid($to) ? $to : today()->toDateString();
        if ($to < $from) [$from, $to] = [$to, $from];

        $invoices = BillingInvoice::with(['items.tax', 'payments', 'creditNotes'])->where('customer_id', $customer->id)->where('status', '!=', 'Draft')->get();
        $entries = collect();
        foreach ($invoices as $inv) {
            $entries->push(['date' => $inv->issue_date->toDateString(), 'particulars' => 'Invoice '.$inv->number(), 'reference' => (string) $inv->ref_number, 'debit' => $inv->total(), 'credit' => 0.0, 'order' => 0]);
            foreach ($inv->payments as $p) {
                $entries->push(['date' => $p->date->toDateString(), 'particulars' => 'Payment received · '.$inv->number(), 'reference' => trim(($p->method ?: '').' '.($p->reference ?: '')), 'debit' => 0.0, 'credit' => (float) $p->amount, 'order' => 1]);
            }
            foreach ($inv->creditNotes as $cn) {
                $entries->push(['date' => \Illuminate\Support\Carbon::parse($cn->date)->toDateString(), 'particulars' => 'Credit note · '.$inv->number(), 'reference' => (string) $cn->description, 'debit' => 0.0, 'credit' => (float) $cn->amount, 'order' => 2]);
            }
        }
        $entries = $entries->sortBy([['date', 'asc'], ['order', 'asc']])->values();
        $before = $entries->filter(fn ($e) => $e['date'] < $from);
        $lines = $entries->filter(fn ($e) => $e['date'] >= $from && $e['date'] <= $to)->values();
        // Dues carried over from the CMS (periods whose invoices are not in HRMS) open the statement.
        $opening = round((float) $customer->opening_balance + $before->sum('debit') - $before->sum('credit'), 2);
        $invoiced = round($lines->sum('debit'), 2);
        $received = round($lines->sum('credit'), 2);

        return ['customer' => $customer, 'company' => $billing->company(), 'from' => $from, 'to' => $to,
            'lines' => $lines, 'opening' => $opening, 'invoiced' => $invoiced, 'received' => $received, 'closing' => round($opening + $invoiced - $received, 2)];
    }

    /** The customer's copy of an estimate from the shared link (signed URL, no sign-in). Drafts are not shown. */
    public function publicEstimate(BillingEstimate $estimate, BillingService $billing): View
    {
        abort_if($estimate->status === 'Draft', 404);
        $estimate->load(['customer', 'items.tax']);

        return view('billing.print', ['doc' => $estimate, 'kind' => 'estimate', 'title' => 'Estimate', 'number' => $estimate->number(), 'company' => $billing->company(), 'taxes' => $billing->taxSplit($estimate), 'interState' => $billing->isInterState($estimate->place_of_supply ?: $estimate->customer?->billing_state)]);
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
