<div>
    <div class="page-head">
        <div><h1>My team</h1><p>{{ $directCount }} direct {{ $directCount === 1 ? 'report' : 'reports' }} · {{ $rows->count() }} people in total (including their teams)</p></div>
        <div class="actions"><a class="btn" href="{{ route('company.org-chart') }}"><x-icon name="team" class="icon-sm" />Whole company</a></div>
    </div>

    <section class="card mb">
        <div class="card-head"><div><h2>Team chart</h2><p>Who reports to whom under you</p></div></div>
        <div class="card-body"><x-team-chart :root="$me" /></div>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Today</h2><p>{{ today()->format('l, d M Y') }}</p></div></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Employee</th><th>Designation</th><th>Reports to</th><th>Today</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                @php($e = $row['employee'])
                <tr wire:key="team-{{ $e->id }}">
                    <td><div style="display:flex;gap:10px;align-items:center"><x-avatar :employee="$e" /><div><a href="{{ route('people.employees.show', $e) }}"><strong>{{ $e->name }}</strong></a><small>{{ $e->employee_code }}{{ $e->department ? ' · '.$e->department->name : '' }}</small></div></div></td>
                    <td>{{ $e->designation ?: '—' }}</td>
                    <td>{{ $row['direct'] ? 'You' : ($e->manager?->name ?? '—') }}</td>
                    <td>@if($row['today'][0] === 'not_in')<x-badge tone="gray">Not checked in</x-badge>@else<x-badge :status="$row['today'][0]" />@endif @if($row['today'][1])<small>{{ $row['today'][1] }}</small>@endif</td>
                </tr>
            @empty
                <tr><td colspan="4"><x-empty title="No one reports to you yet" icon="users" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</div>
