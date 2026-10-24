<div class="tm-page" @if($isToday) wire:poll.60s @endif>
    <div class="page-head">
        <div><h1>Team monitoring</h1><p>Live work status of your team. Every view is logged.</p></div>
        <div class="actions">@can('monitoring.view')<a class="btn" href="{{ route('monitoring.audit') }}"><x-icon name="history" class="icon-sm" />Monitoring audit log</a>@endcan</div>
    </div>

    <div class="tm-cards">
        @foreach([['working', 'Working now', 'tm-green'], ['break', 'On break', 'tm-yellow'], ['idle', 'Idle', 'tm-orange'], ['not_in', 'Not checked in', 'tm-grey']] as [$key, $label, $tone])
            <button type="button" @class(['tm-card', $tone, 'is-active' => $status === $key]) wire:click="$set('status', '{{ $status === $key ? '' : $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}"><i></i><span>{{ $label }}</span><strong>{{ $counts[$key] }}</strong></button>
        @endforeach
        <div class="tm-card tm-red"><i></i><span>Screen capture ON</span><strong>{{ $counts['capture'] }}</strong></div>
    </div>

    <div class="tm-filters">
        <label class="field tm-search"><span>Search</span><span class="emp-input-icon"><x-icon name="search" class="icon-sm" /><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or employee code"></span></label>
        <div class="field"><span>Status</span><div class="docs-chips tm-chips" role="group" aria-label="Status">
            @foreach(['' => 'All', 'working' => 'Working', 'break' => 'Break', 'idle' => 'Idle', 'not_in' => 'Not checked in'] as $key => $label)
                <button type="button" @class(['docs-chip', 'is-active' => $status === $key]) wire:click="$set('status', '{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div></div>
        @if($orgWide)
            <label class="field"><span>Department</span><select wire:model.live="department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
            <label class="field"><span>Manager</span><select wire:model.live="manager"><option value="">All managers</option>@foreach($managers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></label>
        @endif
        <label class="field"><span>Date</span><input type="date" wire:model.live="date" max="{{ today()->toDateString() }}"></label>
        <button type="button" class="btn tm-reset" wire:click="resetFilters"><x-icon name="refresh" class="icon-sm" />Reset</button>
    </div>

    <section class="tm-table-card">
        <div class="table-wrap"><table class="table tm-table" data-no-sort>
            <thead><tr><th>Employee</th><th>Status</th><th>Check-in</th><th>Worked</th><th class="tm-bar-col">Day activity</th><th>Capture</th><th>Screenshots</th><th></th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                @php($e = $row['employee'])
                <tr wire:key="tm-{{ $e->id }}">
                    <td data-label="Employee"><div class="emp-person"><x-avatar :employee="$e" /><div class="emp-person-text"><strong>{{ $e->name }}</strong><small>{{ $e->employee_code }}</small><x-person-badges :user="$e->user" :employee="$e" /></div></div></td>
                    <td data-label="Status"><span class="tm-status" style="--c:{{ $row['status_color'] }}"><i></i>{{ $row['status_label'] }}</span></td>
                    <td data-label="Check-in" class="nowrap">{{ $row['check_in']?->format('h:i A') ?? '—' }}</td>
                    <td data-label="Worked" class="nowrap"><strong>@hms($row['worked'])</strong><small>Break @hms($row['break']) · Idle @hms($row['idle']) · Meeting @hms($row['meeting'])</small></td>
                    <td data-label="Day activity" class="tm-bar-col">@include('livewire.monitoring.partials.day-bar', ['timeline' => $row['timeline']])</td>
                    <td data-label="Capture"><span @class(['tm-capture', 'is-on' => $row['capture']])>{{ $row['capture'] ? 'ON' : 'OFF' }}</span></td>
                    <td data-label="Screenshots">{{ $row['screenshots'] }}</td>
                    <td><a class="btn btn-sm" href="{{ route('monitoring.team.show', ['employee' => $e->id, 'date' => $date]) }}">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8"><x-empty title="Nobody in your team matches" icon="users" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="tm-legend">
            @foreach(['working' => 'Working', 'break' => 'Break', 'idle' => 'Idle', 'meeting' => 'Meeting', 'off' => 'Off'] as $key => $label)<span><i class="tm-dot tm-seg-{{ $key }}"></i>{{ $label }}</span>@endforeach
            @if($isToday)<span class="muted">· refreshes every minute</span>@endif
        </div>
    </section>
</div>
