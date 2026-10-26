{{-- Bell panel: unread count, "Mark all read" and the 6 latest. Also sent by the live check (notifications/live). --}}
                        <div class="nf-panel-head">
                            <span><strong>Notifications</strong> <span class="muted">· {{ $unreadCount }} unread</span></span>
                            @if($unreadCount)<form method="POST" action="{{ route('system.notifications.read-all') }}">@csrf<button type="submit" class="nf-link">Mark all read</button></form>@endif
                        </div>
                        <div class="nf-panel-list">
                            @forelse($bellItems as $item)
                                <a @class(['nf-bell-item', 'is-unread' => $item['unread']]) href="{{ $item['open_url'] }}">
                                    <x-notif-icon :item="$item" />
                                    <span class="nf-body">
                                        <x-notif-title :item="$item" class="nf-clamp" />
                                        <span class="nf-meta">{{ $item['needs_action'] ? 'Needs review · ' : '' }}{{ $item['time'] }}</span>
                                    </span>
                                    @if($item['unread'])<span class="nf-dot" aria-label="Unread"></span>@endif
                                </a>
                            @empty
                                <p class="nf-panel-empty"><x-icon name="check" class="icon-sm" /> You're all caught up</p>
                            @endforelse
                        </div>
