<div>
    <div class="page-head">
        <div><h1>Overtime</h1><p>Approved overtime is paid in payroll at the configured multiplier{{ isset($policy['multiplier']) ? ' ('.$policy['multiplier'].'×)' : '' }}{{ isset($policy['max_minutes_per_day']) ? ', max '.$policy['max_minutes_per_day'].' min/day' : '' }}.</p></div>
        @if($own && ($policy['enabled'] ?? true))<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Request overtime</button>@endif
    </div>
    @unless($policy['enabled'] ?? true)<div class="alert alert-info">Overtime is switched off by the company. New requests cannot be made and payroll pays no overtime.</div>@endunless
    @if($own)
        <div class="stats">
            <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Approved this month</span><span class="stat-value">{{ \App\Support\Duration::fromMinutes($monthApproved) }}</span><small>HH:MM:SS</small></div></div>
            <div class="stat"><span class="stat-icon amber"><x-icon name="hourglass" /></span><div><span class="stat-label">Pending this month</span><span class="stat-value">{{ \App\Support\Duration::fromMinutes($monthPending) }}</span><small>HH:MM:SS</small></div></div>
        </div>
    @endif
    <div class="tabs">
        @if($own)<button class="tab {{ $tab === 'mine' ? 'active' : '' }}" wire:click="$set('tab','mine')">My overtime</button>@endif
        @if($canApprove)<button class="tab {{ $tab === 'approvals' ? 'active' : '' }}" wire:click="$set('tab','approvals')">Awaiting my approval @if($pendingCount)<span class="nav-count" style="margin-left:6px">{{ $pendingCount }}</span>@endif</button><button class="tab {{ $tab === 'all' ? 'active' : '' }}" wire:click="$set('tab','all')">History</button>@endif
    </div>
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr>@if($tab !== 'mine')<th>Employee</th>@endif<th>Date</th><th class="num">Requested</th><th class="num">Approved</th><th>Reason</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($requests as $ot)
                <tr wire:key="ot-{{ $ot->id }}">
                    @if($tab !== 'mine')<td><strong>{{ $ot->employee?->name }}</strong></td>@endif
                    <td class="nowrap">{{ $ot->work_date->format('D d M Y') }}</td>
                    <td class="num">{{ \App\Support\Duration::fromMinutes($ot->requested_minutes) }}</td><td class="num">{{ $ot->approved_minutes !== null ? \App\Support\Duration::fromMinutes($ot->approved_minutes) : '—' }}</td>
                    <td style="white-space:normal;max-width:300px">{{ $ot->reason }}@if($ot->decision_note)<small>Note: {{ $ot->decision_note }}</small>@endif</td>
                    <td><x-badge :status="$ot->status" />@if($ot->decider)<small>by {{ $ot->decider->name }}</small>@endif</td>
                    <td>@if($tab === 'approvals')<button class="btn btn-sm btn-primary" wire:click="openDecision({{ $ot->id }})">Review</button>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="No overtime requests" icon="timer" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $requests->links() }}
    </section>

    @if($showForm)
        <x-modal title="Request overtime">
            <form wire:submit="submit"><div class="modal-body"><div class="form-grid">
                <x-field label="Work date" name="workDate"><input type="date" wire:model="workDate" max="{{ today()->toDateString() }}"></x-field>
                <x-field label="Minutes" name="minutes" hint="15–720"><input type="number" min="15" max="720" step="15" wire:model="minutes"></x-field>
                <x-field label="Reason" name="reason" full><textarea wire:model="reason"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Submit</button></div></form>
        </x-modal>
    @endif
    @if($deciding)
        <x-modal title="Review overtime">
            <div class="modal-body">
                <dl class="kv"><dt>Employee</dt><dd>{{ $deciding->employee?->name }}</dd><dt>Date</dt><dd>{{ $deciding->work_date->format('d M Y') }}</dd><dt>Requested</dt><dd>{{ \App\Support\Duration::fromMinutes($deciding->requested_minutes) }}</dd><dt>Reason</dt><dd>{{ $deciding->reason }}</dd>
                    <dt>Attendance that day</dt><dd>@if($evidence['session']){{ $evidence['session']->check_in?->format('h:i A') }} – {{ $evidence['session']->check_out?->format('h:i A') ?? 'still checked in' }} · worked @hms($evidence['worked_seconds']) (standard @hms($evidence['standard_seconds'])) · <strong @class(['muted' => $evidence['extra_minutes'] >= $deciding->requested_minutes])>{{ \App\Support\Duration::fromMinutes($evidence['extra_minutes']) }} beyond standard</strong>@if($evidence['extra_minutes'] < $deciding->requested_minutes) <x-badge tone="amber">claims more than attendance shows</x-badge>@endif @else <x-badge tone="red">No attendance recorded</x-badge>@endif @if($evidence['holiday']) <x-badge tone="violet">weekly off / holiday</x-badge>@endif</dd></dl>
                <div class="form-grid mt">
                    <x-field label="Approve minutes" name="approvedMinutes"><input type="number" min="1" max="{{ $deciding->requested_minutes }}" wire:model="approvedMinutes"></x-field>
                    <x-field label="Note" name="decisionNote" hint="required to reject"><input wire:model="decisionNote"></x-field>
                </div>
            </div>
            <div class="modal-foot"><button class="btn btn-danger" wire:click="decide(false)">Reject</button><button class="btn btn-success" wire:click="decide(true)">Approve</button></div>
        </x-modal>
    @endif
</div>
