<div class="hol-page">
    <div class="page-head">
        <div><h1>Holidays {{ $year }}</h1><p>Working days: {{ $pattern }}. Weekly off: {{ $weeklyOff->join(', ') ?: 'none' }}. These days are not counted as absences or deducted from leave.</p></div>
        <div class="actions">
            <div class="hol-year" role="group" aria-label="Year">
                <button type="button" class="btn btn-icon" wire:click="$set('year', {{ $year - 1 }})" aria-label="{{ $year - 1 }}"><x-icon name="chevron-left" class="icon-sm" /></button>
                <strong>{{ $year }}</strong>
                <button type="button" class="btn btn-icon" wire:click="$set('year', {{ $year + 1 }})" aria-label="{{ $year + 1 }}"><x-icon name="chevron-right" class="icon-sm" /></button>
            </div>
            @if($canEdit)<a class="btn btn-primary" href="{{ route('system.settings', ['tab' => 'calendar']) }}"><x-icon name="edit" class="icon-sm" />Edit holidays</a>@endif
        </div>
    </div>

    @php($upcoming = $holidays->filter(fn ($h) => $h['date']->gte(today())))
    <div class="hol-top">
        @if($next)
            @php($days = (int) today()->diffInDays($next['date']))
            <section class="hol-next">
                <div class="hol-next-date"><span>{{ $next['date']->format('M') }}</span><strong>{{ $next['date']->format('d') }}</strong><small>{{ $next['date']->format('D') }}</small></div>
                <div class="hol-next-text"><span class="eyebrow">Next holiday</span><h2>{{ $next['name'] }}</h2><p>{{ $next['date']->format('l, d F Y') }}@if(! empty($next['closes_at'])) · half day, office closes {{ \Illuminate\Support\Carbon::parse($next['closes_at'])->format('g:i A') }}@endif</p></div>
                <div class="hol-count"><strong>{{ $next['date']->isToday() ? 'Today' : $days }}</strong>@unless($next['date']->isToday())<span>{{ str('day')->plural($days) }} to go</span>@endunless</div>
            </section>
        @endif
        <div class="hol-tiles">
            <div class="hol-tile"><span>Holidays in {{ $year }}</span><strong>{{ $holidays->count() }}</strong></div>
            <div class="hol-tile"><span>Still to come</span><strong>{{ $upcoming->count() }}</strong></div>
            <div class="hol-tile"><span>Optional</span><strong>{{ $holidays->where('category', 'optional')->count() }}</strong></div>
        </div>
    </div>

    <div class="hol-grid">
        <section class="hol-card">
            <div class="hol-card-head"><h2>All holidays</h2><span class="muted small">{{ $holidays->count() }} in {{ $year }}</span></div>
            @forelse($holidays->groupBy(fn ($h) => $h['date']->format('F')) as $month => $list)
                <h3 class="hol-month">{{ $month }}</h3>
                <ul class="hol-list">
                    @foreach($list as $h)
                        <li @class(['hol-item', 'is-past' => $h['date']->lt(today()), 'is-today' => $h['date']->isToday()])>
                            <div class="hol-date"><strong>{{ $h['date']->format('d') }}</strong><span>{{ $h['date']->format('D') }}</span></div>
                            <div class="hol-info"><strong>{{ $h['name'] }}</strong><small>{{ $h['date']->format('l, d M Y') }}@if($h['category'] === 'optional') · working day — apply for leave to take it @endif</small></div>
                            <div class="hol-tags">
                                <span @class(['hol-tag', 'hol-tag-opt' => $h['category'] === 'optional'])>{{ $h['category_label'] }}</span>
                                <span class="hol-tag hol-tag-pay">{{ $h['paid'] ? 'Paid' : 'Unpaid' }}</span>
                                @if(! empty($h['closes_at']))<span class="hol-tag hol-tag-half" title="The office is open until then: coming in counts as a full day">Half day · closes {{ \Illuminate\Support\Carbon::parse($h['closes_at'])->format('g:i A') }}</span>@endif
                                @if($h['date']->isToday())<span class="hol-tag hol-tag-today">Today</span>@elseif($h['date']->lt(today()))<span class="hol-past">past</span>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @empty
                <x-empty title="No holidays set for {{ $year }}" icon="calendar">@if($canEdit)Add them under Settings → Calendar.@endif</x-empty>
            @endforelse
        </section>

        <aside class="hol-side">
            <section class="hol-card">
                <div class="hol-card-head"><h2>Upcoming weekly offs</h2></div>
                <p class="small muted hol-pad">Days that are off although they are normally working days (e.g. the 3rd Saturday). You get a reminder the evening before.</p>
                @forelse($specialOffs as $off)
                    <div class="hol-off"><div class="hol-date hol-date-sm"><strong>{{ $off['date']->format('d') }}</strong><span>{{ $off['date']->format('M') }}</span></div><div><strong>{{ $off['date']->format('l') }}</strong><small>{{ $off['reason'] }}</small></div></div>
                @empty
                    <p class="small muted hol-pad">No extra weekly offs coming up.</p>
                @endforelse
            </section>
            @if($shifts->isNotEmpty())
                <section class="hol-card">
                    <div class="hol-card-head"><h2>Weekly off moved</h2></div>
                    @foreach($shifts as $shift)<p class="small hol-pad"><strong>{{ \Illuminate\Support\Carbon::parse($shift['from'])->format('D d M') }}</strong> is a working day; <strong>{{ \Illuminate\Support\Carbon::parse($shift['to'])->format('D d M Y') }}</strong> is off instead.@if(! empty($shift['note'])) {{ $shift['note'] }}@endif</p>@endforeach
                </section>
            @endif
        </aside>
    </div>
</div>
