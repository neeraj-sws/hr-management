<div>
    <div class="page-head"><div><h1>Attendance records</h1><p>All attendance sessions captured from web, mobile and desktop devices.</p></div>
        <a class="btn" href="{{ route('hr.reports.export', ['type' => 'attendance', 'from' => $from, 'to' => $to, 'employee_id' => $employee, 'department_id' => $department]) }}"><x-icon name="download" class="icon-sm" />Export CSV</a></div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>To</span><input type="date" wire:model.live="to" x-bind:min="$wire.from"></label>
            <label class="field grow"><span>Employee</span><select wire:model.live="employee"><option value="">All employees</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach</select></label>
            <label class="field"><span>Department</span><select wire:model.live="department"><option value="">All</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
            <label class="field"><span>Session</span><select wire:model.live="status"><option value="">Any</option><option value="working">Working</option><option value="on_break">On break</option><option value="completed">Completed</option></select></label>
        </div>
        <x-errors />
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Employee</th><th>In</th><th>Out</th><th class="num">Active</th><th class="num">Idle</th><th class="num">Break</th><th>Screen time</th><th class="num">Late (min)</th><th>Source</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($sessions as $s)
                <tr><td class="nowrap">{{ $s->work_date->format('D d M Y') }}</td><td><strong>{{ $s->employee?->name }}</strong><small>{{ $s->employee?->department?->name }}</small></td>
                    <td>{{ $s->check_in?->format('h:i A') }}@if($s->check_in_latitude !== null)<small title="{{ $s->check_in_latitude }}, {{ $s->check_in_longitude }}">{{ $s->checkInOffice ? 'at '.$s->checkInOffice->name : ($s->check_in_distance_meters !== null ? number_format($s->check_in_distance_meters).' m from office' : 'location shared') }}</small>@endif</td><td>{{ $s->check_out?->format('h:i A') ?? '—' }}@if($s->check_out_latitude !== null)<small title="{{ $s->check_out_latitude }}, {{ $s->check_out_longitude }}">{{ $s->checkOutOffice ? 'at '.$s->checkOutOffice->name : ($s->check_out_distance_meters !== null ? number_format($s->check_out_distance_meters).' m from office' : 'location shared') }}</small>@endif</td>
                    <td class="num">{{ gmdate('H:i', $attendance->workedSeconds($s)) }}</td><td class="num">{{ gmdate('H:i', (int) $s->idle_seconds) }}</td><td class="num">{{ gmdate('H:i', (int) $s->break_seconds) }}</td><td class="nowrap">@include('livewire.attendance.partials.screen-time', ['s' => $s])</td>
                    <td class="num">{{ $attendance->lateMinutes($s) ?: '—' }}</td><td class="small">{{ $s->device?->computer_name ?? 'Web / mobile' }}</td>
                    <td><x-badge :status="$attendance->isHalfDay($s) ? 'half_day' : ($attendance->isLate($s) ? 'late' : $s->status)" /></td></tr>
            @empty
                <tr><td colspan="10"><x-empty title="No attendance in this range" icon="calendar" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $sessions->links() }}
    </section>
</div>
