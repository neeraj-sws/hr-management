<div class="ds-page">
    @php
        $when = function ($status) {
            $at = $status->submitted_at ?? $status->created_at;
            $d = $status->work_date;
            $label = $d->isToday() ? 'Today' : ($d->isYesterday() ? 'Yesterday' : $d->format('D, d M'));
            return $label.($at ? ', '.$at->format('h:i A') : '');
        };
        $projectTags = fn ($status) => collect((array) ($status->project_updates ?? []))->pluck('project')->filter()->unique()->take(2);
        $tagTone = fn (string $name) => ['blue', 'green', 'violet', 'amber', 'teal'][crc32($name) % 5];
    @endphp
    <div class="page-head">
        <div><h1>Daily Work Status</h1><p>{{ $canSeeTeam ? 'Track team progress, what they worked on, and any blockers.' : 'Write what you worked on today; your manager sees it.' }}</p></div>
        <div class="actions">
            <div class="ds-date-chip"><x-icon name="calendar" class="icon-sm" />{{ $day->format('D, d M Y') }}</div>
            @if($canSetRules)<button type="button" class="btn" wire:click="openRules"><x-icon name="settings" class="icon-sm" />Who must write it</button>@endif
            @if($own)<button type="button" class="btn btn-primary" wire:click="openStatusForm"><x-icon name="{{ $savedToday ? 'edit' : 'plus' }}" class="icon-sm" />{{ $savedToday ? "Edit today's status" : "Add today's status" }}</button>@endif
        </div>
    </div>
    @if($own && ! $savedToday && ! $canSeeTeam)
        <div class="ds-nudge"><x-icon name="message-plus" class="icon-sm" /><span>You haven't written today's status yet.</span><button type="button" class="btn btn-sm btn-primary" wire:click="openStatusForm">Add today's status</button></div>
    @endif

    @if($canSeeTeam)
        {{-- Summary for the chosen day (today unless a date is picked). --}}
        <div class="ds-cards">
            <div class="ds-stat ds-stat-blue"><span class="ds-stat-icon"><x-icon name="users" /></span><div><span>Team updates</span><strong>{{ $cards['updates'] }}</strong><small>of {{ $cards['people'] }} employees</small></div></div>
            <div class="ds-stat ds-stat-green"><span class="ds-stat-icon"><x-icon name="check-square" /></span><div><span>Submitted {{ $day->isToday() ? 'today' : $day->format('d M') }}</span><strong>{{ $cards['submitted'] }} / {{ $cards['expected'] }} @if($cards['expected'])<em class="ds-pct">{{ round($cards['submitted'] * 100 / $cards['expected']) }}%</em>@endif</strong><small>vs {{ $cards['submittedBefore'] }} the day before</small></div></div>
            <div class="ds-stat ds-stat-amber"><span class="ds-stat-icon"><x-icon name="alert" /></span><div><span>Missing {{ $day->isToday() ? 'today' : $day->format('d M') }}</span><strong>{{ $cards['missing'] }}</strong><small>checked in, no status</small></div></div>
            <div class="ds-stat ds-stat-red"><span class="ds-stat-icon"><x-icon name="alert" /></span><div><span>Blockers</span><strong>{{ $cards['blockers'] }}</strong><small>need attention</small></div></div>
        </div>

        <div class="ds-filters">
            <label class="field"><span>Date</span><input type="date" wire:model.live="date" max="{{ today()->toDateString() }}"></label>
            <label class="field"><span>Department</span><select wire:model.live="department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
            <label class="field"><span>Employee</span><select wire:model.live="employee"><option value="">All employees</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
            <label class="field"><span>Project</span><select wire:model.live="project"><option value="">All projects</option>@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
            <label class="field"><span>Status</span><select wire:model.live="blockerFilter"><option value="">All status</option><option value="yes">With blockers</option><option value="no">No blockers</option></select></label>
            <label class="field ds-search"><span>Search</span><span class="emp-input-icon"><x-icon name="search" class="icon-sm" /><input type="search" wire:model.live.debounce.300ms="search" placeholder="Search updates…"></span></label>
            <button type="button" class="btn btn-primary ds-reset" wire:click="resetFilters">Reset</button>
        </div>
    @endif

    <div @class(['ds-grid' => $canSeeTeam])>
        <div class="ds-main">

            <section class="ds-card">
                <div class="ds-card-head">
                    <h2>{{ $canSeeTeam ? 'Team updates' : 'My history' }} <span class="muted">({{ $statuses->total() }})</span></h2>
                    @if($canSeeTeam)<label class="ds-sort"><span class="sr-only">Sort</span><select wire:model.live="sortBy"><option value="latest">Sort by: Latest first</option><option value="oldest">Sort by: Oldest first</option><option value="name">Sort by: Name</option></select></label>@endif
                </div>
                @if($canSeeTeam && ! $date)
                    <div class="ds-tabs" role="tablist">
                        @foreach(['all' => 'All updates', 'today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week'] as $key => $label)
                            <button type="button" role="tab" @class(['ds-tab', 'is-on' => $period === $key]) aria-selected="{{ $period === $key ? 'true' : 'false' }}" wire:click="$set('period', '{{ $key }}')">{{ $label }}@if($key !== 'all') ({{ $periodCounts[$key] }})@endif</button>
                        @endforeach
                    </div>
                @endif
                <div class="ds-list" wire:loading.class="is-loading">
                    @forelse($statuses as $status)
                        <article class="ds-row" wire:key="ds-{{ $status->id }}">
                            <div class="ds-who">
                                <x-avatar :employee="$status->employee" />
                                <div><strong>{{ $status->employee?->name }}</strong><small>{{ $status->employee?->employee_code }}@if($status->employee?->designation) · {{ $status->employee->designation }}@endif</small><small>{{ $when($status) }}</small></div>
                            </div>
                            <div class="ds-body">
                                @if($projectTags($status)->isNotEmpty())<div class="ds-tags">@foreach($projectTags($status) as $tag)<span class="ds-tag ds-tag-{{ $tagTone($tag) }}">{{ $tag }}</span>@endforeach</div>@endif
                                @if($status->plan)<p class="ds-line"><x-icon name="file" class="icon-sm" /><b>Plan:</b> {{ $status->plan }}</p>@endif
                                <p class="ds-line ds-line-done"><x-icon name="check-circle" class="icon-sm" /><b>Completed:</b> {{ $status->summary }}</p>
                                {{-- Filtered by a project: that project's own line (the others stay in View details). --}}
                                @if($project)@foreach(collect((array) ($status->project_updates ?? []))->where('project_id', (int) $project) as $line)<p class="ds-line"><x-icon name="folder" class="icon-sm" /><b>{{ $line['project'] ?? 'Project' }}:</b> {{ $line['update'] ?: collect((array) ($line['tasks'] ?? []))->pluck('title')->join(', ') }}</p>@endforeach @endif
                                @if($status->blockers)<p class="ds-line ds-line-block"><x-icon name="alert" class="icon-sm" /><b>Blockers:</b> {{ $status->blockers }}</p>@endif
                            </div>
                            <div class="ds-row-actions">
                                <details class="menu emp-menu">
                                    <summary class="btn btn-sm btn-icon" aria-label="Actions for {{ $status->employee?->name }}"><x-icon name="more" class="icon-sm" /></summary>
                                    <div class="menu-panel">
                                        <button type="button" class="menu-item" wire:click="view({{ $status->id }})"><x-icon name="eye" class="icon-sm" />View details</button>
                                        @if($status->employee && \App\Support\Navigation::canAccess(auth()->user(), 'people.employees.show', ['employee' => $status->employee->id]))<a class="menu-item" href="{{ route('people.employees.show', $status->employee) }}"><x-icon name="user" class="icon-sm" />Employee profile</a>@endif
                                        @if($status->employee?->user_id === auth()->id() && $status->work_date->isToday())<button type="button" class="menu-item" wire:click="openStatusForm"><x-icon name="edit" class="icon-sm" />Edit my status</button>@endif
                                    </div>
                                </details>
                                <button type="button" class="ds-view" wire:click="view({{ $status->id }})">View details <x-icon name="arrow-right" class="icon-sm" /></button>
                            </div>
                        </article>
                    @empty
                        <x-empty title="No status updates" icon="edit" />
                    @endforelse
                </div>
                <div class="emp-foot">
                    <span class="muted small">@if($statuses->total())Showing {{ $statuses->firstItem() }} – {{ $statuses->lastItem() }} of {{ $statuses->total() }} updates @endif</span>
                    {{ $statuses->onEachSide(1)->links('components.pager') }}
                </div>
            </section>
        </div>

        @if($canSeeTeam)
            <aside class="ds-side">
                <section class="ds-card">
                    <div class="ds-card-head">
                        <h2>Missing {{ $day->isToday() ? 'today' : $day->format('d M') }} <span class="muted">({{ $missing->count() }})</span></h2>
                        @if($missing->isNotEmpty() && $day->isToday())<button type="button" class="btn btn-sm ds-remind-all" wire:click="remindMissing" wire:confirm="Send a reminder to everyone listed?"><x-icon name="mail" class="icon-sm" />Send reminder to all</button>@endif
                    </div>
                    <div class="ds-missing">
                        @forelse($missing as $person)
                            <div class="ds-missing-row" wire:key="miss-{{ $person->id }}">
                                <x-avatar :employee="$person" />
                                <div class="grow"><strong>{{ $person->name }}</strong><small>{{ $person->employee_code }}@if($person->designation) · {{ $person->designation }}@endif</small></div>
                                <span class="ds-pill-missing">Missing</span>
                                @if($day->isToday())<button type="button" class="btn btn-sm" wire:click="remindOne({{ $person->id }})" aria-label="Send reminder to {{ $person->name }}">Send reminder</button>@endif
                            </div>
                        @empty
                            <x-empty title="Everyone is up to date" icon="check-circle" />
                        @endforelse
                    </div>
                </section>
                <section class="ds-card">
                    <div class="ds-card-head"><h2>Needs attention <span class="muted">({{ $cards['blockers'] }})</span></h2>@if($cards['blockers'] > 5)<button type="button" class="ds-view" wire:click="$set('blockerFilter', 'yes')">View all <x-icon name="arrow-right" class="icon-sm" /></button>@endif</div>
                    <div class="ds-missing">
                        @forelse($needsAttention as $status)
                            <button type="button" class="ds-attention" wire:click="view({{ $status->id }})">
                                <span class="ds-attention-icon"><x-icon name="alert" class="icon-sm" /></span>
                                <span class="grow"><strong>{{ \Illuminate\Support\Str::limit($status->blockers, 60) }}</strong><small>Reported by {{ $status->employee?->name }} · {{ ($status->submitted_at ?? $status->created_at)?->diffForHumans() }}</small></span>
                                @if($tag = $projectTags($status)->first())<span class="ds-tag ds-tag-{{ $tagTone($tag) }}">{{ $tag }}</span>@endif
                            </button>
                        @empty
                            <x-empty title="No blockers reported" icon="check-circle" />
                        @endforelse
                    </div>
                </section>
            </aside>
        @endif
    </div>

    @if($showStatusForm && $own)
        <x-modal title="Today's status · {{ today()->format('d M Y') }}" size="lg">
            <div class="ds-form-modal">@include('livewire.work.partials.daily-status-form')</div>
        </x-modal>
    @endif

    {{-- View details: a large side drawer with the full update, tabs and quick facts. --}}
    @if($detail)
        @php($e = $detail['employee'])
        @php($st = $detail['status'])
        <div class="ds-drawer-backdrop" wire:keydown.escape.window="closeView" wire:click.self="closeView">
            <aside class="ds-drawer" role="dialog" aria-modal="true" aria-label="Daily status of {{ $e->name }}">
                <header class="ds-drawer-head">
                    <div class="ds-drawer-who">
                        <x-avatar :employee="$e" size="lg" />
                        <div>
                            <h2>{{ $e->name }}</h2>
                            <p>{{ $e->employee_code }}@if($e->designation) · {{ $e->designation }}@endif @if($e->department) · {{ $e->department->name }}@endif</p>
                            <p class="small muted">@if($e->email)<a href="mailto:{{ $e->email }}">{{ $e->email }}</a>@endif @if($e->phone) · {{ $e->phone }}@endif @if($e->manager) · Manager: {{ $e->manager->name }}@endif</p>
                        </div>
                    </div>
                    <div class="ds-drawer-nav">
                        <button type="button" class="btn btn-sm btn-icon" wire:click="stepDay(-1)" @disabled(! $detail['prev']) aria-label="Previous day"><x-icon name="chevron-left" class="icon-sm" /></button>
                        <span class="ds-date-chip"><x-icon name="calendar" class="icon-sm" />{{ $st->work_date->format('D, d M Y') }}</span>
                        <button type="button" class="btn btn-sm btn-icon" wire:click="stepDay(1)" @disabled(! $detail['next']) aria-label="Next day"><x-icon name="chevron-right" class="icon-sm" /></button>
                        <button type="button" class="btn btn-sm btn-icon" wire:click="closeView" aria-label="Close"><x-icon name="x" class="icon-sm" /></button>
                    </div>
                </header>
                <nav class="ds-tabs ds-drawer-tabs" role="tablist">
                    @foreach(['work' => 'Work update', 'timesheet' => 'Timesheet', 'tasks' => 'Tasks', 'projects' => 'Projects', 'attendance' => 'Attendance', 'leaves' => 'Leaves', 'activity' => 'Activity log'] as $key => $label)
                        <button type="button" role="tab" @class(['ds-tab', 'is-on' => $viewTab === $key]) aria-selected="{{ $viewTab === $key ? 'true' : 'false' }}" wire:click="showTab('{{ $key }}')">{{ $label }}</button>
                    @endforeach
                </nav>
                <div class="ds-drawer-body">
                    <div class="ds-drawer-main" wire:loading.class="is-loading" wire:target="showTab,stepDay">
                        @switch($viewTab)
                            @case('work')
                                <section class="ds-block"><h3><x-icon name="file" class="icon-sm" />Today's plan</h3><p>{{ $st->plan ?: 'No plan written.' }}</p></section>
                                <section class="ds-block ds-block-done"><h3><x-icon name="check-circle" class="icon-sm" />Completed work</h3><p>{{ $st->summary }}</p>
                                    @foreach($detail['projectLines'] as $line)
                                        <div class="ds-proj-line"><strong>{{ $line['project'] ?? 'No project' }}</strong>@if(! empty($line['seconds'])) <span class="muted">· @hms($line['seconds'])</span>@endif
                                            @if(! empty($line['update']))<div>{{ $line['update'] }}</div>@endif
                                            @foreach((array) ($line['tasks'] ?? []) as $t)<div class="small">• {{ $t['title'] }} <span class="muted">({{ str($t['status'])->replace('_', ' ') }}@if(! empty($t['seconds'])) · @hms($t['seconds'])@endif)</span>@if(! empty($t['note'])) — {{ $t['note'] }}@endif</div>@endforeach
                                        </div>
                                    @endforeach
                                </section>
                                <section class="ds-block ds-block-block"><h3><x-icon name="alert" class="icon-sm" />Blockers / issues</h3><p>{{ $st->blockers ?: 'None reported.' }}</p></section>
                                <section class="ds-block"><h3><x-icon name="note" class="icon-sm" />Additional notes</h3>
                                    @if($st->pending)<p><b>Pending:</b> {{ $st->pending }}</p>@endif
                                    @if($st->tomorrow_plan)<p><b>Plan for tomorrow:</b> {{ $st->tomorrow_plan }}</p>@endif
                                    <p>{{ $st->notes ?: ($st->pending || $st->tomorrow_plan ? '' : 'No notes.') }}</p>
                                </section>
                                @break
                            @case('timesheet')
                                <table class="table ds-mini-table" data-no-sort><thead><tr><th>Task</th><th>Start</th><th>End</th><th>Time</th></tr></thead><tbody>
                                    @forelse($detail['tab'] as $entry)<tr><td>{{ $entry->task?->title ?? '—' }}</td><td>{{ $entry->started_at?->format('h:i A') }}</td><td>{{ $entry->ended_at?->format('h:i A') ?? 'running' }}</td><td>@hms($entry->duration_seconds)</td></tr>
                                    @empty<tr><td colspan="4"><x-empty title="No time logged this day" icon="timer" /></td></tr>@endforelse
                                </tbody></table>
                                @break
                            @case('tasks')
                                <table class="table ds-mini-table" data-no-sort><thead><tr><th>Task</th><th>Project</th><th>Status</th><th>Due</th></tr></thead><tbody>
                                    @forelse($detail['tab'] as $task)<tr><td>{{ $task->title }}</td><td>{{ $task->project?->name ?? '—' }}</td><td><x-badge :status="$task->status" /></td><td>{{ $task->due_on?->format('d M') ?? '—' }}</td></tr>
                                    @empty<tr><td colspan="4"><x-empty title="No tasks" icon="check-square" /></td></tr>@endforelse
                                </tbody></table>
                                @break
                            @case('projects')
                                <div class="ds-proj-grid">
                                    @forelse($detail['tab'] as $proj)<div class="ds-block"><h3>{{ $proj->name }}</h3><p class="small muted">{{ $proj->client_name ?: 'Internal' }} · <x-badge :status="$proj->status" /></p></div>
                                    @empty<x-empty title="Not on any project" icon="folder" />@endforelse
                                </div>
                                @break
                            @case('attendance')
                                <table class="table ds-mini-table" data-no-sort><thead><tr><th>Check-in</th><th>Check-out</th><th>Worked</th><th>Break</th><th>Status</th></tr></thead><tbody>
                                    @forelse($detail['tab'] as $session)<tr><td>{{ $session->check_in?->format('h:i A') ?? '—' }}</td><td>{{ $session->check_out?->format('h:i A') ?? '—' }}</td><td>@hms($session->active_seconds)</td><td>@hms($session->break_seconds ?? 0)</td><td><x-badge :status="$session->status" /></td></tr>
                                    @empty<tr><td colspan="5"><x-empty title="No attendance this day" icon="clock" /></td></tr>@endforelse
                                </tbody></table>
                                @break
                            @case('leaves')
                                <table class="table ds-mini-table" data-no-sort><thead><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr></thead><tbody>
                                    @forelse($detail['tab'] as $leave)<tr><td>{{ $leave->leaveType?->name }}</td><td>{{ $leave->starts_on->format('d M') }}</td><td>{{ $leave->ends_on->format('d M') }}</td><td>{{ (float) $leave->days }}</td><td><x-badge :status="$leave->status" /></td></tr>
                                    @empty<tr><td colspan="5"><x-empty title="No leave in the last 2 months" icon="calendar" /></td></tr>@endforelse
                                </tbody></table>
                                @break
                            @case('activity')
                                <ul class="ds-activity">
                                    @forelse($detail['tab'] as $log)<li><strong>{{ str($log->action)->replace(['.', '_'], ' ')->ucfirst() }}</strong><small>{{ $log->actor?->name ?? 'System' }} · {{ $log->created_at->format('d M, h:i A') }}</small></li>
                                    @empty<li class="muted">No activity recorded.</li>@endforelse
                                </ul>
                                @break
                        @endswitch
                    </div>
                    <aside class="ds-drawer-side">
                        <section class="ds-block"><h3>Submission</h3>
                            <p><span @class(['ds-sub-pill', 'is-late' => $detail['onTime'] === false])>{{ $st->submitted_at ? ($detail['onTime'] === false ? 'Submitted late' : 'Submitted') : 'Saved' }}</span></p>
                            <p class="small muted">{{ ($st->submitted_at ?? $st->created_at)?->format('d M Y, h:i A') }}</p>
                        </section>
                        <section class="ds-block"><h3>Tasks</h3>
                            <div class="ds-task-stats"><div><strong>{{ $detail['taskSummary']['completed'] }}</strong><small>completed</small></div><div><strong>{{ $detail['taskSummary']['in_progress'] }}</strong><small>in progress</small></div><div><strong>{{ $detail['taskSummary']['open'] }}</strong><small>open</small></div></div>
                        </section>
                        <section class="ds-block"><h3>Projects</h3>
                            @forelse($detail['projectLines']->pluck('project')->filter()->unique() as $name)<span class="ds-tag ds-tag-{{ $tagTone($name) }}">{{ $name }}</span>@empty<p class="small muted">No project lines.</p>@endforelse
                        </section>
                        <section class="ds-block"><h3>Quick actions</h3>
                            <div class="ds-quick">
                                @if(\App\Support\Navigation::canAccess(auth()->user(), 'people.employees.show', ['employee' => $e->id]))<a class="btn btn-sm" href="{{ route('people.employees.show', $e) }}"><x-icon name="user" class="icon-sm" />Profile</a>@endif
                                @if($e->email)<a class="btn btn-sm" href="mailto:{{ $e->email }}"><x-icon name="mail" class="icon-sm" />E-mail</a>@endif
                                @if($detail['canEdit'])<button type="button" class="btn btn-sm btn-primary" wire:click="editFromDrawer"><x-icon name="edit" class="icon-sm" />Edit my status</button>@endif
                            </div>
                        </section>
                    </aside>
                </div>
            </aside>
        </div>
    @endif
    {{-- Who must write it: per person, from today. Compulsory people missing it are listed and reminded; others never. --}}
    @if($rules)
        <x-modal title="Daily status: who must write it" size="lg" close="closeRules">
            <div class="modal-body">
                <p class="small" style="margin-top:0"><strong>Compulsory + reminder</strong>: listed as missing, reminded automatically at {{ \App\Support\DailyStatusRule::reminderLabel() }}. <strong>Compulsory, no automatic reminder</strong>: listed as missing; the Remind button still works. <strong>Not compulsory</strong>: may write it; never missing, never reminded, no penalty. A change applies from today; past days keep their rule.</p>
                <form class="dsr-default dsr-time" wire:submit="saveReminderTime">
                    <label class="field"><span>Automatic reminder time (every day, to people who checked in and have not written it)</span><input type="time" wire:model="reminderTime"></label>
                    <button class="btn btn-primary btn-sm" wire:loading.attr="disabled">Save time</button>
                    @error('reminderTime')<span class="error">{{ $message }}</span>@enderror
                </form>
                <div class="dsr-default">
                    <label class="field"><span>Company default (everyone without their own choice, and new employees)</span>
                        <select wire:change="setDefaultRule($event.target.value)">@foreach(\App\Support\DailyStatusRule::MODES as $key => $label)<option value="{{ $key }}" @selected($rules['default'] === $key)>{{ $label }}</option>@endforeach</select></label>
                </div>
                <div class="toolbar dsr-tools" style="padding:10px 0">
                    <label class="field grow"><span class="sr-only">Search</span><input type="search" wire:model.live.debounce.300ms="rulesSearch" placeholder="Search name, ID or department"></label>
                    <span class="small muted">Set everyone listed to:</span>
                    @foreach(\App\Support\DailyStatusRule::MODES as $key => $label)
                        <button type="button" class="btn btn-sm" wire:click="setRuleForAll('{{ $key }}')" wire:confirm="Set all {{ $rules['people']->count() }} people listed to &quot;{{ $label }}&quot; from today?">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="table-wrap dsr-list"><table class="table" data-no-sort>
                    <thead><tr><th>Employee</th><th>Department</th><th>Daily status</th></tr></thead>
                    <tbody>
                    @forelse($rules['people'] as $row)
                        @php($e = $row['employee'])
                        <tr wire:key="dsr-{{ $e->id }}">
                            <td><strong>{{ $e->name }}</strong><small>{{ $e->employee_code }}</small></td>
                            <td>{{ $e->department?->name ?? '—' }}</td>
                            <td><select class="dsr-select dsr-{{ $row['mode'] }}" wire:change="setRule({{ $e->id }}, $event.target.value)" aria-label="Daily status for {{ $e->name }}">
                                    <option value="default" @selected(! $row['own'])>Company default ({{ \App\Support\DailyStatusRule::MODES[$rules['default']] }})</option>
                                    @foreach(\App\Support\DailyStatusRule::MODES as $key => $label)<option value="{{ $key }}" @selected($row['own'] && $row['mode'] === $key)>{{ $label }}</option>@endforeach
                                </select></td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><x-empty title="Nobody matches" icon="users" /></td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
            <div class="modal-foot"><span class="small muted">{{ $rules['people']->where('mode', '!=', 'optional')->count() }} compulsory · {{ $rules['people']->where('mode', 'required_reminder')->count() }} with reminder · {{ $rules['people']->where('mode', 'optional')->count() }} not compulsory</span><button type="button" class="btn" wire:click="closeRules">Done</button></div>
        </x-modal>
    @endif
</div>
