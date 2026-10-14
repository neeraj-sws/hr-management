<div>
    <div class="page-head">
        <div><h1>Performance</h1><p>Periodic reviews with a snapshot of task, time and attendance metrics for the review period.</p></div>
        @if($canReview)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New review</button>@endif
    </div>
    <div class="tabs">
        @if($own)<button class="tab {{ $tab === 'mine' ? 'active' : '' }}" wire:click="$set('tab','mine')">My reviews</button>@endif
        @if($canReview)<button class="tab {{ $tab === 'team' ? 'active' : '' }}" wire:click="$set('tab','team')">Team reviews</button>@endif
    </div>
    @if($reviews->isNotEmpty())<div class="stats"><div class="stat"><span class="stat-icon violet"><x-icon name="star" /></span><div><span class="stat-label">Average rating</span><span class="stat-value">{{ number_format($average, 1) }} / 5</span><small>{{ $reviews->total() }} reviews</small></div></div></div>@endif
    <div class="stack">
        @forelse($reviews as $review)
            <section class="card" wire:key="rv-{{ $review->id }}">
                <div class="card-head">
                    <div><h2>{{ $review->employee?->name }} · {{ $review->period_starts_on->format('d M') }} – {{ $review->period_ends_on->format('d M Y') }}</h2><p>Reviewed by {{ $review->reviewer?->name ?? '—' }} on {{ $review->created_at->format('d M Y') }}</p></div>
                    <div class="actions"><span style="color:var(--amber);font-size:18px;letter-spacing:2px" aria-label="{{ $review->rating }} out of 5">{{ str_repeat('★', $review->rating) }}<span style="color:#d5dbe4">{{ str_repeat('★', 5 - $review->rating) }}</span></span><x-badge :status="$review->status" /></div>
                </div>
                <div class="card-body grid grid-2">
                    <div><p class="eyebrow">Strengths</p><p style="white-space:pre-line;margin:0">{{ $review->strengths }}</p>@if($review->improvements)<p class="eyebrow mt">Areas to improve</p><p style="white-space:pre-line;margin:0">{{ $review->improvements }}</p>@endif
                        @if($review->employee_comment)<p class="eyebrow mt">Employee comment</p><p style="margin:0">{{ $review->employee_comment }}</p>@endif</div>
                    <div>@if($review->metrics)<p class="eyebrow">Metrics snapshot</p><div class="pill-row">
                        <span class="pill"><b>{{ $review->metrics['completed_tasks'] ?? 0 }}</b>tasks done</span><span class="pill"><b>{{ $review->metrics['completed_late'] ?? 0 }}</b>late</span><span class="pill"><b>{{ $review->metrics['overdue_tasks'] ?? 0 }}</b>overdue</span>
                        <span class="pill"><b>{{ $review->metrics['on_time_rate'] ?? '—' }}{{ isset($review->metrics['on_time_rate']) ? '%' : '' }}</b>on time</span><span class="pill"><b>{{ $review->metrics['logged_hours'] ?? 0 }}</b>h logged</span><span class="pill"><b>{{ $review->metrics['attendance_days'] ?? 0 }}</b>attendance days</span>
                    </div>@endif
                    @if($tab === 'mine' && $review->status === 'submitted')<div class="mt"><button class="btn btn-primary btn-sm" wire:click="openAcknowledge({{ $review->id }})">Acknowledge</button></div>@endif</div>
                </div>
            </section>
        @empty
            <div class="card"><x-empty title="No performance reviews yet" icon="star" /></div>
        @endforelse
        {{ $reviews->links() }}
    </div>

    @if($showForm)
        <x-modal title="New performance review" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Employee" name="employeeId" full><select wire:model="employeeId"><option value="">Select</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></x-field>
                <x-field label="Period start" name="periodStart"><input type="date" wire:model="periodStart"></x-field>
                <x-field label="Period end" name="periodEnd"><input type="date" wire:model="periodEnd" x-bind:min="$wire.periodStart"></x-field>
                <x-field label="Rating" name="rating" full><select wire:model="rating"><option value="5">5 — Outstanding</option><option value="4">4 — Exceeds expectations</option><option value="3">3 — Meets expectations</option><option value="2">2 — Needs improvement</option><option value="1">1 — Unsatisfactory</option></select></x-field>
                <x-field label="Strengths" name="strengths" full><textarea wire:model="strengths"></textarea></x-field>
                <x-field label="Areas to improve" name="improvements" full><textarea wire:model="improvements"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Share review</button></div></form>
        </x-modal>
    @endif
    @if($acknowledgingId)
        <x-modal title="Acknowledge review">
            <form wire:submit="acknowledge"><div class="modal-body"><x-field label="Comment (optional)" name="employeeComment"><textarea wire:model="employeeComment"></textarea></x-field></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Acknowledge</button></div></form>
        </x-modal>
    @endif
</div>
