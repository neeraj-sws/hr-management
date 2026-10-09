<div>
    <div class="page-head"><div><h1>Audit Logs</h1><p>Append-only record of who did what, when and from where.</p></div>
        <a class="btn" href="{{ route('hr.reports.export', ['type' => 'audit', 'from' => $from ?: now()->startOfMonth()->toDateString(), 'to' => $to ?: today()->toDateString(), 'search' => $search ?: null]) }}"><x-icon name="download" class="icon-sm" />Export CSV</a></div>
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Action (e.g. payroll., credential.), record type or IP"></label>
            <label class="field"><span>Actor</span><select wire:model.live="actor"><option value="">Anyone</option>@foreach($actors as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
            <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>To</span><input type="date" wire:model.live="to" x-bind:min="$wire.from"></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Record</th><th>IP</th><th></th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr><td class="nowrap">{{ $log->created_at?->format('d M Y H:i:s') }}</td><td>{{ $log->actor?->name ?? 'System' }}</td><td><code>{{ $log->action }}</code></td><td>{{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '—' }}</td><td class="small">{{ $log->ip_address ?? '—' }}</td>
                    <td>@if($log->metadata)<button class="btn btn-sm" wire:click="$set('viewingId', {{ $log->id }})">Details</button>@endif</td></tr>
            @empty
                <tr><td colspan="6"><x-empty title="No audit events match" icon="eye" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $logs->links() }}
    </section>
    @if($viewing)
        <x-modal title="Audit event #{{ $viewing->id }}">
            <div class="modal-body"><dl class="kv"><dt>Action</dt><dd>{{ $viewing->action }}</dd><dt>Actor</dt><dd>{{ $viewing->actor?->email ?? 'System' }}</dd><dt>When</dt><dd>{{ $viewing->created_at }}</dd><dt>User agent</dt><dd class="small">{{ $viewing->user_agent ?? '—' }}</dd></dl>
                <pre style="background:#f6f8fb;padding:12px;border-radius:8px;overflow:auto;max-height:320px">{{ json_encode($viewing->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
        </x-modal>
    @endif
</div>
