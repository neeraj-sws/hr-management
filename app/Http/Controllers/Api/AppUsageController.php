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
        ]);
        $saved = $usage->record($employee, $data['apps'], $data['work_date'] ?? null);

        return response()->json(['success' => true, 'enabled' => $usage->enabledFor($employee), 'recorded' => $saved]);
    }
}
