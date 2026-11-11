<?php

namespace App\Livewire\Concerns;

use Livewire\WithPagination;

/**
 * Normal Livewire pagination, but the page number is not kept in the URL — refreshing a list starts again at page 1
 * (like the old CMS), instead of staying on ?page=3.
 */
trait WithPaginationNoUrl
{
    use WithPagination;

    public function queryStringHandlesPagination()
    {
        return [];
    }
}
