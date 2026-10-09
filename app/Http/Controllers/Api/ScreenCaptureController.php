<?php

namespace App\Http\Controllers\Api;

use App\Services\ScreenCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Desktop app: acknowledge screen capture and upload captures (only accepted when the rules allow). */
class ScreenCaptureController extends ApiController
{
    public function acknowledge(Request $request, ScreenCaptureService $captures): JsonResponse
    {
        $this->apiEmployee($request);
        $captures->acknowledge($this->apiUser($request));

        return response()->json(['data' => ['acknowledged_at' => $this->apiUser($request)->fresh()->screen_capture_acknowledged_at?->toIso8601String()]]);
    }

    public function store(Request $request, ScreenCaptureService $captures): JsonResponse
    {
        $data = $request->validate(['image' => ScreenCaptureService::IMAGE_RULES, 'captured_at' => ['required', 'date']]);
        $shot = $captures->store($this->apiEmployee($request), $request->file('image'), Carbon::parse($data['captured_at']));

        return response()->json(['data' => ['id' => $shot->id, 'captured_at' => $shot->captured_at->toIso8601String()]], 201);
    }
}
