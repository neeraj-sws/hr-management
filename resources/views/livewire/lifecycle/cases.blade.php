<div>
    <div class="page-head">
        <div><h1>{{ $kind === 'offboarding' ? 'Offboarding' : 'Onboarding' }}</h1>
            <p>{{ $kind === 'offboarding' ? 'Clearance → asset return → leave balance → salary & advance recovery → final settlement → payment → inactive.' : 'Department → team → manager → salary → leave policy → documents → assets → active.' }}</p></div>
        <div class="actions">@if($kind === 'onboarding')<a class="btn" href="{{ route('people.employees') }}"><x-icon name="user-plus" class="icon-sm" />Add employee</a>@endif<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Open {{ $kind }} case</button></div>
    </div>
    <x-errors />
    <div class="grid grid-master">
        <section class="card">
            <div class="card-head"><h2>Cases</h2><select wire:model.live="status" class="input" style="width:auto" aria-label="Show cases"><option value="open">Open</option><option value="closed">Closed</option><option value="all">All</option></select></div>
            <div class="list">
                @forelse($cases as $c)
                    @php($done = $c->clearances->where('status', 'cleared')->count())
                    <button type="button" class="list-row" wire:click="$set('case', {{ $c->id }})" style="background:{{ $selected?->id === $c->id ? 'var(--blue-soft)' : 'transparent' }};border:0;border-bottom:1px solid var(--line-2);text-align:left;cursor:pointer;width:100%">
                        <div><strong>{{ $c->employee?->name }}</strong><small>{{ $c->effective_on?->format('d M Y') }} · {{ $done }}/{{ $c->clearances->count() }} items</small></div><x-badge :status="$c->status" />
                    </button>
                @empty
                    <x-empty title="No {{ $status === 'all' ? '' : $status }} cases" icon="{{ $kind === 'offboarding' ? 'user-minus' : 'user-plus' }}" />
                @endforelse
            </div>
        </section>

        <section class="card">
            @if(!$selected)
                <x-empty title="Select a case" icon="clipboard">Choose a case to work through its checklist.</x-empty>
            @else
                <div class="card-head">
                    <div><h2>{{ $selected->employee->name }} <x-badge :status="$selected->status" /></h2><p>{{ $selected->employee->employee_code }} · {{ $selected->employee->department?->name ?? 'No department' }} · {{ $kind === 'offboarding' ? 'Last day' : 'Start' }} {{ $selected->effective_on?->format('d M Y') }}</p></div>
                    <div class="actions">
                        <a class="btn btn-sm" href="{{ route('people.employees.show', $selected->employee) }}">Profile</a>
                        @if($selected->status === 'open')<button class="btn btn-sm btn-primary" wire:click="close" wire:confirm="{{ $kind === 'offboarding' ? 'Close offboarding? The employee will be deactivated and all logins and device tokens revoked.' : 'Mark onboarding complete?' }}">Close case</button>@endif
                    </div>
                </div>
                <div class="card-body grid grid-2">
                    <div>
                        @if($selected->notes)<p class="small muted" style="margin-top:0">{{ $selected->notes }}</p>@endif
                        @if($kind === 'onboarding')
                            <p class="eyebrow">Readiness (from live records)</p>
                            @foreach($details['checks'] as $check)
                                <div class="list-row" style="padding:7px 0"><span>{{ $check['label'] }} @unless($check['required'])<span class="small muted">(optional)</span>@endunless</span>@if($check['done'])<x-badge status="completed">done</x-badge>@else<x-badge :status="$check['required'] ? 'pending' : 'draft'">{{ $check['required'] ? 'missing' : 'not yet' }}</x-badge>@endif</div>
                            @endforeach
                        @else
                            <p class="eyebrow">Leave balance (paid)</p>
                            @foreach($details['leave'] as $l)<div class="list-row" style="padding:6px 0"><span>{{ $l['type'] }}</span><strong>{{ round($l['remaining'], 2) }} days</strong></div>@endforeach
                            <p class="eyebrow mt">Advance to recover</p><p style="margin:0"><strong>{{ number_format($details['advances'], 2) }}</strong></p>
                            <p class="eyebrow mt">Final settlement</p>
                            @if($selected->settlement)
                                <p style="margin:0">Net {{ number_format((float) $selected->settlement->net_amount, 2) }} · paid {{ number_format((float) $selected->settlement->payments->sum('amount'), 2) }} <x-badge :status="$selected->settlement->status" /></p>
                            @else
                                <p class="small muted" style="margin:0">Not prepared yet.</p>
                            @endif
                            <a class="btn btn-sm mt" href="{{ route('lifecycle.settlements', ['case' => $selected->id]) }}">Open final settlement</a>
                        @endif
                    </div>
                    <div>
                        <p class="eyebrow">Checklist</p>
                        @foreach($selected->clearances as $item)
                            <div class="list-row" style="padding:7px 0"><span>{{ $item->item }}@if($item->cleared_at)<small>{{ $item->cleared_at->format('d M Y') }}</small>@endif</span>
                                @if($item->status === 'cleared')<x-badge status="cleared" />@elseif($selected->status === 'open')<button class="btn btn-sm" wire:click="clearItem({{ $item->id }})" wire:confirm="Mark this clearance item as done?">Mark done</button>@endif</div>
                        @endforeach
                        @if($selected->status === 'open')
                            <form class="actions mt" wire:submit="addItem"><input class="input" style="flex:1" wire:model="newItem" placeholder="Add checklist item"><button class="btn btn-sm">Add</button></form>
                        @endif
                        <p class="eyebrow mt">Assets</p>
                        @forelse($details['assets'] as $asset)
                            <div class="list-row" style="padding:7px 0"><span>{{ $asset->name }} <small>{{ $asset->asset_tag }}</small></span>
                                @if($asset->status === 'assigned' && $kind === 'offboarding' && $selected->status === 'open')<button class="btn btn-sm" wire:click="returnAsset({{ $asset->id }})" wire:confirm="Confirm the asset has been returned?">Mark returned</button>@else<x-badge :status="$asset->status" />@endif</div>
                        @empty
                            <p class="small muted">No assets. <a href="{{ route('lifecycle.assets') }}">Assign assets</a></p>
                        @endforelse
                    </div>
                </div>
            @endif
        </section>
    </div>

    @if($showForm)
        <x-modal title="Open {{ $kind }} case">
            <form wire:submit="open"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Employee" name="employeeId" full><select wire:model="employeeId"><option value="">Select</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach</select></x-field>
                <x-field :label="$kind === 'offboarding' ? 'Last working day' : 'Start date'" name="effectiveOn"><input type="date" wire:model="effectiveOn"></x-field>
                @if($kind === 'offboarding')
                    <x-field label="Exit type" name="exitType"><select wire:model.live="exitType"><option value="resignation">Resignation</option><option value="termination">Termination</option><option value="end_of_contract">End of contract</option><option value="retirement">Retirement</option></select></x-field>
                    @if($exitType === 'resignation')<x-field label="Resignation date" name="resignedOn"><input type="date" wire:model="resignedOn"></x-field>@endif
                @endif
                <x-field :label="$kind === 'offboarding' ? 'Reason / notes' : 'Notes'" name="notes" full><textarea wire:model="notes"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Open case</button></div></form>
        </x-modal>
    @endif
</div>
