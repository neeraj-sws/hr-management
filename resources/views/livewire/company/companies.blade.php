<div>
    <div class="page-head">
        <div><h1>Companies</h1><p>Create and manage companies. Each company has its own employees, payroll and rules.</p></div>
        @unless($showForm)
            <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" /> New company</button>
        @endunless
    </div>
    <x-errors />

    @if($showForm)
    <section class="card mb">
        <div class="card-head"><h2>{{ $editingId ? 'Edit company' : 'New company' }}</h2></div>
        <form class="card-body stack" wire:submit="save">
            <div class="grid grid-2">
                <x-field label="Company name *" name="name"><input type="text" wire:model="name" placeholder="e.g. SWS Solutions" maxlength="120"></x-field>
                <x-field label="Company code" name="code" hint="Short unique code, e.g. SWS or ITW"><input type="text" wire:model="code" placeholder="e.g. SWS" maxlength="20"></x-field>
                <x-field label="Owner / Authorised person" name="ownerName"><input type="text" wire:model="ownerName" placeholder="e.g. Praveen Vaishnav" maxlength="120"></x-field>
                <x-field label="Contact number" name="phone"><input type="text" wire:model="phone" placeholder="+91 98765 43210" maxlength="30"></x-field>
                <x-field label="Company email" name="email"><input type="email" wire:model="email" placeholder="info@company.com" maxlength="120"></x-field>
                <x-field label="Website" name="website"><input type="text" wire:model="website" placeholder="https://company.com" maxlength="255"></x-field>
            </div>
            <x-field label="Address" name="address"><textarea wire:model="address" rows="2" maxlength="500" placeholder="Full office address"></textarea></x-field>
            <x-field label="Status" name="isActive">
                <label class="toggle"><input type="checkbox" wire:model="isActive"><span>Active</span></label>
            </x-field>
            <div class="toolbar" style="border:0;padding:0">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="save" class="spinner"></span>{{ $editingId ? 'Save changes' : 'Create company' }}</button>
                <button type="button" class="btn" wire:click="cancel">Cancel</button>
            </div>
        </form>
    </section>
    @endif

    <section class="card">
        <table class="table">
            <thead><tr>
                <th>Company</th><th>Code</th><th>Owner</th><th>Contact</th><th>Employees</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($companies as $company)
                <tr>
                    <td>
                        <strong>{{ $company->name }}</strong>
                        @if($company->email)<br><small class="muted">{{ $company->email }}</small>@endif
                    </td>
                    <td>{{ $company->code ?? '-' }}</td>
                    <td>{{ $company->owner_name ?? '-' }}</td>
                    <td>{{ $company->phone ?? '-' }}</td>
                    <td>{{ $company->employees_count }}</td>
                    <td>
                        @if($company->is_active)
                            <span class="pill pill-sm pill-green">Active</span>
                        @else
                            <span class="pill pill-sm pill-red">Inactive</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn btn-sm" wire:click="edit({{ $company->id }})">Edit</button>
                        <a class="btn btn-sm" href="{{ route('company.profile', $company) }}">Profile</a>
                        <a class="btn btn-sm" href="{{ route('company.logins', $company) }}">Logins</a>
                            <button class="btn btn-sm btn-danger" wire:click="delete({{ $company->id }})" wire:confirm="Delete {{ $company->name }}? This cannot be undone. Only possible if the company has no employees.">Delete</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted text-center">No companies yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>

