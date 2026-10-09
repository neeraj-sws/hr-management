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
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></label>
            @if($canManage)<label class="field grow"><span>Employee</span><select wire:model.live="employee"><option value="">All employees</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>@endif
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr>@if($canManage)<th>Employee</th>@endif<th>Date</th><th>Reason</th><th>Source</th><th class="num">Amount</th><th>Status</th><th>Payroll</th><th></th></tr></thead>
            <tbody>
            @forelse($penalties as $p)
                <tr>@if($canManage)<td><strong>{{ $p->employee?->name }}</strong></td>@endif
                    <td class="nowrap">{{ $p->effective_on->format('d M Y') }}</td><td style="white-space:normal">{{ $p->reason }}</td>
                    <td><x-badge :tone="$p->policyRule ? 'violet' : 'blue'">{{ $p->policyRule ? 'policy' : 'manual' }}</x-badge></td>
                    <td class="num">{{ number_format((float) $p->amount, 2) }}</td><td><x-badge :status="$p->status" /></td>
                    <td class="small">{{ $p->payrollItem?->run ? $p->payrollItem->run->period_starts_on->format('M Y') : '—' }}</td>
                    <td><div class="row-actions">@if(in_array($p->id, $decidable))<button class="btn btn-sm btn-success" wire:click="decide({{ $p->id }}, true)" wire:confirm="Apply this penalty? It will be deducted in payroll.">Approve</button><button class="btn btn-sm" wire:click="openAction({{ $p->id }}, 'reduce')">Reduce</button><button class="btn btn-sm" wire:click="openAction({{ $p->id }}, 'reject')">Reject</button>@endif@if(auth()->user()->can('penalties.manage') && in_array($p->status, ['pending', 'approved'], true))<button class="btn btn-sm btn-danger" wire:click="openAction({{ $p->id }}, 'waive')">Waive</button>@endif</div></td></tr>
            @empty
                <tr><td colspan="8"><x-empty title="No penalties" icon="shield" /></td></tr>
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
</div>
