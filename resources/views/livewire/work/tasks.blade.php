<div>
    <div class="page-head">
        <div><h1>{{ $mine ? 'My Tasks' : 'Tasks' }}</h1><p>{{ $mine ? 'Your assigned work. Start a timer to log time; breaks and check-out pause it automatically.' : 'Assign and track work across your scope.' }}</p></div>
        <div class="actions">
            @if($mine)<a class="btn" href="{{ route('work.timer') }}"><x-icon name="timer" class="icon-sm" />Task timer</a>@endif
            @if($canAssign && !$mine)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Assign task</button>@endif
            @if($canAddOwn)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add my task</button>@endif
        </div>
    </div>
    <x-errors />
    <div class="stats">
        <button type="button" class="stat" wire:click="$set('status','open')" style="cursor:pointer;text-align:left"><span class="stat-icon"><x-icon name="list" /></span><div><span class="stat-label">Open</span><span class="stat-value">{{ $counts['open'] }}</span></div></button>
        <button type="button" class="stat" wire:click="$set('status','in_progress')" style="cursor:pointer;text-align:left"><span class="stat-icon amber"><x-icon name="timer" /></span><div><span class="stat-label">In progress</span><span class="stat-value">{{ $counts['in_progress'] }}</span></div></button>
        <button type="button" class="stat" wire:click="$set('due','overdue')" style="cursor:pointer;text-align:left"><span class="stat-icon red"><x-icon name="alert" /></span><div><span class="stat-label">Overdue</span><span class="stat-value">{{ $counts['overdue'] }}</span></div></button>
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Done this week</span><span class="stat-value">{{ $counts['done_week'] }}</span></div></div>
    </div>
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Task title"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="open">Open</option><option value="">All</option><option value="todo">To do</option><option value="in_progress">In progress</option><option value="on_hold">On hold</option><option value="completed">Completed</option></select></label>
            <label class="field"><span>Priority</span><select wire:model.live="priority"><option value="">Any</option><option value="urgent">Urgent</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option></select></label>
            <label class="field"><span>Project</span><select wire:model.live="project"><option value="">Any</option>@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
            @if(!$mine && $assignees->isNotEmpty())<label class="field"><span>Assignee</span><select wire:model.live="assignee"><option value="">Anyone</option>@foreach($assignees as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>@endif
            <label class="field"><span>Due</span><select wire:model.live="due"><option value="">Any time</option><option value="overdue">Overdue</option><option value="today">Due today</option><option value="week">Next 7 days</option><option value="late">Completed late</option></select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Task</th>@unless($mine)<th>Assignee</th>@endunless<th>Priority</th><th>Due</th><th class="num">Est / logged</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($tasks as $task)
                @php($logged = $work->loggedSeconds($task))
                <tr wire:key="t-{{ $task->id }}">
                    <td><button type="button" class="linklike" wire:click="open({{ $task->id }})"><strong>@if($task->parent)<span class="muted">{{ \Illuminate\Support\Str::limit($task->parent->title, 25) }} › </span>@endif{{ $task->title }}</strong></button><small>{{ $task->project?->name ?? 'No project' }}@if($task->subtasks_count) · {{ $task->subtasks_done_count }}/{{ $task->subtasks_count }} subtasks @endif @if($task->comments_count) · 💬 {{ $task->comments_count }}@endif @if($task->work_date) · for {{ $task->work_date->format('d M') }}@endif</small></td>
                    @unless($mine)<td>{{ $task->assignee?->name }}</td>@endunless
                    <td><x-badge :status="$task->priority" /></td>
                    <td class="nowrap">@if($task->due_on){{ $task->due_on->format('d M Y') }}@if($task->isOverdue())<small style="color:var(--red)">{{ $task->due_on->diffInDays(today()) }} days overdue</small>@elseif($task->wasCompletedLate())<small style="color:var(--amber)">completed late</small>@endif @else — @endif</td>
                    <td class="num nowrap">{{ $task->estimated_hours !== null ? (float) $task->estimated_hours.'h' : '—' }} / {{ sprintf('%d:%02d', intdiv($logged, 3600), intdiv($logged % 3600, 60)) }}</td>
                    <td><x-badge :status="$task->status" />@if($runningTaskId === $task->id)<small style="color:var(--green)">● timer running</small>@endif</td>
                    <td><div class="row-actions">
                        @if($own && $task->assignee_id === $own->id && $task->status !== 'completed')
                            @if($runningTaskId === $task->id)
                                <button class="btn btn-sm" wire:click="pause({{ $task->id }})"><x-icon name="pause" class="icon-sm" />Pause</button>
                            @else
                                <button class="btn btn-sm btn-primary" wire:click="start({{ $task->id }})"><x-icon name="play" class="icon-sm" />{{ $logged ? 'Resume' : 'Start' }}</button>
                            @endif
                            <button class="btn btn-sm btn-success" wire:click="complete({{ $task->id }})" wire:confirm="Mark '{{ addslashes($task->title) }}' as completed?"><x-icon name="check" class="icon-sm" />Complete</button>
                        @endif
                        <button class="btn btn-sm" wire:click="open({{ $task->id }})">Open</button>
                        @if(($canAssign && !$mine) || ($canAddOwn && $task->created_by === auth()->id()))<button class="btn btn-sm" wire:click="edit({{ $task->id }})">Edit</button>@endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="No tasks match these filters" icon="check-square" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $tasks->links() }}
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit task' : 'Assign task'" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Title" name="title" full><input wire:model="title"></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description"></textarea></x-field>
                @if($canAssign && !$mine)<x-field label="Assignee" name="assigneeId"><select wire:model="assigneeId"><option value="">Select employee</option>@foreach($assignees as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></x-field>@endif
                <x-field label="Project" name="projectId"><select wire:model="projectId"><option value="">No project</option>@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></x-field>
                <x-field label="Priority" name="taskPriority"><select wire:model="taskPriority"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></x-field>
                <x-field label="Status" name="taskStatus"><select wire:model="taskStatus"><option value="todo">To do</option><option value="in_progress">In progress</option><option value="on_hold">On hold</option><option value="completed">Completed</option></select></x-field>
                <x-field label="Estimated hours" name="estimatedHours"><input type="number" step="0.25" min="0" wire:model="estimatedHours"></x-field>
                <x-field label="Due date" name="dueOn"><input type="date" wire:model="dueOn"></x-field>
                @if($mine)<x-field label="Work date" name="workDate" hint="the day this work is for"><input type="date" wire:model="workDate" max="{{ today()->toDateString() }}"></x-field>@endif
                <x-field label="Subtask of" name="parentId" hint="optional"><select wire:model="parentId"><option value="">— not a subtask —</option>@foreach($parentChoices as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach</select></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">{{ $editingId ? 'Save task' : 'Assign task' }}</button></div></form>
        </x-modal>
    @endif
    @if($opened)
        <x-modal :title="$opened->title" size="lg">
            <div class="modal-body">
                <dl class="kv">
                    <dt>Assignee</dt><dd>{{ $opened->assignee?->name }}</dd>
                    <dt>Project</dt><dd>{{ $opened->project?->name ?? '—' }}</dd>
                    @if($opened->parent)<dt>Subtask of</dt><dd><button type="button" class="linklike" wire:click="open({{ $opened->parent->id }})">{{ $opened->parent->title }}</button></dd>@endif
                    <dt>Status</dt><dd><x-badge :status="$opened->status" /> · {{ $opened->priority }}@if($opened->due_on) · due {{ $opened->due_on->format('d M Y') }}@endif</dd>
                    <dt>Logged</dt><dd>@php($loggedOpen = $work->loggedSeconds($opened)){{ sprintf('%d:%02d', intdiv($loggedOpen, 3600), intdiv($loggedOpen % 3600, 60)) }}{{ $opened->estimated_hours !== null ? ' of '.(float) $opened->estimated_hours.' h estimated' : '' }}</dd>
                    @if($opened->description)<dt>Description</dt><dd style="white-space:pre-line">{{ $opened->description }}</dd>@endif
                </dl>
                <h3 class="mt" style="font-size:14px">Subtasks</h3>
                <div class="list">
                    @forelse($opened->subtasks as $sub)
                        <div class="list-row"><div><button type="button" class="linklike" wire:click="open({{ $sub->id }})"><strong>{{ $sub->title }}</strong></button><small>{{ $sub->assignee?->name }}</small></div><x-badge :status="$sub->status" /></div>
                    @empty
                        <p class="small muted">No subtasks.</p>
                    @endforelse
                </div>
                @if(! $opened->parent_id && (($canAssign && !$mine) || $canAddOwn))<button type="button" class="btn btn-sm mt" wire:click="create({{ $opened->id }})">+ Add subtask</button>@endif
                <h3 class="mt" style="font-size:14px">Comments</h3>
                <div class="list">
                    @forelse($opened->comments as $c)
                        <div class="list-row" style="align-items:flex-start"><div><strong>{{ $c->author?->name ?? 'Former user' }}</strong><small>{{ $c->created_at->format('d M, h:i A') }}</small></div><div class="small" style="flex:1;white-space:pre-line;margin-left:12px">{{ $c->body }}</div></div>
                    @empty
                        <p class="small muted">No comments yet.</p>
                    @endforelse
                </div>
                <form wire:submit="addComment" class="mt"><x-field label="Add a comment" name="comment"><textarea wire:model="comment" rows="2" placeholder="Question, update or blocker"></textarea></x-field><div class="form-actions"><button class="btn btn-primary btn-sm" wire:loading.attr="disabled">Post</button></div></form>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Close</button></div>
        </x-modal>
    @endif
</div>
