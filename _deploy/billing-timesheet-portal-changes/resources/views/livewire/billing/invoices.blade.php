<div>
    <div class="page-head"><div><h1>Invoices</h1><p>Bills to customers — status, payments and dues.</p></div>
        <a class="btn btn-primary" href="{{ route('billing.invoices.create') }}"><x-icon name="plus" class="icon-sm" />New invoice</a></div>
    <div class="stats">
        <div class="stat"><span class="stat-icon"><x-icon name="receipt" /></span><div><span class="stat-label">Invoices</span><span class="stat-value">{{ $totals['count'] }}</span></div></div>
        <div class="stat"><span class="stat-icon"><x-icon name="layers" /></span><div><span class="stat-label">Billed</span><span class="stat-value">{{ number_format($totals['billed'], 0) }}</span></div></div>
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Received</span><span class="stat-value">{{ number_format($totals['received'], 0) }}</span></div></div>
        <div class="stat"><span class="stat-icon amber"><x-icon name="clock" /></span><div><span class="stat-label">Outstanding</span><span class="stat-value">{{ number_format($totals['due'], 0) }}</span></div></div>
    </div>
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Customer name"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option>@foreach(\App\Models\BillingInvoice::STATUSES as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
            <label class="field"><span>Financial year</span><select wire:model.live="fy"><option value="">All</option>@foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Invoice #</th><th>Customer</th><th>Issue</th><th>Due date</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($invoices as $i)
                <tr wire:key="i-{{ $i->id }}"><td><strong>{{ $i->number() }}</strong></td><td>{{ $i->customer?->name ?? '—' }}</td>
                    <td class="nowrap">{{ $i->issue_date->format('d M Y') }}</td><td class="nowrap">{{ $i->due_date?->format('d M Y') ?? '—' }}</td>
                    <td class="num">{{ number_format($i->total(), 2) }}</td><td class="num">{{ number_format($i->paidAmount(), 2) }}</td>
                    <td class="num"><strong>{{ number_format(max(0, $i->due()), 2) }}</strong></td>
                    <td><span class="badge">{{ $i->status }}</span></td>
                    <td><a class="btn btn-sm" href="{{ route('billing.invoices.show', $i) }}">Open</a></td></tr>
            @empty<tr><td colspan="9"><x-empty title="No invoices" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $invoices->links() }}
    </section>
</div>
