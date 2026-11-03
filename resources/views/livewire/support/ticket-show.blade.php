<div>
    <div class="page-head">
        <div>
            <p class="small" style="margin:0 0 4px"><a href="{{ $staff ? route('support.desk') : route('support.my') }}"><x-icon name="arrow-left" class="icon-sm" />{{ $staff ? 'Support desk' : 'My requests' }}</a></p>
            <h1>{{ $ticket->subject }}</h1>
            <p>{{ $ticket->number() }} · {{ $ticket->category?->name }} · raised {{ $ticket->created_at->format('d M Y, h:i A') }}@if($staff) by {{ $ticket->employee?->name }} ({{ $ticket->employee?->employee_code }})@endif</p>
        </div>
        <div class="actions">
            <x-badge :tone="$statusTones[$ticket->status] ?? 'gray'">{{ $statuses[$ticket->status] ?? $ticket->status }}</x-badge>
            @if($staff)<x-badge :tone="$priorityTones[$ticket->priority] ?? 'gray'">{{ $priorities[$ticket->priority] ?? $ticket->priority }} priority</x-badge>@endif
        </div>
    </div>

    <div class="sup-layout">
        <section class="card sup-chat-card" wire:poll.10s.visible>
            <div class="card-head"><div><h2>Conversation</h2><p>Updates by itself every few seconds.{{ $staff ? ' Internal notes (yellow) are never shown to the employee.' : '' }}</p></div></div>
            <div class="sup-chat" x-data x-init="$el.scrollTop = $el.scrollHeight" x-on:support-sent.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
                @foreach($messages as $m)
                    @php
                        $mine = (int) $m->user_id === (int) auth()->id();
                        $hiddenForEmployee = $m->hidden_at && ! $staff;
                        $seen = $mine && ! $m->is_internal ? ($m->sender_type === 'staff' ? ($employeeRead && $employeeRead->gt($m->created_at)) : ($staffRead && \Illuminate\Support\Carbon::parse($staffRead)->gt($m->created_at))) : false;
                    @endphp
                    <article class="sup-msg {{ $mine ? 'sup-msg-mine' : '' }} {{ $m->sender_type === 'staff' ? 'sup-msg-staff' : '' }} {{ $m->is_internal ? 'sup-msg-internal' : '' }} {{ $m->hidden_at ? 'sup-msg-hidden' : '' }}" wire:key="msg-{{ $m->id }}">
                        <header class="sup-msg-head">
                            <x-avatar :name="$m->user?->name ?? '?'" />
                            <div><strong>{{ $m->user?->name ?? 'Former user' }}</strong><small>{{ $support->roleLabel($m->user, $ticket, $m->sender_type) }} · {{ $m->created_at->format('d M Y, h:i A') }}</small></div>
                            @if($m->is_internal)<x-badge tone="amber">Internal note · staff only</x-badge>@endif
                            @if($manage && ! $m->hidden_at && ! $m->is_internal)<button type="button" class="btn btn-ghost btn-sm sup-msg-tool" wire:click="openHide({{ $m->id }})" title="Hide from the employee">Hide</button>@endif
                        </header>
                        @if($hiddenForEmployee)
                            <p class="sup-msg-body muted"><em>This message was removed by the support admin.</em></p>
                        @else
                            @if($m->body !== '')<div class="sup-msg-body">{!! nl2br(e($m->body)) !!}</div>@endif
                            @if($m->attachments->isNotEmpty())
                                <div class="sup-files">
                                    @foreach($m->attachments as $a)
                                        @if($a->isImage())
                                            <a class="sup-thumb" href="{{ route('support.attachments.show', $a) }}" target="_blank" rel="noopener" title="{{ $a->original_name }}"><img src="{{ route('support.attachments.show', $a) }}" alt="{{ $a->original_name }}" loading="lazy"></a>
                                        @else
                                            <a class="sup-file" href="{{ route('support.attachments.show', $a) }}" target="_blank" rel="noopener"><x-icon name="file" class="icon-sm" />{{ \Illuminate\Support\Str::limit($a->original_name, 40) }} <small>{{ $a->sizeLabel() }}</small></a>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                            @if($m->hidden_at)<p class="sup-msg-note"><x-icon name="eye-off" class="icon-sm" />Hidden from the employee by {{ $m->hider?->name ?? 'admin' }}, {{ $m->hidden_at->format('d M, h:i A') }}: {{ $m->hidden_reason }}</p>@endif
                        @endif
                        @if($seen)<small class="sup-seen"><x-icon name="checks" class="icon-sm" />Seen</small>@endif
                    </article>
                @endforeach
            </div>

            {{-- Reply --}}
            @if(! $staff && $ticket->status === 'resolved')
                <div class="sup-closed-note">
                    <p><strong>This request is resolved.</strong> @if($canReopen)Not solved? You can reopen it until {{ $reopenUntil->format('d M Y') }}.@else To ask something more, raise a new request.@endif</p>
                    @if($canReopen)<button class="btn btn-primary" wire:click="openReopen"><x-icon name="refresh" class="icon-sm" />Reopen request</button>@else<a class="btn" href="{{ route('support.my') }}">New request</a>@endif
                </div>
            @elseif(! $staff && $ticket->status === 'closed')
                <div class="sup-closed-note"><p><strong>This request is closed.</strong> To ask something more, raise a new request.</p><a class="btn" href="{{ route('support.my') }}">New request</a></div>
            @else
                <form class="sup-compose" wire:submit="send">
                    <x-errors />
                    <textarea rows="3" wire:model="body" maxlength="5000" placeholder="{{ $staff ? 'Write a reply to '.($ticket->employee?->name ?? 'the employee').'…' : 'Write a message…' }}" aria-label="Message"></textarea>
                    @if($files)
                        <div class="sup-files">
                            @foreach($files as $i => $file)
                                <span class="sup-file" wire:key="up-{{ $i }}"><x-icon name="file" class="icon-sm" />{{ \Illuminate\Support\Str::limit($file->getClientOriginalName(), 40) }}<button type="button" class="btn btn-ghost btn-sm" wire:click="removeFile({{ $i }})" aria-label="Remove"><x-icon name="x" class="icon-sm" /></button></span>
                            @endforeach
                        </div>
                    @endif
                    <div class="sup-compose-bar">
                        <label class="btn btn-sm sup-attach"><x-icon name="upload" class="icon-sm" />Attach<input type="file" wire:model="files" multiple accept="image/*,application/pdf" class="sr-only"></label>
                        <span wire:loading wire:target="files" class="small muted"><span class="spinner"></span> Uploading…</span>
                        @if($staff)
                            <label class="check"><input type="checkbox" wire:model.live="internal"> Internal note (staff only)</label>
                            <select wire:model="replyStatus" aria-label="Status after sending" class="sup-compose-status">
                                <option value="">Keep status ({{ $statuses[$ticket->status] }})</option>
                                @foreach($statuses as $key => $label)@if($key !== $ticket->status)<option value="{{ $key }}">Set to {{ $label }}</option>@endif @endforeach
                            </select>
                        @endif
                        <span class="sup-grow"></span>
                        <button class="btn {{ $internal ? '' : 'btn-primary' }}" wire:loading.attr="disabled" wire:target="send,files"><x-icon name="arrow-right" class="icon-sm" />{{ $internal ? 'Add note' : 'Send' }}</button>
                    </div>
                    <small class="muted">Images or PDF, up to {{ $maxFiles }} files, 5 MB each. Messages cannot be edited or deleted after sending.</small>
                </form>
            @endif
        </section>

        <aside class="stack">
            <section class="card">
                <div class="card-head"><div><h2>Details</h2></div></div>
                <div class="card-body sup-details">
                    @if($staff)
                        <div class="sup-kv"><span>Employee</span><strong>@if(\Illuminate\Support\Facades\Route::has('people.employees.show') && $ticket->employee)<a href="{{ route('people.employees.show', $ticket->employee) }}">{{ $ticket->employee->name }}</a>@else{{ $ticket->employee?->name }}@endif</strong><small>{{ $ticket->employee?->employee_code }}{{ $ticket->employee?->designation ? ' · '.$ticket->employee->designation : '' }}</small></div>
                        <x-field label="Status" name="status"><select wire:model="status" wire:change="saveStatus">@foreach($statuses as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                        <x-field label="Priority" name="priority"><select wire:model="priority" wire:change="savePriority">@foreach($priorities as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                        @if($manage)
                            <x-field label="Assigned to" name="assignee"><select wire:model="assignee" wire:change="saveAssignee"><option value="">Nobody</option>@foreach($handlers as $h)<option value="{{ $h->id }}">{{ $h->name }}</option>@endforeach</select></x-field>
                            <x-field label="Category" name="category"><select wire:model="category" wire:change="saveCategory">@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}{{ $c->is_restricted ? ' (restricted)' : '' }}</option>@endforeach</select></x-field>
                        @else
                            <div class="sup-kv"><span>Assigned to</span><strong>{{ $ticket->assignee?->name ?? 'Nobody yet' }}</strong></div>
                            @if(! $ticket->assigned_to)<button class="btn btn-primary btn-sm" wire:click="takeIt">Take this request</button>@endif
                            <div class="sup-kv"><span>Category</span><strong>{{ $ticket->category?->name }}</strong></div>
                        @endif
                        @error('category')<span class="error">{{ $message }}</span>@enderror
                        <div class="sup-kv"><span>First reply</span><strong>{{ $ticket->first_response_at ? $ticket->first_response_at->format('d M, h:i A') : 'Not yet' }}</strong></div>
                        @if($ticket->resolved_at)<div class="sup-kv"><span>Resolved</span><strong>{{ $ticket->resolved_at->format('d M Y, h:i A') }}</strong><small>{{ \App\Support\Duration::words((int) $ticket->created_at->diffInMinutes($ticket->resolved_at)) }} after it was raised</small></div>@endif
                        @if($ticket->reopen_count)<div class="sup-kv"><span>Reopened</span><strong>{{ $ticket->reopen_count }} time(s)</strong></div>@endif
                    @else
                        <div class="sup-kv"><span>Status</span><strong>{{ $statuses[$ticket->status] }}</strong></div>
                        <div class="sup-kv"><span>Category</span><strong>{{ $ticket->category?->name }}</strong></div>
                        <div class="sup-kv"><span>Handled by</span><strong>{{ $ticket->assignee?->name ?? 'Support team' }}</strong></div>
                        <div class="sup-kv"><span>Raised</span><strong>{{ $ticket->created_at->format('d M Y, h:i A') }}</strong></div>
                        @if($ticket->resolved_at)<div class="sup-kv"><span>Resolved</span><strong>{{ $ticket->resolved_at->format('d M Y, h:i A') }}</strong></div>@endif
                    @endif
                </div>
            </section>

            @if($staff)
                <section class="card">
                    <div class="card-head"><div><h2>Activity history</h2><p>Status, priority, category and assignment changes.</p></div></div>
                    <ol class="sup-history">
                        @foreach($history as $h)
                            <li><strong>{{ $h['text'] }}</strong><small>{{ $h['by'] }} · {{ $h['at']?->format('d M Y, h:i A') }}</small>@if($h['note'])<small class="sup-history-note">{{ $h['note'] }}</small>@endif</li>
                        @endforeach
                    </ol>
                </section>
            @endif
        </aside>
    </div>

    @if($hidingId)
        <x-modal title="Hide message from the employee">
            <form wire:submit="hide"><div class="modal-body">
                <p class="small">The employee will see “This message was removed by the support admin.” The text stays here for staff and is written in the audit log.</p>
                <x-field label="Reason" name="reason" full><input type="text" wire:model="hideReason" maxlength="255" placeholder="e.g. Shared a password by mistake"></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-danger" wire:loading.attr="disabled">Hide message</button></div></form>
        </x-modal>
    @endif

    @if($showReopen)
        <x-modal title="Reopen request">
            <form wire:submit="reopen"><div class="modal-body">
                <x-field label="What is still not solved?" name="reason" full><textarea rows="3" wire:model="reopenReason" maxlength="2000"></textarea></x-field>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Reopen</button></div></form>
        </x-modal>
    @endif
</div>
