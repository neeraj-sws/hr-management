<div>
    <div class="page-head"><div><h1>My course &amp; fees</h1><p>Your course, the fee, what you have paid, what is still due and when each installment is due.</p></div></div>
    @php($money = fn ($v) => '₹'.number_format((float) $v, 2))
    @forelse($enrollments as $e)
        @php($next = $e->nextDue())
        <section class="card mb" wire:key="fee-{{ $e->id }}">
            <div class="card-head"><div><h2>{{ $e->course_name }} <x-badge :tone="$e->status === 'active' ? 'blue' : ($e->status === 'completed' ? 'green' : 'gray')">{{ \App\Models\TrainingEnrollment::STATUSES[$e->status] ?? $e->status }}</x-badge></h2>
                <p>{{ collect([$e->starts_on ? 'from '.$e->starts_on->format('d M Y') : null, $e->ends_on ? 'to '.$e->ends_on->format('d M Y') : null, $e->course?->durationText() ?? ($e->course?->duration_hours ? (float) $e->course->duration_hours.' h' : null)])->filter()->join(' · ') ?: 'Course' }}</p></div></div>
            <div class="card-body stack">
                <div class="stats">
                    <div class="stat"><div><span class="stat-label">Course fee</span><span class="stat-value">{{ $money($e->total_fee) }}</span>@if((float) $e->discount > 0)<small>discount {{ $money($e->discount) }}</small>@endif</div></div>
                    <div class="stat"><div><span class="stat-label">To pay</span><span class="stat-value">{{ $money($e->payable()) }}</span><small>in {{ $e->installments }} {{ $e->installments == 1 ? 'payment' : 'installments' }}</small></div></div>
                    <div class="stat"><div><span class="stat-label">Paid</span><span class="stat-value" style="color:var(--green)">{{ $money($e->paid()) }}</span><small>{{ $e->payments->count() }} {{ $e->payments->count() === 1 ? 'payment' : 'payments' }}</small></div></div>
                    <div class="stat"><div><span class="stat-label">Still due</span><span class="stat-value" style="color:{{ $e->balance() > 0 ? 'var(--red)' : 'var(--green)' }}">{{ $money($e->balance()) }}</span><small>{{ $e->balance() > 0 ? 'left to pay' : 'fully paid' }}</small></div></div>
                </div>
                @if($next)
                    @php($days = (int) today()->diffInDays(\Illuminate\Support\Carbon::parse($next['due_on']), false))
                    <div style="padding:10px 12px;border-radius:10px;background:{{ $next['status'] === 'overdue' ? 'var(--red-soft, #FDECEC)' : 'var(--amber-soft, #FFF6E5)' }}">
                        <strong>Next installment #{{ $next['no'] }}: {{ $money($next['left']) }}</strong> due on {{ \Illuminate\Support\Carbon::parse($next['due_on'])->format('d M Y') }}
                        <span class="small">· {{ $days < 0 ? 'overdue by '.abs($days).' '.(abs($days) === 1 ? 'day' : 'days') : ($days === 0 ? 'due today' : 'in '.$days.' '.($days === 1 ? 'day' : 'days')) }}</span>
                    </div>
                @endif
                @if($e->topics->isNotEmpty())
                    <h3 class="small" style="margin:0">Topics and mentors</h3>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>#</th><th>Topic</th><th>Mentor</th><th>Status</th><th>Dates</th></tr></thead>
                        <tbody>
                        @foreach($e->topics as $t)
                            @php([$label, $tone] = \App\Models\TrainingEnrollmentTopic::STATUSES[$t->status] ?? [$t->status, 'gray'])
                            <tr><td>{{ $loop->iteration }}</td><td><strong>{{ $t->title }}</strong></td><td>{{ $t->mentor?->name ?? 'to be decided' }}</td><td><x-badge :tone="$tone">{{ $label }}</x-badge></td>
                                <td class="small">@if($t->completed_on){{ $t->started_on?->format('d M Y') }} – {{ $t->completed_on->format('d M Y') }}@elseif($t->started_on)since {{ $t->started_on->format('d M Y') }}@else — @endif</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
                <h3 class="small" style="margin:0">Installments</h3>
                @include('partials.fee-installments', ['enrollment' => $e])
                <h3 class="small" style="margin:0">Payments (receipts)</h3>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Installment</th><th class="num">Amount</th><th>Paid by</th><th>Reference</th></tr></thead>
                    <tbody>
                    @forelse($e->payments as $p)
                        <tr><td>{{ $p->receipt_no }}</td><td>{{ $p->paid_on->format('d M Y') }}</td><td>#{{ $p->installment_no }}</td><td class="num">{{ $money($p->amount) }}</td><td>{{ \App\Models\TrainingFeePayment::METHODS[$p->method] ?? $p->method }}</td><td>{{ $p->reference ?: '—' }}</td></tr>
                    @empty
                        <tr><td colspan="6"><x-empty title="No payment yet" icon="file" /></td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
        </section>
    @empty
        <section class="card"><div class="card-body"><x-empty title="No course yet" icon="star" /><p class="small muted" style="text-align:center">Your course and fee will show here once HR adds them (Training &gt; Course fees).</p></div></section>
    @endforelse
</div>
