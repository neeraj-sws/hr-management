{{-- The signed-in employee's own status for today (plan, project / task lines, completed, blockers …). --}}
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
