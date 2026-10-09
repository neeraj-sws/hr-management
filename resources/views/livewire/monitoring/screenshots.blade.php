<div>
    <div class="page-head">
        <div><h1>Screenshots</h1><p>
            @if($policy['enabled'])
                Captured by the desktop app every {{ $policy['interval_minutes'] }} min while checked in and working{{ $policy['active_only'] ? ', only when the computer is in use' : '' }}{{ $policy['window_start'] ? ' between '.$policy['window_start'].' and '.$policy['window_end'] : '' }} · never during breaks or after check-out · kept {{ $policy['retention_days'] }} days. Every view and download is recorded in the audit log.
            @else
                Screen capture is turned off. HR can turn it on under Settings → Screen capture.
            @endif
        </p></div>
        <div class="toolbar">
            @if($people->count() > 1)<select class="input" wire:model.live="employee" style="width:auto" aria-label="Employee">@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>@endif
            <input type="date" class="input" wire:model.live="date" max="{{ today()->toDateString() }}" aria-label="Date">
        </div>
    </div>
    <section class="card card-body">
        @if($shots->isEmpty())
            <x-empty title="No screenshots for {{ $person?->name ?? 'this person' }} on {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}" icon="monitor" />
        @else
            <div class="shot-grid">
                @foreach($shots as $shot)
                    <figure class="shot" wire:key="shot-{{ $shot->id }}">
                        <a href="{{ route('monitoring.screenshot', $shot) }}" target="_blank" rel="noopener"><img src="{{ route('monitoring.screenshot', $shot) }}" alt="Screen at {{ $shot->captured_at->format('h:i A') }}" loading="lazy"></a>
                        <figcaption>{{ $shot->captured_at->format('h:i A') }} <a href="{{ route('monitoring.screenshot', ['screenshot' => $shot, 'download' => 1]) }}" class="small">Download</a></figcaption>
                    </figure>
                @endforeach
            </div>
        @endif
    </section>
</div>
