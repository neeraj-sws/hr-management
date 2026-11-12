<div class="doc-form">
    <div class="page-head"><div><h1>{{ $invoiceId ? 'Invoice Edit' : 'Invoice Create' }}</h1>
        <p><a href="{{ route('billing.invoices') }}">Invoice</a> · {{ $invoiceId ? 'Invoice Edit' : 'Invoice Create' }}</p></div></div>
    <x-errors />
    <form wire:submit="save">
        @include('billing._doc-form', [
            'party' => $customer, 'partyField' => 'customerId', 'parties' => $customers, 'partyLabel' => 'Customer',
            'fieldsView' => 'billing._invoice-fields', 'editing' => (bool) $invoiceId,
            'back' => $invoiceId ? route('billing.invoices.show', $invoiceId) : route('billing.invoices'),
            'saveLabel' => $invoiceId ? 'Update' : 'Create',
        ])
    </form>
</div>
