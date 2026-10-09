<div>
    <div class="page-head"><div><h1>Projects</h1><p>{{ $scoped ? 'Projects you manage.' : 'All projects.' }}</p></div><button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New project</button></div>
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Project name"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option><option value="planned">Planned</option><option value="active">Active</option><option value="on_hold">On hold</option><option value="completed">Completed</option></select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Project</th><th>Manager</th><th>Timeline</th><th>Progress</th><th class="num">Est. hours</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($projects as $project)
                @php($pct = $project->tasks_count ? round($project->completed_tasks_count / $project->tasks_count * 100) : 0)
                <tr wire:key="p-{{ $project->id }}">
                    <td><strong>{{ $project->name }}</strong><small>{{ \Illuminate\Support\Str::limit($project->description, 70) }}</small></td>
                    <td>{{ $project->manager?->name ?? '—' }}</td>
                    <td class="nowrap small">{{ $project->starts_on?->format('d M Y') ?? '—' }} → {{ $project->ends_on?->format('d M Y') ?? '—' }}</td>
                    <td style="min-width:150px"><div class="progress"><span style="width: {{ $pct }}%"></span></div><small>{{ $project->completed_tasks_count }}/{{ $project->tasks_count }} done @if($project->overdue_tasks_count)· <span style="color:var(--red)">{{ $project->overdue_tasks_count }} overdue</span>@endif</small></td>
                    <td class="num">{{ (float) $project->tasks_sum_estimated_hours }}</td>
                    <td><x-badge :status="$project->status" /></td>
                    <td><div class="row-actions"><a class="btn btn-sm" href="{{ route('work.tasks', ['project' => $project->id]) }}">Tasks</a><button class="btn btn-sm" wire:click="openMembers({{ $project->id }})">Members</button><button class="btn btn-sm" wire:click="edit({{ $project->id }})">Edit</button></div></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="No projects yet" icon="folder">Create a project to group related tasks.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit project' : 'New project'">
            <form wire:submit="save"><div class="modal-body"><div class="form-grid">
                <x-field label="Name" name="name" full><input wire:model="name"></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description"></textarea></x-field>
                @unless($scoped)<x-field label="Manager" name="managerId"><select wire:model="managerId"><option value="">—</option>@foreach($managers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></x-field>@endunless
                <x-field label="Status" name="projectStatus"><select wire:model="projectStatus"><option value="planned">Planned</option><option value="active">Active</option><option value="on_hold">On hold</option><option value="completed">Completed</option></select></x-field>
                <x-field label="Start date" name="startsOn"><input type="date" wire:model="startsOn"></x-field>
                <x-field label="End date" name="endsOn"><input type="date" wire:model="endsOn" x-bind:min="$wire.startsOn"></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save project</button></div></form>
        </x-modal>
    @endif
    @if($membersProject)
        <x-modal title="Members · {{ $membersProject->name }}">
            <form wire:submit="saveMembers"><div class="modal-body">
                <p class="small muted" style="margin-top:0">With members set, tasks in this project can only be assigned to them. Leave everyone unticked to allow anyone in scope.</p>
                <div class="checks" style="max-height:300px;overflow:auto">@foreach($managers as $e)<label class="check"><input type="checkbox" value="{{ $e->id }}" wire:model="memberIds"> {{ $e->name }}</label>@endforeach</div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save members</button></div></form>
        </x-modal>
    @endif
</div>
