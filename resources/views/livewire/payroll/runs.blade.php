<div>
    <div class="page-head">
        <div><h1>{{ $approvalsOnly ? 'Payroll Approvals' : 'Payroll' }}</h1><p>Attendance → leave → overtime → penalties → salary → adjustments → approval → lock → payment → payslip.</p></div>
    </div>
    <x-errors />

    @if(!$approvalsOnly && auth()->user()->can('payroll.manage'))
        <section class="card mb">
            <form class="toolbar" wire:submit="calculate" style="border:0">
                <label class="field"><span>Period start</span><input type="date" wire:model="periodStart"></label>
                <label class="field"><span>Period end</span><input type="date" wire:model="periodEnd" x-bind:min="$wire.periodStart"></label>
                <button class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="calculate" class="spinner"></span><x-icon name="refresh" class="icon-sm" />Calculate payroll</button>
                <span class="small muted" style="margin-bottom:8px">Recalculating replaces the draft run. Approved or locked runs cannot be recalculated.</span>
            </form>
        </section>
    @endif

    <div class="grid grid-master grid-master-wide">
        <section class="card">
            <div class="card-head"><h2>Runs</h2></div>
            <div class="list">
                @forelse($runs as $r)
                    <button type="button" class="list-row" wire:click="$set('run', {{ $r->id }})" style="background:{{ $selected?->id === $r->id ? 'var(--blue-soft)' : 'transparent' }};border:0;border-bottom:1px solid var(--line-2);text-align:left;cursor:pointer;width:100%">
                        <div><strong>{{ $r->period_starts_on->format('d M') }} – {{ $r->period_ends_on->format('d M Y') }}</strong><small>{{ $r->items_count }} employees · net {{ number_format((float) $r->items_sum_net_amount, 2) }}</small></div><x-badge :status="$r->status" />
                    </button>
                @empty
                    <x-empty :title="$approvalsOnly ? 'No payroll awaiting approval' : 'No payroll runs yet'" icon="layers" />
                @endforelse
                {{ $runs->links() }}
            </div>
        </section>

        <section class="card">
            @if(!$selected)
                <x-empty title="Select a payroll run" icon="layers">Pick a run on the left to see employee lines, approve, lock and pay.</x-empty>
            @else
                <div class="card-head">
                    <div><h2>{{ $selected->period_starts_on->format('d M Y') }} – {{ $selected->period_ends_on->format('d M Y') }} <x-badge :status="$selected->status" />@if($selected->needs_recalculation) <x-badge tone="red">Inputs changed — recalculate</x-badge>@endif</h2>
                        <p>@if($approval)Approval: {{ $approval->status }}@if($approval->approver) by {{ $approval->approver->name }}@endif @if($approval->comment) — “{{ $approval->comment }}”@endif @endif @if($selected->locked_at)· locked {{ $selected->locked_at->format('d M Y H:i') }}@endif</p></div>
                    <div class="actions">
                        @if($selected->status === 'draft' && auth()->user()->can('payroll.manage'))<button class="btn btn-sm" wire:click="recalculate" wire:loading.attr="disabled">Recalculate</button>@endif
                        @if($canDecide)<button class="btn btn-sm btn-success" wire:click="approve" wire:confirm="Approve this payroll run?">Approve</button>@endif
                        @if($selected->status === 'approved' && auth()->user()->can('payroll.manage'))<button class="btn btn-sm btn-primary" wire:click="lock" wire:confirm="Lock this payroll? Locked payroll cannot be changed.">Lock payroll</button>@endif
                        <a class="btn btn-sm" href="{{ route('hr.reports.export', ['type' => 'payroll', 'from' => $selected->period_starts_on->toDateString(), 'to' => $selected->period_ends_on->toDateString()]) }}"><x-icon name="download" class="icon-sm" />CSV</a>
                    </div>
                </div>
                @if($canDecide)
                    <div class="toolbar"><label class="field grow"><span>Reject with reason</span><input wire:model="rejectNote" placeholder="What needs to be corrected?"></label><button class="btn btn-danger" wire:click="reject">Reject</button></div>
                @endif
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Employee</th><th class="num">Days (work/present/PL/UL)</th><th class="num">OT</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net</th><th class="num">Paid</th><th></th></tr></thead>
                    <tbody>
                    @forelse($selected->items as $item)
                        @php($paid = (float) $item->payments->sum('amount'))
                        @php($deductions = round((float) $item->gross_amount - (float) $item->net_amount, 2))
                        <tr wire:key="pi-{{ $item->id }}">
                            <td><strong>{{ $item->employee?->name }}</strong><small>{{ $item->employee?->employee_code }}</small></td>
                            <td class="num nowrap">{{ $item->working_days }} / {{ (float) $item->present_days }} / {{ (float) $item->paid_leave_days }} / {{ (float) $item->unpaid_leave_days }}</td>
                            <td class="num">{{ number_format((float) $item->overtime_amount, 2) }}<small>{{ (float) $item->overtime_hours }} h</small></td>
                            <td class="num">{{ number_format((float) $item->gross_amount, 2) }}</td>
                            <td class="num">{{ number_format($deductions, 2) }}<small>pen {{ (float) $item->penalties_amount }} · adv {{ (float) $item->recovery_amount }}@if((float) ($item->calculation_breakdown['short_hours']['amount'] ?? 0) > 0) · short {{ \App\Support\Duration::hms($item->calculation_breakdown['short_hours']['short_seconds']) }}@endif</small></td>
                            <td class="num"><strong>{{ number_format((float) $item->net_amount, 2) }}</strong></td>
                            <td class="num">{{ number_format($paid, 2) }}@if($paid > 0 && $paid < (float) $item->net_amount)<small>partial</small>@endif</td>
                            <td><div class="row-actions">
                                <button class="btn btn-sm" wire:click="$set('breakdownItemId', {{ $item->id }})">Breakdown</button>
                                @if($selected->status === 'locked' && $paid < (float) $item->net_amount && auth()->user()->can('payroll.manage'))<button class="btn btn-sm btn-primary" wire:click="openPayment({{ $item->id }})">Pay</button>@endif
                                @if($item->payslip)<a class="btn btn-sm" href="{{ route('hr.payslips.show', $item->payslip) }}" target="_blank">Payslip</a>@endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty title="No employees in this run" icon="users">Only active employees with a salary profile are included.</x-empty></td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            @endif
        </section>
    </div>

    @if($paying)
        <x-modal title="Record payment · {{ $paying->employee?->name }}">
            <form wire:submit="pay"><div class="modal-body"><x-errors />
                <p class="small muted" style="margin-top:0">Net {{ number_format((float) $paying->net_amount, 2) }} · already paid {{ number_format((float) $paying->payments->sum('amount'), 2) }}. Partial payments are allowed.</p>
                <div class="form-grid"><x-field label="Amount" name="paymentAmount"><input type="number" step="0.01" min="0.01" wire:model="paymentAmount"></x-field><x-field label="Bank / reference" name="paymentReference"><input wire:model="paymentReference"></x-field></div>
            </div><div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Record payment</button></div></form>
        </x-modal>
    @endif
    @if($breakdown)
        <x-modal title="Calculation · {{ $breakdown->employee?->name }}">
            <div class="modal-body"><table class="table"><tbody>
                @foreach(($breakdown->calculation_breakdown ?? []) as $label => $value)
                    @if(is_scalar($value))<tr><td>{{ str($label)->replace('_', ' ')->title() }}</td><td class="num">{{ is_numeric($value) ? (fmod((float) $value, 1) ? number_format((float) $value, 2) : $value) : $value }}</td></tr>@endif
                    @if($label === 'statutory_components' && is_array($value))@foreach($value as $c)<tr><td>{{ $c['name'] ?? $c['key'] }} ({{ $c['type'] ?? '' }})</td><td class="num">{{ number_format((float) ($c['amount'] ?? 0), 2) }}</td></tr>@endforeach @endif
                @endforeach
            </tbody></table></div>
            <div class="modal-foot"><button class="btn" wire:click="closeModal">Close</button></div>
        </x-modal>
    @endif
</div>
