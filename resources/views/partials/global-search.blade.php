{{-- Header search: results while typing (people, tasks, projects, daily status, notes, pages), recent searches,
     quick actions and pages. Ctrl/Cmd + K or "/" focuses it. The full list is on /search. --}}
@php
    $u = auth()->user();
    $can = fn (string $route, array $params = []) => \App\Support\Navigation::canAccess($u, $route, $params);
    $quick = array_values(array_filter([
        $u->employeeProfile && $can('leave.requests') ? ['label' => 'Apply leave', 'icon' => 'calendar-plus', 'tone' => 'green', 'url' => route('leave.requests', ['tab' => 'mine', 'new' => 1], false)] : null,
        $u->employeeProfile && $can('work.daily-status') ? ['label' => 'Submit daily status', 'icon' => 'message-plus', 'tone' => 'blue', 'url' => route('work.daily-status', [], false).'#my-status'] : null,
        $can('lifecycle.documents') && ($u->employeeProfile || $u->can('lifecycle.manage')) ? ['label' => 'Upload document', 'icon' => 'file-upload', 'tone' => 'violet', 'url' => route('lifecycle.documents', ['upload' => 1], false)] : null,
    ]));
    $go = array_values(array_filter([
        $can('payroll.salary') ? ['label' => 'My salary', 'section' => 'Payroll', 'icon' => 'receipt', 'url' => route('payroll.salary', [], false)] : null,
        $can('attendance.my') ? ['label' => 'Attendance', 'section' => 'Attendance', 'icon' => 'clock', 'url' => route('attendance.my', [], false)] : null,
        $can('monitoring.policy') ? ['label' => 'Monitoring policy', 'section' => 'Monitoring', 'icon' => 'eye', 'url' => route('monitoring.policy', [], false)] : null,
    ]));
    $groupIcons = ['people' => 'user', 'tasks' => 'check-square', 'projects' => 'folder', 'status' => 'message', 'notes' => 'note', 'pages' => 'file'];
    $icons = collect(['history', 'x', 'search-off', 'arrow-right', 'calendar-plus', 'message-plus', 'file-upload', 'receipt', 'clock', 'eye', 'user', 'check-square', 'folder', 'message', 'note', 'file'])
        ->mapWithKeys(fn ($n) => [$n => \Illuminate\Support\Facades\Blade::render('<x-icon :name="$n" class="icon-sm" />', ['n' => $n])]);
@endphp
<div class="gs" x-data="swsSearch({ suggest: @js(route('search.suggest', [], false)), page: @js(route('search', [], false)), user: {{ (int) $u->id }}, quick: @js($quick), go: @js($go), icons: @js($icons), groupIcons: @js($groupIcons) })"
     x-on:keydown.window="globalKey($event)" x-on:click.outside="close()" :class="{ 'is-open': open }">
    <div class="gs-box" :class="{ 'is-focus': focused }">
        <x-icon name="search" class="icon-sm gs-lead" />
        <input type="search" x-ref="input" x-model="q" x-on:input="changed()" x-on:focus="focused = true; open = true" x-on:blur="focused = false"
               x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="enter()" x-on:keydown.escape.prevent="close(true)"
               placeholder="Search people, tasks, pages…" aria-label="Search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="gs-list"
               :aria-expanded="open ? 'true' : 'false'" :aria-activedescendant="active >= 0 ? 'gs-opt-' + active : null">
        <span class="spinner gs-spin" x-show="loading" x-cloak></span>
        <button type="button" class="gs-clear" x-show="q" x-cloak x-on:click="q = ''; changed(); $refs.input.focus()" aria-label="Clear search">{!! $icons['x'] !!}</button>
        <kbd class="gs-kbd" x-show="! q" x-text="mac ? '⌘K' : 'Ctrl K'"></kbd>
        <button type="button" class="gs-mobile-close" x-on:click="close(true)" aria-label="Close search">{!! $icons['x'] !!}</button>
    </div>
    <button type="button" class="icon-btn gs-mobile-open" x-on:click="open = true; $nextTick(() => $refs.input.focus())" aria-label="Search"><x-icon name="search" /></button>

    <div class="gs-panel" x-show="open" x-cloak id="gs-list" role="listbox" aria-label="Search results">
        {{-- Nothing typed yet: recent searches, quick actions, pages. --}}
        <template x-if="q.trim().length < 2">
            <div>
                <template x-if="recent.length">
                    <div class="gs-group">
                        <div class="gs-group-head"><span>Recent</span><button type="button" class="gs-link" x-on:click="clearRecent()">Clear</button></div>
                        <template x-for="(r, i) in recent" :key="'r' + i">
                            <div class="gs-row" :id="'gs-opt-' + i" role="option" :aria-selected="active === i" :class="{ 'is-active': active === i }" x-on:mouseenter="active = i" x-on:click="q = r; changed(true)">
                                <span class="gs-icon gs-icon-plain" x-html="icons.history"></span><span class="gs-title" x-text="r"></span>
                                <button type="button" class="gs-x" x-on:click.stop="removeRecent(i)" :aria-label="'Remove ' + r" x-html="icons.x"></button>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="quick.length">
                    <div class="gs-group">
                        <div class="gs-group-head"><span>Quick actions</span></div>
                        <template x-for="(a, i) in quick" :key="'q' + i">
                            <a class="gs-row" :href="a.url" :id="'gs-opt-' + (recent.length + i)" role="option" :class="{ 'is-active': active === recent.length + i }" x-on:mouseenter="active = recent.length + i">
                                <span class="gs-icon" :class="'gs-tone-' + a.tone" x-html="icons[a.icon]"></span><span class="gs-title" x-text="a.label"></span>
                            </a>
                        </template>
                    </div>
                </template>
                <template x-if="go.length">
                    <div class="gs-group">
                        <div class="gs-group-head"><span>Go to page</span></div>
                        <template x-for="(p, i) in go" :key="'g' + i">
                            <a class="gs-row" :href="p.url" :id="'gs-opt-' + (recent.length + quick.length + i)" role="option" :class="{ 'is-active': active === recent.length + quick.length + i }" x-on:mouseenter="active = recent.length + quick.length + i">
                                <span class="gs-icon gs-icon-plain" x-html="icons[p.icon]"></span><span class="gs-title" x-text="p.label"></span><span class="gs-meta" x-text="p.section"></span>
                            </a>
                        </template>
                    </div>
                </template>
            </div>
        </template>

        {{-- Results while typing. --}}
        <template x-if="q.trim().length >= 2 && result">
            <div>
                <div class="gs-chips" x-show="groupKeys().length" role="group" aria-label="Filter results">
                    <button type="button" class="gs-chip" :class="{ 'is-on': type === 'all' }" x-on:click="setType('all')" x-text="'All · ' + total()"></button>
                    <template x-for="g in groupKeys()" :key="'c' + g">
                        <button type="button" class="gs-chip" :class="{ 'is-on': type === g }" x-on:click="setType(g)" x-text="result.groups[g].label + ' · ' + result.groups[g].total"></button>
                    </template>
                </div>
                <template x-for="g in groupKeys()" :key="'grp' + g">
                    <div class="gs-group">
                        <div class="gs-group-head"><span x-text="result.groups[g].label"></span>
                            <a class="gs-link" x-show="result.groups[g].total > result.groups[g].items.length" :href="pageUrl(g)" x-text="'See all ' + result.groups[g].total"></a></div>
                        <template x-for="item in result.groups[g].items" :key="g + item.id">
                            <div>
                                <a class="gs-row" :href="item.url || pageUrl(g)" :id="'gs-opt-' + item._i" role="option" :aria-selected="active === item._i" :class="{ 'is-active': active === item._i }" x-on:mouseenter="active = item._i" x-on:click="remember()">
                                    <template x-if="g === 'people'"><span class="gs-avatar"><template x-if="item.photo"><img :src="item.photo" alt=""></template><template x-if="! item.photo"><span x-text="item.initials"></span></template></span></template>
                                    <template x-if="g !== 'people'"><span class="gs-icon" :class="'gs-tone-' + g" x-html="icons[groupIcons[g]]"></span></template>
                                    <span class="gs-text"><span class="gs-title" x-html="mark(item.title)"></span><span class="gs-sub" x-html="mark(item.subtitle || '')"></span></span>
                                    <template x-if="item.status"><span class="gs-pill" :class="'gs-pill-' + item.status.key" :style="item.status.color ? 'color:' + item.status.color : ''" x-text="item.status.label"></span></template>
                                </a>
                                <template x-if="g === 'people' && item.shortcuts && item.shortcuts.length && active === item._i">
                                    <div class="gs-shortcuts">
                                        <template x-for="s in item.shortcuts" :key="s.url"><a class="gs-shortcut" :href="s.url" x-on:click="remember()"><span x-html="icons[s.icon]"></span><span x-text="s.label"></span></a></template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="! groupKeys().length && ! loading">
                    <div class="gs-empty">
                        <span x-html="icons['search-off']"></span>
                        <strong x-text="'No results for “' + result.query + '”'"></strong>
                        <template x-if="result.did_you_mean"><p>Did you mean: <button type="button" class="gs-link" x-on:click="q = result.did_you_mean; changed(true)" x-text="result.did_you_mean"></button>?</p></template>
                    </div>
                </template>
            </div>
        </template>

        <div class="gs-foot">
            <span><kbd>↑</kbd><kbd>↓</kbd> move <kbd>Enter</kbd> open <kbd>Esc</kbd> close</span>
            <a class="gs-link" x-show="q.trim().length >= 2" :href="pageUrl(type)">All results <span x-html="icons['arrow-right']"></span></a>
        </div>
    </div>
</div>
<script>
    window.swsSearch = window.swsSearch || function (cfg) {
        return {
            ...cfg, q: '', type: 'all', open: false, focused: false, loading: false, result: null, active: -1, timer: null, ctrl: null,
            mac: /Mac|iPhone|iPad/.test(navigator.platform), recent: [],
            init() { this.recent = this.loadRecent(); },
            key() { return 'sws.search.recent.' + this.user; },
            loadRecent() { try { return JSON.parse(localStorage.getItem(this.key()) || '[]').slice(0, 5); } catch (e) { return []; } },
            saveRecent() { try { localStorage.setItem(this.key(), JSON.stringify(this.recent)); } catch (e) {} },
            remember() { const t = this.q.trim(); if (t.length < 2) return; this.recent = [t, ...this.recent.filter((r) => r.toLowerCase() !== t.toLowerCase())].slice(0, 5); this.saveRecent(); },
            removeRecent(i) { this.recent.splice(i, 1); this.saveRecent(); },
            clearRecent() { this.recent = []; this.saveRecent(); },
            globalKey(e) {
                const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); this.open = true; this.$refs.input.focus(); this.$refs.input.select(); }
                else if (e.key === '/' && !typing) { e.preventDefault(); this.open = true; this.$refs.input.focus(); }
            },
            close(blur) { this.open = false; this.active = -1; if (blur) this.$refs.input.blur(); },
            changed(now) {
                this.active = -1; this.open = true;
                clearTimeout(this.timer);
                if (this.q.trim().length < 2) { this.result = null; this.loading = false; this.ctrl?.abort(); return; }
                this.timer = setTimeout(() => this.fetch(), now ? 0 : 300);
            },
            setType(t) { this.type = t; this.fetch(); },
            async fetch() {
                this.ctrl?.abort();
                this.ctrl = new AbortController();
                this.loading = true;
                try {
                    const url = this.suggest + '?' + new URLSearchParams({ q: this.q.trim(), type: this.type, limit: this.type === 'all' ? 5 : 10 });
                    const r = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.ctrl.signal, credentials: 'same-origin' });
                    if (!r.ok) throw new Error(r.status);
                    const data = await r.json();
                    let i = 0;
                    Object.keys(data.groups).forEach((g) => data.groups[g].items.forEach((it) => { it._i = i++; }));
                    this.result = data;
                    this.active = i ? 0 : -1;
                } catch (e) { if (e.name !== 'AbortError') this.result = { query: this.q, groups: {}, did_you_mean: null }; }
                finally { this.loading = false; }
            },
            groupKeys() { return this.result ? Object.keys(this.result.groups) : []; },
            total() { return this.groupKeys().reduce((n, g) => n + this.result.groups[g].total, 0); },
            count() {
                if (this.q.trim().length < 2) return this.recent.length + this.quick.length + this.go.length;
                return this.groupKeys().reduce((n, g) => n + this.result.groups[g].items.length, 0);
            },
            move(d) { const n = this.count(); if (!n) return; this.open = true; this.active = (this.active + d + n) % n; this.$nextTick(() => document.getElementById('gs-opt-' + this.active)?.scrollIntoView({ block: 'nearest' })); },
            enter() {
                const el = this.active >= 0 ? document.getElementById('gs-opt-' + this.active) : null;
                if (el && el.tagName === 'A') { this.remember(); window.location = el.getAttribute('href'); return; }
                if (el) { el.click(); return; }
                if (this.q.trim().length >= 2) { this.remember(); window.location = this.pageUrl(this.type); }
            },
            pageUrl(type) { return this.page + '?' + new URLSearchParams({ q: this.q.trim(), type: type || 'all' }); },
            mark(text) {
                const esc = String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                const t = this.q.trim();
                if (t.length < 2) return esc;
                const safe = t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                return esc.replace(new RegExp('(' + safe + ')', 'ig'), '<mark>$1</mark>');
            },
        };
    };
</script>
