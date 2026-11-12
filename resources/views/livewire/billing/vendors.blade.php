<div>
    <div class="page-head"><div><h1>Suppliers</h1><p>Vendors the company buys from — their bills and what is still to be paid.</p></div>
        <div class="row-actions">
            <a class="btn" href="{{ route('billing.vendors.export') }}"><x-icon name="download" class="icon-sm" />Export</a>
            <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add supplier</button>
        </div></div>
    <x-errors />
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Entries per page</span><select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, email or contact"></label>
        </div>
        <div class="table-wrap"><table class="table fx"><colgroup><col style="width:10%"><col style="width:20%"><col style="width:18%"><col style="width:12%"><col style="width:8%"><col style="width:10%"><col style="width:12%"><col style="width:10%"></colgroup>
            <thead><tr><th>Code</th><th>Name</th><th>Email</th><th>Contact</th><th class="num">Bills</th><th class="num">Expenses paid</th><th class="num">Balance to pay</th><th></th></tr></thead>
            <tbody>
            @forelse($vendors as $v)
                <tr wire:key="v-{{ $v->id }}" @unless($v->is_active) style="opacity:.6" @endunless>
                    <td class="nowrap muted">{{ \App\Services\BillingService::code('vendor', $v->id) }}</td>
                    <td><strong>{{ $v->name }}</strong>@if($v->billing_city)<small>{{ $v->billing_city }}{{ $v->billing_state ? ', '.$v->billing_state : '' }}</small>@endif</td>
                    <td>{{ $v->email ?: '—' }}</td><td>{{ $v->contact ?: '—' }}</td><td class="num">{{ $v->bills_count }}</td>
                    <td class="num">₹{{ number_format((float) $v->expenses_sum_amount, 2) }}</td>
                    <td class="num">@if($v->balance_due > 0)<strong style="color:var(--red)">₹{{ number_format($v->balance_due, 2) }}</strong>@else<span class="muted">₹0.00</span>@endif</td>
                    <td><div class="row-actions">
                        <a class="btn btn-sm btn-icon" href="{{ route('billing.bills', ['vendor' => $v->id, 'fy' => '']) }}" title="Bills" aria-label="Bills of {{ $v->name }}"><x-icon name="receipt" class="icon-sm" /></a>
                        <a class="btn btn-sm btn-icon" href="{{ route('billing.bills.create', ['vendor' => $v->id]) }}" title="New bill" aria-label="New bill"><x-icon name="plus" class="icon-sm" /></a>
                        <button class="btn btn-sm btn-icon" wire:click="edit({{ $v->id }})" title="Edit" aria-label="Edit supplier"><x-icon name="edit" class="icon-sm" /></button>
                        <button class="btn btn-sm btn-icon btn-danger" wire:click="delete({{ $v->id }})" wire:confirm="Supplier {{ $v->name }} delete karein?" title="Delete" aria-label="Delete supplier"><x-icon name="trash" class="icon-sm" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="8"><x-empty title="No suppliers" icon="users" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $vendors->links() }}
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit supplier' : 'Add supplier'" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors />
                <div class="form-grid">
                    <x-field label="Name" name="name" full><input wire:model="name"></x-field>
                    <x-field label="Email" name="email"><input type="email" wire:model="email"></x-field>
                    <x-field label="Contact" name="contact"><input wire:model="contact"></x-field>
                    <x-field label="Tax / GST number" name="taxNumber"><input wire:model="taxNumber"></x-field>
                    <label class="check"><input type="checkbox" wire:model="isActive"> Active</label>
                </div>
                <h3 class="small muted" style="margin:14px 0 8px">Billing address</h3>
                <div class="form-grid">
                    <x-field label="Billing name" name="billingName"><input wire:model="billingName"></x-field>
                    <x-field label="Phone" name="billingPhone"><input wire:model="billingPhone"></x-field>
                    <x-field label="Address" name="billingAddress" full><textarea wire:model="billingAddress" rows="2"></textarea></x-field>
                    <x-field label="City" name="billingCity"><input wire:model="billingCity"></x-field>
                    <x-field label="State" name="billingState"><input wire:model="billingState"></x-field>
                    <x-field label="Zip / PIN" name="billingZip"><input wire:model="billingZip"></x-field>
                    <x-field label="Country" name="billingCountry"><input wire:model="billingCountry"></x-field>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
