<div>
    <div class="page-head"><div><h1>Clients</h1><p>Client companies, their projects and their logins to the client portal — where they review the project timesheet, object to lines and approve the month.</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New client</button></div>
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Client</th><th>Projects (rate / hour)</th><th>Billing customer</th><th>Portal logins</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($clients as $c)
                <tr wire:key="cl-{{ $c->id }}">
                    <td style="text-align:left"><strong>{{ $c->name }}</strong><small>{{ collect([$c->contact_name, $c->email, $c->phone])->filter()->join(' · ') }}</small></td>
                    <td style="text-align:left">@forelse($c->projects as $p)<div><a href="{{ route('work.timesheets', ['project' => $p->id]) }}">{{ $p->name }}</a> <span class="muted small">{{ $p->hourly_rate ? '₹'.number_format((float) $p->hourly_rate, 2) : 'no rate yet' }}</span></div>@empty<span class="muted small">No project yet</span>@endforelse</td>
                    <td>{{ $c->billingCustomer?->name ?? '—' }}</td>
                    <td style="text-align:left">@foreach($c->users as $u)<div class="small">{{ $u->email }} @if($u->is_active)<x-badge tone="green">active</x-badge>@else<x-badge tone="red">off</x-badge>@endif <button class="btn btn-sm" wire:click="toggleLogin({{ $u->id }})">{{ $u->is_active ? 'Disable' : 'Enable' }}</button></div>@endforeach
                        <button class="btn btn-sm mt" wire:click="openLogin({{ $c->id }})"><x-icon name="plus" class="icon-sm" />Add login</button></td>
                    <td>@if($c->is_active)<x-badge tone="green">Active</x-badge>@else<x-badge tone="red">Inactive</x-badge>@endif</td>
                    <td><button class="btn btn-sm" wire:click="edit({{ $c->id }})">Edit</button></td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty title="No clients yet" icon="users">Add a client, give them their project(s) and a portal login.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit client' : 'New client'" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Client / company name" name="name" full><input wire:model="name" placeholder="e.g. Source India"></x-field>
                <x-field label="Contact person" name="contactName"><input wire:model="contactName"></x-field>
                <x-field label="E-mail" name="email"><input type="email" wire:model="email"></x-field>
                <x-field label="Phone" name="phone"><input wire:model="phone"></x-field>
                <x-field label="Billing customer" name="billingCustomerId" hint="invoices from the timesheet go to this customer"><select wire:model="billingCustomerId"><option value="">—</option>@foreach($customers as $bc)<option value="{{ $bc->id }}">{{ $bc->name }}</option>@endforeach</select></x-field>
                <div class="field full"><span class="label">Projects of this client</span>
                    <div class="checks" style="max-height:200px;overflow:auto">@foreach($projects as $p)<label class="check"><input type="checkbox" value="{{ $p->id }}" wire:model="projectIds"> {{ $p->name }}</label>@endforeach</div></div>
                <label class="check"><input type="checkbox" wire:model="isActive"> Active (an inactive client's logins are switched off)</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save client</button></div></form>
        </x-modal>
    @endif

    @if($loginClient)
        <x-modal :title="'Portal login · '.$loginClient->name">
            <form wire:submit="saveLogin"><div class="modal-body"><x-errors />
                <p class="small muted" style="margin-top:0">This person signs in on the normal sign-in page and sees only {{ $loginClient->name }}'s projects: the timesheet, objections and invoices — nothing else of the HRMS.</p>
                <div class="form-grid">
                    <x-field label="Name" name="loginName"><input wire:model="loginName"></x-field>
                    <x-field label="E-mail (sign-in)" name="loginEmail"><input type="email" wire:model="loginEmail"></x-field>
                    <x-field label="Password" name="loginPassword" hint="at least 10, letters and numbers" full><input type="text" wire:model="loginPassword" autocomplete="new-password"></x-field>
                </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Create login</button></div></form>
        </x-modal>
    @endif
</div>
