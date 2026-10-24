<div>
    <div class="page-head">
        <div><h1>{{ $mine ? 'My Projects' : 'Projects' }}</h1><p>{{ $mine ? 'Projects you are a member of, manage, or have tasks in.' : ($scoped ? 'Projects you manage.' : 'All projects.') }}</p></div>
        @if($canManage)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New project</button>@endif
    </div>
    @php($tones = ['planned' => 'blue', 'active' => 'green', 'on_hold' => 'amber', 'completed' => 'gray'])
    @php($statusCards = [
        ['label' => 'All', 'count' => $counts['total'], 'tone' => 'navy', 'active' => $status === '', 'click' => "\$set('status', '')"],
        ...collect(\App\Services\WorkService::PROJECT_STATUSES)->map(fn ($label, $key) => ['label' => $label, 'count' => $counts[$key], 'tone' => $tones[$key] ?? 'gray', 'active' => $status === $key, 'click' => "\$set('status', '{$key}')"])->values()->all(),
    ])
    <x-status-cards label="Projects by status" :items="$statusCards" />
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Project or client name"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option><option value="planned">Planned</option><option value="active">Active</option><option value="on_hold">On hold</option><option value="completed">Completed</option></select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Project</th><th>Client</th><th>Manager</th><th>Members</th><th>Timeline</th><th>Progress</th>@if($mine)<th class="num">My open tasks</th>@else<th class="num">Est. time</th>@endif<th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($projects as $project)
                @php($pct = $project->tasks_count ? round($project->completed_tasks_count / $project->tasks_count * 100) : 0)
                <tr wire:key="p-{{ $project->id }}">
                    <td><strong>{{ $project->name }}</strong><small>{{ \Illuminate\Support\Str::limit($project->description, 70) }}</small></td>
                    <td>{{ $project->client_name ?: '—' }}</td>
                    <td>{{ $project->manager?->name ?? '—' }}</td>
                    <td class="small"><x-people-count :people="$project->members" :title="'Members · '.$project->name" empty="Anyone in scope" /></td>
                    <td class="nowrap small">{{ $project->starts_on?->format('d M Y') ?? '—' }} → {{ $project->ends_on?->format('d M Y') ?? '—' }}</td>
                    <td style="min-width:150px"><div class="progress"><span style="width: {{ $pct }}%"></span></div><small>{{ $project->completed_tasks_count }}/{{ $project->tasks_count }} done @if($project->overdue_tasks_count)· <span style="color:var(--red)">{{ $project->overdue_tasks_count }} overdue</span>@endif</small></td>
                    @if($mine)<td class="num">{{ $project->my_open_tasks_count }}</td>@else<td class="num">{{ \App\Support\Duration::fromHours($project->tasks_sum_estimated_hours) }}</td>@endif
                    <td><x-badge :status="$project->status" /></td>
                    <td><div class="row-actions">
                        @if($mine)<a class="btn btn-sm" href="{{ route('work.my-tasks', ['project' => $project->id]) }}">My tasks</a>@else<a class="btn btn-sm" href="{{ route('work.tasks', ['project' => $project->id]) }}">Tasks</a>@endif
                        @if($canManage)<button class="btn btn-sm" wire:click="openMembers({{ $project->id }})">Members</button><button class="btn btn-sm" wire:click="edit({{ $project->id }})">Edit</button>@endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty :title="$mine ? 'You are not on any project yet' : 'No projects yet'" icon="folder">{{ $mine ? 'Projects appear here when you are added to one or given a task in it.' : 'Create a project to group related tasks.' }}</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $projects->links() }}
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit project' : 'New project'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Name" name="name" full><input wire:model="name"></x-field>
                <x-field label="Client name" name="clientName" hint="optional" full><input wire:model="clientName" placeholder="Who the project is for"></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description"></textarea></x-field>
                @unless($scoped)<x-field label="Manager" name="managerId" hint="notified when assigned"><select wire:model="managerId"><option value="">—</option>@foreach($managers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></x-field>@endunless
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
                <p class="small muted" style="margin-top:0">With members set, tasks in this project can only be assigned to them. Leave everyone unticked to allow anyone in scope. People you add or remove are notified.</p>
                <div class="checks" style="max-height:300px;overflow:auto">@foreach($managers as $e)<label class="check"><input type="checkbox" value="{{ $e->id }}" wire:model="memberIds"> {{ $e->name }}</label>@endforeach</div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save members</button></div></form>
        </x-modal>
    @endif
</div>
