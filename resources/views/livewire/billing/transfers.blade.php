<div>
    <div class="page-head"><div><h1>Transfers</h1><p>Money moved between your bank and cash accounts.</p></div>
        <div class="row-actions"><a class="btn" href="{{ route('billing.bank-accounts') }}"><x-icon name="bank" class="icon-sm" />Bank accounts</a><button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New transfer</button></div></div>
    <x-errors />
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Entries per page</span><select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <label class="field"><span>Account</span><select wire:model.live="account"><option value="">All accounts</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</select></label>
            <label class="field"><span>Date from</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>to</span><input type="date" wire:model.live="to"></label>
            <div class="field"><span>&nbsp;</span><button type="button" class="btn" wire:click="resetFilters"><x-icon name="x" class="icon-sm" />Reset</button></div>
        </div>
        <div class="table-wrap"><table class="table fx"><colgroup><col style="width:11%"><col style="width:17%"><col style="width:17%"><col style="width:11%"><col style="width:13%"><col style="width:22%"><col style="width:9%"></colgroup>
            <thead><tr><th>Date</th><th>From account</th><th>To account</th><th class="num">Amount</th><th>Reference</th><th>Description</th><th></th></tr></thead>
            <tbody>
            @forelse($transfers as $t)
                <tr wire:key="tr-{{ $t->id }}"><td class="nowrap">{{ \App\Services\BillingService::date($t->date) }}</td><td>{{ $t->from?->label() ?? '—' }}</td><td>{{ $t->to?->label() ?? '—' }}</td>
                    <td class="num"><strong>₹{{ number_format($t->amount, 2) }}</strong></td><td>{{ $t->reference ?: '—' }}</td><td style="white-space:normal">{{ $t->description ?: '—' }}</td>
                    <td><div class="row-actions">
                        <button class="btn btn-sm btn-icon" wire:click="edit({{ $t->id }})" title="Edit" aria-label="Edit transfer"><x-icon name="edit" class="icon-sm" /></button>
                        <button class="btn btn-sm btn-icon btn-danger" wire:click="delete({{ $t->id }})" wire:confirm="Transfer of ₹{{ number_format($t->amount, 2) }} delete karein?" title="Delete" aria-label="Delete transfer"><x-icon name="trash" class="icon-sm" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="7"><x-empty title="No transfers" icon="refresh" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $transfers->links() }}
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit transfer' : 'New transfer'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="From account" name="fromAccountId"><select wire:model="fromAccountId"><option value="">— choose —</option>@foreach($accounts->where('is_active', true) as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</select></x-field>
                <x-field label="To account" name="toAccountId"><select wire:model="toAccountId"><option value="">— choose —</option>@foreach($accounts->where('is_active', true) as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</select></x-field>
                <x-field label="Amount (₹)" name="amount"><input type="number" step="0.01" min="0" wire:model="amount"></x-field>
                <x-field label="Date" name="date"><input type="date" wire:model="date"></x-field>
                <x-field label="Reference" name="reference"><input wire:model="reference"></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
