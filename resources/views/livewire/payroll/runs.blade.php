<div>
    <div class="page-head">
        <div><h1>{{ $approvalsOnly ? 'Payroll Approvals' : 'Payroll' }}</h1><p>Attendance → leave → {{ app(\App\Services\PeopleOperationsService::class)->overtimeEnabled() ? 'overtime → ' : '' }}penalties → salary → adjustments → approval → lock → payment → payslip.</p></div>
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
                        @if($selected->status === 'draft' && auth()->user()->can('payroll.manage'))<button class="btn btn-sm" wire:click="recalculate" wire:loading.attr="disabled">Recalculate</button><button class="btn btn-sm" wire:click="openCompare" title="This draft against a fresh calculation now: half days and salary for days worked. Nothing is saved.">Compare</button><button class="btn btn-sm btn-danger" wire:click="openDelete">Delete draft</button>@endif
                        @if($canDecide)<button class="btn btn-sm btn-success" wire:click="approve" wire:confirm="Approve this payroll run?">Approve</button>@endif
                        @if($selected->status === 'approved' && auth()->user()->can('payroll.manage'))<button class="btn btn-sm btn-primary" wire:click="lock" wire:confirm="Lock this payroll? Locked payroll cannot be changed.">Lock payroll</button>@endif
                        @if(in_array($selected->status, ['approved', 'locked'], true) && \App\Support\Access::isAdmin(auth()->user()))<button class="btn btn-sm btn-danger" wire:click="openReopen">Reopen</button>@endif
                        <a class="btn btn-sm" href="{{ route('hr.reports.export', ['type' => 'payroll', 'from' => $selected->period_starts_on->toDateString(), 'to' => $selected->period_ends_on->toDateString()]) }}"><x-icon name="download" class="icon-sm" />CSV</a>
                    </div>
                </div>
                @if($canDecide)
                    <div class="toolbar"><label class="field grow"><span>Reject with reason</span><input wire:model="rejectNote" placeholder="What needs to be corrected?"></label><button class="btn btn-danger" wire:click="reject">Reject</button></div>
                @endif
                @php($runHasOt = $selected->items->contains(fn ($i) => (float) $i->overtime_amount > 0 || (float) $i->overtime_hours > 0))
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Employee</th><th class="num">Days (work/present/PL/UL)</th>@if($runHasOt)<th class="num">OT</th>@endif<th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net</th><th class="num">Paid</th><th></th></tr></thead>
                    <tbody>
                    @forelse($selected->items->sortBy(fn ($i) => sprintf('%04d%s', strlen((string) $i->employee?->employee_code), $i->employee?->employee_code)) as $item)
                        {{-- A month from the old salary sheet: what the sheet says was paid counts as paid. --}}
                        @php($sheetPaid = (float) ($item->calculation_breakdown['old_sheet']['paid'] ?? 0))
                        @php($paid = (float) $item->payments->sum('amount') + $sheetPaid)
                        {{-- Reimbursements (approved expense claims) are paid on top of net, so they are not a deduction. --}}
                        @php($claimsPaid = (float) $item->reimbursements_amount)
                        @php($deductions = round((float) $item->gross_amount - (float) $item->net_amount + $claimsPaid, 2))
                        <tr wire:key="pi-{{ $item->id }}">
                            <td><strong>{{ $item->employee?->name }}</strong><small>{{ $item->employee?->employee_code }}</small></td>
                            <td class="num nowrap">{{ $item->working_days }} / {{ (float) $item->present_days }} / {{ (float) $item->paid_leave_days }} / {{ (float) $item->unpaid_leave_days }}</td>
                            @if($runHasOt)<td class="num">{{ number_format((float) $item->overtime_amount, 2) }}<small>{{ (float) $item->overtime_hours }} h</small></td>@endif
                            <td class="num">{{ number_format((float) $item->gross_amount, 2) }}</td>
                            <td class="num">{{ number_format($deductions, 2) }}<small>pen {{ (float) $item->penalties_amount }} · adv {{ (float) $item->recovery_amount }}@if($claimsPaid > 0) · <span style="color:var(--green, #1E7A41)">+ claims {{ number_format($claimsPaid, 2) }} in net</span>@endif@if((float) ($item->calculation_breakdown['short_hours']['amount'] ?? 0) > 0) · short {{ \App\Support\Duration::hms($item->calculation_breakdown['short_hours']['short_seconds']) }}@endif</small></td>
                            <td class="num"><strong>{{ number_format((float) $item->net_amount, 2) }}</strong></td>
                            <td class="num">{{ number_format($paid, 2) }}@if($sheetPaid > 0)<small>old sheet{{ ! empty($item->calculation_breakdown['old_sheet']['payment_date']) ? ' · '.\Illuminate\Support\Carbon::parse($item->calculation_breakdown['old_sheet']['payment_date'])->format('d M Y') : '' }}</small>@elseif($paid > 0 && $paid < (float) $item->net_amount)<small>partial</small>@endif</td>
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
            @php($cd = $breakdown->calculation_data ?? [])
            @php($monthly = (float) ($cd['monthly_salary'] ?? 0))
            @php($days = (int) ($breakdown->calculation_breakdown['working_days'] ?? $breakdown->working_days ?? 0))
            @if(! empty($breakdown->calculation_breakdown['old_sheet']))
                @php($os = $breakdown->calculation_breakdown['old_sheet'])
                <div class="modal-body">
                    <div class="alert alert-info" style="margin-top:0">Taken from the old salary sheet{{ ! empty($os['file']) ? ' ('.$os['file'].(! empty($os['sheet']) ? ', sheet “'.$os['sheet'].'”' : '').')' : '' }} — this month was calculated and paid there.</div>
                    <table class="table"><tbody>
                        @foreach($os['lines'] as $key => $value)<tr><td>{{ str($key)->replace('_', ' ')->ucfirst() }}</td><td class="num">{{ is_float($value) && fmod($value, 1) ? number_format($value, 2) : $value }}</td></tr>@endforeach
                        <tr style="background:var(--canvas)"><td><strong>Payable</strong></td><td class="num"><strong>{{ number_format((float) $breakdown->net_amount, 2) }}</strong></td></tr>
                        <tr><td>Paid{{ ! empty($os['payment_date']) ? ' on '.\Illuminate\Support\Carbon::parse($os['payment_date'])->format('d M Y') : '' }}{{ ! empty($os['status']) ? ' · '.$os['status'] : '' }}</td><td class="num">{{ number_format((float) $os['paid'], 2) }}</td></tr>
                        <tr><td>Remaining</td><td class="num">{{ number_format((float) $os['remains'], 2) }}</td></tr>
                    </tbody></table>
                </div>
            @else
            <div class="modal-body"><table class="table"><tbody>
                {{-- The person's own monthly (basic) salary first, then how this month's pay is worked out from it. --}}
                @if(($cd['pay_basis'] ?? 'monthly') === 'daily')
                <tr style="background:var(--canvas)"><td><strong>Daily wage</strong><small>paid per working day attended</small></td><td class="num"><strong>{{ number_format((float) ($cd['daily_rate'] ?? 0), 2) }} / day</strong></td></tr>
                @php($dailyDays = round((float) $breakdown->present_days - 0.5 * count((array) ($breakdown->calculation_breakdown['half_days'] ?? [])) - collect((array) ($breakdown->calculation_breakdown['hours_paid_days'] ?? []))->sum(fn ($d) => 1 - (float) ($d['share'] ?? 1)), 2))
                <tr><td>Days present<small>{{ number_format((float) ($cd['daily_rate'] ?? 0), 2) }} × {{ $dailyDays }} days</small></td><td class="num">{{ number_format((float) ($cd['daily_rate'] ?? 0) * $dailyDays, 2) }}</td></tr>
                @else
                <tr style="background:var(--canvas)"><td><strong>Monthly basic salary</strong><small>from the salary profile</small></td><td class="num"><strong>{{ number_format($monthly, 2) }}</strong></td></tr>
                @endif
                @if($days > 0 && ($cd['pay_basis'] ?? 'monthly') !== 'daily')<tr><td>Per-day rate<small>{{ number_format($monthly, 2) }} ÷ {{ $days }} working days{{ ($cd['divisor'] ?? 'fixed') === 'calendar' ? ' (this month, from the calendar)' : '' }}</small></td><td class="num">{{ number_format($monthly / $days, 2) }}</td></tr>@endif
                @php($otKeys = ['approved_overtime_minutes', 'holiday_overtime_minutes', 'overtime_multiplier', 'holiday_overtime_multiplier', 'overtime_amount'])
                @php($hasOt = (float) ($breakdown->calculation_breakdown['overtime_amount'] ?? 0) > 0 || (int) ($breakdown->calculation_breakdown['approved_overtime_minutes'] ?? 0) > 0)
                @foreach(($breakdown->calculation_breakdown ?? []) as $label => $value)
                    @continue(in_array($label, $otKeys, true) && ! $hasOt)
                    @if(is_scalar($value))<tr><td>{{ ['base_salary' => 'Salary earned (per-day rate × days paid)'][$label] ?? str($label)->replace('_', ' ')->title() }}</td><td class="num">{{ is_numeric($value) ? (fmod((float) $value, 1) ? number_format((float) $value, 2) : $value) : $value }}</td></tr>@endif
                    @if($label === 'half_days' && is_array($value) && $value)<tr><td>Half days<small>paid ½ day each: {{ collect($value)->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M'))->join(', ') }}</small></td><td class="num">{{ count($value) }} × ½</td></tr>@endif
                    @if($label === 'hours_paid_days' && is_array($value) && $value)<tr><td>Checked in late, paid by hours<small>{{ collect($value)->map(fn ($h, $d) => \Illuminate\Support\Carbon::parse($d)->format('d M').': in '.($h['check_in'] ?? '').', at work '.\App\Support\Duration::hms((int) ($h['seconds'] ?? 0)).' = '.rtrim(rtrim(number_format((float) ($h['share'] ?? 0), 2), '0'), '.').' day')->join(' · ') }}</small></td><td class="num">{{ count($value) }} {{ count($value) === 1 ? 'day' : 'days' }}</td></tr>@endif
                    @if($label === 'statutory_components' && is_array($value))@foreach($value as $c)<tr><td>{{ $c['name'] ?? $c['key'] }} ({{ $c['type'] ?? '' }})</td><td class="num">{{ number_format((float) ($c['amount'] ?? 0), 2) }}</td></tr>@endforeach @endif
                @endforeach
            </tbody></table></div>
            @endif
            <div class="modal-foot"><button class="btn" wire:click="closeModal">Close</button></div>
        </x-modal>
    @endif
    @if($compare !== null && $selected)
        <x-modal title="Compare · {{ $selected->period_starts_on->format('d M') }}–{{ $selected->period_ends_on->format('d M Y') }}">
            <div class="modal-body">
                <p class="small" style="margin-top:0">Salary for days worked: what this draft has now, against a fresh calculation with today's attendance and rules (half day = ½ day). <strong>Nothing is saved</strong> — click Recalculate to apply. Adjustments, reimbursements and deductions are added on top as usual.</p>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Employee</th><th class="num">Present (draft → now)</th><th>Half days (½ paid)</th><th class="num">Draft</th><th class="num">Now</th><th class="num">Difference</th></tr></thead>
                    <tbody>
                    @forelse($compare as $r)
                        <tr><td>{{ $r['name'] }}<small>{{ $r['code'] }}</small></td>
                            <td class="num">{{ $r['present_before'] }} → {{ $r['present_after'] }}</td>
                            <td>@if($r['half_days']){{ count($r['half_days']) }}<small>{{ collect($r['half_days'])->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M'))->join(', ') }}</small>@else<span class="muted">—</span>@endif</td>
                            <td class="num">{{ number_format($r['before'], 2) }}</td><td class="num">{{ number_format($r['after'], 2) }}</td>
                            <td class="num" style="color:{{ $r['diff'] < 0 ? 'var(--red, #A32D2D)' : ($r['diff'] > 0 ? 'var(--green, #1E7A41)' : 'inherit') }}">{{ $r['diff'] > 0 ? '+' : '' }}{{ number_format($r['diff'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="6"><x-empty title="Nothing to compare" icon="wallet" /></td></tr>
                    @endforelse
                    </tbody>
                    @if($compare)<tfoot><tr><th colspan="3">Total</th><th class="num">{{ number_format(collect($compare)->sum('before'), 2) }}</th><th class="num">{{ number_format(collect($compare)->sum('after'), 2) }}</th><th class="num">{{ number_format(collect($compare)->sum('diff'), 2) }}</th></tr></tfoot>@endif
                </table></div>
            </div>
            <div class="modal-foot"><button class="btn" wire:click="closeModal">Close</button>@if(auth()->user()->can('payroll.manage'))<button class="btn btn-primary" wire:click="recalculate" wire:loading.attr="disabled" >Recalculate now</button>@endif</div>
        </x-modal>
    @endif
    @if($showDelete && $selected)
        <x-modal title="Delete draft payroll · {{ $selected->period_starts_on->format('d M') }}–{{ $selected->period_ends_on->format('d M Y') }}">
            <form wire:submit="deleteRun"><div class="modal-body"><x-errors />
                <p class="small" style="margin-top:0">This draft and its {{ $selected->items->count() }} salary rows are removed. Salary adjustments, expense claims, penalties and advance recoveries it had taken are given back, so the right payroll picks them up when it is calculated. Only a draft with no payment can be deleted. This is written to the audit log.</p>
                <x-field label="Why is it being deleted?" name="deleteReason" full><textarea wire:model="deleteReason" rows="3" placeholder="e.g. Made for the wrong dates (01–13 Oct); the month is 01–31 Oct"></textarea></x-field>
            </div><div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-danger" wire:loading.attr="disabled">Delete draft payroll</button></div></form>
        </x-modal>
    @endif
    @if($showReopen && $selected)
        <x-modal title="Reopen payroll · {{ $selected->period_starts_on->format('d M') }}–{{ $selected->period_ends_on->format('d M Y') }}">
            <form wire:submit="reopen"><div class="modal-body"><x-errors />
                <p class="small" style="margin-top:0">The run goes back to draft: attendance corrections, leave and admin check-outs for this period are allowed again, and it must be recalculated and approved again. Not possible once a salary payment is recorded. This is written to the audit log.</p>
                <x-field label="Why is it being reopened?" name="reopenReason" full><textarea wire:model="reopenReason" rows="3" placeholder="e.g. Locked by mistake before the month ended"></textarea></x-field>
            </div><div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-danger" wire:loading.attr="disabled">Reopen payroll</button></div></form>
        </x-modal>
    @endif
</div>
