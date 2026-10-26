<div>
    <div class="page-head"><div><h1>Attendance records</h1><p>All attendance sessions captured from web, mobile and desktop devices.</p></div>
        <a class="btn" href="{{ route('hr.reports.export', ['type' => 'attendance', 'from' => $from, 'to' => $to, 'employee_id' => $employee, 'department_id' => $department]) }}"><x-icon name="download" class="icon-sm" />Export CSV</a></div>

    <section class="card mb">
        <div class="card-head"><div><h2>Import attendance</h2><p>Add past attendance from Excel (.xlsx) or CSV: one row per employee per day.</p></div></div>
        @include('partials.import-form', ['type' => 'attendance', 'kind' => 'Attendance', 'action' => route('attendance.records.import')])
    </section>

    <section class="card">
        <div class="toolbar">
            <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>To</span><input type="date" wire:model.live="to" x-bind:min="$wire.from"></label>
            <label class="field grow"><span>Employee</span><select wire:model.live="employee"><option value="">All employees</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach</select></label>
            <label class="field"><span>Department</span><select wire:model.live="department"><option value="">All</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
            <label class="field"><span>Session</span><select wire:model.live="status"><option value="">Any</option><option value="working">Working</option><option value="on_break">On break</option><option value="completed">Completed</option></select></label>
        </div>
        <x-errors />
        @if($dayEmployee)
            <div class="toolbar" style="padding-top:0">
                <div class="seg" role="group" aria-label="View"><button type="button" @class(['btn btn-sm', 'btn-primary' => $view !== 'calendar']) wire:click="$set('view', 'list')">List</button><button type="button" @class(['btn btn-sm', 'btn-primary' => $view === 'calendar']) wire:click="$set('view', 'calendar')">Calendar</button></div>
                @if($view === 'calendar')<span class="small muted">{{ $dayEmployee->name }} · {{ $calendarMonth->format('F Y') }} (the month of “From”)</span>@endif
            </div>
        @endif
        @if($dayEmployee && $view === 'calendar')
            <x-attendance-calendar :days="$calendarDays" :month="$calendarMonth" />
        @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Employee</th><th>In</th><th>Out</th><th class="num" title="Worked: in–out time less break and idle · at work: in–out less break only (decides a half day)">Worked</th><th class="num">Idle</th><th class="num">Break</th><th>Screen time</th><th class="num">Late by</th><th>Source</th><th>Status</th></tr></thead>
            <tbody>
            @if($dayRows)
                {{-- One employee: every day of the range, incl. leave, weekly off / 3rd Saturday and absent days. --}}
                @forelse($dayRows as $day)
                    @if($day['session'])
                        @include('livewire.attendance.partials.record-row', ['s' => $day['session']])
                    @else
                        <tr class="day-row day-{{ $day['status'] }}"><td class="nowrap">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D d M Y') }}</td><td><strong>{{ $dayEmployee?->name }}</strong><small>{{ $dayEmployee?->department?->name }}</small></td>
                            <td colspan="8" class="small muted" style="text-align:left">{{ ['on_leave' => 'On leave', 'weekend' => 'Weekly off', 'holiday' => 'Holiday', 'absent' => 'No attendance', 'upcoming' => 'Upcoming', 'not_joined' => 'Before joining'][$day['status']] ?? '' }}@if($day['label']) · <strong>{{ $day['label'] }}</strong>@endif</td>
                            <td>@if($day['status'] === 'weekend')<x-badge tone="violet">{{ $day['label'] ?: 'Weekly off' }}</x-badge>@elseif($day['status'] === 'on_leave')<x-badge status="on_leave">{{ $day['label'] ?: 'On leave' }}</x-badge>@else<x-badge :status="$day['status']" />@endif</td></tr>
                    @endif
                @empty
                    <tr><td colspan="11"><x-empty title="No days in this range" icon="calendar" /></td></tr>
                @endforelse
            @else
            @forelse($sessions as $s)
                @include('livewire.attendance.partials.record-row', ['s' => $s])
            @empty
                <tr><td colspan="10"><x-empty title="No attendance in this range" icon="calendar" /></td></tr>
            @endforelse
            @endif
            </tbody>
        </table></div>
        @unless($dayRows){{ $sessions->links() }}@endunless
        @endif
        @if($dayRows)<div class="card-foot small muted">{{ collect($dayRows)->where('session', '!=', null)->count() }} present · {{ collect($dayRows)->where('status', 'on_leave')->count() }} on leave · {{ collect($dayRows)->where('status', 'weekend')->count() + collect($dayRows)->where('status', 'holiday')->count() }} off / holiday · {{ collect($dayRows)->where('status', 'absent')->count() }} absent</div>@endif
    </section>
</div>
