<div @if($date === today()->toDateString()) wire:poll.60s @endif>
    <div class="page-head"><div><h1>Team Attendance</h1><p>{{ \Illuminate\Support\Carbon::parse($date)->format('l, d F Y') }} · {{ $total }} employees in scope</p></div></div>
    <div class="stats">
        <button type="button" class="stat" wire:click="$set('show','present')" style="cursor:pointer;text-align:left"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Present</span><span class="stat-value">{{ $counts['present'] }}</span><small>{{ $counts['working'] }} working · {{ $counts['on_break'] }} break · {{ $counts['completed'] }} done</small></div></button>
        <button type="button" class="stat" wire:click="$set('show','late')" style="cursor:pointer;text-align:left"><span class="stat-icon amber"><x-icon name="clock" /></span><div><span class="stat-label">Late</span><span class="stat-value">{{ $counts['late'] }}</span><small>{{ $counts['half_day'] }} half-day</small></div></button>
        <button type="button" class="stat" wire:click="$set('show','on_leave')" style="cursor:pointer;text-align:left"><span class="stat-icon violet"><x-icon name="calendar" /></span><div><span class="stat-label">On leave</span><span class="stat-value">{{ $counts['on_leave'] }}</span></div></button>
        <button type="button" class="stat" wire:click="$set('show','absent')" style="cursor:pointer;text-align:left"><span class="stat-icon red"><x-icon name="user-minus" /></span><div><span class="stat-label">Absent</span><span class="stat-value">{{ $counts['absent'] }}</span></div></button>
    </div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Date</span><input type="date" wire:model.live="date" max="{{ today()->toDateString() }}"></label>
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or employee code"></label>
            <label class="field"><span>Department</span><select wire:model.live="department"><option value="">All</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
            <label class="field"><span>Show</span><select wire:model.live="show"><option value="all">Everyone</option><option value="present">Present</option><option value="late">Late</option><option value="half_day">Half-day</option><option value="on_leave">On leave</option><option value="absent">Absent</option></select></label>
            <span wire:loading class="spinner" style="margin-bottom:10px"></span>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Employee</th><th>Check in</th><th>Check out</th><th class="num">Worked</th><th class="num">Idle</th><th class="num">Break</th><th>Screen time</th><th>Source</th><th>Day</th><th>Session</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr wire:key="row-{{ $row->employee->id }}">
                    <td><strong>{{ $row->employee->name }}</strong><small>{{ $row->employee->employee_code }} · {{ $row->employee->department?->name ?? '—' }}</small></td>
                    <td>{{ $row->session?->check_in?->format('h:i A') ?? '—' }}</td>
                    <td>{{ $row->session?->check_out?->format('h:i A') ?? '—' }}</td>
                    <td class="num">{{ $row->session ? gmdate('H:i', $row->worked) : '—' }}</td>
                    <td class="num">{{ $row->session ? gmdate('H:i', (int) $row->session->idle_seconds) : '—' }}</td>
                    <td class="num">{{ $row->session ? gmdate('H:i', (int) $row->session->break_seconds) : '—' }}</td>
                    <td class="nowrap">@include('livewire.attendance.partials.screen-time', ['s' => $row->session])</td>
                    <td class="small">{{ $row->session ? ($row->session->device?->computer_name ?? 'Web / mobile') : '—' }}</td>
                    <td><x-badge :status="$row->state" />@if($row->leave)<small>{{ $row->leave->leaveType?->name }}</small>@endif</td>
                    <td>@if($row->session)<x-badge :status="$row->session->status" />@if($row->session->closed_automatically)<small>auto-closed</small>@endif @if(! $row->session->check_out && auth()->user()->canAny(['attendance.correct', 'hr.manage']))<button class="btn btn-sm" wire:click="openClose({{ $row->session->id }})">Check out</button>@endif @else — @endif</td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty title="No employees match these filters" icon="users" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    @if($closingId)
        <x-modal title="Check out on behalf of the employee">
            <form wire:submit="closeSession"><div class="modal-body"><div class="form-grid">
                <x-field label="Check-out time" name="closeTime"><input type="time" wire:model="closeTime"></x-field>
                <x-field label="Reason" name="closeNote" full><input wire:model="closeNote" placeholder="e.g. Left at 6 PM, forgot to check out"></x-field>
            </div><p class="small muted">Recorded in the audit log and the employee is notified.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Check out</button></div></form>
        </x-modal>
    @endif
</div>
