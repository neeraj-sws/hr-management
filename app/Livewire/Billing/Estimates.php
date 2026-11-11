<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingEstimate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Estimates / quotes list. */
class Estimates extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $status = '';
    #[Url] public string $fy = '';
    #[Url] public string $customer = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public int $perPage = 10;

    public function mount(\App\Services\BillingService $billing): void
    {
        if (! request()->has('fy')) $this->fy = $billing->financialYear(today());
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatus(): void { $this->resetPage(); }
    public function updatingFy(): void { $this->resetPage(); }

    public function updated($property): void
    {
        if (in_array($property, ['customer', 'from', 'to', 'perPage'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'customer', 'from', 'to');
        $this->fy = '';
        $this->resetPage();
    }

    public function duplicate(int $id, \App\Services\InvoiceActions $actions): void
    {
        $this->guard();
        $copy = $actions->duplicateEstimate(BillingEstimate::findOrFail($id), auth()->id());
        $this->redirect(route('billing.estimates.edit', $copy));
    }

    public function delete(int $id, \App\Services\InvoiceActions $actions): void
    {
        $this->guard();
        $estimate = BillingEstimate::findOrFail($id);
        try {
            $actions->deleteEstimate($estimate);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toast(collect($e->errors())->flatten()->first(), 'error');

            return;
        }
        $this->toast('Estimate '.$estimate->number().' deleted.');
    }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $estimates = BillingEstimate::with('customer', 'category', 'items.tax')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('customer', fn ($q) => $q->where('name', 'like', "%{$term}%"))
                ->when(preg_match('/(\d+)$/', $term, $m) ? (int) $m[1] : null, fn ($n, $number) => $n->orWhere('number', $number))))
            ->when($this->customer, fn ($q, $c) => $q->where('customer_id', (int) $c))
            ->when($date($this->from), fn ($q, $d) => $q->whereDate('issue_date', '>=', $d))
            ->when($date($this->to), fn ($q, $d) => $q->whereDate('issue_date', '<=', $d))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->fy !== '', fn ($q) => $q->where('financial_year', $this->fy))
            ->orderByDesc('financial_year')->orderBy('number')->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10);

        return $this->page('livewire.billing.estimates', [
            'estimates' => $estimates,
            'customers' => \App\Models\BillingCustomer::orderBy('name')->get(['id', 'name']),
            'years' => BillingEstimate::query()->select('financial_year')->distinct()->orderBy('financial_year')->pluck('financial_year'),
        ], 'Proposals');
    }
}
