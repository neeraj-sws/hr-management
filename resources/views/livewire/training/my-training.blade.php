<div>
    <div class="page-head"><div><h1>My Training</h1><p>Courses assigned to you. Open the material, then mark the course as completed.</p></div></div>
    @if($fees->isNotEmpty())
        <section class="card mb">
            <div class="card-head"><div><h2>My course fees</h2><p>What you have paid for your course and what is still due.</p></div></div>
            @foreach($fees as $e)
                <div class="card-body">
                    <p style="margin-top:0"><strong>{{ $e->course_name }}</strong> · fee {{ number_format($e->payable(), 2) }} · paid {{ number_format($e->paid(), 2) }} · <strong style="color:{{ $e->balance() > 0 ? 'var(--amber)' : 'var(--green)' }}">due {{ number_format($e->balance(), 2) }}</strong> · {{ $e->payments->count() }} of {{ $e->installments }} installments</p>
                    @if($e->payments->isNotEmpty())
                        <div class="table-wrap"><table class="table"><thead><tr><th>Receipt</th><th>Date</th><th>Installment</th><th class="num">Amount</th><th>Paid by</th><th>Reference</th></tr></thead><tbody>
                        @foreach($e->payments as $p)<tr><td>{{ $p->receipt_no }}</td><td>{{ $p->paid_on->format('d M Y') }}</td><td>#{{ $p->installment_no }}</td><td class="num">{{ number_format((float) $p->amount, 2) }}</td><td>{{ \App\Models\TrainingFeePayment::METHODS[$p->method] ?? $p->method }}</td><td class="small">{{ $p->reference ?? '—' }}</td></tr>@endforeach
                        </tbody></table></div>
                    @endif
                </div>
            @endforeach
        </section>
    @endif
    <div class="stats">
        <div class="stat"><span class="stat-icon amber"><x-icon name="list" /></span><div><span class="stat-label">To do</span><span class="stat-value">{{ $open->total() }}</span><small>{{ $overdueCount }} overdue</small></div></div>
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Completed</span><span class="stat-value">{{ $done->total() }}</span></div></div>
    </div>
    <section class="card">
        <div class="card-head"><div><h2>To do</h2></div></div>
        <div class="list">
            @forelse($open as $a)
                <div class="list-row" wire:key="ta-{{ $a->id }}">
                    <div><strong>{{ $a->course?->title }}</strong>@if($a->course?->is_mandatory) <x-badge tone="red">Mandatory</x-badge>@endif
                        <small>{{ collect([$a->course?->category, $a->course?->provider, $a->course?->duration_hours ? (float) $a->course->duration_hours.' h' : null])->filter()->join(' · ') }}@if($a->due_on) · due {{ $a->due_on->format('d M Y') }}@endif</small>
                        @if($a->course?->description)<small>{{ \Illuminate\Support\Str::limit($a->course->description, 160) }}</small>@endif
                    </div>
                    <div class="row-actions">
                        <x-badge :status="$a->displayStatus()" />
                        @if($a->course?->material_url)<a class="btn btn-sm" href="{{ $a->course->material_url }}" target="_blank" rel="noopener noreferrer"><x-icon name="external" class="icon-sm" />Open</a>@endif
                        @if($a->status === 'assigned')<button class="btn btn-sm" wire:click="start({{ $a->id }})">Start</button>@endif
                        <button class="btn btn-sm btn-success" wire:click="openComplete({{ $a->id }})">Mark completed</button>
                    </div>
                </div>
            @empty
                <x-empty title="Nothing to do — you're all caught up" icon="check-circle" />
            @endforelse
            {{ $open->links() }}
        </div>
    </section>
    @if($done->isNotEmpty())
        <section class="card">
            <div class="card-head"><div><h2>Completed</h2></div></div>
            <div class="list">
                @foreach($done as $a)
                    <div class="list-row"><div><strong>{{ $a->course?->title }}</strong><small>Completed {{ $a->completed_at?->format('d M Y') }}{{ $a->completion_note ? ' · '.$a->completion_note : '' }}</small></div><x-badge status="completed" /></div>
                @endforeach
                {{ $done->links() }}
            </div>
        </section>
    @endif

    @if($completing)
        <x-modal title="Complete · {{ $completing->course?->title }}">
            <form wire:submit="complete"><div class="modal-body">
                <x-field label="Note (optional)" name="note"><textarea wire:model="note" placeholder="e.g. Certificate number, key learnings"></textarea></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-success" wire:loading.attr="disabled">Mark completed</button></div></form>
        </x-modal>
    @endif
</div>
