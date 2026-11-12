<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingInvoice;
use App\Services\BillingService;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Concerns\WithPaginationNoUrl as WithPagination;

class Invoices extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $status = '';
    #[Url] public string $fy = '';
    #[Url] public string $customer = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public int $perPage = 10;

    /** Default the financial-year filter to the current year (unless the URL already picked one, incl. "All"). */
    public function mount(BillingService $billing): void
    {
        if (! request()->has('fy')) $this->fy = $billing->financialYear(today());
    }

    public function updated($p): void { if (in_array($p, ['search', 'status', 'fy', 'customer', 'from', 'to', 'perPage'], true)) $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'customer', 'from', 'to');
        $this->fy = '';
        $this->resetPage();
    }

    /** The list's filters, shared with the Excel export. */
    public static function filtered(array $f): \Illuminate\Database\Eloquent\Builder
    {
        $term = trim((string) ($f['search'] ?? ''));

        return BillingInvoice::query()
            ->when($f['status'] ?? '', fn ($q, $s) => $q->where('status', $s))
            ->when($f['fy'] ?? '', fn ($q, $y) => $q->where('financial_year', $y))
            ->when($f['customer'] ?? '', fn ($q, $c) => $q->where('customer_id', (int) $c))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? '')) ? $f['from'] : null, fn ($q, $d) => $q->whereDate('issue_date', '>=', $d))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? '')) ? $f['to'] : null, fn ($q, $d) => $q->whereDate('issue_date', '<=', $d))
            // "SWS/2026-27/00012", "00012" or "12" also find the invoice by its number.
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%"))
                ->when(preg_match('/(\d+)$/', $term, $m) ? (int) $m[1] : null, fn ($n, $number) => $n->orWhere('number', $number))));
    }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    /** Copy: a new Draft with the same customer and items, opened for editing. */
    public function duplicate(int $id, \App\Services\InvoiceActions $actions): void
    {
        $this->guard();
        $copy = $actions->duplicate(BillingInvoice::findOrFail($id), auth()->id());
        $this->redirect(route('billing.invoices.edit', $copy));
    }

    public function delete(int $id, \App\Services\InvoiceActions $actions): void
    {
        $this->guard();
        $invoice = BillingInvoice::findOrFail($id);
        try {
            $actions->delete($invoice);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toast(collect($e->errors())->flatten()->first(), 'error');

            return;
        }
        $this->toast('Invoice '.$invoice->number().' deleted.');
    }

    public function render()
    {
        $this->guard();
        $filters = ['search' => $this->search, 'status' => $this->status, 'fy' => $this->fy, 'customer' => $this->customer, 'from' => $this->from, 'to' => $this->to];
        $invoices = self::filtered($filters)->with(['customer', 'items.tax', 'payments', 'creditNotes'])
            ->orderByDesc('financial_year')->orderBy('number')->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10);

        $all = BillingInvoice::with(['items', 'payments', 'creditNotes'])->get();

        return $this->page('livewire.billing.invoices', [
            'invoices' => $invoices,
            'filters' => array_filter($filters, fn ($v) => $v !== ''),
            'customers' => \App\Models\BillingCustomer::orderBy('name')->get(['id', 'name']),
            'years' => BillingInvoice::select('financial_year')->distinct()->orderBy('financial_year')->pluck('financial_year'),
            'totals' => [
                'count' => $all->count(),
                'billed' => round($all->sum(fn ($i) => $i->total()), 2),
                'received' => round($all->sum(fn ($i) => $i->paidAmount()), 2),
                'due' => round($all->sum(fn ($i) => max(0, $i->due())), 2),
            ],
        ], 'Invoices');
    }
}
