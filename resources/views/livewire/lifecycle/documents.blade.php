<div>
    <div class="page-head"><div><h1>Documents</h1><p>Stored on private disk storage; every download is permission-checked and audited.</p></div>
        @if($canManage)<button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Upload document</button>@endif</div>
    @if($expiring)<div class="alert alert-info">{{ $expiring }} document(s) expired or expire within 30 days.</div>@endif
    <section class="card">
        <div class="toolbar">
            @if($canManage)<label class="field grow"><span>Employee</span><select wire:model.live="employee"><option value="">All employees</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>@endif
            <label class="field"><span>Category</span><select wire:model.live="category"><option value="">All</option>@foreach($categories as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Document</th>@if($canManage)<th>Employee</th>@endif<th>Category</th><th>Uploaded</th><th>Expires</th><th></th></tr></thead>
            <tbody>
            @forelse($documents as $d)
                <tr><td><strong>{{ $d->original_name }}</strong></td>@if($canManage)<td>{{ $d->employee?->name }}</td>@endif<td>{{ $categories[$d->category] ?? ucfirst($d->category) }}</td>
                    <td>{{ $d->created_at->format('d M Y') }}<small>{{ $d->uploader?->name }}</small></td>
                    <td>@if($d->expires_on){{ $d->expires_on->format('d M Y') }}@if($d->expires_on->lt(today()))<x-badge status="overdue">expired</x-badge>@elseif($d->expires_on->lte(today()->addDays(30)))<x-badge status="pending">soon</x-badge>@endif @else — @endif</td>
                    <td><div class="row-actions"><a class="btn btn-sm" href="{{ route('hr.documents.download', $d) }}"><x-icon name="download" class="icon-sm" />Download</a>@if($canManage)<button class="btn btn-sm btn-danger" wire:click="delete({{ $d->id }})" wire:confirm="Delete {{ $d->original_name }}? The file is removed permanently.">Delete</button>@endif</div></td></tr>
            @empty
                <tr><td colspan="6"><x-empty title="No documents" icon="file" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $documents->links() }}
    </section>
    @if($showForm)
        <x-modal title="Upload document">
            {{-- Upload state is tracked here (not with wire:loading on the file), so a failed or cancelled
                 upload can never leave the button disabled; the form stays usable after any error. --}}
            <form wire:submit="upload" x-data="{ uploading: false, progress: 0, failed: '' }"
                  x-on:livewire-upload-start="uploading = true; progress = 0; failed = ''"
                  x-on:livewire-upload-progress="progress = $event.detail.progress"
                  x-on:livewire-upload-finish="uploading = false"
                  x-on:livewire-upload-cancel="uploading = false"
                  x-on:livewire-upload-error="uploading = false; failed = 'The file could not be uploaded — it may be larger than the server allows (max 10 MB) or the connection dropped. Choose the file again.'">
                <div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Employee" name="employeeId" full><select wire:model.live="employeeId"><option value="">Select</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>
                <x-field label="Category" name="documentCategory"><select wire:model.live="documentCategory">@foreach($categories as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                <x-field label="Expiry date" name="expiresOn" hint="optional"><input type="date" wire:model.live="expiresOn"></x-field>
                <x-field label="File" name="file" hint="PDF, JPG, PNG or Word, max 10 MB" full>
                    <input type="file" wire:model="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" x-on:click="failed = ''">
                    <span x-show="uploading" class="small muted">Uploading… <span x-text="progress + '%'"></span></span>
                    <span x-show="failed" x-text="failed" class="error"></span>
                    @if($file && ! $errors->has('file'))<span class="small muted">Selected: {{ method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : '' }}</span>@endif
                </x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="upload"><span wire:loading wire:target="upload" class="spinner"></span>Upload</button></div></form>
        </x-modal>
    @endif
</div>
