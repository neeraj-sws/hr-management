<section class="card">
    <form class="card-body" wire:submit="saveStructure">
        <h2 style="margin-top:0">Salary structure template</h2>
        <p class="small muted">Used for employees whose salary profile has no own components. Earnings are a % of the monthly salary (any remainder is paid as "Balance allowance"); fixed deductions are monthly amounts. You decide every number — nothing statutory is built in.</p>
        <div class="table-wrap"><table class="table"><thead><tr><th>Component</th><th>Type</th><th class="num">% of salary / amount</th><th></th></tr></thead><tbody>
            @forelse($structureRows as $i => $row)
                <tr wire:key="st-{{ $i }}">
                    <td><input class="input" wire:model="structureRows.{{ $i }}.name" placeholder="Basic" aria-label="Component name">@error('structureRows.'.$i.'.name')<span class="error">{{ $message }}</span>@enderror</td>
                    <td><select class="input" wire:model.live="structureRows.{{ $i }}.type" aria-label="Type"><option value="earning">Earning (%)</option><option value="deduction">Fixed deduction (amount)</option></select></td>
                    <td class="num"><input class="input" type="number" step="0.01" min="0" wire:model="structureRows.{{ $i }}.value" aria-label="Value">@error('structureRows.'.$i.'.value')<span class="error">{{ $message }}</span>@enderror</td>
                    <td><button type="button" class="btn btn-sm" wire:click="removeStructureRow({{ $i }})" aria-label="Remove">&times;</button></td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted small">No template — salaries without components are paid as one Basic line.</td></tr>
            @endforelse
        </tbody></table></div>
        @error('structureRows')<span class="error">{{ $message }}</span>@enderror
        <div class="form-actions">
            <button type="button" class="btn btn-sm" wire:click="addStructureRow('earning')">+ Earning</button>
            <button type="button" class="btn btn-sm" wire:click="addStructureRow('deduction')">+ Fixed deduction</button>
            <button class="btn btn-primary">Save template</button>
        </div>
    </form>
</section>
