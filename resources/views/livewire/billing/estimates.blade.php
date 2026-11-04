<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>Estimates</h1><p>Quotes you send before billing — convert an accepted one to an invoice.</p></div>
        <a class="btn btn-primary" href="{{ route('billing.estimates.create') }}"><x-icon name="plus" class="icon-sm" />New estimate</a></div>
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Customer name"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option>@foreach(\App\Models\BillingEstimate::STATUSES as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
            <label class="field"><span>Financial year</span><select wire:model.live="fy"><option value="">All</option>@foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Estimate #</th><th>Customer</th><th>Issue</th><th>Expiry</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($estimates as $e)
                <tr wire:key="e-{{ $e->id }}"><td><strong>{{ $e->number() }}</strong></td><td>{{ $e->customer?->name ?? '—' }}</td>
                    <td class="nowrap">{{ $e->issue_date->format('d M Y') }}</td><td class="nowrap">{{ $e->expiry_date?->format('d M Y') ?? '—' }}</td>
                    <td class="num">{{ number_format($e->total(), 2) }}</td>
                    <td><span class="bill-badge {{ billing_badge_class($e->status) }}">{{ $e->status }}</span></td>
                    <td><a class="btn btn-sm" href="{{ route('billing.estimates.show', $e) }}">Open</a></td></tr>
            @empty<tr><td colspan="7"><x-empty title="No estimates" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $estimates->links() }}
    </section>
</div>
