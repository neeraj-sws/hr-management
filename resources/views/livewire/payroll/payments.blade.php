<div>
    <div class="page-head"><div><h1>Payments</h1><p>Payments can only be recorded against locked payroll. Paying more than the net salary is allowed: the part above it is saved as extra pay for that month and is never cut from later salaries.</p></div></div>
    <x-errors />
    <section class="card mb">
        <div class="card-head"><div><h2>Outstanding on locked payroll</h2><p>{{ $outstanding->count() }} employees · {{ number_format($outstanding->sum('balance'), 2) }} remaining</p></div></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Employee</th><th>Period</th><th class="num">Net</th><th class="num">Paid</th><th class="num">Balance</th><th></th></tr></thead><tbody>
            @forelse($outstanding as $item)
                <tr><td><strong>{{ $item->employee?->name }}</strong></td><td>{{ $item->run->period_starts_on->format('d M') }} – {{ $item->run->period_ends_on->format('d M Y') }}</td><td class="num">{{ number_format((float) $item->net_amount, 2) }}</td><td class="num">{{ number_format((float) $item->payments->sum('amount'), 2) }}</td><td class="num"><strong>{{ number_format($item->balance, 2) }}</strong></td>
                    <td>@can('payroll.manage')<button class="btn btn-sm btn-primary" wire:click="openPayment({{ $item->id }})">Record payment</button>@endcan</td></tr>
            @empty<tr><td colspan="6"><x-empty title="Nothing outstanding" icon="check-circle" /></td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="card">
        <div class="card-head"><h2>Payment ledger</h2></div>
        <div class="toolbar">
            <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>To</span><input type="date" wire:model.live="to" x-bind:min="$wire.from"></label>
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Employee or reference"></label>
        </div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Paid on</th><th>Employee</th><th>Payroll period</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th class="num">Extra pay</th></tr></thead><tbody>
            @forelse($payments as $p)
                <tr><td>{{ $p->paid_on->format('d M Y') }}</td><td>{{ $p->payrollItem?->employee?->name }}</td><td>{{ $p->payrollItem?->run?->period_starts_on->format('d M') }} – {{ $p->payrollItem?->run?->period_ends_on->format('d M Y') }}</td><td>{{ \App\Models\SalaryPayment::METHODS[$p->method] ?? '—' }}</td><td>{{ $p->reference ?? '—' }}</td><td class="num">{{ number_format((float) $p->amount, 2) }}</td><td class="num">@php($extra = $p->extraPart()){{ $extra > 0 ? number_format($extra, 2) : '—' }}</td></tr>
            @empty<tr><td colspan="7"><x-empty title="No payments in this range" icon="credit-card" /></td></tr>@endforelse
        </tbody></table></div>
        {{ $payments->links() }}
    </section>
    @if($paying)
        <x-modal title="Record payment · {{ $paying->employee?->name }}">
            <form wire:submit="pay"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Amount" name="amount"><input type="number" step="0.01" min="0.01" wire:model="amount"></x-field>
                <x-field label="Payment date" name="paidOn"><input type="date" wire:model="paidOn" max="{{ today()->toDateString() }}"></x-field>
                <x-field label="Method" name="method"><select wire:model="method">@foreach(\App\Models\SalaryPayment::METHODS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                <x-field label="Bank / UTR / cheque no." name="reference"><input wire:model="reference"></x-field>
            </div>
            @php($due = max(0, round((float) $paying->net_amount - (float) $paying->payments->sum('amount'), 2)))
            <p class="small muted" style="margin:10px 0 0" x-data x-text="(parseFloat($wire.amount) || 0) > {{ $due }} ? 'Salary due is {{ number_format($due, 2) }}. The extra ' + ((parseFloat($wire.amount) || 0) - {{ $due }}).toFixed(2) + ' will be saved as extra pay for this month (not an advance, never cut from later salaries).' : 'Salary due is {{ number_format($due, 2) }}. Anything paid above it is saved as extra pay.'"></p>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Record payment</button></div></form>
        </x-modal>
    @endif
</div>
