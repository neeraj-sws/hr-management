<div>
    <div class="page-head"><div><h1>Devices</h1><p>Computers registered for attendance — {{ $counts['active'] }} active of {{ $counts['all'] }} · {{ $counts['online'] }} seen in the last 10 minutes.</p></div></div>
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Employee, computer name or Windows user"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">All</option><option value="active">Active</option><option value="revoked">Revoked</option><option value="stale">Not seen for 3+ days</option></select></label>
            <span wire:loading class="spinner" style="margin-bottom:10px"></span>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Employee</th><th>Computer name</th><th>Windows user</th><th>App version</th><th>Last seen</th><th>Access</th><th></th></tr></thead>
            <tbody>
            @forelse($devices as $d)
                <tr wire:key="dev-{{ $d->id }}">
                    <td><strong>{{ $d->employee?->name ?? '—' }}</strong><small>{{ $d->employee?->employee_code }}</small></td>
                    <td>{{ $d->computer_name ?: '—' }}<small class="muted">{{ \Illuminate\Support\Str::limit($d->device_id, 18) }}</small></td>
                    <td>{{ $d->windows_user ?: '—' }}</td>
                    <td>{{ $d->app_version ?: '—' }}</td>
                    <td class="nowrap">@if($d->last_seen_at){{ $d->last_seen_at->format('d M Y, h:i A') }}<small>{{ $d->last_seen_at->diffForHumans() }}</small>@else — @endif</td>
                    <td>@if(! $d->is_active)<x-badge tone="red">Revoked</x-badge>@elseif($d->last_seen_at && $d->last_seen_at->gte(now()->subMinutes(10)))<x-badge tone="green">Online</x-badge>@else<x-badge status="active">Active</x-badge>@endif @if($d->is_active && ! $d->personal_access_token_id)<small>waiting for sign-in</small>@endif</td>
                    <td><div class="row-actions">
                        @if($d->is_active)
                            <button class="btn btn-sm btn-danger" wire:click="revoke({{ $d->id }})" wire:confirm="Revoke {{ $d->computer_name ?: 'this device' }}? It stops sending attendance immediately.">Revoke</button>
                        @else
                            <button class="btn btn-sm" wire:click="activate({{ $d->id }})">Allow again</button>
                        @endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="No devices registered" icon="monitor">Computers appear here after the desktop app signs in.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $devices->links() }}
    </section>
</div>
