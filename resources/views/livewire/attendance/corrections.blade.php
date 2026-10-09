<div>
    <div class="page-head">
        <div><h1>Attendance Corrections</h1><p>Forgot to check out, or checked in late by mistake? Ask for a correction — your manager approves it and the attendance is updated.</p></div>
        @if($own)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Request correction</button>@endif
    </div>
    <div class="tabs">
        @if($own)<button class="tab {{ $tab === 'mine' ? 'active' : '' }}" wire:click="$set('tab','mine')">My requests</button>@endif
        @if($canApprove)<button class="tab {{ $tab === 'approvals' ? 'active' : '' }}" wire:click="$set('tab','approvals')">Awaiting my approval @if($pendingCount)<span class="nav-count" style="margin-left:6px">{{ $pendingCount }}</span>@endif</button><button class="tab {{ $tab === 'all' ? 'active' : '' }}" wire:click="$set('tab','all')">History</button>@endif
    </div>
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr>@if($tab !== 'mine')<th>Employee</th>@endif<th>Day</th><th>Recorded</th><th>Requested</th><th>Reason</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($corrections as $c)
                <tr wire:key="corr-{{ $c->id }}">
                    @if($tab !== 'mine')<td><strong>{{ $c->employee?->name }}</strong><small>{{ $c->employee?->employee_code }}</small></td>@endif
                    <td class="nowrap">{{ $c->work_date->format('D d M Y') }}</td>
                    <td class="nowrap">@if($c->status === 'approved')<span class="muted">updated</span>@elseif($c->session){{ $c->session->check_in?->format('h:i A') ?? '—' }} – {{ $c->session->check_out?->format('h:i A') ?? 'no check-out' }}@else<span class="muted">no attendance</span>@endif</td>
                    <td class="nowrap">{{ $c->requested_check_in?->format('h:i A') ?? 'unchanged' }} – {{ $c->requested_check_out?->format('h:i A') ?? 'unchanged' }}@if($c->requested_break_minutes !== null)<small>break {{ $c->requested_break_minutes }} min</small>@endif@if($c->requested_check_out && $c->requested_check_out->toDateString() !== $c->work_date->toDateString())<small>next day</small>@endif</td>
                    <td style="white-space:normal;max-width:280px">{{ $c->reason }}@if($c->decision_note)<small>Note: {{ $c->decision_note }}</small>@endif</td>
                    <td><x-badge :status="$c->status" />@if($c->decider)<small>by {{ $c->decider->name }}</small>@endif</td>
                    <td>
                        @if($tab === 'approvals')<button class="btn btn-sm btn-primary" wire:click="openDecision({{ $c->id }})">Review</button>
                        @elseif($tab === 'mine' && $c->status === 'pending')<button class="btn btn-sm" wire:click="cancel({{ $c->id }})" wire:confirm="Cancel this correction request?">Cancel</button>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="No correction requests" icon="clock" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $corrections->links() }}
    </section>

    @if($showForm)
        <x-modal title="Request attendance correction">
            <form wire:submit="submit"><div class="modal-body">
                <p class="small muted" style="margin-top:0">Fill in the correct times. Leave a time empty to keep what was recorded. A check-out earlier than the check-in counts as the next morning (night shift).</p>
                <div class="form-grid">
                    <x-field label="Day" name="workDate" full><input type="date" wire:model.live="workDate" max="{{ today()->toDateString() }}"></x-field>
                    <x-field label="Correct check-in" name="checkIn"><input type="time" wire:model="checkIn"></x-field>
                    <x-field label="Correct check-out" name="checkOut"><input type="time" wire:model="checkOut"></x-field>
                    <x-field label="Correct total break (minutes)" name="breakMinutes" hint="blank = keep recorded break" full><input type="number" min="0" max="720" wire:model="breakMinutes" placeholder="e.g. 30"></x-field>
                    <x-field label="Reason" name="reason" full><textarea wire:model="reason" placeholder="e.g. Forgot to check out before leaving at 6:30 PM"></textarea></x-field>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Send for approval</button></div></form>
        </x-modal>
    @endif
    @if($deciding)
        <x-modal title="Review attendance correction">
            <div class="modal-body">
                <dl class="kv">
                    <dt>Employee</dt><dd>{{ $deciding->employee?->name }}</dd>
                    <dt>Day</dt><dd>{{ $deciding->work_date->format('D d M Y') }}</dd>
                    <dt>Recorded</dt><dd>@if($deciding->session){{ $deciding->session->check_in?->format('h:i A') ?? '—' }} – {{ $deciding->session->check_out?->format('h:i A') ?? 'no check-out' }}@else No attendance @endif</dd>
                    <dt>Requested</dt><dd>{{ $deciding->requested_check_in?->format('h:i A') ?? 'unchanged' }} – {{ $deciding->requested_check_out?->format('d M h:i A') ?? 'unchanged' }}</dd>
                    @if($deciding->requested_break_minutes !== null)<dt>Break</dt><dd>{{ $deciding->session ? intdiv((int) $deciding->session->break_seconds, 60).' min recorded → ' : '' }}{{ $deciding->requested_break_minutes }} min</dd>@endif
                    <dt>Reason</dt><dd>{{ $deciding->reason }}</dd>
                </dl>
                <div class="form-grid mt"><x-field label="Note" name="decisionNote" hint="required to reject" full><input wire:model="decisionNote"></x-field></div>
            </div>
            <div class="modal-foot"><button class="btn btn-danger" wire:click="decide(false)">Reject</button><button class="btn btn-success" wire:click="decide(true)" wire:confirm="Approve and update the attendance record?">Approve</button></div>
        </x-modal>
    @endif
</div>
