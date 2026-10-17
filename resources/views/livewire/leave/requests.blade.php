<div>
    <div class="page-head">
        <div><h1>Leave Requests</h1><p>Request time off and track approvals.</p></div>
        @if($own)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Request leave</button>@endif
    </div>
    <x-errors />
    @if(! $own)<div class="alert alert-info">Your account has no employee profile, so you cannot request leave yourself. You can review and approve requests here.</div>@endif
    @if($own && $types->isEmpty())<div class="alert alert-error">No leave types are set up yet, so leave cannot be requested. An administrator can add them under Settings → Leave types.</div>@endif

    @if($own && $balances->isNotEmpty())
        <div class="stats">
            @foreach($balances as $b)
                <div class="stat"><span class="stat-icon {{ $b['type']->paid ? 'green' : 'violet' }}"><x-icon name="calendar" /></span><div><span class="stat-label">{{ $b['type']->name }}</span><span class="stat-value">{{ round($b['remaining'], 2) }}</span><small>{{ $b['type']->paid ? 'days available' : 'unpaid' }} · {{ $b['booked'] }} pending</small></div></div>
            @endforeach
        </div>
    @endif

    <div class="tabs">
        @if($own)<button class="tab {{ $tab === 'mine' ? 'active' : '' }}" wire:click="$set('tab','mine')">My requests</button>@endif
        @if($canApprove)
            <button class="tab {{ $tab === 'approvals' ? 'active' : '' }}" wire:click="$set('tab','approvals')">Awaiting my approval @if($pendingCount)<span class="nav-count" style="margin-left:6px">{{ $pendingCount }}</span>@endif</button>
            <button class="tab {{ $tab === 'all' ? 'active' : '' }}" wire:click="$set('tab','all')">{{ auth()->user()->can('leave.manage') ? 'All requests' : 'Team requests' }}</button>
        @endif
    </div>

    <section class="card">
        <div class="toolbar"><label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option></select></label></div>
        <div class="table-wrap"><table class="table">
            <thead><tr>@if($tab !== 'mine')<th>Employee</th>@endif<th>Type</th><th>Dates</th><th class="num">Days</th><th>Reason</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($requests as $leave)
                <tr wire:key="l-{{ $leave->id }}">
                    @if($tab !== 'mine')<td><strong>{{ $leave->employee?->name }}</strong><small>{{ $leave->employee?->employee_code }}</small></td>@endif
                    <td>{{ $leave->leaveType?->name }}<small>{{ $leave->leaveType?->paid ? 'Paid' : 'Unpaid' }}</small></td>
                    <td class="nowrap">@if($leave->duration === 'full'){{ $leave->starts_on->format('d M') }} – {{ $leave->ends_on->format('d M Y') }}@else{{ $leave->starts_on->format('d M Y') }}<small>{{ \App\Models\LeaveRequest::DURATION_LABELS[$leave->duration] ?? 'Full day' }}</small>@endif</td>
                    <td class="num">{{ (float) $leave->days }}</td>
                    <td style="max-width:280px;white-space:normal">{{ $leave->reason }}@if($leave->decision_note)<small>Note: {{ $leave->decision_note }}</small>@endif</td>
                    <td><x-badge :status="$leave->status" />@if($leave->decider)<small>by {{ $leave->decider->name }}</small>@endif @foreach($leave->conflictResolutions as $settled)<small style="color:{{ $settled->resolution === 'worked' ? 'var(--green)' : 'var(--muted)' }}">{{ $settled->work_date->format('d M') }}: {{ $settled->resolution === 'worked' ? 'worked — counted as a working day'.($leave->leaveType?->paid ? ', leave day returned to balance' : '') : 'counted as leave' }}</small>@endforeach</td>
                    <td><div class="row-actions">
                        @if($tab === 'approvals')<button class="btn btn-sm btn-primary" wire:click="openDecision({{ $leave->id }})">Review</button>@endif
                        @if($tab === 'mine' && $leave->status === 'pending')<button class="btn btn-sm btn-danger" wire:click="cancel({{ $leave->id }})" wire:confirm="Cancel this leave request?">Cancel</button>@endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty :title="$tab === 'approvals' ? 'No leave awaiting your approval' : 'No leave requests'" icon="calendar" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $requests->links() }}
    </section>

    @if($showForm)
        <x-modal title="Request leave">
            <form wire:submit="submit"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Leave type" name="leaveTypeId" full><select wire:model.live="leaveTypeId"><option value="">Select type</option>@foreach($balances as $b)<option value="{{ $b['type']->id }}">{{ $b['type']->name }} — {{ $b['type']->paid ? round($b['remaining'], 2).' day(s) available' : 'unpaid' }}</option>@endforeach</select></x-field>
                <div class="full small muted" style="margin-top:-6px">
                    @foreach($balances as $b)
                        <div @class(['muted' => (int) $leaveTypeId !== $b['type']->id]) style="{{ (int) $leaveTypeId === $b['type']->id ? 'color:var(--ink-2)' : '' }}"><strong>{{ $b['type']->name }}</strong>: {{ $b['type']->describe() }}@if($b['type']->paid) · available {{ round($b['remaining'], 2) }}, pending {{ $b['booked'] }}@endif</div>
                    @endforeach
                </div>
                <x-field label="Duration" name="duration" full><select wire:model.live="duration"><option value="full">Full day (one or more days)</option><option value="half">Half day</option><option value="short">Short leave (part of a day)</option></select></x-field>
                <x-field label="From" name="startsOn"><input type="date" wire:model.live="startsOn"></x-field>
                @if($duration === 'full')<x-field label="To" name="endsOn"><input type="date" wire:model="endsOn" x-bind:min="$wire.startsOn"></x-field>@endif
                <x-field label="Reason" name="reason" full><textarea wire:model="reason"></textarea></x-field>
            </div><p class="small muted mt">Only your working days are counted — weekly offs (including off Saturdays and moved weekly offs) and holidays are skipped. Half day = 0.5 day, short leave = 0.25 day. Paid leave beyond your balance is allowed only for leave types with advance leave, within the configured limit.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Submit request</button></div></form>
        </x-modal>
    @endif

    @if($deciding)
        <x-modal title="Review leave request">
            <div class="modal-body">
                <x-errors />
                <dl class="kv"><dt>Employee</dt><dd>{{ $deciding->employee?->name }}</dd><dt>Type</dt><dd>{{ $deciding->leaveType?->name }}</dd><dt>Dates</dt><dd>@if($deciding->duration === 'full'){{ $deciding->starts_on->format('d M Y') }} – {{ $deciding->ends_on->format('d M Y') }}@else{{ $deciding->starts_on->format('d M Y') }} · {{ \App\Models\LeaveRequest::DURATION_LABELS[$deciding->duration] ?? 'Full day' }}@endif ({{ (float) $deciding->days }} days)</dd><dt>Reason</dt><dd>{{ $deciding->reason }}</dd></dl>
                <div class="mt"><x-field label="Note" name="decisionNote" hint="required when rejecting"><textarea wire:model="decisionNote" rows="3"></textarea></x-field></div>
            </div>
            <div class="modal-foot"><button class="btn btn-danger" wire:click="decide(false)" wire:loading.attr="disabled">Reject</button><button class="btn btn-success" wire:click="decide(true)" wire:loading.attr="disabled">Approve</button></div>
        </x-modal>
    @endif
</div>
