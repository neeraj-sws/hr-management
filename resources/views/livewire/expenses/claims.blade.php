<div>
    <div class="page-head">
        <div><h1>Expense Claims</h1><p>Claim work expenses with a receipt. Approved claims are paid with your next salary as a reimbursement.</p></div>
        @if($own)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New claim</button>@endif
    </div>
    @if($totals)
        <div class="stats">
            <div class="stat"><span class="stat-icon amber"><x-icon name="hourglass" /></span><div><span class="stat-label">Waiting for approval</span><span class="stat-value">{{ number_format($totals['pending'], 2) }}</span></div></div>
            <div class="stat"><span class="stat-icon"><x-icon name="wallet" /></span><div><span class="stat-label">Approved, to be paid</span><span class="stat-value">{{ number_format($totals['to_be_paid'], 2) }}</span><small>with next payroll</small></div></div>
            <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Reimbursed this year</span><span class="stat-value">{{ number_format($totals['paid_year'], 2) }}</span></div></div>
        </div>
    @endif
    {{-- Status cards: my own, waiting for my decision, and everyone I may see (with counts). --}}
    @php($statusCards = array_values(array_filter([
        $own ? ['label' => 'My claims', 'count' => $tabCounts['mine'], 'tone' => 'blue', 'active' => $tab === 'mine', 'click' => "\$set('tab','mine')"] : null,
        $canApprove ? ['label' => 'Awaiting my approval', 'count' => $pendingCount, 'tone' => 'amber', 'active' => $tab === 'approvals', 'click' => "\$set('tab','approvals')"] : null,
        $canApprove ? ['label' => 'All claims', 'count' => $tabCounts['all'], 'tone' => 'gray', 'active' => $tab === 'all', 'click' => "\$set('tab','all')"] : null,
    ])))
    <x-status-cards label="Requests" :items="$statusCards" />
    <section class="card">
        <div class="card-head"><div><h2>{{ $tab === 'approvals' ? 'Waiting for you' : 'Claims' }}</h2></div>
            @if($tab !== 'approvals')<select wire:model.live="status" class="input" style="width:auto" aria-label="Filter by status"><option value="">All statuses</option><option value="pending">Pending</option><option value="approved">Approved (to be paid)</option><option value="paid">Paid</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option></select>@endif
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr>@if($tab !== 'mine')<th>Employee</th>@endif<th>Date</th><th>Category</th><th>Description</th><th class="num">Amount</th><th>Receipt</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($claims as $c)
                <tr wire:key="exp-{{ $c->id }}">
                    @if($tab !== 'mine')<td><strong>{{ $c->employee?->name }}</strong><small>{{ $c->employee?->employee_code }}</small></td>@endif
                    <td class="nowrap">{{ $c->spent_on->format('d M Y') }}</td>
                    <td>{{ $c->category }}</td>
                    <td style="white-space:normal;max-width:280px">{{ $c->description }}@if($c->decision_note)<small>Note: {{ $c->decision_note }}</small>@endif</td>
                    <td class="num">{{ number_format((float) $c->amount, 2) }}</td>
                    <td>@if($c->receipt_path)<a href="{{ route('expenses.receipt', $c) }}" class="btn btn-sm"><x-icon name="download" class="icon-sm" />View</a>@else<span class="muted small">none</span>@endif</td>
                    <td><x-badge :status="$c->displayStatus()" />@if($c->decider)<small>by {{ $c->decider->name }}</small>@endif</td>
                    <td>
                        @if($tab === 'approvals')<button class="btn btn-sm btn-primary" wire:click="openDecision({{ $c->id }})">Review</button>
                        @elseif($tab === 'mine' && $c->status === 'pending')<button class="btn btn-sm" wire:click="cancel({{ $c->id }})" wire:confirm="Cancel this claim?">Cancel</button>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><x-empty title="No expense claims" icon="receipt" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $claims->links() }}
    </section>

    @if($showForm)
        <x-modal title="New expense claim">
            <form wire:submit="submit"><div class="modal-body"><div class="form-grid">
                <x-field label="Category" name="category"><select wire:model="category">@foreach($categories as $cat)<option>{{ $cat }}</option>@endforeach</select></x-field>
                <x-field label="Date of expense" name="spentOn"><input type="date" wire:model="spentOn" max="{{ today()->toDateString() }}"></x-field>
                <x-field label="Amount" name="amount"><input type="number" step="0.01" min="0.01" wire:model="amount" placeholder="0.00"></x-field>
                <x-field label="Receipt" name="receipt" hint="photo or PDF, up to 5 MB"><input type="file" wire:model="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"></x-field>
                <x-field label="What was it for?" name="description" full><textarea wire:model="description" placeholder="e.g. Cab to client office for the quarterly review"></textarea></x-field>
            </div>
            <div wire:loading wire:target="receipt" class="small muted">Uploading receipt…</div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled" wire:target="submit,receipt">Submit claim</button></div></form>
        </x-modal>
    @endif
    @if($deciding)
        <x-modal title="Review expense claim">
            <div class="modal-body">
                <dl class="kv">
                    <dt>Employee</dt><dd>{{ $deciding->employee?->name }}</dd>
                    <dt>Date</dt><dd>{{ $deciding->spent_on->format('d M Y') }}</dd>
                    <dt>Category</dt><dd>{{ $deciding->category }}</dd>
                    <dt>Amount</dt><dd><strong>{{ number_format((float) $deciding->amount, 2) }}</strong></dd>
                    <dt>Description</dt><dd>{{ $deciding->description }}</dd>
                    <dt>Receipt</dt><dd>@if($deciding->receipt_path)<a href="{{ route('expenses.receipt', $deciding) }}" target="_blank" rel="noopener">{{ $deciding->receipt_name ?? 'View receipt' }}</a>@else <span class="muted">No receipt attached</span>@endif</dd>
                </dl>
                <div class="form-grid mt"><x-field label="Note" name="decisionNote" hint="required to reject" full><input wire:model="decisionNote"></x-field></div>
            </div>
            <div class="modal-foot"><button class="btn btn-danger" wire:click="decide(false)">Reject</button><button class="btn btn-success" wire:click="decide(true)" wire:confirm="Approve this claim for reimbursement?">Approve</button></div>
        </x-modal>
    @endif
</div>
