<div>
    <div class="page-head"><div><h1>Expenses</h1><p>Money paid out directly — rent, tea, hardware, salary advance… (CMS "Payment").</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add expense</button></div>
    <x-errors />
    <div class="stats">
        <div class="stat"><span class="stat-icon"><x-icon name="receipt" /></span><div><span class="stat-label">Entries</span><span class="stat-value">{{ $count }}</span></div></div>
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Total paid</span><span class="stat-value">₹{{ number_format($total, 2) }}</span></div></div>
    </div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Date from</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>to</span><input type="date" wire:model.live="to"></label>
            <label class="field"><span>Account</span><select wire:model.live="account"><option value="">All accounts</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</select></label>
            <label class="field"><span>Supplier</span><select wire:model.live="vendor"><option value="">All suppliers</option>@foreach($vendors as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
            <label class="field"><span>Category</span><select wire:model.live="categoryFilter"><option value="">All categories</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
            <div class="field"><span>&nbsp;</span><button type="button" class="btn" wire:click="resetFilters"><x-icon name="x" class="icon-sm" />Reset</button></div>
        </div>
        <div class="toolbar">
            <label class="field"><span>Entries per page</span><select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Supplier, reference or description"></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th class="num">Amount</th><th>Account</th><th>Supplier</th><th>Category</th><th>Reference</th><th>Description</th><th>Payment receipt</th><th></th></tr></thead>
            <tbody>
            @forelse($expenses as $r)
                <tr wire:key="exp-{{ $r->id }}"><td class="nowrap">{{ \App\Services\BillingService::date($r->date) }}</td>
                    <td class="num"><strong>₹{{ number_format($r->amount, 2) }}</strong></td>
                    <td>{{ $r->account?->label() ?? ($r->payment_method ?: '—') }}</td><td>{{ $r->vendor?->name ?? '—' }}</td>
                    <td>{{ $r->category?->name ?? '—' }}</td><td>{{ $r->reference ?: '—' }}</td>
                    <td style="white-space:normal;max-width:260px">{{ \Illuminate\Support\Str::limit((string) $r->description, 90) ?: '—' }}</td>
                    <td>@if($r->receipt_path)<a class="btn btn-sm" href="{{ route('billing.receipt-file', ['expense', $r->id]) }}" target="_blank"><x-icon name="eye" class="icon-sm" />View</a>@else—@endif</td>
                    <td><div class="row-actions">
                        <button class="btn btn-sm btn-icon" wire:click="edit({{ $r->id }})" title="Edit" aria-label="Edit expense"><x-icon name="edit" class="icon-sm" /></button>
                        <button class="btn btn-sm btn-icon btn-danger" wire:click="delete({{ $r->id }})" wire:confirm="Expense of ₹{{ number_format($r->amount, 2) }} delete karein?" title="Delete" aria-label="Delete expense"><x-icon name="trash" class="icon-sm" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="9"><x-empty title="No expenses" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $expenses->links() }}
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit expense' : 'Create new expense'" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Date" name="date"><input type="date" wire:model="date"></x-field>
                <x-field label="Amount (₹)" name="amount"><input type="number" step="0.01" min="0" wire:model="amount" placeholder="Enter amount"></x-field>
                <x-field label="Account" name="accountId"><select wire:model="accountId"><option value="">— choose —</option>@foreach($accounts->where('is_active', true) as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</select></x-field>
                <x-field label="Supplier" name="vendorId"><select wire:model="vendorId"><option value="">—</option>@foreach($vendors as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description" rows="3" placeholder="Enter description"></textarea></x-field>
                <x-field label="Category" name="categoryId" hint="Billing → Categories me banao"><select wire:model="categoryId"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
                <x-field label="Reference" name="reference"><input wire:model="reference" placeholder="Enter reference"></x-field>
                <x-field label="Payment method" name="paymentMethod"><select wire:model="paymentMethod"><option value="">—</option><option>Bank</option><option>Cash</option><option>UPI</option><option>Cheque</option><option>Card</option></select></x-field>
                <x-field label="Payment receipt" name="receipt" hint="image ya PDF, 5 MB tak">
                    <input type="file" wire:model="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf">
                    <div wire:loading wire:target="receipt" class="small muted">Uploading…</div>
                    @if($editingId && ($existing = \App\Models\BillingExpense::find($editingId)) && $existing->receipt_path && ! $receipt)
                        <small><a href="{{ route('billing.receipt-file', ['expense', $editingId]) }}" target="_blank">Current receipt</a> · <label class="check" style="display:inline-flex"><input type="checkbox" wire:model="removeReceipt"> remove</label></small>
                    @endif
                </x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">{{ $editingId ? 'Update' : 'Create' }}</button></div></form>
        </x-modal>
    @endif
</div>
