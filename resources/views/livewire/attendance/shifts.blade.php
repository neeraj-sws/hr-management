<div>
    <div class="page-head">
        <div><h1>Shifts &amp; Roster</h1><p>Each employee's shift sets their start time for late marking. Without a shift, the employee's own start time or the company default is used.</p></div>
        <div class="actions"><button class="btn" wire:click="editShift"><x-icon name="plus" class="icon-sm" />New shift</button><button class="btn btn-primary" wire:click="openAssign"><x-icon name="calendar" class="icon-sm" />Assign shift</button></div>
    </div>

    <section class="card">
        <div class="card-head"><div><h2>Shifts</h2><p>{{ $shiftList->count() }} defined</p></div></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Shift</th><th>Hours</th><th class="num">Grace</th><th class="num">Employees now</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($shiftList as $s)
                <tr wire:key="shift-{{ $s->id }}"><td><strong>{{ $s->name }}</strong>@if($s->crossesMidnight())<small>ends next day</small>@endif</td><td>{{ $s->hours() }}</td><td class="num">{{ $s->grace_minutes !== null ? $s->grace_minutes.' min' : 'default' }}</td><td class="num">{{ $s->assignments_count }}</td><td><x-badge :status="$s->is_active ? 'active' : 'inactive'" /></td>
                    <td><div class="row-actions"><button class="btn btn-sm" wire:click="openAssign({{ $s->id }})">Assign</button><button class="btn btn-sm" wire:click="editShift({{ $s->id }})">Edit</button></div></td></tr>
            @empty
                <tr><td colspan="6"><x-empty title="No shifts yet" icon="clock">Create shifts such as "General 09:30–18:30" or "Night 21:00–06:00".</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="card">
        <div class="card-head">
            <div><h2>Roster</h2><p>Week of {{ \Illuminate\Support\Carbon::parse($week)->format('d M Y') }} · click a day to change only that day's shift · under it: actual in–out and hours worked (<span style="color:var(--amber)">amber</span> = less than required)</p>
                <p class="roster-legend small"><span class="roster-key roster-off-holiday">Holiday</span><span class="roster-key roster-off-special">3rd Saturday / special off</span><span class="roster-key roster-off-weekly">Weekly off</span></p></div>
            <div class="toolbar">
                <select wire:model.live="department" class="input" style="width:auto" aria-label="Department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                <button class="btn btn-sm" wire:click="moveWeek(-1)" aria-label="Previous week">‹ Prev</button><button class="btn btn-sm" wire:click="$set('week','{{ today()->startOfWeek()->toDateString() }}')">This week</button><button class="btn btn-sm" wire:click="moveWeek(1)" aria-label="Next week">Next ›</button>
            </div>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Employee</th>@foreach($dates as $date)<th @class(['muted' => $date === today()->toDateString()])>{{ \Illuminate\Support\Carbon::parse($date)->format('D d') }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($employees as $e)
                <tr wire:key="roster-{{ $e->id }}"><td><strong>{{ $e->name }}</strong><small>{{ $e->department?->name ?? $e->employee_code }}</small><button type="button" class="btn btn-sm" style="margin-top:4px" wire:click="openTimeline({{ $e->id }})" title="See and change all of {{ $e->name }}'s shift dates"><x-icon name="calendar" class="icon-sm" />Edit shift dates</button></td>
                    @foreach($dates as $date)
                        @php($shift = $roster[$e->id][$date] ?? null)
                        @php($actual = $actuals[$e->id.'|'.$date] ?? null)
                        @php($off = $offDays[$e->id.'|'.$date] ?? null)
                        <td class="roster-cell {{ $off ? 'roster-off roster-off-'.$off['kind'] : '' }}" wire:click="openAssign(null, {{ $e->id }}, '{{ $date }}')" title="{{ $off ? $off['label'].' · ' : '' }}Change {{ $e->name }}'s shift on {{ \Illuminate\Support\Carbon::parse($date)->format('D d M') }}">@if($off)<span class="roster-off-tag">{{ $off['kind'] === 'holiday' ? '🎉 '.$off['label'] : ($off['kind'] === 'special' ? $off['label'] : 'Weekly off') }}</span>@endif<div class="roster-shift">@if($shift)<strong>{{ $shift->name }}</strong><small>{{ $shift->hours() }}</small>@else<small class="muted">{{ $e->work_start ? 'own '.substr($e->work_start, 0, 5) : 'default hours' }}</small>@endif</div>@if($actual)<div class="roster-actual {{ $actual['out'] && $actual['worked'] < $actual['required'] ? 'short' : '' }}" title="Actually worked">{{ $actual['in']?->format('H:i') }}–{{ $actual['out']?->format('H:i') ?? ($date === today()->toDateString() ? 'now' : 'no check-out') }}<b>@hms($actual['worked'])</b></div>@elseif($date < today()->toDateString() && ! $off)<div class="roster-actual none">no attendance</div>@endif</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="8"><x-empty title="No employees" icon="users" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $employees->links() }}
    </section>

    @if($showShiftForm)
        <x-modal :title="$editingId ? 'Edit shift' : 'New shift'">
            <form wire:submit="saveShift"><div class="modal-body"><div class="form-grid">
                <x-field label="Name" name="name" full><input wire:model="name" placeholder="General shift"></x-field>
                <x-field label="Start time" name="startTime"><input type="time" wire:model="startTime"></x-field>
                <x-field label="End time" name="endTime" hint="earlier than start = ends next day"><input type="time" wire:model="endTime"></x-field>
                <x-field label="Late grace (minutes)" name="graceMinutes" hint="blank = company default"><input type="number" min="0" max="240" wire:model="graceMinutes"></x-field>
                <label class="check"><input type="checkbox" wire:model="isActive"> Active</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save shift</button></div></form>
        </x-modal>
    @endif
    @if($showAssign)
        <x-modal title="Assign shift" size="lg">
            <form wire:submit="assign"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Shift" name="shiftId" full><select wire:model="shiftId"><option value="">Choose…</option>@foreach($shiftList->where('is_active', true) as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->hours() }})</option>@endforeach</select></x-field>
                <x-field label="From" name="startsOn"><input type="date" wire:model="startsOn"></x-field>
                <x-field label="Until" name="endsOn" hint="blank = until changed"><input type="date" wire:model="endsOn" x-bind:min="$wire.startsOn"></x-field>
                <div class="field full"><span class="label">Employees</span>
                    <div class="checks" style="max-height:240px;overflow:auto">@foreach($allEmployees as $e)<label class="check"><input type="checkbox" value="{{ $e->id }}" wire:model="employeeIds"> {{ $e->name }} <span class="muted small">{{ $e->employee_code }}</span></label>@endforeach</div>
                    @error('employeeIds')<span class="error">{{ $message }}</span>@enderror
                </div>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Assign</button></div></form>
        </x-modal>
    @endif
    @if($timelineEmployee)
        <x-modal :title="'Shift dates · '.$timelineEmployee->name" size="lg">
            <form wire:submit="saveTimeline"><div class="modal-body"><x-errors />
                <p class="small muted" style="margin-top:0">Every shift period of {{ $timelineEmployee->name }}, in date order. Change a shift or its dates, remove a row, or add one. Leave "Until" blank on the last row for "until changed". Periods may not overlap. {{ $timelineEmployee->name }} and their manager are notified.</p>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Shift</th><th>From</th><th>Until</th><th></th></tr></thead>
                    <tbody>
                    @forelse($shiftRows as $i => $row)
                        <tr wire:key="tl-{{ $i }}">
                            <td><select wire:model="shiftRows.{{ $i }}.shift_id"><option value="">Choose…</option>@foreach($shiftList as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->hours() }}){{ $s->is_active ? '' : ' · inactive' }}</option>@endforeach</select></td>
                            <td><input type="date" wire:model="shiftRows.{{ $i }}.starts_on"></td>
                            <td><input type="date" wire:model="shiftRows.{{ $i }}.ends_on" placeholder="onwards"></td>
                            <td><button type="button" class="btn btn-sm btn-danger" wire:click="removeShiftRow({{ $i }})">Remove</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted small">No shift assigned — the company working hours apply. Add a row to assign one.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                <button type="button" class="btn btn-sm mt" wire:click="addShiftRow"><x-icon name="plus" class="icon-sm" />Add period</button>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save shift dates</button></div></form>
        </x-modal>
    @endif
</div>
