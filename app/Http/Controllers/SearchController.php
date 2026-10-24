<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /search/suggest (website) and GET /api/search (app):
 * ?q=risha&type=all|people|tasks|projects|status|notes|pages&limit=5&offset=0
 * Answers {query, groups: {people: {label, total, items: [...]}, …}, did_you_mean}; only groups with results.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'in:all,'.implode(',', array_keys(GlobalSearch::GROUPS))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:5000'],
        ]);

        return response()->json($search->search($request->user(), (string) ($data['q'] ?? ''), $data['type'] ?? 'all', (int) ($data['limit'] ?? 5), (int) ($data['offset'] ?? 0)));
    }
}
