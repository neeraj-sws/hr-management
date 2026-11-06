<div>
    @if($managesAll)
        <div class="page-head"><div><h1>Roles & Permissions</h1><p>Menus, pages, actions and API access are all driven by these permissions. Changes take effect immediately and are audited.</p></div></div>
    @else
        <div class="page-head"><div><h1>Logins & roles</h1><p>Give your company's people the HR, Accounts, Manager or Employee role. They see your company only. Changes take effect immediately and are audited.</p></div></div>
    @endif
    <x-errors />
    @if($managesAll)
        <div class="tabs"><button class="tab {{ $tab === 'matrix' ? 'active' : '' }}" wire:click="$set('tab','matrix')">Permission matrix</button><button class="tab {{ $tab === 'users' ? 'active' : '' }}" wire:click="$set('tab','users')">User roles</button></div>
    @endif

    @if($tab === 'matrix')
        <section class="card">
            <div class="table-wrap"><table class="table matrix">
                <thead><tr><th>Permission</th>@foreach($roles as $role)<th>{{ $role->name }}<small style="display:block;text-transform:none;letter-spacing:0">{{ $role->users_count }} users</small></th>@endforeach</tr></thead>
                <tbody>
                @foreach($permissions as $group => $names)
                    <tr><td colspan="{{ $roles->count() + 1 }}" style="background:#f8fafd"><strong style="text-transform:capitalize">{{ $group }}</strong></td></tr>
                    @foreach($names as $permission)
                        <tr><td>{{ \Illuminate\Support\Str::of($permission)->after('.')->replace('_', ' ')->ucfirst() }}<small class="muted" style="display:block">{{ $permission }}</small></td>
                            @foreach($roles as $role)<td>@if($role->name === $locked)<x-icon name="check" class="icon-sm" style="color:var(--green)" />@else<input type="checkbox" value="{{ $permission }}" wire:model="matrix.{{ $role->id }}" aria-label="{{ $role->name }}: {{ $permission }}">@endif</td>@endforeach</tr>
                    @endforeach
                @endforeach
                </tbody>
                <tfoot><tr><td></td>@foreach($roles as $role)<td>@if($role->name !== $locked)<button class="btn btn-sm btn-primary" wire:click="saveRole({{ $role->id }})" wire:confirm="Save permissions for {{ $role->name }}?">Save</button>@else<span class="small muted">locked</span>@endif</td>@endforeach</tr></tfoot>
            </table></div>
        </section>
    @else
        <section class="card">
            <div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or email"></label></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>User</th><th>Roles</th>@if($managesAll && $companies->count() > 1)<th>Company</th>@endif<th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach($users as $u)
                    <tr><td><strong>{{ $u->name }}</strong><small>{{ $u->email }}</small></td><td><x-person-badges :user="$u" /></td>@if($managesAll && $companies->count() > 1)<td>{{ $u->company?->name ?? 'All companies' }}</td>@endif<td><x-badge :status="$u->is_active ? 'active' : 'inactive'" /></td><td><button class="btn btn-sm" wire:click="editUser({{ $u->id }})">Change roles</button></td></tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $users->links() }}
        </section>
    @endif

    @if($editing)
        <x-modal title="Roles · {{ $editing->name }}">
            <form wire:submit="saveUserRoles"><div class="modal-body"><x-errors /><div class="checks" style="max-height:none">
                @foreach($roles as $role)@if($grantable->contains($role->name))<label class="check"><input type="checkbox" value="{{ $role->name }}" wire:model="userRoles"> {{ $role->name }}</label>@endif @endforeach
            </div>
            @if($managesAll && $companies->count() > 1)
                <x-field label="Company" name="userCompanyId" hint="a login with a company sees that company only (a Company Owner needs one); empty = all companies">
                    <select wire:model="userCompanyId"><option value="">All companies</option>@foreach($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                </x-field>
            @endif
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save roles</button></div></form>
        </x-modal>
    @endif
</div>
