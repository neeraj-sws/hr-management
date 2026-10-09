<div>
    <div class="page-head"><div><h1>Managers</h1><p>People leaders, their direct reports and pending team workload. Assign a manager from the employee form.</p></div></div>
    <section class="card"><div class="table-wrap"><table class="table">
        <thead><tr><th>Manager</th><th>Department</th><th>Direct reports</th><th class="num">Pending leave</th><th class="num">Pending OT</th><th class="num">Overdue tasks</th></tr></thead>
        <tbody>
        @forelse($managers as $manager)
            <tr><td><a href="{{ route('people.employees.show', $manager) }}"><strong>{{ $manager->name }}</strong></a><small>{{ $manager->designation ?? $manager->employee_code }} @unless($manager->user?->hasRole('Manager'))· no Manager role @endunless</small></td>
                <td>{{ $manager->department?->name ?? '—' }}</td>
                <td>{{ $manager->directReports->count() }} @if($manager->directReports->isNotEmpty())<small>{{ $manager->directReports->pluck('name')->join(', ') }}</small>@endif</td>
                <td class="num">{{ $manager->pending_leave }}</td><td class="num">{{ $manager->pending_overtime }}</td><td class="num">@if($manager->overdue_tasks)<x-badge status="overdue">{{ $manager->overdue_tasks }}</x-badge>@else 0 @endif</td></tr>
        @empty
            <tr><td colspan="6"><x-empty title="No managers yet" icon="user-check">Grant the Manager role or set a manager on an employee.</x-empty></td></tr>
        @endforelse
        </tbody>
    </table></div></section>
</div>
