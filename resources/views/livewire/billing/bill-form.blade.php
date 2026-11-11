@php
    $rupee = fn ($v) => '₹'.number_format((float) $v, 2);
    $back = $billId ? route('billing.bills.show', $billId) : route('billing.bills');
@endphp
<div class="inv-page inv-form">
    <div class="page-head inv-head">
        <div class="inv-form-title">
            <span class="inv-form-icon"><x-icon name="receipt" /></span>
            <div><h1>{{ $billId ? 'Edit Bill' : 'New Bill' }}</h1><p>A purchase bill from a supplier — items, tax and discount.</p></div>
        </div>
        <a class="btn" href="{{ $back }}"><x-icon name="arrow-left" class="icon-sm" />Back</a>
    </div>
    <x-errors />
    <form wire:submit="save">
        <div class="inv-grid">
            <section class="card inv-card">
                <div class="inv-card-head"><h2><x-icon name="users" class="icon-sm" />Supplier &amp; dates</h2></div>
                <div class="form-grid inv-form-body">
                    <x-field label="Supplier" name="vendorId" :hint="$vendors->isEmpty() ? 'pehle Purchases → Suppliers me supplier add karo' : null"><select wire:model="vendorId"><option value="">Select supplier</option>@foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach</select></x-field>
                    <x-field label="Category" name="categoryId" hint="expense category"><select wire:model="categoryId"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
                    <x-field label="Bill date" name="billDate"><input type="date" wire:model="billDate"></x-field>
                    <x-field label="Due date" name="dueDate"><input type="date" wire:model="dueDate"></x-field>
                    <x-field label="Supplier's bill no." name="refNumber" hint="optional"><input wire:model="refNumber" placeholder="e.g. INV-2231"></x-field>
                </div>
            </section>
            <aside class="card inv-card inv-summary">
                <div class="inv-card-head"><h2><x-icon name="list" class="icon-sm" />Bill summary</h2></div>
                <dl class="inv-sum">
                    <div><dt>Sub-total</dt><dd>{{ $rupee($subTotal) }}</dd></div>
                    <div><dt>Discount</dt><dd>{{ $rupee($discountTotal) }}</dd></div>
                    <div><dt>Tax</dt><dd>{{ $rupee($grandTotal - $subTotal) }}</dd></div>
                </dl>
                <dl class="inv-total inv-form-total"><div><dt>Total Amount</dt><dd>{{ $rupee($grandTotal) }}</dd></div></dl>
            </aside>
        </div>

        <section class="card inv-card mb">
            <div class="inv-card-head"><h2><x-icon name="list" class="icon-sm" />Items</h2></div>
            @error('items')<p class="error inv-form-error">{{ $message }}</p>@enderror
            <div class="table-wrap"><table class="table inv-table inv-edit-table">
                <thead><tr><th class="inv-n">#</th><th class="inv-col-item">Item / Description</th><th>Qty</th><th>Price (₹)</th><th>Discount (₹)</th><th>Tax</th><th>Amount (₹)</th><th class="inv-act">Action</th></tr></thead>
                <tbody>
                @foreach($items as $i => $it)
                    <tr wire:key="bill-item-{{ $i }}">
                        <td class="inv-n">{{ $loop->iteration }}</td>
                        <td class="inv-col-item">
                            <select wire:model.live="items.{{ $i }}.product_id" aria-label="Product"><option value="">— custom item —</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                            <input wire:model="items.{{ $i }}.name" placeholder="Item name" aria-label="Item name">
                            @error('items.'.$i.'.name')<span class="error">{{ $message }}</span>@enderror
                            <input wire:model="items.{{ $i }}.description" placeholder="Description (optional)" aria-label="Description">
                        </td>
                        <td><input class="inv-w-qty" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.quantity" aria-label="Quantity"></td>
                        <td><input class="inv-w-money" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.price" aria-label="Price"></td>
                        <td><input class="inv-w-money" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.discount" aria-label="Discount"></td>
                        <td><select class="inv-w-tax" wire:model.live="items.{{ $i }}.tax_id" aria-label="Tax"><option value="">None</option>@foreach($taxes as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ (float) $t->rate }}%)</option>@endforeach</select></td>
                        <td><span class="inv-amount-box">{{ number_format($this->lineTotal($it), 2) }}</span></td>
                        <td class="inv-act"><button type="button" class="btn btn-sm btn-danger btn-icon" wire:click="removeItem({{ $i }})" title="Remove item" aria-label="Remove item {{ $loop->iteration }}"><x-icon name="trash" class="icon-sm" /></button></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="inv-form-add"><button type="button" class="btn" wire:click="addItem"><x-icon name="plus" class="icon-sm" />Add Item</button></div>
        </section>

        <section class="card inv-card mb">
            <div class="inv-card-head"><h2><x-icon name="notes" class="icon-sm" />Notes</h2></div>
            <div class="inv-form-body"><textarea wire:model="notes" rows="3" placeholder="Optional" aria-label="Notes"></textarea></div>
        </section>

        <div class="inv-form-foot">
            <a class="btn" href="{{ $back }}">Cancel</a>
            <button class="btn btn-primary" wire:loading.attr="disabled"><x-icon name="check" class="icon-sm" />Save Bill</button>
        </div>
    </form>
</div>
