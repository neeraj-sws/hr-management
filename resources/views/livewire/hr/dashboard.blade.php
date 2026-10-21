<div>
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ today()->format('l, d F Y') }}</p>
            <h1>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ auth()->user()->name }}</h1>
            <p>{{ $orgWide ? 'Organisation overview for today.' : ($profile && $profile->directReports()->exists() ? 'Your team at a glance.' : 'Your work at a glance.') }}</p>
        </div>
        @if($profile)
            <div class="actions">
                <a class="btn" href="{{ route('leave.requests') }}"><x-icon name="calendar" class="icon-sm" />Request leave</a>
                <a class="btn btn-primary" href="{{ route('attendance.my') }}"><x-icon name="clock" class="icon-sm" />{{ !$mySession ? 'Check in' : ($mySession->check_out ? 'View attendance' : 'My attendance') }}</a>
            </div>
        @endif
    </div>

    @if($capture)
        <div class="alert {{ $capture['acknowledged'] ? 'alert-info' : 'alert-warn' }}" role="status">
            <strong>● Screen monitoring is ON for you.</strong> {{ $capture['terms']['frequency'] }} Only while you are checked in and working — not during breaks or after check-out.
            <small>Why: {{ $capture['terms']['purpose'] }} · <a href="{{ route('monitoring.policy') }}">{{ $capture['acknowledged'] ? 'Monitoring policy' : 'Read the monitoring policy and confirm' }}</a></small>
        </div>
    @endif

    @if($profile)
        <div class="grid grid-3 mb">
            <section class="card card-body">
                <p class="eyebrow">Today</p>
                @if(!$mySession)
                    <h2>Not checked in</h2><p class="muted small">Start your day from My Attendance.</p>
                @else
                    <h2>@hms($myWorked) <span class="small muted">worked</span></h2>
                    <p class="small muted">In {{ $mySession->check_in->format('h:i A') }} @if($mySession->check_out)· Out {{ $mySession->check_out->format('h:i A') }}@endif · <x-badge :status="$mySession->status" /></p>
                @endif
            </section>
            <section class="card card-body">
                <p class="eyebrow">Task timer</p>
                @if($runningTimer)
                    <h2>{{ \Illuminate\Support\Str::limit($runningTimer->task->title, 34) }}</h2><p class="small muted">Running since {{ $runningTimer->started_at->format('h:i A') }} · <a href="{{ route('work.timer') }}">Open timer</a></p>
                @else
                    <h2>No timer running</h2><p class="small muted"><a href="{{ route('work.my-tasks') }}">Pick a task to start</a></p>
                @endif
            </section>
            <section class="card card-body">
                <p class="eyebrow">Leave balance</p>
                @forelse($myBalances as $balance)
                    <div class="list-row" style="padding:4px 0;border:0"><span class="small">{{ $balance['type'] }}</span><strong>{{ rtrim(rtrim(number_format($balance['remaining'], 2), '0'), '.') }} days</strong></div>
                @empty
                    <p class="small muted">No leave balances configured.</p>
                @endforelse
            </section>
        </div>
    @endif

    <div class="stats">
        @if($orgWide)<div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('employees')" wire:keydown.enter="openDetail('employees')" title="Click to see the list"><span class="stat-icon"><x-icon name="users" /></span><div><span class="stat-label">Active employees</span><span class="stat-value">{{ $metrics['employees'] }}</span><small>In your scope</small></div></div>@endif
        <div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('checked_in')" wire:keydown.enter="openDetail('checked_in')" title="Click to see the list"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Checked in today</span><span class="stat-value">{{ $metrics['checked_in'] }}</span><small>{{ $metrics['still_in'] }} still checked in · {{ $metrics['working'] }} working now</small></div></div>
        <div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('checked_out')" wire:keydown.enter="openDetail('checked_out')" title="Click to see the list"><span class="stat-icon violet"><x-icon name="user-minus" /></span><div><span class="stat-label">Checked out today</span><span class="stat-value">{{ $metrics['checked_out'] }}</span><small>Finished for the day</small></div></div>
        <div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('on_break')" wire:keydown.enter="openDetail('on_break')" title="Click to see the list"><span class="stat-icon amber"><x-icon name="clock" /></span><div><span class="stat-label">On lunch / break now</span><span class="stat-value">{{ $metrics['on_break'] }}</span><small>Currently on a break</small></div></div>
        <div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('absent')" wire:keydown.enter="openDetail('absent')" title="Click to see the list"><span class="stat-icon red"><x-icon name="user-minus" /></span><div><span class="stat-label">Absent today</span><span class="stat-value">{{ $metrics['absent'] }}</span><small>Excludes approved leave</small></div></div>
        <button type="button" class="stat stat-click" wire:click="openDetail('on_leave')" title="Click to see the list"><span class="stat-icon violet"><x-icon name="calendar" /></span><div><span class="stat-label">On leave</span><span class="stat-value">{{ $metrics['on_leave'] }}</span><small>Approved leave today</small></div></button>
        <div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('late')" wire:keydown.enter="openDetail('late')" title="Click to see the list"><span class="stat-icon amber"><x-icon name="clock" /></span><div><span class="stat-label">Late arrivals</span><span class="stat-value">{{ $metrics['late'] }}</span><small>After start time + grace</small></div></div>
        @if($projectCounts)<div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('projects')" wire:keydown.enter="openDetail('projects')" title="Click to see the list"><span class="stat-icon violet"><x-icon name="layers" /></span><div><span class="stat-label">{{ ['company' => 'Active projects', 'team' => 'Team active projects', 'mine' => 'My active projects'][$projectScope] }}</span><span class="stat-value">{{ $projectCounts['active'] }}</span><small><a wire:click.stop href="{{ route($projectScope !== 'mine' && auth()->user()->canAny(['projects.manage', 'hr.manage']) ? 'work.projects' : 'work.my-projects') }}">{{ $projectCounts['total'] }} {{ $projectScope === 'company' ? 'in the company' : ($projectScope === 'team' ? 'in your team' : 'in total') }}</a> · {{ $projectCounts['planned'] }} planned · {{ $projectCounts['on_hold'] }} on hold · {{ $projectCounts['completed'] }} completed</small></div></div>@endif
        <div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('open_tasks')" wire:keydown.enter="openDetail('open_tasks')" title="Click to see the list"><span class="stat-icon"><x-icon name="check-square" /></span><div><span class="stat-label">Open tasks</span><span class="stat-value">{{ $metrics['active_tasks'] }}</span><small>{{ $metrics['overdue_tasks'] }} overdue</small></div></div>
        <div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('todays_tasks')" wire:keydown.enter="openDetail('todays_tasks')" title="Click to see the list"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Today's tasks completed</span><span class="stat-value">{{ $metrics['task_completion_pct'] }}%</span><small>{{ $metrics['completed_today'] }} of {{ $metrics['todays_tasks'] }} done (due today, overdue or finished today)</small></div></div>
        @if($metrics['pending_approvals'] !== null)<div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('approvals')" wire:keydown.enter="openDetail('approvals')" title="Click to see the list"><span class="stat-icon amber"><x-icon name="inbox" /></span><div><span class="stat-label">Pending approvals</span><span class="stat-value">{{ $metrics['pending_approvals'] }}</span><small><a wire:click.stop href="{{ route('approvals.pending') }}">Review now</a></small></div></div>@endif
        @if($metrics['payroll_pending'] !== null)<div class="stat stat-click" role="button" tabindex="0" wire:click="openDetail('payroll')" wire:keydown.enter="openDetail('payroll')" title="Click to see the list"><span class="stat-icon"><x-icon name="layers" /></span><div><span class="stat-label">Payroll in progress</span><span class="stat-value">{{ $metrics['payroll_pending'] }}</span><small>Draft or awaiting lock</small></div></div>@endif
    </div>

    <div class="grid grid-2">
        <section class="card">
            <div class="card-head"><div><h2>Announcements</h2></div><a class="btn btn-sm" href="{{ route('company.announcements') }}">All</a></div>
            <div class="list">
                @forelse($announcements as $a)
                    <a class="list-row" href="{{ route('company.announcements') }}"><div><strong>@if($a->is_pinned)📌 @endif{{ $a->title }}</strong><small>{{ $a->published_at->diffForHumans() }} · {{ \Illuminate\Support\Str::limit($a->body, 110) }}</small></div></a>
                @empty
                    <x-empty title="No announcements" icon="bell" />
                @endforelse
            </div>
        </section>
        <section class="card">
            <div class="card-head"><div><h2>This week</h2>@if($nextSpecialOff)<p>Next weekly off: <strong>{{ $nextSpecialOff['date']->format('D d M') }}</strong> · {{ $nextSpecialOff['reason'] }}</p>@endif<p>@if($nextHoliday)Next holiday: <strong>{{ $nextHoliday['name'] }}</strong>, {{ $nextHoliday['date']->format('D d M') }}@else No upcoming holidays set @endif</p></div><a class="btn btn-sm" href="{{ route('company.celebrations') }}">Celebrations</a></div>
            <div class="list">
                @forelse($celebrations as $event)
                    <div class="list-row"><div><strong>{{ $event['type'] === 'birthday' ? '🎂' : '🎉' }} {{ $event['employee']->name }}</strong><small>{{ $event['type'] === 'birthday' ? 'Birthday' : $event['years'].' '.str('year')->plural($event['years']).' at the company' }} · {{ $event['today'] ? 'today' : $event['date']->format('D d M') }}</small></div>@if($event['today'])<x-badge tone="green">Today</x-badge>@endif</div>
                @empty
                    <x-empty title="No birthdays or anniversaries this week" icon="star" />
                @endforelse
            </div>
        </section>
        @if($profile && $profile->directReports()->where('is_active', true)->exists())
            <section class="card" style="grid-column:1/-1">
                <div class="card-head"><div><h2>My team</h2><p>Everyone who reports to you, directly or through your managers</p></div><a class="btn btn-sm" href="{{ route('company.org-chart') }}">Org chart</a></div>
                <div class="card-body"><x-team-chart :root="$profile" /></div>
            </section>
        @endif
        @if($probationDue->isNotEmpty())
            <section class="card">
                <div class="card-head"><div><h2>Probation reviews due</h2><p>Ending in the next 30 days</p></div></div>
                <div class="list">@foreach($probationDue as $p)<a class="list-row" href="{{ route('people.employees.show', $p) }}"><div><strong>{{ $p->name }}</strong><small>Probation ends {{ $p->probation_ends_on->format('d M Y') }}</small></div><x-badge :tone="$p->probation_ends_on->isPast() ? 'red' : 'amber'">{{ $p->probation_ends_on->isPast() ? 'overdue' : 'due' }}</x-badge></a>@endforeach</div>
            </section>
        @endif
        @if($myTraining->isNotEmpty())
            <section class="card">
                <div class="card-head"><div><h2>My training</h2></div><a class="btn btn-sm" href="{{ route('training.my') }}">Open</a></div>
                <div class="list">
                    @foreach($myTraining as $t)<div class="list-row"><div><strong>{{ $t->course?->title }}</strong><small>{{ $t->due_on ? 'Due '.$t->due_on->format('d M') : 'No due date' }}</small></div><x-badge :status="$t->displayStatus()" /></div>@endforeach
                </div>
            </section>
        @endif
        <section class="card">
            <div class="card-head"><div><h2>Attendance today</h2><p>{{ $metrics['completed'] }} completed · {{ $metrics['late'] }} late</p></div>@if($canTeamAttendance)<a class="btn btn-sm" href="{{ route('attendance.dashboard') }}">View all</a>@endif</div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Employee</th><th>Check in</th><th>Check out</th><th>Worked</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($recentAttendance as $session)
                    <tr><td><strong>{{ $session->employee?->name }}</strong><small>{{ $session->employee?->employee_code }}</small></td><td>{{ $session->check_in?->format('h:i A') ?? '—' }}</td><td>{{ $session->check_out?->format('h:i A') ?? '—' }}</td><td>@hms(app(\App\Services\AttendanceService::class)->workedSeconds($session))</td><td><x-badge :status="$session->status" /></td></tr>
                @empty
                    <tr><td colspan="5"><x-empty title="No attendance yet today" icon="clock" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>

        @if($pendingApprovals->isNotEmpty() || $metrics['pending_approvals'] !== null)
            <section class="card">
                <div class="card-head"><div><h2>Needs your approval</h2><p>Oldest requests first in the approval center</p></div><a class="btn btn-sm" href="{{ route('approvals.pending') }}">Open</a></div>
                <div class="list">
                    @forelse($pendingApprovals as $approval)
                        <div class="list-row"><div><strong>{{ $inbox->typeLabel($approval->approvable_type) }}</strong><small>{{ $inbox->summary($approval) }}</small></div><x-badge status="pending" /></div>
                    @empty
                        <x-empty title="You're all caught up" icon="check-circle" />
                    @endforelse
                </div>
            </section>
        @endif

        @if($profile)
            <section class="card">
                <div class="card-head"><div><h2>My open tasks</h2><p>Sorted by due date</p></div><a class="btn btn-sm" href="{{ route('work.my-tasks') }}">My tasks</a></div>
                <div class="list">
                    @forelse($myTasks as $task)
                        <div class="list-row"><div><strong>{{ $task->title }}</strong><small>@if($task->due_on)Due {{ $task->due_on->format('d M') }}@else No due date @endif</small></div>@if($task->isOverdue())<x-badge status="overdue" />@else<x-badge :status="$task->status" />@endif</div>
                    @empty
                        <x-empty title="No open tasks" icon="check-square" />
                    @endforelse
                </div>
            </section>
        @endif

        <section class="card">
            <div class="card-head"><div><h2>Overdue tasks</h2><p>Open work past its due date</p></div></div>
            <div class="list">
                @forelse($overdueTasks as $task)
                    <div class="list-row"><div><strong>{{ $task->title }}</strong><small>{{ $task->assignee?->name }} · due {{ $task->due_on->format('d M') }}</small></div><x-badge status="overdue">{{ $task->due_on->diffInDays(today()) }}d late</x-badge></div>
                @empty
                    <x-empty title="Nothing overdue" icon="check-circle" />
                @endforelse
            </div>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>Recent daily work status</h2></div><a class="btn btn-sm" href="{{ route('work.daily-status') }}">Open</a></div>
            <div class="list">
                @forelse($recentStatuses as $status)
                    <div class="list-row"><div><strong>{{ $status->employee?->name }}</strong><small>{{ $status->work_date->format('d M Y') }}</small></div><span class="small muted" style="max-width:55%;text-align:right">{{ \Illuminate\Support\Str::limit($status->summary, 90) }}</span></div>
                @empty
                    <x-empty title="No updates submitted" icon="edit" />
                @endforelse
            </div>
        </section>

        @can('audit.view')
            <section class="card">
                <div class="card-head"><div><h2>Recent audit events</h2></div><a class="btn btn-sm" href="{{ route('security.audit') }}">Audit logs</a></div>
                <div class="list">
                    @forelse($recentAudit as $event)
                        <div class="list-row"><div><strong>{{ str($event->action)->replace(['.', '_'], ' ')->title() }}</strong><small>{{ $event->actor?->name ?? 'System' }}@if($summary = $event->summary()) · {{ $summary }}@endif · {{ $event->created_at?->diffForHumans() }}</small></div><span class="small muted">{{ class_basename($event->subject_type ?? '') }} {{ $event->subject_id ? '#'.$event->subject_id : '' }}</span></div>
                    @empty
                        <x-empty title="No audit events yet" icon="eye" />
                    @endforelse
                </div>
            </section>
        @endcan
    </div>

    @if($details)
        <x-modal :title="$details['title'].' ('.count($details['rows']).')'" size="lg">
            <div class="modal-body">
                <div class="table-wrap"><table class="table">
                    <thead><tr>@foreach($details['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                    @forelse($details['rows'] as $i => $row)
                        <tr wire:key="detail-{{ $i }}">@foreach($row as $c => $cell)<td @class(['nowrap' => $c > 1])>@if($c === 0 && ($link = $details['links'][$i] ?? null))<a href="{{ $link }}"><strong>{{ $cell[0] }}</strong></a>@elseif($c === 0)<strong>{{ $cell[0] }}</strong>@else{{ $cell[0] }}@endif @if(! empty($cell[1]))<small>{{ $cell[1] }}</small>@endif</td>@endforeach</tr>
                    @empty
                        <tr><td colspan="{{ max(1, count($details['headers'])) }}"><x-empty title="Nothing to show" icon="check-circle" /></td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
            <div class="modal-foot">@if(! empty($details['more']))<a class="btn btn-primary" href="{{ $details['more'][0] }}">{{ $details['more'][1] }}</a>@endif<button type="button" class="btn" wire:click="closeModal">Close</button></div>
        </x-modal>
    @endif
</div>
