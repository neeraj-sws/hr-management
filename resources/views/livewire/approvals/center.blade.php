<div>
    <div class="page-head">
        <div><h1>{{ $title }}</h1><p>Every request you are allowed to decide. You can never approve your own request.</p></div>
        <div class="actions">
            <a class="btn {{ $mode === 'center' ? 'btn-primary' : '' }}" href="{{ route('approvals.center') }}">Overview</a>
            <a class="btn {{ $mode === 'pending' ? 'btn-primary' : '' }}" href="{{ route('approvals.pending') }}">Pending ({{ $byType->flatten()->count() }})</a>
            <a class="btn {{ $mode === 'history' ? 'btn-primary' : '' }}" href="{{ route('approvals.history') }}">History</a>
        </div>
    </div>
    <x-errors />

    {{-- Overview: stats cards --}}
    @if($mode === 'center')
        <div class="stats">
            @foreach($types as $class => [$label])
                <a class="stat" href="{{ route('approvals.pending', ['type' => $class]) }}" style="color:inherit;text-decoration:none">
                    <span class="stat-icon {{ ($byType[$class] ?? collect())->count() ? 'amber' : 'green' }}"><x-icon name="inbox" /></span>
                    <div><span class="stat-label">{{ $label }}</span><span class="stat-value">{{ ($byType[$class] ?? collect())->count() }}</span><small>pending</small></div>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Pending section (center preview + full pending page) --}}
    @if($mode !== 'history')
        <section class="card mb">
            <div class="card-head">
                <h2>{{ $mode === 'center' ? 'Pending requests' : 'Pending requests' }}</h2>
            </div>
            @if($mode === 'pending')
                <div class="toolbar">
                    <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Employee name, request…"></label>
                    <label class="field"><span>Type</span><select wire:model.live="type"><option value="">All types</option>@foreach($types as $class => [$label])<option value="{{ $class }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
                    <label class="field"><span>To</span><input type="date" wire:model.live="to"></label>
                </div>
            @else
                <div class="toolbar">
                    <label class="field"><span>Type</span><select wire:model.live="type"><option value="">All types</option>@foreach($types as $class => [$label])<option value="{{ $class }}">{{ $label }}</option>@endforeach</select></label>
                </div>
            @endif
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Type</th><th>Request</th><th>Submitted</th><th></th></tr></thead>
                <tbody>
                @forelse(($mode === 'center' ? $pending->take(10) : $pendingPage) as $approval)
                    <tr wire:key="ap-{{ $approval->id }}">
                        <td><x-badge tone="blue">{{ $inbox->typeLabel($approval->approvable_type) }}</x-badge></td>
                        <td style="white-space:normal">{{ $inbox->summary($approval) }}</td>
                        <td class="nowrap">{{ $approval->created_at->diffForHumans() }}</td>
                        <td><div class="row-actions">
                            <button class="btn btn-sm btn-success" wire:click="quickApprove({{ $approval->id }})" wire:confirm="Approve this {{ strtolower($inbox->typeLabel($approval->approvable_type)) }}?">Approve</button>
                            <button class="btn btn-sm" wire:click="open({{ $approval->id }})">Review</button>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-empty title="You're all caught up" icon="check-circle">No requests need your decision.</x-empty></td></tr>
                @endforelse
                </tbody>
            </table></div>
            @if($mode === 'pending')
                @if($search || $from || $to || $type)
                    <div style="padding:.5rem 1rem .25rem;font-size:.85rem;color:var(--muted)">
                        Showing {{ $pending->count() }} result(s)
                        @if($search) · "<strong>{{ $search }}</strong>"@endif
                        @if($from || $to) · {{ $from ?: '…' }} → {{ $to ?: '…' }}@endif
                        @if($type) · {{ $inbox->typeLabel($type) }}@endif
                        — <button class="btn-link" wire:click="$set('search','');$set('from','');$set('to','');$set('type','')">Clear filters</button>
                    </div>
                @endif
                {{ $pendingPage->links() }}
            @endif
        </section>
    @endif

    {{-- History / Recent decisions --}}
    @if($mode !== 'pending')
        <section class="card">
            <div class="card-head">
                <h2>{{ $mode === 'center' ? 'Recent decisions' : 'Decisions' }}</h2>
            </div>
            @if($mode === 'history')
                <div class="toolbar">
                    <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Employee name, request…"></label>
                    <label class="field"><span>Type</span><select wire:model.live="type"><option value="">All types</option>@foreach($types as $class => [$label])<option value="{{ $class }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
                    <label class="field"><span>To</span><input type="date" wire:model.live="to"></label>
                </div>
            @endif
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Type</th><th>Request</th><th>Decision</th><th>By</th><th>When</th><th>Comment</th></tr></thead>
                <tbody>
                @forelse(($mode === 'center' ? $recent : $history) as $approval)
                    <tr>
                        <td><x-badge tone="blue">{{ $inbox->typeLabel($approval->approvable_type) }}</x-badge></td>
                        <td style="white-space:normal">{{ $inbox->summary($approval) }}</td>
                        <td><x-badge :status="$approval->status" /></td>
                        <td>{{ $approval->approver?->name ?? '—' }}</td>
                        <td class="nowrap">{{ $approval->decided_at?->format('d M Y H:i') }}</td>
                        <td style="white-space:normal">{{ $approval->comment ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty title="No decisions yet" icon="history" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
            @if($mode === 'history')
                @if($search || $from || $to || $type)
                    <div style="padding:.5rem 1rem .25rem;font-size:.85rem;color:var(--muted)">
                        Showing {{ $history->total() }} result(s)
                        @if($search) · "<strong>{{ $search }}</strong>"@endif
                        @if($from || $to) · {{ $from ?: '…' }} → {{ $to ?: '…' }}@endif
                        @if($type) · {{ $inbox->typeLabel($type) }}@endif
                        — <button class="btn-link" wire:click="$set('search','');$set('from','');$set('to','');$set('type','')">Clear filters</button>
                    </div>
                @endif
                {{ $history->links() }}
            @endif
        </section>
    @endif

    @if($deciding)
        <x-modal title="Review {{ strtolower($inbox->typeLabel($deciding->approvable_type)) }}">
            <div class="modal-body">
                <x-errors />
                <p style="margin-top:0">{{ $inbox->summary($deciding) }}</p>
                @if(isset($deciding->approvable->reason))<p class="small muted">Reason given: {{ $deciding->approvable->reason }}</p>@endif
                <x-field label="Comment" name="note" hint="required when rejecting"><textarea wire:model="note" rows="3"></textarea></x-field>
            </div>
            <div class="modal-foot"><button class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-danger" wire:click="decide(false)">Reject</button><button class="btn btn-success" wire:click="decide(true)">Approve</button></div>
        </x-modal>
    @endif
</div>
