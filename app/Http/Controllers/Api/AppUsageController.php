<?php

namespace App\Http\Controllers\Api;

use App\Services\AppUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The desktop app reports application-usage totals (app name + seconds) when the admin has enabled it. */
class AppUsageController extends ApiController
{
    public function store(Request $request, AppUsageService $usage): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate([
            'work_date' => ['nullable', 'date'],
            'apps' => ['required', 'array', 'max:200'],
            'apps.*' => ['required', 'integer', 'min:1', 'max:86400'],
            'titles' => ['nullable', 'array', 'max:400'],
            'titles.*.app' => ['required', 'string', 'max:120'],
            'titles.*.title' => ['required', 'string', 'max:255'],
            'titles.*.seconds' => ['required', 'integer', 'min:1', 'max:86400'],
        ]);
        $saved = $usage->record($employee, $data['apps'], $data['work_date'] ?? null);
        $savedTitles = $usage->recordTitles($employee, $data['titles'] ?? [], $data['work_date'] ?? null);

        return response()->json(['success' => true, 'enabled' => $usage->enabledFor($employee), 'titles_enabled' => $usage->titlesEnabledFor($employee), 'recorded' => $saved, 'recorded_titles' => $savedTitles]);
    }
}
