<div x-data="{ tab: 'overview' }">
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

    @php($tabs = ['overview' => 'Overview', 'attendance' => 'Attendance', 'leave' => 'Leave & OT', 'work' => 'Work', 'payroll' => 'Payroll', 'docs' => 'Docs & assets', 'compliance' => 'Compliance'])
    @if($canEdit || $capture !== null || $appUsageOn !== null)@php($tabs['admin'] = 'Settings & monitoring')@endif
    @php($tabs['history'] = 'History')
    <div class="tabbar" role="tablist">
        @foreach($tabs as $key => $label)
            <button type="button" class="tab" :class="{ 'tab-on': tab === '{{ $key }}' }" @click="tab = '{{ $key }}'" role="tab">{{ $label }}</button>
        @endforeach
    </div>

    {{-- OVERVIEW --}}
    <div x-show="tab === 'overview'" class="grid grid-2">
        <section class="card"><div class="card-head"><h2>Profile</h2></div><div class="card-body"><dl class="kv">
            <dt>Email</dt><dd>{{ $employee->email ?? '—' }}</dd>
            <dt>Phone</dt><dd>{{ $employee->phone ?? '—' }}</dd>
            <dt>Manager</dt><dd>{{ $employee->manager?->name ?? '—' }}</dd>
            <dt>Direct reports</dt><dd>{{ $employee->directReports->pluck('name')->join(', ') ?: '—' }}</dd>
            <dt>Teams</dt><dd>{{ $employee->teams->pluck('name')->join(', ') ?: '—' }}</dd>
            <dt>Projects</dt><dd><strong>{{ $projectCounts['total'] }}</strong> · {{ $projectCounts['active'] }} active · {{ $projectCounts['completed'] }} completed</dd>
            <dt>Roles</dt><dd>{{ $employee->user?->roles->pluck('name')->join(', ') ?: 'No login' }}</dd>
            <dt>Joined</dt><dd>{{ $employee->joined_at?->format('d M Y') ?? '—' }}</dd>
            <dt>Employment</dt><dd>{{ \App\Services\EmployeeService::EMPLOYMENT_TYPES[$employee->employment_type] ?? 'Full time' }}</dd>
            <dt>Probation</dt><dd>@if($employee->confirmed_on)Confirmed {{ $employee->confirmed_on->format('d M Y') }}@elseif($employee->probation_ends_on)Until {{ $employee->probation_ends_on->format('d M Y') }}@if($employee->probation_ends_on->lte(today()->addDays(30))) <x-badge tone="amber">{{ $employee->probation_ends_on->isPast() ? 'ended' : 'ends soon' }}</x-badge>@endif @if($canEdit) <button class="btn btn-sm" wire:click="confirmEmployment" wire:confirm="Confirm {{ $employee->name }}'s employment from today?">Confirm</button>@endif @else — @endif</dd>
            <dt>Notice period</dt><dd>{{ $employee->notice_period_days !== null ? $employee->notice_period_days.' days' : '—' }}</dd>
            @if($employee->exit_date)<dt>Exit</dt><dd>{{ str_replace('_', ' ', ucfirst((string) $employee->exit_type)) }} · last day {{ $employee->exit_date->format('d M Y') }}@if($employee->resigned_on) · resigned {{ $employee->resigned_on->format('d M Y') }}@endif</dd>@endif
            <dt>Schedule</dt><dd>{{ $employee->work_start ? substr($employee->work_start, 0, 5).' – '.substr((string) $employee->work_end, 0, 5) : 'Not scheduled' }} · {{ (float) $employee->daily_work_hours }} h/day</dd>
        </dl></div></section>

        <section class="card"><div class="card-head"><h2>Open tasks</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'tasks', 'employee' => $employee->id]) }}">Full report →</a></div><div class="list">
            @forelse($tasks as $task)<div class="list-row"><div><strong>{{ $task->title }}</strong><small>{{ ucfirst($task->priority) }} · {{ $task->due_on ? 'due '.$task->due_on->format('d M') : 'no due date' }}</small></div>@if($task->isOverdue())<x-badge status="overdue" />@else<x-badge :status="$task->status" />@endif</div>
            @empty<x-empty title="No open tasks" icon="check-square" />@endforelse
        </div></section>
    </div>

    {{-- ATTENDANCE --}}
    <div x-show="tab === 'attendance'" x-cloak>
        @if(auth()->user()->canAny(['hr.manage', 'employees.manage']))
            <section class="card mb">
                <div class="card-head"><div><h2>Import {{ $employee->name }}'s attendance (Excel)</h2><p>Upload this employee's sheet (one row per day). Every row is saved for {{ $employee->name }} — the Employee ID column in the file is not needed. Afterwards the imported days open in Attendance records.</p></div></div>
                <form method="POST" action="{{ route('attendance.records.import') }}" enctype="multipart/form-data" class="card-body toolbar" style="border:0" x-data="{ busy: false }" x-on:submit="busy = true">
                    @csrf
                    <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                    <input type="file" name="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                    <label class="check"><input type="checkbox" name="update_existing" value="1"> Update days that already exist</label>
                    @if(\App\Support\Access::isAdmin(auth()->user()))<label class="check" title="For migrating old history: days inside a locked payroll are saved too; that payroll is not recalculated. Admins only."><input type="checkbox" name="into_locked" value="1"> Old data: also import into locked payroll months</label>@endif
                    <button class="btn btn-primary" type="submit" x-bind:disabled="busy"><span x-show="busy" class="spinner"></span>Import for {{ $employee->name }}</button>
                    <a class="btn btn-sm" href="{{ route('imports.template', 'attendance') }}"><x-icon name="download" class="icon-sm" />Excel template</a>
                </form>
            </section>
        @endif
        <section class="card"><div class="card-head"><h2>Recent attendance</h2><span><a class="link-sm" href="{{ route('attendance.records', ['employee' => $employee->id, 'from' => now()->subYear()->toDateString(), 'to' => today()->toDateString()]) }}">All days (incl. imported) →</a> · <a class="link-sm" href="{{ route('reports.show', ['type' => 'attendance', 'employee' => $employee->id]) }}">Full report →</a></span></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>In</th><th>Out</th><th>Status</th></tr></thead><tbody>
                @foreach($recentDays as $day)<tr><td>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D d M') }}</td><td>{{ $day['session']?->check_in?->format('h:i A') ?? '—' }}</td><td>{{ $day['session']?->check_out?->format('h:i A') ?? '—' }}</td><td><x-badge :status="$day['status']" /></td></tr>@endforeach
            </tbody></table></div>
        </section>
    </div>

    {{-- LEAVE & OT --}}
    <div x-show="tab === 'leave'" x-cloak class="grid grid-2">
        <section class="card"><div class="card-head"><h2>Leave balance {{ today()->year }}</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'leave', 'employee' => $employee->id]) }}">Full report →</a></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th class="num">Entitled + accrued</th><th class="num">Carried</th><th class="num">Used</th><th class="num">Pending</th><th class="num">Remaining</th></tr></thead><tbody>
                @foreach($balances as $b)<tr><td><strong>{{ $b['type']->name }}</strong><small>{{ $b['type']->paid ? 'Paid' : 'Unpaid' }}</small></td><td class="num">{{ $b['entitled'] + $b['accrued'] }}</td><td class="num">{{ $b['carried'] }}</td><td class="num">{{ $b['used'] }}</td><td class="num">{{ $b['booked'] }}</td><td class="num"><strong>{{ round($b['remaining'], 2) }}</strong></td></tr>@endforeach
            </tbody></table></div>
        </section>
        <section class="card"><div class="card-head"><h2>Recent leave requests</h2></div><div class="list">
            @forelse($recentLeave as $lr)<div class="list-row"><div><strong>{{ $lr->leaveType?->name }}</strong><small>{{ $lr->starts_on?->format('d M') }} – {{ $lr->ends_on?->format('d M Y') }} · {{ (float) $lr->days }} day(s)</small></div><x-badge :status="$lr->status" /></div>
            @empty<x-empty title="No leave requests" icon="calendar" />@endforelse
        </div></section>
        <section class="card"><div class="card-head"><h2>Overtime</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'overtime', 'employee' => $employee->id]) }}">Full report →</a></div><div class="list">
            @forelse($overtime as $ot)<div class="list-row"><div><strong>{{ $ot->work_date?->format('d M Y') }}</strong><small>{{ $ot->approved_minutes ?? $ot->requested_minutes }} min · {{ $ot->reason }}</small></div><x-badge :status="$ot->status" /></div>
            @empty<x-empty title="No overtime" icon="timer" />@endforelse
        </div></section>
    </div>

    {{-- WORK --}}
    <div x-show="tab === 'work'" x-cloak class="grid grid-2">
        <section class="card"><div class="card-head"><div><h2>Projects ({{ $projectCounts['total'] }})</h2><p>{{ $projectCounts['active'] }} active · {{ $projectCounts['planned'] }} planned · {{ $projectCounts['on_hold'] }} on hold · {{ $projectCounts['completed'] }} completed</p></div><a class="link-sm" href="{{ route('reports.show', ['type' => 'projects', 'employee' => $employee->id]) }}">Full report →</a></div><div class="list">
            @forelse($projects as $project)<div class="list-row"><div><strong>{{ $project->name }}</strong><small>{{ $project->manager_id === $employee->id ? 'Manager' : 'Manager: '.($project->manager?->name ?? '—') }} · {{ $project->my_tasks_count }} {{ str('task')->plural($project->my_tasks_count) }} ({{ $project->my_open_tasks_count }} open)</small></div><x-badge :status="$project->status" /></div>
            @empty<x-empty title="Not on any project" icon="layers" />@endforelse
            @if($projectCounts['total'] > $projects->count())<p class="small muted">Showing {{ $projects->count() }} of {{ $projectCounts['total'] }}.</p>@endif
        </div></section>
        <section class="card"><div class="card-head"><h2>Open tasks</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'tasks', 'employee' => $employee->id]) }}">Full report →</a></div><div class="list">
            @forelse($tasks as $task)<div class="list-row"><div><strong>{{ $task->title }}</strong><small>{{ ucfirst($task->priority) }} · {{ $task->due_on ? 'due '.$task->due_on->format('d M') : 'no due date' }}</small></div>@if($task->isOverdue())<x-badge status="overdue" />@else<x-badge :status="$task->status" />@endif</div>
            @empty<x-empty title="No open tasks" icon="check-square" />@endforelse
        </div></section>
        <section class="card"><div class="card-head"><h2>Performance reviews</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'performance', 'employee' => $employee->id]) }}">Full report →</a></div><div class="list">
            @forelse($reviews as $rev)<div class="list-row"><div><strong>{{ $rev->period_starts_on?->format('M Y') }} – {{ $rev->period_ends_on?->format('M Y') }}</strong><small>By {{ $rev->reviewer?->name ?? '—' }} {{ $rev->rating ? '· rating '.$rev->rating.'/5' : '' }}</small></div><x-badge :status="$rev->status" /></div>
            @empty<x-empty title="No reviews" icon="star" />@endforelse
        </div></section>
        <section class="card"><div class="card-head"><h2>Training</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'training', 'employee' => $employee->id]) }}">Full report →</a></div><div class="list">
            @forelse($training as $tr)<div class="list-row"><div><strong>{{ $tr->course?->title }}</strong><small>{{ $tr->course?->is_mandatory ? 'Mandatory' : 'Optional' }} {{ $tr->due_on ? '· due '.$tr->due_on->format('d M Y') : '' }}</small></div><x-badge :status="$tr->displayStatus()" /></div>
            @empty<x-empty title="No training assigned" icon="list" />@endforelse
        </div></section>
    </div>

    {{-- PAYROLL --}}
    <div x-show="tab === 'payroll'" x-cloak class="grid grid-2">
        @if($showSalary)
            <section class="card"><div class="card-head"><h2>Salary history</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'salary', 'employee' => $employee->id]) }}">Full report →</a></div>
                <div class="table-wrap"><table class="table"><thead><tr><th>Effective from</th><th class="num">Monthly</th><th class="num">Days/month</th><th class="num">Hours/day</th></tr></thead><tbody>
                    @forelse($employee->salaryProfiles as $p)<tr><td>{{ $p->effective_from->format('d M Y') }}</td><td class="num">{{ number_format((float) $p->monthly_salary, 2) }}</td><td class="num">{{ $p->working_days_per_month }}</td><td class="num">{{ (float) $p->standard_daily_hours }}</td></tr>
                    @empty<tr><td colspan="4"><x-empty title="No salary profile" icon="wallet" /></td></tr>@endforelse
                </tbody></table></div>
            </section>
            <section class="card"><div class="card-head"><h2>Payslips</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'payments', 'employee' => $employee->id]) }}">Payments →</a></div><div class="list">
                @forelse($payslips as $ps)<div class="list-row"><div><strong>{{ $ps->item?->run?->period_starts_on?->format('M Y') ?? $ps->reference }}</strong><small>Net {{ number_format((float) ($ps->item?->net_amount ?? 0), 2) }} · {{ $ps->reference }}</small></div><a class="btn btn-sm" href="{{ route('hr.payslips.show', $ps) }}">Open</a></div>
                @empty<x-empty title="No payslips" icon="file" />@endforelse
            </div></section>
        @else
            <section class="card"><div class="card-body"><x-empty title="You do not have access to this employee's salary." icon="lock" /></div></section>
        @endif
    </div>

    {{-- DOCS & ASSETS --}}
    <div x-show="tab === 'docs'" x-cloak>
        <section class="card"><div class="card-head"><h2>Assets & documents</h2></div><div class="list">
            @foreach($assets as $asset)<div class="list-row"><div><strong>{{ $asset->name }}</strong><small>{{ $asset->asset_tag }} · since {{ $asset->assigned_on?->format('d M Y') }}</small></div><x-badge :status="$asset->status" /></div>@endforeach
            @foreach($documents as $doc)<div class="list-row"><div><strong><a href="{{ route('hr.documents.download', $doc) }}">{{ $doc->original_name }}</a></strong><small>{{ ucfirst($doc->category) }} @if($doc->expires_on)· expires {{ $doc->expires_on->format('d M Y') }}@endif</small></div><x-icon name="file" class="icon-sm" /></div>@endforeach
            @if($assets->isEmpty() && $documents->isEmpty())<x-empty title="No assets or documents" icon="monitor" />@endif
        </div></section>
    </div>

    {{-- COMPLIANCE --}}
    <div x-show="tab === 'compliance'" x-cloak class="grid grid-2">
        <section class="card"><div class="card-head"><h2>Penalties</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'compliance', 'employee' => $employee->id]) }}">Full report →</a></div><div class="list">
            @forelse($penalties as $pen)<div class="list-row"><div><strong>{{ number_format((float) $pen->amount, 2) }} · {{ $pen->reason }}</strong><small>{{ $pen->effective_on?->format('d M Y') }} · {{ $pen->policyRule?->rule_key ?? 'manual' }}</small></div><x-badge :status="$pen->status" /></div>
            @empty<x-empty title="No penalties" icon="shield" />@endforelse
        </div></section>
        <section class="card"><div class="card-head"><h2>Expenses</h2><a class="link-sm" href="{{ route('reports.show', ['type' => 'expenses', 'employee' => $employee->id]) }}">Full report →</a></div><div class="list">
            @forelse($expenses as $ex)<div class="list-row"><div><strong>{{ $ex->category }} · {{ number_format((float) $ex->amount, 2) }}</strong><small>{{ $ex->spent_on?->format('d M Y') }} · {{ $ex->description }}</small></div><x-badge :status="$ex->displayStatus()" /></div>
            @empty<x-empty title="No expense claims" icon="receipt" />@endforelse
        </div></section>
    </div>

    {{-- ADMIN: SETTINGS & MONITORING --}}
    @if(isset($tabs['admin']))
    <div x-show="tab === 'admin'" x-cloak class="grid grid-2">
        @if($canEdit)
            <section class="card"><div class="card-head"><div><h2>Attendance methods</h2><p>Where this employee may check in, break and check out. Managers can always correct attendance.</p></div></div><div class="card-body">
                <div class="chips" wire:ignore.self>
                    @foreach(\App\Services\AttendanceService::SOURCES as $key => $label)
                        @php($on = in_array($key, $attendanceSources, true))
                        <button type="button" class="chip {{ $on ? 'chip-on' : '' }}" wire:click="setAttendanceSources({{ \Illuminate\Support\Js::from($on ? array_values(array_diff($attendanceSources, [$key])) : array_merge($attendanceSources, [$key])) }})">{{ $label }}</button>
                    @endforeach
                </div>
            </div></section>
        @endif
        @if($appUsageOn !== null)
            <section class="card"><div class="card-head"><div><h2>Application usage</h2><p>@if($appUsageOn)On — records which app is in front and for how long, while checked in and working. App name and time only; no titles or content.@else Off.@endif</p></div>
                <button type="button" class="btn {{ $appUsageOn ? '' : 'btn-primary' }}" wire:click="toggleAppUsage" wire:loading.attr="disabled">{{ $appUsageOn ? 'Turn off' : 'Turn on' }}</button></div>
                @if($appUsageOn)<div class="card-body"><label class="check"><input type="checkbox" {{ $windowTitlesOn ? 'checked' : '' }} wire:click="toggleWindowTitles"> Also record the active window/tab title (shows the website, document or email subject that is in front — more detail, more sensitive)</label></div>@endif
            </section>
        @endif
        @if($capture)
            <section class="card"><div class="card-head"><div><h2>Screen capture</h2><p>@if($capture['enabled'])On — every {{ $capture['interval_minutes'] }} min while checked in and working.@else Off. @endif</p></div>
                <button type="button" class="btn {{ $capture['enabled'] ? '' : 'btn-primary' }}" wire:click="toggleCapture" wire:loading.attr="disabled">{{ $capture['enabled'] ? 'Turn off' : 'Turn on' }}</button></div></section>
        @endif
    </div>
    @endif

    {{-- HISTORY --}}
    <div x-show="tab === 'history'" x-cloak>
        <section class="card"><div class="card-head"><h2>History</h2></div><div class="list">
            @forelse($teamHistory as $h)<div class="list-row"><div><strong>Team {{ $h->team?->name ?? 'removed team' }}</strong><small>{{ $h->starts_on->format('d M Y') }} – {{ $h->ends_on?->format('d M Y') ?? 'now' }}</small></div></div>@empty @endforelse
            @foreach($managerHistory as $h)<div class="list-row"><div><strong>Reports to {{ $h->manager?->name ?? 'nobody' }}</strong><small>{{ $h->starts_on->format('d M Y') }} – {{ $h->ends_on?->format('d M Y') ?? 'now' }}</small></div></div>@endforeach
            @foreach($history as $event)<div class="list-row"><div><strong>{{ str($event->action)->after('.')->replace('_', ' ')->ucfirst() }}</strong><small>{{ $event->actor?->name ?? 'System' }} · {{ $event->created_at->format('d M Y, h:i A') }}</small></div></div>@endforeach
            @if($teamHistory->isEmpty() && $managerHistory->isEmpty() && $history->isEmpty())<x-empty title="No history yet" icon="history" />@endif
        </div></section>
    </div>
</div>
