<div>
    <div class="page-head"><div><h1>Final Settlement</h1><p>Calculated from actual records: unpaid salary since the last locked payroll, approved overtime, adjustments, configured leave encashment, penalties, outstanding advances and unpaid locked payroll.</p></div></div>
    <x-errors />
    <div class="grid grid-master">
        <section class="card">
            <div class="card-head"><h2>Offboarding cases</h2></div>
            <div class="list">
                @forelse($cases as $c)
                    <button type="button" class="list-row" wire:click="$set('case', {{ $c->id }})" style="background:{{ $selected?->id === $c->id ? 'var(--blue-soft)' : 'transparent' }};border:0;border-bottom:1px solid var(--line-2);text-align:left;cursor:pointer;width:100%">
                        <div><strong>{{ $c->employee?->name }}</strong><small>last day {{ $c->effective_on?->format('d M Y') ?? '—' }}</small></div>
                        @if($c->settlement)<x-badge :status="$c->settlement->status" />@else<x-badge tone="draft">not prepared</x-badge>@endif
                    </button>
                @empty
                    <x-empty title="No offboarding cases" icon="user-minus"><a href="{{ route('lifecycle.offboarding') }}">Open an offboarding case</a></x-empty>
                @endforelse
            </div>
        </section>
        <section class="card">
            @if(!$selected)
                <x-empty title="Select an offboarding case" icon="receipt" />
            @else
                <div class="card-head">
                    <div><h2>{{ $selected->employee->name }}</h2><p>Case {{ $selected->status }} · as of {{ $settlement?->as_of_date?->format('d M Y') ?? $selected->effective_on?->format('d M Y') }}</p></div>
                    <div class="actions">
                        @if(auth()->user()->can('payroll.manage') && (!$settlement || in_array($settlement->status, ['draft', 'pending', 'rejected'], true)))
                            <button class="btn btn-sm btn-primary" wire:click="prepare" @disabled(!$clearancesDone) wire:loading.attr="disabled">{{ $settlement ? 'Recalculate' : 'Calculate settlement' }}</button>
                        @endif
                        @if($canDecide)<button class="btn btn-sm btn-success" wire:click="approve" wire:confirm="Approve this final settlement?">Approve</button>@endif
                        <a class="btn btn-sm" href="{{ route('lifecycle.offboarding', ['case' => $selected->id]) }}">Checklist</a>
                    </div>
                </div>
                @if(!$clearancesDone)<div class="alert alert-info" style="margin:14px 18px 0">Complete all clearance items in the offboarding checklist before calculating.</div>@endif
                @if($canDecide)<div class="toolbar"><label class="field grow"><span>Reject with reason</span><input wire:model="rejectNote"></label><button class="btn btn-danger" wire:click="reject">Reject</button></div>@endif
                @if($settlement)
                    <div class="card-body grid grid-2">
                        <div>
                            <div class="stats stats-3" style="margin-bottom:12px">
                                <div class="stat" style="padding:12px"><div><span class="stat-label">Gross</span><span class="stat-value" style="font-size:18px">{{ number_format((float) $settlement->gross_amount, 2) }}</span></div></div>
                                <div class="stat" style="padding:12px"><div><span class="stat-label">Deductions</span><span class="stat-value" style="font-size:18px">{{ number_format((float) $settlement->deductions, 2) }}</span></div></div>
                                <div class="stat" style="padding:12px"><div><span class="stat-label">Net</span><span class="stat-value" style="font-size:18px">{{ number_format((float) $settlement->net_amount, 2) }}</span></div></div>
                            </div>
                            <p class="small">Status <x-badge :status="$settlement->status" /> @if($approval?->approver)· decided by {{ $approval->approver->name }}@endif @if($approval?->comment)· “{{ $approval->comment }}”@endif</p>
                            <p class="eyebrow mt">Payments</p>
                            @forelse($settlement->payments as $p)<div class="list-row" style="padding:6px 0"><span>{{ $p->paid_on->format('d M Y') }} · {{ $p->reference ?? '—' }}</span><strong>{{ number_format((float) $p->amount, 2) }}</strong></div>@empty<p class="small muted">No payments yet.</p>@endforelse
                            @if(in_array($settlement->status, ['approved', 'partially_paid'], true) && auth()->user()->can('payroll.manage'))
                                <form class="form-grid mt" wire:submit="pay">
                                    <x-field label="Amount" name="paymentAmount"><input type="number" step="0.01" min="0.01" wire:model="paymentAmount"></x-field>
                                    <x-field label="Reference" name="paymentReference"><input wire:model="paymentReference"></x-field>
                                    <div class="full"><button class="btn btn-primary">Record payment</button></div>
                                </form>
                            @endif
                        </div>
                        <div>
                            <p class="eyebrow">Calculation breakdown</p>
                            <table class="table"><tbody>
                                @foreach(($settlement->calculation_breakdown ?? []) as $label => $value)
                                    @if(is_scalar($value) || $value === null)<tr><td>{{ str($label)->replace('_', ' ')->ucfirst() }}</td><td class="num">{{ is_bool($value) ? ($value ? 'yes' : 'no') : (is_numeric($value) && fmod((float) $value, 1) ? number_format((float) $value, 2) : ($value ?? '—')) }}</td></tr>@endif
                                @endforeach
                            </tbody></table>
                        </div>
                    </div>
                @else
                    <x-empty title="Settlement not calculated yet" icon="receipt" />
                @endif
            @endif
        </section>
    </div>
</div>
