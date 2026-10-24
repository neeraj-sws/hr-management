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
    {{-- Status cards: clicking one filters the list (Overdue = open tasks past their due date). --}}
    @php($statusCards = [
        ['label' => 'All', 'count' => $counts['total'], 'tone' => 'navy', 'active' => $status === '' && $due !== 'overdue', 'click' => "\$set('due', ''); \$set('status', '')"],
        ['label' => 'To do', 'count' => $counts['todo'], 'tone' => 'blue', 'active' => $status === 'todo', 'click' => "\$set('due', ''); \$set('status', 'todo')"],
        ['label' => 'In progress', 'count' => $counts['in_progress'], 'tone' => 'green', 'active' => $status === 'in_progress', 'click' => "\$set('due', ''); \$set('status', 'in_progress')"],
        ['label' => 'On hold', 'count' => $counts['on_hold'], 'tone' => 'amber', 'active' => $status === 'on_hold', 'click' => "\$set('due', ''); \$set('status', 'on_hold')"],
        ['label' => 'Overdue', 'count' => $counts['overdue'], 'tone' => 'red', 'active' => $due === 'overdue', 'click' => "\$set('status', ''); \$set('due', 'overdue')"],
        ['label' => 'Completed', 'count' => $counts['completed'], 'tone' => 'gray', 'active' => $status === 'completed', 'click' => "\$set('due', ''); \$set('status', 'completed')"],
    ])
    <x-status-cards label="Tasks by status" :items="$statusCards" />
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Task title"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">All (open first)</option><option value="open">Open</option><option value="todo">To do</option><option value="in_progress">In progress</option><option value="on_hold">On hold</option><option value="completed">Completed</option></select></label>
            <label class="field"><span>Priority</span><select wire:model.live="priority"><option value="">Any</option><option value="urgent">Urgent</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option></select></label>
            <label class="field"><span>Project</span><select wire:model.live="project"><option value="">Any</option>@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
            @if(!$mine && $assignees->isNotEmpty())<label class="field"><span>Assignee</span><select wire:model.live="assignee"><option value="">Anyone</option>@foreach($assignees as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>@endif
            <label class="field"><span>Due</span><select wire:model.live="due"><option value="">Any time</option><option value="overdue">Overdue</option><option value="today">Due today</option><option value="week">Next 7 days</option><option value="late">Completed late</option><option value="custom">Custom dates…</option></select></label>
            <label class="field"><span>Sort</span><select wire:model.live="sort"><option value="newest">Newest first</option><option value="due">Due date</option><option value="priority">Priority</option></select></label>
            @if($due === 'custom')<label class="field"><span>Due from</span><input type="date" wire:model.live="dueFrom"></label><label class="field"><span>Due to</span><input type="date" wire:model.live="dueTo"></label>@endif
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Task</th>@unless($mine)<th>Assignee</th>@endunless<th>Priority</th><th>Due</th><th class="num">Est / logged</th><th>Progress</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($tasks as $task)
                @php($logged = $work->loggedSeconds($task))
                <tr wire:key="t-{{ $task->id }}">
                    <td><button type="button" class="linklike" wire:click="open({{ $task->id }})"><strong>@if($task->parent)<span class="muted">{{ \Illuminate\Support\Str::limit($task->parent->title, 25) }} › </span>@endif{{ $task->title }}</strong></button><small>{{ $task->project?->name ?? 'No project' }}@if($task->subtasks_count) · {{ $task->subtasks_done_count }}/{{ $task->subtasks_count }} subtasks @endif @if($task->comments_count) · 💬 {{ $task->comments_count }}@endif @if($task->work_date) · for {{ $task->work_date->format('d M') }}@endif</small>@if($task->creator && $task->created_by !== $task->assignee?->user_id)<small>Assigned by {{ $task->creator->name }}</small>@endif</td>
                    @unless($mine)<td>{{ $task->assignee?->name }}</td>@endunless
                    <td><x-badge :status="$task->priority" /></td>
                    <td class="nowrap">@if($task->due_on){{ $task->due_on->format('d M Y') }}@if($task->isOverdue())<small style="color:var(--red)">{{ $task->due_on->diffInDays(today()) }} days overdue</small>@elseif($task->wasCompletedLate())<small style="color:var(--amber)">completed late</small>@endif @else — @endif</td>
                    <td class="num nowrap">{{ $task->estimated_hours !== null ? (float) $task->estimated_hours.'h' : '—' }} / @hms($logged)</td>
                    @php($pct = $task->progressPercent($logged))
                    <td style="min-width:120px"><div class="progress"><span style="width: {{ $pct }}%"></span></div><small>{{ $pct }}%@if($task->progress === null && $task->status !== 'completed') <span class="muted">(auto)</span>@endif</small>
                        @if($task->status !== 'completed' && (($own && $task->assignee_id === $own->id) || ($canAssign && ! $mine)))<select class="input" style="width:auto;padding:2px 6px;font-size:11px" aria-label="Set progress" x-on:change="$wire.updateProgress({{ $task->id }}, parseInt($event.target.value))"><option value="">Set %</option>@foreach([0, 10, 25, 50, 75, 90, 100] as $step)<option value="{{ $step }}">{{ $step }}%</option>@endforeach</select>@endif</td>
                    <td><x-badge :status="$task->status">{{ $task->status === 'completed' ? 'Completed' : '' }}</x-badge>@if($task->status === 'completed' && $task->completed_at)<small>{{ $task->completed_at->format('d M Y, h:i A') }}</small>@endif @if($runningTaskId === $task->id)<small style="color:var(--green)">● timer running</small>@endif</td>
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
                <tr><td colspan="8"><x-empty title="No tasks match these filters" icon="check-square" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $tasks->links() }}
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit task' : ($parentId ? 'Add subtask' : 'Assign task')" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Title" name="title" full><input wire:model="title"></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description"></textarea></x-field>
                @if($canAssign && !$mine)<x-field label="Assignee" name="assigneeId"><select wire:model="assigneeId"><option value="">Select employee</option>@foreach($assignees as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></x-field>@endif
                <x-field label="Project" name="projectId"><select wire:model="projectId"><option value="">No project</option>@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></x-field>
                <x-field label="Priority" name="taskPriority"><select wire:model="taskPriority"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></x-field>
                <x-field label="Status" name="taskStatus"><select wire:model="taskStatus"><option value="todo">To do</option><option value="in_progress">In progress</option><option value="on_hold">On hold</option><option value="completed">Completed</option></select></x-field>
                <x-field label="Estimated hours" name="estimatedHours"><input type="number" step="0.25" min="0" wire:model="estimatedHours"></x-field>
                <x-field label="Progress %" name="taskProgress" hint="empty = automatic"><input type="number" min="0" max="100" step="5" wire:model="taskProgress"></x-field>
                <x-field label="Due date" name="dueOn"><input type="date" wire:model="dueOn"></x-field>
                @if($mine)<x-field label="Work date" name="workDate" hint="the day this work is for"><input type="date" wire:model="workDate" max="{{ today()->toDateString() }}"></x-field>@endif
                <x-field label="Subtask of" name="parentId" hint="optional"><select wire:model="parentId"><option value="">— not a subtask —</option>@foreach($parentChoices as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach</select></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">{{ $editingId ? 'Save task' : 'Assign task' }}</button></div></form>
        </x-modal>
    @endif
    @if($opened)
        @php($loggedOpen = $work->loggedSeconds($opened))
        @php($pctOpen = $opened->progressPercent($loggedOpen))
        <x-modal :title="$opened->title" size="lg">
            <div class="modal-body task-view">
                <div class="task-view-head">
                    <div class="pill-row">
                        <x-badge :status="$opened->status">{{ $opened->status === 'completed' ? 'Completed' : '' }}</x-badge>
                        <x-badge :status="$opened->priority" />
                        @if($opened->isOverdue())<x-badge tone="red">{{ $opened->due_on->diffInDays(today()) }} days overdue</x-badge>@elseif($opened->wasCompletedLate())<x-badge tone="amber">completed late</x-badge>@endif
                        @if($opened->parent)<span class="pill">Subtask of <button type="button" class="linklike" wire:click="open({{ $opened->parent->id }})">{{ $opened->parent->title }}</button></span>@endif
                    </div>
                    <div class="task-view-progress"><div class="progress"><span style="width: {{ $pctOpen }}%"></span></div><small>{{ $pctOpen }}% done @if($opened->progress === null && $opened->status !== 'completed') (auto from time)@endif</small></div>
                </div>
                <div class="task-view-grid">
                    <div><span>Assignee</span><strong>{{ $opened->assignee?->name ?? '—' }}</strong></div>
                    <div><span>Assigned by</span><strong>{{ $opened->creator?->name ?? '—' }}</strong><small>{{ $opened->created_at?->format('d M Y, h:i A') }}</small></div>
                    <div><span>Project</span><strong>{{ $opened->project?->name ?? 'No project' }}</strong>@if($opened->project?->client_name)<small>{{ $opened->project->client_name }}</small>@endif</div>
                    <div><span>Due</span><strong>{{ $opened->due_on?->format('d M Y') ?? '—' }}</strong></div>
                    <div><span>Time logged</span><strong>@hms($loggedOpen)</strong><small>{{ $opened->estimated_hours !== null ? 'of '.(float) $opened->estimated_hours.' h estimated' : 'no estimate' }}</small></div>
                    <div><span>Completed</span><strong>{{ $opened->completed_at?->format('d M Y, h:i A') ?? '—' }}</strong></div>
                </div>
                @if($opened->description)<div class="task-view-section"><h3>Description</h3><p style="white-space:pre-line;margin:0">{{ $opened->description }}</p></div>@endif
                <div class="task-view-section">
                    <div class="task-view-section-head"><h3>Subtasks @if($opened->subtasks->isNotEmpty())<span class="muted">· {{ $opened->subtasks->where('status', 'completed')->count() }}/{{ $opened->subtasks->count() }} done</span>@endif</h3>
                        @if(! $opened->parent_id && (($canAssign && !$mine) || $canAddOwn))<button type="button" class="btn btn-sm" wire:click="create({{ $opened->id }})"><x-icon name="plus" class="icon-sm" />Add subtask</button>@endif</div>
                    <div class="list bordered">
                        @forelse($opened->subtasks as $sub)
                            <div class="list-row" wire:key="sub-{{ $sub->id }}"><div><button type="button" class="linklike" wire:click="open({{ $sub->id }})"><strong>{{ $sub->title }}</strong></button><small>{{ $sub->assignee?->name }}@if($sub->due_on) · due {{ $sub->due_on->format('d M') }}@endif</small></div><x-badge :status="$sub->status" /></div>
                        @empty
                            <p class="small muted" style="padding:10px 14px;margin:0">No subtasks yet.</p>
                        @endforelse
                    </div>
                </div>
                <div class="task-view-section">
                    <h3>History</h3>
                    <ol class="timeline">
                        @forelse($history as $event)
                            <li><strong>{{ str($event->action)->after('task.')->replace('_', ' ')->ucfirst() }}</strong> <span class="muted">by {{ $event->actor?->name ?? 'System' }}@if(isset($event->metadata['duration_seconds'])) · @hms($event->metadata['duration_seconds'])@endif @if(isset($event->metadata['to'])) · {{ $event->metadata['to'] }}%@endif</span><small>{{ $event->created_at?->format('d M Y, h:i A') }}</small></li>
                        @empty
                            <li class="muted">No history yet.</li>
                        @endforelse
                    </ol>
                </div>
                <div class="task-view-section">
                    <h3>Comments @if($opened->comments->isNotEmpty())<span class="muted">· {{ $opened->comments->count() }}</span>@endif</h3>
                    @foreach($opened->comments as $c)
                        <div class="comment"><div><strong>{{ $c->author?->name ?? 'Former user' }}</strong> <small class="muted">{{ $c->created_at->format('d M, h:i A') }}</small></div><div style="white-space:pre-line">{{ $c->body }}</div></div>
                    @endforeach
                    <form wire:submit="addComment" class="mt"><x-field label="Add a comment" name="comment"><textarea wire:model="comment" rows="2" placeholder="Question, update or blocker"></textarea></x-field><div class="form-actions"><button class="btn btn-primary btn-sm" wire:loading.attr="disabled">Post</button></div></form>
                </div>
            </div>
            <div class="modal-foot">
                @if(($canAssign && !$mine) || ($canAddOwn && $opened->created_by === auth()->id()))<button type="button" class="btn" wire:click="edit({{ $opened->id }})">Edit</button>@endif
                <button type="button" class="btn" wire:click="closeModal">Close</button>
            </div>
        </x-modal>
    @endif
</div>
