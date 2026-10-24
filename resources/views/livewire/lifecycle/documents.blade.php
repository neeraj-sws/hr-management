<div class="docs-page">
    <div class="page-head"><div><h1>Documents</h1><p class="docs-sub"><x-icon name="lock" class="icon-sm" />Private storage · every download is checked and logged</p></div>
        <div class="row-actions">@if($canManage)<button class="btn" wire:click="editRequiredList"><x-icon name="check" class="icon-sm" />Required at onboarding</button>@endif
        @if($canUpload)<button class="btn btn-primary" wire:click="create"><x-icon name="upload" class="icon-sm" />Upload document</button>@endif</div></div>
    @if($expiring)<div class="alert alert-info">{{ $expiring }} document(s) expired or expire within 30 days.</div>@endif

    @if($canManage)
        <div class="toolbar docs-employee"><label class="field grow"><span>Employee</span><select wire:model.live="employee"><option value="">All employees</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label></div>
    @endif

    {{-- Card 1: onboarding checklist. Missing items first, then the done chips. --}}
    @if($checklistFor && count($checklist))
        @php($missingItems = collect($checklist)->where('done', false)->values())
        @php($doneItems = collect($checklist)->where('done', true)->values())
        @php($total = count($checklist))
        <section class="docs-card mb">
            <div class="docs-onb-head">
                <div><h2>Onboarding documents{{ $canManage ? ' · '.$checklistFor->name : '' }}</h2>
                    <p class="muted small">@if($missingItems->count()){{ $missingItems->count() }} document{{ $missingItems->count() > 1 ? 's' : '' }} left.@else All documents handed in.@endif {{ $canManage ? '' : 'HR is notified as soon as you upload.' }}</p></div>
                <div class="docs-onb-count"><strong>{{ $doneItems->count() }} / {{ $total }}</strong><span>done</span></div>
            </div>
            <div class="docs-progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $doneItems->count() }}" aria-label="Onboarding documents done"><span style="width:{{ $total ? round($doneItems->count() / $total * 100) : 0 }}%"></span></div>
            @if($missingItems->count())
                <h3 class="docs-section">Missing ({{ $missingItems->count() }})</h3>
                <div class="docs-missing">
                    @foreach($missingItems as $item)
                        <div class="docs-missing-row">
                            <span class="docs-icon docs-icon-missing"><x-icon :name="$item['icon']" class="icon-sm" /></span>
                            <div class="grow"><strong>{{ \Illuminate\Support\Str::before($item['label'], ' (') }}</strong><small>{{ $item['hint'] }}</small></div>
                            @if($canUpload)<button type="button" class="btn btn-sm btn-primary" wire:click="create('{{ $item['key'] }}')" aria-label="Upload {{ $item['label'] }}">Upload</button>@endif
                        </div>
                    @endforeach
                </div>
            @endif
            @if($doneItems->count())
                <h3 class="docs-section">Done ({{ $doneItems->count() }})</h3>
                <div class="docs-done">
                    @foreach($doneItems as $item)
                        <span class="docs-done-chip"><x-icon name="check" class="icon-sm" />{{ \Illuminate\Support\Str::before($item['label'], ' (') }}@if($item['key'] !== 'photo') · {{ $item['count'] }} file{{ $item['count'] > 1 ? 's' : '' }}@endif
                            @if($canUpload)<button type="button" wire:click="create('{{ $item['key'] }}')" aria-label="Add more: {{ $item['label'] }}">· Add more</button>@endif</span>
                    @endforeach
                </div>
            @endif
        </section>
    @elseif($canManage && ! $checklistFor)
        <p class="small muted">Pick an employee above to see which onboarding documents they still have to hand in.</p>
    @endif

    {{-- Card 2: files. The chips and the search filter in the browser (no reload). --}}
    @php($chips = ['all' => 'All', 'identity' => 'Identity', 'education' => 'Education', 'bank' => 'Bank', 'other' => 'Other'])
    @php($index = $files->map(fn ($f) => [$f['group'], mb_strtolower($f['name'].' '.$f['category'].' '.($f['employee'] ?? ''))])->all())
    <section class="docs-card" x-data="{ group: 'all', q: '', files: @js($index), shown(i) { const f = this.files[i]; return (this.group === 'all' || this.group === f[0]) && f[1].includes(this.q.trim().toLowerCase()); }, get none() { return ! this.files.some((f, i) => this.shown(i)); } }">
        <div class="docs-files-head">
            <h2>{{ $canManage ? ($checklistFor ? 'Files' : 'All files') : 'My files' }} ({{ $groupCounts['all'] }})</h2>
            <label class="docs-search"><x-icon name="search" class="icon-sm" /><input type="search" x-model="q" placeholder="Search files" aria-label="Search files"></label>
        </div>
        <div class="docs-chips" role="group" aria-label="Filter files by category">
            @foreach($chips as $key => $label)
                <button type="button" class="docs-chip" :class="group === '{{ $key }}' && 'is-active'" :aria-pressed="group === '{{ $key }}' ? 'true' : 'false'" x-on:click="group = '{{ $key }}'">{{ $label }} · {{ $groupCounts[$key] }}</button>
            @endforeach
        </div>
        <ul class="docs-list">
            @foreach($files as $i => $f)
                @php($daysLeft = $f['expires'] ? today()->diffInDays($f['expires'], false) : null)
                <li class="docs-file" wire:key="doc-{{ $f['id'] ?? 'photo' }}" x-show="shown({{ $i }})">
                    <span class="docs-icon docs-kind-{{ $f['kind'] }}"><x-icon :name="$f['kind'] === 'image' ? 'image' : 'file'" class="icon-sm" /></span>
                    <div class="docs-file-main">
                        <strong>{{ $f['name'] }}</strong>
                        <small>@if($canManage && $f['employee'] && ! $checklistFor){{ $f['employee'] }} · @endif{{ $f['category'] }}@if($f['size']) · {{ $f['size'] >= 1048576 ? number_format($f['size'] / 1048576, 1).' MB' : max(1, round($f['size'] / 1024)).' KB' }}@endif
                            @if($f['expires'] && $daysLeft < 0)<span class="docs-exp docs-exp-red">Expired {{ $f['expires']->format('d M Y') }}</span>@elseif($f['expires'] && $daysLeft <= 30)<span class="docs-exp docs-exp-orange">Expires {{ $f['expires']->format('d M Y') }}</span>@endif</small>
                        <small class="docs-file-date">{{ $f['date']->format('d M Y') }}@if($f['by']) · by {{ $f['by'] }}@endif</small>
                    </div>
                    <div class="docs-file-actions">
                        <a class="btn btn-sm btn-icon" href="{{ $f['view'] }}" target="_blank" rel="noopener" aria-label="View {{ $f['name'] }}" title="View"><x-icon name="eye" class="icon-sm" /></a>
                        <a class="btn btn-sm" href="{{ $f['download'] }}" @if(! $f['id']) download="profile-photo" @endif><x-icon name="download" class="icon-sm" /><span>Download</span></a>
                        @if($f['canDelete'] && $f['id'])<button class="btn btn-sm btn-icon btn-danger" wire:click="delete({{ $f['id'] }})" wire:confirm="Delete {{ $f['name'] }}? The file is removed permanently." aria-label="Delete {{ $f['name'] }}" title="Delete"><x-icon name="trash" class="icon-sm" /></button>@endif
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="docs-empty" x-show="none" @if($files->isNotEmpty()) x-cloak @endif>
            <x-icon name="file" />
            <p>No files in this category yet</p>
            @if($canUpload)<button type="button" class="btn btn-primary btn-sm" wire:click="create"><x-icon name="upload" class="icon-sm" />Upload</button>@endif
        </div>
    </section>
    @if($showForm)
        <x-modal title="Upload document">
            {{-- Upload state is tracked here (not with wire:loading on the file), so a failed or cancelled
                 upload can never leave the button disabled; the form stays usable after any error. --}}
            <form wire:submit="saveDocument" x-data="{ uploading: false, progress: 0, failed: '' }"
                  x-on:livewire-upload-start="uploading = true; progress = 0; failed = ''"
                  x-on:livewire-upload-progress="progress = $event.detail.progress"
                  x-on:livewire-upload-finish="uploading = false"
                  x-on:livewire-upload-cancel="uploading = false"
                  x-on:livewire-upload-error="uploading = false; failed = 'The file could not be uploaded — it may be larger than the server allows (max 10 MB) or the connection dropped. Choose the file again.'">
                <div class="modal-body"><x-errors /><div class="form-grid">
                @if($canManage)<x-field label="Employee" name="employeeId" full><select wire:model.live="employeeId"><option value="">Select</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>@endif
                <x-field label="Document" name="documentCategory"><select wire:model.live="documentCategory">@foreach($uploadCategories as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                @if($documentCategory !== 'photo')<x-field label="Expiry date" name="expiresOn" hint="optional (e.g. passport)"><input type="date" wire:model.live="expiresOn"></x-field>@endif
                <x-field label="File" name="file" :hint="$documentCategory === 'photo' ? 'JPG, PNG or WebP, max 3 MB — becomes your profile photo' : 'PDF, JPG, PNG or Word, max 10 MB'" full>
                    <input type="file" wire:model="file" accept="{{ $documentCategory === 'photo' ? '.jpg,.jpeg,.png,.webp' : '.pdf,.jpg,.jpeg,.png,.doc,.docx' }}" x-on:click="failed = ''">
                    <span x-show="uploading" class="small muted">Uploading… <span x-text="progress + '%'"></span></span>
                    <span x-show="failed" x-text="failed" class="error"></span>
                    @if($file && ! $errors->has('file'))<span class="small muted">Selected: {{ method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : '' }}</span>@endif
                </x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="saveDocument"><span wire:loading wire:target="saveDocument" class="spinner"></span>Upload</button></div></form>
        </x-modal>
    @endif
    @if($editRequired)
        <x-modal title="Required at onboarding">
            <form wire:submit="saveRequired">
                <div class="modal-body"><p class="small muted" style="margin-top:0">Every employee sees these as a checklist on their Documents page (web and app) and can upload them; HR is told on each upload.</p>
                    <div class="stack">@foreach($requirable as $key => $label)<label class="check"><input type="checkbox" value="{{ $key }}" wire:model="required"> {{ $label }}</label>@endforeach</div></div>
                <div class="modal-foot"><button type="button" class="btn" wire:click="$set('editRequired', false)">Cancel</button><button class="btn btn-primary">Save</button></div>
            </form>
        </x-modal>
    @endif
</div>
