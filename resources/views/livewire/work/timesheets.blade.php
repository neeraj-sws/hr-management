<div>
    @php($fmt = fn ($m) => \App\Support\Duration::fromMinutes((int) $m))
    <div class="page-head"><div><h1>Timesheets</h1><p>The project's monthly timesheet the client sees in their portal. Lines come in automatically from the team's task timers; you can add lines, import an old sheet, change the minutes charged and answer the client's objections.</p></div>
        @if($projectModel)<div class="row-actions">
            <button class="btn" wire:click="openSettings"><x-icon name="settings" class="icon-sm" />Rate & settings</button>
            <button class="btn" wire:click="$set('showImport', true)"><x-icon name="upload" class="icon-sm" />Import Excel</button>
            <button class="btn btn-primary" wire:click="openEntry"><x-icon name="plus" class="icon-sm" />Add line</button>
        </div>@endif</div>
    <x-errors />
    @if(session('timesheet_import'))
        @php($r = session('timesheet_import'))
        <div class="alert {{ $r['errors'] ? 'alert-info' : 'alert-success' }}"><strong>Import:</strong> {{ $r['created'] }} lines added, {{ $r['kept'] }} already there @if($r['months']) · months {{ collect($r['months'])->map(fn ($m) => \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->format('M Y'))->join(', ') }}@endif
            @if($r['errors'])<ul>@foreach($r['errors'] as $e)<li class="small">{{ $e }}</li>@endforeach</ul>@endif
            @if($r['notes'])<details><summary class="small">Notes ({{ count($r['notes']) }})</summary><ul>@foreach($r['notes'] as $n)<li class="small">{{ $n }}</li>@endforeach</ul></details>@endif</div>
    @endif

    <section class="card mb">
        <div class="toolbar">
            <label class="field grow"><span>Project</span><select wire:model.live="project">@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}{{ $p->client ? ' — '.$p->client->name : ' (no client)' }}</option>@endforeach</select></label>
            <div class="field"><span>Month</span><div class="row-actions"><button class="btn btn-sm" wire:click="moveMonth(-1)">‹</button><input type="month" wire:model.live="month" style="width:auto"><button class="btn btn-sm" wire:click="moveMonth(1)">›</button></div></div>
        </div>
        @if($projectModel && $summary)
            <div class="pill-row" style="padding:0 18px 14px">
                <span class="pill">Client <b>{{ $projectModel->client?->name ?? '— set in Work → Clients' }}</b></span>
                <span class="pill">Carried in <b>{{ $fmt($summary['opening_minutes']) }}</b></span>
                <span class="pill">Worked <b>{{ $fmt($summary['worked_minutes']) }}</b></span>
                <span class="pill">Billable <b>{{ $fmt($summary['billable_minutes']) }}</b></span>
                <span class="pill">Rate <b>{{ $summary['rate'] ? '₹'.number_format($summary['rate'], 2).'/h' : 'not set' }}</b></span>
                <span class="pill">Amount <b>₹{{ number_format($summary['amount'], 2) }}</b></span>
                @if($summary['open_objections'])<span class="pill" style="color:var(--red)">Open objections <b>{{ $summary['open_objections'] }}</b></span>@endif
                @if($summary['approved_at'])<span class="pill" style="color:var(--green)">Approved by <b>{{ $summary['approved_by'] }}</b> · {{ $summary['approved_at']->format('d M Y') }}</span>@else<span class="pill">Not approved by the client yet</span>@endif
                @if($summary['invoice'])<a class="pill" href="{{ route('billing.invoices.show', $summary['invoice']) }}">Invoice <b>{{ $summary['invoice']->number() }}</b></a>
                @elseif($canInvoice && $summary['billable_minutes'] > 0 && (! $summary['rate'] || ! $projectModel->client?->billing_customer_id))<span class="small muted">To invoice: set the hourly rate (Rate &amp; settings) and the client's billing customer (Work → Clients).</span>
                @elseif($canInvoice && $summary['billable_minutes'] > 0)<button class="btn btn-sm btn-primary" wire:click="createInvoice" wire:confirm="Create an invoice for {{ $fmt($summary['billable_minutes']) }} billable hours at ₹{{ number_format($summary['rate'], 2) }}/h?">Create invoice</button>@endif
            </div>
        @endif
    </section>

    @if($projectModel)
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Developer</th><th>Task</th><th>Time</th><th class="num">Worked</th><th class="num">Billable (min)</th><th>Status</th><th>Client</th><th></th></tr></thead>
            <tbody>
            @forelse($entries as $e)
                @php($open = $e->objections->firstWhere('status', 'open'))
                <tr wire:key="ts-{{ $e->id }}" @if($open) style="background:#fff7ed" @endif>
                    <td class="nowrap">{{ $e->work_date->format('D d M') }}</td>
                    <td>{{ $e->developer() }}<small>{{ ['timer' => 'task timer', 'manual' => 'added', 'import' => 'imported'][$e->source] ?? $e->source }}</small></td>
                    <td style="text-align:left;max-width:380px;white-space:normal">{{ \Illuminate\Support\Str::limit($e->description, 160) }}@if($e->remarks)<small>{{ $e->remarks }}</small>@endif
                        @foreach($e->objections as $o)
                            <div class="small" style="margin-top:4px;padding:4px 8px;border-left:3px solid {{ $o->status === 'open' ? 'var(--amber)' : ($o->status === 'accepted' ? 'var(--green)' : 'var(--line)') }}">
                                <strong>Objection</strong> by {{ $o->raiser?->name }} · {{ $o->created_at->format('d M') }}: “{{ $o->reason }}”@if($o->proposed_minutes !== null) (asks {{ $fmt($o->proposed_minutes) }})@endif
                                @if($o->status === 'open')<button class="btn btn-sm" wire:click="openAnswer({{ $o->id }})">Answer</button>
                                @else<br><span class="muted">{{ ucfirst($o->status) }} by {{ $o->responder?->name }}: {{ $o->response }}</span>@endif
                            </div>
                        @endforeach</td>
                    <td class="nowrap">{{ $e->start_time ? substr($e->start_time, 0, 5) : '' }}{{ $e->end_time ? '–'.substr($e->end_time, 0, 5) : '' }}</td>
                    <td class="num">{{ $fmt($e->minutes) }}</td>
                    <td class="num">@if($summary['approved_at']){{ $e->billable_minutes }}@else<input type="number" min="0" max="1440" value="{{ $e->billable_minutes }}" style="width:76px" x-on:change="$wire.setBillable({{ $e->id }}, $event.target.value)" aria-label="Billable minutes">@endif
                        @if($e->billable_minutes !== $e->minutes)<small class="muted">{{ $e->billable_minutes < $e->minutes ? '−' : '+' }}{{ abs($e->billable_minutes - $e->minutes) }}</small>@endif</td>
                    <td>{{ $e->status ?: '—' }}</td>
                    <td><button class="btn btn-sm" wire:click="toggleVisible({{ $e->id }})" title="Show / hide this line in the client portal">{{ $e->visible_to_client ? 'Shown' : 'Hidden' }}</button></td>
                    <td><div class="row-actions">@unless($summary['approved_at'])<button class="btn btn-sm" wire:click="openEntry({{ $e->id }})">Edit</button><button class="btn btn-sm btn-danger" wire:click="deleteEntry({{ $e->id }})" wire:confirm="Remove this line?">×</button>@endunless</div></td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty title="No timesheet lines this month" icon="clock">Time the team logs on this project's tasks appears here automatically. You can also add a line or import the old Excel sheet.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    @endif

    @if($showEntry)
        <x-modal :title="$entryId ? 'Edit line' : 'Add timesheet line'" size="lg">
            <form wire:submit="saveEntry"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Date" name="entry.work_date"><input type="date" wire:model="entry.work_date"></x-field>
                <x-field label="Developer" name="entry.employee_id"><select wire:model="entry.employee_id"><option value="">— other (type the name) —</option>@foreach($employees as $emp)<option value="{{ $emp->id }}">{{ $emp->name }}</option>@endforeach</select></x-field>
                @if(! $entry['employee_id'])<x-field label="Developer name" name="entry.developer_name"><input wire:model="entry.developer_name"></x-field>@endif
                <x-field label="Task" name="entry.description" full><textarea wire:model="entry.description" rows="3"></textarea></x-field>
                <x-field label="Start" name="entry.start_time"><input type="time" wire:model="entry.start_time"></x-field>
                <x-field label="End" name="entry.end_time"><input type="time" wire:model="entry.end_time"></x-field>
                <x-field label="Worked (minutes)" name="entry.minutes"><input type="number" min="0" wire:model="entry.minutes"></x-field>
                <x-field label="Billable (minutes)" name="entry.billable_minutes" hint="blank = same as worked"><input type="number" min="0" wire:model="entry.billable_minutes"></x-field>
                <x-field label="Status" name="entry.status"><select wire:model="entry.status"><option>Done</option><option>In progress</option><option>Not done</option></select></x-field>
                <x-field label="Remarks" name="entry.remarks"><input wire:model="entry.remarks"></x-field>
                <label class="check"><input type="checkbox" wire:model="entry.visible_to_client"> Show to the client</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save line</button></div></form>
        </x-modal>
    @endif

    @if($showImport)
        <x-modal title="Import old timesheet (Excel)">
            <form wire:submit="import"><div class="modal-body"><x-errors />
                <p class="small muted" style="margin-top:0">One sheet per month (e.g. "Oct 2026", "August- 2026") with Date, Task Details, Start Time, End Time, Hours, In Minutes, the billable-minutes column next to it, Developer, Status, Remarks — like the Source India sheet. An "Old remaining" line sets the minutes carried into the month. Developers are matched to employees by name. Importing the same file again adds nothing twice.</p>
                <input type="file" wire:model="file" accept=".xlsx">
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled" wire:target="import,file">Import</button></div></form>
        </x-modal>
    @endif

    @if($answeringObjection)
        <x-modal title="Answer the client's objection">
            <div class="modal-body"><x-errors />
                <p style="margin-top:0"><strong>{{ $answeringObjection->raiser?->name }}</strong> on {{ $answeringObjection->entry->work_date->format('d M') }} — “{{ $answeringObjection->reason }}”</p>
                <p class="small muted">Line: {{ \Illuminate\Support\Str::limit($answeringObjection->entry->description, 120) }} · worked {{ $fmt($answeringObjection->entry->minutes) }}, billed {{ $fmt($answeringObjection->entry->billable_minutes) }}@if($answeringObjection->proposed_minutes !== null) · client asks {{ $fmt($answeringObjection->proposed_minutes) }}@endif</p>
                <div class="form-grid">
                    <x-field label="Your answer (the client sees it)" name="answer" full><textarea wire:model="answer" rows="3"></textarea></x-field>
                    <x-field label="Billable minutes if you accept" name="answerMinutes"><input type="number" min="0" wire:model="answerMinutes"></x-field>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button type="button" class="btn" wire:click="respond(false)">Keep as is</button><button type="button" class="btn btn-primary" wire:click="respond(true)">Accept & set minutes</button></div>
        </x-modal>
    @endif

    @if($showSettings)
        <x-modal title="Rate & settings · {{ $projectModel?->name }}">
            <form wire:submit="saveSettings"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Hourly rate (₹)" name="rate" hint="used for the invoice"><input type="number" step="0.01" min="0" wire:model="rate"></x-field>
                <x-field label="Minutes carried into {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->format('M Y') }}" name="opening" hint="“Old remaining”"><input type="number" wire:model="opening"></x-field>
                <label class="check" style="grid-column:1/-1"><input type="checkbox" wire:model="showDeveloper"> Show developer names to the client</label>
            </div><p class="small muted">The client, and the billing customer invoices go to, are set in Work → Clients.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
