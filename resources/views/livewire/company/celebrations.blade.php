<div>
    <div class="page-head">
        <div><h1>Celebrations</h1><p>Birthdays and work anniversaries in the next 30 days. Everyone gets a reminder on the day.</p></div>
        @if($own)<a class="btn btn-sm" href="{{ route('account.profile') }}">My birthday settings</a>@endif
    </div>
    <section class="card">
        <div class="list">
            @forelse($events as $event)
                <div class="list-row">
                    <div style="display:flex;gap:12px;align-items:center">
                        <span class="avatar">{{ mb_strtoupper(mb_substr($event['employee']->name, 0, 1)) }}</span>
                        <div><strong>{{ $event['employee']->name }}</strong>
                            <small>{{ $event['type'] === 'birthday' ? 'Birthday' : $event['years'].' '.str('year')->plural($event['years']).' at the company' }} · {{ $event['date']->format('D, d M') }}</small></div>
                    </div>
                    @if($event['today'])<x-badge tone="green">Today</x-badge>@else<x-badge :status="$event['type']" />@endif
                </div>
            @empty
                <x-empty title="No birthdays or anniversaries in the next 30 days" icon="star" />
            @endforelse
        </div>
    </section>
</div>
