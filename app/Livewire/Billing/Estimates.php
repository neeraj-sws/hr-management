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

    public function mount(\App\Services\BillingService $billing): void
    {
        if (! request()->has('fy')) $this->fy = $billing->financialYear(today());
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatus(): void { $this->resetPage(); }
    public function updatingFy(): void { $this->resetPage(); }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $estimates = BillingEstimate::with('customer', 'items')
            ->when($term !== '', fn ($q) => $q->whereHas('customer', fn ($q) => $q->where('name', 'like', "%{$term}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->fy !== '', fn ($q) => $q->where('financial_year', $this->fy))
            ->orderByDesc('issue_date')->orderByDesc('number')->paginate(20);

        return $this->page('livewire.billing.estimates', [
            'estimates' => $estimates,
            'years' => BillingEstimate::query()->select('financial_year')->distinct()->orderBy('financial_year')->pluck('financial_year'),
        ], 'Estimates');
    }
}
