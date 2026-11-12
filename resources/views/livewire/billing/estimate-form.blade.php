<div class="doc-form">
    <div class="page-head"><div><h1>{{ $estimateId ? 'Proposal Edit' : 'Proposal Create' }}</h1>
        <p><a href="{{ route('billing.estimates') }}">Proposal</a> · {{ $estimateId ? 'Proposal Edit' : 'Proposal Create' }}</p></div></div>
    <x-errors />
    <form wire:submit="save">
        @include('billing._doc-form', [
            'party' => $customer, 'partyField' => 'customerId', 'parties' => $customers, 'partyLabel' => 'Customer',
            'fieldsView' => 'billing._estimate-fields',
            'back' => $estimateId ? route('billing.estimates.show', $estimateId) : route('billing.estimates'),
            'saveLabel' => $estimateId ? 'Update' : 'Create',
        ])
    </form>
</div>
