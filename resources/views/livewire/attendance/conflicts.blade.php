<div>
    <div class="page-head">
        <div><h1>Leave &amp; Attendance Conflicts</h1><p>The employee had approved leave and also checked in. Payroll never pays these days twice; until you decide, the day is paid as worked.</p></div>
        <div class="toolbar"><input type="date" class="input" wire:model.live="from" aria-label="From"><input type="date" class="input" wire:model.live="to" x-bind:min="$wire.from" aria-label="To"></div>
    </div>
    <section class="card">
        <div class="card-head"><div><h2>Needs a decision</h2><p>{{ $open->total() }} day(s)</p></div></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Employee</th><th>Day</th><th>Leave</th><th>Attendance</th><th></th></tr></thead>
            <tbody>
            @forelse($open as $c)
                <tr wire:key="conf-{{ $c['employee_id'] }}-{{ $c['date'] }}">
                    <td><strong>{{ $c['leave']->employee?->name }}</strong><small>{{ $c['leave']->employee?->employee_code }}</small></td>
                    <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($c['date'])->format('D d M Y') }}</td>
                    <td>{{ $c['leave']->typeName() }}<small>{{ $c['leave']->leaveType?->paid ? 'paid' : 'unpaid' }} · {{ $c['leave']->starts_on->format('d M') }}–{{ $c['leave']->ends_on->format('d M') }}</small></td>
                    <td class="nowrap">{{ $c['session']->check_in?->format('h:i A') }} – {{ $c['session']->check_out?->format('h:i A') ?? 'open' }}</td>
                    <td><div class="row-actions">
                        <button class="btn btn-sm btn-primary" wire:click="resolve({{ $c['leave']->id }}, '{{ $c['date'] }}', 'worked')" wire:confirm="Count as a working day and give the leave day back?">Worked</button>
                        <button class="btn btn-sm" wire:click="resolve({{ $c['leave']->id }}, '{{ $c['date'] }}', 'leave')" wire:confirm="Count this day as leave (attendance is kept only for the record)?">Leave</button>
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty title="No conflicts in this period" icon="check-circle" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $open->links() }}
    </section>
    @if($resolved->isNotEmpty())
        <section class="card">
            <div class="card-head"><div><h2>Recently resolved</h2></div></div>
            <div class="list">
                @foreach($resolved as $r)
                    <div class="list-row"><div><strong>{{ $r->employee?->name }} · {{ $r->work_date->format('d M Y') }}</strong><small>{{ $r->leaveRequest?->typeName() }} · by {{ $r->resolver?->name ?? 'system' }} · {{ $r->created_at->diffForHumans() }}</small></div><x-badge :tone="$r->resolution === 'worked' ? 'green' : 'violet'">{{ $r->resolution }}</x-badge></div>
                @endforeach
                {{ $resolved->links() }}
            </div>
        </section>
    @endif
</div>
