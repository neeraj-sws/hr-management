<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>Manage Customers</h1><p>People and companies you invoice.</p></div>
        <div class="row-actions">
            <button class="btn" wire:click="$set('showImport', true)"><x-icon name="upload" class="icon-sm" />Import</button>
            <a class="btn" href="{{ route('billing.customers.export') }}"><x-icon name="download" class="icon-sm" />Export</a>
            <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add customer</button>
        </div></div>
    <x-errors />
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Entries per page</span><select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, email, contact or #CUST number"></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>#</th><th>Name</th><th>Contact</th><th>Email</th><th>Balance</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($customers as $c)
                <tr wire:key="c-{{ $c->id }}">
                    <td><a class="cms-no" href="{{ route('billing.customers.show', $c) }}">{{ $c->code() }}</a></td>
                    <td><a href="{{ route('billing.customers.show', $c) }}" style="color:inherit">{{ $c->name }}</a></td>
                    <td>{{ $c->contact ?: '—' }}</td><td>{{ $c->email ?: '—' }}</td>
                    <td class="nowrap">Rs {{ number_format(-$c->balance_due + 0, 2) }}</td>
                    <td><div class="cms-acts">
                        <a class="cms-act view" href="{{ route('billing.customers.show', $c) }}" title="View" aria-label="View customer"><x-icon name="eye" /></a>
                        <button type="button" class="cms-act edit" wire:click="edit({{ $c->id }})" title="Edit" aria-label="Edit customer"><x-icon name="edit" /></button>
                        <button type="button" class="cms-act del" wire:click="deleteCustomer({{ $c->id }})" wire:confirm="Delete {{ $c->name }}? This cannot be undone." title="Delete" aria-label="Delete customer"><x-icon name="trash" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="6"><x-empty title="No customers" icon="users" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $customers->links() }}
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit Customer' : 'Create Customer'" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors />
                <style>.cust-form h3{margin:0 0 10px;font-size:15px}.cust-form .g3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 18px}@media(max-width:700px){.cust-form .g3{grid-template-columns:1fr}}</style>
                <div class="cust-form">
                    <h3>Basic Info</h3>
                    <div class="g3">
                        <x-field label="Name" name="name"><input wire:model="name" placeholder="Enter Name"></x-field>
                        <x-field label="Contact" name="contact"><input wire:model="contact" placeholder="Enter Contact"></x-field>
                        <x-field label="Email" name="email"><input type="email" wire:model="email" placeholder="Enter email"></x-field>
                        <x-field label="Tax Number" name="taxNumber"><input wire:model="taxNumber" placeholder="Enter Tax Number"></x-field>
                    </div>
                    <h3 style="margin-top:18px">Billing Address</h3>
                    <div class="form-grid">
                        <x-field label="Name" name="billingName"><input wire:model="billingName" placeholder="Enter Name"></x-field>
                        <x-field label="Phone" name="billingPhone"><input wire:model="billingPhone" placeholder="Enter Phone"></x-field>
                        <x-field label="Address" name="billingAddress" full><textarea wire:model="billingAddress" rows="3" placeholder="Enter Address"></textarea></x-field>
                        <x-field label="City" name="billingCity"><input wire:model="billingCity" placeholder="Enter City"></x-field>
                        <x-field label="State" name="billingState"><input wire:model="billingState" placeholder="Enter State"></x-field>
                        <x-field label="Country" name="billingCountry"><input wire:model="billingCountry" placeholder="Enter Country"></x-field>
                        <x-field label="Zip Code" name="billingZip"><input wire:model="billingZip" placeholder="Enter Zip Code"></x-field>
                    </div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">{{ $editingId ? 'Update' : 'Create' }}</button></div></form>
        </x-modal>
    @endif
    @if($showImport)
        <x-modal title="Import customers (Excel)">
            <form wire:submit="import"><div class="modal-body"><x-errors />
                <p class="small muted">.xlsx file me pehli row header ho: <b>Name</b> zaroori; Email, Contact, GSTIN, Billing address, City, State, Zip, Country, Shipping … optional. Jo naam pehle se hai wo skip hoga.</p>
                <p><a class="btn btn-sm" href="{{ route('billing.customers.sample') }}"><x-icon name="download" class="icon-sm" />Download sample customer CSV / Excel</a></p>
                <x-field label="Excel file" name="importFile"><input type="file" wire:model="importFile" accept=".xlsx"></x-field>
                <div wire:loading wire:target="importFile" class="small muted">Uploading…</div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Import</button></div></form>
        </x-modal>
    @endif
</div>
