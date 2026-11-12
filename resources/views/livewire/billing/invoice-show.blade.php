@php
    $customer = $invoice->customer;
    $state = $invoice->place_of_supply ?: $customer?->billing_state;
    $gstin = \App\Services\BillingService::cleanGstin($customer?->tax_number);
    $initials = collect(preg_split('/\s+/', trim((string) $customer?->name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('') ?: '—';
    $statusTone = ['Paid' => 'green', 'Partially Paid' => 'amber', 'Unpaid' => 'red', 'Sent' => 'blue', 'Draft' => 'gray'][$invoice->status] ?? 'gray';
    $due = max(0, $invoice->due());
    $lastPaid = $invoice->payments->max('date');
    $discount = $invoice->items->sum(fn ($i) => (float) $i->discount);
    $pct = fn (float $r) => rtrim(rtrim(number_format($r, 2), '0'), '.').'%';
    $rupee = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp
@assets
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
@endassets
<div class="inv-page">
    @include('billing._ui')
    <div class="page-head inv-head">
        <div>
            <div class="inv-title"><h1>Invoice Detail</h1><x-badge :tone="$statusTone" class="inv-status-badge">@if($invoice->status === 'Paid')<x-icon name="check-circle" class="icon-sm" />@endif{{ $invoice->status }}</x-badge></div>
            <p><a href="{{ route('billing.invoices') }}">Invoice</a> <span class="inv-dot">›</span> #{{ ltrim($invoice->number(), '#') }} <span class="inv-dot">·</span> {{ $customer?->name ?? '—' }} <span class="inv-dot">·</span> Created on {{ $invoice->created_at?->format('d M Y, h:i A') ?? \App\Services\BillingService::date($invoice->issue_date) }}</p>
        </div>
        <div class="row-actions inv-actions">
            @if($invoice->status === 'Draft')<button class="btn" wire:click="markSent"><x-icon name="check" class="icon-sm" />Mark as sent</button>@endif
            <a class="btn" href="{{ route('billing.invoices.print', $invoice) }}" target="_blank"><x-icon name="printer" class="icon-sm" />Print</a>
            <a class="btn" href="{{ route('billing.invoices.edit', $invoice) }}"><x-icon name="edit" class="icon-sm" />Edit</a>
            @if($invoice->due() > 0)<button class="btn btn-primary" wire:click="openPayment"><x-icon name="plus" class="icon-sm" />Record payment</button>@endif
            <details class="menu inv-more">
                <summary class="btn btn-icon" aria-label="More actions"><x-icon name="more" class="icon-sm" /></summary>
                <div class="menu-panel inv-more-panel">
                    @if($publicUrl)<button type="button" class="menu-item" x-on:click="navigator.clipboard.writeText(@js($publicUrl)).then(() => window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Invoice link copied — customer bina login ke dekh sakta hai.', type: 'success' } })))"><x-icon name="external" class="icon-sm" />Copy link</button>
                    <a class="menu-item" href="{{ $publicUrl }}" target="_blank"><x-icon name="eye" class="icon-sm" />Customer view</a>@endif
                    <button type="button" class="menu-item" wire:click="duplicate" wire:confirm="Is invoice ki copy banake nayi Draft invoice kholein?"><x-icon name="layers" class="icon-sm" />Copy invoice (duplicate)</button>
                    <button type="button" class="menu-item" style="color:var(--red)" wire:click="delete" wire:confirm="Invoice {{ $invoice->number() }} delete karein? Ye wapas nahi aayegi."><x-icon name="trash" class="icon-sm" />Delete invoice</button>
                    <a class="menu-item" href="{{ route('billing.invoices.print', $invoice) }}" target="_blank"><x-icon name="printer" class="icon-sm" />Print / PDF</a>
                    <a class="menu-item" href="{{ route('billing.credit-notes') }}"><x-icon name="receipt" class="icon-sm" />Credit notes</a>
                    <a class="menu-item" href="{{ route('billing.customers') }}"><x-icon name="users" class="icon-sm" />Customers</a>
                    <a class="menu-item" href="{{ route('billing.invoices') }}"><x-icon name="arrow-left" class="icon-sm" />All invoices</a>
                </div>
            </details>
        </div>
    </div>
    <x-errors />

    @php($stepDone = ['create' => true, 'send' => $invoice->status !== 'Draft', 'paid' => $due <= 0 && $invoice->status !== 'Draft'])
    {{-- Same as the CMS invoice page: three steps, the action buttons, then the invoice itself. --}}
    <section class="card mb cms-steps">
        <div class="cms-step">
            <div class="cms-step-ic" style="color:#0d6efd"><x-icon name="plus" /></div>
            <h6 style="color:#0d6efd">Create Invoice</h6>
            <p><x-icon name="clock" class="icon-sm" />Created on {{ \App\Services\BillingService::date($invoice->issue_date) }}</p>
            <a class="btn btn-sm cms-b-primary" href="{{ route('billing.invoices.edit', $invoice) }}"><x-icon name="edit" class="icon-sm" />Edit</a>
        </div>
        <div class="cms-step">
            <div class="cms-step-ic" style="color:#ffa21d"><x-icon name="mail" /></div>
            <h6 style="color:#ffa21d">Send Invoice</h6>
            <p>@if($invoice->send_date)<x-icon name="clock" class="icon-sm" />Sent on {{ \App\Services\BillingService::date($invoice->send_date) }}@elseif($stepDone['send'])Sent @else Status : Not Sent @endif</p>
            @if($invoice->status === 'Draft')<button class="btn btn-sm cms-b-warning" wire:click="markSent"><x-icon name="send" class="icon-sm" />Mark Sent</button>@endif
        </div>
        <div class="cms-step">
            <div class="cms-step-ic" style="color:#3ec9d6"><x-icon name="currency" /></div>
            <h6 style="color:#3ec9d6">Get Paid</h6>
            <p>Status : {{ $stepDone['paid'] ? 'Paid'.($lastPaid ? ' on '.\App\Services\BillingService::date($lastPaid) : '') : ($invoice->paidAmount() > 0 ? 'Partially paid' : 'Awaiting payment') }}</p>
            @if($invoice->status !== 'Draft' && $invoice->due() > 0)<button class="btn btn-sm cms-b-info" wire:click="openPayment"><x-icon name="receipt" class="icon-sm" />Add Payment</button>@endif
        </div>
    </section>

    <div class="cms-btnrow mb">
        @if($invoice->due() > 0)<button class="btn btn-sm cms-b-primary" wire:click="openCredit">Add Credit Note</button>@endif
        @if($invoice->status !== 'Draft' && $invoice->due() > 0)<button class="btn btn-sm cms-b-primary" wire:click="openEmail('reminder')">Receipt Reminder</button>@endif
        <button class="btn btn-sm cms-b-primary" wire:click="openEmail('send')">{{ $invoice->status === 'Draft' ? 'Send Invoice' : 'Resend Invoice' }}</button>
        <button type="button" class="btn btn-sm cms-b-primary" x-data x-on:click="(() => { const b = $el, t = b.innerHTML; document.getElementById('sws-pdf-frame')?.remove(); const f = document.createElement('iframe'); f.id = 'sws-pdf-frame'; f.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;height:1300px;border:0'; f.src = @js(route('billing.invoices.print', ['invoice' => $invoice, 'download' => 1])); document.body.appendChild(f); b.disabled = true; b.innerHTML = 'Downloading…'; setTimeout(() => { b.disabled = false; b.innerHTML = t; }, 6000); })()">Download</button>
    </div>

    <section class="card mb cms-inv">
        <div class="cms-inv-head"><h4>Invoice</h4><h4>#{{ ltrim($invoice->number(), '#') }}</h4></div>
        <div class="cms-inv-dates">
            <div><strong>Issue Date :</strong><br>{{ \App\Services\BillingService::date($invoice->issue_date) }}</div>
            <div><strong>Due Date :</strong><br>{{ \App\Services\BillingService::date($invoice->due_date) ?? '—' }}</div>
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
            @if($publicUrl)
                <div class="cms-qr" wire:ignore x-data x-init="(function draw(n){ if (typeof qrcode !== 'function') { if (n < 40) setTimeout(() => draw(n + 1), 150); return; } const q = qrcode(0, 'M'); q.addData(@js($publicUrl)); q.make(); $el.innerHTML = q.createSvgTag({ cellSize: 3, margin: 0 }); })(0)"></div>
            @endif
        </div>
        <div class="cms-inv-status">
            <div class="small"><strong>Status :</strong><br><span class="cms-pill {{ billing_badge_class($invoice->status) }}" style="font-size:11px;padding:2px 8px;min-width:0">{{ $invoice->status }}</span></div>
            <div class="small"><strong>Hours :</strong><br>{{ $invoice->hours !== null ? rtrim(rtrim(number_format((float) $invoice->hours, 2), '0'), '.') : '' }}</div>
        </div>

        <div class="cms-inv-prod">
            <div style="font-weight:600">Product Summary</div>
            <small class="muted">All items here cannot be deleted.</small>
        </div>
        <div class="table-wrap"><table class="table cms-inv-table">
            <thead><tr><th>#</th><th>Product</th><th>Quantity</th><th>Rate</th><th>Discount</th><th>Tax</th><th>Description</th><th class="cms-r">Price<br><small style="color:#ff3a6e;font-weight:700">after tax &amp; discount</small></th></tr></thead>
            <tbody>
            @forelse($invoice->items as $it)
                <tr><td>{{ $loop->iteration }}</td><td>{{ $it->name }}</td><td>{{ (float) $it->quantity }}</td><td>Rs {{ number_format($it->price, 2) }}</td><td>Rs {{ number_format($it->discount, 2) }}</td>
                    <td>@if($it->tax){{ $it->tax->name }} ({{ $pct((float) $it->tax->rate) }}) Rs {{ number_format($it->lineTax(), 2) }}@else - @endif</td>
                    <td style="white-space:normal">{{ $it->description }}</td><td class="cms-r">Rs {{ number_format($it->lineTotal(), 2) }}</td></tr>
            @empty<tr><td colspan="8" class="muted">No items</td></tr>@endforelse
            </tbody>
            <tfoot>
                <tr class="cms-tot-row"><td></td><td><b>Total</b></td><td><b>{{ (float) $invoice->items->sum('quantity') }}</b></td><td><b>Rs {{ number_format($invoice->items->sum(fn ($i) => (float) $i->price * (float) $i->quantity), 2) }}</b></td><td><b>Rs {{ number_format($discount, 2) }}</b></td><td><b>Rs {{ number_format(collect($taxes)->sum('total_tax'), 2) }}</b></td><td></td><td></td></tr>
                <tr><td colspan="6"></td><td class="cms-r"><b>Sub Total</b></td><td class="cms-r">Rs {{ number_format($invoice->subTotal(), 2) }}</td></tr>
                <tr><td colspan="6"></td><td class="cms-r"><b>Discount</b></td><td class="cms-r">Rs {{ number_format($discount, 2) }}</td></tr>
                @if(collect($taxes)->sum('total_tax') > 0)
                    @if($interState)<tr><td colspan="6"></td><td class="cms-r"><b>IGST</b></td><td class="cms-r">Rs {{ number_format(collect($taxes)->sum('igst'), 2) }}</td></tr>
                    @else<tr><td colspan="6"></td><td class="cms-r"><b>CGST</b></td><td class="cms-r">Rs {{ number_format(collect($taxes)->sum('cgst'), 2) }}</td></tr><tr><td colspan="6"></td><td class="cms-r"><b>SGST</b></td><td class="cms-r">Rs {{ number_format(collect($taxes)->sum('sgst'), 2) }}</td></tr>@endif
                @endif
                <tr><td colspan="6"></td><td class="cms-r" style="color:#0d6efd"><b>Total</b></td><td class="cms-r" style="color:#0d6efd">Rs {{ number_format($invoice->total(), 2) }}</td></tr>
                <tr><td colspan="6"></td><td class="cms-r"><b>Paid</b></td><td class="cms-r">Rs {{ number_format($invoice->paidAmount(), 2) }}</td></tr>
                <tr><td colspan="6"></td><td class="cms-r"><b>Credit Note</b></td><td class="cms-r">Rs {{ number_format($invoice->creditTotal(), 2) }}</td></tr>
                <tr><td colspan="6"></td><td class="cms-r"><b>Due</b></td><td class="cms-r">Rs {{ number_format($due, 2) }}</td></tr>
            </tfoot>
        </table></div>
        @if($invoice->notes)<div class="small" style="padding:12px 0 0;white-space:pre-line"><strong>Notes :</strong> {{ $invoice->notes }}</div>@endif
    </section>

    <section class="card inv-card mb">
        <div class="inv-card-head"><h2><x-icon name="credit-card" class="icon-sm" />Receipt Summary</h2>@if($invoice->due() > 0)<button class="btn btn-sm" wire:click="openPayment"><x-icon name="plus" class="icon-sm" />Record payment</button>@endif</div>
        <div class="table-wrap"><table class="table inv-table">
            <thead><tr><th>Payment receipt</th><th>Date</th><th class="num">Amount (₹)</th><th>Payment type</th><th>Account</th><th>Reference</th><th>Description</th><th>Receipt</th><th>Added by</th><th class="inv-act">Action</th></tr></thead>
            <tbody>
            @forelse($invoice->payments as $p)
                <tr><td>@if($p->receipt_path)<a class="btn btn-sm" href="{{ route('billing.receipt-file', ['payment', $p->id]) }}" target="_blank"><x-icon name="eye" class="icon-sm" />View</a>@else<span class="muted">—</span>@endif</td>
                    <td class="nowrap">{{ \App\Services\BillingService::date($p->date) }}</td><td class="num"><strong>{{ number_format($p->amount, 2) }}</strong></td>
                    <td>{{ $p->method ?: 'Manually' }}</td><td>{{ $p->account?->label() ?? '—' }}</td><td>{{ $p->reference ?: '—' }}</td><td style="white-space:normal">{{ $p->note ?: '—' }}</td>
                    <td><a class="btn btn-sm" href="{{ route('billing.payments.receipt', $p) }}" target="_blank" title="Print payment receipt"><x-icon name="printer" class="icon-sm" />R-{{ str_pad((string) $p->id, 5, '0', STR_PAD_LEFT) }}</a> <button type="button" class="btn btn-sm btn-icon" wire:click="openEmail('payment', {{ $p->id }})" title="E-mail this receipt to the customer" aria-label="E-mail receipt"><x-icon name="mail" class="icon-sm" /></button></td>
                    <td>{{ $p->recorder?->name ?? '—' }}@if($p->created_at)<small class="inv-desc">{{ $p->created_at->format('d M Y, h:i A') }}</small>@endif</td>
                    <td class="inv-act"><button class="btn btn-sm btn-danger btn-icon" wire:click="deletePayment({{ $p->id }})" wire:confirm="Remove this payment of {{ number_format($p->amount, 2) }}? The invoice will show it as due again." title="Remove payment" aria-label="Remove payment of {{ number_format($p->amount, 2) }}"><x-icon name="trash" class="icon-sm" /></button></td></tr>
            @empty<tr><td colspan="10"><x-empty title="No payments yet" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
    </section>

    <section class="card inv-card mb">
        <div class="inv-card-head"><h2><x-icon name="receipt" class="icon-sm" />Credit Note Summary</h2>@if($invoice->due() > 0)<button class="btn btn-sm" wire:click="openCredit"><x-icon name="plus" class="icon-sm" />Add credit note</button>@endif</div>
        <div class="table-wrap"><table class="table inv-table">
            <thead><tr><th class="inv-n">#</th><th>Date</th><th class="num">Amount (₹)</th><th>Description</th><th class="inv-act">Action</th></tr></thead>
            <tbody>
            @forelse($invoice->creditNotes as $cn)
                <tr><td class="inv-n">{{ $loop->iteration }}</td><td class="nowrap">{{ \App\Services\BillingService::date($cn->date) ?? '—' }}</td>
                    <td class="num"><strong>{{ number_format($cn->amount, 2) }}</strong></td><td>{{ $cn->description ?: '—' }}</td>
                    <td class="inv-act"><button class="btn btn-sm btn-icon" wire:click="openCredit({{ $cn->id }})" title="Edit" aria-label="Edit credit note"><x-icon name="edit" class="icon-sm" /></button><button class="btn btn-sm btn-danger btn-icon" wire:click="deleteCredit({{ $cn->id }})" wire:confirm="Credit note of {{ number_format($cn->amount, 2) }} remove karein?" title="Delete" aria-label="Delete credit note"><x-icon name="trash" class="icon-sm" /></button></td></tr>
            @empty<tr><td colspan="5"><x-empty title="No credit notes" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
    </section>

    <section class="card inv-card">
        <div class="inv-card-head"><h2><x-icon name="activity" class="icon-sm" />Activity Log</h2></div>
        <div class="table-wrap"><table class="table inv-table">
            <thead><tr><th>Date &amp; Time</th><th>Action</th><th>Details</th><th>By</th></tr></thead>
            <tbody>
            @forelse($activity as $a)
                <tr><td class="nowrap">{{ $a->at?->format('d M Y, h:i A') }}</td><td><x-badge :tone="$a->tone" class="inv-pill">{{ $a->label }}</x-badge></td><td class="inv-detail">{{ $a->detail }}</td><td>{{ $a->by }}</td></tr>
            @empty<tr><td colspan="4"><x-empty title="No activity yet" icon="activity" /></td></tr>@endforelse
            </tbody></table></div>
    </section>

    @if($emailKind)
        <x-modal :title="$emailKind === 'payment' ? 'E-mail payment receipt' : ($emailKind === 'reminder' ? 'Payment reminder' : ($invoice->status === 'Draft' ? 'Send invoice' : 'Resend invoice'))">
            <form wire:submit="sendEmail"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="To (customer e-mail)" name="emailTo" full><input type="email" wire:model="emailTo" placeholder="customer@company.com"></x-field>
                <x-field label="Message (optional)" name="emailMessage" full hint="mail me upar dikhega"><textarea wire:model="emailMessage" rows="3"></textarea></x-field>
            </div>
            <p class="small muted">@if($emailKind === 'payment')Customer ko payment milne ki receipt jayegi (amount, date, baaki due) aur invoice ka link.@else Mail me invoice ka link jayega — customer bina login ke dekh / print / PDF kar sakta hai.@if($emailKind === 'send' && $invoice->status === 'Draft') Bhejne par invoice "Sent" ho jayegi.@endif @endif</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('emailKind', null)">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled"><x-icon name="mail" class="icon-sm" />Send e-mail</button></div></form>
        </x-modal>
    @endif

    @if($showCredit)
        <x-modal :title="$creditId ? 'Edit credit note' : 'Add credit note'">
            <form wire:submit="saveCredit"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Amount (₹)" name="creditAmount" hint="due: ₹{{ number_format(max(0, $invoice->due()), 2) }}"><input type="number" step="0.01" min="0" wire:model="creditAmount"></x-field>
                <x-field label="Date" name="creditDate"><input type="date" wire:model="creditDate"></x-field>
                <x-field label="Description" name="creditDescription" full><input wire:model="creditDescription" placeholder="e.g. Discount on October hours"></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('showCredit', false)">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif

    @if($showPayment)
        <x-modal title="Add Payment">
            <form wire:submit="recordPayment"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Date" name="payDate"><input type="date" wire:model="payDate"></x-field>
                <x-field label="Amount" name="payAmount"><input type="number" step="0.01" wire:model="payAmount"></x-field>
                <x-field label="Account" name="payAccountId"><select wire:model="payAccountId"><option value="">Enter Account</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</select></x-field>
                <x-field label="Reference" name="payReference"><input wire:model="payReference" placeholder="Enter Reference"></x-field>
                <x-field label="Description" name="payNote" full><textarea wire:model="payNote" rows="2"></textarea></x-field>
                <x-field label="Payment receipt" name="payReceipt" hint="image ya PDF, 5 MB tak" full><input type="file" wire:model="payReceipt" accept=".jpg,.jpeg,.png,.webp,.pdf"><div wire:loading wire:target="payReceipt" class="small muted">Uploading…</div></x-field>
                <label class="check">@if($invoice->customer?->email)<input type="checkbox" wire:model="payEmail"> Customer ko receipt e-mail karo ({{ $invoice->customer->email }})@else<span class="small muted">Customer ka e-mail nahi hai — receipt e-mail nahi jayegi.</span>@endif</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('showPayment', false)">Cancel</button><button class="btn btn-primary">Add</button></div></form>
        </x-modal>
    @endif

</div>
