@php
    $d = fn ($v) => \App\Services\BillingService::date($v);
    $due = max(0, $bill->due());
    $label = $bill->status === 'Sent' ? 'Received' : $bill->status;
    $v = $bill->vendor;
@endphp
<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>{{ $bill->number() }}<span class="bill-badge {{ billing_badge_class($bill->status) }}">{{ $label }}</span></h1>
        <p><a href="{{ route('billing.bills') }}">Bills</a> · {{ $v?->name }} · {{ $d($bill->bill_date) }}</p></div>
        <div class="row-actions">
            @if($bill->status === 'Draft')<button class="btn" wire:click="markReceived"><x-icon name="check" class="icon-sm" />Mark as received</button>@endif
            <a class="btn" href="{{ route('billing.bills.edit', $bill) }}"><x-icon name="edit" class="icon-sm" />Edit</a>
            @if($due > 0)<button class="btn" wire:click="openDebit()"><x-icon name="plus" class="icon-sm" />Debit note</button><button class="btn btn-primary" wire:click="openPayment"><x-icon name="plus" class="icon-sm" />Add payment</button>@endif
        </div></div>
    <x-errors />

    <section class="card mb">
        <div class="slip-meta" style="padding:16px 18px">
            <div><span>Supplier</span><strong>{{ $v?->billing_name ?: $v?->name }}</strong><small>{{ collect([$v?->billing_city, $v?->billing_state])->filter()->join(', ') }}</small></div>
            <div><span>Bill date</span><strong>{{ $d($bill->bill_date) }}</strong></div>
            <div><span>Due date</span><strong>{{ $d($bill->due_date) ?? '—' }}</strong></div>
            <div><span>Supplier's bill no.</span><strong>{{ $bill->ref_number ?: '—' }}</strong></div>
            <div><span>Category</span><strong>{{ $bill->category?->name ?? '—' }}</strong></div>
            <div><span>Status</span><strong>{{ $label }}</strong></div>
        </div>
        <div class="table-wrap"><table class="table bill-items">
            <thead><tr><th>#</th><th>Product</th><th class="num">Quantity</th><th class="num">Rate</th><th class="num">Discount</th><th>Tax</th><th>Description</th><th class="num">Price</th></tr></thead>
            <tbody>
            @foreach($bill->items as $it)
                <tr><td>{{ $loop->iteration }}</td><td><strong>{{ $it->name }}</strong></td><td class="num">{{ (float) $it->quantity }}</td><td class="num">{{ number_format($it->price, 2) }}</td>
                    <td class="num">{{ number_format($it->discount, 2) }}</td><td>{{ $it->tax?->name ?? '—' }}</td><td style="white-space:normal">{{ $it->description ?: '—' }}</td><td class="num">{{ number_format($it->lineTotal(), 2) }}</td></tr>
            @endforeach
            </tbody></table></div>
        <div class="inv-totals" style="margin:16px 18px 18px auto">
            <div class="r"><span>Sub total</span><b>₹{{ number_format($bill->subTotal(), 2) }}</b></div>
            <div class="r"><span>Tax</span><b>₹{{ number_format($bill->totalTax(), 2) }}</b></div>
            <div class="r total"><span>Total</span><b>₹{{ number_format($bill->total(), 2) }}</b></div>
            <div class="r"><span>Paid</span><b>₹{{ number_format($bill->paidAmount(), 2) }}</b></div>
            <div class="r"><span>Debit note</span><b>₹{{ number_format($bill->debitTotal(), 2) }}</b></div>
            <div class="inv-due {{ $due <= 0 ? 'clear' : '' }}"><span class="lbl">Due</span><b>₹{{ number_format($due, 2) }}</b></div>
        </div>
        @if($bill->notes)<p class="small" style="padding:0 18px 16px;white-space:pre-line"><strong>Notes:</strong> {{ $bill->notes }}</p>@endif
    </section>

    <section class="card mb">
        <div class="card-head"><h2><x-icon name="credit-card" class="icon-sm" /> Payment Summary</h2>@if($due > 0)<button class="btn btn-sm" wire:click="openPayment"><x-icon name="plus" class="icon-sm" />Add payment</button>@endif</div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Payment receipt</th><th>Date</th><th class="num">Amount</th><th>Payment type</th><th>Account</th><th>Reference</th><th>Description</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($bill->payments as $p)
                <tr><td>@if($p->receipt_path)<a class="btn btn-sm" href="{{ route('billing.receipt-file', ['bill-payment', $p->id]) }}" target="_blank"><x-icon name="eye" class="icon-sm" />View</a>@else—@endif</td>
                    <td class="nowrap">{{ $d($p->date) }}</td><td class="num"><strong>{{ number_format($p->amount, 2) }}</strong></td><td>{{ $p->method ?: '—' }}</td>
                    <td>{{ $p->account?->label() ?? '—' }}</td><td>{{ $p->reference ?: '—' }}</td><td style="white-space:normal">{{ $p->note ?: '—' }}</td>
                    <td><button class="btn btn-sm btn-icon btn-danger" wire:click="deletePayment({{ $p->id }})" wire:confirm="Payment of {{ number_format($p->amount, 2) }} remove karein?" title="Remove" aria-label="Remove payment"><x-icon name="trash" class="icon-sm" /></button></td></tr>
            @empty<tr><td colspan="8"><x-empty title="No payments yet" icon="credit-card" /></td></tr>@endforelse
            </tbody></table></div>
    </section>

    <section class="card">
        <div class="card-head"><h2><x-icon name="receipt" class="icon-sm" /> Debit Note Summary</h2>@if($due > 0)<button class="btn btn-sm" wire:click="openDebit()"><x-icon name="plus" class="icon-sm" />Add debit note</button>@endif</div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th class="num">Amount</th><th>Description</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($bill->debitNotes as $n)
                <tr><td class="nowrap">{{ $d($n->date) }}</td><td class="num"><strong>{{ number_format($n->amount, 2) }}</strong></td><td style="white-space:normal">{{ $n->description ?: '—' }}</td>
                    <td><div class="row-actions"><button class="btn btn-sm btn-icon" wire:click="openDebit({{ $n->id }})" title="Edit" aria-label="Edit debit note"><x-icon name="edit" class="icon-sm" /></button>
                        <button class="btn btn-sm btn-icon btn-danger" wire:click="deleteDebit({{ $n->id }})" wire:confirm="Debit note remove karein?" title="Delete" aria-label="Delete debit note"><x-icon name="trash" class="icon-sm" /></button></div></td></tr>
            @empty<tr><td colspan="4"><x-empty title="No debit notes" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
    </section>

    @if($showPayment)
        <x-modal title="Add payment">
            <form wire:submit="recordPayment"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Amount (₹)" name="payAmount"><input type="number" step="0.01" wire:model="payAmount"></x-field>
                <x-field label="Date" name="payDate"><input type="date" wire:model="payDate"></x-field>
                <x-field label="Account" name="payAccountId"><select wire:model="payAccountId"><option value="">—</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</select></x-field>
                <x-field label="Method" name="payMethod"><select wire:model="payMethod"><option>Bank</option><option>Cash</option><option>UPI</option><option>Cheque</option><option>Card</option></select></x-field>
                <x-field label="Reference" name="payReference"><input wire:model="payReference"></x-field>
                <x-field label="Payment receipt" name="payReceipt" hint="image ya PDF"><input type="file" wire:model="payReceipt" accept=".jpg,.jpeg,.png,.webp,.pdf"></x-field>
                <x-field label="Description" name="payNote" full><textarea wire:model="payNote" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('showPayment', false)">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save payment</button></div></form>
        </x-modal>
    @endif
    @if($showDebit)
        <x-modal :title="$debitId ? 'Edit debit note' : 'Add debit note'">
            <form wire:submit="saveDebit"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Amount (₹)" name="debitAmount" hint="due: ₹{{ number_format($due, 2) }}"><input type="number" step="0.01" wire:model="debitAmount"></x-field>
                <x-field label="Date" name="debitDate"><input type="date" wire:model="debitDate"></x-field>
                <x-field label="Description" name="debitDescription" full><input wire:model="debitDescription" placeholder="e.g. Returned goods"></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('showDebit', false)">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
