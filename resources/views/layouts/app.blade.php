<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $user = auth()->user();
        $crumb = \App\Support\Navigation::breadcrumb();
        $pageTitle = $title ?? ($crumb[1] ?? 'SWS HR');
        $unreadCount = $user->unreadNotifications()->count();
        $recentNotifications = $user->notifications()->latest()->limit(6)->get();
        $roleLabel = $user->getRoleNames()->implode(', ') ?: 'Employee';
        $initials = collect(explode(' ', trim($user->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
    @endphp
    <title>{{ $pageTitle }} · SWS HR</title>
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}?v={{ filemtime(public_path('css/sws.css')) }}">
    @livewireStyles
</head>
<body>
    <input type="checkbox" id="nav-toggle" aria-label="Toggle navigation">
    <aside class="sidebar" aria-label="Main navigation">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">SW</span>
            <span><span class="brand-name">SWS HR</span><span class="brand-sub">HR &amp; Work Management</span></span>
        </a>
        <div class="nav-find">
            <x-icon name="search" class="icon-sm" />
            <input type="search" id="nav-find" placeholder="Find a page…" aria-label="Find a page in the menu" autocomplete="off">
        </div>
        {{-- Groups fold: only the current page's group starts open, opening one closes the others. --}}
        <nav class="nav-scroll" id="nav-menu">
            @foreach(\App\Support\Navigation::for($user) as $group)
                @php($hasActive = collect($group['items'])->contains('active', true))
                @if(count($group['items']) === 1 && $group['heading'] === $group['items'][0]['label'])
                    <div class="nav-group nav-single">
                        @php($item = $group['items'][0])
                        <a class="nav-link {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif><x-icon :name="$item['icon']" />{{ $item['label'] }}</a>
                    </div>
                @else
                    <details class="nav-group" @if($hasActive) open data-current @endif>
                        <summary class="nav-heading">{{ $group['heading'] }}@if($group['heading'] === 'System' && $unreadCount)<span class="nav-count">{{ $unreadCount }}</span>@endif</summary>
                        @foreach($group['items'] as $item)
                            <a class="nav-link {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif>
                                <x-icon :name="$item['icon']" />{{ $item['label'] }}
                                @if($item['route'] === 'system.notifications' && $unreadCount)<span class="nav-count">{{ $unreadCount }}</span>@endif
                            </a>
                        @endforeach
                    </details>
                @endif
            @endforeach
            <p class="nav-empty" hidden>No page matches.</p>
        </nav>
        <script>
            (() => {
                const menu = document.getElementById('nav-menu');
                const find = document.getElementById('nav-find');
                const groups = [...menu.querySelectorAll('details.nav-group')];
                let filtering = false;
                groups.forEach((group) => group.addEventListener('toggle', () => {
                    if (group.open && !filtering) groups.forEach((other) => { if (other !== group) other.open = false; });
                }));
                menu.querySelector('.nav-link.active')?.scrollIntoView({ block: 'center' });
                find.addEventListener('input', () => {
                    const term = find.value.trim().toLowerCase();
                    filtering = term !== '';
                    let shown = 0;
                    menu.querySelectorAll('.nav-group').forEach((group) => {
                        const heading = group.querySelector('summary')?.textContent.toLowerCase() ?? '';
                        let matches = 0;
                        group.querySelectorAll('.nav-link').forEach((link) => {
                            const hit = !term || heading.includes(term) || link.textContent.toLowerCase().includes(term);
                            link.hidden = !hit;
                            if (hit) matches++;
                        });
                        group.hidden = matches === 0;
                        shown += matches;
                        if (group.tagName === 'DETAILS') group.open = term ? matches > 0 : group.hasAttribute('data-current');
                    });
                    menu.querySelector('.nav-empty').hidden = shown > 0;
                    if (term) menu.scrollTop = 0;
                });
                find.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') { event.preventDefault(); menu.querySelector('.nav-link:not([hidden])')?.click(); }
                    if (event.key === 'Escape') { find.value = ''; find.dispatchEvent(new Event('input')); }
                });
            })();
        </script>
    </aside>
    <label class="scrim" for="nav-toggle" aria-hidden="true"></label>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <label class="burger icon-btn" for="nav-toggle" aria-label="Open navigation"><x-icon name="menu" /></label>
                <nav class="crumbs" aria-label="Breadcrumb">
                    <a href="{{ route('dashboard') }}">SWS HR</a>
                    @if($crumb && $crumb[0] !== $crumb[1])<span class="sep crumb-group">/</span><span class="crumb-group">{{ $crumb[0] }}</span>@endif
                    <span class="sep">/</span><strong>{{ $pageTitle }}</strong>
                </nav>
            </div>
            <form class="top-search" method="GET" action="{{ route('search') }}" role="search">
                <x-icon name="search" class="icon-sm" />
                <input type="search" name="q" value="{{ request()->routeIs('search') ? request('q') : '' }}" placeholder="Search employees, tasks, projects…" aria-label="Search">
            </form>
            <div class="top-actions">
                <details class="menu">
                    <summary class="icon-btn" aria-label="Notifications"><x-icon name="bell" />@if($unreadCount)<span class="icon-count">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>@endif</summary>
                    <div class="menu-panel">
                        <div class="menu-head"><strong>Notifications</strong> <span class="small muted">· {{ $unreadCount }} unread</span></div>
                        @forelse($recentNotifications as $notification)
                            <a class="notif-item" href="{{ route('system.notifications.open', $notification->id) }}">
                                @if(!$notification->read_at)<span class="dot" style="color:var(--blue)"></span>@endif
                                {{ $notification->data['message'] ?? 'You have an update.' }}
                                <small>{{ $notification->created_at?->diffForHumans() }}</small>
                            </a>
                        @empty
                            <p class="small muted" style="padding:10px">You're all caught up.</p>
                        @endforelse
                        <div class="divider" style="margin:6px 0"></div>
                        <a class="menu-item" href="{{ route('system.notifications') }}"><x-icon name="inbox" class="icon-sm" />View all notifications</a>
                    </div>
                </details>
                <details class="menu">
                    <summary class="profile-chip"><span class="avatar">{{ $initials }}</span><span class="profile-copy"><strong>{{ $user->name }}</strong><small>{{ $roleLabel }}</small></span></summary>
                    <div class="menu-panel">
                        <div class="menu-head"><strong>{{ $user->name }}</strong><div class="small muted">{{ $user->email }}</div><div class="small muted">{{ $roleLabel }}</div></div>
                        <a class="menu-item" href="{{ route('account.profile') }}"><x-icon name="user" class="icon-sm" />My profile</a>
                        <a class="menu-item" href="{{ route('account.profile') }}#password"><x-icon name="lock" class="icon-sm" />Change password</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="menu-item danger" type="submit"><x-icon name="logout" class="icon-sm" />Log out</button></form>
                    </div>
                </details>
            </div>
        </header>

        <main class="content">{{ $slot }}</main>
        <footer class="footer">SWS HR · HR &amp; Work Management</footer>
    </div>

    <div class="toasts" x-data="{ items: [] }"
         x-on:toast.window="const id = Date.now() + Math.random(); items.push({ id, ...$event.detail }); setTimeout(() => items = items.filter(i => i.id !== id), 4200)">
        <template x-for="item in items" :key="item.id">
            <div class="toast" :class="item.type" role="status"><span x-text="item.message"></span></div>
        </template>
    </div>
    @if(session('toast'))
        <script>document.addEventListener('livewire:init', () => setTimeout(() => window.dispatchEvent(new CustomEvent('toast', { detail: @json(session('toast')) })), 50));</script>
    @endif

    @livewireScripts
    <script src="{{ asset('js/offline-action-queue.js') }}" defer></script>
</body>
</html>
