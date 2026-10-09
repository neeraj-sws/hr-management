<div>
    <div class="page-head"><div><h1>{{ $teamMode ? 'Team Notes' : 'My Notes' }}</h1><p>{{ $teamMode ? 'Notes shared with your teams and manager notes about your direct reports.' : 'Your private notes, notes you wrote, and notes your manager shared with you.' }}</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New note</button></div>
    <div class="card mb"><div class="toolbar" style="border:0"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Title or text"></label></div></div>
    <div class="grid grid-3">
        @forelse($notes as $note)
            <article class="card" wire:key="n-{{ $note->id }}">
                <div class="card-head"><div><h2>{{ $note->title }}</h2><p>{{ $note->author?->name }} · {{ $note->updated_at->diffForHumans() }}</p></div>
                    <x-badge :tone="['personal' => 'blue', 'manager' => 'violet', 'team' => 'green'][$note->visibility] ?? null">{{ $note->visibility === 'manager' ? 'about '.($note->employee?->name ?? '—') : ($note->visibility === 'team' ? $note->team?->name : 'private') }}</x-badge></div>
                <div class="card-body" style="white-space:pre-line">{{ $note->body }}</div>
                @can('update', $note)<div class="card-foot actions"><button class="btn btn-sm" wire:click="edit({{ $note->id }})">Edit</button><button class="btn btn-sm btn-danger" wire:click="delete({{ $note->id }})" wire:confirm="Delete this note?">Delete</button></div>@endcan
            </article>
        @empty
            <div class="card" style="grid-column:1/-1"><x-empty title="No notes here yet" icon="note" /></div>
        @endforelse
    </div>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit note' : 'New note'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Visibility" name="visibility"><select wire:model.live="visibility"><option value="personal">Private (only me)</option>@if($reports->isNotEmpty())<option value="manager">Manager ↔ team member</option>@endif @if($teams->isNotEmpty())<option value="team">Team</option>@endif</select></x-field>
                @if($visibility === 'manager')<x-field label="Team member" name="employeeId"><select wire:model="employeeId"><option value="">Select</option>@foreach($reports as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select></x-field>@endif
                @if($visibility === 'team')<x-field label="Team" name="teamId"><select wire:model="teamId"><option value="">Select</option>@foreach($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></x-field>@endif
                <x-field label="Title" name="title" full><input wire:model="title"></x-field>
                <x-field label="Note" name="body" full><textarea wire:model="body" rows="6"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save note</button></div></form>
        </x-modal>
    @endif
</div>
