<div class="ta-page" @if($date === today()->toDateString()) wire:poll.30s @endif>
    @php($day = \Illuminate\Support\Carbon::parse($date))
    <div class="page-head">
        <div><h1>Team Attendance</h1><p>{{ $day->format('l, d F Y') }} · {{ $total }} {{ str('employee')->plural($total) }} in scope @if($date === today()->toDateString())<span class="ta-live"><i></i>live</span>@endif</p></div>
        <div class="actions ta-daynav">
            <button type="button" class="btn btn-icon" wire:click="$set('date', '{{ $day->copy()->subDay()->toDateString() }}')" aria-label="Previous day"><x-icon name="chevron-left" class="icon-sm" /></button>
            <input type="date" wire:model.live="date" max="{{ today()->toDateString() }}" aria-label="Date">
            <button type="button" class="btn btn-icon" wire:click="$set('date', '{{ $day->copy()->addDay()->toDateString() }}')" @disabled($day->isToday()) aria-label="Next day"><x-icon name="chevron-right" class="icon-sm" /></button>
            @unless($day->isToday())<button type="button" class="btn" wire:click="$set('date', '{{ today()->toDateString() }}')">Today</button>@endunless
            @if(\App\Support\Navigation::canAccess(auth()->user(), 'reports.show', ['type' => 'attendance']))<a class="btn" href="{{ route('reports.show', ['type' => 'attendance', 'from' => $date, 'to' => $date]) }}"><x-icon name="download" class="icon-sm" />Export</a>@endif
        </div>
    </div>

    <div class="ta-cards" role="group" aria-label="Filter by attendance">
        @foreach([['present', 'Present', 'check-circle', 'ta-green', $counts['present'], $counts['completed'].' done for the day'], ['working', 'Working now', 'activity', 'ta-blue', $counts['working'], 'checked in, working'], ['on_break', 'On break', 'coffee', 'ta-yellow', $counts['on_break'], 'right now'], ['late', 'Late', 'clock', 'ta-amber', $counts['late'], $counts['half_day'].' half-day'], ['on_leave', 'On leave', 'calendar', 'ta-violet', $counts['on_leave'], 'approved leave'], ['absent', 'Absent', 'user-minus', 'ta-red', $counts['absent'], 'no check-in']] as [$key, $label, $icon, $tone, $count, $sub])
            <button type="button" @class(['ta-card', $tone, 'is-active' => $show === $key]) wire:click="$set('show', '{{ $show === $key ? 'all' : $key }}')" aria-pressed="{{ $show === $key ? 'true' : 'false' }}">
                <span class="ta-card-icon"><x-icon :name="$icon" class="icon-sm" /></span>
                <span class="ta-card-text"><span>{{ $label }}</span><strong>{{ $count }}</strong><small>{{ $sub }}</small></span>
            </button>
        @endforeach
    </div>

    <section class="ta-card-box">
        <div class="ta-filters">
            <label class="emp-input-icon ta-search"><x-icon name="search" class="icon-sm" /><input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name or employee code" aria-label="Search"></label>
            <select wire:model.live="department" aria-label="Department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
            <div class="docs-chips ta-chips" role="group" aria-label="Show">
                @foreach(['all' => 'Everyone', 'present' => 'Present', 'late' => 'Late', 'half_day' => 'Half-day', 'on_leave' => 'On leave', 'absent' => 'Absent'] as $key => $label)
                    <button type="button" @class(['docs-chip', 'is-active' => $show === $key]) wire:click="$set('show', '{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
            <span wire:loading class="spinner"></span>
        </div>
        <div class="table-wrap"><table class="table ta-table" data-no-sort>
            <thead><tr><th>Employee</th><th>Status</th><th>Check in</th><th>Check out</th><th>Worked</th><th>Screen time</th><th>Day</th><th class="ta-act"><span class="sr-only">Actions</span></th></tr></thead>
            @forelse($rows as $row)
                @php($s = $row->session)
                @php($t = $row->live['screen'] ?? null)
                @php($tracked = $t ? $t['active'] + $t['idle'] + $t['meeting'] + $t['corrected'] : 0)
                <tbody x-data="{ open: false }" wire:key="row-{{ $row->employee->id }}-{{ now()->timestamp }}">
                <tr @if($row->live) x-data="swsLive(@js($row->live))" @endif>
                    <td>
                        <div class="emp-person"><x-avatar :employee="$row->employee" />
                            <div class="emp-person-text"><strong>{{ $row->employee->name }}</strong><small>{{ $row->employee->employee_code }} · {{ $row->employee->department?->name ?? '—' }}</small><x-person-badges :employee="$row->employee" /></div></div>
                    </td>
                    <td>
                        @if($s && ! $s->check_out)
                            <span class="ta-pill {{ $s->status === 'on_break' ? 'ta-pill-break' : 'ta-pill-work' }}"><i></i>{{ $s->status === 'on_break' ? 'On break' : 'Working' }}</span>
                        @elseif($s)
                            <span class="ta-pill ta-pill-done"><i></i>Done</span>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td class="nowrap">
                        @if($s?->check_in)
                            <strong class="ta-time">{{ $s->check_in->format('h:i A') }}</strong>
                            @if($row->lateMinutes)<small class="ta-late">+{{ \App\Support\Duration::words($row->lateMinutes) }} late</small>@endif
                            <span class="ta-icons">@if($s->check_in_photo_path)<a href="{{ route('attendance.photo', [$s, 'in']) }}" target="_blank" title="Check-in selfie">📸</a>@endif @if($s->check_in_latitude)<span title="{{ $s->check_in_latitude }}, {{ $s->check_in_longitude }}">📍</span>@endif</span>
                        @else — @endif
                    </td>
                    <td class="nowrap">
                        @if($s?->check_out)<strong class="ta-time">{{ $s->check_out->format('h:i A') }}</strong>@if($s->check_out_photo_path) <a href="{{ route('attendance.photo', [$s, 'out']) }}" target="_blank" title="Check-out selfie">📸</a>@endif
                            @if($s->autoCheckedOut())<small>auto check-out (left the computer)</small>@endif
                            @if($s->wasForcedOut())<small title="{{ $s->forcedReasonLabel() }}">checked out by {{ $s->forcedBy?->name ?? 'admin' }}</small>@endif
                            @if($s->closed_automatically)<small>auto-closed</small>@endif
                        @else — @endif
                    </td>
                    <td class="nowrap">
                        @if($row->live)<strong class="ta-worked" x-text="hms(v.worked)">@hms($row->live['worked'])</strong>
                            <small>Break <span x-text="hms(v.brk)">@hms($row->live['break'])</span> · Idle <span x-text="hms(v.idle)">@hms($row->live['idle'])</span></small>
                        @else — @endif
                    </td>
                    <td class="ta-screen">
                        @if($t && ($t['active'] + $t['idle'] + $t['meeting']) > 0)
                            <div class="ta-bar" title="In use {{ \App\Support\Duration::hms($t['active']) }} · meetings {{ \App\Support\Duration::hms($t['meeting']) }} · idle {{ \App\Support\Duration::hms($t['idle']) }}">
                                <span class="ta-bar-use" style="width:{{ round(($t['active'] + $t['corrected']) * 100 / max(1, $tracked), 1) }}%"></span><span class="ta-bar-meet" style="width:{{ round($t['meeting'] * 100 / max(1, $tracked), 1) }}%"></span><span class="ta-bar-idle" style="width:{{ round($t['idle'] * 100 / max(1, $tracked), 1) }}%"></span>
                            </div>
                            <small><span x-text="hms(v.active)">@hms($t['active'])</span> in use · <span x-text="pct">{{ (int) round(($t['active'] + $t['meeting'] + $t['corrected']) * 100 / max(1, $tracked)) }}</span>% working</small>
                        @else
                            <span class="muted small" title="Idle and screen time come from the SWS HR desktop app; it has not reported for this day">no desktop app</span>
                        @endif
                    </td>
                    <td><x-badge :status="$row->state" />@if($row->leave)<small>{{ $row->leave->leaveType?->name }}</small>@endif</td>
                    <td class="ta-act">
                        <div class="ta-actions">
                            @if($s && ! $s->check_out && $canClose)<button type="button" class="btn btn-sm" wire:click="openClose({{ $s->id }})">Check out</button>@endif
                            <details class="menu emp-menu">
                                <summary class="btn btn-sm btn-icon" aria-label="More for {{ $row->employee->name }}"><x-icon name="more" class="icon-sm" /></summary>
                                <div class="menu-panel">
                                    @if($s)<button type="button" class="menu-item" x-on:click="open = ! open; $el.closest('details').removeAttribute('open')"><x-icon name="eye" class="icon-sm" /><span x-text="open ? 'Hide details' : 'Show details'">Show details</span></button>@endif
                                    @if(\App\Support\Navigation::canAccess(auth()->user(), 'attendance.records'))<a class="menu-item" href="{{ route('attendance.records', ['employee' => $row->employee->id]) }}"><x-icon name="calendar" class="icon-sm" />Attendance records</a>@endif
                                    @if($canMonitor)<a class="menu-item" href="{{ route('monitoring.team.show', ['employee' => $row->employee->id, 'date' => $date]) }}"><x-icon name="monitor" class="icon-sm" />Team monitoring</a>@endif
                                    @if(\App\Support\Navigation::canAccess(auth()->user(), 'people.employees.show', ['employee' => $row->employee->id]))<a class="menu-item" href="{{ route('people.employees.show', $row->employee) }}"><x-icon name="user" class="icon-sm" />Profile</a>@endif
                                </div>
                            </details>
                        </div>
                    </td>
                </tr>
                @if($s)
                    <tr class="ta-details" x-show="open" x-cloak @if($row->live) x-data="swsLive(@js($row->live))" @endif>
                        <td colspan="8">
                            <div class="ta-details-grid">
                                <div><span class="ta-label">Screen time</span>@include('livewire.attendance.partials.screen-time', ['s' => $s, 'f' => $row->live])</div>
                                <div><span class="ta-label">Source</span>{{ $s->device?->computer_name ?? 'Web / mobile' }}@if($s->check_in_ip)<small>IP in {{ $s->check_in_ip }}@if($s->check_out_ip) · out {{ $s->check_out_ip }}@endif</small>@endif</div>
                                <div><span class="ta-label">Session</span><x-badge :status="$s->status" />@if($s->wasForcedOut())<small>{{ $s->forcedReasonLabel() }}</small>@endif</div>
                            </div>
                        </td>
                    </tr>
                @endif
                </tbody>
            @empty
                <tbody><tr><td colspan="8"><x-empty title="No employees match these filters" icon="users" /></td></tr></tbody>
            @endforelse
        </table></div>
        <div class="emp-foot"><span class="muted small">@if($rows->total())Showing {{ $rows->firstItem() }} to {{ $rows->lastItem() }} of {{ $rows->total() }}@endif</span>{{ $rows->onEachSide(1)->links('components.pager') }}</div>
    </section>
    @if($closingId)
        <x-modal title="Check out on behalf of the employee">
            <form wire:submit="closeSession"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Check-out time" name="closeTime"><input type="time" wire:model="closeTime"></x-field>
                <x-field label="Reason" name="closeReason"><select wire:model.live="closeReason"><option value="">Select a reason</option>@foreach(\App\Models\AttendanceSession::FORCED_CHECKOUT_REASONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                <x-field :label="$closeReason === 'other' ? 'Explanation (required)' : 'Explanation (optional)'" name="closeNote" full><textarea wire:model="closeNote" rows="2" placeholder="e.g. Left at 6 PM, confirmed by the team lead"></textarea></x-field>
            </div><p class="small muted">The audit log records who checked the employee out, whose attendance it was, the date/time and the reason. The employee and their manager are notified, and the employee cannot check out again for this day.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Check out</button></div></form>
        </x-modal>
    @endif
</div>
