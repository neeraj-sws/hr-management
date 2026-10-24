{{-- Compact pager: ‹ 1 2 3 › (Livewire). --}}
@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pages">
        <button type="button" class="pager-btn" wire:click="previousPage('{{ $paginator->getPageName() }}')" @disabled($paginator->onFirstPage()) aria-label="Previous page"><x-icon name="chevron-left" class="icon-sm" /></button>
        @foreach ($elements as $element)
            @if (is_string($element))<span class="pager-gap">…</span>@endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    <button type="button" @class(['pager-btn', 'is-on' => $page == $paginator->currentPage()]) wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" @if($page == $paginator->currentPage()) aria-current="page" @endif>{{ $page }}</button>
                @endforeach
            @endif
        @endforeach
        <button type="button" class="pager-btn" wire:click="nextPage('{{ $paginator->getPageName() }}')" @disabled(! $paginator->hasMorePages()) aria-label="Next page"><x-icon name="chevron-right" class="icon-sm" /></button>
    </nav>
@endif
