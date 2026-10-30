<div>
    @php($rs = fn ($v) => '₹'.number_format((float) $v, 2))
    <div class="page-head"><div><h1>Day-wise salary</h1><p>Each day of the month: status, time at work, the day's pay, extra working, overtime, fines and the day's net. The days and the month's other items add up to the salary payroll pays.</p></div></div>
    <x-errors />

    <section class="card mb">
        <div class="toolbar card-body">
            <x-field label="Employee" name="employee"><select wire:model.live="employee">@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }} · {{ $p->employee_code }}{{ $p->is_active ? '' : ' (left)' }}</option>@endforeach</select></x-field>
            <x-field label="Month" name="month"><div style="display:flex;gap:4px"><button type="button" class="btn btn-sm" wire:click="shiftMonth(-1)" aria-label="Previous month">‹</button><input type="month" wire:model.live="month" max="{{ today()->format('Y-m') }}"><button type="button" class="btn btn-sm" wire:click="shiftMonth(1)" aria-label="Next month">›</button></div></x-field>
        </div>
    </section>

    @if(! $person)
        <section class="card card-body"><x-empty title="{{ $people->isEmpty() ? 'Nobody to show' : 'Choose an employee' }}" icon="wallet" /></section>
    @elseif(! $data)
        <section class="card card-body"><x-empty title="No salary set up for {{ $person->name }}" icon="wallet" /></section>
    @else
        @php($removable = collect($data['rows'])->flatMap(fn ($r) => collect($r['fines'])->where('can_remove', true)->pluck('id'))->values()->all())
        <div class="grid mb" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
            <section class="card card-body"><small class="muted">{{ $data['daily'] ? 'Daily wage' : 'Monthly salary' }}</small><h2>{{ $rs($data['monthly_salary']) }}</h2><small class="muted">{{ $rs($data['hourly']) }} an hour</small></section>
            <section class="card card-body"><small class="muted">Earned from the days</small><h2>{{ $rs($data['totals']['pay'] + $data['totals']['extra'] + $data['totals']['overtime']) }}</h2>@if($data['totals']['extra'] || $data['totals']['overtime'])<small class="muted">incl. {{ $rs($data['totals']['extra'] + $data['totals']['overtime']) }} extra / overtime</small>@endif</section>
            <section class="card card-body"><small class="muted">Fines deducted</small><h2 style="color:var(--red)">{{ $rs($data['totals']['fines']) }}</h2>@if($canRemove && $removable)<button type="button" class="btn btn-sm" wire:click="openRemove(@js($removable))">Take back all ({{ count($removable) }})</button>@endif</section>
            <section class="card card-body"><small class="muted">Net salary · {{ $data['month'] }}</small><h2 style="color:var(--green)">{{ $rs($data['net']) }}</h2><small class="muted">as things stand now</small></section>
        </div>

        <section class="card mb">
            <div class="card-head"><div><h2>{{ $person->name }} · {{ $data['month'] }}</h2><p>{{ $person->employee_code }}{{ $person->department ? ' · '.$person->department->name : '' }}{{ $person->manager ? ' · manager '.$person->manager->name : '' }}</p></div></div>
            <div class="table-wrap"><table class="table" data-no-sort>
                <thead><tr><th>Date</th><th>Status</th><th>In – Out</th><th class="num">At work</th><th class="num">Day's pay</th><th class="num">Extra / OT</th><th>Fines</th><th class="num">Net</th></tr></thead>
                <tbody>
                @foreach($data['rows'] as $r)
                    <tr wire:key="dw-{{ $r['date'] }}" @if(! $r['workday'] && ! $r['extra'])style="background:var(--canvas)"@endif>
                        <td class="nowrap"><strong>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d M') }}</strong><small>{{ $r['day'] }}</small></td>
                        <td><x-badge :tone="$r['tone']">{{ $r['status'] }}</x-badge>@if($r['note'])<small>{{ $r['note'] }}</small>@endif</td>
                        <td class="nowrap small">{{ $r['in'] ? $r['in']->format('h:i A') : '—' }} – {{ $r['out'] ? $r['out']->format('h:i A') : ($r['in'] ? 'still in' : '—') }}</td>
                        <td class="num">{{ $r['at_work'] !== null ? \App\Support\Duration::hm($r['at_work']) : '—' }}</td>
                        <td class="num">@if($r['rate'] !== null){{ $rs($r['pay']) }}@if($r['share'] > 0 && $r['share'] < 1)<small>{{ round($r['share'] * 100) }}% of {{ $rs($r['rate']) }}</small>@endif @else<span class="muted">—</span>@endif</td>
                        <td class="num">@if($r['extra'] || $r['overtime'])<span style="color:var(--green)">+{{ $rs($r['extra'] + $r['overtime']) }}</span><small>{{ $r['extra'] ? 'extra working' : 'overtime' }}</small>@else<span class="muted">—</span>@endif</td>
                        <td>
                            @foreach($r['fines'] as $fine)
                                <div class="small" style="margin-bottom:4px">
                                    @if($fine['status'] === 'waived')<s class="muted">{{ $rs($fine['amount']) }}</s> <x-badge tone="gray">Removed</x-badge>
                                    @elseif($fine['paid_back'])<s class="muted">{{ $rs($fine['amount']) }}</s> <x-badge tone="blue">Paid back next salary</x-badge>
                                    @elseif($fine['status'] === 'pending')<span style="color:var(--amber)">{{ $rs($fine['amount']) }}</span> <x-badge tone="amber">Pending</x-badge>
                                    @else<b style="color:var(--red)">−{{ $rs($fine['amount']) }}</b>@endif
                                    <small>{{ \Illuminate\Support\Str::limit($fine['reason'], 60) }}@if($fine['status'] === 'waived' && $fine['note']) · {{ \Illuminate\Support\Str::limit($fine['note'], 80) }}@endif</small>
                                    @if($canRemove && $fine['can_remove'])<button type="button" class="btn btn-sm" wire:click="openRemove([{{ $fine['id'] }}])">Remove fine</button>@endif
                                </div>
                            @endforeach
                            @if(! $r['fines'])<span class="muted">—</span>@endif
                        </td>
                        <td class="num"><b>{{ $rs($r['net']) }}</b></td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr><th colspan="4">Days total</th><th class="num">{{ $rs($data['totals']['pay']) }}</th><th class="num">+{{ $rs($data['totals']['extra'] + $data['totals']['overtime']) }}</th><th>−{{ $rs($data['totals']['fines']) }}</th><th class="num">{{ $rs($data['totals']['net']) }}</th></tr></tfoot>
            </table></div>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>Month items</h2><p>Not tied to one day.</p></div></div>
            <div class="list">
                <div class="list-row"><div><strong>Days total</strong></div><b>{{ $rs($data['totals']['net']) }}</b></div>
                @foreach($data['items'] as $item)
                    <div class="list-row"><div><strong>{{ $item['label'] }}</strong>@if($item['detail'])<small>{{ $item['detail'] }}</small>@endif</div><b style="color:{{ $item['amount'] < 0 ? 'var(--red)' : 'var(--green)' }}">{{ $item['amount'] < 0 ? '−' : '+' }}{{ $rs(abs($item['amount'])) }}</b></div>
                @endforeach
                <div class="list-row"><div><strong>Net salary</strong><small>the same as payroll calculates now; an approved payroll keeps the figures of the day it was approved</small></div><b style="font-size:1.1em">{{ $rs($data['net']) }}</b></div>
            </div>
        </section>
    @endif

    @if($removing)
        <x-modal title="Take back {{ count($removing) }} fine(s)">
            <form wire:submit="saveRemove"><div class="modal-body"><x-errors />
                <p class="small">{{ $rs($removingTotal) }} in all. Each fine is removed and keeps this reason in its history; one whose salary is already paid (locked payroll) is paid back in the next salary. The employee is told.</p>
                <x-field label="Why is it taken back?" name="removeNote" full><textarea rows="3" wire:model="removeNote" placeholder="e.g. Came late because of a client visit; approved by manager"></textarea></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-danger" wire:loading.attr="disabled">Take back</button></div></form>
        </x-modal>
    @endif
</div>
