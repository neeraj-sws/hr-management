<div class="bill-form">
    @include('billing._ui')
    <style>.bill-form .bill-items td:nth-child(2) .input{max-width:100%;width:100%}.bill-form .bill-items{table-layout:auto}</style>
    <div class="page-head"><div><h1>{{ $estimateId ? 'Edit estimate' : 'New estimate' }}</h1><p>Quote a customer — add line items, tax and discount.</p></div>
        <a class="btn" href="{{ route('billing.estimates') }}"><x-icon name="arrow-left" class="icon-sm" />Back</a></div>
    <x-errors />
    <form wire:submit="save">
        <div class="inv-grid mb">
            <section class="card">
                <div class="card-head"><h2><x-icon name="briefcase" class="icon-sm" /> Customer &amp; dates</h2></div>
                <div class="form-grid">
                    <x-field label="Customer" name="customerId"><select wire:model="customerId"><option value="">Select customer</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
                    <x-field label="Reference" name="refNumber" hint="optional"><input wire:model="refNumber" placeholder="e.g. EST-001"></x-field>
                    <x-field label="Issue date" name="issueDate"><input type="date" wire:model="issueDate"></x-field>
                    <x-field label="Expiry date" name="expiryDate"><input type="date" wire:model="expiryDate"></x-field>
                </div>
            </section>
            <section class="card">
                <div class="card-head"><h2><x-icon name="list" class="icon-sm" /> Estimate summary</h2></div>
                <div class="inv-sum">
                    <div class="r"><span>Sub-total</span><strong>₹{{ number_format($subTotal + $discountTotal, 2) }}</strong></div>
                    <div class="r"><span>Discount</span><strong>₹{{ number_format($discountTotal, 2) }}</strong></div>
                    <div class="r"><span>Tax</span><strong>₹{{ number_format($grandTotal - $subTotal, 2) }}</strong></div>
                    <div class="r tot"><span>Total amount</span><strong>₹{{ number_format($grandTotal, 2) }}</strong></div>
                </div>
            </section>
        </div>

        <section class="card mb">
            <div class="card-head"><h2><x-icon name="receipt" class="icon-sm" /> Items</h2></div>
            <div class="table-wrap"><table class="table bill-items">
                <thead><tr><th>#</th><th style="min-width:220px">Item / description</th><th class="num">Qty</th><th class="num">Price (₹)</th><th class="num">Discount (₹)</th><th>Tax</th><th class="num">Amount (₹)</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($items as $i => $it)
                    <tr wire:key="item-{{ $i }}">
                        <td>{{ $i + 1 }}</td>
                        <td>
                            <select class="input" wire:model.live="items.{{ $i }}.product_id" aria-label="Product"><option value="">— Select item —</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                            <input class="input" wire:model="items.{{ $i }}.name" placeholder="Item name" aria-label="Item name" style="margin-top:4px">
                            @error('items.'.$i.'.name')<span class="error">{{ $message }}</span>@enderror
                            <input class="input" wire:model="items.{{ $i }}.description" placeholder="Description (optional)" aria-label="Description" style="margin-top:4px">
                        </td>
                        <td class="num"><input class="input" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.quantity" style="max-width:72px" aria-label="Quantity"></td>
                        <td class="num"><input class="input" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.price" style="max-width:100px" aria-label="Price"></td>
                        <td class="num"><input class="input" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.discount" style="max-width:96px" aria-label="Discount"></td>
                        <td><select class="input" wire:model.live="items.{{ $i }}.tax_id" aria-label="Tax"><option value="">None</option>@foreach($taxes as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ (float) $t->rate }}%)</option>@endforeach</select></td>
                        <td class="num">{{ number_format($this->lineTotal($it), 2) }}</td>
                        <td><button type="button" class="btn btn-sm btn-danger" wire:click="removeItem({{ $i }})" aria-label="Remove"><x-icon name="trash" class="icon-sm" /></button></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="toolbar mt"><button type="button" class="btn btn-sm" wire:click="addItem"><x-icon name="plus" class="icon-sm" />Add item</button></div>
        </section>

        <div class="two-col mb">
            <section class="card"><div class="card-head"><h2><x-icon name="note" class="icon-sm" /> Notes</h2></div>
                <textarea wire:model="notes" rows="4" placeholder="Add internal notes (optional)…"></textarea></section>
            <section class="card"><div class="card-head"><h2><x-icon name="file" class="icon-sm" /> Terms &amp; conditions</h2></div>
                <textarea wire:model="terms" rows="4" placeholder="Add terms and conditions to show on the estimate…"></textarea></section>
        </div>

        <div class="form-actions" style="display:flex;justify-content:space-between;gap:10px">
            <a class="btn" href="{{ route('billing.estimates') }}">Cancel</a>
            <div class="row-actions">
                <button type="submit" class="btn" wire:loading.attr="disabled">Save as draft</button>
                <button type="button" class="btn btn-primary" wire:click="save(true)" wire:loading.attr="disabled">{{ $estimateId ? 'Save estimate' : 'Create estimate' }}</button>
            </div>
        </div>
    </form>
</div>
