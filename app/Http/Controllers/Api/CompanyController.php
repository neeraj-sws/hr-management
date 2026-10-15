<?php

namespace App\Http\Controllers\Api;

use App\Models\Announcement;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Announcements, celebrations and holidays for the mobile app. */
class CompanyController extends ApiController
{
    public function announcements(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $items = Announcement::with('author')->visibleTo($user->employeeProfile)->orderByDesc('is_pinned')->latest('published_at')->limit(50)->get();

        return response()->json(['data' => $items->map(fn ($a) => [
            'id' => $a->id, 'title' => $a->title, 'body' => $a->body, 'pinned' => $a->is_pinned,
            'published_at' => $a->published_at->toIso8601String(), 'author' => $a->author?->name,
        ])->values()]);
    }

    public function celebrations(Request $request, CompanyService $company): JsonResponse
    {
        $this->apiUser($request);

        return response()->json(['data' => $company->celebrations(14)->map(fn ($e) => [
            'employee' => $e['employee']->name, 'type' => $e['type'], 'date' => $e['date']->toDateString(), 'years' => $e['years'], 'today' => $e['today'],
        ])->values()]);
    }

    public function holidays(Request $request, CompanyService $company): JsonResponse
    {
        $user = $this->apiUser($request);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? today()->year);
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        return response()->json([
            'data' => $company->holidays($user->employeeProfile, $year)->map(fn ($h) => ['date' => $h['date']->toDateString(), 'name' => $h['name'], 'category' => $h['category'], 'paid' => $h['paid']])->values(),
            'weekly_off' => collect($names)->except($company->workingDays($user->employeeProfile))->values(),
            'year' => $year,
        ]);
    }
}
