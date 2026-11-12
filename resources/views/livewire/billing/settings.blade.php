<div>
    <div class="page-head"><div><h1>Billing settings</h1><p>Your company details on every tax invoice and estimate. CGST + SGST is used when the customer's state (place of supply) is your state, IGST when it is another state.</p></div></div>
    <form wire:submit="save">
        <section class="card mb">
            <div class="card-head"><h2>Company</h2></div>
            <div class="card-body"><x-errors /><div class="form-grid">
                @foreach(['name', 'gstin', 'pan', 'address', 'city', 'state', 'state_code', 'pin', 'email', 'phone', 'website'] as $f)
                    <x-field :label="$fields[$f]" :name="'company.'.$f" :full="$f === 'address'"><input wire:model="company.{{ $f }}" @if(in_array($f, ['gstin', 'pan'], true)) style="text-transform:uppercase" @endif></x-field>
                @endforeach
            </div></div>
        </section>
        <section class="card mb">
            <div class="card-head"><h2>Payment details</h2></div>
            <div class="card-body"><div class="form-grid">
                @foreach(['bank_name', 'account_name', 'account_number', 'ifsc', 'upi', 'due_days'] as $f)
                    <x-field :label="$fields[$f]" :name="'company.'.$f"><input wire:model="company.{{ $f }}" @if($f === 'due_days') type="number" min="0" @endif @if($f === 'ifsc') style="text-transform:uppercase" @endif></x-field>
                @endforeach
            </div></div>
        </section>
        <section class="card mb">
            <div class="card-head"><h2>Logo, colours &amp; numbering</h2></div>
            <div class="card-body"><div class="form-grid">
                <x-field label="Logo" name="logoFile" hint="PNG / JPG, up to 300 KB — shown on invoices, proposals, receipts and the customer link">
                    <input type="file" wire:model="logoFile" accept=".png,.jpg,.jpeg,.webp">
                    <div wire:loading wire:target="logoFile" class="small muted">Uploading…</div>
                    @if($logoFile && in_array(strtolower($logoFile->getClientOriginalExtension()), ['png', 'jpg', 'jpeg', 'webp'], true))<img src="{{ $logoFile->temporaryUrl() }}" alt="New logo" style="max-height:56px;margin-top:8px">
                    @elseif($logoFile)<div class="small" style="color:var(--red)">Only PNG / JPG / WEBP images are allowed.</div>
                    @elseif($hasLogo && ! $dropLogo)<div style="display:flex;align-items:center;gap:10px;margin-top:8px"><img src="{{ \App\Services\BillingService::settings()['logo'] }}" alt="Current logo" style="max-height:56px"><button type="button" class="btn btn-sm" wire:click="removeLogo">Remove</button></div>
                    @elseif($dropLogo)<div class="small muted" style="margin-top:6px">The logo is removed when you save.</div>@endif
                </x-field>
                <x-field label="Invoice colour" name="company.invoice_color"><input type="color" wire:model="company.invoice_color" value="{{ $company['invoice_color'] ?: '#1f3a5f' }}"></x-field>
                <x-field label="Estimate colour" name="company.estimate_color"><input type="color" wire:model="company.estimate_color" value="{{ $company['estimate_color'] ?: '#1f3a5f' }}"></x-field>
                <x-field label="QR code on invoice" name="company.show_qr" hint="customer link ka QR — scan karke invoice khule"><select wire:model="company.show_qr"><option value="">Show</option><option value="off">Hide</option></select></x-field>
                <x-field label="Date format" name="company.date_format"><select wire:model="company.date_format"><option value="">May 1, 2026 (default, like the CMS)</option>@foreach(\App\Services\BillingService::DATE_FORMATS as $f => $example)<option value="{{ $f }}">{{ $example }}</option>@endforeach</select></x-field>
                <x-field label="Invoice prefix" name="company.invoice_prefix" hint="e.g. SWS → SWS/2026-27/00001"><input wire:model="company.invoice_prefix" placeholder="{{ config('billing.prefix') }}"></x-field>
                <x-field label="Estimate prefix" name="company.estimate_prefix"><input wire:model="company.estimate_prefix" placeholder="#PROP"></x-field>
                <x-field label="Customer prefix" name="company.customer_prefix" hint="e.g. #CUST → #CUST00024"><input wire:model="company.customer_prefix" placeholder="#CUST"></x-field>
                <x-field label="Vendor prefix" name="company.vendor_prefix"><input wire:model="company.vendor_prefix" placeholder="#VEND"></x-field>
                <x-field label="Bill prefix" name="company.bill_prefix"><input wire:model="company.bill_prefix" placeholder="BILL"></x-field>
            </div></div>
        </section>
        <section class="card mb">
            <div class="card-head"><h2>Invoice e-mails</h2></div>
            <div class="card-body"><div class="form-grid">
                <x-field label="Send invoices from (e-mail)" name="company.mail_from_address" hint="e.g. accounts@sohamsolution.com · leave empty to use the normal HRMS mail address"><input type="email" wire:model="company.mail_from_address"></x-field>
                <x-field label="Sender name" name="company.mail_from_name"><input wire:model="company.mail_from_name" placeholder="Soham Web Solution"></x-field>
            </div>
            <p class="small muted">The mail server (SMTP) stays the one HRMS uses — this address must be allowed to send from that server (Office365 needs the "Send As" permission).</p></div>
        </section>
        <section class="card mb">
            <div class="card-head"><h2>Invoice footer</h2></div>
            <div class="card-body"><div class="form-grid">
                <x-field :label="$fields['terms']" name="company.terms" full><textarea wire:model="company.terms" rows="3" placeholder="e.g. Payment within 7 days. Interest @ 18% p.a. on late payment."></textarea></x-field>
                <x-field :label="$fields['footer_title']" name="company.footer_title"><input wire:model="company.footer_title"></x-field>
                <x-field :label="$fields['footer_notes']" name="company.footer_notes" full hint="printed at the bottom of the invoice PDF (like the CMS) — &lt;b&gt;bold&lt;/b&gt; and &lt;br&gt; work"><textarea wire:model="company.footer_notes" rows="5" placeholder="<b>Bank Details:</b><br>Bank Name - HDFC Bank ..."></textarea></x-field>
                <x-field :label="$fields['signatory']" name="company.signatory" hint="e.g. Neeraj Malakar — printed under the signature with Authorised Signatory"><input wire:model="company.signatory"></x-field>
                <x-field :label="$fields['signature']" name="signatureFile" hint="PNG of the signature + seal, up to 300 KB — printed at the bottom right of the PDF">
                    <input type="file" wire:model="signatureFile" accept=".png,.jpg,.jpeg,.webp">
                    <div wire:loading wire:target="signatureFile" class="small muted">Uploading…</div>
                    @if($signatureFile && in_array(strtolower($signatureFile->getClientOriginalExtension()), ['png', 'jpg', 'jpeg', 'webp'], true))<img src="{{ $signatureFile->temporaryUrl() }}" alt="New signature" style="max-height:70px;margin-top:8px">
                    @elseif($signatureFile)<div class="small" style="color:var(--red)">Only PNG / JPG / WEBP images are allowed.</div>
                    @elseif($hasSignature && ! $dropSignature)<div style="display:flex;align-items:center;gap:10px;margin-top:8px"><img src="{{ \App\Services\BillingService::settings()['signature'] }}" alt="Current signature" style="max-height:70px"><button type="button" class="btn btn-sm" wire:click="removeSignature">Remove</button></div>
                    @elseif($dropSignature)<div class="small muted" style="margin-top:6px">The signature is removed when you save.</div>@endif
                </x-field>
            </div>
            <p class="small muted">Invoice numbers look like <code>{{ \App\Services\BillingService::prefix('invoice') }}/2026-27/00001</code>; the number restarts every financial year (April–March). Changing the prefix also changes how older invoice numbers are shown.</p></div>
        </section>
        <div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled">Save billing settings</button></div>
    </form>
    @if(\App\Support\Access::isAdmin(auth()->user()))
        <section class="card mb" style="margin-top:18px">
            <div class="card-head"><h2>Import from the old CMS</h2></div>
            <div class="card-body">
                <p class="small muted">Upload the old CMS database dump (.sql, e.g. <code>u841922431_sws_cms.sql</code>). Customers, products, taxes, bank accounts, categories, invoices (current and 2025-26), items and payments are brought in — with the CMS numbers, customers and amounts. Running it again is safe: an invoice that already exists is only corrected, never duplicated, and payments added in HRMS are kept. Take a database backup first.</p>
                <form wire:submit="importCms" class="row-actions" style="align-items:flex-end;flex-wrap:wrap">
                    <x-field label="CMS dump (.sql)" name="cmsDump"><input type="file" wire:model="cmsDump" accept=".sql"></x-field>
                    <button class="btn btn-primary" wire:loading.attr="disabled" wire:target="cmsDump,importCms" @disabled(! $cmsDump)><x-icon name="upload" class="icon-sm" />Import</button>
                    <span wire:loading wire:target="cmsDump" class="small muted">Uploading…</span><span wire:loading wire:target="importCms" class="small muted">Importing… (1–2 min)</span>
                </form>
                @if($cmsImportLog)<pre class="small" style="white-space:pre-wrap;background:var(--canvas);padding:10px;border-radius:8px;margin-top:10px">{{ $cmsImportLog }}</pre>@endif
            </div>
        </section>
    @endif
</div>
