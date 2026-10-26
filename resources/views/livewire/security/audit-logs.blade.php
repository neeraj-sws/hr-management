<div>
    <div class="page-head"><div><h1>Audit Logs</h1><p>Append-only record of who did what, when and from where — grouped by area.</p></div>
        <a class="btn" href="{{ route('hr.reports.export', ['type' => 'audit', 'from' => $from ?: now()->startOfMonth()->toDateString(), 'to' => $to ?: today()->toDateString(), 'search' => $search ?: null]) }}"><x-icon name="download" class="icon-sm" />Export CSV</a></div>

    @if($employee)
        <div class="alert alert-info">Showing every change recorded for <strong>{{ $employeeName ?? 'employee #'.$employee }}</strong> (profile, photo, monitoring settings, shift, login). <a href="{{ route('people.employees.show', $employee) }}">Back to profile</a> · <button type="button" class="nf-link" wire:click="$set('employee', null)">Show everyone</button></div>
    @endif
    <div class="tabs">
        <button class="tab {{ $category === '' ? 'active' : '' }}" wire:click="setCategory('')">All <span class="nav-count" style="margin-left:6px">{{ $total }}</span></button>
        @foreach($categories as $key => [$label])
            <button class="tab {{ $category === $key ? 'active' : '' }}" wire:click="setCategory('{{ $key }}')">{{ $label }} <span class="nav-count" style="margin-left:6px">{{ $counts[$key] }}</span></button>
        @endforeach
    </div>

    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Action, person, record, IP or details"></label>
            <label class="field"><span>Actor</span><select wire:model.live="actor"><option value="">Anyone</option>@foreach($actors as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
            <label class="field"><span>Record</span><select wire:model.live="record"><option value="">Any</option>@foreach($records as $r)<option value="{{ $r }}">{{ str($r)->headline() }}</option>@endforeach</select></label>
            <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>To</span><input type="date" wire:model.live="to" x-bind:min="$wire.from"></label>
            <label class="field"><span>Sort</span><select wire:model.live="sort">@foreach(\App\Livewire\Security\AuditLogs::SORTS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label class="field"><span>Per page</span><select wire:model.live="perPage"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <button type="button" class="btn" wire:click="resetFilters">Reset</button>
            <span wire:loading class="spinner" style="margin-bottom:10px"></span>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>When</th><th>Category</th><th>Actor</th><th>Action</th><th>Record</th><th>IP</th><th></th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr wire:key="log-{{ $log->id }}"><td class="nowrap">{{ $log->created_at?->format('d M Y h:i:s A') }}</td><td><x-badge :tone="['attendance' => 'green', 'payroll' => 'violet', 'employee' => 'blue', 'tasks' => 'amber', 'leave' => 'green', 'assets' => 'gray', 'expenses' => 'amber', 'system' => 'gray'][$log->category()] ?? 'gray'">{{ $log->categoryLabel() }}</x-badge></td><td>{{ $log->actor?->name ?? 'System' }}</td><td><code>{{ $log->action }}</code><small>{{ str($log->action)->after('.')->replace('_', ' ')->ucfirst() }}</small></td><td>{{ $log->subject_type ? str(class_basename($log->subject_type))->headline().' #'.$log->subject_id : '—' }}@if($summary = $log->summary())<small>{{ $summary }}</small>@endif</td><td class="small">{{ $log->ip_address ?? '—' }}</td>
                    <td>@if($log->metadata)<button class="btn btn-sm" wire:click="$set('viewingId', {{ $log->id }})">Details</button>@endif</td></tr>
            @empty
                <tr><td colspan="7"><x-empty title="No audit events match" icon="eye" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $logs->links() }}
    </section>
    @if($viewing)
        <x-modal title="Audit event #{{ $viewing->id }}">
            <div class="modal-body"><dl class="kv"><dt>Category</dt><dd>{{ $viewing->categoryLabel() }}</dd><dt>Action</dt><dd>{{ $viewing->action }}</dd><dt>Actor</dt><dd>{{ $viewing->actor?->name ? $viewing->actor->name.' ('.$viewing->actor->email.')' : 'System' }}</dd><dt>When</dt><dd>{{ $viewing->created_at?->format('d M Y h:i:s A') }}</dd><dt>Record</dt><dd>{{ $viewing->subject_type ? class_basename($viewing->subject_type).' #'.$viewing->subject_id : '—' }}</dd><dt>User agent</dt><dd class="small">{{ $viewing->user_agent ?? '—' }}</dd></dl>
                <pre style="background:#f6f8fb;padding:12px;border-radius:8px;overflow:auto;max-height:320px;text-align:left">{{ json_encode($viewing->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></div>
        </x-modal>
    @endif
</div>
