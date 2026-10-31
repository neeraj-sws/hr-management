<div>
    @php($fmt = fn ($m) => \App\Support\Duration::fromMinutes((int) $m))
    <div class="page-head"><div><h1>Timesheet</h1><p>Every line of work on your project. Something not right? Use “Object” on that line — the team answers here. When the month is right, approve it.</p></div></div>
    <x-errors />
    <section class="card mb">
        <div class="toolbar">
            <label class="field grow"><span>Project</span><select wire:model.live="project">@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
            <div class="field"><span>Month</span><div class="row-actions"><button class="btn btn-sm" wire:click="moveMonth(-1)">‹</button><input type="month" wire:model.live="month" style="width:auto"><button class="btn btn-sm" wire:click="moveMonth(1)">›</button></div></div>
        </div>
        @if($summary)
            <div class="pill-row" style="padding:0 18px 14px">
                @if($summary['opening_minutes'])<span class="pill">Carried in <b>{{ $fmt($summary['opening_minutes']) }}</b></span>@endif
                <span class="pill">Billable this month <b>{{ $fmt($summary['billable_minutes']) }}</b></span>
                <span class="pill">Lines <b>{{ $summary['entries'] }}</b></span>
                @if($summary['open_objections'])<span class="pill" style="color:var(--amber)">Open objections <b>{{ $summary['open_objections'] }}</b></span>@endif
                @if($summary['approved_at'])<span class="pill" style="color:var(--green)">Approved by {{ $summary['approved_by'] }} on {{ $summary['approved_at']->format('d M Y') }}</span>
                @elseif($summary['entries'])<button class="btn btn-primary btn-sm" wire:click="approve" wire:confirm="Approve this month's timesheet ({{ $fmt($summary['billable_minutes']) }} billable)?" @disabled($summary['open_objections'])>Approve this month</button>
                    @if($summary['open_objections'])<span class="small muted">You can approve once the open objections are answered.</span>@endif
                @endif
            </div>
        @endif
    </section>
    @if($projectModel)
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th>@if($projectModel->client_sees_developer)<th>Developer</th>@endif<th>Task</th><th>Time</th><th class="num">Billable</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($entries as $e)
                @php($open = $e->objections->firstWhere('status', 'open'))
                <tr wire:key="ce-{{ $e->id }}">
                    <td class="nowrap">{{ $e->work_date->format('D d M') }}</td>
                    @if($projectModel->client_sees_developer)<td>{{ $e->developer() }}</td>@endif
                    <td style="text-align:left;max-width:460px;white-space:normal">{{ $e->description }}@if($e->remarks)<small>{{ $e->remarks }}</small>@endif
                        @foreach($e->objections as $o)
                            <div class="small" style="margin-top:4px;padding:4px 8px;border-left:3px solid {{ $o->status === 'open' ? 'var(--amber)' : ($o->status === 'accepted' ? 'var(--green)' : 'var(--line)') }}">
                                <strong>Your objection</strong> ({{ $o->created_at->format('d M') }}): “{{ $o->reason }}”
                                @if($o->status === 'open')<br><span class="muted">Waiting for the team's answer.</span>
                                @else<br><strong>{{ $o->status === 'accepted' ? 'Accepted' : 'Answer' }}</strong> from {{ $o->responder?->name }}: {{ $o->response }}
                                @endif
                            </div>
                        @endforeach</td>
                    <td class="nowrap">{{ $e->start_time ? substr($e->start_time, 0, 5) : '' }}{{ $e->end_time ? '–'.substr($e->end_time, 0, 5) : '' }}</td>
                    <td class="num">{{ $fmt($e->billable_minutes) }}</td>
                    <td>{{ $e->status ?: '—' }}</td>
                    <td>@if(! $summary['approved_at'] && ! $open)<button class="btn btn-sm" wire:click="openObjection({{ $e->id }})">Object</button>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="Nothing logged this month" icon="clock" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    @endif

    @if($objectingEntry)
        <x-modal title="Object to this line">
            <form wire:submit="object"><div class="modal-body"><x-errors />
                <p style="margin-top:0" class="small">{{ $objectingEntry->work_date->format('d M Y') }} · {{ \Illuminate\Support\Str::limit($objectingEntry->description, 140) }} · billed {{ $fmt($objectingEntry->billable_minutes) }}</p>
                <div class="form-grid">
                    <x-field label="What is wrong?" name="reason" full><textarea wire:model="reason" rows="3" placeholder="e.g. The meeting took 20 minutes, not 45."></textarea></x-field>
                    <x-field label="Should be (minutes)" name="proposed" hint="optional"><input type="number" min="0" wire:model="proposed"></x-field>
                </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('objecting', null)">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Send objection</button></div></form>
        </x-modal>
    @endif
</div>
