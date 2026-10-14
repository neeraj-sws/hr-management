<div>
    <div class="page-head">
        <div><h1>Holidays {{ $year }}</h1><p>Working days: {{ $pattern }}. Weekly off: {{ $weeklyOff->join(', ') ?: 'none' }}. These days are not counted as absences or deducted from leave.</p></div>
        <div class="actions">
            <button class="btn btn-sm" wire:click="$set('year', {{ $year - 1 }})">‹ {{ $year - 1 }}</button><button class="btn btn-sm" wire:click="$set('year', {{ $year + 1 }})">{{ $year + 1 }} ›</button>
            @if($canEdit)<a class="btn btn-sm btn-primary" href="{{ route('system.settings', ['tab' => 'calendar']) }}">Edit holidays</a>@endif
        </div>
    </div>
    @if($next)
        <section class="card card-body"><span class="eyebrow">Next holiday</span><h2 style="margin:4px 0">{{ $next['name'] }}</h2><p class="muted" style="margin:0">{{ $next['date']->format('l, d F Y') }} · {{ $next['date']->isToday() ? 'today' : 'in '.(int) today()->diffInDays($next['date']).' '.str('day')->plural((int) today()->diffInDays($next['date'])) }}</p></section>
    @endif
    @if($shifts->isNotEmpty())
        <section class="card card-body"><span class="eyebrow">Weekly off moved</span>@foreach($shifts as $shift)<div class="small"><strong>{{ \Illuminate\Support\Carbon::parse($shift['from'])->format('D d M') }}</strong> is a working day; <strong>{{ \Illuminate\Support\Carbon::parse($shift['to'])->format('D d M Y') }}</strong> is off instead.@if(! empty($shift['note'])) {{ $shift['note'] }}@endif</div>@endforeach</section>
    @endif
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Day</th><th>Holiday</th><th></th></tr></thead>
            <tbody>
            @forelse($holidays as $h)
                <tr @class(['muted' => $h['date']->lt(today())])><td class="nowrap">{{ $h['date']->format('d M Y') }}</td><td>{{ $h['date']->format('l') }}</td><td><strong>{{ $h['name'] }}</strong></td><td>@if($h['date']->isToday())<x-badge tone="green">Today</x-badge>@elseif($h['date']->lt(today()))<span class="small muted">past</span>@endif</td></tr>
            @empty
                <tr><td colspan="4"><x-empty title="No holidays set for {{ $year }}" icon="calendar">@if($canEdit)Add them under Settings → Calendar.@endif</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</div>
