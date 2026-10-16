<div>
    <div class="page-head"><div><h1>Managers</h1><p>People leaders, their direct reports and pending team workload. Assign a manager from the employee form.</p></div></div>
    <div class="stats">
        <div class="stat"><span class="stat-icon"><x-icon name="user-check" /></span><div><span class="stat-label">Managers</span><span class="stat-value">{{ $totals['managers'] }}</span><small>{{ $totals['reports'] }} direct reports</small></div></div>
        <a class="stat" href="{{ route('people.employees') }}"><span class="stat-icon amber"><x-icon name="users" /></span><div><span class="stat-label">Without a manager</span><span class="stat-value">{{ $totals['without_manager'] }}</span><small>active employees</small></div></a>
        <div class="stat"><span class="stat-icon violet"><x-icon name="calendar" /></span><div><span class="stat-label">Pending leave / OT</span><span class="stat-value">{{ $totals['pending_leave'] }} / {{ $totals['pending_overtime'] }}</span></div></div>
        <div class="stat"><span class="stat-icon red"><x-icon name="alert" /></span><div><span class="stat-label">Overdue tasks</span><span class="stat-value">{{ $totals['overdue'] }}</span></div></div>
    </div>
    <section class="card"><div class="table-wrap"><table class="table">
        <thead><tr><th>Manager</th><th>Department</th><th>Direct reports</th><th class="num">Pending leave</th><th class="num">Pending OT</th><th class="num">Overdue tasks</th></tr></thead>
        <tbody>
        @forelse($managers as $manager)
            <tr><td><a href="{{ route('people.employees.show', $manager) }}"><strong>{{ $manager->name }}</strong></a><small>{{ $manager->designation ?? $manager->employee_code }} @unless($manager->user?->hasRole('Manager'))· no Manager role @endunless</small></td>
                <td>{{ $manager->department?->name ?? '—' }}</td>
                <td><x-people-count :people="$manager->directReports" :title="'Reports to '.$manager->name" label="direct report" empty="0" />@if($manager->directReports->isNotEmpty())<small><a href="{{ route('people.employees', ['manager' => $manager->id]) }}">open in Employees</a></small>@endif</td>
                <td class="num">{{ $manager->pending_leave }}</td><td class="num">{{ $manager->pending_overtime }}</td><td class="num">@if($manager->overdue_tasks)<x-badge status="overdue">{{ $manager->overdue_tasks }}</x-badge>@else 0 @endif</td></tr>
        @empty
            <tr><td colspan="6"><x-empty title="No managers yet" icon="user-check">Grant the Manager role or set a manager on an employee.</x-empty></td></tr>
        @endforelse
        </tbody>
    </table></div></section>
    {{ $managers->links() }}
</div>
