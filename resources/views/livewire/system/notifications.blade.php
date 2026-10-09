<div>
    <div class="page-head"><div><h1>Notifications</h1><p>{{ $unread }} unread · approvals, decisions, payroll, payments and task assignments.</p></div>
        @if($unread)<button class="btn" wire:click="markAllRead"><x-icon name="check" class="icon-sm" />Mark all read</button>@endif</div>
    <div class="tabs"><button class="tab {{ $filter === 'all' ? 'active' : '' }}" wire:click="$set('filter','all')">All</button><button class="tab {{ $filter === 'unread' ? 'active' : '' }}" wire:click="$set('filter','unread')">Unread</button></div>
    <section class="card">
        <div class="list">
            @forelse($notifications as $n)
                <div class="list-row" wire:key="n-{{ $n->id }}" style="{{ $n->read_at ? '' : 'background:#f7faff' }}">
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <span class="stat-icon" style="width:32px;height:32px">{{-- kind icon --}}<x-icon :name="match($n->data['kind'] ?? '') { 'LeaveRequest' => 'calendar', 'OvertimeRequest' => 'timer', 'PayrollRun', 'SalaryPayment' => 'wallet', 'TaskAssignment' => 'check-square', 'PerformanceReview' => 'star', default => 'bell' }" class="icon-sm" /></span>
                        <div><strong>{{ $n->data['message'] ?? 'Update' }}</strong><small>{{ str($n->data['kind'] ?? '')->headline() }} · {{ $n->created_at->diffForHumans() }}</small></div>
                    </div>
                    <div class="row-actions">
                        <a class="btn btn-sm" href="{{ route('system.notifications.open', $n->id) }}">Open</a>
                        @unless($n->read_at)<button class="btn btn-sm btn-ghost" wire:click="markRead('{{ $n->id }}')">Mark read</button>@endunless
                    </div>
                </div>
            @empty
                <x-empty title="No notifications" icon="bell" />
            @endforelse
        </div>
        {{ $notifications->links() }}
    </section>
</div>
