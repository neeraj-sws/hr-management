<div>
    <div class="page-head">
        <div><h1>Salary Advances</h1><p>Approved advances are disbursed by Accounts and recovered from each payroll in the agreed instalment.</p></div>
        @if($own)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Request advance</button>@endif
    </div>
    <x-errors />
    <div class="stats"><div class="stat"><span class="stat-icon amber"><x-icon name="trending" /></span><div><span class="stat-label">Outstanding to recover</span><span class="stat-value">{{ number_format($outstanding, 2) }}</span><small>{{ $canManage ? 'All employees' : 'Your advances' }}</small></div></div></div>
    <section class="card">
        <div class="toolbar"><label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="disbursed">Disbursed</option><option value="rejected">Rejected</option></select></label></div>
        <div class="table-wrap"><table class="table">
            <thead><tr>@if($canManage)<th>Employee</th>@endif<th>Requested</th><th class="num">Amount</th><th class="num">Disbursed</th><th class="num">Recovered</th><th class="num">Instalment</th><th>Recovery</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($advances as $a)
                @php($pct = (float) $a->disbursed_amount > 0 ? min(100, round((float) $a->recovered_amount / (float) $a->disbursed_amount * 100)) : 0)
                <tr wire:key="adv-{{ $a->id }}">
                    @if($canManage)<td><strong>{{ $a->employee?->name }}</strong></td>@endif
                    <td>{{ $a->requested_on?->format('d M Y') }}<small>{{ $a->reason }}</small></td>
                    <td class="num">{{ number_format((float) $a->amount, 2) }}</td><td class="num">{{ number_format((float) $a->disbursed_amount, 2) }}</td><td class="num">{{ number_format((float) $a->recovered_amount, 2) }}</td><td class="num">{{ number_format((float) $a->installment_amount, 2) }}</td>
                    <td style="min-width:130px"><div class="progress"><span style="width: {{ $pct }}%"></span></div><small>{{ $a->repayments->count() }} payroll deductions</small></td>
                    <td><x-badge :status="$a->status" /></td>
                    <td><div class="row-actions">
                        @if(in_array($a->id, $decidable))<button class="btn btn-sm btn-success" wire:click="decide({{ $a->id }}, true)" wire:confirm="Approve this request?">Approve</button><button class="btn btn-sm btn-danger" wire:click="decide({{ $a->id }}, false)" wire:confirm="Reject this request?">Reject</button>@endif
                        @if($canManage && $a->status === 'approved' && (float) $a->disbursed_amount < (float) $a->amount)<button class="btn btn-sm btn-primary" wire:click="openDisburse({{ $a->id }})">Disburse</button>@endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty title="No salary advances" icon="trending" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $advances->links() }}
    </section>

    @if($showForm)
        <x-modal title="Request salary advance">
            <form wire:submit="submit"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Advance amount" name="amount"><input type="number" step="0.01" min="1" wire:model="amount"></x-field>
                <x-field label="Recovery per payroll" name="installment"><input type="number" step="0.01" min="1" wire:model="installment"></x-field>
                <x-field label="Reason" name="reason" full><input wire:model="reason"></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Submit</button></div></form>
        </x-modal>
    @endif
    @if($disbursing)
        <x-modal title="Disburse advance · {{ $disbursing->employee?->name }}">
            <form wire:submit="disburse"><div class="modal-body"><p class="small muted" style="margin-top:0">Approved {{ number_format((float) $disbursing->amount, 2) }}, already disbursed {{ number_format((float) $disbursing->disbursed_amount, 2) }}.</p>
                <x-field label="Amount to disburse now" name="disburseAmount"><input type="number" step="0.01" min="0.01" wire:model="disburseAmount"></x-field></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Record disbursement</button></div></form>
        </x-modal>
    @endif
</div>
