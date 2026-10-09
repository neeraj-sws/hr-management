<div>
    <div class="page-head">
        <div><h1>Leave Balance</h1><p>Entitlement, monthly accrual, carry-forward (with expiry), usage and pending bookings for {{ $year }}.</p></div>
        <div class="actions">
            <label class="field"><span class="sr-only">Year</span><select wire:model.live="year">@for($y = today()->year + 1; $y >= today()->year - 3; $y--)<option value="{{ $y }}">{{ $y }}</option>@endfor</select></label>
            @can('policies.manage')<button class="btn" wire:click="runAccrual" wire:confirm="Run monthly accrual through {{ today()->format('F Y') }}? It is idempotent." wire:loading.attr="disabled"><x-icon name="refresh" class="icon-sm" />Run accrual</button>@endcan
        </div>
    </div>

    @if($own)
        <section class="card mb">
            <div class="card-head"><h2>My balances</h2><a class="btn btn-sm" href="{{ route('leave.requests') }}">Request leave</a></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Leave type</th><th class="num">Entitled</th><th class="num">Accrued</th><th class="num">Carried fwd</th><th class="num">Adjusted</th><th class="num">Used</th><th class="num">Pending</th><th class="num">Available</th></tr></thead>
                <tbody>
                @foreach($mine as $b)
                    <tr><td><strong>{{ $b['type']->name }}</strong><small>{{ $b['type']->paid ? 'Paid' : 'Unpaid' }}{{ $b['type']->carry_forward ? ' · carry-forward up to '.(float) $b['type']->carry_forward_limit : '' }}{{ $b['type']->allow_advance ? ' · advance allowed' : '' }}</small></td>
                        <td class="num">{{ $b['entitled'] }}</td><td class="num">{{ $b['accrued'] }}</td>
                        <td class="num">{{ $b['carried'] }}@if($b['carried_expires_on'])<small>expires {{ \Illuminate\Support\Carbon::parse($b['carried_expires_on'])->format('d M Y') }}</small>@endif</td>
                        <td class="num">{{ $b['adjusted'] }}</td><td class="num">{{ $b['used'] }}</td><td class="num">{{ $b['booked'] }}</td>
                        <td class="num"><strong>{{ round($b['remaining'], 2) }}</strong></td></tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
        <section class="card mb">
            <div class="card-head"><h2>My leave history {{ $year }}</h2></div>
            <div class="list">
                @forelse($history as $leave)<div class="list-row"><div><strong>{{ $leave->leaveType?->name }} · {{ (float) $leave->days }} days</strong><small>{{ $leave->starts_on->format('d M') }} – {{ $leave->ends_on->format('d M Y') }} · {{ $leave->reason }}</small></div><x-badge :status="$leave->status" /></div>
                @empty<x-empty title="No leave taken this year" icon="calendar" />@endforelse
            </div>
        </section>
    @endif

    @if($team)
        <section class="card">
            <div class="card-head"><h2>Employee balances</h2></div>
            <div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or code"></label></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Employee</th>@foreach($types as $t)<th class="num">{{ $t->name }}</th>@endforeach</tr></thead>
                <tbody>
                @forelse($team as $employee)
                    <tr><td><strong>{{ $employee->name }}</strong><small>{{ $employee->employee_code }}</small></td>
                        @foreach($types as $t)@php($b = $employee->balances[$t->id])<td class="num"><strong>{{ round($b['remaining'], 2) }}</strong><small>used {{ $b['used'] }} · pend {{ $b['booked'] }}</small><button class="btn btn-sm btn-ghost" wire:click="openAdjust({{ $employee->id }}, {{ $t->id }})">Adjust</button></td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ $types->count() + 1 }}"><x-empty title="No employees" icon="users" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
            {{ $team->links() }}
        </section>
    @endif

    @if($adjusting && $adjustType)
        <x-modal title="Adjust leave balance">
            <form wire:submit="saveAdjustment"><div class="modal-body">
                <p class="small muted" style="margin-top:0">{{ $adjusting->name }} · {{ $adjustType->name }} · {{ $year }}. Use a negative number to deduct. The adjustment is audited.</p>
                <div class="form-grid">
                    <x-field label="Days (+/−)" name="adjustDays"><input type="number" step="0.5" wire:model="adjustDays"></x-field>
                    <x-field label="Reason" name="adjustReason"><input wire:model="adjustReason"></x-field>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save adjustment</button></div></form>
        </x-modal>
    @endif
</div>
