<div>
    <div class="page-head"><div><h1>Teams</h1><p>{{ $canManage ? 'All teams in the organisation' : 'Teams you belong to or lead' }} · {{ $teams->total() }} shown · {{ $totals['active'] }} active · {{ $totals['archived'] }} archived · {{ $totals['members'] }} people in teams</p></div>@if($canManage)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Create team</button>@endif</div>
    @unless($showForm)<x-errors />@endunless
    <div class="grid grid-3">
        @forelse($teams as $team)
            <section class="card" wire:key="team-{{ $team->id }}">
                <div class="card-head"><div><h2>{{ $team->name }}</h2><p>{{ $team->department?->name ?? 'No department' }} · Lead: {{ $team->manager?->name ?? '—' }}</p></div>@unless($team->is_active)<x-badge status="inactive">archived</x-badge>@endunless</div>
                <div class="card-body">
                    @if($team->description)<p class="small muted" style="margin-top:0">{{ $team->description }}</p>@endif
                    <p><x-people-count :people="$team->members->sortBy('name')" :title="'Team · '.$team->name" empty="No members yet" /></p>
                </div>
                @if($canManage)<div class="card-foot actions"><button class="btn btn-sm" wire:click="edit({{ $team->id }})">Edit</button><button class="btn btn-sm btn-danger" wire:click="remove({{ $team->id }})" wire:confirm="Remove the team {{ $team->name }}? Only possible when it never had members — otherwise archive it.">Remove</button><button class="btn btn-sm" wire:click="archive({{ $team->id }})" wire:confirm="{{ $team->is_active ? 'Archive this team?' : 'Restore this team?' }}">{{ $team->is_active ? 'Archive' : 'Restore' }}</button></div>@endif
            </section>
        @empty
            <div class="card" style="grid-column:1/-1"><x-empty title="No teams to show" icon="team">{{ $canManage ? 'Create a team to group employees.' : 'You have not been added to a team yet.' }}</x-empty></div>
        @endforelse
    </div>
    {{ $teams->links() }}

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit team' : 'Create team'" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Team name" name="name"><input wire:model="name"></x-field>
                <x-field label="Department" name="departmentId"><select wire:model="departmentId"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-field>
                <x-field label="Team lead" name="managerId" full><select wire:model="managerId"><option value="">—</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach</select></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description"></textarea></x-field>
                <div class="field full"><span class="label">Members</span><div class="checks">@foreach($employees as $e)<label class="check"><input type="checkbox" value="{{ $e->id }}" wire:model="memberIds"> {{ $e->name }}</label>@endforeach</div></div>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save team</button></div></form>
        </x-modal>
    @endif
</div>
