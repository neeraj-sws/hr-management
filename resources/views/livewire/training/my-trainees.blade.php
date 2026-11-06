<div>
    <div class="page-head"><div><h1>My trainees</h1><p>{{ $isHr ? 'Who each mentor is teaching now, who comes to them next and whom they have taught.' : 'The trainees you teach: the topic going on now, who comes to you next and whom you have taught. Mark a topic done when the trainee has finished it; the next mentor is told.' }}</p></div></div>

    <div class="stats">
        <div class="stat"><span class="stat-icon blue"><x-icon name="users" /></span><div><span class="stat-label">Teaching now</span><span class="stat-value">{{ $counts['now'] }}</span><small>topics going on</small></div></div>
        <div class="stat"><span class="stat-icon amber"><x-icon name="clock" /></span><div><span class="stat-label">Coming next</span><span class="stat-value">{{ $counts['next'] }}</span><small>after their current topic</small></div></div>
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Taught</span><span class="stat-value">{{ $counts['done'] }}</span><small>topics done</small></div></div>
    </div>

    <div class="tabs">
        @foreach(['now' => 'Teaching now', 'next' => 'Coming next', 'done' => 'Done'] as $key => $label)
            <button class="tab {{ $show === $key ? 'active' : '' }}" wire:click="$set('show','{{ $key }}')">{{ $label }} ({{ $counts[$key] }})</button>
        @endforeach
    </div>

    <section class="card">
        @if($isHr)
            <div class="toolbar"><label class="field"><span>Mentor</span><select wire:model.live="mentor"><option value="">All mentors</option>@foreach($mentors as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></label></div>
        @endif
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Trainee</th><th>Course</th><th>Topic</th>@if($isHr && $mentor === '')<th>Mentor</th>@endif<th>{{ $show === 'done' ? 'Done on' : ($show === 'next' ? 'Now on' : 'Since') }}</th><th>Course progress</th><th></th></tr></thead>
            <tbody>
            @forelse($rows as $t)
                @php($e = $t->enrollment)
                @php($all = $e->topics)
                @php($current = $all->firstWhere('status', 'active'))
                @php($after = $all->first(fn ($x) => $x->sort > $t->sort))
                <tr wire:key="mt-{{ $t->id }}">
                    <td><strong>{{ $e->employee?->name }}</strong><small>{{ $e->employee?->employee_code }}</small></td>
                    <td>{{ $e->course_name }}<small>@if($e->starts_on){{ $e->starts_on->format('d M Y') }}@endif @if($e->ends_on)– {{ $e->ends_on->format('d M Y') }}@endif</small></td>
                    <td><strong>{{ $t->title }}</strong>@if($show !== 'done' && $after)<small>then {{ $after->title }}{{ $after->mentor ? ' · '.$after->mentor->name : '' }}</small>@endif</td>
                    @if($isHr && $mentor === '')<td>{{ $t->mentor?->name }}</td>@endif
                    <td class="nowrap">
                        @if($show === 'done'){{ $t->completed_on?->format('d M Y') ?? '—' }}
                        @elseif($show === 'next'){{ $current ? $current->title.($current->mentor ? ' · '.$current->mentor->name : '') : '—' }}
                        @else{{ $t->started_on?->format('d M Y') ?? '—' }}@if($t->started_on)<small>{{ $t->started_on->isToday() ? 'today' : $t->started_on->diffForHumans(today(), ['parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</small>@endif
                        @endif
                    </td>
                    <td style="min-width:130px">@php($doneCount = $all->where('status', 'completed')->count())<div class="progress"><span style="width:{{ $all->count() ? (int) round($doneCount * 100 / $all->count()) : 0 }}%"></span></div><small>{{ $doneCount }} of {{ $all->count() }} topics done</small></td>
                    <td><div class="row-actions">
                        @if($t->status === 'active' && ($isHr || (int) $t->mentor_id === (int) $ownId))
                            <button class="btn btn-sm btn-primary" wire:click="markDone({{ $t->id }})" wire:confirm="Mark {{ $t->title }} done for {{ $e->employee?->name }}?{{ $after ? ' They move on to '.$after->title.($after->mentor ? ' with '.$after->mentor->name : '').'.' : ' This is the last topic of the course.' }}">Mark done</button>
                        @endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty :title="['now' => 'No trainee with you right now', 'next' => 'No one is coming to you next', 'done' => 'No topic done yet'][$show] ?? 'Nothing here'" icon="users">Trainees come here when HR enrols them in a course (Training › Course fees) and picks you as the mentor of a topic.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</div>
