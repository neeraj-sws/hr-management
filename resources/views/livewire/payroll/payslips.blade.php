<div>
    <div class="page-head"><div><h1>Payslips</h1><p>A payslip is issued when the first payment for a locked payroll is recorded.</p></div></div>
    @if($canAll && $own)
        <div class="tabs"><button class="tab {{ $scope === 'mine' ? 'active' : '' }}" wire:click="$set('scope','mine')">My payslips</button><button class="tab {{ $scope === 'all' ? 'active' : '' }}" wire:click="$set('scope','all')">All payslips</button></div>
    @endif
    @if($canAnswer && $pendingRequests->isNotEmpty())
        {{-- Payroll staff: requests for months whose payslip this system did not make (e.g. before October 2026). --}}
        <section class="card mb">
            <div class="card-head"><div><h2>Payslip requests ({{ $pendingRequests->count() }})</h2><p>Months from before this system. Send the month from Legacy Salary in one click, upload the payslip file, or say why not. The employee is told.</p></div></div>
            <div class="table-wrap"><table class="table" data-no-sort><thead><tr><th>Employee</th><th>Month</th><th>Note</th><th>Asked</th><th></th></tr></thead><tbody>
                @foreach($pendingRequests as $r)
                    <tr wire:key="pr-{{ $r->id }}"><td><strong>{{ $r->employee?->name }}</strong><small>{{ $r->employee?->employee_code }}</small></td><td class="nowrap">{{ $r->monthLabel() }}</td><td style="white-space:normal">{{ $r->note ?? '—' }}</td><td class="nowrap small">{{ $r->created_at->format('d M, h:i A') }}</td>
                        <td><div class="row-actions">@if($r->has_legacy)<button class="btn btn-sm btn-primary" wire:click="sendLegacy({{ $r->id }})" wire:confirm="Send this payslip from Legacy Salary?">Send from Legacy Salary</button>@else<small class="muted">Not in Legacy Salary</small>@endif
                            <button class="btn btn-sm" wire:click="openUpload({{ $r->id }})">Upload file</button><button class="btn btn-sm btn-danger" wire:click="openReject({{ $r->id }})">Cannot give</button></div></td></tr>
                @endforeach
            </tbody></table></div>
        </section>
    @endif
    @if($own && $scope === 'mine')
        {{-- Employee: ask for any month's payslip; one made by this system opens at once, older months go to HR. --}}
        <section class="card mb">
            <div class="card-head"><div><h2>Request a payslip</h2><p>Any month. If this system made it, it opens at once; for older months (before this system) HR sends it and you are told.</p></div></div>
            <form class="toolbar" wire:submit="requestPayslip" style="border:0">
                <label class="field"><span>Month</span><input type="month" wire:model="reqMonth" max="{{ now()->format('Y-m') }}"></label>
                <label class="field grow"><span>Note (optional)</span><input wire:model="reqNote" placeholder="e.g. Needed for a bank loan"></label>
                <button class="btn btn-primary" wire:loading.attr="disabled">Request payslip</button>
            </form>
            @error('reqMonth')<p class="error" style="padding:0 16px 12px">{{ $message }}</p>@enderror
            @if($myRequests->isNotEmpty())
                <div class="table-wrap"><table class="table" data-no-sort><thead><tr><th>Month</th><th>Status</th><th>Answer</th><th></th></tr></thead><tbody>
                    @foreach($myRequests as $r)
                        <tr><td class="nowrap"><strong>{{ $r->monthLabel() }}</strong><small>asked {{ $r->created_at->format('d M Y') }}</small></td>
                            <td><x-badge :tone="['sent' => 'green', 'rejected' => 'red'][$r->status] ?? 'amber'">{{ ['sent' => 'ready', 'rejected' => 'not given', 'pending' => 'waiting for HR'][$r->status] ?? $r->status }}</x-badge></td>
                            <td class="small" style="white-space:normal">{{ $r->decision_note ?? ($r->status === 'sent' ? 'Sent'.($r->decider ? ' by '.$r->decider->name : '').' on '.$r->decided_at?->format('d M Y') : '—') }}</td>
                            <td>@if($r->hasDocument())<a class="btn btn-sm" href="{{ route('payslip-requests.show', $r) }}" target="_blank"><x-icon name="external" class="icon-sm" />Open</a>@endif</td></tr>
                    @endforeach
                </tbody></table></div>
            @endif
        </section>
    @endif
    <section class="card">
        @if($scope === 'all')<div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Reference, employee name or code"></label></div>@endif
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Reference</th>@if($scope === 'all')<th>Employee</th>@endif<th>Period</th><th class="num">Gross</th><th class="num">Net</th><th class="num">Paid</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($payslips as $slip)
                @php($paid = (float) $slip->item->payments->sum('amount'))
                <tr><td><strong>{{ $slip->reference }}</strong><small>issued {{ $slip->issued_at->format('d M Y') }}</small></td>
                    @if($scope === 'all')<td>{{ $slip->employee?->name }}</td>@endif
                    <td class="nowrap">{{ $slip->item->run->period_starts_on->format('d M') }} – {{ $slip->item->run->period_ends_on->format('d M Y') }}</td>
                    <td class="num">{{ number_format((float) $slip->item->gross_amount, 2) }}</td><td class="num"><strong>{{ number_format((float) $slip->item->net_amount, 2) }}</strong></td><td class="num">{{ number_format($paid, 2) }}</td>
                    <td><x-badge :status="$paid >= (float) $slip->item->net_amount ? 'paid' : 'partially_paid'" /></td>
                    <td><a class="btn btn-sm" href="{{ route('hr.payslips.show', $slip) }}" target="_blank"><x-icon name="external" class="icon-sm" />View / print</a></td></tr>
            @empty
                <tr><td colspan="8"><x-empty title="No payslips yet" icon="file" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $payslips->links() }}
    </section>
    @if($uploadingId)
        <x-modal title="Upload payslip file">
            <form wire:submit="saveUpload"><div class="modal-body"><x-errors />
                <x-field label="Payslip (PDF, JPG or PNG, up to 5 MB)" name="uploadFile" full><input type="file" wire:model="uploadFile" accept=".pdf,image/*"></x-field>
                <span wire:loading wire:target="uploadFile" class="small muted">Uploading…</span>
            </div><div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Send to the employee</button></div></form>
        </x-modal>
    @endif
    @if($rejectingId)
        <x-modal title="Payslip cannot be given">
            <form wire:submit="saveReject"><div class="modal-body"><x-errors />
                <x-field label="Why (the employee sees it)" name="rejectNote" full><textarea rows="3" wire:model="rejectNote" placeholder="e.g. No salary record for that month"></textarea></x-field>
            </div><div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-danger" wire:loading.attr="disabled">Send answer</button></div></form>
        </x-modal>
    @endif
</div>
