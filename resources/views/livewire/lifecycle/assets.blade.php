<div>
    <div class="page-head"><div><h1>Assets</h1><p>{{ $canManage ? 'Company equipment — add assets to inventory, then assign to employees. All assigned assets must be returned before offboarding can close.' : 'Equipment issued to you — current and returned.' }}</p></div>
        @if($canManage)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add asset</button>@endif</div>
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Tag, asset or employee"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">All</option><option value="available">Available</option><option value="assigned">Assigned</option><option value="returned">Returned</option><option value="lost">Lost (unresolved)</option><option value="recovery_requested">Recovery requested</option><option value="written_off">Written off</option></select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Asset</th><th>Tag</th>@if($canManage)<th>Employee</th>@endif<th>Assigned</th><th>Returned</th><th>Notes</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($assets as $a)
                @php($pastHolder = ! $canManage && $own && $a->employee_id !== $own->id)
                <tr wire:key="asset-{{ $a->id }}"><td><strong>{{ $a->name }}</strong></td><td>{{ $a->asset_tag }}</td>@if($canManage)<td>{{ $a->employee?->name ?? '—' }}</td>@endif
                    <td>{{ $pastHolder ? '—' : ($a->assigned_on?->format('d M Y') ?? '—') }}</td><td>{{ $a->returned_on?->format('d M Y') ?? ($pastHolder ? 'returned' : '—') }}</td><td class="small" style="white-space:pre-line">{{ $pastHolder ? '—' : ($a->condition_notes ?? '—') }}</td>
                    <td>@if($pastHolder)<x-badge status="returned">Returned</x-badge><small>by you earlier</small>@elseif($a->status === 'available')<x-badge status="available">Available</x-badge>@else<x-badge :status="$a->status">{{ $a->status === 'returned' ? 'Returned' : '' }}</x-badge>@endif</td>
                    <td><div class="row-actions">
                        @if($a->events_count)<button class="btn btn-sm" wire:click="openHistory({{ $a->id }})">History</button>@endif
                        @if($canManage && in_array($a->status, ['available', 'returned']))
                            <button class="btn btn-sm btn-primary" wire:click="openAssign({{ $a->id }})">Assign</button>
                            <button class="btn btn-sm" wire:click="edit({{ $a->id }})">Edit</button>
                            <button class="btn btn-sm btn-danger" wire:click="confirmDelete({{ $a->id }})">Remove</button>
                        @endif
                        @if($canManage && $a->status === 'assigned')
                            <button class="btn btn-sm" wire:click="edit({{ $a->id }})">Edit</button>
                            <button class="btn btn-sm" wire:click="openReturn({{ $a->id }})">Mark returned</button>
                            <button class="btn btn-sm btn-danger" wire:click="openLost({{ $a->id }}, 'lost')">Lost</button>
                        @endif
                        @if($canManage && $a->status === 'lost')<button class="btn btn-sm" wire:click="openLost({{ $a->id }}, 'write_off')">Write off</button><button class="btn btn-sm" wire:click="openLost({{ $a->id }}, 'recovery')">Request recovery</button>@endif
                    </div>@if($a->resolution_note && ! $pastHolder)<small style="white-space:pre-line">{{ $a->resolution_note }}</small>@endif</td></tr>
            @empty
                <tr><td colspan="8"><x-empty title="No assets" icon="monitor" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $assets->links() }}
    </section>

    {{-- Add / Edit asset --}}
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit asset' : 'Add asset to inventory'">
            <form wire:submit="{{ $editingId ? 'saveEdit' : 'store' }}"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Asset name" name="name"><input wire:model="name" placeholder="Laptop — Dell Latitude 5440"></x-field>
                <x-field label="Asset tag" name="assetTag"><input wire:model="assetTag" placeholder="LAP-0042"></x-field>
                <x-field label="Condition notes" name="conditionNotes" full><textarea wire:model="conditionNotes" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">{{ $editingId ? 'Save changes' : 'Add to inventory' }}</button></div></form>
        </x-modal>
    @endif

    {{-- Assign asset to employee --}}
    @if($assigning)
        <x-modal title="Assign {{ $assigning->name }} ({{ $assigning->asset_tag }})">
            <form wire:submit="assign"><div class="modal-body"><x-errors />
                <x-field label="Employee" name="employeeId" full><select wire:model="employeeId"><option value="">Select employee</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Assign</button></div></form>
        </x-modal>
    @endif

    {{-- Confirm delete --}}
    @if($deletingId)
        <x-modal title="Remove asset">
            <div class="modal-body"><p>This will permanently remove the asset from the company inventory. This cannot be undone.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-danger" wire:click="deleteAsset" wire:loading.attr="disabled">Remove</button></div>
        </x-modal>
    @endif

    {{-- Lost / Write-off / Recovery --}}
    @if($lostId)
        <x-modal :title="['lost' => 'Mark asset as lost', 'write_off' => 'Write off lost asset', 'recovery' => 'Recover cost from salary'][$lostAction]">
            <form wire:submit="saveLost"><div class="modal-body"><x-errors /><div class="form-grid">
                @if($lostAction === 'recovery')<x-field label="Amount to recover" name="recoveryAmount" hint="goes for approval as a salary deduction"><input type="number" step="0.01" min="0.01" wire:model="recoveryAmount"></x-field>@endif
                <x-field label="Note" name="lostNote" full><textarea wire:model="lostNote" rows="3" placeholder="What happened, police report number, decision…"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif

    {{-- Mark returned --}}
    @if($returningId)
        <x-modal title="Return asset">
            <form wire:submit="markReturned"><div class="modal-body"><x-field label="Return condition notes" name="returnNotes"><textarea wire:model="returnNotes" rows="3" placeholder="e.g. Good condition, charger included"></textarea></x-field><p class="small muted">The asset stays in the list as "Returned", with who had it and when.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Mark returned</button></div></form>
        </x-modal>
    @endif

    {{-- Issue again --}}
    @if($reissuing)
        <x-modal title="Issue {{ $reissuing->name }} again">
            <form wire:submit="reissue"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Employee" name="reissueEmployeeId" full><select wire:model="reissueEmployeeId"><option value="">Select</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>
                <x-field label="Condition notes" name="reissueNotes" full><textarea wire:model="reissueNotes" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Issue</button></div></form>
        </x-modal>
    @endif

    {{-- History --}}
    @if($history)
        <x-modal title="History · {{ $history->name }} ({{ $history->asset_tag }})">
            <div class="modal-body">
                <div class="list">
                    @forelse($history->events as $event)
                        @continue(! $canManage && $own && $event->employee_id !== $own->id)
                        <div class="list-row"><div><strong>{{ str($event->action)->replace('_', ' ')->ucfirst() }} @if($event->employee)· {{ $event->employee->name }}@endif</strong><small>{{ $event->happened_on->format('d M Y') }}@if($event->recorder) · recorded by {{ $event->recorder->name }}@endif @if($event->notes) · {{ $event->notes }}@endif</small></div><x-badge :status="$event->action" /></div>
                    @empty
                        <p class="small muted">No history recorded.</p>
                    @endforelse
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Close</button></div>
        </x-modal>
    @endif
</div>
