<div>
    <div class="page-head">
        <div class="profile-chip">
            <span class="avatar avatar-lg">{{ collect(explode(' ', $employee->name))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('') }}</span>
            <div><p class="eyebrow">{{ $employee->employee_code }}</p><h1>{{ $employee->name }}</h1><p>{{ $employee->designation ?? 'Employee' }} · {{ $employee->department?->name ?? 'No department' }} · <x-badge :status="$employee->is_active ? 'active' : 'inactive'" /></p></div>
        </div>
        @if($canEdit)<div class="actions"><a class="btn" href="{{ route('people.employees', ['search' => $employee->employee_code, 'status' => 'all']) }}"><x-icon name="edit" class="icon-sm" />Edit in directory</a></div>@endif
    </div>

    <div class="stats">
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Present (month)</span><span class="stat-value">{{ ($summary['present'] ?? 0) + ($summary['late'] ?? 0) + ($summary['half_day'] ?? 0) }}</span><small>{{ $summary['late'] ?? 0 }} late · {{ $summary['half_day'] ?? 0 }} half-day</small></div></div>
        <div class="stat"><span class="stat-icon red"><x-icon name="user-minus" /></span><div><span class="stat-label">Absent (month)</span><span class="stat-value">{{ $summary['absent'] ?? 0 }}</span><small>{{ $summary['on_leave'] ?? 0 }} on leave</small></div></div>
        <div class="stat"><span class="stat-icon"><x-icon name="check-square" /></span><div><span class="stat-label">Tasks done (month)</span><span class="stat-value">{{ $metrics->completed_tasks }}</span><small>{{ $metrics->completed_late }} late · {{ $metrics->overdue_tasks }} overdue</small></div></div>
        <div class="stat"><span class="stat-icon violet"><x-icon name="timer" /></span><div><span class="stat-label">Logged hours</span><span class="stat-value">{{ $metrics->logged_hours }}</span><small>{{ $metrics->active_hours }} h active attendance</small></div></div>
    </div>

    <div class="grid grid-2">
        <section class="card"><div class="card-head"><h2>Profile</h2></div><div class="card-body"><dl class="kv">
            <dt>Email</dt><dd>{{ $employee->email ?? '—' }}</dd>
            <dt>Phone</dt><dd>{{ $employee->phone ?? '—' }}</dd>
            <dt>Manager</dt><dd>{{ $employee->manager?->name ?? '—' }}</dd>
            <dt>Direct reports</dt><dd>{{ $employee->directReports->pluck('name')->join(', ') ?: '—' }}</dd>
            <dt>Teams</dt><dd>{{ $employee->teams->pluck('name')->join(', ') ?: '—' }}</dd>
            <dt>Roles</dt><dd>{{ $employee->user?->roles->pluck('name')->join(', ') ?: 'No login' }}</dd>
            <dt>Joined</dt><dd>{{ $employee->joined_at?->format('d M Y') ?? '—' }}</dd>
            <dt>Employment</dt><dd>{{ \App\Services\EmployeeService::EMPLOYMENT_TYPES[$employee->employment_type] ?? 'Full time' }}</dd>
            <dt>Probation</dt><dd>@if($employee->confirmed_on)Confirmed {{ $employee->confirmed_on->format('d M Y') }}@elseif($employee->probation_ends_on)Until {{ $employee->probation_ends_on->format('d M Y') }}@if($employee->probation_ends_on->lte(today()->addDays(30))) <x-badge tone="amber">{{ $employee->probation_ends_on->isPast() ? 'ended' : 'ends soon' }}</x-badge>@endif @if($canEdit) <button class="btn btn-sm" wire:click="confirmEmployment" wire:confirm="Confirm {{ $employee->name }}'s employment from today?">Confirm</button>@endif @else — @endif</dd>
            <dt>Notice period</dt><dd>{{ $employee->notice_period_days !== null ? $employee->notice_period_days.' days' : '—' }}</dd>
            @if($employee->exit_date)<dt>Exit</dt><dd>{{ str_replace('_', ' ', ucfirst((string) $employee->exit_type)) }} · last day {{ $employee->exit_date->format('d M Y') }}@if($employee->resigned_on) · resigned {{ $employee->resigned_on->format('d M Y') }}@endif</dd>@endif
            <dt>Schedule</dt><dd>{{ $employee->work_start ? substr($employee->work_start, 0, 5).' – '.substr((string) $employee->work_end, 0, 5) : 'Not scheduled' }} · {{ (float) $employee->daily_work_hours }} h/day</dd>
        </dl></div></section>

        <section class="card"><div class="card-head"><h2>Leave balance {{ today()->year }}</h2></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th class="num">Entitled + accrued</th><th class="num">Carried</th><th class="num">Used</th><th class="num">Pending</th><th class="num">Remaining</th></tr></thead><tbody>
                @foreach($balances as $b)<tr><td><strong>{{ $b['type']->name }}</strong><small>{{ $b['type']->paid ? 'Paid' : 'Unpaid' }}</small></td><td class="num">{{ $b['entitled'] + $b['accrued'] }}</td><td class="num">{{ $b['carried'] }}</td><td class="num">{{ $b['used'] }}</td><td class="num">{{ $b['booked'] }}</td><td class="num"><strong>{{ round($b['remaining'], 2) }}</strong></td></tr>@endforeach
            </tbody></table></div>
        </section>

        <section class="card"><div class="card-head"><h2>Recent attendance</h2></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>In</th><th>Out</th><th>Status</th></tr></thead><tbody>
                @foreach($recentDays as $day)<tr><td>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D d M') }}</td><td>{{ $day['session']?->check_in?->format('h:i A') ?? '—' }}</td><td>{{ $day['session']?->check_out?->format('h:i A') ?? '—' }}</td><td><x-badge :status="$day['status']" /></td></tr>@endforeach
            </tbody></table></div>
        </section>

        <section class="card"><div class="card-head"><h2>Open tasks</h2></div><div class="list">
            @forelse($tasks as $task)<div class="list-row"><div><strong>{{ $task->title }}</strong><small>{{ ucfirst($task->priority) }} · {{ $task->due_on ? 'due '.$task->due_on->format('d M') : 'no due date' }}</small></div>@if($task->isOverdue())<x-badge status="overdue" />@else<x-badge :status="$task->status" />@endif</div>
            @empty<x-empty title="No open tasks" icon="check-square" />@endforelse
        </div></section>

        @if($showSalary)
            <section class="card"><div class="card-head"><h2>Salary history</h2></div>
                <div class="table-wrap"><table class="table"><thead><tr><th>Effective from</th><th class="num">Monthly</th><th class="num">Days/month</th><th class="num">Hours/day</th></tr></thead><tbody>
                    @forelse($employee->salaryProfiles as $p)<tr><td>{{ $p->effective_from->format('d M Y') }}</td><td class="num">{{ number_format((float) $p->monthly_salary, 2) }}</td><td class="num">{{ $p->working_days_per_month }}</td><td class="num">{{ (float) $p->standard_daily_hours }}</td></tr>
                    @empty<tr><td colspan="4"><x-empty title="No salary profile" icon="wallet" /></td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endif

        @if($managerHistory->isNotEmpty() || $teamHistory->isNotEmpty() || $history->isNotEmpty())
            <section class="card"><div class="card-head"><h2>History</h2></div><div class="list">
                @foreach($teamHistory as $h)<div class="list-row"><div><strong>Team {{ $h->team?->name ?? 'removed team' }}</strong><small>{{ $h->starts_on->format('d M Y') }} – {{ $h->ends_on?->format('d M Y') ?? 'now' }}</small></div></div>@endforeach
                @foreach($managerHistory as $h)<div class="list-row"><div><strong>Reports to {{ $h->manager?->name ?? 'nobody' }}</strong><small>{{ $h->starts_on->format('d M Y') }} – {{ $h->ends_on?->format('d M Y') ?? 'now' }}</small></div></div>@endforeach
                @foreach($history as $event)<div class="list-row"><div><strong>{{ str($event->action)->after('.')->replace('_', ' ')->ucfirst() }}</strong><small>{{ $event->actor?->name ?? 'System' }} · {{ $event->created_at->format('d M Y, h:i A') }}</small></div></div>@endforeach
            </div></section>
        @endif
        <section class="card"><div class="card-head"><h2>Assets & documents</h2></div><div class="list">
            @foreach($assets as $asset)<div class="list-row"><div><strong>{{ $asset->name }}</strong><small>{{ $asset->asset_tag }} · since {{ $asset->assigned_on?->format('d M Y') }}</small></div><x-badge :status="$asset->status" /></div>@endforeach
            @foreach($documents as $doc)<div class="list-row"><div><strong><a href="{{ route('hr.documents.download', $doc) }}">{{ $doc->original_name }}</a></strong><small>{{ ucfirst($doc->category) }} @if($doc->expires_on)· expires {{ $doc->expires_on->format('d M Y') }}@endif</small></div><x-icon name="file" class="icon-sm" /></div>@endforeach
            @if($assets->isEmpty() && $documents->isEmpty())<x-empty title="No assets or documents" icon="monitor" />@endif
        </div></section>
    </div>
</div>
