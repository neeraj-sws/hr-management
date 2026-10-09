<div>
    <div class="page-head"><div><h1>Export Center</h1><p>UTF-8 CSV files (Excel compatible) for every report you can access. Exports are permission-checked, limited to one year per file and audited.</p></div></div>
    <section class="card mb"><div class="toolbar" style="border:0">
        <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
        <label class="field"><span>To</span><input type="date" wire:model.live="to" x-bind:min="$wire.from"></label>
    </div></section>
    <div class="grid grid-3 mb">
        @forelse($types as $type => $info)
            <section class="card">
                <div class="card-head"><div><h2>{{ $info['label'] }}</h2><p>{{ count($info['columns']) }} columns</p></div></div>
                <div class="card-body small muted">{{ collect($info['columns'])->map(fn ($c) => str($c)->replace('_', ' '))->join(', ') }}</div>
                <div class="card-foot actions"><a class="btn btn-sm btn-primary" href="{{ route('hr.reports.export', ['type' => $type, 'from' => $from, 'to' => $to]) }}"><x-icon name="download" class="icon-sm" />Download CSV</a><a class="btn btn-sm" href="{{ route('reports.show', ['type' => $type, 'from' => $from, 'to' => $to]) }}">Preview & filter</a></div>
            </section>
        @empty
            <div class="card" style="grid-column:1/-1"><x-empty title="No reports available for your role" icon="chart" /></div>
        @endforelse
    </div>
    <section class="card">
        <div class="card-head"><h2>Your recent exports</h2></div>
        <div class="list">
            @forelse($recent as $log)<div class="list-row"><div><strong>{{ ucfirst($log->metadata['type'] ?? 'report') }}</strong><small>{{ $log->metadata['filters']['from'] ?? '' }} → {{ $log->metadata['filters']['to'] ?? '' }} · {{ $log->metadata['rows'] ?? 0 }} rows</small></div><span class="small muted">{{ $log->created_at->diffForHumans() }}</span></div>
            @empty<x-empty title="No exports yet" icon="download" />@endforelse
        </div>
    </section>
</div>
