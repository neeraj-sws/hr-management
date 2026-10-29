<div>
    <div class="page-head">
        <div><p class="eyebrow">Reports</p><h1>{{ $label }} report</h1><p>The Excel/CSV export uses exactly the same query and filters as this report.</p>
            @if(in_array($type, ['performance', 'productivity'], true))<p class="small muted" style="max-width:760px">Logged time = task-timer time. A day never counts more task time than that day's actual working time (in–out less break and idle), and a timer left running only counts until midnight. <strong>Not counted time</strong> is what was left out for those two reasons — usually a timer someone forgot to stop.</p>@endif</div>
        <div class="actions">
            <a class="btn btn-primary" href="{{ $exportUrlExcel }}"><x-icon name="download" class="icon-sm" />Export Excel</a>
            <a class="btn" href="{{ $exportUrl }}"><x-icon name="download" class="icon-sm" />Export CSV</a>
        </div>
    </div>
    <div class="tabs">@foreach($types as $key => $name)<a class="tab {{ $key === $type ? 'active' : '' }}" href="{{ route('reports.show', $key) }}">{{ $name }}</a>@endforeach</div>
    <section class="card">
        <div class="toolbar">
            {{-- Month by month, or a custom range (From / To). --}}
            <label class="field"><span>Month</span><select wire:model.live="month">
                @for($m = now()->startOfMonth(), $i = 0; $i < 24; $i++, $m->subMonth())<option value="{{ $m->format('Y-m') }}">{{ $m->format('F Y') }}</option>@endfor
                <option value="custom">Custom range…</option>
            </select></label>
            @if($month === 'custom')
                <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
                <label class="field"><span>To</span><input type="date" wire:model.live="to" x-bind:min="$wire.from"></label>
            @else
                <span class="small muted report-range">{{ \Illuminate\Support\Carbon::parse($from)->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</span>
            @endif
            @if($usesEmployees)
                <label class="field"><span>Employee</span><select wire:model.live="employee"><option value="">All in scope</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->employee_code }} · {{ $e->name }}</option>@endforeach</select></label>
                <label class="field"><span>Department</span><select wire:model.live="department"><option value="">All</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
                <label class="field"><span>Team</span><select wire:model.live="team"><option value="">All</option>@foreach($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></label>
                @if($type === 'attendance')<label class="field"><span>Day</span><select wire:model.live="day"><option value="">All days</option>@foreach($dayStatuses as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>@endif
            @endif
            <label class="field grow"><span>Search</span><input type="search" list="report-search-options" autocomplete="off" wire:model.live.debounce.400ms="search" placeholder="{{ ['tasks' => 'Task title', 'audit' => 'Action — type or pick from the list', 'projects' => 'Project name'][$type] ?? 'Employee name or code' }}">
                <datalist id="report-search-options">@foreach($suggestions as $option)<option value="{{ $option }}"></option>@endforeach</datalist></label>
            <button type="button" class="btn" wire:click="resetFilters">Reset</button>
            <span wire:loading class="spinner" style="margin-bottom:10px"></span>
        </div>
        {{-- Sorted on the server, so a heading click orders every page (not only the 25 rows on screen). --}}
        <div class="table-wrap"><table class="table" data-no-sort>
            <thead><tr>@foreach($columns as $column)<th wire:click="sortBy('{{ $column }}')" @class(['sortable', 'sorted-asc' => $sort === $column && $dir !== 'desc', 'sorted-desc' => $sort === $column && $dir === 'desc']) aria-sort="{{ $sort === $column ? ($dir === 'desc' ? 'descending' : 'ascending') : 'none' }}">{{ $headings[$column] ?? str($column)->replace('_', ' ') }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>@foreach($columns as $column)@php($value = $row[$column] ?? null)<td @class(['num' => is_int($value) || is_float($value)]) style="{{ $column === 'metadata' ? 'min-width:340px;max-width:460px;white-space:normal;overflow-wrap:anywhere;text-align:left' : '' }}">@if(in_array($column, ['status', 'day_status', 'session_status', 'run_status', 'payroll_status', 'timeliness'], true) && $value)<x-badge :status="$value" />@elseif($column === 'metadata' && is_array($details = is_string($value) ? json_decode($value, true) : $value))@forelse($details as $k => $v)<div><span class="muted">{{ str($k)->replace('_', ' ')->ucfirst() }}:</span> {{ is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'yes' : 'no') : ($v ?? '—')) }}</div>@empty — @endforelse @else{{ is_float($value) ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') : ($value ?? '—') }}@endif</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($columns) }}"><x-empty title="No records for these filters" icon="chart" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $rows->links() }}
        <div class="card-foot small muted">{{ $rows->total() }} rows @if($truncated)· the screen shows the first {{ \App\Livewire\Reports\Report::PREVIEW_LIMIT }} rows — export for the full data set @endif · durations are HH:MM:SS</div>
    </section>
</div>
