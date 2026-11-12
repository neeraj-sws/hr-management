<?php

namespace App\Http\Controllers;

use App\Livewire\Billing\Invoices;
use App\Models\BillingCustomer;
use App\Support\XlsxWorkbook;
use Illuminate\Http\Request;

/** Excel downloads for billing: the invoice list (with the list's filters) and the customer list. */
class BillingExportController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->canAny(['hr.manage', 'payroll.manage']), 403);
    }

    public function invoices(Request $request)
    {
        $this->guard($request);
        $filters = $request->only(['search', 'status', 'fy', 'customer', 'from', 'to']);
        $invoices = Invoices::filtered($filters)->with(['customer', 'items.tax', 'payments', 'creditNotes'])->orderBy('issue_date')->orderBy('number')->get();

        $columns = [
            ['Invoice', 'text', 20], ['Customer', 'text', 34], ['Issue date', 'date', 12], ['Due date', 'date', 12],
            ['Sub total (₹)', 'currency', 14], ['Tax (₹)', 'currency', 12], ['Total (₹)', 'currency', 14], ['Paid (₹)', 'currency', 14],
            ['Credit notes (₹)', 'currency', 14], ['Due (₹)', 'currency', 14], ['Status', 'text', 14], ['Ref', 'text', 14], ['Hours', 'decimal', 10],
        ];
        $rows = $invoices->map(fn ($i) => [
            $i->number(), (string) $i->customer?->name, $i->issue_date->toDateString(), $i->due_date?->toDateString() ?? '',
            $i->subTotal(), $i->totalTax(), $i->total(), $i->paidAmount(), $i->creditTotal(), max(0, $i->due()), $i->status, (string) $i->ref_number, $i->hours !== null ? (float) $i->hours : '',
        ])->all();
        $rows[] = array_fill(0, 13, '');
        $rows[] = ['', ['v' => 'Total', 's' => 'title'], '', '',
            ['v' => round($invoices->sum(fn ($i) => $i->subTotal()), 2), 's' => 'currency'], ['v' => round($invoices->sum(fn ($i) => $i->totalTax()), 2), 's' => 'currency'],
            ['v' => round($invoices->sum(fn ($i) => $i->total()), 2), 's' => 'currency'], ['v' => round($invoices->sum(fn ($i) => $i->paidAmount()), 2), 's' => 'currency'],
            ['v' => round($invoices->sum(fn ($i) => $i->creditTotal()), 2), 's' => 'currency'], ['v' => round($invoices->sum(fn ($i) => max(0, $i->due())), 2), 's' => 'currency'], '', '', ['v' => round((float) $invoices->sum('hours'), 2), 's' => 'decimal']];

        return $this->download([['name' => 'Invoices', 'columns' => $columns, 'rows' => $rows]], 'invoices-'.today()->format('Y-m-d').'.xlsx');
    }

    public function customers(Request $request)
    {
        $this->guard($request);
        $customers = BillingCustomer::with(['invoices.items.tax', 'invoices.payments', 'invoices.creditNotes'])->orderBy('name')->get();
        $columns = [
            ['Name', 'text', 34], ['Email', 'text', 28], ['Contact', 'text', 16], ['GSTIN', 'text', 18], ['Billing name', 'text', 24], ['Billing address', 'wrap', 36],
            ['City', 'text', 14], ['State', 'text', 16], ['Zip', 'text', 10], ['Country', 'text', 12], ['Billing phone', 'text', 16],
            ['Invoices', 'integer', 10], ['Total invoiced (₹)', 'currency', 16], ['Balance due (₹)', 'currency', 16], ['Active', 'text', 8],
        ];
        $rows = $customers->map(fn ($c) => [
            $c->name, (string) $c->email, (string) $c->contact, (string) $c->tax_number, (string) $c->billing_name, (string) $c->billing_address,
            (string) $c->billing_city, (string) $c->billing_state, (string) $c->billing_zip, (string) $c->billing_country, (string) $c->billing_phone,
            $c->invoices->count(), round($c->invoices->sum(fn ($i) => $i->total()), 2), $c->owed(), $c->is_active ? 'Yes' : 'No',
        ])->all();

        return $this->download([['name' => 'Customers', 'columns' => $columns, 'rows' => $rows]], 'customers-'.today()->format('Y-m-d').'.xlsx');
    }

    public function estimates(Request $request)
    {
        $this->guard($request);
        $rows = \App\Models\BillingEstimate::with(['customer', 'category', 'items.tax'])->orderBy('number')->get()->map(fn ($e) => [
            $e->number(), (string) $e->customer?->name, (string) $e->category?->name, $e->issue_date->toDateString(), $e->expiry_date?->toDateString() ?? '',
            $e->total(), $e->status,
        ])->all();
        $columns = [['Proposal', 'text', 14], ['Customer', 'text', 32], ['Category', 'text', 24], ['Issue date', 'date', 12], ['Valid till', 'date', 12], ['Amount (₹)', 'currency', 14], ['Status', 'text', 12]];

        return $this->download([['name' => 'Proposals', 'columns' => $columns, 'rows' => $rows]], 'proposals-'.today()->format('Y-m-d').'.xlsx');
    }

    /** An empty import template with the column headers (and one example row). */
    public function customerSample(Request $request)
    {
        $this->guard($request);
        $headers = ['Name', 'Email', 'Contact', 'GSTIN', 'Billing name', 'Billing phone', 'Billing address', 'City', 'State', 'Zip', 'Country'];
        $example = ['Saletify', 'nilesh@saletify.com', '8087999946', '', 'Saletify Marketing Pvt Ltd', '8530666677', '305, Kumar Primus, Hadapsar', 'Pune', 'MH', '411013', 'India'];

        return $this->download([['name' => 'Customers', 'columns' => array_map(fn ($h) => [$h, 'text', 20], $headers), 'rows' => [$example]]], 'sample-customers.xlsx');
    }

    public function vendors(Request $request)
    {
        $this->guard($request);
        $vendors = \App\Models\BillingVendor::with(['bills.items.tax', 'bills.payments', 'bills.debitNotes'])->withSum('expenses', 'amount')->orderBy('name')->get();
        $columns = [['Code', 'text', 12], ['Name', 'text', 32], ['Email', 'text', 28], ['Contact', 'text', 16], ['GSTIN', 'text', 18], ['Address', 'wrap', 34],
            ['City', 'text', 14], ['State', 'text', 14], ['Bills', 'integer', 8], ['Billed (₹)', 'currency', 14], ['To pay (₹)', 'currency', 14], ['Expenses paid (₹)', 'currency', 16]];
        $rows = $vendors->map(fn ($v) => [\App\Services\BillingService::code('vendor', $v->id), $v->name, (string) $v->email, (string) $v->contact, (string) $v->tax_number,
            (string) $v->billing_address, (string) $v->billing_city, (string) $v->billing_state, $v->bills->count(), round($v->bills->sum(fn ($b) => $b->total()), 2),
            round($v->bills->where('status', '!=', 'Draft')->sum(fn ($b) => max(0, $b->due())), 2), round((float) $v->expenses_sum_amount, 2)])->all();

        return $this->download([['name' => 'Suppliers', 'columns' => $columns, 'rows' => $rows]], 'suppliers-'.today()->format('Y-m-d').'.xlsx');
    }

    private function download(array $sheets, string $name)
    {
        return response()->download(XlsxWorkbook::toTempFile($sheets), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
