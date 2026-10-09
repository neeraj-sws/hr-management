<div>
    <div class="page-head">
        <div><h1>Announcements</h1><p>Company news and notices. New announcements also arrive as a notification.</p></div>
        @if($canManage)<button class="btn btn-primary" wire:click="edit"><x-icon name="plus" class="icon-sm" />New announcement</button>@endif
    </div>
    <div class="stack">
        @forelse($announcements as $a)
            @php($expired = $a->expires_on && $a->expires_on->lt(today()))
            <section class="card card-body" wire:key="ann-{{ $a->id }}">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
                    <div>
                        <h2 style="margin:0">@if($a->is_pinned)<x-badge tone="violet">Pinned</x-badge> @endif{{ $a->title }}</h2>
                        <p class="small muted" style="margin:4px 0 0">{{ $a->published_at->format('d M Y, h:i A') }} · {{ $a->author?->name ?? 'HR' }}@if($canManage) · {{ $a->audienceLabel() }}@endif @if($a->expires_on) · {{ $expired ? 'expired' : 'until '.$a->expires_on->format('d M Y') }}@endif</p>
                    </div>
                    @if($canManage)<div class="row-actions"><button class="btn btn-sm" wire:click="edit({{ $a->id }})">Edit</button><button class="btn btn-sm btn-danger" wire:click="delete({{ $a->id }})" wire:confirm="Delete this announcement?">Delete</button></div>@endif
                </div>
                <div class="mt" style="white-space:pre-line;line-height:1.6">{{ $a->body }}</div>
            </section>
        @empty
            <section class="card"><x-empty title="No announcements yet" icon="bell" /></section>
        @endforelse
        {{ $announcements->links() }}
    </div>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit announcement' : 'New announcement'" size="lg">
            <form wire:submit="save"><div class="modal-body"><div class="form-grid">
                <x-field label="Title" name="title" full><input wire:model="title" maxlength="200"></x-field>
                <x-field label="Message" name="body" full><textarea wire:model="body" rows="7"></textarea></x-field>
                <x-field label="Send to" name="audience"><select wire:model.live="audience"><option value="all">Everyone</option><option value="department">One department</option><option value="team">One team</option></select></x-field>
                @if($audience === 'department')<x-field label="Department" name="departmentId"><select wire:model="departmentId"><option value="">Choose…</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-field>@endif
                @if($audience === 'team')<x-field label="Team" name="teamId"><select wire:model="teamId"><option value="">Choose…</option>@foreach($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></x-field>@endif
                <x-field label="Show until" name="expiresOn" hint="blank = always"><input type="date" wire:model="expiresOn" min="{{ today()->toDateString() }}"></x-field>
                <label class="check"><input type="checkbox" wire:model="isPinned"> Pin to the top</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">{{ $editingId ? 'Save' : 'Publish' }}</button></div></form>
        </x-modal>
    @endif
</div>
