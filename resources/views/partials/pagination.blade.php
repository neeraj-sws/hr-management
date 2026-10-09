@if ($paginator->hasPages())
    @php($pageName = $paginator->getPageName())
    <nav class="pagination" aria-label="Pagination">
        <span class="small muted">Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
        <div class="pages">
            <button type="button" class="page-btn" wire:click="previousPage('{{ $pageName }}')" @disabled($paginator->onFirstPage())>‹</button>
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="page-btn" aria-disabled="true">{{ $element }}</span>
                @elseif (is_array($element))
                    @foreach ($element as $page => $url)
                        <button type="button" class="page-btn {{ $page == $paginator->currentPage() ? 'active' : '' }}" wire:click="gotoPage({{ $page }}, '{{ $pageName }}')">{{ $page }}</button>
                    @endforeach
                @endif
            @endforeach
            <button type="button" class="page-btn" wire:click="nextPage('{{ $pageName }}')" @disabled(! $paginator->hasMorePages())>›</button>
        </div>
    </nav>
@endif
