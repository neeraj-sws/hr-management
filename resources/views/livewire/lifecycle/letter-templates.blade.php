<div>
    <div class="page-head">
        <div><h1>Letter templates</h1><p>Appointment, offer, NOC, relieving and experience letters. Write the wording once; make a letter from an employee's profile &gt; Docs &amp; assets &gt; Generate letter.</p></div>
        <div class="row-actions"><button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New template</button></div>
    </div>

    <section class="card mb">
        <div class="card-head"><div><h2>Templates</h2><p>{{ $templates->where('is_active', true)->count() }} on · {{ $templates->where('is_active', false)->count() }} off</p></div></div>
        <div class="list">
            @forelse($templates as $t)
                <div class="list-row" wire:key="lt-{{ $t->id }}">
                    <div><strong>{{ $t->name }}</strong><small>Saved as: {{ $categories[$t->category] ?? $t->category }} · changed {{ $t->updated_at?->format('d M Y') }}{{ $t->editor ? ' by '.$t->editor->name : '' }}</small></div>
                    <div class="row-actions">
                        <x-badge :tone="$t->is_active ? 'green' : 'gray'">{{ $t->is_active ? 'On' : 'Off' }}</x-badge>
                        <button class="btn btn-sm" wire:click="edit({{ $t->id }})">Edit</button>
                        <button class="btn btn-sm" wire:click="duplicate({{ $t->id }})">Copy</button>
                        <button class="btn btn-sm" wire:click="toggle({{ $t->id }})">{{ $t->is_active ? 'Turn off' : 'Turn on' }}</button>
                        <button class="btn btn-sm btn-danger" wire:click="delete({{ $t->id }})" wire:confirm="Delete the template “{{ $t->name }}”? Letters already issued stay in the documents.">Delete</button>
                    </div>
                </div>
            @empty
                <x-empty title="No templates yet" icon="file" />
            @endforelse
        </div>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Signature on letters</h2><p>Shown at the {signature} place in every letter (or at the end when a template has no {signature}).</p></div></div>
        <form class="card-body" wire:submit="saveSignatory">
            <div class="form-grid">
                <x-field label="Signed by (name)" name="signatoryName"><input type="text" wire:model="signatoryName" maxlength="120"></x-field>
                <x-field label="Designation" name="signatoryDesignation"><input type="text" wire:model="signatoryDesignation" maxlength="120" placeholder="Authorised Signatory"></x-field>
            </div>
            <p class="small muted">Company name, address, phone and e-mail on the letterhead come from Billing &gt; Settings (company details).</p>
            <div class="form-actions"><button class="btn btn-primary">Save signature</button></div>
        </form>
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit template' : 'New template'" size="lg">
            <form wire:submit="save">
                <div class="modal-body stack">
                    <div class="form-grid">
                        <x-field label="Template name" name="templateName"><input type="text" wire:model="templateName" maxlength="120" placeholder="e.g. Internship completion letter"></x-field>
                        <x-field label="Saved in the employee's documents as" name="templateCategory"><select wire:model="templateCategory">@foreach($categories as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                    </div>
                    <div>
                        <p class="small muted" style="margin:0 0 6px">Words in {curly brackets} are filled with the employee's data. Pick them from “Insert placeholder”.</p>
                        <x-letter-editor model="templateBody" :placeholders="$placeholders" :error="$errors->first('templateBody')" />
                    </div>
                    <details>
                        <summary class="small"><strong>Preview with an employee's data</strong></summary>
                        <div class="stack" style="margin-top:8px">
                            <div class="toolbar" style="border:0;padding:0">
                                <x-field label="Employee" name="previewEmployeeId"><select wire:model.live="previewEmployeeId"><option value="">Choose</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} · {{ $e->employee_code }}</option>@endforeach</select></x-field>
                                @if($previewEmployeeId)<button type="button" class="btn btn-sm" wire:click="$refresh">Refresh preview</button><button type="button" class="btn btn-sm" wire:click="samplePdf"><x-icon name="download" class="icon-sm" />Sample PDF</button>@endif
                            </div>
                            @if($preview)
                                @if($preview['missing'])<div class="alert alert-info" style="margin:0">Not in this employee's profile (shows as a blank line): {{ implode(', ', $preview['missing']) }}.</div>@endif
                                <div class="letter-preview">{!! str_replace('{signature}', '<em>[signature]</em>', $preview['html']) !!}</div>
                            @endif
                        </div>
                    </details>
                </div>
                <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save template</button></div>
            </form>
        </x-modal>
    @endif
</div>
