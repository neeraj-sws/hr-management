<div>
    <div class="page-head"><div><h1>Search</h1><p>Employees, tasks and projects visible to your role.</p></div></div>
    <div class="card mb"><div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="q" placeholder="Type at least 2 characters" autofocus></label></div></div>
    @if(mb_strlen($term) < 2)
        <div class="card"><x-empty title="Start typing to search" icon="search" /></div>
    @else
        <div class="grid grid-3">
            <section class="card"><div class="card-head"><h2>Employees ({{ $employees->count() }})</h2></div><div class="list">
                @forelse($employees as $employee)
                    <a class="list-row" href="{{ route('people.employees.show', $employee) }}"><div><strong>{{ $employee->name }}</strong><small>{{ $employee->employee_code }} · {{ $employee->designation ?? $employee->department?->name ?? '—' }}</small></div>@unless($employee->is_active)<x-badge status="inactive" />@endunless</a>
                @empty <x-empty title="No employees match" icon="users" /> @endforelse
            </div></section>
            <section class="card"><div class="card-head"><h2>Tasks ({{ $tasks->count() }})</h2></div><div class="list">
                @forelse($tasks as $task)
                    <div class="list-row"><div><strong>{{ $task->title }}</strong><small>{{ $task->assignee?->name }}</small></div><x-badge :status="$task->status" /></div>
                @empty <x-empty title="No tasks match" icon="check-square" /> @endforelse
            </div></section>
            <section class="card"><div class="card-head"><h2>Projects ({{ $projects->count() }})</h2></div><div class="list">
                @forelse($projects as $project)
                    <a class="list-row" href="{{ route('work.projects') }}"><div><strong>{{ $project->name }}</strong></div><x-badge :status="$project->status" /></a>
                @empty <x-empty title="No projects match" icon="folder" /> @endforelse
            </div></section>
        </div>
    @endif
</div>
