<div>
    <div class="page-head">
        <div><h1>Support reports</h1><p>Requests raised in the chosen dates. Resolution time = from raising the request to “Resolved”.</p></div>
        <div class="actions"><a class="btn" href="{{ route('support.desk') }}"><x-icon name="inbox" class="icon-sm" />Support desk</a></div>
    </div>

    <section class="card mb">
        <div class="toolbar" style="border:0">
            <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>To</span><input type="date" wire:model.live="to"></label>
            <label class="field"><span>Category</span><select wire:model.live="category"><option value="">All</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">All</option><option value="active">Not resolved yet</option>@foreach($statuses as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            <label class="field"><span>Assigned to</span><select wire:model.live="assignee"><option value="">Anyone</option><option value="none">Nobody</option>@foreach($handlers as $h)<option value="{{ $h->id }}">{{ $h->name }}</option>@endforeach</select></label>
            <button type="button" class="btn" wire:click="resetFilters"><x-icon name="refresh" class="icon-sm" />Reset</button>
        </div>
    </section>

    @php($s = $report['summary'])
    @php($hrs = fn ($h) => $h === null ? '—' : \App\Support\Duration::words((int) round($h * 60)))
    <div class="stats">
        @foreach([
            ['Total requests', $s['total'], 'raised in these dates', 'blue', 'inbox'],
            ['Open', $s['open'], 'open, assigned or in progress', 'amber', 'clock'],
            ['Waiting for employee', $s['waiting'], 'support asked for a reply', 'violet', 'message'],
            ['Pending', $s['pending'], 'not resolved yet', 'red', 'hourglass'],
            ['Resolved', $s['resolved'], ($s['closed'] ? $s['closed'].' more closed' : 'and not closed yet'), 'green', 'check-circle'],
            ['Avg. resolution time', $hrs($s['avg_hours']), 'first reply in '.$hrs($s['first_reply_hours']).' on average', 'blue', 'timer'],
        ] as [$label, $value, $hint, $tone, $icon])
            <div class="stat"><span class="stat-icon {{ $tone }}"><x-icon :name="$icon" /></span><div><span class="stat-label">{{ $label }}</span><strong class="stat-value">{{ $value }}</strong><small>{{ $hint }}</small></div></div>
        @endforeach
    </div>

    <div class="grid grid-2 mb">
        <section class="card">
            <div class="card-head"><div><h2>By category</h2></div></div>
            <div class="table-wrap"><table class="table" data-no-sort>
                <thead><tr><th>Category</th><th>Total</th><th>Pending</th><th>Resolved / closed</th><th>Avg. resolution</th></tr></thead>
                <tbody>
                @forelse($report['by_category'] as $row)
                    <tr><td><strong>{{ $row['name'] }}</strong></td><td>{{ $row['total'] }}</td><td>{{ $row['pending'] }}</td><td>{{ $row['resolved'] + $row['closed'] }}</td><td>{{ $hrs($row['avg_hours']) }}</td></tr>
                @empty
                    <tr><td colspan="5"><x-empty title="No requests in these dates" icon="chart" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        <section class="card">
            <div class="card-head"><div><h2>Per handler</h2><p>Who the requests are assigned to.</p></div></div>
            <div class="table-wrap"><table class="table" data-no-sort>
                <thead><tr><th>Handler</th><th>Assigned</th><th>Pending</th><th>Resolved / closed</th><th>Avg. resolution</th><th>Avg. first reply</th></tr></thead>
                <tbody>
                @forelse($report['by_handler'] as $row)
                    <tr><td><strong>{{ $row['name'] }}</strong></td><td>{{ $row['total'] }}</td><td>{{ $row['pending'] }}</td><td>{{ $row['resolved'] + $row['closed'] }}</td><td>{{ $hrs($row['avg_hours']) }}</td><td>{{ $hrs($row['first_reply_hours']) }}</td></tr>
                @empty
                    <tr><td colspan="6"><x-empty title="No requests in these dates" icon="users" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>

    <section class="card mb">
        <div class="card-head"><div><h2>Employee-wise</h2><p>Open an employee to see all their requests.</p></div></div>
        <div class="table-wrap"><table class="table" data-no-sort>
            <thead><tr><th>Employee</th><th>Requests</th><th>Pending</th><th>Resolved / closed</th><th>Last request</th><th></th></tr></thead>
            <tbody>
            @forelse($report['by_employee'] as $row)
                <tr wire:key="emp-{{ $row['id'] }}"><td><strong>{{ $row['name'] }}</strong><small>{{ $row['code'] }}</small></td><td>{{ $row['total'] }}</td><td>{{ $row['pending'] }}</td><td>{{ $row['resolved'] + $row['closed'] }}</td><td><small>{{ $row['last']?->format('d M Y') }}</small></td>
                    <td><button class="btn btn-sm" wire:click="showEmployee({{ $row['id'] }})">History</button></td></tr>
            @empty
                <tr><td colspan="6"><x-empty title="No requests in these dates" icon="users" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    @if($person)
        <section class="card">
            <div class="card-head"><div><h2>All requests of {{ $person->name }} ({{ $person->employee_code }})</h2><p>Every date, not only the chosen ones.</p></div><button class="btn btn-sm btn-ghost" wire:click="showEmployee" aria-label="Close"><x-icon name="x" class="icon-sm" /></button></div>
            <div class="table-wrap"><table class="table" data-no-sort>
                <thead><tr><th>Request</th><th>Subject</th><th>Category</th><th>Status</th><th>Assigned to</th><th>Raised</th><th>Resolved</th></tr></thead>
                <tbody>
                @forelse($history as $t)
                    <tr><td class="nowrap"><a href="{{ route('support.show', $t->id) }}"><strong>{{ $t->number() }}</strong></a></td><td>{{ $t->subject }}</td><td>{{ $t->category?->name }}</td>
                        <td><x-badge :tone="$statusTones[$t->status] ?? 'gray'">{{ $statuses[$t->status] ?? $t->status }}</x-badge></td><td>{{ $t->assignee?->name ?? '—' }}</td>
                        <td class="nowrap"><small>{{ $t->created_at->format('d M Y') }}</small></td><td class="nowrap"><small>{{ $t->resolved_at?->format('d M Y') ?? '—' }}</small></td></tr>
                @empty
                    <tr><td colspan="7"><x-empty title="No requests" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    @endif
</div>
