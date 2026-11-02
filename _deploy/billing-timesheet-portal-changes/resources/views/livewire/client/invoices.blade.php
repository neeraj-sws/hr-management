<div>
    <div class="page-head"><div><h1>Invoices</h1><p>Your invoices and what is still due.</p></div></div>
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Invoice</th><th>Date</th><th>Project</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($invoices as $inv)
                <tr><td><strong>{{ $inv->number() }}</strong></td><td>{{ $inv->issue_date->format('d M Y') }}</td><td>{{ $inv->project?->name ?? '—' }}</td>
                    <td class="num">₹{{ number_format($inv->total(), 2) }}</td><td class="num">{{ number_format($inv->paidAmount(), 2) }}</td><td class="num"><strong>{{ number_format(max(0, $inv->due()), 2) }}</strong></td>
                    <td>{{ $inv->status }}</td><td><a class="btn btn-sm" href="{{ route('client.invoices.print', $inv) }}" target="_blank">View / PDF</a></td></tr>
            @empty
                <tr><td colspan="8"><x-empty title="No invoices yet" icon="receipt" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</div>
