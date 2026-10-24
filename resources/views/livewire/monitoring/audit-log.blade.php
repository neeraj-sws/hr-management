<div class="tm-page">
    <div class="page-head"><div><h1>Monitoring audit log</h1><p>Who looked at whose monitoring data (screenshots and timelines), view or download, and every screen capture change with its reason.</p></div>
        <div class="actions"><a class="btn" href="{{ route('monitoring.team') }}"><x-icon name="users" class="icon-sm" />Team monitoring</a></div></div>
    <div class="tm-filters">
        <label class="field"><span>Action</span><select wire:model.live="action"><option value="">All actions</option>@foreach(\App\Livewire\Monitoring\AuditLog::ACTIONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        <label class="field"><span>Employee (whose data)</span><select wire:model.live="employee"><option value="">Everyone</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->employee_code }} · {{ $e->name }}</option>@endforeach</select></label>
        <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
        <label class="field"><span>To</span><input type="date" wire:model.live="to" x-bind:min="$wire.from"></label>
    </div>
    <section class="tm-table-card">
        <div class="table-wrap"><table class="table tm-table" data-no-sort>
            <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Whose data</th><th>Screenshot</th><th>Details</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                @php($shot = $log->subject_type === \App\Models\Screenshot::class ? $shots->get($log->subject_id) : null)
                @php($person = $shot?->employee ?? ($log->subject_type === \App\Models\AttendanceEmployee::class ? $people->get($log->subject_id) : null))
                <tr wire:key="ma-{{ $log->id }}">
                    <td data-label="When" class="nowrap">{{ $log->created_at->format('d M Y, h:i:s A') }}</td>
                    <td data-label="Who">{{ $log->actor?->name ?? 'System' }}</td>
                    <td data-label="Action"><span @class(['tm-capture', 'is-on' => str_contains($log->action, 'enabled'), 'is-dl' => str_contains($log->action, 'downloaded')])>{{ \App\Livewire\Monitoring\AuditLog::ACTIONS[$log->action] ?? $log->action }}</span></td>
                    <td data-label="Whose data">{{ $person ? $person->name.' ('.$person->employee_code.')' : '—' }}</td>
                    <td data-label="Screenshot">{{ $shot ? '#'.$shot->id.' · '.$shot->captured_at->format('d M h:i A') : ($log->metadata['screenshot_id'] ?? null ? '#'.$log->metadata['screenshot_id'] : '—') }}</td>
                    <td data-label="Details" class="small">@if(! empty($log->metadata['reason']))Reason: {{ $log->metadata['reason'] }}@endif @if(! empty($log->metadata['until'])) · until {{ $log->metadata['until'] }}@endif @if(! empty($log->metadata['date'])) Day {{ $log->metadata['date'] }}@endif @if(! empty($log->metadata['deleted'])) {{ $log->metadata['deleted'] }} deleted @endif</td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty title="Nothing logged for these filters" icon="history" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="emp-foot"><span class="muted small">{{ $logs->total() }} entries</span>{{ $logs->onEachSide(1)->links('components.pager') }}</div>
    </section>
</div>
