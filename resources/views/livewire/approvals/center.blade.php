<div>
    <div class="page-head"><div><h1>{{ $title }}</h1><p>Every request you are allowed to decide. You can never approve your own request.</p></div>
        <div class="actions"><a class="btn {{ $mode === 'pending' ? 'btn-primary' : '' }}" href="{{ route('approvals.pending') }}">Pending ({{ $byType->flatten()->count() }})</a><a class="btn {{ $mode === 'history' ? 'btn-primary' : '' }}" href="{{ route('approvals.history') }}">History</a></div></div>
    <x-errors />

    @if($mode === 'center')
        <div class="stats">
            @foreach($types as $class => [$label])
                <a class="stat" href="{{ route('approvals.pending', ['type' => $class]) }}" style="color:inherit;text-decoration:none"><span class="stat-icon {{ ($byType[$class] ?? collect())->count() ? 'amber' : 'green' }}"><x-icon name="inbox" /></span><div><span class="stat-label">{{ $label }}</span><span class="stat-value">{{ ($byType[$class] ?? collect())->count() }}</span><small>pending</small></div></a>
            @endforeach
        </div>
    @endif

    @if($mode !== 'history')
        <section class="card mb">
            <div class="card-head"><h2>{{ $mode === 'center' ? 'Oldest pending first' : 'Pending requests' }}</h2>
                <label class="field"><span class="sr-only">Type</span><select wire:model.live="type"><option value="">All types</option>@foreach($types as $class => [$label])<option value="{{ $class }}">{{ $label }}</option>@endforeach</select></label></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Type</th><th>Request</th><th>Submitted</th><th></th></tr></thead>
                <tbody>
                @forelse(($mode === 'center' ? $pending->sortBy('created_at')->take(10) : $pending->sortBy('created_at')) as $approval)
                    <tr wire:key="ap-{{ $approval->id }}"><td><x-badge tone="blue">{{ $inbox->typeLabel($approval->approvable_type) }}</x-badge></td><td style="white-space:normal">{{ $inbox->summary($approval) }}</td><td class="nowrap">{{ $approval->created_at->diffForHumans() }}</td>
                        <td><div class="row-actions"><button class="btn btn-sm btn-success" wire:click="quickApprove({{ $approval->id }})" wire:confirm="Approve this {{ strtolower($inbox->typeLabel($approval->approvable_type)) }}?">Approve</button><button class="btn btn-sm" wire:click="open({{ $approval->id }})">Review</button></div></td></tr>
                @empty
                    <tr><td colspan="4"><x-empty title="You're all caught up" icon="check-circle">No requests need your decision.</x-empty></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    @endif

    @if($mode !== 'pending')
        <section class="card">
            <div class="card-head"><h2>{{ $mode === 'center' ? 'Recent decisions' : 'Decisions' }}</h2>
                @if($mode === 'history')<label class="field"><span class="sr-only">Type</span><select wire:model.live="type"><option value="">All types</option>@foreach($types as $class => [$label])<option value="{{ $class }}">{{ $label }}</option>@endforeach</select></label>@endif</div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Type</th><th>Request</th><th>Decision</th><th>By</th><th>When</th><th>Comment</th></tr></thead>
                <tbody>
                @forelse(($mode === 'center' ? $recent : $history) as $approval)
                    <tr><td><x-badge tone="blue">{{ $inbox->typeLabel($approval->approvable_type) }}</x-badge></td><td style="white-space:normal">{{ $inbox->summary($approval) }}</td><td><x-badge :status="$approval->status" /></td><td>{{ $approval->approver?->name ?? '—' }}</td><td class="nowrap">{{ $approval->decided_at?->format('d M Y H:i') }}</td><td style="white-space:normal">{{ $approval->comment ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="6"><x-empty title="No decisions yet" icon="history" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
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
