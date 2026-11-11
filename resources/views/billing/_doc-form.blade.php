{{--
    Shared layout of the invoice / proposal (estimate) / bill create & edit pages, like the old CMS:
    a "Bill to / Ship to" card with the dates and numbers next to it, then "Product & Services"
    (item, quantity, price, discount, tax, amount after tax & discount, description) and the totals.
    Expects: $party (customer / vendor model or null), $partyField (wire model name), $parties, $partyLabel,
    $fieldsView (view with the right-hand fields), $lineTotals, $items, $products, $taxes, $subTotal, $discountTotal, $grandTotal, $back, $saveLabel.
--}}
@php($rupee = fn ($v) => number_format((float) $v, 2))
<style>
    .doc-form .doc-top{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:24px;padding:22px 24px}
    @media(max-width:900px){.doc-form .doc-top{grid-template-columns:1fr}}
    .doc-form .doc-party{display:grid;grid-template-columns:1fr 1fr auto;gap:16px;align-items:start}
    .doc-form .doc-party h3{margin:0 0 8px;font-size:15px}
    .doc-form .doc-party p{margin:0;font-size:13px;line-height:1.8;color:var(--ink-2)}
    .doc-form .doc-remove{font-size:13px;color:var(--blue);background:none;border:0;cursor:pointer;padding:0}
    .doc-form .doc-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}
    .doc-form .doc-section-title{margin:22px 0 10px;font-size:17px;font-weight:600}
    .doc-form .doc-items-head{display:flex;justify-content:flex-end;padding:12px 16px}
    .doc-form table.doc-items{width:100%;border-collapse:collapse}
    .doc-form .doc-items th{background:var(--canvas);text-align:left;font-size:12px;letter-spacing:.03em;text-transform:uppercase;padding:14px 16px;border-bottom:1px solid var(--line-2)}
    .doc-form .doc-items th small{display:block;color:var(--red);font-size:10px}
    .doc-form .doc-items td{padding:12px 16px;vertical-align:top}
    .doc-form .doc-items tr.doc-desc td{padding-top:0;border-bottom:1px solid var(--line-2)}
    .doc-form .doc-addon{display:flex}.doc-form .doc-addon input{border-top-right-radius:0;border-bottom-right-radius:0;min-width:0}
    .doc-form .doc-addon span{display:flex;align-items:center;padding:0 12px;border:1px solid var(--line);border-left:0;border-radius:0 8px 8px 0;background:var(--canvas);font-size:13px}
    .doc-form .doc-amount{text-align:right;font-weight:600;white-space:nowrap;padding-top:22px!important}
    .doc-form .doc-totals td{padding:12px 16px;border-top:1px solid var(--line-2)}
    .doc-form .doc-totals td{white-space:nowrap}
    .doc-form .doc-totals .lbl{font-weight:600;text-align:left}
    .doc-form .doc-totals .val{text-align:right;font-weight:600}
    .doc-form .doc-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
</style>
<section class="card">
    <div class="doc-top">
        <div>
            @if($party)
                <div class="doc-party">
                    <div><h3>Bill to</h3><p>
                        {{ $party->billing_name ?: $party->name }}<br>
                        @if($party->billing_phone ?: $party->contact){{ $party->billing_phone ?: $party->contact }}<br>@endif
                        @if($party->billing_address){{ $party->billing_address }}<br>@endif
                        @if($party->billing_city || $party->billing_state){{ collect([$party->billing_city, $party->billing_state, $party->billing_country])->filter()->join(' , ') }}.<br>@endif
                        {{ $party->billing_zip }}
                        @if($party->tax_number)<br>GSTIN: {{ \App\Services\BillingService::cleanGstin($party->tax_number) }}@endif
                    </p></div>
                    <div><h3>Ship to</h3><p>
                        @if(isset($party->shipping_address) && trim((string) $party->shipping_address) !== '')
                            {{ $party->shipping_name ?: $party->billing_name ?: $party->name }}<br>{{ $party->shipping_address }}<br>{{ collect([$party->shipping_city, $party->shipping_state, $party->shipping_country])->filter()->join(' , ') }}<br>{{ $party->shipping_zip }}
                        @else - @endif
                    </p></div>
                    <button type="button" class="doc-remove" wire:click="$set('{{ $partyField }}', null)">Remove</button>
                </div>
            @else
                <x-field :label="$partyLabel" :name="$partyField"><select wire:model.live="{{ $partyField }}"><option value="">Select {{ strtolower($partyLabel) }}</option>@foreach($parties as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></x-field>
            @endif
        </div>
        <div class="doc-fields">@include($fieldsView)</div>
    </div>
</section>

<div class="doc-section-title">Product &amp; Services</div>
<section class="card">
    @error('items')<p class="error" style="padding:10px 16px 0">{{ $message }}</p>@enderror
    <div class="doc-items-head"><button type="button" class="btn btn-primary" wire:click="addItem"><x-icon name="plus" class="icon-sm" />Add item</button></div>
    <div class="table-wrap"><table class="doc-items">
        <thead><tr><th style="width:30%">Items</th><th>Quantity</th><th>Price</th><th>Discount</th><th>Tax (%)</th><th style="text-align:right">Amount<small>after tax &amp; discount</small></th><th></th></tr></thead>
        <tbody>
        @foreach($items as $i => $it)
            <tr wire:key="doc-item-{{ $i }}">
                <td>
                    <select wire:model.live="items.{{ $i }}.product_id" aria-label="Item"><option value="">--</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                    @if(! $it['product_id'])<input wire:model="items.{{ $i }}.name" placeholder="Item name (custom)" aria-label="Item name" style="margin-top:6px">@endif
                    @error('items.'.$i.'.name')<span class="error">{{ $message }}</span>@enderror
                </td>
                <td><input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.quantity" placeholder="Qty" aria-label="Quantity"></td>
                <td><div class="doc-addon"><input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.price" placeholder="Price" aria-label="Price"><span>Rs</span></div></td>
                <td><div class="doc-addon"><input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.discount" placeholder="Discount" aria-label="Discount"><span>Rs</span></div></td>
                <td><select wire:model.live="items.{{ $i }}.tax_id" aria-label="Tax"><option value="">—</option>@foreach($taxes as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ (float) $t->rate }}%)</option>@endforeach</select></td>
                <td class="doc-amount">{{ $rupee($lineTotals[$i] ?? 0) }}</td>
                <td><button type="button" class="btn btn-sm btn-icon btn-danger" wire:click="removeItem({{ $i }})" title="Remove item" aria-label="Remove item {{ $loop->iteration }}"><x-icon name="trash" class="icon-sm" /></button></td>
            </tr>
            <tr class="doc-desc" wire:key="doc-desc-{{ $i }}"><td colspan="3"><textarea wire:model="items.{{ $i }}.description" rows="2" placeholder="Description" aria-label="Description"></textarea></td><td colspan="4"></td></tr>
        @endforeach
        </tbody>
        <tbody class="doc-totals">
            <tr><td colspan="4"></td><td class="lbl">Sub Total (Rs)</td><td class="val">{{ $rupee($subTotal + $discountTotal) }}</td><td></td></tr>
            <tr><td colspan="4"></td><td class="lbl">Discount (Rs)</td><td class="val">{{ $rupee($discountTotal) }}</td><td></td></tr>
            <tr><td colspan="4"></td><td class="lbl">Tax (Rs)</td><td class="val">{{ $rupee($grandTotal - $subTotal) }}</td><td></td></tr>
            <tr><td colspan="4"></td><td class="lbl">Total Amount (Rs)</td><td class="val">{{ $rupee($grandTotal) }}</td><td></td></tr>
        </tbody>
    </table></div>
</section>

<div class="doc-foot">
    <a class="btn" href="{{ $back }}">Cancel</a>
    <button class="btn btn-primary" wire:loading.attr="disabled">{{ $saveLabel }}</button>
</div>
