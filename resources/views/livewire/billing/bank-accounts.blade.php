<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>Bank accounts</h1><p>Cash and bank accounts that invoice payments and revenue are received into.</p></div>
        <div class="row-actions"><a class="btn" href="{{ route('billing.transfers') }}"><x-icon name="refresh" class="icon-sm" />Transfers</a><button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add account</button></div></div>
    <x-errors />
    <section class="card">
        <div class="table-wrap"><table class="table fx"><colgroup><col style="width:12%"><col style="width:14%"><col style="width:14%"><col style="width:10%"><col style="width:10%"><col style="width:10%"><col style="width:12%"><col style="width:10%"><col style="width:8%"></colgroup>
            <thead><tr><th>Bank</th><th>Account holder</th><th>Account no.</th><th class="num">Opening (₹)</th><th class="num">Money in (₹)</th><th class="num">Money out (₹)</th><th class="num">Current balance (₹)</th><th>Contact</th><th></th></tr></thead>
            <tbody>
            @forelse($accounts as $a)
                <tr wire:key="acc-{{ $a->id }}" @unless($a->is_active) style="opacity:.6" @endunless>
                    <td><strong>{{ $a->bank_name }}</strong>@unless($a->is_active)<small>inactive</small>@endunless</td><td>{{ $a->holder_name }}</td>
                    <td>{{ $a->account_number ?: '—' }}@if($a->ifsc)<small>IFSC {{ $a->ifsc }}</small>@endif</td>
                    <td class="num">{{ number_format((float) $a->opening_balance, 2) }}</td><td class="num">{{ number_format($a->money_in, 2) }}</td><td class="num">{{ number_format($a->money_out, 2) }}</td>
                    <td class="num"><strong>{{ number_format((float) $a->opening_balance + $a->money_in - $a->money_out, 2) }}</strong></td><td>{{ $a->contact ?: '—' }}</td>
                    <td><div class="row-actions">
                        <button class="btn btn-sm btn-icon" wire:click="edit({{ $a->id }})" title="Edit" aria-label="Edit account"><x-icon name="edit" class="icon-sm" /></button>
                        <button class="btn btn-sm btn-icon btn-danger" wire:click="delete({{ $a->id }})" wire:confirm="Account {{ $a->bank_name }} delete karein?" title="Delete" aria-label="Delete account"><x-icon name="trash" class="icon-sm" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="9"><x-empty title="No accounts" icon="bank" /></td></tr>@endforelse
            </tbody></table></div>
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit account' : 'Add account'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Bank name" name="bankName"><input wire:model="bankName" placeholder="e.g. HDFC Bank / Cash"></x-field>
                <x-field label="Account holder" name="holderName"><input wire:model="holderName" placeholder="e.g. Soham Web Solution"></x-field>
                <x-field label="Account number" name="accountNumber"><input wire:model="accountNumber"></x-field>
                <x-field label="IFSC" name="ifsc"><input wire:model="ifsc"></x-field>
                <x-field label="Opening balance (₹)" name="openingBalance"><input type="number" step="0.01" wire:model="openingBalance"></x-field>
                <x-field label="Contact" name="contact"><input wire:model="contact"></x-field>
                <x-field label="Bank address" name="address" full><textarea wire:model="address" rows="2"></textarea></x-field>
                <label class="check"><input type="checkbox" wire:model="isActive"> Active</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
