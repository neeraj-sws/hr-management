<div>
    <div class="page-head">
        <div><h1>Night work</h1><p>Worked at night (after the office day)? Ask for it with the tasks and what you did. When your manager approves, the time is paid with your salary (hourly rate).</p></div>
        @if($own)<div class="actions"><button class="btn btn-primary" wire:click="$set('showForm', true)"><x-icon name="plus" class="icon-sm" />Add night work</button></div>@endif
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
            <thead><tr><th>Date</th>@if($team)<th>Employee</th>@endif<th>Time</th><th>Tasks &amp; work done</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($requests as $r)
                <tr wire:key="nw-{{ $r->id }}">
                    <td class="nowrap"><strong>{{ $r->work_date->format('d M Y') }}</strong><small>{{ $r->work_date->format('D') }} · asked {{ $r->created_at->format('d M, h:i A') }}</small></td>
                    @if($team)<td><strong>{{ $r->employee?->name }}</strong><small>{{ $r->employee?->employee_code }}</small></td>@endif
                    <td class="nowrap">{{ $r->timeRange() }}<small>{{ \App\Support\Duration::words($r->minutes) }}</small></td>
                    <td>@foreach($r->tasks() as $t)<div class="small">• {{ $t->title }}@if($t->project)<span class="muted"> · {{ $t->project->name }}</span>@endif</div>@endforeach<small>{{ $r->work_done }}</small></td>
                    <td><x-badge :status="$r->status" />@if($r->decision_note)<small>{{ $r->decision_note }}</small>@endif @if($r->decider)<small>{{ $r->decider->name }}, {{ $r->decided_at?->format('d M') }}</small>@endif</td>
                    <td class="nowrap">
                        @if(in_array($r->id, $decidable))<button class="btn btn-sm btn-success" wire:click="openDecide({{ $r->id }}, true)">Approve</button> <button class="btn btn-sm" wire:click="openDecide({{ $r->id }}, false)">Reject</button>@endif
                        @if(! $team && $r->status === 'pending')<button class="btn btn-sm" wire:click="cancel({{ $r->id }})" wire:confirm="Cancel this night work request?">Cancel</button>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty title="No night work requests" icon="clock" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $requests->links() }}
    </section>

    @if($showForm)
        <x-modal title="Add night work">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Date" name="workDate" hint="the day the night belongs to"><input type="date" wire:model="workDate" max="{{ today()->toDateString() }}" min="{{ today()->subDays(\App\Services\NightWorkService::DAYS_BACK)->toDateString() }}"></x-field>
                <x-field label="From" name="startsAt"><input type="time" wire:model="startsAt"></x-field>
                <x-field label="To" name="endsAt" hint="after midnight is fine, e.g. 01:30"><input type="time" wire:model="endsAt"></x-field>
                <x-field label="Tasks you worked on" name="taskIds" full>
                    <div class="checks">
                        @forelse($myTasks as $t)<label class="check"><input type="checkbox" value="{{ $t->id }}" wire:model="taskIds"> {{ $t->title }}@if($t->project)<span class="muted small"> · {{ $t->project->name }}</span>@endif</label>@empty<span class="small muted">No open tasks - write what you did below.</span>@endforelse
                    </div>
                </x-field>
                <x-field label="What did you do?" name="workDone" full><textarea rows="4" wire:model="workDone" placeholder="e.g. Fixed the live search bug reported by the client and uploaded the code"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Send for approval</button></div></form>
        </x-modal>
    @endif

    @if($decidingId)
        <x-modal :title="$decideApprove ? 'Approve night work' : 'Reject night work'">
            <form wire:submit="decide"><div class="modal-body"><x-errors />
                <p class="small">{{ $decideApprove ? 'The time is paid with the salary at the hourly rate.' : 'Say why it is not approved; the employee is told.' }}</p>
                <x-field :label="$decideApprove ? 'Note (optional)' : 'Reason'" name="decisionNote" full><textarea rows="3" wire:model="decisionNote"></textarea></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn {{ $decideApprove ? 'btn-success' : 'btn-danger' }}" wire:loading.attr="disabled">{{ $decideApprove ? 'Approve' : 'Reject' }}</button></div></form>
        </x-modal>
    @endif
</div>
