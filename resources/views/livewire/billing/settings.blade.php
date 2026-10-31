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
            <div class="card-head"><h2>Invoice footer</h2></div>
            <div class="card-body"><div class="form-grid">
                <x-field :label="$fields['terms']" name="company.terms" full><textarea wire:model="company.terms" rows="3" placeholder="e.g. Payment within 7 days. Interest @ 18% p.a. on late payment."></textarea></x-field>
                <x-field :label="$fields['signatory']" name="company.signatory"><input wire:model="company.signatory"></x-field>
            </div>
            <p class="small muted">Invoice numbers: <code>{{ config('billing.prefix') }}/2026-27/00001</code> — the prefix is <code>BILLING_PREFIX</code> in the server's .env; the number restarts every financial year (April–March).</p></div>
        </section>
        <div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled">Save billing settings</button></div>
    </form>
</div>
