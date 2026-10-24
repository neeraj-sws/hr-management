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
        $bellItems = (new \App\Support\NotificationPresenter($user))->present($user->notifications()->latest()->limit(6)->get());
        $roleLabel = \App\Support\PersonBadges::primary($user);
        // Approvals waiting for this person (badge in the menu), counted at most once a minute.
        $navApprovals = \Illuminate\Support\Facades\Cache::remember('nav.approvals.'.$user->id, 60, fn () => rescue(fn () => app(\App\Services\ApprovalInbox::class)->pendingCount($user), 0, false));
        $initials = collect(explode(' ', trim($user->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
    @endphp
    <title>{{ $pageTitle }} · SWS HR</title>
    @include('partials.favicon')
    <script>try { if (localStorage.getItem('sws.nav.collapsed') === '1') document.documentElement.classList.add('nav-collapsed'); } catch (e) {}</script>
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}?v={{ filemtime(public_path('css/sws.css')) }}">
    @livewireStyles
    <script>
        /* Live attendance figures: the server's numbers (AttendanceService::liveFigures) counted on each second
           by the same rule as the desktop and phone apps. Use: x-data="swsLive(...figures as JSON...)" and x-text="hms(v.worked)". */
        window.swsLive = function (c) {
            return {
                t0: Date.now(), now: Date.now(), timer: null,
                init() { if (c.running) this.timer = setInterval(() => this.now = Date.now(), 1000); },
                destroy() { clearInterval(this.timer); },
                get v() {
                    const s = c.running ? Math.max(0, Math.floor((this.now - this.t0) / 1000)) : 0;
                    const k = Math.min(s, c.tick_for || 0), r = s - k;
                    const v = { worked: c.worked, inOut: c.in_out, idle: c.idle, brk: c.break, active: c.screen.active, meeting: c.screen.meeting, sIdle: c.screen.idle, corrected: c.screen.corrected, untracked: c.screen.untracked };
                    if (!c.running) return v;
                    v.inOut += s;
                    if (c.status === 'on_break') { v.brk += s; return v; }
                    if (c.state === 'idle' && c.idle_deducted !== false) { v.idle += k; v.sIdle += k; v.worked += r; }
                    else if (c.state === 'idle') { v.sIdle += k; v.worked += s; } // field / mobile work: idle is not taken off
                    else { v.worked += s; if (c.state === 'active') v.active += k; else if (c.state === 'meeting') v.meeting += k; }
                    return v;
                },
                get pct() { const v = this.v, all = v.active + v.sIdle + v.meeting + v.corrected; return all ? Math.round((v.active + v.meeting + v.corrected) * 100 / all) : 0; },
                hms(n) { n = Math.max(0, Math.floor(n || 0)); const p = (x) => String(x).padStart(2, '0'); return p(Math.floor(n / 3600)) + ':' + p(Math.floor(n % 3600 / 60)) + ':' + p(n % 60); },
            };
        };
    </script>
</head>
<body>
    <input type="checkbox" id="nav-toggle" aria-label="Toggle navigation">
    <aside class="sidebar" aria-label="Main navigation">
        <div class="brand-row">
            <a class="brand" href="{{ route('dashboard') }}">
                <img class="brand-mark brand-logo" src="{{ asset('images/sws-logo.png') }}?v=2" alt="SWS">
                <span class="brand-text"><span class="brand-name">SWS HR</span><span class="brand-sub">HR &amp; Work Management</span></span>
            </a>
            <button type="button" class="nav-collapse" id="nav-collapse" aria-label="Collapse the menu" title="Collapse the menu"><x-icon name="chevron-left" class="icon-sm" /><x-icon name="chevron-left" class="icon-sm" /></button>
        </div>
        <div class="nav-find">
            <x-icon name="search" class="icon-sm" />
            <input type="search" id="nav-find" placeholder="Search menu…" aria-label="Search the menu" autocomplete="off">
            <kbd>Ctrl /</kbd>
        </div>
        {{-- Sections (Organization, Finance …) hold modules; a module opens to its pages. Only the current module starts open. --}}
        <nav class="nav-scroll" id="nav-menu">
            @foreach(\App\Support\Navigation::sections($user) as $section)
                @php($sectionActive = collect($section['groups'])->contains('active', true))
                <div class="nav-section" @if($section['title']) data-section="{{ \Illuminate\Support\Str::slug($section['title']) }}" @endif @if($sectionActive) data-current @endif>
                    @if($section['title'])
                        <button type="button" class="nav-section-head" aria-expanded="true">{{ $section['title'] }}<x-icon name="chevron-down" class="icon-sm" /></button>
                    @endif
                    <div class="nav-section-body">
                        @foreach($section['groups'] as $group)
                            @php($badge = match ($group['heading']) { 'Approvals' => $navApprovals, 'System' => $unreadCount, default => 0 })
                            @if(count($group['items']) === 1 && $group['heading'] === $group['items'][0]['label'])
                                @php($item = $group['items'][0])
                                <div class="nav-group nav-single">
                                    <a class="nav-link nav-mod {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}" title="{{ $item['label'] }}" @if($item['active']) aria-current="page" @endif>
                                        <span class="nav-tile" style="--tile:{{ $group['tone'] }}"><x-icon :name="$group['icon']" /></span><span class="nav-label">{{ $item['label'] }}</span><x-icon name="chevron-right" class="icon-sm nav-chev" />
                                    </a>
                                </div>
                            @else
                                <details class="nav-group" @if($group['active']) open data-current @endif>
                                    <summary class="nav-heading nav-mod" title="{{ $group['heading'] }}">
                                        <span class="nav-tile" style="--tile:{{ $group['tone'] }}"><x-icon :name="$group['icon']" /></span><span class="nav-label">{{ $group['heading'] }}</span>
                                        @if($badge)<span class="nav-count">{{ $badge > 99 ? '99+' : $badge }}</span>@endif
                                        <x-icon name="chevron-right" class="icon-sm nav-chev" />
                                    </summary>
                                    <div class="nav-sub">
                                        @foreach($group['items'] as $item)
                                            <a class="nav-link {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif>
                                                <x-icon :name="$item['icon']" />{{ $item['label'] }}
                                                @if($item['route'] === 'system.notifications' && $unreadCount)<span class="nav-count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
                                                @if($item['route'] === 'approvals.pending' && $navApprovals)<span class="nav-count">{{ $navApprovals }}</span>@endif
                                            </a>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
            <p class="nav-empty" hidden>No page matches.</p>
        </nav>
        {{-- Signed-in person, always at the bottom. --}}
        <div class="nav-profile">
            <x-avatar :employee="$user->employeeProfile" :name="$user->name" />
            <span class="nav-profile-text"><strong>{{ $user->name }}</strong><small>{{ $user->employeeProfile?->designation ?: $roleLabel }}</small></span>
            <details class="menu nav-profile-menu">
                <summary class="nav-profile-btn" aria-label="Account menu"><x-icon name="more" class="icon-sm" /></summary>
                <div class="menu-panel">
                    <a class="menu-item" href="{{ route('account.profile') }}"><x-icon name="user" class="icon-sm" />My profile</a>
                    <a class="menu-item" href="{{ route('account.profile') }}#notifications"><x-icon name="bell" class="icon-sm" />Notification settings</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="menu-item danger" type="submit"><x-icon name="logout" class="icon-sm" />Log out</button></form>
                </div>
            </details>
        </div>
        <script>
            (() => {
                const root = document.documentElement;
                const menu = document.getElementById('nav-menu');
                const find = document.getElementById('nav-find');
                const groups = [...menu.querySelectorAll('details.nav-group')];
                const store = { get: (k) => { try { return localStorage.getItem(k); } catch { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch {} } };
                let filtering = false;

                // Opening a module closes the others.
                groups.forEach((group) => group.addEventListener('toggle', () => {
                    if (group.open && !filtering) groups.forEach((other) => { if (other !== group) other.open = false; });
                }));

                // Sections fold too; the choice is remembered (the current page's section always stays open).
                const folded = new Set((store.get('sws.nav.folded') || '').split(',').filter(Boolean));
                menu.querySelectorAll('.nav-section[data-section]').forEach((section) => {
                    const head = section.querySelector('.nav-section-head');
                    const apply = (closed) => { section.classList.toggle('is-folded', closed); head.setAttribute('aria-expanded', closed ? 'false' : 'true'); };
                    apply(folded.has(section.dataset.section) && !section.hasAttribute('data-current'));
                    head.addEventListener('click', () => {
                        const closed = !section.classList.contains('is-folded');
                        apply(closed);
                        closed ? folded.add(section.dataset.section) : folded.delete(section.dataset.section);
                        store.set('sws.nav.folded', [...folded].join(','));
                    });
                });

                // Collapse to icons (desktop). Clicking a module while collapsed opens the menu again.
                document.getElementById('nav-collapse').addEventListener('click', () => {
                    const on = !root.classList.contains('nav-collapsed');
                    root.classList.toggle('nav-collapsed', on);
                    store.set('sws.nav.collapsed', on ? '1' : '0');
                });
                menu.addEventListener('click', (event) => {
                    if (!root.classList.contains('nav-collapsed')) return;
                    const summary = event.target.closest('summary.nav-mod');
                    if (!summary) return;
                    event.preventDefault();
                    root.classList.remove('nav-collapsed');
                    store.set('sws.nav.collapsed', '0');
                    summary.parentElement.open = true;
                });

                menu.querySelector('.nav-link.active')?.scrollIntoView({ block: 'center' });

                // Search: shows matching pages (and their modules), opens what matches.
                find.addEventListener('input', () => {
                    const term = find.value.trim().toLowerCase();
                    filtering = term !== '';
                    let shown = 0;
                    menu.querySelectorAll('.nav-section').forEach((section) => {
                        let inSection = 0;
                        section.querySelectorAll('.nav-group').forEach((group) => {
                            const heading = group.querySelector('summary')?.textContent.toLowerCase() ?? '';
                            let matches = 0;
                            group.querySelectorAll('.nav-link').forEach((link) => {
                                const hit = !term || heading.includes(term) || link.textContent.toLowerCase().includes(term);
                                link.hidden = !hit;
                                if (hit) matches++;
                            });
                            group.hidden = matches === 0;
                            inSection += matches;
                            if (group.tagName === 'DETAILS') group.open = term ? matches > 0 : group.hasAttribute('data-current');
                        });
                        section.hidden = inSection === 0;
                        if (term) section.classList.remove('is-folded');
                        shown += inSection;
                    });
                    menu.querySelector('.nav-empty').hidden = shown > 0;
                    if (term) menu.scrollTop = 0;
                });
                find.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') { event.preventDefault(); menu.querySelector('.nav-link:not([hidden])')?.click(); }
                    if (event.key === 'Escape') { find.value = ''; find.dispatchEvent(new Event('input')); find.blur(); }
                });
                // Ctrl + / jumps to the menu search (Ctrl + K is the global search in the header).
                document.addEventListener('keydown', (event) => {
                    if ((event.ctrlKey || event.metaKey) && event.key === '/') {
                        event.preventDefault();
                        if (root.classList.contains('nav-collapsed')) { root.classList.remove('nav-collapsed'); store.set('sws.nav.collapsed', '0'); }
                        document.getElementById('nav-toggle').checked = true;
                        find.focus();
                    }
                });
            })();
        </script>
    </aside>
    <label class="scrim" for="nav-toggle" aria-hidden="true"></label>

    <div class="main">
        @if(\App\Services\ImpersonationService::active())
            <div class="viewas-bar" role="status">
                <x-icon name="eye" class="icon-sm" />
                <span>You are viewing as <strong>{{ $user->name }}</strong>. View only: nothing can be saved in their name, and this visit is logged under {{ \App\Services\ImpersonationService::impersonator()?->name ?? 'your name' }}.</span>
                <form method="POST" action="{{ route('people.view-as.stop') }}">@csrf<button type="submit" class="btn btn-sm"><x-icon name="arrow-left" class="icon-sm" />Return to my account</button></form>
            </div>
        @endif
        <header class="topbar">
            <div class="topbar-left">
                <label class="burger icon-btn" for="nav-toggle" aria-label="Open navigation"><x-icon name="menu" /></label>
                <nav class="crumbs" aria-label="Breadcrumb">
                    <a href="{{ route('dashboard') }}">SWS HR</a>
                    @if($crumb && $crumb[0] !== $crumb[1])<span class="sep crumb-group">/</span><span class="crumb-group">{{ $crumb[0] }}</span>@endif
                    <span class="sep">/</span><strong>{{ $pageTitle }}</strong>
                </nav>
            </div>
            @include('partials.global-search')
            <div class="top-actions">
                {{-- Bell: the 6 latest, newest first. No tabs or approve buttons here; the page has those. --}}
                <details class="menu nf-bell">
                    <summary class="icon-btn" aria-label="Notifications{{ $unreadCount ? ', '.$unreadCount.' unread' : '' }}"><x-icon name="bell" />@if($unreadCount)<span class="icon-count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif</summary>
                    <div class="menu-panel nf-panel">
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
                        <a class="nf-panel-foot" href="{{ route('system.notifications') }}">View all notifications <x-icon name="arrow-right" class="icon-sm" /></a>
                    </div>
                </details>
                <details class="menu">
                    <summary class="profile-chip"><x-avatar :employee="$user->employeeProfile" :name="$user->name" /><span class="profile-copy"><strong>{{ $user->name }}</strong><x-person-badges :user="$user" class="header-badges" /></span></summary>
                    <div class="menu-panel">
                        <div class="menu-head"><strong>{{ $user->name }}</strong><div class="small muted">{{ $user->email }}</div><div style="margin-top:6px"><x-person-badges :user="$user" /></div></div>
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
    <script>
        // Header menus (bell, profile) close on a click outside or Esc, and Esc puts focus back on the button.
        document.addEventListener('click', (e) => document.querySelectorAll('details.menu[open]').forEach((d) => { if (! d.contains(e.target)) d.removeAttribute('open'); }));
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            document.querySelectorAll('details.menu[open]').forEach((d) => { d.removeAttribute('open'); d.querySelector('summary')?.focus(); });
        });
    </script>
    <script src="{{ asset('js/offline-action-queue.js') }}" defer></script>
    <script src="{{ asset('js/table-sort.js') }}?v={{ filemtime(public_path('js/table-sort.js')) }}" defer></script>
</body>
</html>
