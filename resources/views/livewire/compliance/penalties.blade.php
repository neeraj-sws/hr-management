<div>
    <div class="page-head"><div><h1>Penalties</h1><p>{{ $canManage ? 'Policy-generated and manual penalties. Approved penalties are deducted in the next payroll covering their date.' : 'Penalties recorded against you.' }}</p></div>
        @if($canManage)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Manual penalty</button>@endif</div>
    <x-errors />
    <div class="stats">
        <div class="stat"><span class="stat-icon amber"><x-icon name="hourglass" /></span><div><span class="stat-label">Pending review</span><span class="stat-value">{{ number_format($totals['pending'], 2) }}</span></div></div>
        <div class="stat"><span class="stat-icon red"><x-icon name="alert" /></span><div><span class="stat-label">Approved</span><span class="stat-value">{{ number_format($totals['approved'], 2) }}</span></div></div>
    </div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="waived">Removed / waived</option></select></label>
            @if($canManage)<label class="field grow"><span>Employee</span><select wire:model.live="employee"><option value="">All employees</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>@endif
            <label class="field"><span>Month</span><input type="month" wire:model.live="month"></label>
        </div>
        {{-- HR / admin: tick fines (or all that match the filters) and take them back with one reason. --}}
        @if($canManage)
            <div class="toolbar" style="border-top:0;padding-top:0;gap:8px;align-items:center">
                <button type="button" class="btn btn-sm" wire:click="selectAllMatching">Select all matching</button>
                @if($selected)
                    <span class="small"><b>{{ count($selected) }}</b> selected · Rs {{ number_format($selectedTotal, 2) }}</span>
                    <button type="button" class="btn btn-sm" wire:click="clearSelection">Clear</button>
                    <button type="button" class="btn btn-sm btn-danger" wire:click="openBulk">Take back selected</button>
                @else
                    <span class="small muted">Tick fines below, or filter (e.g. employee + month) and "Select all matching".</span>
                @endif
            </div>
        @endif
        <div class="table-wrap"><table class="table">
            <thead><tr>@if($canManage)<th style="width:28px"></th><th>Employee</th>@endif<th>Date</th><th>Reason</th><th>Source</th><th class="num">Amount</th><th>Status</th><th>Payroll</th><th></th></tr></thead>
            <tbody>
            @forelse($penalties as $p)
                <tr wire:key="pen-{{ $p->id }}">@if($canManage)<td>@if($p->canBeRemoved())<input type="checkbox" value="{{ $p->id }}" wire:model.live="selected" aria-label="Select this fine">@endif</td><td><strong>{{ $p->employee?->name }}</strong></td>@endif
                    <td class="nowrap">{{ $p->effective_on->format('d M Y') }}</td>
                    {{-- The whole story on both sides: why it was made, the employee's reason, and why it stays or was removed. --}}
                    <td style="white-space:normal"><div><b>Why applied:</b> {{ $p->reason }}</div>
                        @if($p->appeal)
                            <div class="small mt"><b>Employee's reason</b> ({{ $p->appeal->created_at->format('d M, h:i A') }}): "{{ $p->appeal->reason }}"</div>
                            @if($p->appeal->status === 'pending')
                                <div class="small"><x-badge status="pending">Reason waiting for HR</x-badge></div>
                            @elseif($p->appeal->status === 'approved')
                                <div class="small" style="color:var(--green)"><b>Reverted:</b> {{ $p->appeal->decided_by ? 'reason accepted by '.($p->appeal->decider?->name ?? 'HR') : 'automatically, the missed daily status was written' }} on {{ $p->appeal->decided_at?->format('d M Y') }}@if($p->appeal->refund_adjustment_id) · the fine is paid back in the next payroll (that payroll was already locked)@else · the fine is removed @endif @if($p->appeal->decision_note) · Note: {{ $p->appeal->decision_note }}@endif</div>
                            @else
                                <div class="small" style="color:var(--red)"><b>Reason not accepted</b> by {{ $p->appeal->decider?->name ?? 'HR' }} on {{ $p->appeal->decided_at?->format('d M Y') }}: {{ $p->appeal->decision_note }} · the fine stays</div>
                            @endif
                        @elseif($p->decision_note && $p->decided_at)
                            <div class="small mt"><b>{{ ['waived' => 'Why removed', 'rejected' => 'Why rejected'][$p->status] ?? ($p->isPaidBack() ? 'Taken back' : 'Note') }}</b> ({{ $p->decider?->name ?? 'HR' }}, {{ $p->decided_at->format('d M Y') }}): {{ $p->decision_note }}</div>
                        @endif
                    </td>
                    <td><x-badge :tone="$p->policyRule ? 'violet' : 'blue'">{{ $p->policyRule ? 'policy' : 'manual' }}</x-badge></td>
                    <td class="num">{{ number_format((float) $p->amount, 2) }}</td><td><x-badge :status="$p->status" /></td>
                    <td class="small">{{ $p->payrollItem?->run ? $p->payrollItem->run->period_starts_on->format('M Y') : '—' }}</td>
                    <td><div class="row-actions">@if(in_array($p->id, $decidable))<button class="btn btn-sm btn-success" wire:click="decide({{ $p->id }}, true)" wire:confirm="Apply this penalty? It will be deducted in payroll.">Approve</button><button class="btn btn-sm" wire:click="openAction({{ $p->id }}, 'reduce')">Reduce</button><button class="btn btn-sm" wire:click="openAction({{ $p->id }}, 'reject')">Reject</button>@endif@if(auth()->user()->can('penalties.manage') && in_array($p->status, ['pending', 'approved'], true))<button class="btn btn-sm btn-danger" wire:click="openAction({{ $p->id }}, 'waive')">Waive</button>@endif@if($p->appeal && in_array($p->appeal->id, $reviewable))<button class="btn btn-sm btn-success" wire:click="openReview({{ $p->appeal->id }}, true)" title="The fine is removed">Accept reason</button><button class="btn btn-sm" wire:click="openReview({{ $p->appeal->id }}, false)" title="The fine stays">Don't accept</button>@endif@if($p->employee_id === $ownId && $p->canAppeal())<button class="btn btn-sm btn-primary" wire:click="openAppeal({{ $p->id }})">Give reason</button>@endif</div></td></tr>
            @empty
                <tr><td colspan="9"><x-empty title="No penalties" icon="shield" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $penalties->links() }}
    </section>
    @if($showForm)
        <x-modal title="Manual penalty">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Employee" name="employeeId" full><select wire:model="employeeId"><option value="">Select</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>
                <x-field label="Amount" name="amount"><input type="number" step="0.01" min="0.01" wire:model="amount"></x-field>
                <x-field label="Reason" name="reason"><input wire:model="reason"></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Submit for approval</button></div></form>
        </x-modal>
    @endif
    @if($actingId)
        <x-modal :title="['reduce' => 'Approve a reduced penalty', 'reject' => 'Reject penalty', 'waive' => 'Waive penalty'][$acting]">
            <form wire:submit="act"><div class="modal-body"><div class="form-grid">
                @if($acting === 'reduce')<x-field label="New amount" name="actAmount"><input type="number" step="0.01" min="0.01" wire:model="actAmount"></x-field>@endif
                <x-field label="Reason" name="actNote" full><input wire:model="actNote" placeholder="e.g. First time, train delayed"></x-field>
            </div>@if($acting === 'waive')<p class="small muted">If the penalty is already in a draft payroll run, the run must be recalculated before approval. Penalties in an approved or locked payroll cannot be waived.</p>@endif</div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif
    @if($bulkOpen)
        <x-modal title="Take back {{ count($selected) }} fine(s)">
            <form wire:submit="saveBulk"><div class="modal-body"><x-errors />
                <p class="small">Rs {{ number_format($selectedTotal, 2) }} in all. Each fine is removed and keeps this reason in its history; one whose salary is already paid (locked payroll) is paid back in the next salary. Each employee is told once.</p>
                <x-field label="Why are they taken back?" name="bulkNote" full><textarea rows="3" wire:model="bulkNote" placeholder="e.g. Warning given on 29 Oct; October late fines taken back"></textarea></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-danger" wire:loading.attr="disabled">Take back</button></div></form>
        </x-modal>
    @endif
    @if($appealingId)
        <x-modal title="Give your reason">
            <form wire:submit="saveAppeal"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Your reason" name="appealReason" full><textarea rows="4" wire:model="appealReason" placeholder="e.g. Train was 40 minutes late; was ill and sent the leave mail late; the HRMS was down when I tried"></textarea></x-field>
            </div><p class="small muted">HR or an admin reads it. If they accept it, the fine is removed; if not, they tell you why. You can give one reason per fine.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Send to HR</button></div></form>
        </x-modal>
    @endif
    @if($reviewingId)
        <x-modal :title="$reviewAccept ? 'Accept the reason (remove the fine)' : 'Don\'t accept the reason (the fine stays)'">
            <form wire:submit="saveReview"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field :label="$reviewAccept ? 'Note to the employee (optional)' : 'Why it is not accepted'" name="reviewNote" full><input wire:model="reviewNote"></x-field>
            </div>@if($reviewAccept)<p class="small muted">If that payroll is already locked, the fine is paid back in the next payroll.</p>@endif</div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif
</div>
