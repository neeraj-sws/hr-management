<?php

namespace App\Livewire\Client;

use App\Models\BillingInvoice;
use Livewire\Component;

/** The client's invoices (from their billing customer), with balance due and a printable copy. */
class Invoices extends Component
{
    public function render()
    {
        $user = auth()->user();
        abort_unless($user?->client_id, 403);
        $customerId = $user->client?->billing_customer_id;
        $invoices = $customerId ? BillingInvoice::with(['items.tax', 'payments', 'creditNotes', 'project'])->where('customer_id', $customerId)->where('status', '!=', 'Draft')->orderByDesc('issue_date')->get() : collect();

        return view('livewire.client.invoices', ['invoices' => $invoices])->layout('layouts.client')->title('Invoices');
    }
}
