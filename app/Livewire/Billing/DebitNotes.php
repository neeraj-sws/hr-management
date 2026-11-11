<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingBill;
use App\Models\BillingDebitNote;
use App\Models\BillingVendor;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Purchases → Debit notes: amounts a supplier takes back on a bill (added from the bill page). */
class DebitNotes extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $vendor = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public int $perPage = 10;

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function updated($property): void { if (in_array($property, ['vendor', 'from', 'to', 'perPage'], true)) $this->resetPage(); }

    public function resetFilters(): void { $this->reset('vendor', 'from', 'to'); $this->resetPage(); }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $note = BillingDebitNote::findOrFail($id);
        $audit->record('billing.debit_note_deleted', $note, ['amount' => (float) $note->amount]);
        $note->delete();
        if ($note->bill_id && $bill = BillingBill::with('items.tax', 'payments', 'debitNotes')->find($note->bill_id)) {
            if ($bill->status === 'Paid') $bill->status = $bill->paidAmount() > 0 ? 'Partially Paid' : 'Unpaid';
            $bill->refreshStatus();
        }
        $this->toast('Debit note removed.');
    }

    public function render()
    {
        $this->guard();
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $query = BillingDebitNote::query()
            ->when($this->vendor, fn ($q, $v) => $q->where('vendor_id', (int) $v))
            ->when($date($this->from), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($date($this->to), fn ($q, $d) => $q->whereDate('date', '<=', $d));

        return $this->page('livewire.billing.debit-notes', [
            'notes' => (clone $query)->with('bill', 'vendor')->orderByDesc('date')->orderByDesc('id')->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10),
            'total' => (float) (clone $query)->sum('amount'),
            'vendors' => BillingVendor::orderBy('name')->get(['id', 'name']),
        ], 'Debit notes');
    }
}
