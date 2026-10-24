<div>
    <div class="page-head"><div><h1>Daily Work Status</h1><p>Summarise what you worked on today. Managers see their team's updates.</p></div></div>
    <div class="grid grid-sidebar">
        <div class="stack">
            @if($canSeeTeam && $employee)
            <section class="card">
                <div class="card-head"><h2>{{ $canSeeTeam ? 'Team updates' : 'My history' }}</h2></div>
                @if($canSeeTeam)
                    <div class="toolbar">
                        <label class="field"><span>Date</span><input type="date" wire:model.live="date"></label>
                        <label class="field grow"><span>Employee</span><select wire:model.live="employee"><option value="">Everyone</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
                        <label class="field"><span>Project</span><select wire:model.live="project"><option value="">All projects</option>@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
                    </div>
                @endif
                <div class="list">
                    @forelse($statuses as $status)
                        <div class="list-row" style="align-items:flex-start"><div style="min-width:160px"><strong>{{ $status->employee?->name }}</strong><small>{{ $status->work_date->format('D, d M Y') }}</small></div><div class="small" style="flex:1;white-space:pre-line">@if($status->plan)<div><span class="muted">Plan:</span> {{ $status->plan }}</div>@endif<div><span class="muted">Completed:</span> {{ $status->summary }}</div>@foreach(collect((array) ($status->project_updates ?? []))->when($project, fn ($lines) => $lines->where('project_id', (int) $project)) as $line)<div style="margin-top:4px;padding-left:8px;border-left:3px solid var(--line)"><strong>{{ $line['project'] ?? 'No project' }}</strong>@if(! empty($line['seconds'])) <span class="muted">· @hms($line['seconds'])</span>@endif @if(! empty($line['update']))<div>{{ $line['update'] }}</div>@endif @foreach((array) ($line['tasks'] ?? []) as $t)<div class="small">• {{ $t['title'] }} <span class="muted">({{ str($t['status'])->replace('_', ' ') }}@if(! empty($t['seconds'])) · @hms($t['seconds'])@endif)</span>@if(! empty($t['note'])) — {{ $t['note'] }}@endif</div>@endforeach</div>@endforeach@if($status->pending)<div><span class="muted">Pending:</span> {{ $status->pending }}</div>@endif @if($status->tomorrow_plan)<div><span class="muted">Tomorrow:</span> {{ $status->tomorrow_plan }}</div>@endif @if($status->blockers)<div style="color:var(--red)"><span class="muted">Blockers:</span> {{ $status->blockers }}</div>@endif@if($status->notes)<div><span class="muted">Notes:</span> {{ $status->notes }}</div>@endif@if($status->submitted_at)<small>submitted {{ $status->submitted_at->format('d M, h:i A') }}</small>@endif</div></div>
                    @empty
                        <x-empty title="No status updates" icon="edit" />
                    @endforelse
                </div>
                {{ $statuses->links() }}
            </section>
            @if($own)
                <section class="card">
                    <div class="card-head"><div><h2>Today · {{ today()->format('d M Y') }}</h2><p>You can update it until the end of the day.</p></div></div>
                    <form class="card-body" wire:submit="save">
                        <x-errors />
                        <x-field label="Plan for today" name="plan"><textarea wire:model="plan" rows="2" placeholder="What you will work on today (fill this in the morning)"></textarea></x-field>
                        @if($byProject->isNotEmpty())
                            <div class="field"><span class="label">Project &amp; task-wise status</span>
                                @foreach($byProject as $row)
                                    @php($key = (string) ($row['project']?->id ?? 0))
                                    <div class="ds-project" wire:key="dsp-{{ $key }}">
                                        <div class="ds-project-head"><strong>{{ $row['project']?->name ?? 'Other work (no project)' }}</strong>@if($row['project']?->client_name)<small class="muted"> · {{ $row['project']->client_name }}</small>@endif @if($row['seconds'])<span class="pill">@hms($row['seconds']) today</span>@endif</div>
                                        @foreach($row['tasks'] as $t)
                                            <div class="ds-task" wire:key="dst-{{ $t->id }}">
                                                <div class="ds-task-title"><span>{{ $t->title }}</span> <x-badge :status="$t->status" />@if($t->progress !== null)<small class="muted"> {{ $t->progress }}%</small>@endif @if($t->seconds_today)<small class="muted"> · @hms($t->seconds_today)</small>@endif</div>
                                                <input type="text" wire:model="taskNotes.{{ $t->id }}" placeholder="What you did on this task (optional)">
                                            </div>
                                        @endforeach
                                        <textarea wire:model="projectUpdates.{{ $key }}" rows="2" placeholder="Overall update for {{ $row['project']?->name ?? 'other work' }} (optional)"></textarea>
                                    </div>
                                @endforeach
                                <span class="small muted">Your manager and the project managers are notified (in the app and by e-mail).</span>
                            </div>
                        @endif
                        <x-field label="Completed" name="summary" :hint="$byProject->isNotEmpty() ? 'optional when you wrote project / task lines above' : null"><textarea id="daily-summary-field" wire:model="summary" rows="3" placeholder="What you finished or moved forward"></textarea></x-field>
                        <x-field label="Pending work" name="pending"><textarea wire:model="pending" rows="2" placeholder="Started but not finished yet"></textarea></x-field>
                        <x-field label="Plan for tomorrow" name="tomorrowPlan"><textarea wire:model="tomorrowPlan" rows="2" placeholder="What you will pick up next working day"></textarea></x-field>
                        <x-field label="Blockers" name="blockers" hint="your manager is notified"><textarea wire:model="blockers" rows="2" placeholder="Anything stopping you"></textarea></x-field>
                        <x-field label="Notes" name="notes"><textarea wire:model="notes" rows="2"></textarea></x-field>
                        @if($todayTasks->isNotEmpty())<p class="small muted">Tasks touched today: {{ $todayTasks->pluck('title')->join(', ') }}</p>@endif
                        <div class="form-actions">
                            <button type="button" class="btn" title="Saves to this browser and syncs when you are back online"
                                    onclick="window.SwsOfflineQueue.enqueue({action_id: crypto.randomUUID(), type: 'daily_status.submit', base_version: {{ (int) (\App\Models\DailyWorkStatus::where('employee_id', $own->id)->whereDate('work_date', today())->value('server_version') ?? 0) }}, payload: {work_date: '{{ today()->toDateString() }}', summary: document.getElementById('daily-summary-field').value}})">Save offline</button>
                            <button class="btn btn-primary" wire:loading.attr="disabled">Save status</button>
                        </div>
                        <p class="small muted" data-offline-sync-status>Offline sync: ready</p>
                    </form>
                </section>
            @endif
            @else
            @if($own)
                <section class="card">
                    <div class="card-head"><div><h2>Today · {{ today()->format('d M Y') }}</h2><p>You can update it until the end of the day.</p></div></div>
                    <form class="card-body" wire:submit="save">
                        <x-errors />
                        <x-field label="Plan for today" name="plan"><textarea wire:model="plan" rows="2" placeholder="What you will work on today (fill this in the morning)"></textarea></x-field>
                        @if($byProject->isNotEmpty())
                            <div class="field"><span class="label">Project &amp; task-wise status</span>
                                @foreach($byProject as $row)
                                    @php($key = (string) ($row['project']?->id ?? 0))
                                    <div class="ds-project" wire:key="dsp-{{ $key }}">
                                        <div class="ds-project-head"><strong>{{ $row['project']?->name ?? 'Other work (no project)' }}</strong>@if($row['project']?->client_name)<small class="muted"> · {{ $row['project']->client_name }}</small>@endif @if($row['seconds'])<span class="pill">@hms($row['seconds']) today</span>@endif</div>
                                        @foreach($row['tasks'] as $t)
                                            <div class="ds-task" wire:key="dst-{{ $t->id }}">
                                                <div class="ds-task-title"><span>{{ $t->title }}</span> <x-badge :status="$t->status" />@if($t->progress !== null)<small class="muted"> {{ $t->progress }}%</small>@endif @if($t->seconds_today)<small class="muted"> · @hms($t->seconds_today)</small>@endif</div>
                                                <input type="text" wire:model="taskNotes.{{ $t->id }}" placeholder="What you did on this task (optional)">
                                            </div>
                                        @endforeach
                                        <textarea wire:model="projectUpdates.{{ $key }}" rows="2" placeholder="Overall update for {{ $row['project']?->name ?? 'other work' }} (optional)"></textarea>
                                    </div>
                                @endforeach
                                <span class="small muted">Your manager and the project managers are notified (in the app and by e-mail).</span>
                            </div>
                        @endif
                        <x-field label="Completed" name="summary" :hint="$byProject->isNotEmpty() ? 'optional when you wrote project / task lines above' : null"><textarea id="daily-summary-field" wire:model="summary" rows="3" placeholder="What you finished or moved forward"></textarea></x-field>
                        <x-field label="Pending work" name="pending"><textarea wire:model="pending" rows="2" placeholder="Started but not finished yet"></textarea></x-field>
                        <x-field label="Plan for tomorrow" name="tomorrowPlan"><textarea wire:model="tomorrowPlan" rows="2" placeholder="What you will pick up next working day"></textarea></x-field>
                        <x-field label="Blockers" name="blockers" hint="your manager is notified"><textarea wire:model="blockers" rows="2" placeholder="Anything stopping you"></textarea></x-field>
                        <x-field label="Notes" name="notes"><textarea wire:model="notes" rows="2"></textarea></x-field>
                        @if($todayTasks->isNotEmpty())<p class="small muted">Tasks touched today: {{ $todayTasks->pluck('title')->join(', ') }}</p>@endif
                        <div class="form-actions">
                            <button type="button" class="btn" title="Saves to this browser and syncs when you are back online"
                                    onclick="window.SwsOfflineQueue.enqueue({action_id: crypto.randomUUID(), type: 'daily_status.submit', base_version: {{ (int) (\App\Models\DailyWorkStatus::where('employee_id', $own->id)->whereDate('work_date', today())->value('server_version') ?? 0) }}, payload: {work_date: '{{ today()->toDateString() }}', summary: document.getElementById('daily-summary-field').value}})">Save offline</button>
                            <button class="btn btn-primary" wire:loading.attr="disabled">Save status</button>
                        </div>
                        <p class="small muted" data-offline-sync-status>Offline sync: ready</p>
                    </form>
                </section>
            @endif
            <section class="card">
                <div class="card-head"><h2>{{ $canSeeTeam ? 'Team updates' : 'My history' }}</h2></div>
                @if($canSeeTeam)
                    <div class="toolbar">
                        <label class="field"><span>Date</span><input type="date" wire:model.live="date"></label>
                        <label class="field grow"><span>Employee</span><select wire:model.live="employee"><option value="">Everyone</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
                        <label class="field"><span>Project</span><select wire:model.live="project"><option value="">All projects</option>@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
                    </div>
                @endif
                <div class="list">
                    @forelse($statuses as $status)
                        <div class="list-row" style="align-items:flex-start"><div style="min-width:160px"><strong>{{ $status->employee?->name }}</strong><small>{{ $status->work_date->format('D, d M Y') }}</small></div><div class="small" style="flex:1;white-space:pre-line">@if($status->plan)<div><span class="muted">Plan:</span> {{ $status->plan }}</div>@endif<div><span class="muted">Completed:</span> {{ $status->summary }}</div>@foreach(collect((array) ($status->project_updates ?? []))->when($project, fn ($lines) => $lines->where('project_id', (int) $project)) as $line)<div style="margin-top:4px;padding-left:8px;border-left:3px solid var(--line)"><strong>{{ $line['project'] ?? 'No project' }}</strong>@if(! empty($line['seconds'])) <span class="muted">· @hms($line['seconds'])</span>@endif @if(! empty($line['update']))<div>{{ $line['update'] }}</div>@endif @foreach((array) ($line['tasks'] ?? []) as $t)<div class="small">• {{ $t['title'] }} <span class="muted">({{ str($t['status'])->replace('_', ' ') }}@if(! empty($t['seconds'])) · @hms($t['seconds'])@endif)</span>@if(! empty($t['note'])) — {{ $t['note'] }}@endif</div>@endforeach</div>@endforeach@if($status->pending)<div><span class="muted">Pending:</span> {{ $status->pending }}</div>@endif @if($status->tomorrow_plan)<div><span class="muted">Tomorrow:</span> {{ $status->tomorrow_plan }}</div>@endif @if($status->blockers)<div style="color:var(--red)"><span class="muted">Blockers:</span> {{ $status->blockers }}</div>@endif@if($status->notes)<div><span class="muted">Notes:</span> {{ $status->notes }}</div>@endif@if($status->submitted_at)<small>submitted {{ $status->submitted_at->format('d M, h:i A') }}</small>@endif</div></div>
                    @empty
                        <x-empty title="No status updates" icon="edit" />
                    @endforelse
                </div>
                {{ $statuses->links() }}
            </section>
            @endif
        </div>
        @if($canSeeTeam)
            <section class="card">
                <div class="card-head"><div><h2>Missing today</h2><p>Checked in, but no status yet</p></div>@if($missing->isNotEmpty())<button class="btn btn-sm" wire:click="remindMissing" wire:confirm="Send a reminder to everyone listed?">Remind</button>@endif</div>
                <div class="list">
                    @forelse($missing as $person)<div class="list-row"><strong>{{ $person->name }}</strong><x-badge status="pending">missing</x-badge></div>
                    @empty<x-empty title="Everyone is up to date" icon="check-circle" />@endforelse
                </div>
            </section>
        @endif
    </div>
</div>
