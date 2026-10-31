<div>
    @php($fmt = fn ($m) => \App\Support\Duration::fromMinutes((int) $m))
    <div class="page-head"><div><h1>Your projects</h1><p>Review each month's timesheet, raise an objection on any line, and approve the month when it is right.</p></div></div>
    <div class="grid grid-2">
        @forelse($projects as $row)
            @php($p = $row['project'])
            <section class="card">
                <div class="card-head"><div><h2>{{ $p->name }}</h2><p>{{ $p->status ? ucfirst(str_replace('_', ' ', $p->status)) : '' }}</p></div><a class="btn btn-sm btn-primary" href="{{ route('client.timesheet', ['project' => $p->id]) }}">Open timesheet</a></div>
                <div class="card-body">
                    <div class="list-row"><span>{{ today()->format('F Y') }}</span><strong>{{ $fmt($row['now']['billable_minutes']) }} billable · {{ $row['now']['entries'] }} lines</strong></div>
                    <div class="list-row"><span>{{ today()->subMonthNoOverflow()->format('F Y') }}</span><strong>{{ $fmt($row['last']['billable_minutes']) }} · @if($row['last']['approved_at'])<span style="color:var(--green)">approved</span>@elseif($row['last']['entries'])<a href="{{ route('client.timesheet', ['project' => $p->id, 'month' => today()->subMonthNoOverflow()->format('Y-m')]) }}">waiting for your approval</a>@else — @endif</strong></div>
                    @if($row['now']['open_objections'] + $row['last']['open_objections'])<div class="list-row"><span>Open objections</span><strong style="color:var(--amber)">{{ $row['now']['open_objections'] + $row['last']['open_objections'] }}</strong></div>@endif
                </div>
            </section>
        @empty
            <section class="card"><x-empty title="No projects yet" icon="folder">Your projects appear here once they are set up for you.</x-empty></section>
        @endforelse
    </div>
</div>
