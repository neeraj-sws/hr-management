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
                <x-field label="Logo" name="logoFile" hint="PNG / JPG, 300 KB tak — invoice, estimate, receipt aur customer link par dikhega">
                    <input type="file" wire:model="logoFile" accept=".png,.jpg,.jpeg,.webp">
                    <div wire:loading wire:target="logoFile" class="small muted">Uploading…</div>
                    @if($logoFile && in_array(strtolower($logoFile->getClientOriginalExtension()), ['png', 'jpg', 'jpeg', 'webp'], true))<img src="{{ $logoFile->temporaryUrl() }}" alt="New logo" style="max-height:56px;margin-top:8px">
                    @elseif($logoFile)<div class="small" style="color:var(--red)">Sirf PNG / JPG / WEBP image chalegi.</div>
                    @elseif($hasLogo && ! $dropLogo)<div style="display:flex;align-items:center;gap:10px;margin-top:8px"><img src="{{ \App\Services\BillingService::settings()['logo'] }}" alt="Current logo" style="max-height:56px"><button type="button" class="btn btn-sm" wire:click="removeLogo">Remove</button></div>
                    @elseif($dropLogo)<div class="small muted" style="margin-top:6px">Logo save karne par hat jayega.</div>@endif
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
                <x-field label="Send invoices from (e-mail)" name="company.mail_from_address" hint="e.g. accounts@sohamsolution.com · khali = HRMS ka normal mail address"><input type="email" wire:model="company.mail_from_address"></x-field>
                <x-field label="Sender name" name="company.mail_from_name"><input wire:model="company.mail_from_name" placeholder="Soham Web Solution"></x-field>
            </div>
            <p class="small muted">Mail server (SMTP) wahi rahega jo HRMS use karta hai — ye address us server se bhejne ke liye allowed hona chahiye (Office365 me "Send As" permission).</p></div>
        </section>
        <section class="card mb">
            <div class="card-head"><h2>Invoice footer</h2></div>
            <div class="card-body"><div class="form-grid">
                <x-field :label="$fields['terms']" name="company.terms" full><textarea wire:model="company.terms" rows="3" placeholder="e.g. Payment within 7 days. Interest @ 18% p.a. on late payment."></textarea></x-field>
                <x-field :label="$fields['footer_title']" name="company.footer_title"><input wire:model="company.footer_title"></x-field>
                <x-field :label="$fields['footer_notes']" name="company.footer_notes" full hint="invoice PDF me neeche dikhega (CMS jaisa) — &lt;b&gt;bold&lt;/b&gt; aur &lt;br&gt; chalega"><textarea wire:model="company.footer_notes" rows="5" placeholder="<b>Bank Details:</b><br>Bank Name - HDFC Bank ..."></textarea></x-field>
                <x-field :label="$fields['signatory']" name="company.signatory" hint="e.g. Neeraj Malakar — signature ke neeche, 'Authorised Signatory' ke saath"><input wire:model="company.signatory"></x-field>
                <x-field :label="$fields['signature']" name="signatureFile" hint="sign + seal ki PNG, 300 KB tak — PDF me neeche right side">
                    <input type="file" wire:model="signatureFile" accept=".png,.jpg,.jpeg,.webp">
                    <div wire:loading wire:target="signatureFile" class="small muted">Uploading…</div>
                    @if($signatureFile && in_array(strtolower($signatureFile->getClientOriginalExtension()), ['png', 'jpg', 'jpeg', 'webp'], true))<img src="{{ $signatureFile->temporaryUrl() }}" alt="New signature" style="max-height:70px;margin-top:8px">
                    @elseif($signatureFile)<div class="small" style="color:var(--red)">Sirf PNG / JPG / WEBP image chalegi.</div>
                    @elseif($hasSignature && ! $dropSignature)<div style="display:flex;align-items:center;gap:10px;margin-top:8px"><img src="{{ \App\Services\BillingService::settings()['signature'] }}" alt="Current signature" style="max-height:70px"><button type="button" class="btn btn-sm" wire:click="removeSignature">Remove</button></div>
                    @elseif($dropSignature)<div class="small muted" style="margin-top:6px">Signature save karne par hat jayega.</div>@endif
                </x-field>
            </div>
            <p class="small muted">Invoice numbers look like <code>{{ \App\Services\BillingService::prefix('invoice') }}/2026-27/00001</code>; the number restarts every financial year (April–March). Prefix badalne se purani invoices ka number bhi naye prefix ke saath dikhega.</p></div>
        </section>
        <div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled">Save billing settings</button></div>
    </form>
    @if(\App\Support\Access::isAdmin(auth()->user()))
        <section class="card mb" style="margin-top:18px">
            <div class="card-head"><h2>Import from the old CMS</h2></div>
            <div class="card-body">
                <p class="small muted">CMS ka database dump (.sql, jaise <code>u841922431_sws_cms.sql</code>) upload karo. Customers, products, taxes, bank accounts, categories, invoices (abhi ke aur 2025-26 ke), items aur payments aa jayenge — CMS ke number, customer aur amount ke saath. Dobara chalana safe hai: jo invoice pehle se hai wo sirf theek hoti hai, dobara nahi banti; HRMS me jode payments rehte hain. Pehle database ka backup le lo.</p>
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
