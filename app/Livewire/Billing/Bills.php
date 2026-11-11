<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingBill;
use App\Models\BillingVendor;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Purchases → Bills: supplier bills, what was paid and what is still due. */
class Bills extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $vendor = '';
    #[Url] public string $status = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public int $perPage = 10;

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'vendor', 'status', 'from', 'to', 'perPage'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'vendor', 'status', 'from', 'to');
        $this->resetPage();
    }

    public function duplicate(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $source = BillingBill::with('items')->findOrFail($id);
        $copy = \Illuminate\Support\Facades\DB::transaction(function () use ($source, $audit) {
            $copy = BillingBill::create(['number' => (int) BillingBill::lockForUpdate()->max('number') + 1, 'vendor_id' => $source->vendor_id, 'category_id' => $source->category_id,
                'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'status' => 'Draft', 'notes' => $source->notes, 'created_by' => auth()->id()]);
            foreach ($source->items as $item) { $line = $item->replicate(['bill_id']); $line->bill_id = $copy->id; $line->save(); }
            $audit->record('billing.bill_created', $copy, ['copied_from' => $source->number()]);

            return $copy;
        });
        $this->redirect(route('billing.bills.edit', $copy));
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $bill = BillingBill::withCount(['payments', 'debitNotes'])->findOrFail($id);
        if ($bill->payments_count || $bill->debit_notes_count) { $this->toast('Is bill par payment / debit note hai — pehle unhe hatao.', 'error'); return; }
        $audit->record('billing.bill_deleted', $bill, ['number' => $bill->number()]);
        $bill->items()->delete();
        $bill->delete();
        $this->toast('Bill deleted.');
    }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $query = BillingBill::query()
            ->when($this->vendor, fn ($q, $v) => $q->where('vendor_id', (int) $v))
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->when($date($this->from), fn ($q, $d) => $q->whereDate('bill_date', '>=', $d))
            ->when($date($this->to), fn ($q, $d) => $q->whereDate('bill_date', '<=', $d))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$term}%"))->orWhere('ref_number', 'like', "%{$term}%")
                ->when(preg_match('/(\d+)$/', $term, $m) ? (int) $m[1] : null, fn ($n, $number) => $n->orWhere('number', $number))));
        $all = (clone $query)->with(['items.tax', 'payments', 'debitNotes'])->get();

        return $this->page('livewire.billing.bills', [
            'bills' => $query->with(['vendor', 'items.tax', 'payments', 'debitNotes'])->orderByDesc('bill_date')->orderByDesc('number')
                ->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10),
            'vendors' => BillingVendor::orderBy('name')->get(['id', 'name']),
            'totals' => ['count' => $all->count(), 'billed' => round($all->sum(fn ($b) => $b->total()), 2), 'paid' => round($all->sum(fn ($b) => $b->paidAmount()), 2),
                'due' => round($all->where('status', '!=', 'Draft')->sum(fn ($b) => max(0, $b->due())), 2)],
        ], 'Bills');
    }
}
