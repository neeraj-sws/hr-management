<div>
    @include('billing._ui')
    @assets
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
    @endassets
    @php
        $customer = $estimate->customer;
        $converted = $estimate->status === 'Converted';
        $pubUrl = $estimate->status !== 'Draft' ? app(\App\Services\InvoiceActions::class)->estimateUrl($estimate) : null;
        $pct = fn (float $r) => rtrim(rtrim(number_format($r, 2), '0'), '.').'%';
        $discount = $estimate->items->sum(fn ($i) => (float) $i->discount);
        $gstin = \App\Services\BillingService::cleanGstin($customer?->tax_number);
    @endphp
    <div class="page-head"><div><h1>Proposal Detail</h1>
        <p><a href="{{ route('billing.estimates') }}">Proposal</a> <span class="inv-dot">›</span> {{ $estimate->number() }} <span class="inv-dot">·</span> {{ $customer?->name ?? '—' }}</p></div></div>
    <x-errors />

    {{-- Same as the CMS proposal page: Create → Send → Status (with a status picker), then the proposal itself. --}}
    <section class="card mb cms-steps">
        <div class="cms-step">
            <div class="cms-step-ic" style="color:#0d6efd"><x-icon name="plus" /></div>
            <h6 style="color:#0d6efd">Create Proposal</h6>
            <p><x-icon name="clock" class="icon-sm" />Created on {{ \App\Services\BillingService::date($estimate->issue_date) }}</p>
            <a class="btn btn-sm cms-b-primary" href="{{ route('billing.estimates.edit', $estimate) }}"><x-icon name="edit" class="icon-sm" />Edit</a>
        </div>
        <div class="cms-step">
            <div class="cms-step-ic" style="color:#ffa21d"><x-icon name="mail" /></div>
            <h6 style="color:#ffa21d">Send Proposal</h6>
            <p>{{ $estimate->status === 'Draft' ? 'Status : Not Sent' : 'Sent' }}</p>
            @unless($converted)<button class="btn btn-sm cms-b-warning" wire:click="openEmail"><x-icon name="send" class="icon-sm" />{{ $estimate->status === 'Draft' ? 'Send' : 'Resend' }}</button>@endunless
        </div>
        <div class="cms-step">
            <div class="cms-step-ic" style="color:#3ec9d6"><x-icon name="currency" /></div>
            <h6 style="color:#3ec9d6">Proposal Status</h6>
            <p><span class="cms-pill {{ billing_badge_class($estimate->status) }}" style="font-size:11px;padding:2px 10px;min-width:0">{{ $estimate->status }}</span></p>
            @unless($converted)
                <select style="width:auto;display:inline-block" wire:change="setStatus($event.target.value)" aria-label="Change status">
                    @foreach(['Draft', 'Sent', 'Accepted', 'Declined'] as $st)<option value="{{ $st }}" @selected($estimate->status === $st)>{{ $st }}</option>@endforeach
                </select>
            @else<a class="btn btn-sm cms-b-info" href="{{ route('billing.invoices.show', $estimate->converted_invoice_id) }}">View invoice</a>@endunless
        </div>
    </section>

    <div class="cms-btnrow mb">
        <a class="btn btn-sm cms-b-primary" href="{{ route('billing.estimates.print', ['estimate' => $estimate, 'download' => 1]) }}" target="_blank">Download</a>
        @if($pubUrl)<button type="button" class="btn btn-sm cms-b-primary" x-on:click="navigator.clipboard.writeText(@js($pubUrl)).then(() => window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Proposal link copied — the customer can open it without signing in.', type: 'success' } })))">Copy link</button>@endif
        <button type="button" class="btn btn-sm cms-b-primary" wire:click="duplicate" wire:confirm="Make a copy of this proposal as a new Draft?">Duplicate</button>
        @unless($converted)<button type="button" class="btn btn-sm cms-b-primary" wire:click="convert" wire:confirm="Create an invoice from this proposal?">Convert to invoice</button>@endunless
        <button type="button" class="btn btn-sm btn-danger" wire:click="delete" wire:confirm="Proposal {{ $estimate->number() }} — delete?">Delete</button>
    </div>

    <section class="card mb cms-inv">
        <div class="cms-inv-head"><h4>Proposal</h4><h4>{{ $estimate->number() }}</h4></div>
        <div class="cms-inv-dates">
            <div><strong>Issue Date :</strong><br>{{ \App\Services\BillingService::date($estimate->issue_date) }}</div>
            @if($estimate->expiry_date)<div><strong>Expiry Date :</strong><br>{{ \App\Services\BillingService::date($estimate->expiry_date) }}</div>@endif
        </div>
        <div class="cms-inv-parties">
            <div class="small">
                <strong>Billed To :</strong><br>
                {{ $customer?->billing_name ?: $customer?->name }}<br>
                @if($customer?->billing_address){{ $customer->billing_address }}<br>@endif
                @if($customer?->billing_city){{ $customer->billing_city }}<br>@endif
                @if($customer?->billing_state || $customer?->billing_zip){{ collect([$customer?->billing_state, $customer?->billing_zip])->filter()->join(', ') }}<br>@endif
                @if($customer?->billing_country){{ $customer->billing_country }}<br>@endif
                @if($customer?->billing_phone ?: $customer?->contact){{ $customer->billing_phone ?: $customer->contact }}<br>@endif
                @if($gstin)<strong>Tax Number : </strong>{{ $gstin }}@endif
            </div>
            @if($pubUrl)<div class="cms-qr" wire:ignore x-data x-init="(function draw(n){ if (typeof qrcode !== 'function') { if (n < 40) setTimeout(() => draw(n + 1), 150); return; } const q = qrcode(0, 'M'); q.addData(@js($pubUrl)); q.make(); $el.innerHTML = q.createSvgTag({ cellSize: 3, margin: 0 }); })(0)"></div>@endif
        </div>
        <div class="cms-inv-status"><div class="small"><strong>Status :</strong><br><span class="cms-pill {{ billing_badge_class($estimate->status) }}" style="font-size:11px;padding:2px 8px;min-width:0">{{ $estimate->status }}</span></div></div>

        <div class="cms-inv-prod"><div style="font-weight:600">Product Summary</div><small class="muted">All items here cannot be deleted.</small></div>
        <div class="table-wrap"><table class="table cms-inv-table">
            <thead><tr><th>#</th><th>Product</th><th>Quantity</th><th>Rate</th><th>Discount</th><th>Tax</th><th>Description</th><th class="cms-r">Price<br><small style="color:#ff3a6e;font-weight:700">after tax &amp; discount</small></th></tr></thead>
            <tbody>
            @forelse($estimate->items as $it)
                <tr><td>{{ $loop->iteration }}</td><td>{{ $it->name }}</td><td>{{ (float) $it->quantity }}</td><td>Rs {{ number_format($it->price, 2) }}</td><td>Rs {{ number_format($it->discount, 2) }}</td>
                    <td>@if($it->tax){{ $it->tax->name }} ({{ $pct((float) $it->tax->rate) }}) Rs {{ number_format($it->lineTax(), 2) }}@else - @endif</td>
                    <td style="white-space:normal">{{ $it->description }}</td><td class="cms-r">Rs {{ number_format($it->lineSubtotal() + $it->lineTax(), 2) }}</td></tr>
            @empty<tr><td colspan="8" class="muted">No items</td></tr>@endforelse
            </tbody>
            <tfoot>
                <tr class="cms-tot-row"><td></td><td><b>Total</b></td><td><b>{{ (float) $estimate->items->sum('quantity') }}</b></td><td><b>Rs {{ number_format($estimate->items->sum(fn ($i) => (float) $i->price * (float) $i->quantity), 2) }}</b></td><td><b>Rs {{ number_format($discount, 2) }}</b></td><td><b>Rs {{ number_format($estimate->totalTax(), 2) }}</b></td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td class="cms-r"><b>Sub Total</b></td><td class="cms-r">Rs {{ number_format($estimate->subTotal(), 2) }}</td></tr>
                <tr><td colspan="6"></td><td class="cms-r"><b>Discount</b></td><td class="cms-r">Rs {{ number_format($discount, 2) }}</td></tr>
                <tr><td colspan="6"></td><td class="cms-r" style="color:#0d6efd"><b>Total</b></td><td class="cms-r" style="color:#0d6efd">Rs {{ number_format($estimate->total(), 2) }}</td></tr>
            </tfoot>
        </table></div>
    </section>

    @if($emailOpen)
        <x-modal title="Send proposal">
            <form wire:submit="sendEmail"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="To (customer e-mail)" name="emailTo" full><input type="email" wire:model="emailTo" placeholder="customer@company.com"></x-field>
                <x-field label="Message (optional)" name="emailMessage" full hint="shown at the top of the e-mail"><textarea wire:model="emailMessage" rows="3"></textarea></x-field>
            </div><p class="small muted">The customer gets a link to the proposal — they can view / print it without signing in. Sending a Draft proposal marks it "Sent".</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('emailOpen', false)">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled"><x-icon name="mail" class="icon-sm" />Send e-mail</button></div></form>
        </x-modal>
    @endif
</div>
