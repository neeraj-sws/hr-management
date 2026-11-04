<div>
    <div class="page-head">
        <div><h1>Help &amp; Support</h1><p>Have a question or a problem (attendance, leave, salary, tasks, documents, technical)? Raise a request and chat with the support team here. You are notified when they reply.</p></div>
        <div class="actions"><button class="btn btn-primary" wire:click="openForm"><x-icon name="plus" class="icon-sm" />New request</button></div>
    </div>

    <div class="tabs mb">
        @foreach(['active' => 'Open', 'finished' => 'Resolved & closed', 'all' => 'All'] as $key => $label)
            <button class="tab {{ $tab === $key ? 'active' : '' }}" wire:click="$set('tab', '{{ $key }}')">{{ $label }} <span class="muted">({{ $counts[$key] }})</span></button>
        @endforeach
    </div>

    <section class="card">
        <div class="table-wrap"><table class="table sup-table" data-no-sort>
            <thead><tr><th>Request</th><th>Subject</th><th>Category</th><th>Status</th><th>With</th><th>Last update</th><th></th></tr></thead>
            <tbody>
            @forelse($tickets as $t)
                <tr wire:key="my-sr-{{ $t->id }}" class="{{ in_array($t->id, $unread) ? 'sup-row-unread' : '' }}">
                    <td class="nowrap"><strong>@if(in_array($t->id, $unread))<span class="sup-dot" title="New reply"></span>@endif{{ $t->number() }}</strong><small>{{ $t->created_at->format('d M Y, h:i A') }}</small></td>
                    <td class="sup-subject"><a href="{{ route('support.show', $t->id) }}"><strong>{{ $t->subject }}</strong></a>@if(in_array($t->id, $unread))<small class="sup-new">New reply</small>@endif</td>
                    <td>{{ $t->category?->name }}</td>
                    <td><x-badge :tone="$statusTones[$t->status] ?? 'gray'">{{ $statuses[$t->status] ?? $t->status }}</x-badge></td>
                    <td>{{ $t->assignee?->name ?? 'Support team' }}</td>
                    <td class="nowrap"><small>{{ ($t->last_message_at ?? $t->updated_at)?->format('d M, h:i A') }}</small></td>
                    <td><a class="btn btn-sm" href="{{ route('support.show', $t->id) }}">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="No requests here" icon="message">Use “New request” to ask the support team anything.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $tickets->links() }}
    </section>

    @if($showForm)
        <x-modal title="New support request" size="lg">
            <form wire:submit="save">
                <div class="modal-body stack">
                    <div class="form-grid">
                        <x-field label="Category" name="category_id">
                            <select wire:model="category_id"><option value="">Choose</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                        </x-field>
                        <x-field label="Subject" name="subject"><input type="text" wire:model="subject" maxlength="200" placeholder="e.g. Check-out not saved on 2 Nov"></x-field>
                        <x-field label="Describe the problem" name="description" full><textarea rows="5" wire:model="description" maxlength="5000" placeholder="What happened, when, and what you expected. Add a screenshot if it helps."></textarea></x-field>
                        <x-field label="Screenshots / files" name="attachments" full hint="images or PDF, up to {{ $maxFiles }}, 5 MB each">
                            <input type="file" wire:model="attachments" multiple accept="image/*,application/pdf">
                        </x-field>
                    </div>
                    <div wire:loading wire:target="attachments" class="small muted"><span class="spinner"></span> Uploading…</div>
                    @error('attachments.*')<span class="error">{{ $message }}</span>@enderror
                    @if($attachments)
                        <div class="sup-files">
                            @foreach($attachments as $i => $file)
                                <span class="sup-file" wire:key="new-att-{{ $i }}"><x-icon name="file" class="icon-sm" />{{ \Illuminate\Support\Str::limit($file->getClientOriginalName(), 40) }}<button type="button" class="btn btn-ghost btn-sm" wire:click="removeAttachment({{ $i }})" aria-label="Remove"><x-icon name="x" class="icon-sm" /></button></span>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,attachments">Send request</button></div>
            </form>
        </x-modal>
    @endif
</div>
