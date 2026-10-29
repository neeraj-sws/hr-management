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

    {{-- Status cards: my own, waiting for my decision, and everyone I may see (with counts). --}}
    @php($statusCards = array_values(array_filter([
        $own ? ['label' => 'My requests', 'count' => $tabCounts['mine'], 'tone' => 'blue', 'active' => $tab === 'mine', 'click' => "\$set('tab','mine')"] : null,
        $canApprove ? ['label' => 'Awaiting my approval', 'count' => $pendingCount, 'tone' => 'amber', 'active' => $tab === 'approvals', 'click' => "\$set('tab','approvals')"] : null,
        $canApprove ? ['label' => auth()->user()->can('leave.manage') ? 'All requests' : 'Team requests', 'count' => $tabCounts['all'], 'tone' => 'gray', 'active' => $tab === 'all', 'click' => "\$set('tab','all')"] : null,
    ])))
    <x-status-cards label="Requests" :items="$statusCards" />

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
                    <td><x-badge :status="$leave->status" />@if($leave->isBackdated())<x-badge tone="amber" title="Asked for on {{ $leave->created_at->format('d M') }}, after the day">Old date</x-badge>@endif@if($leave->decider)<small>by {{ $leave->decider->name }}</small>@endif @foreach($leave->conflictResolutions as $settled)<small style="color:{{ $settled->resolution === 'worked' ? 'var(--green)' : 'var(--muted)' }}">{{ $settled->work_date->format('d M') }}: {{ $settled->resolution === 'worked' ? 'worked — counted as a working day'.($leave->leaveType?->paid ? ', leave day returned to balance' : '') : 'counted as leave' }}</small>@endforeach</td>
                    <td><div class="row-actions">
                        @if($tab === 'approvals')<button class="btn btn-sm btn-primary" wire:click="openDecision({{ $leave->id }})">Review</button>@endif
                        @if($canChangeDecisions && $tab !== 'mine' && in_array($leave->status, ['approved', 'rejected'], true))<button class="btn btn-sm" wire:click="openChange({{ $leave->id }})" title="Approved or rejected by mistake? Change it, with a reason">Change decision</button>@endif
                        @if($tab === 'mine' && $leave->status === 'pending')<button class="btn btn-sm" wire:click="openEdit({{ $leave->id }})" title="Change the leave type or the reason while it waits for a decision">Edit</button>@endif
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
            <form wire:submit="submit"><div class="modal-body"><x-errors />@if($payrollNotice)<div class="alert alert-warn">{{ $payrollNotice }}</div>@endif<div class="form-grid">
                <x-field label="Leave type" name="leaveTypeId" full><select wire:model.live="leaveTypeId"><option value="">Select type</option>@foreach($balances as $b)<option value="{{ $b['type']->id }}">{{ $b['type']->name }} — {{ $b['type']->paid ? round($b['remaining'], 2).' day(s) available' : 'unpaid' }}</option>@endforeach</select></x-field>
                <div class="full small muted" style="margin-top:-6px">
                    @foreach($balances as $b)
                        <div @class(['muted' => (int) $leaveTypeId !== $b['type']->id]) style="{{ (int) $leaveTypeId === $b['type']->id ? 'color:var(--ink-2)' : '' }}"><strong>{{ $b['type']->name }}</strong>: {{ $b['type']->describe() }}@if($b['type']->paid) · available {{ round($b['remaining'], 2) }}, pending {{ $b['booked'] }}@endif</div>
                    @endforeach
                </div>
                <x-field label="Duration" name="duration" full><select wire:model.live="dayChoice"><option value="one">Full day — one day</option><option value="several">Full day — several days</option><option value="half">Half day</option><option value="short">Short leave (part of a day)</option></select></x-field>
                <x-field :label="$dayChoice === 'several' ? 'From' : 'Date'" name="startsOn"><input type="date" wire:model.live="startsOn" min="{{ today()->subMonthNoOverflow()->startOfMonth()->toDateString() }}"></x-field>
                @if($startsOn && $startsOn < today()->toDateString())<p class="small" style="grid-column:1/-1;margin:0;color:#9a6412">This is a past date ("Old date"): your approver must give a reason to approve it, and a late-leave fine may apply.</p>@endif
                @if($dayChoice === 'several')<x-field label="To" name="endsOn"><input type="date" wire:model.live="endsOn" x-bind:min="$wire.startsOn || @js(today()->subMonthNoOverflow()->startOfMonth()->toDateString())"></x-field>@endif
                <x-field label="Reason" name="reason" full><textarea wire:model="reason"></textarea></x-field>
            </div><p class="small muted mt">Only your working days are counted — weekly offs (including off Saturdays and moved weekly offs) and holidays are skipped. Half day = 0.5 day, short leave = 0.25 day. Paid leave beyond your balance is allowed only for leave types with advance leave, within the configured limit.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Submit request</button></div></form>
        </x-modal>
    @endif

    @if($editing)
        {{-- Employee, while still pending: another leave type (e.g. Casual -> Sick) or a better reason. --}}
        <x-modal title="Edit leave request">
            <form wire:submit="saveEdit"><div class="modal-body"><x-errors />
                <dl class="kv"><dt>Dates</dt><dd>{{ $editing->starts_on->format('d M Y') }} – {{ $editing->ends_on->format('d M Y') }} ({{ (float) $editing->days }} days)</dd><dt>Now</dt><dd>{{ $editing->leaveType?->name }}</dd></dl>
                <div class="form-grid mt">
                    <x-field label="Leave type" name="editTypeId"><select wire:model="editTypeId">@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}{{ $t->paid ? '' : ' (unpaid)' }}</option>@endforeach</select></x-field>
                    <x-field label="Reason" name="editReason" full><textarea rows="3" wire:model="editReason"></textarea></x-field>
                </div>
                <p class="small muted">Only while it waits for a decision. The balance of the new type is checked; your approver is told. To change the dates, cancel this request and apply again.</p>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif
    @if($changing)
        {{-- Approved / rejected by mistake: the other decision, with a reason; balance, attendance, fines and salary follow. --}}
        <x-modal title="Change leave decision">
            <form wire:submit="saveChange"><div class="modal-body"><x-errors />
                <dl class="kv"><dt>Employee</dt><dd>{{ $changing->employee?->name }}</dd><dt>Type</dt><dd>{{ $changing->leaveType?->name }}</dd><dt>Dates</dt><dd>{{ $changing->starts_on->format('d M Y') }} – {{ $changing->ends_on->format('d M Y') }} ({{ (float) $changing->days }} days)</dd>
                    <dt>Now</dt><dd><x-badge :status="$changing->status" /> @if($changing->decider)<small>by {{ $changing->decider->name }}, {{ $changing->decided_at?->format('d M Y') }}</small>@endif</dd>
                    <dt>Change to</dt><dd><x-badge :status="$changing->status === 'approved' ? 'rejected' : 'approved'" /></dd></dl>
                <p class="small muted mt">{{ $changing->status === 'approved' ? 'Rejected: the days go back to the leave balance and become absent (not paid; a fine only if the absence rule says so).' : 'Approved: the days are taken from the leave balance and become on leave (paid for a paid leave type); an absence fine for them is removed.' }} If that salary is already processed, the difference goes into the next salary. The employee, their manager and HR are told.</p>
                <x-field label="Why is the decision changed?" name="changeReason" full><textarea rows="3" wire:model="changeReason" placeholder="e.g. Approved by mistake - the project deadline was that week"></textarea></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn {{ $changing->status === 'approved' ? 'btn-danger' : 'btn-success' }}" wire:loading.attr="disabled">{{ $changing->status === 'approved' ? 'Change to Rejected' : 'Change to Approved' }}</button></div></form>
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
