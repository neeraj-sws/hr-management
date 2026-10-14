<div>
    <div class="page-head"><div><h1>Task Timer</h1><p>One timer runs at a time. Starting a break or checking out pauses it automatically.</p></div><a class="btn" href="{{ route('work.my-tasks') }}"><x-icon name="list" class="icon-sm" />My tasks</a></div>
    <x-errors />
    @if(!$session || $session->check_out)
        <div class="alert alert-info">{{ !$session ? 'You have not checked in today. Time is still logged, but remember to check in from My Attendance.' : 'You have checked out for today.' }}</div>
    @elseif($session->status === 'on_break')
        <div class="alert alert-info">You are on a break. Resume work from My Attendance before starting a timer.</div>
    @endif
    <div class="grid grid-sidebar">
        <section class="card card-body">
            @if($running)
                <p class="eyebrow">Running · {{ $running->task->project?->name ?? 'No project' }}</p>
                <h2 style="font-size:20px">{{ $running->task->title }}</h2>
                <div class="timer-display mt" x-data="{ start: {{ $running->started_at->getTimestamp() }}, now: Math.floor(Date.now() / 1000) }" x-init="setInterval(() => now = Math.floor(Date.now() / 1000), 1000)"
                     x-text="(() => { const s = Math.max(0, now - start); return [Math.floor(s / 3600), Math.floor(s % 3600 / 60), s % 60].map(v => String(v).padStart(2, '0')).join(':') })()">@hms($running->started_at->diffInSeconds(now()))</div>
                <p class="small muted">Started {{ $running->started_at->format('h:i A') }}</p>
                <div class="actions mt">
                    <button class="btn btn-lg" wire:click="pause" wire:loading.attr="disabled"><x-icon name="pause" />Pause</button>
                    <button class="btn btn-success btn-lg" wire:click="complete" wire:confirm="Complete this task?" wire:loading.attr="disabled"><x-icon name="check" />Complete task</button>
                </div>
            @else
                <p class="eyebrow">No timer running</p>
                <form wire:submit="start" class="stack">
                    <x-field label="Pick a task" name="taskId"><select wire:model="taskId"><option value="">Select one of your open tasks</option>@foreach($openTasks->groupBy(fn ($t) => $t->project?->name ?? 'No project') as $projectName => $group)<optgroup label="{{ $projectName }}">@foreach($group as $t)<option value="{{ $t->id }}">{{ $t->title }}@if($t->due_on) — due {{ $t->due_on->format('d M') }}@endif @if($t->creator && $t->creator->id !== auth()->id()) · from {{ $t->creator->name }}@endif</option>@endforeach</optgroup>@endforeach</select></x-field>
                    <div><button class="btn btn-primary btn-lg" @disabled($openTasks->isEmpty()) wire:loading.attr="disabled"><x-icon name="play" />Start timer</button></div>
                    @if($openTasks->isEmpty())<p class="small muted">You have no open tasks.</p>@endif
                </form>
            @endif
        </section>
        <section class="card">
            <div class="card-head"><div><h2>Today</h2><p>@hms($todaySeconds) logged</p></div></div>
            <div class="list">
                @forelse($entries as $entry)
                    <div class="list-row"><div><strong>{{ $entry->task->title }}</strong><small>{{ $entry->started_at->format('h:i A') }} – {{ $entry->ended_at?->format('h:i A') ?? 'now' }}</small></div><span class="small">{{ $entry->status === 'running' ? 'running' : \App\Support\Duration::hms((int) $entry->duration_seconds) }}</span></div>
                @empty
                    <x-empty title="No time logged today" icon="timer" />
                @endforelse
            </div>
        </section>
    </div>
</div>
