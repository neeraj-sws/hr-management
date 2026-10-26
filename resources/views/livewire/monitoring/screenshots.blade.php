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
        @if($people->isEmpty())
            <x-empty title="No one to show" icon="monitor">You see the screenshots of your team only, never your own.</x-empty>
        @elseif($shots->isEmpty())
            <x-empty title="No screenshots for {{ $person?->name ?? 'this person' }} on {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}" icon="monitor" />
        @else
            {{-- Click a screenshot to view it large; ← → move, Esc closes. Every view and download is logged. --}}
            <div x-data="{ list: @js($shots->map(fn ($s) => ['src' => route('monitoring.screenshot', $s), 'download' => route('monitoring.screenshot', ['screenshot' => $s, 'download' => 1]), 'time' => $s->captured_at->format('h:i A')])->values()), i: null, step(d) { const n = this.i + d; if (n >= 0 && n < this.list.length) this.i = n; } }"
                 x-on:keydown.window.escape="i = null" x-on:keydown.window.arrow-right="i !== null && step(1)" x-on:keydown.window.arrow-left="i !== null && step(-1)">
                <div class="shot-grid">
                    @foreach($shots as $n => $shot)
                        <figure class="shot" wire:key="shot-{{ $shot->id }}">
                            <button type="button" class="shot-open" x-on:click="i = {{ $n }}" aria-label="View screenshot taken at {{ $shot->captured_at->format('h:i A') }}">
                                <img src="{{ route('monitoring.screenshot', $shot) }}" alt="" loading="lazy" onerror="this.replaceWith(Object.assign(document.createElement('span'), {className: 'shot-missing', textContent: 'Image not available'}))">
                            </button>
                            <figcaption>{{ $shot->captured_at->format('h:i A') }}
                                <span class="shot-actions"><button type="button" class="shot-link" x-on:click="i = {{ $n }}"><x-icon name="eye" class="icon-sm" />View</button><a href="{{ route('monitoring.screenshot', ['screenshot' => $shot, 'download' => 1]) }}" class="shot-link"><x-icon name="download" class="icon-sm" />Download</a></span>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
                <template x-if="i !== null">
                    <div class="tm-viewer" role="dialog" aria-modal="true" aria-label="Screenshot" x-on:click.self="i = null">
                        <div class="tm-viewer-bar">
                            <span x-text="'{{ $person?->name }} · ' + list[i].time + ' · ' + (i + 1) + ' of ' + list.length"></span>
                            <span class="tm-viewer-actions">
                                <button type="button" class="btn btn-sm" x-on:click="step(-1)" :disabled="i === 0" aria-label="Previous screenshot"><x-icon name="chevron-left" class="icon-sm" /></button>
                                <button type="button" class="btn btn-sm" x-on:click="step(1)" :disabled="i === list.length - 1" aria-label="Next screenshot"><x-icon name="chevron-right" class="icon-sm" /></button>
                                <a class="btn btn-sm btn-primary" :href="list[i].download"><x-icon name="download" class="icon-sm" />Download</a>
                                <button type="button" class="btn btn-sm" x-on:click="i = null" aria-label="Close"><x-icon name="x" class="icon-sm" /></button>
                            </span>
                        </div>
                        <img :src="list[i].src" alt="Screenshot">
                    </div>
                </template>
            </div>
        @endif
    </section>
</div>
