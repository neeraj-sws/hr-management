<div class="sr-page">
    @php
        $mark = function (?string $text) use ($term) {
            $safe = e((string) $text);
            return mb_strlen($term) >= 2 ? preg_replace('/('.preg_quote(e($term), '/').')/iu', '<mark>$1</mark>', $safe) : $safe;
        };
        $icons = ['people' => 'user', 'tasks' => 'check-square', 'projects' => 'folder', 'status' => 'message', 'notes' => 'note', 'pages' => 'file'];
    @endphp
    <div class="page-head"><div><h1>Search</h1><p>People, tasks, projects, daily status, notes and pages you are allowed to see.</p></div></div>
    <div class="sr-box">
        <x-icon name="search" class="icon-sm" />
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Search people, tasks, pages…" aria-label="Search" autofocus>
        <span wire:loading class="spinner"></span>
    </div>

    @if(mb_strlen($term) < 2)
        <div class="sr-card"><x-empty title="Type at least 2 letters" icon="search" /></div>
    @elseif(! $totals)
        <div class="sr-card gs-empty">
            <x-icon name="search-off" />
            <strong>No results for “{{ $term }}”</strong>
            @if($result['did_you_mean'])<p>Did you mean: <button type="button" class="gs-link" wire:click="$set('q', @js($result['did_you_mean']))">{{ $result['did_you_mean'] }}</button>?</p>@endif
        </div>
    @else
        <div class="gs-chips sr-chips" role="group" aria-label="Filter results">
            <button type="button" @class(['gs-chip', 'is-on' => $type === 'all']) wire:click="$set('type', 'all')">All · {{ array_sum($totals) }}</button>
            @foreach($totals as $key => $count)
                <button type="button" @class(['gs-chip', 'is-on' => $type === $key]) wire:click="$set('type', '{{ $key }}')">{{ \App\Services\GlobalSearch::GROUPS[$key] }} · {{ $count }}</button>
            @endforeach
        </div>
        @foreach($result['groups'] as $key => $group)
            <section class="sr-card">
                <div class="gs-group-head"><span>{{ $group['label'] }} ({{ $group['total'] }})</span>@if($type === 'all' && $group['total'] > count($group['items']))<button type="button" class="gs-link" wire:click="$set('type', '{{ $key }}')">See all {{ $group['total'] }}</button>@endif</div>
                @foreach($group['items'] as $item)
                    <a class="gs-row" href="{{ $item['url'] ?? '#' }}" wire:key="sr-{{ $key }}-{{ $item['id'] }}">
                        @if($key === 'people')<span class="gs-avatar">@if($item['photo'])<img src="{{ $item['photo'] }}" alt="">@else{{ $item['initials'] }}@endif</span>
                        @else<span class="gs-icon gs-tone-{{ $key }}"><x-icon :name="$icons[$key]" class="icon-sm" /></span>@endif
                        <span class="gs-text"><span class="gs-title">{!! $mark($item['title']) !!}</span><span class="gs-sub">{!! $mark($item['subtitle'] ?? '') !!}</span></span>
                        @if(! empty($item['status']))<span class="gs-pill gs-pill-{{ $item['status']['key'] }}" @if(! empty($item['status']['color'])) style="color:{{ $item['status']['color'] }}" @endif>{{ $item['status']['label'] }}</span>@endif
                    </a>
                @endforeach
                @if($type !== 'all' && $group['total'] > count($group['items']))<div class="nf-more-row"><button type="button" class="btn" wire:click="loadMore">Load more</button></div>@endif
            </section>
        @endforeach
    @endif
</div>
