<div>
    <div class="page-head"><div><h1>Assets</h1><p>{{ $canManage ? 'Company equipment issued to employees. All assigned assets must be returned before offboarding can close.' : 'Equipment issued to you.' }}</p></div>
        @if($canManage)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Assign asset</button>@endif</div>
    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Tag, asset or employee"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="assigned">Assigned</option><option value="returned">Returned</option><option value="lost">Lost (unresolved)</option><option value="recovery_requested">Recovery requested</option><option value="written_off">Written off</option><option value="">All</option></select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Asset</th><th>Tag</th>@if($canManage)<th>Employee</th>@endif<th>Assigned</th><th>Returned</th><th>Notes</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($assets as $a)
                <tr><td><strong>{{ $a->name }}</strong></td><td>{{ $a->asset_tag }}</td>@if($canManage)<td>{{ $a->employee?->name }}</td>@endif
                    <td>{{ $a->assigned_on?->format('d M Y') ?? '—' }}</td><td>{{ $a->returned_on?->format('d M Y') ?? '—' }}</td><td class="small" style="white-space:pre-line">{{ $a->condition_notes ?? '—' }}</td>
                    <td><x-badge :status="$a->status" /></td><td><div class="row-actions">@if($canManage && $a->status === 'assigned')<button class="btn btn-sm" wire:click="openReturn({{ $a->id }})">Mark returned</button><button class="btn btn-sm btn-danger" wire:click="openLost({{ $a->id }}, 'lost')">Lost</button>@endif@if($canManage && $a->status === 'lost')<button class="btn btn-sm" wire:click="openLost({{ $a->id }}, 'write_off')">Write off</button><button class="btn btn-sm" wire:click="openLost({{ $a->id }}, 'recovery')">Request recovery</button>@endif</div>@if($a->resolution_note)<small style="white-space:pre-line">{{ $a->resolution_note }}</small>@endif</td></tr>
            @empty
                <tr><td colspan="8"><x-empty title="No assets" icon="monitor" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    @if($showForm)
        <x-modal title="Assign asset">
            <form wire:submit="assign"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Employee" name="employeeId" full><select wire:model="employeeId"><option value="">Select</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>
                <x-field label="Asset" name="name"><input wire:model="name" placeholder="Laptop — Dell Latitude 5440"></x-field>
                <x-field label="Asset tag" name="assetTag"><input wire:model="assetTag" placeholder="LAP-0042"></x-field>
                <x-field label="Condition notes" name="conditionNotes" full><textarea wire:model="conditionNotes" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Assign</button></div></form>
        </x-modal>
    @endif
    @if($lostId)
        <x-modal :title="['lost' => 'Mark asset as lost', 'write_off' => 'Write off lost asset', 'recovery' => 'Recover cost from salary'][$lostAction]">
            <form wire:submit="saveLost"><div class="modal-body"><div class="form-grid">
                @if($lostAction === 'recovery')<x-field label="Amount to recover" name="recoveryAmount" hint="goes for approval as a salary deduction"><input type="number" step="0.01" min="0.01" wire:model="recoveryAmount"></x-field>@endif
                <x-field label="Note" name="lostNote" full><textarea wire:model="lostNote" rows="3" placeholder="What happened, police report number, decision…"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif
    @if($returningId)
        <x-modal title="Return asset">
            <form wire:submit="markReturned"><div class="modal-body"><x-field label="Return condition notes" name="returnNotes"><textarea wire:model="returnNotes" rows="3" placeholder="e.g. Good condition, charger included"></textarea></x-field></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Mark returned</button></div></form>
        </x-modal>
    @endif
</div>
