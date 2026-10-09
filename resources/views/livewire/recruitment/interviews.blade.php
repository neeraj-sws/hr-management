<div>
    <div class="page-head">
        <div><h1>Interviews</h1><p>{{ $all ? 'All interviews' : 'Interviews you are taking' }}. After each one, add your rating and feedback.</p></div>
        @if($canSeeAll)<label class="check"><input type="checkbox" wire:model.live="all"> Show everyone's interviews</label>@endif
    </div>
    <div class="tabs">
        <button class="tab {{ $show === 'upcoming' ? 'active' : '' }}" wire:click="$set('show','upcoming')">Upcoming</button>
        <button class="tab {{ $show === 'past' ? 'active' : '' }}" wire:click="$set('show','past')">Completed &amp; cancelled</button>
    </div>
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>When</th><th>Candidate</th><th>Position</th><th>Mode</th>@if($all)<th>Interviewer</th>@endif<th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($interviews as $iv)
                <tr wire:key="iv-{{ $iv->id }}">
                    <td class="nowrap"><strong>{{ $iv->scheduled_at->format('D d M') }}</strong><small>{{ $iv->scheduled_at->format('h:i A') }} · {{ $iv->duration_minutes }} min</small></td>
                    <td><strong>{{ $iv->candidate?->name }}</strong><small>@if($iv->candidate?->resume_path)<a href="{{ route('recruitment.resume', $iv->candidate) }}">CV</a>@else no CV @endif</small></td>
                    <td>{{ $iv->candidate?->jobOpening?->title }}</td>
                    <td>{{ \App\Models\Interview::MODES[$iv->mode] ?? $iv->mode }}@if($iv->location)<small style="word-break:break-all">{{ $iv->location }}</small>@endif</td>
                    @if($all)<td>{{ $iv->interviewer?->name }}</td>@endif
                    <td><x-badge :status="$iv->status" />@if($iv->rating)<small>★ {{ $iv->rating }}/5 · {{ \App\Models\Interview::RECOMMENDATIONS[$iv->recommendation] ?? '' }}</small>@endif</td>
                    <td>@if($iv->status !== 'cancelled' && ($iv->interviewer_id === auth()->id() || $canSeeAll))<button class="btn btn-sm {{ $iv->status === 'scheduled' ? 'btn-primary' : '' }}" wire:click="openFeedback({{ $iv->id }})">{{ $iv->status === 'completed' ? 'Edit feedback' : 'Give feedback' }}</button>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="{{ $show === 'upcoming' ? 'No upcoming interviews' : 'No past interviews' }}" icon="calendar" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    @if($giving)
        <x-modal title="Feedback · {{ $giving->candidate?->name }}">
            <form wire:submit="saveFeedback"><div class="modal-body">
                <p class="small muted" style="margin-top:0">{{ $giving->candidate?->jobOpening?->title }} · {{ $giving->scheduled_at->format('d M Y, h:i A') }}</p>
                <div class="form-grid">
                    <x-field label="Rating" name="rating"><select wire:model="rating"><option value="">Choose…</option>@foreach([5 => '5 — Excellent', 4 => '4 — Good', 3 => '3 — Average', 2 => '2 — Below average', 1 => '1 — Poor'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></x-field>
                    <x-field label="Recommendation" name="recommendation"><select wire:model="recommendation"><option value="">Choose…</option>@foreach(\App\Models\Interview::RECOMMENDATIONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                    <x-field label="Feedback" name="feedback" full><textarea wire:model="feedback" rows="6" placeholder="Strengths, gaps, and why you recommend this"></textarea></x-field>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save feedback</button></div></form>
        </x-modal>
    @endif
</div>
