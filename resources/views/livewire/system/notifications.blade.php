<div class="nf-page">
    <div class="page-head">
        <div><h1>Notifications</h1><p>{{ $counts['unread'] }} unread · {{ $counts['needs_action'] }} need{{ $counts['needs_action'] === 1 ? 's' : '' }} your action</p></div>
        <div class="row-actions">
            <a class="btn btn-icon" href="{{ route('account.profile') }}#notifications" aria-label="Notification settings" title="Notification settings"><x-icon name="settings" class="icon-sm" /></a>
            @if($counts['unread'])<button class="btn" wire:click="markAllRead"><x-icon name="checks" class="icon-sm" />Mark all read</button>@endif
        </div>
    </div>

    {{-- Filter chips; "Unread only" on the right. --}}
    <div class="nf-chips" role="group" aria-label="Filter notifications">
        <button type="button" @class(['nf-chip', 'is-active' => $filter === 'all']) wire:click="$set('filter', 'all')" aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">All · {{ $counts['all'] }}</button>
        <button type="button" @class(['nf-chip', 'nf-chip-action', 'is-active' => $filter === 'action']) wire:click="$set('filter', 'action')" aria-pressed="{{ $filter === 'action' ? 'true' : 'false' }}">Needs action · {{ $counts['needs_action'] }}</button>
        @foreach($chips as $key => $label)
            <button type="button" @class(['nf-chip', 'is-active' => $filter === $key]) wire:click="$set('filter', '{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">{{ $label }} · {{ $counts[$key] }}</button>
        @endforeach
        <span class="nf-chips-gap"></span>
        <button type="button" @class(['nf-chip', 'is-active' => $unreadOnly]) wire:click="$toggle('unreadOnly')" aria-pressed="{{ $unreadOnly ? 'true' : 'false' }}"><x-icon :name="$unreadOnly ? 'eye' : 'eye-off'" class="icon-sm" />Unread only</button>
    </div>

    <section class="nf-card">
        @forelse($days as $day => $items)
            <h2 class="nf-day">{{ $day }}</h2>
            <ul class="nf-list">
                @foreach($items as $item)
                    <li @class(['nf-row', 'is-unread' => $item['unread']]) wire:key="nf-{{ $item['id'] }}" @if(! empty($item['grouped'])) x-data="{ open: false }" @endif>
                        @if($item['unread'])<span class="nf-dot" aria-label="Unread"></span>@endif
                        <x-notif-icon :item="$item" />
                        <div class="nf-body">
                            <x-notif-title :item="$item" />
                            @if($item['details'])<div class="nf-details">{{ $item['details'] }}</div>@endif
                            @if($item['quote'])<div class="nf-quote">“{{ $item['quote'] }}”</div>@endif
                            <div class="nf-meta">
                                @if($item['needs_action'])<span class="nf-tag nf-tag-action">Needs action</span>
                                @elseif($item['decision'])<span @class(['nf-tag', 'nf-tag-ok' => str_starts_with($item['decision'], 'Approved'), 'nf-tag-no' => str_starts_with($item['decision'], 'Rejected')])>{{ $item['decision'] }}</span>
                                @else<span class="nf-tag" style="background:{{ $item['bg'] }};color:{{ $item['fg'] }}">{{ $item['tag'] }}</span>@endif
                                <span>· {{ $item['time'] }}</span>
                            </div>
                            @if(! empty($item['grouped']))
                                <ul class="nf-children" x-show="open" x-cloak>
                                    @foreach($item['children'] as $child)
                                        <li><x-notif-title :item="$child" />@if($child['details'])<span class="nf-details"> · {{ $child['details'] }}</span>@endif <span class="nf-details">· {{ $child['time'] }}</span></li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div class="nf-actions">
                            @if($item['needs_action'])
                                <button type="button" class="btn btn-sm nf-approve" wire:click="approve({{ $item['approval_id'] }}, '{{ $item['id'] }}')" wire:loading.attr="disabled"><x-icon name="check" class="icon-sm" />Approve</button>
                                <button type="button" class="btn btn-sm" wire:click="askReject({{ $item['approval_id'] }}, '{{ $item['id'] }}')">Reject</button>
                                <details class="menu nf-more">
                                    <summary class="btn btn-sm btn-icon" aria-label="More actions"><x-icon name="more" class="icon-sm" /></summary>
                                    <div class="menu-panel">
                                        <a class="menu-item" href="{{ $item['open_url'] }}"><x-icon name="external" class="icon-sm" />View details</a>
                                        @if($item['unread'])<button type="button" class="menu-item" wire:click="markRead('{{ $item['id'] }}')"><x-icon name="check" class="icon-sm" />Mark read</button>@endif
                                    </div>
                                </details>
                            @elseif(! empty($item['grouped']))
                                @if($item['unread'])<button type="button" class="btn btn-sm btn-icon" wire:click="markReadMany(@js($item['ids']))" aria-label="Mark all {{ count($item['ids']) }} as read" title="Mark read"><x-icon name="check" class="icon-sm" /></button>@endif
                                <button type="button" class="btn btn-sm btn-icon" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-label="Show each person"><x-icon name="chevron-down" class="icon-sm" /></button>
                            @else
                                <a class="btn btn-sm" href="{{ $item['open_url'] }}">Open</a>
                                @if($item['unread'])<button type="button" class="btn btn-sm btn-icon" wire:click="markRead('{{ $item['id'] }}')" aria-label="Mark read" title="Mark read"><x-icon name="check" class="icon-sm" /></button>@endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @empty
            <div class="nf-empty"><span class="nf-empty-icon"><x-icon name="check" /></span><strong>You're all caught up</strong><p class="muted small">{{ $filter === 'all' && ! $unreadOnly ? 'No notifications yet.' : 'Nothing here for this filter.' }}</p></div>
        @endforelse
        @if($hasMore)<div class="nf-more-row"><button type="button" class="btn" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore"><span wire:loading wire:target="loadMore" class="spinner"></span>Load more</button></div>@endif
    </section>

    @if($rejectApproval)
        <x-modal title="Reject request" close="cancelReject">
            <form wire:submit="confirmReject">
                <div class="modal-body"><x-field label="Reason" name="rejectNote" hint="the employee sees it"><textarea wire:model="rejectNote" rows="3" maxlength="1000" placeholder="Why is it rejected?"></textarea></x-field></div>
                <div class="modal-foot"><button type="button" class="btn" wire:click="cancelReject">Cancel</button><button class="btn btn-danger">Reject</button></div>
            </form>
        </x-modal>
    @endif
</div>
