<div>
    <div class="page-head">
        <div><h1>Late arrival</h1><p>Coming late? Tell in advance - before your day starts - with your expected check-in time and a valid reason. Management decides; sending it does not mean approval. Approved and in by that time: no late fine and no late cut.</p></div>
        @if($own)<div class="actions"><button class="btn btn-primary" wire:click="$set('showForm', true)"><x-icon name="plus" class="icon-sm" />I will come late</button></div>@endif
    </div>
    <x-errors />

    @if($canSeeTeam && $own)
        <div class="tabs mb">
            <button class="tab {{ ! $team ? 'active' : '' }}" wire:click="$set('tab', 'mine')">My requests</button>
            <button class="tab {{ $team ? 'active' : '' }}" wire:click="$set('tab', 'team')">Team requests</button>
        </div>
    @endif

    <section class="card">
        <div class="table-wrap"><table class="table" data-no-sort>
            <thead><tr><th>Date</th>@if($team)<th>Employee</th>@endif<th>Expected check-in</th><th>Actual check-in</th><th>Reason</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($requests as $r)
                @php($in = $checkIns[$r->employee_id.'|'.$r->work_date->toDateString()] ?? null)
                <tr wire:key="la-{{ $r->id }}">
                    <td class="nowrap"><strong>{{ $r->work_date->format('d M Y') }}</strong><small>{{ $r->work_date->format('D') }} · sent {{ $r->created_at->format('d M, h:i A') }}</small></td>
                    @if($team)<td><strong>{{ $r->employee?->name }}</strong><small>{{ $r->employee?->employee_code }}</small></td>@endif
                    <td class="nowrap">{{ $r->expectedLabel() }}</td>
                    <td class="nowrap">@if($in){{ $in->format('h:i A') }}@if($r->status === 'approved')<small style="color:{{ $in->format('H:i') <= $r->expected_at ? 'var(--green)' : 'var(--red)' }}">{{ $in->format('H:i') <= $r->expected_at ? 'in time - no late cut' : 'later than given - late rules apply' }}</small>@endif @else<span class="muted">—</span>@endif</td>
                    <td><small>{{ $r->reason }}</small></td>
                    <td><x-badge :status="$r->status" />@if($r->decision_note)<small>{{ $r->decision_note }}</small>@endif @if($r->decider)<small>{{ $r->decider->name }}, {{ $r->decided_at?->format('d M') }}</small>@endif</td>
                    <td class="nowrap">
                        @if(in_array($r->id, $decidable))<button class="btn btn-sm btn-success" wire:click="openDecide({{ $r->id }}, true)">Approve</button> <button class="btn btn-sm" wire:click="openDecide({{ $r->id }}, false)">Reject</button>@endif
                        @if(! $team && $r->status === 'pending')<button class="btn btn-sm" wire:click="cancel({{ $r->id }})" wire:confirm="Cancel this late arrival request?">Cancel</button>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="No late arrival requests" icon="clock" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $requests->links() }}
    </section>

    @if($showForm)
        <x-modal title="I will come late">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Date" name="workDate" hint="today (before your start time) or a later day"><input type="date" wire:model="workDate" min="{{ today()->toDateString() }}" max="{{ today()->addDays(30)->toDateString() }}"></x-field>
                <x-field label="Expected check-in" name="expectedAt"><input type="time" wire:model="expectedAt"></x-field>
                <x-field label="Reason" name="reason" full hint="a valid reason; a personal reason is not accepted"><textarea rows="3" wire:model="reason" placeholder="e.g. Bank visit for the company account, will reach by 11:30 AM"></textarea></x-field>
            </div><p class="small muted">Management reviews it; sending does not mean approval. If you check in later than the time you give, the normal late rules apply.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Send</button></div></form>
        </x-modal>
    @endif

    @if($decidingId)
        <x-modal :title="$decideApprove ? 'Approve late arrival' : 'Reject late arrival'">
            <form wire:submit="decide"><div class="modal-body"><x-errors />
                <p class="small">{{ $decideApprove ? 'If they check in by the time given: no late fine and no late cut that day.' : 'Say why it is not approved; the employee is told and the normal late rules apply.' }}</p>
                <x-field :label="$decideApprove ? 'Note (optional)' : 'Reason'" name="decisionNote" full><textarea rows="3" wire:model="decisionNote"></textarea></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn {{ $decideApprove ? 'btn-success' : 'btn-danger' }}" wire:loading.attr="disabled">{{ $decideApprove ? 'Approve' : 'Reject' }}</button></div></form>
        </x-modal>
    @endif
</div>
