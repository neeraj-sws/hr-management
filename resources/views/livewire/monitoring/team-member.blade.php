<div class="tm-page" @if($isToday) wire:poll.60s @endif>
    <p class="small"><a href="{{ route('monitoring.team', ['date' => $date]) }}"><x-icon name="arrow-left" class="icon-sm" /> Team monitoring</a></p>
    <div class="tm-head">
        <div class="tm-who">
            <x-avatar :employee="$employee" size="lg" />
            <div>
                <h1>{{ $employee->name }} <span class="tm-status" style="--c:{{ $row['status_color'] }}"><i></i>{{ $isToday ? $row['status_label'] : ($row['check_in'] ? 'Worked this day' : 'No attendance') }}</span></h1>
                <p>{{ $employee->employee_code }}@if($employee->department) · {{ $employee->department->name }}@endif @if($employee->manager) · Manager: {{ $employee->manager->name }}@endif</p>
                <x-person-badges :user="$employee->user" :employee="$employee" />
            </div>
        </div>
        <div class="tm-head-actions">
            {{-- Screen capture: HR / Admin switch it; a manager asks HR. --}}
            <div class="tm-capture-box">
                <span>Screen capture</span>
                <strong @class(['tm-capture', 'is-on' => $capture['on']])>{{ $capture['on'] ? 'ON' : 'OFF' }}</strong>
                @if($canToggle)
                    <button type="button" role="switch" aria-checked="{{ $capture['on'] ? 'true' : 'false' }}" @class(['tm-switch', 'is-on' => $capture['on']]) wire:click="openCapture('{{ $capture['on'] ? 'off' : 'on' }}')" aria-label="{{ $capture['on'] ? 'Turn screen capture off' : 'Turn screen capture on' }}"><span></span></button>
                @elseif(! $capture['on'])
                    @if($pendingRequest)<span class="small muted">Request waiting for HR</span>
                    @else<button type="button" class="btn btn-sm" wire:click="openCapture('request')">Request screen capture</button>@endif
                @endif
            </div>
            @if($capture['on'] && $capture['reason'])<p class="small muted tm-capture-why">On since {{ $capture['since'] ? \Illuminate\Support\Carbon::parse($capture['since'])->format('d M Y') : '—' }} · {{ $capture['reason'] }}@if($capture['until']) · until {{ \Illuminate\Support\Carbon::parse($capture['until'])->format('d M Y') }}@endif</p>@endif
        </div>
    </div>

    <div class="tm-datebar">
        <button type="button" class="btn btn-sm" wire:click="stepDay(-1)"><x-icon name="chevron-left" class="icon-sm" />Previous day</button>
        <input type="date" wire:model.live="date" max="{{ today()->toDateString() }}" aria-label="Date">
        <button type="button" class="btn btn-sm" wire:click="stepDay(1)" @disabled($isToday)>Next day<x-icon name="chevron-right" class="icon-sm" /></button>
        <div class="ds-tabs tm-tabs" role="tablist">
            <button type="button" role="tab" @class(['ds-tab', 'is-on' => $tab === 'day']) wire:click="$set('tab', 'day')">Day</button>
            <button type="button" role="tab" @class(['ds-tab', 'is-on' => $tab === 'week']) wire:click="$set('tab', 'week')">Week</button>
        </div>
    </div>

    @if($tab === 'day')
        <div class="tm-summary">
            @foreach([['Check-in', $row['check_in']?->format('h:i A') ?? '—'], ['Check-out', $row['check_out']?->format('h:i A') ?? ($row['check_in'] && $isToday ? 'still in' : '—')], ['Worked', \App\Support\Duration::hms($row['worked'])], ['Break', \App\Support\Duration::hms($row['break'])], ['Idle', \App\Support\Duration::hms($row['idle'])], ['Meeting', \App\Support\Duration::hms($row['meeting'])], ['Screen in use (incl. meetings)', \App\Support\Duration::hms($row['active'] + $row['meeting'])], ['Not tracked', \App\Support\Duration::hms($row['untracked'])]] as [$label, $value])
                <div><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
            @endforeach
        </div>

        <section class="tm-table-card tm-pad">
            <h2>Timeline</h2>
            @include('livewire.monitoring.partials.day-bar', ['timeline' => $row['timeline'], 'scale' => true])
            <div class="tm-legend">@foreach(['working' => 'Working', 'break' => 'Break', 'idle' => 'Idle', 'meeting' => 'Meeting', 'off' => 'Off'] as $key => $label)<span><i class="tm-dot tm-seg-{{ $key }}"></i>{{ $label }}</span>@endforeach</div>
        </section>

        <section class="tm-table-card tm-pad">
            <h2>Screenshots <span class="muted">({{ $shots->flatten()->count() }})</span></h2>
            @if(! $capture['on'] && $shots->isEmpty())
                <x-empty title="Screen capture is off for this employee" icon="eye-off" />
            @elseif($shots->isEmpty())
                <x-empty title="No screenshots on this day" icon="monitor" />
            @else
                {{-- Viewer: every open and download is logged (viewer, employee, screenshot, time). --}}
                <div x-data="{ list: @js($shots->flatten()->map(fn ($s) => ['id' => $s->id, 'src' => route('monitoring.screenshot', $s), 'download' => route('monitoring.screenshot', ['screenshot' => $s, 'download' => 1]), 'time' => $s->captured_at->format('h:i A')])->values()), i: null, open(n) { this.i = n; $wire.logScreenshot(this.list[n].id); }, step(d) { const n = this.i + d; if (n >= 0 && n < this.list.length) this.open(n); } }"
                     x-on:keydown.window.escape="i = null" x-on:keydown.window.arrow-right="i !== null && step(1)" x-on:keydown.window.arrow-left="i !== null && step(-1)">
                    @php($n = 0)
                    @foreach($shots as $hour => $group)
                        <h3 class="tm-hour">{{ \Illuminate\Support\Carbon::parse($date.' '.$hour)->format('h A') }}</h3>
                        <div class="tm-shots">
                            @foreach($group as $shot)
                                <button type="button" class="tm-shot" x-on:click="open({{ $n }})" aria-label="Open screenshot taken at {{ $shot->captured_at->format('h:i A') }}">
                                    <img src="{{ route('monitoring.screenshot', $shot) }}" alt="" loading="lazy"><span>{{ $shot->captured_at->format('h:i A') }}</span>
                                </button>
                                @php($n++)
                            @endforeach
                        </div>
                    @endforeach
                    <template x-if="i !== null">
                        <div class="tm-viewer" role="dialog" aria-modal="true" aria-label="Screenshot" x-on:click.self="i = null">
                            <div class="tm-viewer-bar">
                                <span x-text="'Taken at ' + list[i].time + ' · ' + (i + 1) + ' of ' + list.length"></span>
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
            <p class="small muted">Only taken while checked in and working — never during a break, while idle or locked, or after check-out. Deleted automatically after the retention period. Keystrokes, passwords, webcam, microphone and clipboard are never recorded.</p>
        </section>
    @else
        @php($max = max(1, collect($week)->max(fn ($d) => $d['worked'] + $d['idle'])))
        <section class="tm-table-card tm-pad">
            <h2>Last 7 days</h2>
            <div class="tm-week" role="img" aria-label="Worked and idle time for the last 7 days">
                @foreach($week as $d)
                    <div class="tm-week-day">
                        <div class="tm-week-bars">
                            <span class="tm-week-worked" style="height:{{ round($d['worked'] * 100 / $max) }}%" title="Worked @hms($d['worked'])"></span>
                            <span class="tm-week-idle" style="height:{{ round($d['idle'] * 100 / $max) }}%" title="Idle @hms($d['idle'])"></span>
                        </div>
                        <strong>{{ \App\Support\Duration::hms($d['worked']) }}</strong>
                        <small>{{ $d['label'] }}</small>
                    </div>
                @endforeach
            </div>
            <div class="tm-legend"><span><i class="tm-dot tm-seg-working"></i>Worked</span><span><i class="tm-dot tm-seg-idle"></i>Idle</span></div>
        </section>
    @endif

    @if($captureDialog)
        <x-modal :title="['on' => 'Turn screen capture on', 'off' => 'Turn screen capture off', 'request' => 'Request screen capture'][$captureDialog]" close="closeCapture">
            <form wire:submit="saveCapture">
                <div class="modal-body">
                    @if($captureDialog === 'on')<div class="alert alert-info" style="margin-top:0">The employee will be told in the app and by e-mail before capture starts.</div>@endif
                    @if($captureDialog === 'request')<div class="alert alert-info" style="margin-top:0">HR decides this in Approvals. If approved, {{ $employee->name }} is told in the app and by e-mail before capture starts.</div>@endif
                    @if($captureDialog === 'off')<p class="small muted" style="margin-top:0">{{ $employee->name }} is told that capture is off.</p>@endif
                    <x-field label="Reason" name="captureReason" :hint="$captureDialog === 'off' ? 'optional' : 'required — the employee sees it'" full><textarea wire:model="captureReason" rows="3" maxlength="500"></textarea></x-field>
                    @if($captureDialog !== 'off')<x-field label="End date" name="captureUntil" hint="optional — capture stops after this day" full><input type="date" wire:model="captureUntil" min="{{ today()->toDateString() }}"></x-field>@endif
                </div>
                <div class="modal-foot"><button type="button" class="btn" wire:click="closeCapture">Cancel</button><button class="btn btn-primary">{{ ['on' => 'Turn on and tell them', 'off' => 'Turn off', 'request' => 'Send request'][$captureDialog] }}</button></div>
            </form>
        </x-modal>
    @endif
</div>
