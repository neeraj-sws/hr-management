<div>
    <div class="page-head">
        <div><h1>Off day requests</h1><p>For people whose week-of-month off (e.g. 2nd Saturday) counts only when they ask for it. Ask here when you are taking the off; once HR approves, the day is your off. Not asked for or not approved, it is a normal working day (not coming in = absent).</p></div>
        @if($asks)<div class="actions"><button class="btn btn-primary" wire:click="openForm"><x-icon name="plus" class="icon-sm" />Ask for an off day</button></div>@endif
    </div>
    <x-errors />

    @if($asks)
        <section class="card mb"><div class="card-body">
            <strong>Your offs that need asking for</strong>
            <div class="pill-row" style="margin-top:8px">
                @forelse($dates as $d)
                    <span class="pill">{{ \Illuminate\Support\Carbon::parse($d)->format('D d M') }} · {{ ['approved' => 'Off (approved)', 'pending' => 'Waiting for HR'][$asked[$d] ?? ''] ?? 'Working day unless you ask' }}</span>
                @empty
                    <span class="small muted">None in the next {{ \App\Services\OffDayService::DAYS_AHEAD }} days.</span>
                @endforelse
            </div>
        </div></section>
    @endif

    @if($canSeeTeam && $asks)
        <div class="tabs mb">
            <button class="tab {{ ! $team ? 'active' : '' }}" wire:click="$set('tab', 'mine')">My requests</button>
            <button class="tab {{ $team ? 'active' : '' }}" wire:click="$set('tab', 'team')">Everyone's requests</button>
        </div>
    @endif

    <section class="card">
        <div class="table-wrap"><table class="table" data-no-sort>
            <thead><tr><th>Off day</th>@if($team)<th>Employee</th>@endif<th>Note</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($requests as $r)
                <tr wire:key="od-{{ $r->id }}">
                    <td class="nowrap"><strong>{{ $r->off_date->format('d M Y') }}</strong><small>{{ $r->off_date->format('l') }} · asked {{ $r->created_at->format('d M, h:i A') }}</small></td>
                    @if($team)<td><strong>{{ $r->employee?->name }}</strong><small>{{ $r->employee?->employee_code }}</small></td>@endif
                    <td><small>{{ $r->note ?: '—' }}</small></td>
                    <td><x-badge :status="$r->status" />@if($r->decision_note)<small>{{ $r->decision_note }}</small>@endif @if($r->decider)<small>{{ $r->decider->name }}, {{ $r->decided_at?->format('d M') }}</small>@endif</td>
                    <td class="nowrap">
                        @if(in_array($r->id, $decidable))<button class="btn btn-sm btn-success" wire:click="openDecide({{ $r->id }}, true)">Approve</button> <button class="btn btn-sm" wire:click="openDecide({{ $r->id }}, false)">Reject</button>@endif
                        @if(! $team && $r->status === 'pending')<button class="btn btn-sm" wire:click="cancel({{ $r->id }})" wire:confirm="Cancel this off day request?">Cancel</button>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty title="No off day requests" icon="calendar" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $requests->links() }}
    </section>

    @if($showForm)
        <x-modal title="Ask for an off day">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Off day" name="offDate" full>
                    <select wire:model="offDate"><option value="">Choose the day</option>@foreach($dates as $d)@unless(isset($asked[$d]))<option value="{{ $d }}">{{ \Illuminate\Support\Carbon::parse($d)->format('l, d M Y') }}</option>@endunless @endforeach</select>
                </x-field>
                <x-field label="Note (optional)" name="note" full><textarea rows="3" wire:model="note" placeholder="e.g. Taking my 2nd Saturday off"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Send to HR</button></div></form>
        </x-modal>
    @endif

    @if($decidingId)
        <x-modal :title="$decideApprove ? 'Approve off day' : 'Reject off day'">
            <form wire:submit="decide"><div class="modal-body"><x-errors />
                <p class="small">{{ $decideApprove ? "The day becomes the employee's off (paid like any weekly off)." : 'The day stays a working day; say why, the employee is told.' }}</p>
                <x-field :label="$decideApprove ? 'Note (optional)' : 'Reason'" name="decisionNote" full><textarea rows="3" wire:model="decisionNote"></textarea></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn {{ $decideApprove ? 'btn-success' : 'btn-danger' }}" wire:loading.attr="disabled">{{ $decideApprove ? 'Approve' : 'Reject' }}</button></div></form>
        </x-modal>
    @endif
</div>
