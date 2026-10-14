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
            <div><h2>Roster</h2><p>Week of {{ \Illuminate\Support\Carbon::parse($week)->format('d M Y') }}</p></div>
            <div class="toolbar">
                <select wire:model.live="department" class="input" style="width:auto" aria-label="Department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                <button class="btn btn-sm" wire:click="moveWeek(-1)" aria-label="Previous week">‹ Prev</button><button class="btn btn-sm" wire:click="$set('week','{{ today()->startOfWeek()->toDateString() }}')">This week</button><button class="btn btn-sm" wire:click="moveWeek(1)" aria-label="Next week">Next ›</button>
            </div>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Employee</th>@foreach($dates as $date)<th @class(['muted' => $date === today()->toDateString()])>{{ \Illuminate\Support\Carbon::parse($date)->format('D d') }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($employees as $e)
                <tr wire:key="roster-{{ $e->id }}"><td><strong>{{ $e->name }}</strong><small>{{ $e->department?->name ?? $e->employee_code }}</small></td>
                    @foreach($dates as $date)
                        @php($shift = $roster[$e->id][$date] ?? null)
                        <td style="cursor:pointer" wire:click="openAssign(null, {{ $e->id }})" title="Change shift">@if($shift)<span class="pill">{{ $shift->name }}<br><small>{{ $shift->hours() }}</small></span>@else<span class="muted small">{{ $e->work_start ? 'own '.substr($e->work_start, 0, 5) : 'default' }}</span>@endif</td>
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
            <form wire:submit="assign"><div class="modal-body"><div class="form-grid">
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
</div>
