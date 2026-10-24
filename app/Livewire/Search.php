<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithHr;
use App\Services\GlobalSearch;
use Livewire\Attributes\Url;
use Livewire\Component;

/** All search results (the header box shows the first few): the same groups, chips and rules, with Load more. */
class Search extends Component
{
    use InteractsWithHr;

    #[Url] public string $q = '';
    #[Url] public string $type = 'all';
    public int $limit = 10;

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'type'], true)) $this->limit = 10;
    }

    public function loadMore(): void
    {
        $this->limit = min(200, $this->limit + 20);
    }

    public function render(GlobalSearch $search)
    {
        if (! array_key_exists($this->type, GlobalSearch::GROUPS)) $this->type = 'all';
        $all = $search->search($this->user(), $this->q, 'all', 1);
        $result = $search->search($this->user(), $this->q, $this->type, $this->type === 'all' ? 5 : $this->limit);

        return $this->page('livewire.search', [
            'result' => $result,
            'totals' => collect($all['groups'])->map(fn ($g) => $g['total'])->all(),
            'term' => trim($this->q),
        ], 'Search');
    }
}
