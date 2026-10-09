<?php

namespace App\Http\Controllers\Api;

use App\Models\TrainingAssignment;
use App\Services\TrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $items = TrainingAssignment::with('course')->where('employee_id', $employee->id)
            ->orderByRaw("status = 'completed'")->orderByRaw('due_on IS NULL')->orderBy('due_on')->get();

        return response()->json(['data' => $items->map(fn ($a) => $this->present($a))->values()]);
    }

    public function start(Request $request, TrainingAssignment $assignment, TrainingService $training): JsonResponse
    {
        return response()->json(['data' => $this->present($training->start($assignment, $this->apiEmployee($request))->load('course'))]);
    }

    public function complete(Request $request, TrainingAssignment $assignment, TrainingService $training): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->present($training->complete($assignment, $this->apiEmployee($request), $data['note'] ?? null)->load('course'))]);
    }

    private function present(TrainingAssignment $a): array
    {
        return [
            'id' => $a->id, 'title' => $a->course?->title, 'description' => $a->course?->description, 'category' => $a->course?->category,
            'duration_hours' => $a->course?->duration_hours !== null ? (float) $a->course->duration_hours : null, 'material_url' => $a->course?->material_url,
            'mandatory' => (bool) $a->course?->is_mandatory, 'due_on' => $a->due_on?->toDateString(), 'status' => $a->displayStatus(),
            'completed_at' => $a->completed_at?->toIso8601String(),
        ];
    }
}
