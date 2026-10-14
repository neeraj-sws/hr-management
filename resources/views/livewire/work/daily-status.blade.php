<div>
    <div class="page-head"><div><h1>Daily Work Status</h1><p>Summarise what you worked on today. Managers see their team's updates.</p></div></div>
    <div class="grid grid-sidebar">
        <div class="stack">
            @if($own)
                <section class="card">
                    <div class="card-head"><div><h2>Today · {{ today()->format('d M Y') }}</h2><p>You can update it until the end of the day.</p></div></div>
                    <form class="card-body" wire:submit="save">
                        <x-field label="Yesterday" name="yesterday"><textarea wire:model="yesterday" rows="2" placeholder="What you did yesterday"></textarea></x-field>
                        <x-field label="Plan for today" name="plan"><textarea wire:model="plan" rows="2" placeholder="What you intend to do"></textarea></x-field>
                        <x-field label="Completed" name="summary"><textarea id="daily-summary-field" wire:model="summary" rows="4" placeholder="What you finished or moved forward"></textarea></x-field>
                        @if($byProject->isNotEmpty())
                            <div class="field"><span class="label">Project-wise status</span>
                                @foreach($byProject as $row)
                                    @php($key = (string) ($row['project']?->id ?? 0))
                                    <div class="card card-body" style="padding:10px;margin-bottom:8px" wire:key="dsp-{{ $key }}">
                                        <strong>{{ $row['project']?->name ?? 'No project' }}</strong>
                                        @if($row['tasks']->isNotEmpty())<small class="muted">{{ $row['tasks']->map(fn ($t) => $t->title.' ('.str($t->status)->replace('_', ' ').')')->join(', ') }}@if($row['seconds']) · @hms($row['seconds']) logged today @endif</small>@endif
                                        <textarea wire:model="projectUpdates.{{ $key }}" rows="2" placeholder="What you did on {{ $row['project']?->name ?? 'other work' }} today"></textarea>
                                    </div>
                                @endforeach
                                <span class="small muted">Your manager and the project managers are notified (in the app and by e-mail).</span>
                            </div>
                        @endif
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
                        <div class="list-row" style="align-items:flex-start"><div style="min-width:160px"><strong>{{ $status->employee?->name }}</strong><small>{{ $status->work_date->format('D, d M Y') }}</small></div><div class="small" style="flex:1;white-space:pre-line">@if($status->yesterday)<div><span class="muted">Yesterday:</span> {{ $status->yesterday }}</div>@endif@if($status->plan)<div><span class="muted">Plan:</span> {{ $status->plan }}</div>@endif<div><span class="muted">Completed:</span> {{ $status->summary }}</div>@foreach((array) ($status->project_updates ?? []) as $line)<div style="margin-top:4px;padding-left:8px;border-left:3px solid var(--line)"><strong>{{ $line['project'] ?? 'No project' }}</strong>@if(! empty($line['seconds'])) <span class="muted">· @hms($line['seconds'])</span>@endif @if(! empty($line['update']))<div>{{ $line['update'] }}</div>@endif @if(! empty($line['tasks']))<small>{{ collect($line['tasks'])->map(fn ($t) => $t['title'].' ('.str($t['status'])->replace('_', ' ').')')->join(', ') }}</small>@endif</div>@endforeach@if($status->pending)<div><span class="muted">Pending:</span> {{ $status->pending }}</div>@endif @if($status->tomorrow_plan)<div><span class="muted">Tomorrow:</span> {{ $status->tomorrow_plan }}</div>@endif @if($status->blockers)<div style="color:var(--red)"><span class="muted">Blockers:</span> {{ $status->blockers }}</div>@endif@if($status->notes)<div><span class="muted">Notes:</span> {{ $status->notes }}</div>@endif@if($status->submitted_at)<small>submitted {{ $status->submitted_at->format('d M, h:i A') }}</small>@endif</div></div>
                    @empty
                        <x-empty title="No status updates" icon="edit" />
                    @endforelse
                </div>
                {{ $statuses->links() }}
            </section>
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
