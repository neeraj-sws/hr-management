<div class="ar-page" x-data="{ importOpen: @js((bool) session('import_result')) }">
    <div class="page-head">
        <div><h1>Attendance Records</h1><p>View and manage attendance logs, working hours, idle time, screen time and activity details.</p></div>
        <div class="actions"><button type="button" class="btn" x-on:click="importOpen = ! importOpen" x-bind:aria-expanded="importOpen"><x-icon name="upload" class="icon-sm" />Import</button></div>
    </div>

    {{-- Today at a glance (the Department filter applies), compared with yesterday. --}}
    <div class="ar-cards">
        <div class="ar-card ar-blue">
            <span class="ar-card-icon"><x-icon name="users" /></span>
            <div class="ar-card-body"><span class="ar-card-label">Present Today</span><strong class="ar-card-value">{{ $summary['present'] }}</strong><small>of {{ $summary['employees'] }} employees</small></div>
            @if($summary['present_change'])<span class="ar-trend {{ $summary['present_change'] > 0 ? 'up' : 'down' }}" title="Compared with yesterday">{{ $summary['present_change'] > 0 ? '↑' : '↓' }} {{ abs($summary['present_change']) }}</span>@endif
        </div>
        <div class="ar-card ar-amber">
            <span class="ar-card-icon"><x-icon name="clock" /></span>
            <div class="ar-card-body"><span class="ar-card-label">Late Arrivals</span><strong class="ar-card-value">{{ $summary['late'] }}</strong><small>of {{ $summary['employees'] }} employees</small></div>
            @if($summary['late_change'])<span class="ar-trend {{ $summary['late_change'] > 0 ? 'bad' : 'up' }}" title="Compared with yesterday">{{ $summary['late_change'] > 0 ? '↑' : '↓' }} {{ abs($summary['late_change']) }}</span>@endif
        </div>
        <div class="ar-card ar-green">
            <span class="ar-card-icon"><x-icon name="calendar" /></span>
            <div class="ar-card-body"><span class="ar-card-label">Total Worked</span><strong class="ar-card-value">{{ \App\Support\Duration::hm($summary['worked']) }}</strong><small>today (all employees)</small></div>
            @if($summary['worked_change'])<span class="ar-trend {{ $summary['worked_change'] > 0 ? 'up' : 'down' }}" title="Compared with yesterday">{{ $summary['worked_change'] > 0 ? '↑' : '↓' }} {{ abs($summary['worked_change']) }}%</span>@endif
        </div>
        <div class="ar-card ar-violet">
            <span class="ar-card-icon"><x-icon name="monitor" /></span>
            <div class="ar-card-body"><span class="ar-card-label">Working Now</span><strong class="ar-card-value">{{ $summary['working'] }}</strong><small>employees online @if($summary['on_break'])· {{ $summary['on_break'] }} on break @endif</small></div>
            @if($summary['working'])<span class="ar-live" title="Checked in and working right now"></span>@endif
        </div>
    </div>

    <section class="card mb" x-show="importOpen" x-cloak>
        <div class="card-head"><div><h2>Import attendance</h2><p>Add past attendance from Excel (.xlsx) or CSV: one row per employee per day.</p></div>
            <button type="button" class="btn btn-sm btn-ghost" x-on:click="importOpen = false" aria-label="Close import"><x-icon name="x" class="icon-sm" /></button></div>
        @include('partials.import-form', ['type' => 'attendance', 'kind' => 'Attendance', 'action' => route('attendance.records.import')])
    </section>

    {{-- Filters: applied with Search (or Enter); Reset goes back to this month, everyone. --}}
    <form class="card ar-filters" wire:submit="search">
        <label class="field"><span>From</span><input type="date" wire:model="from"></label>
        <label class="field"><span>To</span><input type="date" wire:model="to" x-bind:min="$wire.from"></label>
        <label class="field ar-grow"><span>Employee</span><select wire:model="employee"><option value="">All employees</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach</select></label>
        <label class="field ar-grow"><span>Department</span><select wire:model="department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
        <label class="field"><span>Session</span><select wire:model="status"><option value="">Any</option><option value="working">Working</option><option value="on_break">On break</option><option value="completed">Completed</option></select></label>
        <div class="ar-filter-actions">
            <button type="submit" class="btn btn-primary"><span wire:loading wire:target="search" class="spinner"></span><x-icon name="search" class="icon-sm" wire:loading.remove wire:target="search" />Search</button>
            <button type="button" class="btn" wire:click="resetFilters"><x-icon name="refresh" class="icon-sm" />Reset</button>
        </div>
    </form>

    @php($cols = ['in' => 'In', 'out' => 'Out', 'worked' => 'Worked', 'idle' => 'Idle', 'break' => 'Break', 'screen' => 'Screen time', 'late' => 'Late by', 'source' => 'Source', 'status' => 'Status'])
    <section class="card ar-table-card"
        x-data="{ hidden: (() => { try { return JSON.parse(localStorage.getItem('sws.ar.hiddenCols') || '[]') } catch (e) { return [] } })(), menu: false,
                  toggle(k) { this.hidden = this.hidden.includes(k) ? this.hidden.filter(x => x !== k) : [...this.hidden, k]; try { localStorage.setItem('sws.ar.hiddenCols', JSON.stringify(this.hidden)) } catch (e) {} } }"
        x-bind:class="hidden.map(k => 'ar-hide-' + k)">
        <div class="card-head ar-table-head">
            <div><h2>Attendance Records <span class="ar-range">({{ $from === $to ? \Illuminate\Support\Carbon::parse($from)->format('d M Y') : \Illuminate\Support\Carbon::parse($from)->format('d M').' – '.\Illuminate\Support\Carbon::parse($to)->format('d M Y') }})</span></h2>
                @if($dayEmployee)<p>{{ $dayEmployee->name }} · every day of the range, leave and weekly offs included</p>@endif</div>
            <div class="ar-head-actions">
                @if($dayEmployee)
                    <div class="seg" role="group" aria-label="View"><button type="button" @class(['btn btn-sm', 'btn-primary' => $view !== 'calendar']) wire:click="$set('view', 'list')">List</button><button type="button" @class(['btn btn-sm', 'btn-primary' => $view === 'calendar']) wire:click="$set('view', 'calendar')">Calendar</button></div>
                @endif
                <a class="btn btn-sm" href="{{ route('hr.reports.export', ['type' => 'attendance', 'from' => $from, 'to' => $to, 'employee_id' => $employee, 'department_id' => $department]) }}"><x-icon name="download" class="icon-sm" />Export</a>
                <div class="ar-colmenu" x-on:click.outside="menu = false">
                    <button type="button" class="btn btn-sm" x-on:click="menu = ! menu" x-bind:aria-expanded="menu"><x-icon name="settings" class="icon-sm" />Columns</button>
                    <div class="ar-colmenu-pop" x-show="menu" x-cloak x-transition.opacity>
                        <p>Show columns</p>
                        @foreach($cols as $key => $label)
                            <label class="check"><input type="checkbox" x-bind:checked="! hidden.includes('{{ $key }}')" x-on:change="toggle('{{ $key }}')"> {{ $label }}</label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        <x-errors />
        @if($dayEmployee && $view === 'calendar')
            <p class="small muted" style="padding:0 18px">{{ $dayEmployee->name }} · {{ $calendarMonth->format('F Y') }} (the month of “From”)</p>
            <x-attendance-calendar :days="$calendarDays" :month="$calendarMonth" />
        @else
        <div class="table-wrap" wire:loading.class="ar-loading" wire:target="search,resetFilters,gotoPage,nextPage,previousPage,perPage"><table class="table ar-table">
            <thead><tr><th>Date</th><th>Employee</th>
                <th data-col="in">In</th><th data-col="out">Out</th>
                <th data-col="worked" class="num" title="Worked: in–out time less break and idle · at work: in–out less break only (decides a half day)">Worked</th>
                <th data-col="idle" class="num">Idle</th><th data-col="break" class="num">Break</th>
                <th data-col="screen" class="ar-c">Screen time</th><th data-col="late" class="num">Late by</th>
                <th data-col="source">Source</th><th data-col="status">Status</th><th class="ar-act"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            @if($dayRows)
                {{-- One employee: every day of the range, incl. leave, weekly off / 3rd Saturday and absent days. --}}
                @forelse($dayRows as $day)
                    @if($day['session'])
                        @include('livewire.attendance.partials.record-row', ['s' => $day['session']])
                    @else
                        @php($date = \Illuminate\Support\Carbon::parse($day['date']))
                        <tr class="day-row day-{{ $day['status'] }}"><td class="nowrap ar-date" data-label="Date"><strong>{{ $date->format('d M Y') }}</strong><small>{{ $date->format('D') }}</small></td>
                            <td data-label="Employee"><div class="ar-person"><x-avatar :employee="$dayEmployee" /><div><strong>{{ $dayEmployee?->name }}</strong><small>{{ $dayEmployee?->department?->name }}</small></div></div></td>
                            <td colspan="8" class="small muted ar-dayinfo" style="text-align:left">{{ ['on_leave' => 'On leave', 'weekend' => 'Weekly off', 'holiday' => 'Holiday', 'absent' => 'No attendance', 'upcoming' => 'Upcoming', 'not_joined' => 'Before joining'][$day['status']] ?? '' }}@if($day['label']) · <strong>{{ $day['label'] }}</strong>@endif</td>
                            <td data-col="status" data-label="Status">@if($day['status'] === 'weekend')<x-badge tone="violet">{{ $day['label'] ?: 'Weekly off' }}</x-badge>@elseif($day['status'] === 'on_leave')<x-badge status="on_leave">{{ $day['label'] ?: 'On leave' }}</x-badge>@else<x-badge :status="$day['status']" />@endif</td>
                            <td class="ar-act"></td></tr>
                    @endif
                @empty
                    <tr><td colspan="12"><x-empty title="No days in this range" icon="calendar" /></td></tr>
                @endforelse
            @else
            @forelse($sessions as $s)
                @include('livewire.attendance.partials.record-row', ['s' => $s])
            @empty
                <tr><td colspan="12"><x-empty title="No attendance in this range" icon="calendar" /></td></tr>
            @endforelse
            @endif
            </tbody>
        </table></div>
        @if($dayRows)
            <div class="card-foot small muted">{{ collect($dayRows)->where('session', '!=', null)->count() }} present · {{ collect($dayRows)->where('status', 'on_leave')->count() }} on leave · {{ collect($dayRows)->where('status', 'weekend')->count() + collect($dayRows)->where('status', 'holiday')->count() }} off / holiday · {{ collect($dayRows)->where('status', 'absent')->count() }} absent</div>
        @elseif($sessions->total())
            <div class="ar-foot">
                <span class="small muted">Showing {{ $sessions->firstItem() }} to {{ $sessions->lastItem() }} of {{ $sessions->total() }} records</span>
                <div class="ar-pager">
                    <button type="button" class="ar-pg" wire:click="previousPage" @disabled($sessions->onFirstPage()) aria-label="Previous page"><x-icon name="chevron-left" class="icon-sm" /></button>
                    @php($cur = $sessions->currentPage())
                    @php($last = $sessions->lastPage())
                    @foreach(collect([1, $cur - 1, $cur, $cur + 1, $last])->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values() as $i => $p)
                        @if($i > 0 && $p - collect([1, $cur - 1, $cur, $cur + 1, $last])->filter(fn ($q) => $q >= 1 && $q <= $last)->unique()->sort()->values()[$i - 1] > 1)<span class="ar-pg-gap">…</span>@endif
                        <button type="button" @class(['ar-pg', 'on' => $p === $cur]) wire:click="gotoPage({{ $p }})" @if($p === $cur) aria-current="page" @endif>{{ $p }}</button>
                    @endforeach
                    <button type="button" class="ar-pg" wire:click="nextPage" @disabled(! $sessions->hasMorePages()) aria-label="Next page"><x-icon name="chevron-right" class="icon-sm" /></button>
                    <select class="ar-perpage" wire:model.live="perPage" aria-label="Rows per page">@foreach(\App\Livewire\Attendance\Records::PER_PAGE as $n)<option value="{{ $n }}">{{ $n }} per page</option>@endforeach</select>
                </div>
            </div>
        @endif
        @endif
    </section>

    {{-- View details: everything about one day's attendance, incl. the full screen-time activity. --}}
    @if($detailSession)
        @php($d = $detailSession)
        @php($f = $attendance->liveFigures($d))
        <div class="ds-drawer-backdrop" wire:click.self="closeDetail" wire:keydown.escape.window="closeDetail">
            <aside class="ds-drawer ar-drawer" role="dialog" aria-modal="true" aria-label="Attendance of {{ $d->employee?->name }}" x-data="swsLive(@js($f))">
                <header class="ds-drawer-head">
                    <div class="ds-drawer-who">
                        <x-avatar :employee="$d->employee" size="lg" />
                        <div><h2>{{ $d->employee?->name }}</h2><p>{{ collect([$d->employee?->designation, $d->employee?->department?->name, $d->employee?->employee_code])->filter()->join(' · ') }}</p>
                            <p class="ar-drawer-date">{{ $d->work_date->format('l, d M Y') }} · <x-badge :status="$attendance->isHalfDay($d) ? 'half_day' : ($attendance->isLate($d) ? 'late' : $d->status)" /></p></div>
                    </div>
                    <div class="ds-drawer-nav"><button type="button" class="btn btn-sm" wire:click="closeDetail" aria-label="Close"><x-icon name="x" class="icon-sm" /></button></div>
                </header>
                <div class="ar-drawer-body">
                    <div class="ar-tiles">
                        <div><span>Worked</span><strong x-text="hms(v.worked)">@hms($f['worked'])</strong>@if($d->check_out)<small>at work @hms($attendance->officeSeconds($d))</small>@endif</div>
                        <div><span>Idle</span><strong x-text="hms(v.idle)">@hms($f['idle'])</strong>@if(! ($f['idle_deducted'] ?? true))<small>field / mobile: not taken off</small>@endif</div>
                        <div><span>Break</span><strong x-text="hms(v.brk)">@hms($f['break'])</strong></div>
                        <div><span>Late by</span><strong @class(['ar-red' => $attendance->lateMinutes($d)])>{{ $attendance->lateMinutes($d) ? \App\Support\Duration::fromMinutes($attendance->lateMinutes($d)) : '—' }}</strong></div>
                    </div>

                    <section class="ar-box">
                        <h3>Check-in &amp; check-out</h3>
                        <dl class="kv">
                            <dt>Check-in</dt><dd><strong>{{ $d->check_in?->format('h:i A') ?? '—' }}</strong>@if($d->check_in_photo_path) · <a href="{{ route('attendance.photo', [$d, 'in']) }}" target="_blank" rel="noopener">selfie</a>@endif
                                @if($d->check_in_latitude !== null)<small>{{ $d->checkInOffice ? 'at '.$d->checkInOffice->name : ($d->check_in_distance_meters !== null ? number_format($d->check_in_distance_meters).' m from office' : 'location shared') }} ({{ $d->check_in_latitude }}, {{ $d->check_in_longitude }})</small>@endif
                                @if($d->check_in_ip)<small class="muted">IP {{ $d->check_in_ip }}</small>@endif</dd>
                            <dt>Check-out</dt><dd><strong>{{ $d->check_out?->format('h:i A') ?? '—' }}</strong>@if($d->check_out_photo_path) · <a href="{{ route('attendance.photo', [$d, 'out']) }}" target="_blank" rel="noopener">selfie</a>@endif
                                @if($d->autoCheckedOut())<small>auto check-out (left the computer after the shift)</small>@endif
                                @if($d->check_out_latitude !== null)<small>{{ $d->checkOutOffice ? 'at '.$d->checkOutOffice->name : ($d->check_out_distance_meters !== null ? number_format($d->check_out_distance_meters).' m from office' : 'location shared') }} ({{ $d->check_out_latitude }}, {{ $d->check_out_longitude }})</small>@endif
                                @if($d->check_out_ip)<small class="muted">IP {{ $d->check_out_ip }}</small>@endif</dd>
                            <dt>In–out</dt><dd x-text="hms(v.inOut)">@hms($f['in_out'])</dd>
                            <dt>Source</dt><dd>{{ $d->device?->computer_name ? 'Desktop · '.$d->device->computer_name : 'Web / mobile' }}</dd>
                            <dt>Session</dt><dd>{{ str_replace('_', ' ', ucfirst($d->status)) }}</dd>
                        </dl>
                    </section>

                    <section class="ar-box">
                        <h3>Screen time (desktop app)</h3>
                        <div class="ar-screen-full">@include('livewire.attendance.partials.screen-time', ['s' => $d, 'f' => $f])</div>
                    </section>

                    @php($labels = ['check_in' => 'Checked in', 'check_out' => 'Checked out', 'auto_check_out' => 'Checked out automatically', 'break_start' => 'Break started', 'break_end' => 'Back from break', 'away' => 'Away from the computer', 'away_worked' => 'Away, but working', 'idle' => 'Idle', 'meeting' => 'In a meeting', 'correction' => 'Attendance corrected'])
                    @php($events = $d->events->whereIn('event_type', array_keys($labels))->values())
                    <section class="ar-box">
                        <h3>Activity ({{ $events->count() }})</h3>
                        @if($events->isEmpty())
                            <p class="small muted">No activity recorded for this day.</p>
                        @else
                            <ol class="ar-timeline">
                                @foreach($events->take(80) as $ev)
                                    <li class="ar-ev ar-ev-{{ $ev->event_type }}"><time>{{ $ev->event_time?->format('h:i A') }}</time>
                                        <div><strong>{{ $labels[$ev->event_type] }}</strong>@if($ev->duration_seconds)<small>@hms($ev->duration_seconds)</small>@endif @if($ev->metadata['note'] ?? null)<small>{{ $ev->metadata['note'] }}</small>@endif @if($ev->metadata['reason'] ?? null)<small>{{ $ev->metadata['reason'] }}</small>@endif</div></li>
                                @endforeach
                            </ol>
                            @if($events->count() > 80)<p class="small muted">Showing the first 80 of {{ $events->count() }}.</p>@endif
                        @endif
                    </section>

                    <div class="ar-drawer-links">
                        @if($d->employee)<a class="btn btn-sm" href="{{ route('people.employees.show', $d->employee) }}"><x-icon name="user" class="icon-sm" />Employee profile</a>
                        <a class="btn btn-sm" href="{{ route('attendance.records', ['employee' => $d->employee_id, 'from' => $d->work_date->copy()->startOfMonth()->toDateString(), 'to' => $d->work_date->copy()->endOfMonth()->min(today())->toDateString()]) }}"><x-icon name="calendar" class="icon-sm" />Whole month</a>@endif
                    </div>
                </div>
            </aside>
        </div>
    @endif
</div>
