<div class="bill-form">
    <style>
        .bill-form { max-width: 1120px; }
        .bill-form td:first-child .input { max-width: 440px; }
    </style>
    <div class="page-head"><div><h1>{{ $estimateId ? 'Edit estimate' : 'New estimate' }}</h1><p>Quote a customer — add line items, tax and discount.</p></div>
        <a class="btn" href="{{ route('billing.estimates') }}">Back</a></div>
    <x-errors />
    <form wire:submit="save">
        <section class="card mb">
            <div class="form-grid">
                <x-field label="Customer" name="customerId"><select wire:model="customerId"><option value="">Select customer</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
                <x-field label="Issue date" name="issueDate"><input type="date" wire:model="issueDate"></x-field>
                <x-field label="Expiry date" name="expiryDate"><input type="date" wire:model="expiryDate"></x-field>
            </div>
        </section>

        <section class="card mb">
            <div class="card-head"><h2>Items</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th style="min-width:200px">Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Discount</th><th>Tax</th><th class="num">Amount</th><th></th></tr></thead>
                <tbody>
                @foreach($items as $i => $it)
                    <tr wire:key="item-{{ $i }}">
                        <td>
                            <select class="input" wire:model.live="items.{{ $i }}.product_id" aria-label="Product"><option value="">— custom —</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                            <input class="input" wire:model="items.{{ $i }}.name" placeholder="Item name" aria-label="Item name" style="margin-top:4px">
                            @error('items.'.$i.'.name')<span class="error">{{ $message }}</span>@enderror
                            <input class="input" wire:model="items.{{ $i }}.description" placeholder="Description (optional)" aria-label="Description" style="margin-top:4px">
                        </td>
                        <td class="num"><input class="input" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.quantity" style="max-width:80px" aria-label="Quantity"></td>
                        <td class="num"><input class="input" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.price" style="max-width:110px" aria-label="Price"></td>
                        <td class="num"><input class="input" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.discount" style="max-width:100px" aria-label="Discount"></td>
                        <td><select class="input" wire:model.live="items.{{ $i }}.tax_id" aria-label="Tax"><option value="">None</option>@foreach($taxes as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ (float) $t->rate }}%)</option>@endforeach</select></td>
                        <td class="num">{{ number_format($this->lineTotal($it), 2) }}</td>
                        <td><button type="button" class="btn btn-sm" wire:click="removeItem({{ $i }})" aria-label="Remove">&times;</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="toolbar mt"><button type="button" class="btn btn-sm" wire:click="addItem">+ Add item</button></div>
            <div class="stack" style="max-width:300px;margin-left:auto;margin-top:12px">
                <div class="list-row"><span>Sub-total</span><strong>{{ number_format($subTotal, 2) }}</strong></div>
                <div class="list-row"><span>Tax</span><strong>{{ number_format($grandTotal - $subTotal, 2) }}</strong></div>
                <div class="list-row"><span><strong>Total</strong></span><strong>{{ number_format($grandTotal, 2) }}</strong></div>
            </div>
        </section>

        <section class="card mb">
            <x-field label="Notes" name="notes"><textarea wire:model="notes" rows="2" placeholder="Shown on the estimate (optional)"></textarea></x-field>
        </section>

        <div class="form-actions"><a class="btn" href="{{ route('billing.estimates') }}">Cancel</a><button class="btn btn-primary" wire:loading.attr="disabled">Save estimate</button></div>
    </form>
</div>
