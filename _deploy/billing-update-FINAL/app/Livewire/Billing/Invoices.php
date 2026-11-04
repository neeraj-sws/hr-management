<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingInvoice;
use App\Services\BillingService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Invoices extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $status = '';
    #[Url] public string $fy = '';

    /** Default the financial-year filter to the current year (unless the URL already picked one, incl. "All"). */
    public function mount(BillingService $billing): void
    {
        if (! request()->has('fy')) $this->fy = $billing->financialYear(today());
    }

    public function updated($p): void { if (in_array($p, ['search', 'status', 'fy'], true)) $this->resetPage(); }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $invoices = BillingInvoice::with(['customer', 'items', 'payments', 'creditNotes'])
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->when($this->fy, fn ($q, $f) => $q->where('financial_year', $f))
            ->when($term !== '', fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")))
            ->orderByDesc('issue_date')->orderByDesc('number')->paginate(20);

        $all = BillingInvoice::with(['items', 'payments', 'creditNotes'])->get();

        return $this->page('livewire.billing.invoices', [
            'invoices' => $invoices,
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
