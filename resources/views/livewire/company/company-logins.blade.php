<div>
    <div class="page-head">
        <div>
            <h1>{{ $company->name }} – Logins</h1>
            <p>All login accounts for this company. Each login can only access this company's data.</p>
        </div>
        <div class="toolbar" style="border:0">
            <a class="btn" href="{{ route('company.companies') }}">← All companies</a>
            @unless($showForm || $showPasswordForm || $showEditForm)
                <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" /> New login</button>
            @endunless
        </div>
    </div>
    <x-errors />

    @if($showForm)
    <section class="card mb">
        <div class="card-head"><h2>New login for {{ $company->name }}</h2></div>
        <form class="card-body stack" wire:submit="save">
            <div class="grid grid-2">
                <x-field label="Name *" name="loginName"><input type="text" wire:model="loginName" placeholder="Full name" maxlength="120"></x-field>
                <x-field label="Email *" name="loginEmail"><input type="email" wire:model="loginEmail" placeholder="email@company.com" maxlength="120"></x-field>
                <x-field label="Password *" name="loginPassword" hint="Minimum 8 characters"><input type="password" wire:model="loginPassword" autocomplete="new-password"></x-field>
                <x-field label="Role *" name="loginRole">
                    <select wire:model="loginRole">
                        <option value="">Select role…</option>
                        @foreach($allowedRoles as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
            <p class="small muted">This login will only be able to see and manage <strong>{{ $company->name }}</strong> data.</p>
            <div class="toolbar" style="border:0;padding:0">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="save" class="spinner"></span>Create login</button>
                <button type="button" class="btn" wire:click="cancel">Cancel</button>
            </div>
        </form>
    </section>
    @endif

    @if($showPasswordForm)
    <section class="card mb">
        <div class="card-head"><h2>Reset password</h2></div>
        <form class="card-body stack" wire:submit="savePassword">
            <x-field label="New password *" name="newPassword" hint="Minimum 8 characters">
                <input type="password" wire:model="newPassword" autocomplete="new-password">
            </x-field>
            <div class="toolbar" style="border:0;padding:0">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="savePassword" class="spinner"></span>Set password</button>
                <button type="button" class="btn" wire:click="cancelPassword">Cancel</button>
            </div>
        </form>
    </section>
    @endif

    @if($showEditForm)
    <section class="card mb">
        <div class="card-head"><h2>Edit login</h2></div>
        <form class="card-body stack" wire:submit="saveEdit">
            <div class="grid grid-2">
                <x-field label="Name *" name="loginName"><input type="text" wire:model="loginName" placeholder="Full name" maxlength="120"></x-field>
                <x-field label="Email *" name="loginEmail"><input type="email" wire:model="loginEmail" placeholder="email@company.com" maxlength="120"></x-field>
                <x-field label="Role *" name="loginRole">
                    <select wire:model="loginRole">
                        <option value="">Select role…</option>
                        @foreach($allowedRoles as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
            <div class="toolbar" style="border:0;padding:0">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="saveEdit" class="spinner"></span>Save</button>
                <button type="button" class="btn" wire:click="cancelEdit">Cancel</button>
            </div>
        </form>
    </section>
    @endif

    <section class="card">
        <table class="table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($logins as $login)
                <tr>
                    <td>{{ $login->name }}</td>
                    <td>{{ $login->email }}</td>
                    <td>{{ $login->roles->pluck('name')->join(', ') ?: '—' }}</td>
                    <td>
                        @if($login->is_active ?? true)
                            <span class="pill pill-sm pill-green">Active</span>
                        @else
                            <span class="pill pill-sm pill-red">Inactive</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn btn-sm" wire:click="editRow({{ $login->id }})">Edit</button>
                        <button class="btn btn-sm" wire:click="openPasswordReset({{ $login->id }})">Reset password</button>
                        @if($login->is_active ?? true)
                            <button class="btn btn-sm" wire:click="deactivate({{ $login->id }})" wire:confirm="Deactivate {{ $login->name }}? They will not be able to log in.">Deactivate</button>
                        @else
                            <button class="btn btn-sm btn-primary" wire:click="reactivate({{ $login->id }})">Reactivate</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted text-center">No logins yet for this company.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>