@php($stars = fn ($n) => '<span style="color:var(--amber);letter-spacing:2px" aria-label="'.$n.' out of 5">'.str_repeat('★', (int) $n).'<span style="color:#d5dbe4">'.str_repeat('★', 5 - (int) $n).'</span></span>')
<div>
    <div class="page-head">
        <div><h1>Reviews &amp; 360 feedback</h1><p>Self-review by the employee, feedback from colleagues, then the manager scores each goal and gives the final rating. A snapshot of tasks, time and attendance is kept with each review.</p></div>
        @if($canReview)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New review</button>@endif
    </div>
    <div class="tabs">
        @if($own)<button class="tab {{ $tab === 'mine' ? 'active' : '' }}" wire:click="$set('tab','mine')">My reviews</button>@endif
        @if($canReview)<button class="tab {{ $tab === 'team' ? 'active' : '' }}" wire:click="$set('tab','team')">Team reviews</button>@endif
        <button class="tab {{ $tab === 'feedback' ? 'active' : '' }}" wire:click="$set('tab','feedback')">Feedback to give @if($openRequests)<span class="badge badge-amber">{{ $openRequests }}</span>@endif</button>
    </div>

    @if($tab === 'feedback')
        <section class="card">
            <div class="card-head"><div><h2>Feedback requests</h2><p>Your answer goes to the manager with your name; the employee sees all answers together without names (only when at least {{ \App\Models\PerformanceReview::ANONYMOUS_MINIMUM }} people answered).</p></div></div>
            <div class="list">
                @forelse($requests as $r)
                    <div class="list-row" wire:key="fb-{{ $r->id }}"><div><strong>{{ $r->review?->employee?->name }}</strong><small>{{ $r->review?->period_starts_on?->format('d M') }} – {{ $r->review?->period_ends_on?->format('d M Y') }} · you as: {{ \App\Models\PerformanceFeedback::RELATIONSHIPS[$r->relationship] ?? $r->relationship }}@if($r->rating) · you gave {{ $r->rating }}/5 @endif</small></div>
                        <div class="actions"><x-badge :status="$r->status" />@if($r->status === 'requested')<button class="btn btn-sm btn-primary" wire:click="openFeedback({{ $r->id }})">Give feedback</button>@endif</div></div>
                @empty
                    <x-empty title="No feedback requests for you" icon="star" />
                @endforelse
            </div>
        </section>
    @else
        @if($reviews->isNotEmpty() && $average)<div class="stats"><div class="stat"><span class="stat-icon violet"><x-icon name="star" /></span><div><span class="stat-label">Average rating</span><span class="stat-value">{{ number_format($average, 1) }} / 5</span><small>{{ $reviews->total() }} reviews</small></div></div></div>@endif
        <div class="stack">
            @forelse($reviews as $review)
                @php($shared = $review->isShared())
                @php($goals = $review->goals())
                @php($answers = $review->feedback->where('status', 'submitted'))
                <section class="card" wire:key="rv-{{ $review->id }}">
                    <div class="card-head">
                        <div><h2>{{ $review->employee?->name }} · {{ $review->period_starts_on->format('d M') }} – {{ $review->period_ends_on->format('d M Y') }}</h2><p>Manager: {{ $review->reviewer?->name ?? '—' }} · started {{ $review->created_at->format('d M Y') }}@if($review->feedback->isNotEmpty()) · 360 feedback {{ $answers->count() }} of {{ $review->feedback->count() }}@endif</p></div>
                        <div class="actions">@if($shared && $review->rating){!! $stars($review->rating) !!}@endif<x-badge :status="$review->status">{{ \App\Models\PerformanceReview::STATUSES[$review->status] ?? str($review->status)->replace('_', ' ') }}</x-badge></div>
                    </div>
                    <div class="card-body grid grid-2">
                        <div>
                            @if($shared)
                                <p class="eyebrow">Manager's review</p><p style="white-space:pre-line;margin:0"><b>Strengths:</b> {{ $review->strengths }}</p>@if($review->improvements)<p style="white-space:pre-line;margin:6px 0 0"><b>To improve:</b> {{ $review->improvements }}</p>@endif
                                @if($review->employee_comment)<p class="eyebrow mt">Employee comment</p><p style="margin:0">{{ $review->employee_comment }}</p>@endif
                            @endif
                            @if($review->self_submitted_at && ($tab === 'team' || $tab === 'mine'))
                                <p class="eyebrow {{ $shared ? 'mt' : '' }}">Self-review · {!! $stars($review->self_rating) !!}</p><p style="white-space:pre-line;margin:0">{{ $review->self_assessment }}</p>
                            @elseif($review->status === 'self_review')
                                <p class="muted" style="margin:0">{{ $tab === 'mine' ? 'Please write your self-review: how the period went, what you achieved against your goals, and what you need.' : 'Waiting for the employee\'s self-review. You can also complete the review without it.' }}</p>
                            @elseif($review->status === 'manager_review' && $tab === 'mine')
                                <p class="muted" style="margin:0">Your manager is completing the review.</p>
                            @endif

                            {{-- 360 feedback: names for the manager / HR; for the employee only together and without names. --}}
                            @if($tab === 'team' && $review->feedback->isNotEmpty())
                                <p class="eyebrow mt">360 feedback</p>
                                <div class="list">@foreach($review->feedback as $f)<div class="list-row"><div><strong>{{ $f->giver?->name }}</strong> <small>{{ \App\Models\PerformanceFeedback::RELATIONSHIPS[$f->relationship] ?? $f->relationship }}@if($f->status === 'submitted') · {{ $f->rating }}/5 @if($f->strengths) · Good: {{ $f->strengths }}@endif @if($f->improvements) · Improve: {{ $f->improvements }}@endif @endif</small></div><x-badge :status="$f->status" /></div>@endforeach</div>
                            @elseif($tab === 'mine' && $shared && ($anon = $performance->anonymousFeedback($review)))
                                <p class="eyebrow mt">Feedback from {{ $anon['count'] }} colleagues · average {{ $anon['average'] }}/5</p>
                                @if($anon['strengths'])<p style="margin:0"><b>What you do well</b></p><ul style="margin:4px 0">@foreach($anon['strengths'] as $t)<li>{{ $t }}</li>@endforeach</ul>@endif
                                @if($anon['improvements'])<p style="margin:0"><b>What to improve</b></p><ul style="margin:4px 0">@foreach($anon['improvements'] as $t)<li>{{ $t }}</li>@endforeach</ul>@endif
                            @endif
                        </div>
                        <div>
                            @if($goals->isNotEmpty())
                                <p class="eyebrow">Goals @if($shared && ($score = $review->goalScore())) · goal score {{ $score }}/5 @endif</p>
                                <div class="list">@foreach($goals as $g)<div class="list-row"><div><strong>{{ $g->title }}</strong><small>{{ \App\Models\PerformanceGoal::KINDS[$g->kind] ?? '' }} · weight {{ $g->weight }}% · progress {{ $g->progress }}%@if($shared && ($s = ($review->goal_scores[$g->id] ?? null))) · score {{ $s }}/5 @endif</small></div><x-badge :status="$g->status" /></div>@endforeach</div>
                            @endif
                            @if($review->metrics)<p class="eyebrow {{ $goals->isNotEmpty() ? 'mt' : '' }}">Metrics snapshot</p><div class="pill-row">
                                <span class="pill"><b>{{ $review->metrics['completed_tasks'] ?? 0 }}</b>tasks done</span><span class="pill"><b>{{ $review->metrics['completed_late'] ?? 0 }}</b>late</span><span class="pill"><b>{{ $review->metrics['overdue_tasks'] ?? 0 }}</b>overdue</span>
                                <span class="pill"><b>{{ $review->metrics['on_time_rate'] ?? '—' }}{{ isset($review->metrics['on_time_rate']) ? '%' : '' }}</b>on time</span><span class="pill"><b>{{ $review->metrics['logged_hours'] ?? 0 }}</b>h logged</span><span class="pill"><b>{{ $review->metrics['attendance_days'] ?? 0 }}</b>attendance days</span>
                            </div>@endif
                            <div class="mt actions">
                                @if($tab === 'mine' && $review->status === 'self_review')<button class="btn btn-primary btn-sm" wire:click="openSelf({{ $review->id }})">Write self-review</button>@endif
                                @if($tab === 'mine' && $review->status === 'submitted')<button class="btn btn-primary btn-sm" wire:click="openAcknowledge({{ $review->id }})">Acknowledge</button>@endif
                                @if($tab === 'team' && ! $shared)<button class="btn btn-primary btn-sm" wire:click="openComplete({{ $review->id }})">Complete review</button>@endif
                            </div>
                        </div>
                    </div>
                </section>
            @empty
                <div class="card"><x-empty title="No performance reviews yet" icon="star" /></div>
            @endforelse
            {{ $reviews->links() }}
        </div>
    @endif

    @if($showForm)
        <x-modal title="New performance review" size="lg">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Employee" name="employeeId" full><select wire:model.live="employeeId"><option value="">Select</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></x-field>
                <x-field label="Period start" name="periodStart"><input type="date" wire:model="periodStart"></x-field>
                <x-field label="Period end" name="periodEnd"><input type="date" wire:model="periodEnd" x-bind:min="$wire.periodStart"></x-field>
                <x-field label="How" name="mode" full><select wire:model.live="mode"><option value="cycle">Ask for a self-review and 360 feedback first (recommended)</option><option value="direct">Write the review now</option></select></x-field>
                @if($mode === 'cycle')
                    <x-field label="Ask these colleagues for feedback (360)" name="feedbackFrom" full hint="peers, people who report to them, other managers · up to 10 · hold Ctrl to pick several"><select multiple size="7" wire:model="feedbackFrom">@foreach($colleagues as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
                @endif
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">{{ $mode === 'cycle' ? 'Start review' : 'Next' }}</button></div></form>
        </x-modal>
    @endif

    @if($selfReview)
        <x-modal title="Self-review · {{ $selfReview->period_starts_on->format('d M') }} – {{ $selfReview->period_ends_on->format('d M Y') }}" size="lg">
            <form wire:submit="submitSelf"><div class="modal-body"><x-errors />
                @if(($goals = $selfReview->goals())->isNotEmpty())<p class="eyebrow" style="margin-top:0">Your goals</p><div class="list mb">@foreach($goals as $g)<div class="list-row"><div><strong>{{ $g->title }}</strong><small>weight {{ $g->weight }}% · progress {{ $g->progress }}%@if($g->target) · target {{ $g->target }}@endif</small></div></div>@endforeach</div>@endif
                <div class="form-grid">
                    <x-field label="How would you rate your own work?" name="selfRating" full><select wire:model="selfRating"><option value="5">5 — Outstanding</option><option value="4">4 — Exceeds expectations</option><option value="3">3 — Meets expectations</option><option value="2">2 — Needs improvement</option><option value="1">1 — Unsatisfactory</option></select></x-field>
                    <x-field label="Self-review" name="selfAssessment" full hint="what you achieved against your goals, what went well, what was hard, what help you need"><textarea rows="7" wire:model="selfAssessment"></textarea></x-field>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Send to my manager</button></div></form>
        </x-modal>
    @endif

    @if($feedbackFor)
        <x-modal title="Feedback for {{ $feedbackFor->review?->employee?->name }}">
            <form wire:submit="submitFeedback"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Overall rating" name="feedbackRating" full><select wire:model="feedbackRating"><option value="5">5 — Outstanding</option><option value="4">4 — Exceeds expectations</option><option value="3">3 — Meets expectations</option><option value="2">2 — Needs improvement</option><option value="1">1 — Unsatisfactory</option></select></x-field>
                <x-field label="What do they do well?" name="feedbackStrengths" full><textarea wire:model="feedbackStrengths"></textarea></x-field>
                <x-field label="What could they do better?" name="feedbackImprovements" full><textarea wire:model="feedbackImprovements"></textarea></x-field>
            </div><p class="small muted">The manager sees your name. The employee sees the answers together, without names.</p></div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" wire:click="submitFeedback(true)" wire:confirm="Decline this feedback request?">Decline</button><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Send feedback</button></div></form>
        </x-modal>
    @endif

    @if($completing)
        <x-modal title="Complete review · {{ $completing->employee?->name }}" size="lg">
            <form wire:submit="complete"><div class="modal-body"><x-errors />
                @if($completing->self_submitted_at)<p class="eyebrow" style="margin-top:0">Self-review · {!! $stars($completing->self_rating) !!}</p><p class="small" style="white-space:pre-line">{{ $completing->self_assessment }}</p>
                @elseif($completing->status === 'self_review')<div class="alert alert-info">The employee has not written a self-review yet. You can still complete the review.</div>@endif
                @if(($given = $completing->feedback->where('status', 'submitted'))->isNotEmpty())<p class="eyebrow">360 feedback · average {{ round($given->avg('rating'), 1) }}/5 from {{ $given->count() }}</p><div class="list mb">@foreach($given as $f)<div class="list-row"><div><strong>{{ $f->giver?->name }} · {{ $f->rating }}/5</strong><small>Good: {{ $f->strengths }}@if($f->improvements) · Improve: {{ $f->improvements }}@endif</small></div></div>@endforeach</div>@endif
                @if(($goals = $completing->goals())->isNotEmpty())
                    <p class="eyebrow">Score each goal (1–5)</p>
                    <div class="table-wrap mb"><table class="table"><thead><tr><th>Goal</th><th class="num">Weight</th><th class="num">Progress</th><th>Score</th></tr></thead><tbody>
                        @foreach($goals as $g)<tr wire:key="gs-{{ $g->id }}"><td>{{ $g->title }}@if($g->target)<small>{{ $g->target }}</small>@endif</td><td class="num">{{ $g->weight }}%</td><td class="num">{{ $g->progress }}%</td><td><select wire:model="goalScores.{{ $g->id }}"><option value="">—</option>@foreach([5, 4, 3, 2, 1] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select></td></tr>@endforeach
                    </tbody></table></div>
                @endif
                <div class="form-grid">
                    <x-field label="Final rating" name="rating" full><select wire:model="rating"><option value="5">5 — Outstanding</option><option value="4">4 — Exceeds expectations</option><option value="3">3 — Meets expectations</option><option value="2">2 — Needs improvement</option><option value="1">1 — Unsatisfactory</option></select></x-field>
                    <x-field label="Strengths" name="strengths" full><textarea wire:model="strengths"></textarea></x-field>
                    <x-field label="Areas to improve" name="improvements" full><textarea wire:model="improvements"></textarea></x-field>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Share review with the employee</button></div></form>
        </x-modal>
    @endif

    @if($acknowledgingId)
        <x-modal title="Acknowledge review">
            <form wire:submit="acknowledge"><div class="modal-body"><x-field label="Comment (optional)" name="employeeComment"><textarea wire:model="employeeComment"></textarea></x-field></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Acknowledge</button></div></form>
        </x-modal>
    @endif
</div>
