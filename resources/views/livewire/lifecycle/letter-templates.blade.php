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
                        @if($t->changeable())<button class="btn btn-sm" wire:click="edit({{ $t->id }})">Edit</button>@endif
                        <button class="btn btn-sm" wire:click="duplicate({{ $t->id }})">Copy</button>
                        @if($t->changeable())<button class="btn btn-sm" wire:click="toggle({{ $t->id }})">{{ $t->is_active ? 'Turn off' : 'Turn on' }}</button>
                        <button class="btn btn-sm btn-danger" wire:click="delete({{ $t->id }})" wire:confirm="Delete the template “{{ $t->name }}”? Letters already issued stay in the documents.">Delete</button>@endif
                    </div>
                </div>
            @empty
                <x-empty title="No templates yet" icon="file" />
            @endforelse
        </div>
    </section>

    <section class="card mb">
        <div class="card-head"><div><h2>Letterhead</h2><p>Printed at the top of every letter, with the logo. The same details are used by Billing (if it is in use).</p></div></div>
        <form class="card-body" wire:submit="saveCompany">
            <div class="form-grid">
                @foreach($companyFields as $key => $label)
                    <x-field :label="$label" :name="'company.'.$key"><input type="{{ $key === 'email' ? 'email' : 'text' }}" wire:model="company.{{ $key }}" maxlength="200"></x-field>
                @endforeach
            </div>
            <div class="form-actions"><button class="btn btn-primary">Save letterhead</button></div>
        </form>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Signature on letters</h2><p>Shown at the {signature} place in every letter (or at the end when a template has no {signature}).</p></div></div>
        <form class="card-body" wire:submit="saveSignatory">
            <div class="form-grid">
                <x-field label="Signed by (name)" name="signatoryName"><input type="text" wire:model="signatoryName" maxlength="120"></x-field>
                <x-field label="Designation" name="signatoryDesignation"><input type="text" wire:model="signatoryDesignation" maxlength="120" placeholder="Authorised Signatory"></x-field>
            </div>
            <div class="form-actions"><button class="btn btn-primary">Save signature</button></div>
        </form>
        {{-- Signature and seal images: printed on every new letter and certificate (signature above the line, seal beside it). --}}
        <div class="card-body" style="border-top:1px solid var(--line-2)">
            <div class="form-grid">
                @foreach(['signature' => 'Signature image', 'seal' => 'Company seal (stamp)'] as $kind => $label)
                    <div wire:key="sign-{{ $kind }}">
                        <x-field :label="$label" :name="$kind.'Upload'" :hint="$signImages[$kind] ? 'saved: printed on new letters' : 'PNG or JPG, up to 2 MB'">
                            <input type="file" accept=".png,.jpg,.jpeg" wire:model="{{ $kind }}Upload">
                        </x-field>
                        <div class="row-actions" style="margin-top:6px">
                            <button type="button" class="btn btn-sm btn-primary" wire:click="uploadSignImage('{{ $kind }}')" wire:loading.attr="disabled" @disabled(! ${$kind.'Upload'})>Save {{ $kind === 'seal' ? 'seal' : 'signature' }}</button>
                            @if($signImages[$kind])<x-badge tone="green">On letters</x-badge><button type="button" class="btn btn-sm btn-danger" wire:click="removeSignImage('{{ $kind }}')" wire:confirm="Remove it from new letters?">Remove</button>@endif
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="small muted" style="margin:8px 0 0">Tip: a signature on a white or transparent background looks best. Check it with "Sample PDF" in a template.</p>
        </div>
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit template' : 'New template'" size="lg">
            <form wire:submit="save">
                <div class="modal-body stack">
                    <div class="form-grid">
                        <x-field label="Template name" name="templateName"><input type="text" wire:model="templateName" maxlength="120" placeholder="e.g. Internship completion letter"></x-field>
                        <x-field label="Saved in the employee's documents as" name="templateCategory"><select wire:model="templateCategory">@foreach($categories as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                    </div>
                    <div class="toolbar" style="border:0;padding:0;align-items:flex-end">
                        <x-field label="Start from a Word file (.docx)" name="wordFile" hint="its text replaces what is below"><input type="file" accept=".docx" wire:model="wordFile"></x-field>
                        <button type="button" class="btn btn-sm" wire:click="importWord" wire:loading.attr="disabled" @disabled(! $wordFile)>Take in Word file</button>
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
