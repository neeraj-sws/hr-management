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
        /* Live bell: asks the server every 30 seconds (only while the tab is visible) for anything new.
           The sound is made in the browser (no file); browsers allow it only after the first click on the page. */
        /* Viewing the site as someone else is read only: a blocked click says so in a toast, not Livewire's full-screen error page. */
        document.addEventListener('livewire:init', () => {
            Livewire.hook('request', ({ fail }) => {
                fail(({ status, content, preventDefault }) => {
                    if (status === 403 && String(content || '').includes('View only')) {
                        preventDefault();
                        window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'View only: nothing can be saved or changed while you are viewing as an employee.', type: 'error' } }));
                    }
                });
            });
        });
        window.swsBell = function (c) {
            let audio = null;
            const unlock = () => { try { audio ??= new (window.AudioContext || window.webkitAudioContext)(); if (audio.state === 'suspended') audio.resume(); } catch (e) {} };
            const ding = () => {
                try {
                    unlock(); if (! audio || audio.state !== 'running') return;
                    const t = audio.currentTime;
                    [[880, 0], [1320, 0.12]].forEach(([f, d]) => {
                        const o = audio.createOscillator(), g = audio.createGain();
                        o.type = 'sine'; o.frequency.value = f;
                        g.gain.setValueAtTime(0.0001, t + d); g.gain.exponentialRampToValueAtTime(0.18, t + d + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.45);
                        o.connect(g).connect(audio.destination); o.start(t + d); o.stop(t + d + 0.5);
                    });
                } catch (e) {}
            };
            return {
                count: c.count, since: c.since, sound: c.sound, busy: false, urgent: null,
                init() {
                    ['pointerdown', 'keydown'].forEach((e) => window.addEventListener(e, unlock, { once: true }));
                    setInterval(() => this.check(), 30000);
                    document.addEventListener('visibilitychange', () => { if (! document.hidden) this.check(); });
                },
                async check() {
                    if (document.hidden || this.busy) return;
                    this.busy = true;
                    try {
                        const r = await fetch(c.url + (this.since ? '?since=' + encodeURIComponent(this.since) : ''), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                        if (! r.ok) return;
                        const d = await r.json();
                        this.count = d.count; this.sound = d.sound;
                        if (d.since) this.since = d.since;
                        if (d.panel && this.$refs.panel) this.$refs.panel.innerHTML = d.panel;
                        if (d.urgent && d.urgent.length) this.urgent = d.urgent[0];
                        if (d.new && d.new.length) {
                            if (this.sound) ding();
                            const message = d.new.length === 1 ? d.new[0] : d.new.length + ' new notifications';
                            window.dispatchEvent(new CustomEvent('toast', { detail: { message, type: 'info' } }));
                        }
                    } catch (e) {} finally { this.busy = false; }
                },
            };
        };
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
                {{-- Live: checks every 30 seconds; new ones update the count and list, show a popup and (if on) play a short sound. --}}
                <details class="menu nf-bell" x-data="swsBell(@js(['count' => $unreadCount, 'since' => optional($user->notifications()->latest()->value('created_at'))->toIso8601String(), 'sound' => (bool) (($user->notification_preferences ?? [])['web_sound'] ?? true), 'url' => route('system.notifications.live', [], false)]))">
                    <summary class="icon-btn" aria-label="Notifications{{ $unreadCount ? ', '.$unreadCount.' unread' : '' }}" :aria-label="'Notifications' + (count ? ', ' + count + ' unread' : '')"><x-icon name="bell" /><span class="icon-count" x-show="count > 0" x-text="count > 99 ? '99+' : count" @unless($unreadCount) style="display:none" @endunless>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span></summary>
                    <div class="menu-panel nf-panel">
                        <div class="nf-live" x-ref="panel">@include('partials.bell-panel')</div>
                        <a class="nf-panel-foot" href="{{ route('system.notifications') }}">View all notifications <x-icon name="arrow-right" class="icon-sm" /></a>
                    </div>
                    {{-- An important reminder (e.g. daily status at 6:15 PM) opens here and stays until it is closed. --}}
                    <template x-if="urgent">
                        <div class="nf-urgent" role="alertdialog" aria-live="assertive">
                            <strong>Reminder</strong>
                            <p x-text="urgent.title"></p>
                            <div class="nf-urgent-actions"><a class="btn btn-primary btn-sm" :href="urgent.url">Open</a><button type="button" class="btn btn-sm" x-on:click="urgent = null">Later</button></div>
                        </div>
                    </template>
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
        // A ⋮ menu inside a scrolling table floats above the page, so it is never cut off by the table edge;
        // near the bottom of the screen it opens upwards.
        document.addEventListener('toggle', (e) => {
            const d = e.target;
            if (!(d instanceof HTMLDetailsElement) || !d.classList.contains('menu')) return;
            const panel = d.querySelector(':scope > .menu-panel');
            if (!panel || !d.closest('.table-wrap, .ds-list, .tm-table-card, .docs-list')) return;
            if (!d.open) { panel.style.cssText = ''; return; }
            const r = d.querySelector('summary').getBoundingClientRect();
            panel.style.position = 'fixed';
            panel.style.right = 'auto';
            const w = panel.offsetWidth, h = panel.offsetHeight;
            panel.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)) + 'px';
            panel.style.top = (r.bottom + h + 8 > window.innerHeight && r.top - h - 8 > 0 ? r.top - h - 6 : r.bottom + 6) + 'px';
            panel.style.bottom = 'auto';
        }, true);
        // Scrolling closes a floating menu (it would drift away from its button).
        window.addEventListener('scroll', () => document.querySelectorAll('.table-wrap details.menu[open], .ds-list details.menu[open]').forEach((d) => d.removeAttribute('open')), { passive: true });
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
