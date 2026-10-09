<div>
    <div class="page-head"><div><h1>Payslips</h1><p>A payslip is issued when the first payment for a locked payroll is recorded.</p></div></div>
    @if($canAll && $own)
        <div class="tabs"><button class="tab {{ $scope === 'mine' ? 'active' : '' }}" wire:click="$set('scope','mine')">My payslips</button><button class="tab {{ $scope === 'all' ? 'active' : '' }}" wire:click="$set('scope','all')">All payslips</button></div>
    @endif
    <section class="card">
        @if($scope === 'all')<div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Reference, employee name or code"></label></div>@endif
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Reference</th>@if($scope === 'all')<th>Employee</th>@endif<th>Period</th><th class="num">Gross</th><th class="num">Net</th><th class="num">Paid</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($payslips as $slip)
                @php($paid = (float) $slip->item->payments->sum('amount'))
                <tr><td><strong>{{ $slip->reference }}</strong><small>issued {{ $slip->issued_at->format('d M Y') }}</small></td>
                    @if($scope === 'all')<td>{{ $slip->employee?->name }}</td>@endif
                    <td class="nowrap">{{ $slip->item->run->period_starts_on->format('d M') }} – {{ $slip->item->run->period_ends_on->format('d M Y') }}</td>
                    <td class="num">{{ number_format((float) $slip->item->gross_amount, 2) }}</td><td class="num"><strong>{{ number_format((float) $slip->item->net_amount, 2) }}</strong></td><td class="num">{{ number_format($paid, 2) }}</td>
                    <td><x-badge :status="$paid >= (float) $slip->item->net_amount ? 'paid' : 'partially_paid'" /></td>
                    <td><a class="btn btn-sm" href="{{ route('hr.payslips.show', $slip) }}" target="_blank"><x-icon name="external" class="icon-sm" />View / print</a></td></tr>
            @empty
                <tr><td colspan="8"><x-empty title="No payslips yet" icon="file" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $payslips->links() }}
    </section>
</div>
