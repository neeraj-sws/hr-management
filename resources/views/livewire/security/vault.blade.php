<div>
    <div class="page-head"><div><h1>Credential Vault</h1><p>Secrets are encrypted at rest, never listed, revealed one at a time only to authorised users, and every access is audited.</p></div>
        @if($canManage)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add credential</button>@endif</div>
    <div class="grid grid-sidebar">
        <section class="card">
            <div class="toolbar">
                <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Label, username or URL"></label>
                <label class="field"><span>Category</span><select wire:model.live="category"><option value="">All</option>@foreach($categories as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            </div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Credential</th><th>Category</th><th>Visible to</th><th>Owner</th><th></th></tr></thead>
                <tbody>
                @forelse($entries as $entry)
                    @php($allowed = auth()->user()->can('view', $entry))
                    <tr wire:key="v-{{ $entry->id }}">
                        <td><strong>{{ $entry->label }}</strong><small>{{ $entry->username ?? '—' }}@if($entry->url) · <a href="{{ $entry->url }}" target="_blank" rel="noopener noreferrer">{{ parse_url($entry->url, PHP_URL_HOST) }}</a>@endif</small>
                            @if($revealedId === $entry->id)<div class="mt"><span class="secret">{{ $revealedSecret }}</span> <button class="btn btn-sm btn-ghost" wire:click="hide"><x-icon name="eye-off" class="icon-sm" />Hide</button></div>@endif</td>
                        <td>{{ $categories[$entry->category] ?? $entry->category }}</td>
                        <td>@switch($entry->visibility)@case('private')<x-badge tone="blue">only creator</x-badge>@break @case('hr')<x-badge tone="violet">HR</x-badge>@break @case('team')<x-badge tone="green">team: {{ $entry->team?->name }}</x-badge>@break @default<x-badge tone="amber">{{ $entry->owner?->name }}</x-badge>@endswitch</td>
                        <td class="small">{{ $entry->creator?->name }}</td>
                        <td><div class="row-actions">
                            @if($allowed && $revealedId !== $entry->id)<button class="btn btn-sm" wire:click="reveal({{ $entry->id }})"><x-icon name="eye" class="icon-sm" />Reveal</button>@endif
                            @if($canManage && $allowed)<button class="btn btn-sm" wire:click="edit({{ $entry->id }})">Edit</button>@endif
                            @can('delete', $entry)<button class="btn btn-sm btn-danger" wire:click="delete({{ $entry->id }})" wire:confirm="Delete {{ $entry->label }}?">Delete</button>@endcan
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty title="No credentials visible to you" icon="key" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        @if($canManage)
            <section class="card">
                <div class="card-head"><div><h2>Access log</h2><p>Latest vault activity</p></div><a class="btn btn-sm" href="{{ route('security.audit', ['search' => 'credential.']) }}">All</a></div>
                <div class="list">
                    @forelse($accessLog as $log)<div class="list-row"><div><strong>{{ str($log->action)->after('credential.')->replace('_', ' ')->ucfirst() }}</strong><small>{{ $log->actor?->name ?? 'system' }} · #{{ $log->subject_id }} · {{ $log->created_at?->diffForHumans() }}</small></div>@if($log->action === 'credential.access_denied')<x-badge status="rejected">denied</x-badge>@endif</div>
                    @empty<x-empty title="No vault activity" icon="eye" />@endforelse
                </div>
            </section>
        @endif
    </div>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit credential' : 'Add credential'" size="lg">
            <form wire:submit="save" autocomplete="off"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Label" name="label"><input wire:model="label" placeholder="Production database"></x-field>
                <x-field label="Category" name="vaultCategory"><select wire:model="vaultCategory">@foreach($categories as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                <x-field label="Username" name="username"><input wire:model="username" autocomplete="off"></x-field>
                <x-field label="{{ $editingId ? 'New secret' : 'Secret' }}" name="secret" :hint="$editingId ? 'leave blank to keep current' : null"><input type="password" wire:model="secret" autocomplete="new-password"></x-field>
                <x-field label="URL / host" name="url" full><input type="url" wire:model="url" placeholder="https://"></x-field>
                <x-field label="Visibility" name="visibility"><select wire:model.live="visibility"><option value="private">Only me</option><option value="hr">HR / vault managers</option><option value="team">A team</option><option value="employee">One employee</option></select></x-field>
                @if($visibility === 'team')<x-field label="Team" name="teamId"><select wire:model="teamId"><option value="">Select</option>@foreach($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></x-field>@endif
                @if($visibility === 'employee')<x-field label="Employee" name="ownerId"><select wire:model="ownerId"><option value="">Select</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>@endif
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save encrypted</button></div></form>
        </x-modal>
    @endif
</div>
