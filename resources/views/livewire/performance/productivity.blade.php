<div>
    <div class="page-head"><div><h1>Productivity</h1><p>Derived from task completion, due dates, timer logs and attendance — no screen, keyboard or camera monitoring.</p></div>
        <a class="btn" href="{{ route('hr.reports.export', ['type' => 'productivity', 'from' => $from, 'to' => $to, 'department_id' => $department]) }}"><x-icon name="download" class="icon-sm" />Export CSV</a></div>
    <div class="stats">
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Tasks completed</span><span class="stat-value">{{ $totals['completed'] }}</span><small>{{ $totals['late'] }} completed late</small></div></div>
        <div class="stat"><span class="stat-icon red"><x-icon name="alert" /></span><div><span class="stat-label">Overdue now</span><span class="stat-value">{{ $totals['overdue'] }}</span></div></div>
        <div class="stat"><span class="stat-icon"><x-icon name="timer" /></span><div><span class="stat-label">Logged task time</span><span class="stat-value">@hms($totals['logged'])</span><small>@hms($totals['active']) active attendance</small></div></div>
    </div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>To</span><input type="date" wire:model.live="to" x-bind:min="$wire.from"></label>
            <label class="field"><span>Department</span><select wire:model.live="department"><option value="">All</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Employee</th><th class="num">Done</th><th class="num">Late</th><th>On-time rate</th><th class="num">Open</th><th class="num">Overdue</th><th class="num">Est. h</th><th class="num">Logged h</th><th class="num">Attendance</th><th>Utilisation</th></tr></thead>
            <tbody>
            @forelse($rows as $r)
                <tr><td><strong>{{ $r->employee->name }}</strong><small>{{ $r->employee->employee_code }}</small></td>
                    <td class="num">{{ $r->completed_tasks }}</td><td class="num">{{ $r->completed_late }}</td>
                    <td style="min-width:120px">@if($r->on_time_rate !== null)<div class="progress"><span style="width: {{ $r->on_time_rate }}%;background:{{ $r->on_time_rate >= 80 ? 'var(--green)' : ($r->on_time_rate >= 50 ? 'var(--amber)' : 'var(--red)') }}"></span></div><small>{{ $r->on_time_rate }}%</small>@else<span class="muted">—</span>@endif</td>
                    <td class="num">{{ $r->open_tasks }}</td><td class="num">{{ $r->overdue_tasks }}</td><td class="num">{{ \App\Support\Duration::fromHours($r->estimated_hours) }}</td><td class="num">@hms($r->logged_seconds)</td>
                    <td class="num">{{ $r->attendance_days }} d<small>@hms($r->active_seconds)</small></td>
                    <td>{{ $r->utilization !== null ? $r->utilization.'%' : '—' }}<small>logged ÷ active</small></td></tr>
            @empty
                <tr><td colspan="10"><x-empty title="No employees in scope" icon="activity" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $rows->links() }}
    </section>
</div>
